from __future__ import annotations

import glob
import os
import signal
import shutil
import subprocess
import threading
import time
import uuid
from dataclasses import dataclass
from datetime import datetime, timezone
from typing import Any

from playwright.sync_api import sync_playwright

from .config import Settings

AUTH_UNKNOWN = "AUTH_UNKNOWN"
AUTH_REQUIRED = "AUTH_REQUIRED"
LOGIN_RUNNING = "LOGIN_RUNNING"
AUTH_OK = "AUTH_OK"

_LOGIN_DETECT_JS = r"""
() => {
  const href = (window.location.href || '').toLowerCase();
  const onLoginUrl = /(login|signin|auth|account)/.test(href);

  const passwordInput = document.querySelector('input[type="password"], input[name*="pass" i], input[id*="pass" i]');
  const emailInput = document.querySelector('input[type="email"], input[name*="email" i], input[id*="email" i], input[name*="user" i], input[id*="user" i]');

  const signInButtons = Array.from(document.querySelectorAll('button, a, [role="button"]')).some((el) => {
    const t = ((el.textContent || '') + ' ' + (el.getAttribute('aria-label') || '')).toLowerCase();
    return /sign in|log in|zaloguj|zarejestruj|continue with|kontynuuj/.test(t);
  });

  return Boolean((onLoginUrl && (passwordInput || emailInput || signInButtons)) || passwordInput);
}
"""


def _now_iso() -> str:
    return datetime.now(timezone.utc).isoformat()


def _auth_probe_url(settings: Settings) -> str:
    if settings.auth_check_url:
        return settings.auth_check_url
    return settings.login_url


def check_authenticated(settings: Settings) -> bool:
    probe_url = _auth_probe_url(settings)
    if not probe_url:
        return False

    with sync_playwright() as p:
        context = p.chromium.launch_persistent_context(
            user_data_dir=settings.profile_dir,
            headless=settings.headless_auth_check,
            viewport={"width": 1280, "height": 900},
            timeout=settings.auth_check_timeout_ms,
        )
        try:
            context.set_default_timeout(settings.auth_check_timeout_ms)
            page = context.new_page()
            page.set_default_timeout(settings.auth_check_timeout_ms)
            page.goto(probe_url, wait_until="domcontentloaded", timeout=settings.auth_check_timeout_ms)
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
    browser_proc: subprocess.Popen | None = None


class AuthController:
    def __init__(self, settings: Settings, profile_lock: threading.Lock) -> None:
        self.settings = settings
        self._lock = threading.Lock()
        self._profile_lock = profile_lock
        self.state = AUTH_UNKNOWN
        self.login: LoginSession | None = None
        self.last_check_at: str | None = None
        self._last_check_ts: float | None = None

    def _status_locked(self) -> dict[str, Any]:
        return {
            "state": self.state,
            "target_name": self.settings.target_name,
            "login_url": self.settings.login_url,
            "login_session_id": self.login.session_id if self.login else None,
            "novnc_url": self.login.novnc_url if self.login else None,
            "started_at": self.login.started_at if self.login else None,
            "last_check_at": self.last_check_at,
        }

    def status(self) -> dict[str, Any]:
        with self._lock:
            return self._status_locked()

    def refresh_status(self) -> dict[str, Any]:
        with self._lock:
            if self.login is not None:
                self._update_login_state_locked()
                self.last_check_at = _now_iso()
                self._last_check_ts = time.time()
                return self._status_locked()

            if (
                self._last_check_ts is not None
                and (time.time() - self._last_check_ts) < float(self.settings.status_cache_ttl_seconds)
                and self.state in {AUTH_OK, AUTH_REQUIRED, AUTH_UNKNOWN}
            ):
                return self._status_locked()

        acquired = self._profile_lock.acquire(timeout=0.1)
        if not acquired:
            with self._lock:
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
        if not self.settings.login_url:
            raise RuntimeError("SESSION_LOGIN_URL is empty")

        with self._lock:
            if self.login is not None:
                self._update_login_state_locked()
                return self._status_locked()

        if not self._profile_lock.acquire(timeout=0.1):
            raise RuntimeError("Profile is busy")

        _cleanup_profile_locks(self.settings.profile_dir)

        login = LoginSession(
            session_id=uuid.uuid4().hex[:12],
            started_at=_now_iso(),
            novnc_url=self.settings.novnc_public_url,
            display=self.settings.xvfb_display,
        )

        try:
            self._start_login_stack(login)
        except Exception:
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

        return self.refresh_status()

    def _start_login_stack(self, login: LoginSession) -> None:
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

        # Launch a regular Chromium process (not Playwright-driven) to avoid
        # "controlled by automated test software" Google account login blocks.
        browser_bin = _find_chromium_binary()
        env = os.environ.copy()
        env["DISPLAY"] = login.display
        argv = [
            browser_bin,
            "--user-data-dir=" + self.settings.profile_dir,
            "--no-first-run",
            "--no-default-browser-check",
            "--disable-dev-shm-usage",
            "--window-size=1400,900",
            "--new-window",
            self.settings.login_url,
        ]
        # Container often runs as root; Chromium requires --no-sandbox in that case.
        if os.geteuid() == 0:
            argv.insert(1, "--no-sandbox")

        login.browser_proc = _popen(argv, env=env)
        _wait(0.8)

    def _stop_login_stack(self, login: LoginSession) -> None:
        _terminate(login.browser_proc)

        for proc in (login.websockify, login.x11vnc, login.xvfb):
            _terminate(proc)

    def _update_login_state_locked(self) -> None:
        if self.login is None:
            return

        browser = self.login.browser_proc
        if browser is not None and browser.poll() is None:
            self.state = LOGIN_RUNNING
            return

        # Browser window has been closed unexpectedly; best-effort probe.
        try:
            ok = check_authenticated(self.settings)
            self.state = AUTH_OK if ok else AUTH_REQUIRED
        except Exception:
            self.state = AUTH_UNKNOWN


def _find_chromium_binary() -> str:
    env_bin = os.getenv("CHROMIUM_BIN", "").strip()
    candidates: list[str] = []
    if env_bin:
        candidates.append(env_bin)

    for name in ("google-chrome", "chromium-browser", "chromium"):
        found = shutil.which(name)
        if found:
            candidates.append(found)

    # Playwright image path fallback.
    candidates.extend(sorted(glob.glob("/ms-playwright/chromium-*/chrome-linux/chrome")))

    for cand in candidates:
        if cand and os.path.isfile(cand) and os.access(cand, os.X_OK):
            return cand

    raise RuntimeError("Chromium binary not found. Set CHROMIUM_BIN or install chromium.")


def _popen(argv: list[str], env: dict[str, str] | None = None) -> subprocess.Popen:
    return subprocess.Popen(
        argv,
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
        env=env,
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
            pass


def _wait_for_x_socket(login: LoginSession) -> None:
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
