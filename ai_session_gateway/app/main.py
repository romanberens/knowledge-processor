from __future__ import annotations

import logging
import re
import threading
import uuid
from datetime import datetime, timezone
from typing import Any

from fastapi import FastAPI, HTTPException, Query

from .auth import AUTH_OK, AUTH_REQUIRED, LOGIN_RUNNING, AuthController
from .config import get_settings
from .contracts import (
    IntegrationEventIn,
    MessageCreateIn,
    SyncStartIn,
    ThreadCreateIn,
    ThreadExchangeIn,
    ThreadHistorySyncIn,
)
from .db import (
    AttachmentRecord,
    Database,
    IntegrationEventRecord,
    LoginSessionRecord,
    MessageRecord,
    ThreadRecord,
)
from .exchange import exchange_once, scan_threads_index_once, sync_history_once

logger = logging.getLogger(__name__)
AUTH_UNKNOWN = "AUTH_UNKNOWN"

settings = get_settings()
db = Database(settings)
profile_lock = threading.Lock()
auth = AuthController(settings=settings, profile_lock=profile_lock)

app = FastAPI(title="AI UI Session Gateway", version="0.1.0")

exchange_tasks: dict[str, dict[str, Any]] = {}
exchange_tasks_lock = threading.Lock()
sync_tasks: dict[str, dict[str, Any]] = {}
sync_tasks_lock = threading.Lock()


def _new_id(prefix: str) -> str:
    return f"{prefix}_{uuid.uuid4().hex[:12]}"


def _utc_now_iso() -> str:
    return datetime.now(timezone.utc).isoformat()


def _cleanup_exchange_tasks(max_items: int = 300) -> None:
    with exchange_tasks_lock:
        if len(exchange_tasks) <= max_items:
            return
        ordered = sorted(
            exchange_tasks.items(),
            key=lambda kv: str(kv[1].get("updated_at") or kv[1].get("started_at") or ""),
        )
        for task_id, _ in ordered[: max(0, len(exchange_tasks) - max_items)]:
            exchange_tasks.pop(task_id, None)


def _set_exchange_task(exchange_id: str, patch: dict[str, Any]) -> dict[str, Any]:
    with exchange_tasks_lock:
        current = dict(exchange_tasks.get(exchange_id) or {})
        current.update(patch)
        current["exchange_id"] = exchange_id
        current["updated_at"] = _utc_now_iso()
        exchange_tasks[exchange_id] = current
        out = dict(current)
    _cleanup_exchange_tasks()
    return out


def _get_exchange_task(exchange_id: str) -> dict[str, Any] | None:
    with exchange_tasks_lock:
        current = exchange_tasks.get(exchange_id)
        if current is None:
            return None
        return dict(current)


def _cleanup_sync_tasks(max_items: int = 120) -> None:
    with sync_tasks_lock:
        if len(sync_tasks) <= max_items:
            return
        ordered = sorted(
            sync_tasks.items(),
            key=lambda kv: str(kv[1].get("updated_at") or kv[1].get("started_at") or ""),
        )
        for task_id, _ in ordered[: max(0, len(sync_tasks) - max_items)]:
            sync_tasks.pop(task_id, None)


def _set_sync_task(job_id: str, patch: dict[str, Any]) -> dict[str, Any]:
    with sync_tasks_lock:
        current = dict(sync_tasks.get(job_id) or {})
        current.update(patch)
        current["job_id"] = job_id
        current["updated_at"] = _utc_now_iso()
        sync_tasks[job_id] = current
        out = dict(current)
    _cleanup_sync_tasks()
    return out


def _get_sync_task(job_id: str) -> dict[str, Any] | None:
    with sync_tasks_lock:
        current = sync_tasks.get(job_id)
        if current is None:
            return None
        return dict(current)


@app.on_event("startup")
def startup_init() -> None:
    db.init_schema()
    recovered = db.recover_open_sessions("Recovered open session after gateway restart")
    if recovered > 0:
        logger.warning("Recovered %d open auth session(s) in Postgres.", recovered)


@app.get("/health")
def health() -> dict[str, Any]:
    return {"ok": True, "db": db.ping()}


@app.get("/status")
def status() -> dict[str, Any]:
    return {
        "target": settings.target_name,
        "auth": auth.status(),
        "latest_session": db.latest_session(),
        "recent_events": db.recent_events(limit=20),
    }


@app.get("/auth/status")
def auth_status() -> dict[str, Any]:
    payload = auth.refresh_status()
    session_id = str(payload.get("login_session_id") or "")
    if session_id:
        db.update_session_state(
            session_id=session_id,
            state=str(payload.get("state") or AUTH_UNKNOWN),
            metadata={"last_check_at": payload.get("last_check_at")},
        )
    return payload


@app.post("/auth/login/start")
def auth_login_start() -> dict[str, Any]:
    try:
        payload = auth.start_login()
    except RuntimeError as exc:
        raise HTTPException(status_code=409, detail=str(exc))
    except Exception as exc:
        raise HTTPException(status_code=500, detail=str(exc))

    session_id = str(payload.get("login_session_id") or "")
    if session_id:
        db.upsert_login_session(
            LoginSessionRecord(
                session_id=session_id,
                state=str(payload.get("state") or LOGIN_RUNNING),
                target_name=settings.target_name,
                login_url=settings.login_url,
                novnc_url=str(payload.get("novnc_url") or settings.novnc_public_url),
            ),
            metadata={"started_at": payload.get("started_at")},
        )
        db.append_event(session_id, "login_started", payload)

    return payload


@app.get("/auth/login/status")
def auth_login_status(session_id: str = Query(..., min_length=6)) -> dict[str, Any]:
    try:
        payload = auth.login_status(session_id)
    except Exception as exc:
        raise HTTPException(status_code=400, detail=str(exc))

    db.update_session_state(
        session_id=session_id,
        state=str(payload.get("state") or AUTH_UNKNOWN),
        metadata={"last_check_at": payload.get("last_check_at")},
    )
    db.append_event(session_id, "login_polled", {"state": payload.get("state")})
    return payload


@app.post("/auth/login/stop")
def auth_login_stop(session_id: str = Query(..., min_length=6)) -> dict[str, Any]:
    try:
        payload = auth.stop_login(session_id)
    except Exception as exc:
        raise HTTPException(status_code=400, detail=str(exc))

    final_state = str(payload.get("state") or AUTH_REQUIRED)
    db.stop_session(session_id=session_id, final_state=final_state)
    db.append_event(session_id, "login_stopped", {"final_state": final_state})
    return payload


@app.get("/v1/schema")
def integration_schema() -> dict[str, Any]:
    return {
        "version": "1.0",
        "entities": [
            "thread",
            "message",
            "attachment",
            "event",
        ],
        "thread_fields": [
            "thread_id",
            "title",
            "project_id",
            "assistant_id",
            "status",
            "created_at",
            "updated_at",
            "metadata_json",
            "message_count",
        ],
        "message_fields": [
            "message_id",
            "thread_id",
            "parent_message_id",
            "role",
            "content_text",
            "mode",
            "source",
            "status",
            "tool_name",
            "created_at",
            "metadata_json",
            "attachments[]",
        ],
    }


@app.get("/v1/threads")
def threads_list(
    limit: int = Query(100, ge=1, le=500),
    project_id: str | None = Query(default=None),
    assistant_id: str | None = Query(default=None),
    q: str | None = Query(default=None),
) -> dict[str, Any]:
    items = db.list_threads(limit=limit, project_id=project_id, assistant_id=assistant_id, q=q)
    return {"items": items, "count": len(items)}


@app.post("/v1/threads")
def thread_upsert(payload: ThreadCreateIn) -> dict[str, Any]:
    thread_id = (payload.thread_id or "").strip() or _new_id("thr")
    record = ThreadRecord(
        thread_id=thread_id,
        title=payload.title,
        project_id=payload.project_id,
        assistant_id=payload.assistant_id,
        status=payload.status,
        metadata=payload.metadata,
    )
    try:
        out = db.upsert_thread(record)
        db.append_integration_event(
            IntegrationEventRecord(
                event_id=_new_id("evt"),
                event_type="thread_upserted",
                thread_id=thread_id,
                source="gateway_api",
                payload={
                    "title": payload.title,
                    "project_id": payload.project_id,
                    "assistant_id": payload.assistant_id,
                },
            )
        )
        return out
    except Exception as exc:
        raise HTTPException(status_code=500, detail=str(exc))


@app.get("/v1/threads/{thread_id}")
def thread_get(thread_id: str) -> dict[str, Any]:
    out = db.get_thread(thread_id)
    if out is None:
        raise HTTPException(status_code=404, detail=f"Thread not found: {thread_id}")
    return out


@app.get("/v1/threads/{thread_id}/messages")
def thread_messages_list(thread_id: str, limit: int = Query(200, ge=1, le=1000)) -> dict[str, Any]:
    if db.get_thread(thread_id) is None:
        raise HTTPException(status_code=404, detail=f"Thread not found: {thread_id}")
    items = db.list_messages(thread_id=thread_id, limit=limit)
    return {"thread_id": thread_id, "items": items, "count": len(items)}


@app.post("/v1/threads/{thread_id}/messages")
def thread_message_create(thread_id: str, payload: MessageCreateIn) -> dict[str, Any]:
    if db.get_thread(thread_id) is None:
        raise HTTPException(status_code=404, detail=f"Thread not found: {thread_id}")

    message_id = (payload.message_id or "").strip() or _new_id("msg")
    message = MessageRecord(
        message_id=message_id,
        thread_id=thread_id,
        parent_message_id=payload.parent_message_id,
        role=payload.role,
        content_text=payload.content_text,
        mode=payload.mode,
        source=payload.source,
        status=payload.status,
        tool_name=payload.tool_name,
        metadata=payload.metadata,
    )
    attachments = [
        AttachmentRecord(
            attachment_id=(att.attachment_id or "").strip() or _new_id("att"),
            message_id=message_id,
            file_name=att.file_name,
            mime_type=att.mime_type,
            size_bytes=att.size_bytes,
            storage_ref=att.storage_ref,
            metadata=att.metadata,
        )
        for att in payload.attachments
    ]

    try:
        out = db.create_message(message, attachments)
        db.append_integration_event(
            IntegrationEventRecord(
                event_id=_new_id("evt"),
                event_type="message_created",
                thread_id=thread_id,
                message_id=message_id,
                source="gateway_api",
                payload={
                    "role": payload.role,
                    "mode": payload.mode,
                    "attachments_count": len(attachments),
                },
            )
        )
        return out
    except ValueError as exc:
        raise HTTPException(status_code=404, detail=str(exc))
    except Exception as exc:
        raise HTTPException(status_code=500, detail=str(exc))


def _normalize_comparison_options(raw: Any) -> list[dict[str, Any]]:
    out: list[dict[str, Any]] = []
    if not isinstance(raw, list):
        return out
    for item in raw:
        if not isinstance(item, dict):
            continue
        text = str(item.get("text") or "").strip()
        if text == "":
            continue
        label = str(item.get("label") or "").strip() or "Odpowiedź"
        idx_raw = item.get("index")
        idx = int(idx_raw) if isinstance(idx_raw, int) else None
        out.append({"index": idx, "label": label, "text": text})
    return out


REMOTE_THREAD_RE = re.compile(r"/c/([a-zA-Z0-9-]+)")


def _remote_thread_id_from_url(url: str) -> str:
    raw = str(url or "").strip()
    if raw == "":
        return ""
    match = REMOTE_THREAD_RE.search(raw)
    if not match:
        return ""
    return str(match.group(1) or "").strip()


def _thread_chatgpt_meta(thread_row: dict[str, Any] | None) -> dict[str, Any]:
    if not isinstance(thread_row, dict):
        return {}
    metadata = thread_row.get("metadata_json")
    if not isinstance(metadata, dict):
        return {}
    chatgpt_meta = metadata.get("chatgpt")
    if not isinstance(chatgpt_meta, dict):
        return {}
    return dict(chatgpt_meta)


def _thread_conversation_url(thread_row: dict[str, Any] | None) -> str:
    chatgpt_meta = _thread_chatgpt_meta(thread_row)
    return str(chatgpt_meta.get("conversation_url") or "").strip()


def _thread_remote_id(thread_row: dict[str, Any] | None) -> str:
    chatgpt_meta = _thread_chatgpt_meta(thread_row)
    remote_id = str(chatgpt_meta.get("remote_thread_id") or "").strip()
    if remote_id != "":
        return remote_id
    return _remote_thread_id_from_url(str(chatgpt_meta.get("conversation_url") or ""))


def _set_thread_chatgpt_metadata(thread_id: str, patch: dict[str, Any]) -> None:
    row = db.get_thread(thread_id)
    base = _thread_chatgpt_meta(row)
    merged = dict(base)
    for key, val in patch.items():
        merged[key] = val
    db.update_thread_metadata(
        thread_id,
        metadata={
            "chatgpt": merged,
        },
    )


def _store_thread_conversation_url(thread_id: str, conversation_url: str, opened_url: str = "") -> None:
    conv = str(conversation_url or "").strip()
    if conv == "":
        return
    opened = str(opened_url or "").strip()
    _set_thread_chatgpt_metadata(
        thread_id,
        {
            "conversation_url": conv,
            "opened_url": opened if opened != "" else conv,
            "remote_thread_id": _remote_thread_id_from_url(conv),
            "remote_last_seen_at": _utc_now_iso(),
            "remote_deleted_at": None,
            "updated_at": _utc_now_iso(),
        },
    )


def _calc_history_append_start(
    local_messages: list[dict[str, Any]],
    remote_messages: list[dict[str, Any]],
) -> int:
    local_pairs: list[tuple[str, str]] = []
    for item in local_messages:
        role = str(item.get("role") or "").strip().lower()
        text = str(item.get("content_text") or "").strip()
        if role not in ("user", "assistant") or text == "":
            continue
        local_pairs.append((role, text))

    remote_pairs: list[tuple[str, str]] = []
    for item in remote_messages:
        role = str(item.get("role") or "").strip().lower()
        text = str(item.get("text") or "").strip()
        if role not in ("user", "assistant") or text == "":
            continue
        remote_pairs.append((role, text))

    if not local_pairs:
        return 0
    if not remote_pairs:
        return 0

    max_common = min(len(local_pairs), len(remote_pairs))
    common_prefix = 0
    for i in range(max_common):
        if local_pairs[i] != remote_pairs[i]:
            break
        common_prefix = i + 1

    if common_prefix > 0:
        return common_prefix

    if len(local_pairs) <= len(remote_pairs):
        for start in range(0, len(remote_pairs) - len(local_pairs) + 1):
            if remote_pairs[start : start + len(local_pairs)] == local_pairs:
                return start + len(local_pairs)

    return min(len(local_pairs), len(remote_pairs))


def _remote_message_key(remote_thread_id: str, index: int, role: str) -> str:
    rid = str(remote_thread_id or "").strip()
    if rid == "":
        rid = "unknown"
    return f"{rid}:{int(index)}:{str(role or 'assistant').strip().lower()}"


def _normalize_remote_history_items(raw_items: Any) -> list[dict[str, Any]]:
    out: list[dict[str, Any]] = []
    if not isinstance(raw_items, list):
        return out
    for idx, item in enumerate(raw_items):
        if not isinstance(item, dict):
            continue
        role = str(item.get("role") or "").strip().lower()
        text = str(item.get("text") or "").strip()
        if role not in ("user", "assistant") or text == "":
            continue
        remote_index = item.get("index")
        out_idx = int(remote_index) if isinstance(remote_index, int) else idx
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
        out.append(
            {
                "index": out_idx,
                "role": role,
                "text": text,
                "attachments": attachments,
            }
        )
    return out


def _build_attachment_records(
    message_id: str,
    remote_attachments: list[dict[str, Any]],
    *,
    source: str,
    remote_key: str,
) -> list[AttachmentRecord]:
    out: list[AttachmentRecord] = []
    for idx, att in enumerate(remote_attachments):
        url = str(att.get("url") or "").strip()
        if url == "":
            continue
        kind = str(att.get("kind") or "file").strip() or "file"
        file_name = str(att.get("file_name") or "").strip() or f"attachment-{idx + 1}"
        mime_type = str(att.get("mime_type") or "").strip() or None
        out.append(
            AttachmentRecord(
                attachment_id=_new_id("att"),
                message_id=message_id,
                file_name=file_name,
                mime_type=mime_type,
                size_bytes=None,
                storage_ref=url,
                metadata={
                    "preview_kind": kind,
                    "download_status": "remote_url",
                    "source": source,
                    "remote_key": remote_key,
                    "synced_at": _utc_now_iso(),
                },
            )
        )
    return out


def _sync_single_thread_history(
    *,
    thread_row: dict[str, Any],
    mode: str,
    source: str,
    mirror_delete_local: bool,
    conversation_url_override: str | None = None,
) -> dict[str, Any]:
    thread_id = str(thread_row.get("thread_id") or "").strip()
    if thread_id == "":
        raise RuntimeError("THREAD_ID_REQUIRED")

    configured_url = str(conversation_url_override or "").strip()
    existing_url = _thread_conversation_url(thread_row)
    conversation_url = configured_url or existing_url
    if conversation_url == "":
        raise RuntimeError("CONVERSATION_URL_REQUIRED")

    sync_result = sync_history_once(settings=settings, conversation_url=conversation_url)
    remote_messages = _normalize_remote_history_items(sync_result.get("messages"))
    opened_url = str(sync_result.get("opened_url") or conversation_url).strip()
    resolved_conversation_url = str(sync_result.get("conversation_url") or conversation_url).strip()
    remote_thread_id = _remote_thread_id_from_url(resolved_conversation_url) or _thread_remote_id(thread_row)

    local_messages_before = db.list_messages(thread_id, limit=5000)
    local_by_remote_key: dict[str, dict[str, Any]] = {}
    local_ordered_dialogue: list[dict[str, Any]] = []
    for local in local_messages_before:
        role_local = str(local.get("role") or "").strip().lower()
        text_local = str(local.get("content_text") or "").strip()
        if role_local in ("user", "assistant") and text_local != "":
            local_ordered_dialogue.append(local)
        meta = local.get("metadata_json")
        if not isinstance(meta, dict):
            continue
        sync_meta = meta.get("sync")
        if not isinstance(sync_meta, dict):
            continue
        key = str(sync_meta.get("remote_key") or "").strip()
        if key == "":
            continue
        local_by_remote_key[key] = local

    inserted_count = 0
    updated_count = 0
    deleted_count = 0
    attachments_inserted = 0
    remote_keys_now: list[str] = []
    parent_message_id = ""
    if local_messages_before:
        parent_message_id = str(local_messages_before[-1].get("message_id") or "")

    for fallback_idx, item in enumerate(remote_messages):
        idx = int(item.get("index") if isinstance(item.get("index"), int) else fallback_idx)
        role = str(item.get("role") or "assistant")
        text = str(item.get("text") or "")
        remote_key = _remote_message_key(remote_thread_id, idx, role)
        remote_keys_now.append(remote_key)
        sync_meta = {
            "state": "imported",
            "remote_key": remote_key,
            "remote_index": idx,
            "remote_thread_id": remote_thread_id,
            "conversation_url": resolved_conversation_url,
            "opened_url": opened_url,
            "synced_at": _utc_now_iso(),
        }

        existing_local = local_by_remote_key.get(remote_key)
        if existing_local is None and idx < len(local_ordered_dialogue):
            candidate = local_ordered_dialogue[idx]
            if (
                str(candidate.get("role") or "").strip().lower() == role
                and str(candidate.get("content_text") or "").strip() == text
            ):
                existing_local = candidate
        if existing_local is not None:
            existing_message_id = str(existing_local.get("message_id") or "")
            db.update_message(
                existing_message_id,
                content_text=text,
                status="received",
                metadata={"sync": sync_meta},
            )
            remote_atts = item.get("attachments") if isinstance(item.get("attachments"), list) else []
            att_records = _build_attachment_records(
                existing_message_id,
                remote_atts,
                source=source,
                remote_key=remote_key,
            )
            if att_records:
                attachments_inserted += db.append_attachments(existing_message_id, att_records)
            updated_count += 1
            parent_message_id = existing_message_id
            continue

        message_id = _new_id("msg")
        remote_atts = item.get("attachments") if isinstance(item.get("attachments"), list) else []
        att_records = _build_attachment_records(
            message_id,
            remote_atts,
            source=source,
            remote_key=remote_key,
        )
        created = db.create_message(
            MessageRecord(
                message_id=message_id,
                thread_id=thread_id,
                parent_message_id=parent_message_id or None,
                role=role,
                content_text=text,
                mode=mode,
                source=source,
                status="received",
                metadata={"sync": sync_meta},
            ),
            att_records,
        )
        attachments_inserted += len(created.get("attachments") or [])
        inserted_count += 1
        parent_message_id = message_id

    if mirror_delete_local:
        to_delete_ids: list[str] = []
        keep_keys = set(remote_keys_now)
        for local in local_messages_before:
            meta = local.get("metadata_json")
            if not isinstance(meta, dict):
                continue
            sync_meta = meta.get("sync")
            if not isinstance(sync_meta, dict):
                continue
            key = str(sync_meta.get("remote_key") or "").strip()
            if key == "":
                continue
            if key not in keep_keys:
                mid = str(local.get("message_id") or "").strip()
                if mid != "":
                    to_delete_ids.append(mid)
        if to_delete_ids:
            deleted_count = db.delete_messages_by_ids(to_delete_ids)

    _set_thread_chatgpt_metadata(
        thread_id,
        {
            "conversation_url": resolved_conversation_url,
            "opened_url": opened_url,
            "remote_thread_id": remote_thread_id,
            "remote_last_seen_at": _utc_now_iso(),
            "remote_deleted_at": None,
            "updated_at": _utc_now_iso(),
        },
    )

    return {
        "thread_id": thread_id,
        "conversation_url": resolved_conversation_url,
        "opened_url": opened_url,
        "remote_thread_id": remote_thread_id,
        "remote_count": len(remote_messages),
        "local_before_count": len(local_messages_before),
        "inserted_count": inserted_count,
        "updated_count": updated_count,
        "deleted_count": deleted_count,
        "attachments_inserted": attachments_inserted,
    }


def _thread_id_from_remote(remote_thread_id: str) -> str:
    normalized = re.sub(r"[^a-zA-Z0-9]+", "_", str(remote_thread_id or "").strip()).strip("_").lower()
    if normalized == "":
        return _new_id("thr")
    return ("thr_c_" + normalized)[:64]


def _run_exchange_task(
    *,
    exchange_id: str,
    thread_id: str,
    prompt: str,
    mode: str,
    source: str,
    comparison_preference: str,
    user_message_id: str,
    assistant_message_id: str,
    assistant_metadata_seed: dict[str, Any],
) -> None:
    thread_row = db.get_thread(thread_id) or {}
    existing_conversation_url = _thread_conversation_url(thread_row)
    _set_exchange_task(
        exchange_id,
        {
            "status": "running",
            "thread_id": thread_id,
            "mode": mode,
            "source": source,
            "user_message_id": user_message_id,
            "assistant_message_id": assistant_message_id,
            "assistant_text": "",
            "error": None,
            "conversation_url": existing_conversation_url,
            "started_at": _utc_now_iso(),
        },
    )

    if not profile_lock.acquire(timeout=0.2):
        detail = "PROFILE_BUSY"
        db.update_message(
            assistant_message_id,
            status="failed",
            metadata={"exchange": {"error": detail, "state": "failed"}},
        )
        db.append_integration_event(
            IntegrationEventRecord(
                event_id=_new_id("evt"),
                event_type="exchange_failed",
                thread_id=thread_id,
                message_id=user_message_id,
                source="exchange_gateway",
                payload={"detail": detail, "mode": mode},
            )
        )
        _set_exchange_task(
            exchange_id,
            {"status": "failed", "error": detail, "completed_at": _utc_now_iso()},
        )
        return

    try:
        def _on_partial(text: str, meta: dict[str, Any]) -> None:
            exchange_meta = {
                "state": "streaming",
                "assistant_count": int(meta.get("assistant_count") or 0),
                "user_count": int(meta.get("user_count") or 0),
                "conversation_url": str(meta.get("conversation_url") or ""),
                "opened_url": str(meta.get("opened_url") or existing_conversation_url),
                "comparison_gate_handled": bool(meta.get("comparison_gate_handled")),
                "comparison_selected_index": meta.get("comparison_selected_index"),
                "comparison_options": _normalize_comparison_options(meta.get("comparison_options")),
            }
            db.update_message(
                assistant_message_id,
                content_text=text,
                status="streaming",
                metadata={"exchange": exchange_meta},
            )
            _set_exchange_task(
                exchange_id,
                {
                    "status": "running",
                    "assistant_text": text,
                    "exchange": exchange_meta,
                },
            )

        exchange_result = exchange_once(
            settings=settings,
            prompt=prompt,
            mode=mode,
            comparison_preference=comparison_preference,
            conversation_url=existing_conversation_url,
            on_partial=_on_partial,
        )

        assistant_text = str(exchange_result.get("assistant_text") or "").strip()
        if assistant_text == "":
            raise RuntimeError("EMPTY_ASSISTANT_RESPONSE")

        resolved_conversation_url = str(exchange_result.get("conversation_url") or "").strip()
        opened_url = str(exchange_result.get("opened_url") or existing_conversation_url).strip()
        _store_thread_conversation_url(
            thread_id=thread_id,
            conversation_url=resolved_conversation_url,
            opened_url=opened_url,
        )

        comparison_options = _normalize_comparison_options(exchange_result.get("comparison_options"))

        assistant_meta = dict(assistant_metadata_seed)
        assistant_meta["exchange"] = {
            "state": "completed",
            "elapsed_ms": int(exchange_result.get("elapsed_ms") or 0),
            "conversation_url": resolved_conversation_url,
            "opened_url": opened_url,
            "assistant_count": int(exchange_result.get("assistant_count") or 0),
            "user_count": int(exchange_result.get("user_count") or 0),
            "comparison_gate_handled": bool(exchange_result.get("comparison_gate_handled")),
            "comparison_selected_index": exchange_result.get("comparison_selected_index"),
            "comparison_options": comparison_options,
        }

        updated = db.update_message(
            assistant_message_id,
            content_text=assistant_text,
            status="received",
            metadata=assistant_meta,
        )
        db.append_integration_event(
            IntegrationEventRecord(
                event_id=_new_id("evt"),
                event_type="exchange_completed",
                thread_id=thread_id,
                message_id=assistant_message_id,
                source="exchange_gateway",
                payload={
                    "mode": mode,
                    "elapsed_ms": int(exchange_result.get("elapsed_ms") or 0),
                    "comparison_gate_handled": bool(exchange_result.get("comparison_gate_handled")),
                    "comparison_selected_index": exchange_result.get("comparison_selected_index"),
                    "comparison_options_count": len(comparison_options),
                    "conversation_url": resolved_conversation_url,
                    "streaming": True,
                },
            )
        )
        _set_exchange_task(
            exchange_id,
            {
                "status": "completed",
                "assistant_text": assistant_text,
                "assistant_message": updated,
                "exchange": assistant_meta.get("exchange"),
                "conversation_url": resolved_conversation_url,
                "completed_at": _utc_now_iso(),
            },
        )
    except RuntimeError as exc:
        detail = str(exc)
        db.update_message(
            assistant_message_id,
            status="failed",
            metadata={"exchange": {"state": "failed", "error": detail}},
        )
        db.append_integration_event(
            IntegrationEventRecord(
                event_id=_new_id("evt"),
                event_type="exchange_failed",
                thread_id=thread_id,
                message_id=user_message_id,
                source="exchange_gateway",
                payload={"detail": detail, "mode": mode, "streaming": True},
            )
        )
        _set_exchange_task(
            exchange_id,
            {"status": "failed", "error": detail, "completed_at": _utc_now_iso()},
        )
    except Exception as exc:
        detail = str(exc)
        db.update_message(
            assistant_message_id,
            status="failed",
            metadata={"exchange": {"state": "failed", "error": detail}},
        )
        db.append_integration_event(
            IntegrationEventRecord(
                event_id=_new_id("evt"),
                event_type="exchange_failed",
                thread_id=thread_id,
                message_id=user_message_id,
                source="exchange_gateway",
                payload={"detail": detail, "mode": mode, "streaming": True},
            )
        )
        _set_exchange_task(
            exchange_id,
            {"status": "failed", "error": detail, "completed_at": _utc_now_iso()},
        )
    finally:
        profile_lock.release()


@app.post("/v1/threads/{thread_id}/exchange/start")
def thread_exchange_start(thread_id: str, payload: ThreadExchangeIn) -> dict[str, Any]:
    if db.get_thread(thread_id) is None:
        raise HTTPException(status_code=404, detail=f"Thread not found: {thread_id}")

    prompt = payload.prompt.strip()
    if prompt == "":
        raise HTTPException(status_code=400, detail="Prompt is empty")

    auth_snapshot = auth.status()
    if str(auth_snapshot.get("state") or "") == LOGIN_RUNNING:
        raise HTTPException(status_code=409, detail="LOGIN_SESSION_OPEN")

    mode = payload.mode.strip() or "default"
    source = payload.source.strip() or "web_panel"
    user_message_id = (payload.user_message_id or "").strip() or _new_id("msg")
    assistant_message_id = (payload.assistant_message_id or "").strip() or _new_id("msg")
    exchange_id = _new_id("xchg")

    try:
        user_message = db.create_message(
            MessageRecord(
                message_id=user_message_id,
                thread_id=thread_id,
                parent_message_id=payload.parent_message_id,
                role="user",
                content_text=prompt,
                mode=mode,
                source=source,
                status="submitted",
                metadata=payload.metadata,
            ),
            [],
        )
        assistant_message = db.create_message(
            MessageRecord(
                message_id=assistant_message_id,
                thread_id=thread_id,
                parent_message_id=user_message_id,
                role="assistant",
                content_text="",
                mode=mode,
                source="chatgpt_ui",
                status="queued",
                metadata={
                    "exchange": {
                        "state": "queued",
                        "streaming": True,
                        "comparison_selected_index": 0 if payload.comparison_preference == "first" else 1,
                    }
                },
            ),
            [],
        )
        db.append_integration_event(
            IntegrationEventRecord(
                event_id=_new_id("evt"),
                event_type="exchange_user_message_created",
                thread_id=thread_id,
                message_id=user_message_id,
                source="exchange_gateway",
                payload={"mode": mode, "chars": len(prompt), "streaming": True},
            )
        )
        db.append_integration_event(
            IntegrationEventRecord(
                event_id=_new_id("evt"),
                event_type="exchange_started",
                thread_id=thread_id,
                message_id=assistant_message_id,
                source="exchange_gateway",
                payload={"exchange_id": exchange_id, "mode": mode},
            )
        )

        _set_exchange_task(
            exchange_id,
            {
                "status": "queued",
                "thread_id": thread_id,
                "mode": mode,
                "source": source,
                "prompt_chars": len(prompt),
                "user_message_id": user_message_id,
                "assistant_message_id": assistant_message_id,
                "assistant_text": "",
                "error": None,
                "started_at": _utc_now_iso(),
            },
        )

        worker = threading.Thread(
            target=_run_exchange_task,
            kwargs={
                "exchange_id": exchange_id,
                "thread_id": thread_id,
                "prompt": prompt,
                "mode": mode,
                "source": source,
                "comparison_preference": payload.comparison_preference,
                "user_message_id": user_message_id,
                "assistant_message_id": assistant_message_id,
                "assistant_metadata_seed": dict(payload.assistant_metadata),
            },
            daemon=True,
            name=f"exchange-{exchange_id}",
        )
        worker.start()

        return {
            "ok": True,
            "exchange_id": exchange_id,
            "thread_id": thread_id,
            "status": "queued",
            "user_message": user_message,
            "assistant_message": assistant_message,
        }
    except ValueError as exc:
        raise HTTPException(status_code=404, detail=str(exc))
    except Exception as exc:
        raise HTTPException(status_code=500, detail=str(exc))


@app.get("/v1/exchanges/{exchange_id}")
def exchange_status(exchange_id: str) -> dict[str, Any]:
    task = _get_exchange_task(exchange_id)
    if task is None:
        raise HTTPException(status_code=404, detail=f"Exchange not found: {exchange_id}")

    assistant_message = None
    assistant_message_id = str(task.get("assistant_message_id") or "")
    if assistant_message_id != "":
        assistant_message = db.get_message(assistant_message_id)
        if isinstance(assistant_message, dict):
            task["assistant_message"] = assistant_message
            task["assistant_text"] = str(assistant_message.get("content_text") or "")

    return task


def _run_sync_job(job_id: str, job_type: str, payload: SyncStartIn) -> None:
    project_id = (payload.project_id or "").strip() or None
    assistant_id = (payload.assistant_id or "").strip() or None
    mode = payload.mode.strip() or "default"
    source = payload.source.strip() or "chatgpt_ui_sync"
    mirror_delete_local = bool(payload.mirror_delete_local)
    max_threads = max(1, min(int(payload.max_threads), 20000))

    _set_sync_task(
        job_id,
        {
            "status": "running",
            "job_type": job_type,
            "project_id": project_id,
            "assistant_id": assistant_id,
            "mode": mode,
            "source": source,
            "mirror_delete_local": mirror_delete_local,
            "progress_done": 0,
            "progress_total": 0,
            "phase": "init",
            "error": None,
            "started_at": _utc_now_iso(),
        },
    )

    auth_snapshot = auth.status()
    if str(auth_snapshot.get("state") or "") == LOGIN_RUNNING:
        _set_sync_task(
            job_id,
            {
                "status": "failed",
                "error": "LOGIN_SESSION_OPEN",
                "completed_at": _utc_now_iso(),
            },
        )
        return

    if not profile_lock.acquire(timeout=0.2):
        _set_sync_task(
            job_id,
            {
                "status": "failed",
                "error": "PROFILE_BUSY",
                "completed_at": _utc_now_iso(),
            },
        )
        return

    try:
        scanned_count = 0
        deleted_threads = 0
        inserted_messages = 0
        updated_messages = 0
        deleted_messages = 0
        attachments_inserted = 0
        scan_end_reason = ""
        scan_stable_rounds = 0
        scan_stable_target = 0
        scan_scroll_ops = 0
        scan_scroll_moved_total = 0
        failed_threads: list[dict[str, Any]] = []
        threads_for_messages: list[dict[str, Any]] = []
        remote_ids_in_scan: set[str] = set()

        if job_type in ("threads_scan", "full_sync"):
            _set_sync_task(job_id, {"phase": "scan_threads"})
            def _scan_progress(progress: dict[str, Any]) -> None:
                round_no = int(progress.get("round") or 0)
                max_rounds_local = int(payload.max_rounds or 0)
                _set_sync_task(
                    job_id,
                    {
                        "phase": "scan_threads",
                        "progress_done": round_no,
                        "progress_total": max_rounds_local,
                        "scan_runtime": progress,
                    },
                )

            scan = scan_threads_index_once(
                settings=settings,
                max_rounds=int(payload.max_rounds),
                stable_rounds=20,
                on_progress=_scan_progress,
            )
            scan_end_reason = str(scan.get("end_reason") or "").strip()
            scan_stable_target = int(scan.get("stable_target") or 0)
            scan_stable_rounds = int(scan.get("stable_rounds") or 0)
            scan_scroll_ops = int(scan.get("scroll_ops") or 0)
            scan_scroll_moved_total = int(scan.get("scroll_moved_total") or 0)
            scanned_items_raw = scan.get("items")
            scanned_items = scanned_items_raw if isinstance(scanned_items_raw, list) else []
            scanned_items = scanned_items[:max_threads]
            total_scan = len(scanned_items)
            _set_sync_task(job_id, {"progress_total": total_scan, "progress_done": 0})

            for idx, item in enumerate(scanned_items, start=1):
                if not isinstance(item, dict):
                    continue
                remote_thread_id = str(item.get("remote_thread_id") or "").strip()
                conversation_url = str(item.get("conversation_url") or "").strip()
                title = str(item.get("title") or "").strip()
                if remote_thread_id == "" or conversation_url == "":
                    continue
                if title == "":
                    title = remote_thread_id
                remote_ids_in_scan.add(remote_thread_id)

                existing = db.find_thread_by_remote_thread_id(remote_thread_id)
                if existing is None:
                    existing = db.find_thread_by_conversation_url(conversation_url)
                thread_id = str((existing or {}).get("thread_id") or "").strip() or _thread_id_from_remote(remote_thread_id)
                if existing is not None and title == "":
                    title = str(existing.get("title") or "").strip()
                if title == "":
                    title = "Rozmowa " + remote_thread_id
                row = db.upsert_thread(
                    ThreadRecord(
                        thread_id=thread_id,
                        title=title,
                        project_id=project_id or str((existing or {}).get("project_id") or "") or None,
                        assistant_id=assistant_id or str((existing or {}).get("assistant_id") or "") or None,
                        status="active",
                        metadata={},
                    )
                )
                _set_thread_chatgpt_metadata(
                    thread_id,
                    {
                        "remote_thread_id": remote_thread_id,
                        "conversation_url": conversation_url,
                        "opened_url": conversation_url,
                        "remote_last_seen_at": _utc_now_iso(),
                        "remote_deleted_at": None,
                        "scan_revision": job_id,
                        "updated_at": _utc_now_iso(),
                    },
                )
                scanned_count += 1
                latest_row = db.get_thread(thread_id)
                if isinstance(latest_row, dict):
                    threads_for_messages.append(latest_row)
                else:
                    threads_for_messages.append(row)
                _set_sync_task(
                    job_id,
                    {
                        "progress_done": idx,
                        "progress_total": total_scan,
                    },
                )

            scope_for_delete = db.list_threads_for_sync(
                limit=max_threads,
                project_id=project_id,
                assistant_id=assistant_id,
            )
            can_mirror_delete_threads = (
                mirror_delete_local
                and (project_id is not None or assistant_id is not None)
                and scan_end_reason == "stable_no_growth"
                and scan_stable_rounds >= scan_stable_target
            )
            if can_mirror_delete_threads:
                for local_thread in scope_for_delete:
                    local_tid = str(local_thread.get("thread_id") or "").strip()
                    if local_tid == "":
                        continue
                    local_remote_id = _thread_remote_id(local_thread)
                    if local_remote_id == "":
                        continue
                    if local_remote_id in remote_ids_in_scan:
                        continue
                    deleted_threads += db.delete_thread(local_tid)
            else:
                # When deletion mirror is disabled, only mark remote-missing threads.
                for local_thread in scope_for_delete:
                    local_tid = str(local_thread.get("thread_id") or "").strip()
                    if local_tid == "":
                        continue
                    local_remote_id = _thread_remote_id(local_thread)
                    if local_remote_id == "":
                        continue
                    if local_remote_id in remote_ids_in_scan:
                        _set_thread_chatgpt_metadata(local_tid, {"remote_deleted_at": None})
                        continue
                    _set_thread_chatgpt_metadata(local_tid, {"remote_deleted_at": _utc_now_iso()})

        if job_type in ("messages_pull", "full_sync"):
            _set_sync_task(job_id, {"phase": "pull_messages"})
            if not threads_for_messages:
                threads_for_messages = db.list_threads_for_sync(
                    limit=max_threads,
                    project_id=project_id,
                    assistant_id=assistant_id,
                )
            filtered_threads: list[dict[str, Any]] = []
            for thread_row in threads_for_messages:
                conv = _thread_conversation_url(thread_row)
                if conv == "":
                    continue
                filtered_threads.append(thread_row)

            total_threads = len(filtered_threads)
            _set_sync_task(job_id, {"progress_done": 0, "progress_total": total_threads})
            for idx, thread_row in enumerate(filtered_threads, start=1):
                local_tid = str(thread_row.get("thread_id") or "").strip()
                if local_tid == "":
                    continue
                try:
                    one = _sync_single_thread_history(
                        thread_row=thread_row,
                        mode=mode,
                        source=source,
                        mirror_delete_local=mirror_delete_local,
                        conversation_url_override=None,
                    )
                    inserted_messages += int(one.get("inserted_count") or 0)
                    updated_messages += int(one.get("updated_count") or 0)
                    deleted_messages += int(one.get("deleted_count") or 0)
                    attachments_inserted += int(one.get("attachments_inserted") or 0)
                except RuntimeError as exc:
                    failed_threads.append({"thread_id": local_tid, "error": str(exc)})
                _set_sync_task(
                    job_id,
                    {
                        "progress_done": idx,
                        "progress_total": total_threads,
                    },
                )

        status = "completed" if not failed_threads else "completed_with_errors"
        result_payload = {
            "scanned_threads": scanned_count,
            "deleted_threads": deleted_threads,
            "scan_end_reason": scan_end_reason if job_type in ("threads_scan", "full_sync") else "",
            "scan_stable_rounds": scan_stable_rounds if job_type in ("threads_scan", "full_sync") else 0,
            "scan_stable_target": scan_stable_target if job_type in ("threads_scan", "full_sync") else 0,
            "scan_scroll_ops": scan_scroll_ops if job_type in ("threads_scan", "full_sync") else 0,
            "scan_scroll_moved_total": scan_scroll_moved_total if job_type in ("threads_scan", "full_sync") else 0,
            "inserted_messages": inserted_messages,
            "updated_messages": updated_messages,
            "deleted_messages": deleted_messages,
            "attachments_inserted": attachments_inserted,
            "failed_threads": failed_threads,
        }
        _set_sync_task(
            job_id,
            {
                "status": status,
                "phase": "done",
                "result": result_payload,
                "completed_at": _utc_now_iso(),
            },
        )
        db.append_integration_event(
            IntegrationEventRecord(
                event_id=_new_id("evt"),
                event_type="sync_job_completed",
                source=source,
                payload={
                    "job_id": job_id,
                    "job_type": job_type,
                    "project_id": project_id,
                    "assistant_id": assistant_id,
                    "status": status,
                    **result_payload,
                },
            )
        )
    except RuntimeError as exc:
        _set_sync_task(
            job_id,
            {
                "status": "failed",
                "error": str(exc),
                "completed_at": _utc_now_iso(),
            },
        )
    except Exception as exc:
        _set_sync_task(
            job_id,
            {
                "status": "failed",
                "error": str(exc),
                "completed_at": _utc_now_iso(),
            },
        )
    finally:
        profile_lock.release()


def _start_sync_job(job_type: str, payload: SyncStartIn) -> dict[str, Any]:
    job_id = _new_id("sync")
    _set_sync_task(
        job_id,
        {
            "status": "queued",
            "job_type": job_type,
            "project_id": payload.project_id,
            "assistant_id": payload.assistant_id,
            "progress_done": 0,
            "progress_total": 0,
            "phase": "queued",
            "started_at": _utc_now_iso(),
        },
    )
    worker = threading.Thread(
        target=_run_sync_job,
        kwargs={"job_id": job_id, "job_type": job_type, "payload": payload},
        daemon=True,
        name=f"sync-{job_type}-{job_id}",
    )
    worker.start()
    return {
        "ok": True,
        "job_id": job_id,
        "status": "queued",
        "job_type": job_type,
    }


@app.post("/v1/sync/threads_scan/start")
def sync_threads_scan_start(payload: SyncStartIn) -> dict[str, Any]:
    return _start_sync_job("threads_scan", payload)


@app.post("/v1/sync/messages_pull/start")
def sync_messages_pull_start(payload: SyncStartIn) -> dict[str, Any]:
    return _start_sync_job("messages_pull", payload)


@app.post("/v1/sync/full/start")
def sync_full_start(payload: SyncStartIn) -> dict[str, Any]:
    return _start_sync_job("full_sync", payload)


@app.get("/v1/sync/jobs/{job_id}")
def sync_job_status(job_id: str) -> dict[str, Any]:
    task = _get_sync_task(job_id)
    if task is None:
        raise HTTPException(status_code=404, detail=f"Sync job not found: {job_id}")
    return task


@app.post("/v1/threads/{thread_id}/sync_history")
def thread_sync_history(thread_id: str, payload: ThreadHistorySyncIn) -> dict[str, Any]:
    thread_row = db.get_thread(thread_id)
    if thread_row is None:
        raise HTTPException(status_code=404, detail=f"Thread not found: {thread_id}")

    auth_snapshot = auth.status()
    if str(auth_snapshot.get("state") or "") == LOGIN_RUNNING:
        raise HTTPException(status_code=409, detail="LOGIN_SESSION_OPEN")

    if not profile_lock.acquire(timeout=0.2):
        raise HTTPException(status_code=409, detail="PROFILE_BUSY")

    try:
        source = payload.source.strip() or "chatgpt_ui_sync"
        mode = payload.mode.strip() or "default"
        result = _sync_single_thread_history(
            thread_row=thread_row,
            mode=mode,
            source=source,
            mirror_delete_local=True,
            conversation_url_override=payload.conversation_url,
        )
        db.append_integration_event(
            IntegrationEventRecord(
                event_id=_new_id("evt"),
                event_type="history_sync_completed",
                thread_id=thread_id,
                source=source,
                payload={
                    "remote_count": int(result.get("remote_count") or 0),
                    "local_before_count": int(result.get("local_before_count") or 0),
                    "inserted_count": int(result.get("inserted_count") or 0),
                    "updated_count": int(result.get("updated_count") or 0),
                    "deleted_count": int(result.get("deleted_count") or 0),
                    "attachments_inserted": int(result.get("attachments_inserted") or 0),
                    "conversation_url": str(result.get("conversation_url") or ""),
                },
            )
        )
        return {"ok": True, **result}
    except RuntimeError as exc:
        detail = str(exc)
        if detail == "AUTH_REQUIRED":
            raise HTTPException(status_code=412, detail=detail)
        if detail == "CHALLENGE_PAGE":
            raise HTTPException(status_code=503, detail=detail)
        if detail == "CONVERSATION_URL_REQUIRED":
            raise HTTPException(status_code=400, detail=detail)
        raise HTTPException(status_code=502, detail=detail)
    finally:
        profile_lock.release()


@app.post("/v1/threads/{thread_id}/exchange")
def thread_exchange(thread_id: str, payload: ThreadExchangeIn) -> dict[str, Any]:
    thread_row = db.get_thread(thread_id)
    if thread_row is None:
        raise HTTPException(status_code=404, detail=f"Thread not found: {thread_id}")

    prompt = payload.prompt.strip()
    if prompt == "":
        raise HTTPException(status_code=400, detail="Prompt is empty")

    auth_snapshot = auth.status()
    if str(auth_snapshot.get("state") or "") == LOGIN_RUNNING:
        raise HTTPException(status_code=409, detail="LOGIN_SESSION_OPEN")

    if not profile_lock.acquire(timeout=0.2):
        raise HTTPException(status_code=409, detail="PROFILE_BUSY")

    mode = payload.mode.strip() or "default"
    user_message_id = (payload.user_message_id or "").strip() or _new_id("msg")
    assistant_message_id = (payload.assistant_message_id or "").strip() or _new_id("msg")
    source = payload.source.strip() or "web_panel"
    existing_conversation_url = _thread_conversation_url(thread_row)

    try:
        user_message = db.create_message(
            MessageRecord(
                message_id=user_message_id,
                thread_id=thread_id,
                parent_message_id=payload.parent_message_id,
                role="user",
                content_text=prompt,
                mode=mode,
                source=source,
                status="submitted",
                metadata=payload.metadata,
            ),
            [],
        )
        db.append_integration_event(
            IntegrationEventRecord(
                event_id=_new_id("evt"),
                event_type="exchange_user_message_created",
                thread_id=thread_id,
                message_id=user_message_id,
                source="exchange_gateway",
                payload={
                    "mode": mode,
                    "chars": len(prompt),
                },
            )
        )

        try:
            exchange_result = exchange_once(
                settings=settings,
                prompt=prompt,
                mode=mode,
                comparison_preference=payload.comparison_preference,
                conversation_url=existing_conversation_url,
            )
        except RuntimeError as exc:
            detail = str(exc)
            db.append_integration_event(
                IntegrationEventRecord(
                    event_id=_new_id("evt"),
                    event_type="exchange_failed",
                    thread_id=thread_id,
                    message_id=user_message_id,
                    source="exchange_gateway",
                    payload={"detail": detail, "mode": mode},
                )
            )
            if detail == "AUTH_REQUIRED":
                raise HTTPException(status_code=412, detail=detail)
            if detail == "CHALLENGE_PAGE":
                raise HTTPException(status_code=503, detail=detail)
            if detail == "XVFB_START_FAILED":
                raise HTTPException(status_code=500, detail=detail)
            if "timeout" in detail.lower():
                raise HTTPException(status_code=504, detail=detail)
            raise HTTPException(status_code=502, detail=detail)

        assistant_text = str(exchange_result.get("assistant_text") or "").strip()
        if assistant_text == "":
            db.append_integration_event(
                IntegrationEventRecord(
                    event_id=_new_id("evt"),
                    event_type="exchange_failed",
                    thread_id=thread_id,
                    message_id=user_message_id,
                    source="exchange_gateway",
                    payload={"detail": "EMPTY_ASSISTANT_RESPONSE", "mode": mode},
                )
            )
            raise HTTPException(status_code=502, detail="EMPTY_ASSISTANT_RESPONSE")

        assistant_meta = dict(payload.assistant_metadata)
        resolved_conversation_url = str(exchange_result.get("conversation_url") or "").strip()
        opened_url = str(exchange_result.get("opened_url") or existing_conversation_url).strip()
        _store_thread_conversation_url(
            thread_id=thread_id,
            conversation_url=resolved_conversation_url,
            opened_url=opened_url,
        )
        comparison_options_raw = exchange_result.get("comparison_options")
        comparison_options: list[dict[str, Any]] = []
        if isinstance(comparison_options_raw, list):
            for item in comparison_options_raw:
                if not isinstance(item, dict):
                    continue
                text = str(item.get("text") or "").strip()
                if text == "":
                    continue
                label = str(item.get("label") or "").strip() or "Odpowiedź"
                idx_raw = item.get("index")
                idx = int(idx_raw) if isinstance(idx_raw, int) else None
                comparison_options.append({"index": idx, "label": label, "text": text})

        assistant_meta["exchange"] = {
            "elapsed_ms": int(exchange_result.get("elapsed_ms") or 0),
            "conversation_url": resolved_conversation_url,
            "opened_url": opened_url,
            "assistant_count": int(exchange_result.get("assistant_count") or 0),
            "user_count": int(exchange_result.get("user_count") or 0),
            "comparison_gate_handled": bool(exchange_result.get("comparison_gate_handled")),
            "comparison_selected_index": exchange_result.get("comparison_selected_index"),
            "comparison_options": comparison_options,
        }

        assistant_message = db.create_message(
            MessageRecord(
                message_id=assistant_message_id,
                thread_id=thread_id,
                parent_message_id=user_message_id,
                role="assistant",
                content_text=assistant_text,
                mode=mode,
                source="chatgpt_ui",
                status="received",
                metadata=assistant_meta,
            ),
            [],
        )
        db.append_integration_event(
            IntegrationEventRecord(
                event_id=_new_id("evt"),
                event_type="exchange_completed",
                thread_id=thread_id,
                message_id=assistant_message_id,
                source="exchange_gateway",
                payload={
                    "mode": mode,
                    "elapsed_ms": int(exchange_result.get("elapsed_ms") or 0),
                    "comparison_gate_handled": bool(exchange_result.get("comparison_gate_handled")),
                    "comparison_selected_index": exchange_result.get("comparison_selected_index"),
                    "comparison_options_count": len(comparison_options),
                    "conversation_url": resolved_conversation_url,
                },
            )
        )

        return {
            "ok": True,
            "thread_id": thread_id,
            "user_message": user_message,
            "assistant_message": assistant_message,
            "exchange": exchange_result,
        }
    except HTTPException:
        raise
    except ValueError as exc:
        raise HTTPException(status_code=404, detail=str(exc))
    except Exception as exc:
        raise HTTPException(status_code=500, detail=str(exc))
    finally:
        profile_lock.release()


@app.post("/v1/events")
def integration_event_create(payload: IntegrationEventIn) -> dict[str, Any]:
    event_id = (payload.event_id or "").strip() or _new_id("evt")
    try:
        db.append_integration_event(
            IntegrationEventRecord(
                event_id=event_id,
                event_type=payload.event_type,
                thread_id=payload.thread_id,
                message_id=payload.message_id,
                source=payload.source,
                payload=payload.payload,
            )
        )
        return {"ok": True, "event_id": event_id}
    except Exception as exc:
        raise HTTPException(status_code=500, detail=str(exc))


@app.get("/v1/events")
def integration_events_list(
    limit: int = Query(100, ge=1, le=1000),
    thread_id: str | None = Query(default=None),
) -> dict[str, Any]:
    items = db.list_integration_events(limit=limit, thread_id=thread_id)
    return {"items": items, "count": len(items)}

# Keep constants referenced in static analysis and future API extensions.
_ = (AUTH_OK, AUTH_REQUIRED, LOGIN_RUNNING)
