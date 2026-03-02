from __future__ import annotations

import os
import signal
import subprocess
import threading
import time
import uuid
from dataclasses import dataclass
from datetime import datetime, timezone
from typing import Any

from playwright.sync_api import BrowserContext, Page, sync_playwright

from .config import Settings


AUTH_UNKNOWN = "AUTH_UNKNOWN"
AUTH_REQUIRED = "AUTH_REQUIRED"
LOGIN_RUNNING = "LOGIN_RUNNING"
AUTH_OK = "AUTH_OK"


_LOGIN_DETECT_JS = r"""
() => {
  const href = window.location.href || '';
  const onLoginUrl = href.includes('/login') || href.includes('/checkpoint/challenge');
  const loginInput = document.querySelector('input#username, input[name="session_key"], input#password');
  return Boolean(onLoginUrl || loginInput);
}
"""


def _now_iso() -> str:
    return datetime.now(timezone.utc).isoformat()


def check_authenticated(settings: Settings) -> bool:
    """Fast check: open feed in headless mode using the persistent profile."""
    with sync_playwright() as p:
        context = p.chromium.launch_persistent_context(
            user_data_dir=settings.profile_dir,
            headless=True,
            viewport={"width": 1200, "height": 900},
            timeout=15_000,
        )
        try:
            context.set_default_timeout(15_000)
            page = context.new_page()
            page.set_default_timeout(15_000)
            page.goto(
                "https://www.linkedin.com/feed/",
                wait_until="domcontentloaded",
                timeout=15_000,
            )
            page.wait_for_timeout(1200)
            login_detected = bool(page.evaluate(_LOGIN_DETECT_JS))
            return not login_detected
        finally:
            context.close()


@dataclass
class LoginSession:
    session_id: str
    started_at: str
    novnc_url: str
    display: str
    xvfb: subprocess.Popen | None = None
    x11vnc: subprocess.Popen | None = None
    websockify: subprocess.Popen | None = None
    pw: Any | None = None
    context: BrowserContext | None = None
    page: Page | None = None


class AuthController:
    def __init__(self, settings: Settings, profile_lock: threading.Lock) -> None:
        self.settings = settings
        self._lock = threading.Lock()
        self._profile_lock = profile_lock
        self.state = AUTH_UNKNOWN
        self.login: LoginSession | None = None
        self.last_check_at: str | None = None
        self._last_check_ts: float | None = None
        self._cache_ttl_seconds = 30.0

    def _status_locked(self) -> dict[str, Any]:
        # Caller must hold self._lock.
        return {
            "state": self.state,
            "login_session_id": self.login.session_id if self.login else None,
            "novnc_url": self.login.novnc_url if self.login else None,
            "started_at": self.login.started_at if self.login else None,
            "last_check_at": self.last_check_at,
        }

    def status(self) -> dict[str, Any]:
        with self._lock:
            return self._status_locked()

    def refresh_status(self) -> dict[str, Any]:
        """If no interactive login is running, checks if session is authenticated."""
        with self._lock:
            if self.login is not None:
                # When login is running, live status is determined from that session.
                self._update_login_state_locked()
                return self._status_locked()

            if (
                self._last_check_ts is not None
                and (time.time() - self._last_check_ts) < self._cache_ttl_seconds
                and self.state in {AUTH_OK, AUTH_REQUIRED, AUTH_UNKNOWN}
            ):
                return self._status_locked()

        # Exclusive access to the persistent profile.
        acquired = self._profile_lock.acquire(timeout=0.1)
        if not acquired:
            with self._lock:
                # Someone else is using the profile (scrape or login).
                return self._status_locked()

        try:
            ok: bool | None
            try:
                _cleanup_profile_locks(self.settings.profile_dir)
                ok = check_authenticated(self.settings)
            except Exception:
                ok = None
        finally:
            self._profile_lock.release()

        with self._lock:
            if ok is True:
                self.state = AUTH_OK
            elif ok is False:
                self.state = AUTH_REQUIRED
            else:
                self.state = AUTH_UNKNOWN
            self.last_check_at = _now_iso()
            self._last_check_ts = time.time()
            return self._status_locked()

    def start_login(self) -> dict[str, Any]:
        with self._lock:
            if self.login is not None:
                self._update_login_state_locked()
                return self._status_locked()

        # Exclusive access to the persistent profile.
        if not self._profile_lock.acquire(timeout=0.1):
            raise RuntimeError("Profile is busy (scraper may be running).")

        _cleanup_profile_locks(self.settings.profile_dir)

        session_id = uuid.uuid4().hex[:12]
        display = ":99"
        novnc_url = "http://127.0.0.1:7900/vnc.html?autoconnect=1&resize=remote&reconnect=1"
        login = LoginSession(
            session_id=session_id,
            started_at=_now_iso(),
            novnc_url=novnc_url,
            display=display,
        )

        try:
            self._start_login_stack(login)
        except Exception:
            # Best-effort cleanup in case some processes were already started.
            try:
                self._stop_login_stack(login)
            except Exception:
                pass
            self._profile_lock.release()
            raise

        with self._lock:
            self.login = login
            self.state = LOGIN_RUNNING
            self.last_check_at = _now_iso()
            self._last_check_ts = time.time()
            return self._status_locked()

    def login_status(self, session_id: str) -> dict[str, Any]:
        with self._lock:
            if self.login is not None and self.login.session_id == session_id:
                self._update_login_state_locked()
                self.last_check_at = _now_iso()
                self._last_check_ts = time.time()
                return self._status_locked()
        # No active session; return current auth state.
        return self.refresh_status()

    def stop_login(self, session_id: str) -> dict[str, Any]:
        login: LoginSession | None
        with self._lock:
            login = self.login
            if login is not None and login.session_id != session_id:
                raise RuntimeError("Unknown login session id")

        if login is None:
            return self.refresh_status()

        try:
            self._stop_login_stack(login)
        finally:
            self._profile_lock.release()

        with self._lock:
            self.login = None
            self.last_check_at = _now_iso()
            # Re-check to set final state.

        return self.refresh_status()

    def _start_login_stack(self, login: LoginSession) -> None:
        # Start Xvfb + VNC + websockify(noVNC). On-demand only.
        login.xvfb = _popen(
            [
                "Xvfb",
                login.display,
                "-screen",
                "0",
                "1400x900x24",
                "-ac",
                "-nolisten",
                "tcp",
            ]
        )
        _wait_for_x_socket(login)

        login.x11vnc = _popen(
            [
                "x11vnc",
                "-display",
                login.display,
                "-rfbport",
                "5900",
                "-shared",
                "-forever",
                "-nopw",
                "-noxdamage",
                "-quiet",
            ]
        )
        _wait(0.5)

        login.websockify = _popen(
            [
                "websockify",
                "--web=/usr/share/novnc",
                "0.0.0.0:7900",
                "localhost:5900",
            ]
        )
        _wait(0.7)

        # Launch Playwright driver and Chromium on the virtual display.
        # DISPLAY must be set before starting Playwright so the driver inherits it.
        old_display = os.environ.get("DISPLAY")
        os.environ["DISPLAY"] = login.display
        try:
            login.pw = sync_playwright().start()
            login.context = login.pw.chromium.launch_persistent_context(
                user_data_dir=self.settings.profile_dir,
                headless=False,
                viewport={"width": 1400, "height": 900},
            )
        finally:
            if old_display is None:
                os.environ.pop("DISPLAY", None)
            else:
                os.environ["DISPLAY"] = old_display

        login.page = login.context.new_page()
        login.page.goto("https://www.linkedin.com/login", wait_until="domcontentloaded")

    def _stop_login_stack(self, login: LoginSession) -> None:
        # Close Playwright first (flush profile state).
        try:
            if login.context is not None:
                login.context.close()
        except Exception:
            pass
        try:
            if login.pw is not None:
                login.pw.stop()
        except Exception:
            pass

        # Stop services.
        for proc in (login.websockify, login.x11vnc, login.xvfb):
            _terminate(proc)

    def _update_login_state_locked(self) -> None:
        if self.login is None:
            return

        page = self.login.page
        ctx = self.login.context

        if page is None or ctx is None:
            self.state = AUTH_REQUIRED
            return

        try:
            login_detected = bool(page.evaluate(_LOGIN_DETECT_JS))
            href = page.url or ""
        except Exception:
            self.state = AUTH_REQUIRED
            return

        if "checkpoint/challenge" in href or login_detected:
            self.state = LOGIN_RUNNING
        else:
            self.state = AUTH_OK


def _popen(argv: list[str]) -> subprocess.Popen:
    return subprocess.Popen(
        argv,
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
        start_new_session=True,
    )


def _terminate(proc: subprocess.Popen | None) -> None:
    if proc is None:
        return
    try:
        if proc.poll() is not None:
            return
        os.killpg(proc.pid, signal.SIGTERM)
        for _ in range(20):
            if proc.poll() is not None:
                return
            time.sleep(0.1)
        os.killpg(proc.pid, signal.SIGKILL)
    except Exception:
        pass


def _wait(seconds: float) -> None:
    time.sleep(seconds)


def _cleanup_profile_locks(profile_dir: str) -> None:
    for name in ("SingletonLock", "SingletonCookie", "SingletonSocket"):
        path = os.path.join(profile_dir, name)
        try:
            if os.path.exists(path) or os.path.islink(path):
                os.unlink(path)
        except FileNotFoundError:
            pass
        except Exception:
            # Best-effort only.
            pass


def _wait_for_x_socket(login: LoginSession) -> None:
    # Wait until the X socket appears so Chromium can connect reliably.
    display_num = login.display.lstrip(":")
    socket_path = f"/tmp/.X11-unix/X{display_num}"

    started = time.monotonic()
    timeout_s = 3.5

    while True:
        if login.xvfb and login.xvfb.poll() is not None:
            raise RuntimeError("Xvfb exited unexpectedly")
        if os.path.exists(socket_path):
            return
        if (time.monotonic() - started) > timeout_s:
            raise RuntimeError("Xvfb did not create X socket in time")
        time.sleep(0.1)
