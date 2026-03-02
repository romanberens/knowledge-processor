from __future__ import annotations

import os
import pathlib
import re
import signal
import subprocess
import time
from contextlib import contextmanager
from typing import Any, Callable

from playwright.sync_api import Error as PlaywrightError
from playwright.sync_api import sync_playwright

from .config import Settings

LOGIN_DETECT_JS = r"""
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

SNAPSHOT_JS = r"""
() => {
  const asText = (el) => ((el && el.innerText) ? el.innerText.trim() : '');
  const assistantNodes = Array.from(document.querySelectorAll('[data-message-author-role="assistant"]'));
  const userNodes = Array.from(document.querySelectorAll('[data-message-author-role="user"]'));
  const lastAssistant = assistantNodes.length ? assistantNodes[assistantNodes.length - 1] : null;
  const lastUser = userNodes.length ? userNodes[userNodes.length - 1] : null;
  const stopBtn = document.querySelector('button[data-testid="stop-button"], button[aria-label*="Stop" i], button[aria-label*="Przerwij" i]');
  const sendBtn = document.querySelector('button[data-testid="send-button"], button[aria-label*="Send" i], button[aria-label*="Wyślij" i]');

  return {
    url: String(window.location.href || ''),
    assistant_count: assistantNodes.length,
    user_count: userNodes.length,
    last_assistant_text: asText(lastAssistant),
    last_user_text: asText(lastUser),
    stop_visible: Boolean(stopBtn),
    send_visible: Boolean(sendBtn),
  };
}
"""

COMPOSER_SELECTORS = [
    "#prompt-textarea",
    "textarea[data-testid='prompt-textarea']",
    "textarea[placeholder*='Message']",
    "textarea[placeholder*='Zapytaj']",
    "[contenteditable='true'][id='prompt-textarea']",
]

COMPARISON_BUTTON_LABEL_RE = re.compile(
    r"wolę tę odpowiedź|wole te odpowiedz|choose this response|i prefer this response",
    re.IGNORECASE,
)

HISTORY_MESSAGES_JS = r"""
() => {
  const guessMime = (kind, url) => {
    const u = String(url || '').toLowerCase();
    if (kind === 'image') return 'image/*';
    if (kind === 'video') return 'video/*';
    if (kind === 'audio') return 'audio/*';
    if (u.endsWith('.pdf')) return 'application/pdf';
    if (u.endsWith('.png')) return 'image/png';
    if (u.endsWith('.jpg') || u.endsWith('.jpeg')) return 'image/jpeg';
    if (u.endsWith('.gif')) return 'image/gif';
    if (u.endsWith('.webp')) return 'image/webp';
    if (u.endsWith('.svg')) return 'image/svg+xml';
    if (u.endsWith('.mp4')) return 'video/mp4';
    if (u.endsWith('.webm')) return 'video/webm';
    if (u.endsWith('.mp3')) return 'audio/mpeg';
    if (u.endsWith('.wav')) return 'audio/wav';
    return '';
  };
  const fileNameFromUrl = (url) => {
    try {
      const parsed = new URL(String(url || ''), window.location.origin);
      const path = parsed.pathname || '';
      const last = path.split('/').filter(Boolean).pop() || '';
      return decodeURIComponent(last || '');
    } catch (_) {
      const u = String(url || '');
      const clean = u.split('?')[0].split('#')[0];
      const last = clean.split('/').filter(Boolean).pop() || '';
      return last;
    }
  };
  const collectAttachments = (rootEl) => {
    const out = [];
    const seen = new Set();
    const pushItem = (item) => {
      if (!item || typeof item !== 'object') return;
      const url = String(item.url || '').trim();
      if (url === '') return;
      const key = String(item.kind || 'file') + '|' + url;
      if (seen.has(key)) return;
      seen.add(key);
      out.push({
        kind: String(item.kind || 'file'),
        url: url,
        file_name: String(item.file_name || '').trim() || fileNameFromUrl(url) || 'attachment',
        mime_type: String(item.mime_type || '').trim() || guessMime(String(item.kind || 'file'), url),
      });
    };

    const imgs = Array.from(rootEl.querySelectorAll('img[src]'));
    imgs.forEach((el) => {
      const src = String(el.getAttribute('src') || '').trim();
      if (!src) return;
      pushItem({ kind: 'image', url: src, file_name: String(el.getAttribute('alt') || '').trim() });
    });

    const vids = Array.from(rootEl.querySelectorAll('video[src], video source[src]'));
    vids.forEach((el) => {
      const src = String(el.getAttribute('src') || '').trim();
      if (!src) return;
      pushItem({ kind: 'video', url: src, mime_type: String(el.getAttribute('type') || '').trim() });
    });

    const auds = Array.from(rootEl.querySelectorAll('audio[src], audio source[src]'));
    auds.forEach((el) => {
      const src = String(el.getAttribute('src') || '').trim();
      if (!src) return;
      pushItem({ kind: 'audio', url: src, mime_type: String(el.getAttribute('type') || '').trim() });
    });

    const links = Array.from(rootEl.querySelectorAll('a[href]'));
    links.forEach((el) => {
      const href = String(el.getAttribute('href') || '').trim();
      if (!href) return;
      let abs = href;
      try {
        abs = String(new URL(href, window.location.origin).toString());
      } catch (_) {
        abs = href;
      }
      const low = abs.toLowerCase();
      const kind = low.endsWith('.pdf') ? 'pdf' : 'file';
      const textName = String((el.textContent || '')).trim();
      pushItem({ kind: kind, url: abs, file_name: textName });
    });

    return out;
  };

  const norm = (txt) => String(txt || '')
    .replace(/\u200b/g, '')
    .replace(/\s+\n/g, '\n')
    .replace(/\n{3,}/g, '\n\n')
    .trim();

  const nodes = Array.from(
    document.querySelectorAll('[data-message-author-role="user"], [data-message-author-role="assistant"]')
  );
  const out = [];
  nodes.forEach((el, idx) => {
    const role = String(el.getAttribute('data-message-author-role') || '').trim().toLowerCase();
    if (role !== 'user' && role !== 'assistant') {
      return;
    }
    const text = norm((el && el.innerText) ? el.innerText : '');
    if (text === '') {
      return;
    }
    const attachments = collectAttachments(el);
    out.push({
      index: idx,
      role: role,
      text: text,
      attachments: attachments,
    });
  });
  return out;
}
"""

THREADS_INDEX_JS = r"""
() => {
  const norm = (txt) => String(txt || '')
    .replace(/\u200b/g, '')
    .replace(/\s+/g, ' ')
    .trim();
  const toAbs = (href) => {
    try {
      return String(new URL(String(href || ''), window.location.origin).toString());
    } catch (_) {
      return String(href || '');
    }
  };

  const anchors = Array.from(document.querySelectorAll('a[href*="/c/"]'));
  const out = [];
  const seen = new Set();
  anchors.forEach((a) => {
    const abs = toAbs(a.getAttribute('href') || a.href || '');
    if (!abs) return;
    const noQuery = abs.split('?')[0].split('#')[0];
    const m = noQuery.match(/\/c\/([a-zA-Z0-9-]+)/);
    if (!m || !m[1]) return;
    const remoteId = String(m[1]).trim();
    if (!remoteId || seen.has(remoteId)) return;
    seen.add(remoteId);

    let title = norm(a.getAttribute('aria-label') || '');
    if (!title) {
      title = norm(a.textContent || '');
    }
    if (!title) {
      title = remoteId;
    }

    out.push({
      remote_thread_id: remoteId,
      conversation_url: noQuery,
      title: title,
    });
  });
  return out;
}
"""


def _new_context_kwargs(settings: Settings) -> dict[str, Any]:
    kwargs: dict[str, Any] = {
        "user_data_dir": settings.profile_dir,
        "headless": settings.headless_exchange,
        "viewport": {"width": 1280, "height": 900},
        "timeout": settings.exchange_timeout_ms,
        "args": [
            "--disable-blink-features=AutomationControlled",
            "--disable-dev-shm-usage",
        ],
    }
    browser_bin = os.getenv("CHROMIUM_BIN", "").strip()
    if browser_bin:
        kwargs["executable_path"] = browser_bin
    return kwargs


def _is_challenge_title(page_title: str) -> bool:
    t = page_title.strip().lower()
    if t == "":
        return False
    return any(
        key in t
        for key in (
            "cierpliwo",
            "just a moment",
            "checking your browser",
            "attention required",
        )
    )


def _wait_for_x_socket(display: str, timeout_seconds: float = 5.0) -> bool:
    d = display.strip()
    if not d.startswith(":"):
        return False
    disp_num = d[1:]
    if not disp_num.isdigit():
        return False
    socket_path = pathlib.Path(f"/tmp/.X11-unix/X{disp_num}")
    deadline = time.monotonic() + timeout_seconds
    while time.monotonic() < deadline:
        if socket_path.exists():
            return True
        time.sleep(0.05)
    return socket_path.exists()


def _pick_display() -> str:
    for num in range(97, 89, -1):
        candidate = f":{num}"
        socket_path = pathlib.Path(f"/tmp/.X11-unix/X{num}")
        if not socket_path.exists():
            return candidate
    return ":97"


def _terminate(proc: subprocess.Popen[Any] | None) -> None:
    if proc is None:
        return
    if proc.poll() is not None:
        return
    try:
        proc.terminate()
        proc.wait(timeout=2)
        return
    except Exception:
        pass
    try:
        os.killpg(proc.pid, signal.SIGTERM)
    except Exception:
        pass
    try:
        proc.wait(timeout=2)
        return
    except Exception:
        pass
    try:
        proc.kill()
    except Exception:
        pass


@contextmanager
def _xvfb_context(enabled: bool):
    if not enabled:
        yield
        return

    existing_display = os.getenv("DISPLAY", "").strip()
    if existing_display:
        yield
        return

    display = _pick_display()
    proc = subprocess.Popen(
        [
            "Xvfb",
            display,
            "-screen",
            "0",
            "1400x900x24",
            "-ac",
            "-nolisten",
            "tcp",
        ],
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
        start_new_session=True,
    )
    if not _wait_for_x_socket(display, timeout_seconds=5.0):
        _terminate(proc)
        raise RuntimeError("XVFB_START_FAILED")

    previous = os.getenv("DISPLAY")
    os.environ["DISPLAY"] = display
    try:
        yield
    finally:
        if previous is None:
            os.environ.pop("DISPLAY", None)
        else:
            os.environ["DISPLAY"] = previous
        _terminate(proc)


def _open_chat_page(page, settings: Settings, conversation_url: str | None = None) -> str:
    target_url = str(conversation_url or "").strip() or settings.auth_check_url or "https://chatgpt.com/"
    page.goto(target_url, wait_until="domcontentloaded", timeout=settings.exchange_timeout_ms)
    page.wait_for_timeout(1400)
    if _is_challenge_title(page.title()):
        raise RuntimeError("CHALLENGE_PAGE")
    return target_url


def _ensure_authenticated(page) -> None:
    try:
        need_login = bool(page.evaluate(LOGIN_DETECT_JS))
    except PlaywrightError as exc:
        raise RuntimeError(f"Cannot evaluate auth state: {exc}")
    if need_login:
        raise RuntimeError("AUTH_REQUIRED")


def _find_composer(page):
    for selector in COMPOSER_SELECTORS:
        loc = page.locator(selector).first
        try:
            if loc.count() > 0:
                loc.wait_for(state="visible", timeout=5000)
                return loc
        except PlaywrightError:
            continue
    raise RuntimeError("Composer input not found in ChatGPT UI")


def _snapshot(page) -> dict[str, Any]:
    raw = page.evaluate(SNAPSHOT_JS)
    if not isinstance(raw, dict):
        return {
            "url": str(page.url),
            "assistant_count": 0,
            "user_count": 0,
            "last_assistant_text": "",
            "last_user_text": "",
            "stop_visible": False,
            "send_visible": False,
        }
    return raw


def _comparison_buttons(page):
    return page.get_by_role("button", name=COMPARISON_BUTTON_LABEL_RE)


def _clean_comparison_text(text: str) -> str:
    if text == "":
        return ""
    lines = [ln.strip() for ln in text.splitlines()]
    out: list[str] = []
    for ln in lines:
        if ln == "":
            continue
        if COMPARISON_BUTTON_LABEL_RE.search(ln):
            continue
        out.append(ln)
    return "\n".join(out).strip()


def _capture_comparison_options(page, max_options: int = 2) -> list[dict[str, Any]]:
    try:
        buttons = _comparison_buttons(page)
        count = int(buttons.count() or 0)
    except PlaywrightError:
        return []

    if count <= 0:
        return []

    options: list[dict[str, Any]] = []
    capture_n = min(count, max_options)
    for i in range(capture_n):
        text = ""
        try:
            btn = buttons.nth(i)
            text = str(
                btn.evaluate(
                    """(el) => {
                      const card =
                        el.closest('article') ||
                        el.closest('section') ||
                        el.closest('[role="group"]') ||
                        el.closest('div');
                      return String((card && card.innerText) ? card.innerText : '');
                    }"""
                )
                or ""
            )
        except PlaywrightError:
            text = ""

        cleaned = _clean_comparison_text(text)
        if cleaned == "":
            continue
        options.append(
            {
                "index": i,
                "label": f"Odpowiedź {i + 1}",
                "text": cleaned,
            }
        )
    return options


def _resolve_comparison_gate(page, preference: str = "first") -> dict[str, Any]:
    try:
        buttons = _comparison_buttons(page)
        count = int(buttons.count() or 0)
    except PlaywrightError:
        return {"detected": False, "handled": False, "count": 0}

    if count < 1:
        return {"detected": False, "handled": False, "count": 0}

    idx = 1 if preference == "second" and count > 1 else 0
    try:
        target = buttons.nth(idx)
        target.scroll_into_view_if_needed(timeout=2000)
        target.click(timeout=5000)
        page.wait_for_timeout(900)
        return {"detected": True, "handled": True, "count": count, "selected_index": idx}
    except PlaywrightError:
        return {"detected": True, "handled": False, "count": count, "selected_index": idx}


def _history_scroll_to_top(page, rounds: int = 7) -> None:
    # Long conversations can lazy-load older turns while scrolling up.
    for _ in range(max(1, rounds)):
        try:
            page.evaluate(
                """() => {
                  const candidates = Array.from(document.querySelectorAll('main, [role="main"], [data-testid*="conversation" i], [class*="conversation" i], [class*="thread" i], .overflow-y-auto'));
                  candidates.forEach((el) => {
                    if (!el || typeof el.scrollTop !== 'number') return;
                    el.scrollTop = 0;
                  });
                  window.scrollTo(0, 0);
                }"""
            )
        except PlaywrightError:
            pass
        page.wait_for_timeout(300)


def _scroll_threads_index_to_bottom(page) -> int:
    try:
        moved = page.evaluate(
            """() => {
              const buckets = [
                ...Array.from(document.querySelectorAll('aside, nav, [role="navigation"], [class*="sidebar" i], [class*="rail" i], [class*="history" i], [class*="scroll" i], [class*="overflow" i]')),
                document.scrollingElement,
              ];
              let moved = 0;
              buckets.forEach((el) => {
                if (!el || typeof el.scrollHeight !== 'number' || typeof el.clientHeight !== 'number' || typeof el.scrollTop !== 'number') {
                  return;
                }
                if (el.scrollHeight <= (el.clientHeight + 8)) {
                  return;
                }
                const before = el.scrollTop;
                el.scrollTop = el.scrollHeight;
                if (Math.abs(el.scrollTop - before) > 1) {
                  moved += 1;
                }
              });
              window.scrollTo(0, document.body.scrollHeight);
              return moved;
            }"""
        )
        if isinstance(moved, int):
            return moved
    except PlaywrightError:
        return 0
    return 0


def scan_threads_index_once(
    settings: Settings,
    *,
    max_rounds: int = 4000,
    stable_rounds: int = 20,
    on_progress: Callable[[dict[str, Any]], None] | None = None,
) -> dict[str, Any]:
    rounds_limit = max(8, min(int(max_rounds), 20000))
    stable_limit = max(3, min(int(stable_rounds), 400))

    with _xvfb_context(enabled=not settings.headless_exchange):
        with sync_playwright() as p:
            context = p.chromium.launch_persistent_context(**_new_context_kwargs(settings))
            try:
                context.add_init_script(
                    "Object.defineProperty(navigator, 'webdriver', {get: () => undefined});"
                )
                page = context.new_page()
                page.set_default_timeout(settings.exchange_timeout_ms)

                opened_url = _open_chat_page(page, settings, conversation_url=None)
                _ensure_authenticated(page)
                page.wait_for_timeout(1200)
                started_ts = time.monotonic()

                seen_map: dict[str, dict[str, Any]] = {}
                no_growth_cycles = 0
                rounds_done = 0
                last_visible_count = 0
                last_added = 0
                scroll_ops = 0
                scroll_moved_total = 0

                def _emit(action: str, **extra: Any) -> None:
                    if on_progress is None:
                        return
                    payload = {
                        "action": str(action or "").strip() or "scan",
                        "round": rounds_done,
                        "max_rounds": rounds_limit,
                        "stable_rounds": no_growth_cycles,
                        "stable_target": stable_limit,
                        "visible_count": last_visible_count,
                        "total_found": len(seen_map),
                        "added": last_added,
                        "scroll_ops": scroll_ops,
                        "scroll_moved_total": scroll_moved_total,
                        "elapsed_ms": int((time.monotonic() - started_ts) * 1000),
                    }
                    payload.update(extra)
                    try:
                        on_progress(payload)
                    except Exception:
                        pass

                def _harvest_visible() -> tuple[int, int]:
                    raw_local = page.evaluate(THREADS_INDEX_JS)
                    visible_count_local = 0
                    added_local = 0
                    if isinstance(raw_local, list):
                        for item in raw_local:
                            if not isinstance(item, dict):
                                continue
                            remote_id = str(item.get("remote_thread_id") or "").strip()
                            conversation_url = str(item.get("conversation_url") or "").strip()
                            title = str(item.get("title") or "").strip()
                            if remote_id == "" or conversation_url == "":
                                continue
                            visible_count_local += 1
                            if title == "":
                                title = remote_id
                            if remote_id not in seen_map:
                                seen_map[remote_id] = {
                                    "remote_thread_id": remote_id,
                                    "conversation_url": conversation_url,
                                    "title": title,
                                }
                                added_local += 1
                            else:
                                existing = seen_map[remote_id]
                                if str(existing.get("title") or "").strip() == "" and title != "":
                                    existing["title"] = title
                                if str(existing.get("conversation_url") or "").strip() == "" and conversation_url != "":
                                    existing["conversation_url"] = conversation_url
                    return visible_count_local, added_local

                for i in range(rounds_limit):
                    rounds_done = i + 1
                    _emit("reading_visible")
                    visible_count, added = _harvest_visible()
                    last_visible_count = visible_count
                    last_added = added

                    if added > 0:
                        no_growth_cycles = 0
                        _emit("new_threads_detected")
                    else:
                        cycle_gained = 0
                        for wait_ms in (420, 900, 1600, 2500):
                            moved = _scroll_threads_index_to_bottom(page)
                            scroll_ops += 1
                            scroll_moved_total += int(moved or 0)
                            _emit("scrolling", wait_ms=wait_ms, moved=int(moved or 0))
                            page.mouse.wheel(0, 5000)
                            _emit("waiting_lazy_load", wait_ms=wait_ms)
                            page.wait_for_timeout(wait_ms)
                            _emit("rechecking_visible", wait_ms=wait_ms)
                            visible_count_retry, added_retry = _harvest_visible()
                            last_visible_count = visible_count_retry
                            if added_retry > 0:
                                cycle_gained += added_retry
                                break
                        if cycle_gained > 0:
                            no_growth_cycles = 0
                            last_added = cycle_gained
                            _emit("new_threads_detected_after_wait")
                        else:
                            no_growth_cycles += 1
                            _emit("no_growth_cycle")

                    # Jitter scroll to trigger lazy-loaders that depend on scroll events.
                    try:
                        page.mouse.wheel(0, -500)
                        page.mouse.wheel(0, 1200)
                        scroll_ops += 2
                    except PlaywrightError:
                        pass
                    page.wait_for_timeout(240)

                    if no_growth_cycles >= stable_limit:
                        break

                end_reason = "stable_no_growth" if no_growth_cycles >= stable_limit else "max_rounds_reached"
                _emit("finished", end_reason=end_reason)

                return {
                    "ok": True,
                    "opened_url": opened_url,
                    "url": str(page.url or opened_url),
                    "items": list(seen_map.values()),
                    "count": len(seen_map),
                    "rounds": rounds_done,
                    "stable_rounds": no_growth_cycles,
                    "stable_target": stable_limit,
                    "end_reason": end_reason,
                    "visible_last_count": last_visible_count,
                    "last_added": last_added,
                    "scroll_ops": scroll_ops,
                    "scroll_moved_total": scroll_moved_total,
                }
            finally:
                context.close()


def sync_history_once(
    settings: Settings,
    conversation_url: str,
) -> dict[str, Any]:
    conv_url = str(conversation_url or "").strip()
    if conv_url == "":
        raise RuntimeError("CONVERSATION_URL_REQUIRED")

    with _xvfb_context(enabled=not settings.headless_exchange):
        with sync_playwright() as p:
            context = p.chromium.launch_persistent_context(**_new_context_kwargs(settings))
            try:
                context.add_init_script(
                    "Object.defineProperty(navigator, 'webdriver', {get: () => undefined});"
                )
                page = context.new_page()
                page.set_default_timeout(settings.exchange_timeout_ms)

                opened_url = _open_chat_page(page, settings, conversation_url=conv_url)
                _ensure_authenticated(page)
                _history_scroll_to_top(page, rounds=8)

                raw = page.evaluate(HISTORY_MESSAGES_JS)
                messages: list[dict[str, Any]] = []
                if isinstance(raw, list):
                    for item in raw:
                        if not isinstance(item, dict):
                            continue
                        role = str(item.get("role") or "").strip().lower()
                        text = str(item.get("text") or "").strip()
                        if role not in ("user", "assistant") or text == "":
                            continue
                        idx_raw = item.get("index")
                        idx = int(idx_raw) if isinstance(idx_raw, int) else len(messages)
                        attachments_raw = item.get("attachments")
                        attachments: list[dict[str, Any]] = []
                        if isinstance(attachments_raw, list):
                            for att in attachments_raw:
                                if not isinstance(att, dict):
                                    continue
                                url = str(att.get("url") or "").strip()
                                if url == "":
                                    continue
                                attachments.append(
                                    {
                                        "kind": str(att.get("kind") or "file").strip() or "file",
                                        "url": url,
                                        "file_name": str(att.get("file_name") or "").strip(),
                                        "mime_type": str(att.get("mime_type") or "").strip(),
                                    }
                                )
                        messages.append(
                            {
                                "index": idx,
                                "role": role,
                                "text": text,
                                "attachments": attachments,
                            }
                        )

                return {
                    "ok": True,
                    "opened_url": opened_url,
                    "conversation_url": str(page.url or conv_url),
                    "messages": messages,
                    "count": len(messages),
                }
            finally:
                context.close()


def exchange_once(
    settings: Settings,
    prompt: str,
    mode: str = "default",
    comparison_preference: str = "first",
    conversation_url: str | None = None,
    on_partial: Callable[[str, dict[str, Any]], None] | None = None,
) -> dict[str, Any]:
    prompt = prompt.strip()
    if not prompt:
        raise RuntimeError("Prompt is empty")

    started = time.monotonic()
    with _xvfb_context(enabled=not settings.headless_exchange):
        with sync_playwright() as p:
            context = p.chromium.launch_persistent_context(**_new_context_kwargs(settings))
            try:
                context.add_init_script(
                    "Object.defineProperty(navigator, 'webdriver', {get: () => undefined});"
                )
                page = context.new_page()
                page.set_default_timeout(settings.exchange_timeout_ms)

                opened_url = _open_chat_page(page, settings, conversation_url=conversation_url)
                _ensure_authenticated(page)

                before = _snapshot(page)
                before_assistant_count = int(before.get("assistant_count") or 0)
                before_last_assistant = str(before.get("last_assistant_text") or "")

                composer = _find_composer(page)
                composer.click()
                try:
                    composer.fill(prompt)
                except PlaywrightError:
                    page.keyboard.type(prompt)

                page.keyboard.press("Enter")

                deadline = time.monotonic() + (float(settings.exchange_timeout_ms) / 1000.0)
                stable_seconds = float(settings.exchange_response_stable_ms) / 1000.0
                last_text = ""
                stable_since: float | None = None
                final_snapshot: dict[str, Any] | None = None
                comparison_handled = False
                comparison_selected_index: int | None = None
                comparison_options: list[dict[str, Any]] = []

                while time.monotonic() < deadline:
                    # Handle "choose preferred response" cards (A/B answers) before evaluating normal output.
                    if not comparison_handled:
                        if not comparison_options:
                            comparison_options = _capture_comparison_options(page, max_options=2)
                        gate = _resolve_comparison_gate(page, preference=comparison_preference)
                        if bool(gate.get("handled")):
                            comparison_handled = True
                            selected = gate.get("selected_index")
                            comparison_selected_index = int(selected) if isinstance(selected, int) else None

                    snap = _snapshot(page)
                    final_snapshot = snap
                    current_count = int(snap.get("assistant_count") or 0)
                    current_text = str(snap.get("last_assistant_text") or "").strip()
                    stop_visible = bool(snap.get("stop_visible"))

                    has_new_turn = current_count > before_assistant_count
                    has_new_text = current_text != "" and current_text != before_last_assistant

                    if has_new_text or has_new_turn:
                        if current_text != last_text:
                            last_text = current_text
                            stable_since = time.monotonic()
                            if on_partial is not None and current_text != "":
                                try:
                                    on_partial(
                                        current_text,
                                        {
                                            "assistant_count": current_count,
                                            "user_count": int(snap.get("user_count") or 0),
                                            "conversation_url": str(snap.get("url") or page.url),
                                            "opened_url": opened_url,
                                            "comparison_gate_handled": comparison_handled,
                                            "comparison_selected_index": comparison_selected_index,
                                            "comparison_options": comparison_options,
                                        },
                                    )
                                except Exception:
                                    # Callback failures must not break the exchange loop.
                                    pass
                        elif stable_since is not None and (time.monotonic() - stable_since) >= stable_seconds and not stop_visible:
                            elapsed_ms = int((time.monotonic() - started) * 1000)
                            return {
                                "ok": True,
                                "assistant_text": current_text,
                                "assistant_count": current_count,
                                "user_count": int(snap.get("user_count") or 0),
                                "conversation_url": str(snap.get("url") or page.url),
                                "elapsed_ms": elapsed_ms,
                                "mode": mode,
                                "opened_url": opened_url,
                                "comparison_gate_handled": comparison_handled,
                                "comparison_selected_index": comparison_selected_index,
                                "comparison_options": comparison_options,
                            }

                    page.wait_for_timeout(700)

                if final_snapshot is None:
                    final_snapshot = _snapshot(page)

                preview = str(final_snapshot.get("last_assistant_text") or "")[:160]
                raise RuntimeError(
                    "Assistant response timeout"
                    + (f" (last={preview!r})" if preview else "")
                )
            finally:
                context.close()
