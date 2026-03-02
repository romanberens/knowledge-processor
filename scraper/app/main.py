from __future__ import annotations

import logging
import threading
from datetime import datetime, timezone
from typing import Any

from fastapi import FastAPI, HTTPException, Query

from .auth import AuthController, AUTH_OK, AUTH_REQUIRED, LOGIN_RUNNING
from .config import get_settings
from .db import Database
from .linkedin_activity import LinkedInActivityScraper

logger = logging.getLogger(__name__)
STALE_RUN_RECOVERY_MINUTES = 10
STALE_RUN_RECOVERY_REASON = "Recovered stale running run after scraper restart"


class RuntimeState:
    def __init__(self) -> None:
        self._lock = threading.Lock()
        self.running = False
        self.mode: str | None = None
        self.started_at: datetime | None = None
        self.finished_at: datetime | None = None
        self.run_id: int | None = None
        self.progress: dict[str, Any] = {}
        self.last_result: dict[str, Any] = {}

    def start(self, mode: str) -> bool:
        with self._lock:
            if self.running:
                return False
            self.running = True
            self.mode = mode
            self.started_at = datetime.now(timezone.utc)
            self.finished_at = None
            self.run_id = None
            self.progress = {"message": "job accepted"}
            return True

    def attach_run_id(self, run_id: int) -> None:
        with self._lock:
            self.run_id = run_id

    def update_progress(self, payload: dict[str, Any]) -> None:
        with self._lock:
            self.progress = payload

    def finish(self, result: dict[str, Any]) -> None:
        with self._lock:
            self.running = False
            self.finished_at = datetime.now(timezone.utc)
            self.last_result = result

    def fail(self, error_message: str) -> None:
        with self._lock:
            self.running = False
            self.finished_at = datetime.now(timezone.utc)
            self.last_result = {"status": "error", "message": error_message}

    def snapshot(self) -> dict[str, Any]:
        with self._lock:
            return {
                "running": self.running,
                "mode": self.mode,
                "run_id": self.run_id,
                "started_at": self.started_at.isoformat() if self.started_at else None,
                "finished_at": self.finished_at.isoformat() if self.finished_at else None,
                "progress": self.progress,
                "last_result": self.last_result,
            }


settings = get_settings()
db = Database(settings)
state = RuntimeState()
profile_lock = threading.Lock()
auth = AuthController(settings=settings, profile_lock=profile_lock)

app = FastAPI(title="LinkedIn Archive Scraper", version="0.1.0")


def _recover_stale_runs_if_idle() -> int:
    if state.snapshot().get("running"):
        return 0
    recovered = db.recover_stale_running_runs(
        reason=STALE_RUN_RECOVERY_REASON,
        min_age_minutes=STALE_RUN_RECOVERY_MINUTES,
    )
    if recovered > 0:
        logger.warning("Recovered %d stale run(s) from DB state.", recovered)
    return recovered


@app.on_event("startup")
def startup_recovery() -> None:
    try:
        _recover_stale_runs_if_idle()
    except Exception:
        logger.exception("Failed to recover stale running runs on startup.")


def _execute_scrape(mode: str, scroll_limit: int | None, hydrate_limit: int | None) -> None:
    run_id: int | None = None
    try:
        run_id = db.create_run(mode)
        state.attach_run_id(run_id)
        scraper = LinkedInActivityScraper(settings=settings, db=db)
        # Exclusive access to the persistent Chromium profile dir.
        profile_lock.acquire()
        try:
            summary = scraper.run(
                mode=mode,
                run_id=run_id,
                progress_cb=state.update_progress,
                max_scrolls_override=scroll_limit,
                hydrate_limit_override=hydrate_limit,
            )
        finally:
            profile_lock.release()
        inserted = int(summary.get("inserted", 0))
        seen = int(summary.get("seen", 0))
        section_errors = summary.get("section_errors") or []
        final_status = "partial" if section_errors else "ok"
        if final_status == "partial" and inserted == 0 and seen == 0:
            final_status = "error"

        db.finish_run(
            run_id=run_id,
            status=final_status,
            new_posts=inserted,
            total_seen=seen,
            error_message=None if final_status == "ok" else "Some sections failed" if final_status == "partial" else "No data collected",
            details=summary,
        )
        state.finish({"status": final_status, "summary": summary})
    except Exception as exc:
        error_message = str(exc)
        if run_id is not None:
            db.finish_run(
                run_id=run_id,
                status="error",
                new_posts=0,
                total_seen=0,
                error_message=error_message,
                details={"error": error_message},
            )
        state.fail(error_message)


def _execute_hydrate_only(
    *,
    limit: int,
    max_content_len: int,
    only_without_notes: bool,
    source: str | None,
    activity_kind: str | None,
) -> None:
    run_id: int | None = None
    try:
        # runs.mode is an ENUM('deep','update'); store hydrate-only runs as update + details.job=hydrate_only.
        run_id = db.create_run("update")
        state.attach_run_id(run_id)
        scraper = LinkedInActivityScraper(settings=settings, db=db)
        profile_lock.acquire()
        try:
            summary = scraper.hydrate_only(
                run_id=run_id,
                limit=limit,
                max_content_len=max_content_len,
                only_without_notes=only_without_notes,
                source=source,
                activity_kind=activity_kind,
                progress_cb=state.update_progress,
            )
        finally:
            profile_lock.release()

        attempted = int(summary.get("permalink_hydrate_attempted", 0))
        failed = int(summary.get("permalink_hydrate_failed", 0))
        final_status = "partial" if failed > 0 else "ok"

        db.finish_run(
            run_id=run_id,
            status=final_status,
            new_posts=0,
            total_seen=attempted,
            error_message=None if final_status == "ok" else "Some permalinks failed",
            details=summary,
        )
        state.finish({"status": final_status, "summary": summary})
    except Exception as exc:
        error_message = str(exc)
        if run_id is not None:
            db.finish_run(
                run_id=run_id,
                status="error",
                new_posts=0,
                total_seen=0,
                error_message=error_message,
                details={"error": error_message, "job": "hydrate_only"},
            )
        state.fail(error_message)


@app.get("/health")
def health() -> dict[str, Any]:
    return {"ok": True, "db": db.ping()}


@app.get("/status")
def status() -> dict[str, Any]:
    return {
        "runtime": state.snapshot(),
        "latest_run": db.latest_run(),
        "total_posts": db.total_posts(),
        "auth": auth.status(),
    }


@app.post("/scrape")
def scrape(
    mode: str = Query("update", pattern="^(deep|update)$"),
    scroll_limit: int | None = Query(None, ge=1, le=2000),
    hydrate_limit: int | None = Query(None, ge=0, le=500),
) -> dict[str, Any]:
    auth_snapshot = auth.status()
    # Interactive login keeps the persistent Chromium profile open and must be
    # closed before scraping can use it.
    if auth_snapshot.get("login_session_id"):
        raise HTTPException(status_code=409, detail="LOGIN_SESSION_OPEN")
    if auth_snapshot.get("state") == LOGIN_RUNNING:
        raise HTTPException(status_code=409, detail="Login is running")

    auth_state = auth.refresh_status().get("state")
    if auth_state != AUTH_OK:
        raise HTTPException(status_code=412, detail=AUTH_REQUIRED)

    # Cleanup stale DB rows from old crashed processes before starting a new job.
    _recover_stale_runs_if_idle()

    if not state.start(mode):
        raise HTTPException(status_code=409, detail="Scraper is already running")

    thread = threading.Thread(
        target=_execute_scrape,
        args=(mode, scroll_limit, hydrate_limit),
        daemon=True,
    )
    thread.start()
    return {
        "accepted": True,
        "mode": mode,
        "scroll_limit": scroll_limit,
        "hydrate_limit": hydrate_limit,
    }


@app.post("/hydrate")
def hydrate(
    limit: int = Query(20, ge=1, le=200),
    max_content_len: int = Query(1200, ge=50, le=20000),
    only_without_notes: bool = Query(False),
    source: str | None = Query(None),
    kind: str | None = Query(None),
) -> dict[str, Any]:
    auth_snapshot = auth.status()
    if auth_snapshot.get("login_session_id"):
        raise HTTPException(status_code=409, detail="LOGIN_SESSION_OPEN")
    if auth_snapshot.get("state") == LOGIN_RUNNING:
        raise HTTPException(status_code=409, detail="Login is running")

    auth_state = auth.refresh_status().get("state")
    if auth_state != AUTH_OK:
        raise HTTPException(status_code=412, detail=AUTH_REQUIRED)

    # Cleanup stale DB rows from old crashed processes before starting a new job.
    _recover_stale_runs_if_idle()

    if not state.start("hydrate_only"):
        raise HTTPException(status_code=409, detail="Scraper is already running")

    thread = threading.Thread(
        target=_execute_hydrate_only,
        kwargs={
            "limit": limit,
            "max_content_len": max_content_len,
            "only_without_notes": bool(only_without_notes),
            "source": source,
            "activity_kind": kind,
        },
        daemon=True,
    )
    thread.start()
    return {"accepted": True, "job": "hydrate_only", "limit": limit, "max_content_len": max_content_len}


@app.get("/auth/status")
def auth_status() -> dict[str, Any]:
    if state.snapshot().get("running"):
        # Don't probe the profile while scraper is running.
        return auth.status()
    return auth.refresh_status()


@app.post("/auth/login/start")
def auth_login_start() -> dict[str, Any]:
    if state.snapshot().get("running"):
        raise HTTPException(status_code=409, detail="Scraper is running")
    try:
        return auth.start_login()
    except Exception as exc:
        raise HTTPException(status_code=500, detail=str(exc))


@app.get("/auth/login/status")
def auth_login_status(session_id: str = Query(..., min_length=6)) -> dict[str, Any]:
    try:
        return auth.login_status(session_id)
    except Exception as exc:
        raise HTTPException(status_code=400, detail=str(exc))


@app.post("/auth/login/stop")
def auth_login_stop(session_id: str = Query(..., min_length=6)) -> dict[str, Any]:
    try:
        return auth.stop_login(session_id)
    except Exception as exc:
        raise HTTPException(status_code=400, detail=str(exc))
