from __future__ import annotations

import json
from dataclasses import dataclass
from typing import Any

import psycopg2
from psycopg2.extras import RealDictCursor

from .config import Settings


@dataclass
class LoginSessionRecord:
    session_id: str
    state: str
    target_name: str
    login_url: str
    novnc_url: str


@dataclass
class ThreadRecord:
    thread_id: str
    title: str
    project_id: str | None = None
    assistant_id: str | None = None
    status: str = "active"
    metadata: dict[str, Any] | None = None


@dataclass
class MessageRecord:
    message_id: str
    thread_id: str
    role: str
    content_text: str
    parent_message_id: str | None = None
    mode: str = "default"
    source: str = "local_ui"
    status: str = "accepted"
    tool_name: str | None = None
    metadata: dict[str, Any] | None = None


@dataclass
class AttachmentRecord:
    attachment_id: str
    message_id: str
    file_name: str
    mime_type: str | None = None
    size_bytes: int | None = None
    storage_ref: str | None = None
    metadata: dict[str, Any] | None = None


@dataclass
class IntegrationEventRecord:
    event_id: str
    event_type: str
    thread_id: str | None = None
    message_id: str | None = None
    source: str = "web_panel"
    payload: dict[str, Any] | None = None


class Database:
    def __init__(self, settings: Settings) -> None:
        self.settings = settings

    def _connect(self):
        return psycopg2.connect(
            host=self.settings.db_host,
            port=self.settings.db_port,
            dbname=self.settings.db_name,
            user=self.settings.db_user,
            password=self.settings.db_password,
        )

    def ping(self) -> bool:
        try:
            conn = self._connect()
            with conn:
                with conn.cursor() as cur:
                    cur.execute("SELECT 1")
            return True
        except Exception:
            return False

    def init_schema(self) -> None:
        conn = self._connect()
        with conn:
            with conn.cursor() as cur:
                cur.execute(
                    """
                    CREATE TABLE IF NOT EXISTS auth_sessions (
                        id BIGSERIAL PRIMARY KEY,
                        session_id VARCHAR(64) NOT NULL UNIQUE,
                        target_name VARCHAR(128) NOT NULL,
                        login_url TEXT NOT NULL,
                        novnc_url TEXT NOT NULL,
                        state VARCHAR(32) NOT NULL,
                        started_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                        stopped_at TIMESTAMPTZ NULL,
                        last_check_at TIMESTAMPTZ NULL,
                        metadata_json JSONB NULL
                    )
                    """
                )
                cur.execute(
                    """
                    CREATE INDEX IF NOT EXISTS idx_auth_sessions_started_at
                    ON auth_sessions (started_at DESC)
                    """
                )
                cur.execute(
                    """
                    CREATE TABLE IF NOT EXISTS auth_events (
                        id BIGSERIAL PRIMARY KEY,
                        session_id VARCHAR(64) NOT NULL,
                        event_type VARCHAR(64) NOT NULL,
                        payload_json JSONB NULL,
                        created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
                    )
                    """
                )
                cur.execute(
                    """
                    CREATE INDEX IF NOT EXISTS idx_auth_events_session_created
                    ON auth_events (session_id, created_at DESC)
                    """
                )

                cur.execute(
                    """
                    CREATE TABLE IF NOT EXISTS integration_threads (
                        id BIGSERIAL PRIMARY KEY,
                        thread_id VARCHAR(64) NOT NULL UNIQUE,
                        title VARCHAR(255) NOT NULL,
                        project_id VARCHAR(128) NULL,
                        assistant_id VARCHAR(128) NULL,
                        status VARCHAR(32) NOT NULL DEFAULT 'active',
                        created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                        updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                        metadata_json JSONB NULL
                    )
                    """
                )
                cur.execute(
                    """
                    CREATE INDEX IF NOT EXISTS idx_integration_threads_updated
                    ON integration_threads (updated_at DESC)
                    """
                )
                cur.execute(
                    """
                    CREATE INDEX IF NOT EXISTS idx_integration_threads_project_updated
                    ON integration_threads (project_id, updated_at DESC)
                    """
                )
                cur.execute(
                    """
                    CREATE INDEX IF NOT EXISTS idx_integration_threads_assistant_updated
                    ON integration_threads (assistant_id, updated_at DESC)
                    """
                )

                cur.execute(
                    """
                    CREATE TABLE IF NOT EXISTS integration_messages (
                        id BIGSERIAL PRIMARY KEY,
                        message_id VARCHAR(64) NOT NULL UNIQUE,
                        thread_id VARCHAR(64) NOT NULL REFERENCES integration_threads(thread_id) ON DELETE CASCADE,
                        parent_message_id VARCHAR(64) NULL,
                        role VARCHAR(16) NOT NULL,
                        content_text TEXT NOT NULL,
                        mode VARCHAR(64) NOT NULL DEFAULT 'default',
                        source VARCHAR(64) NOT NULL DEFAULT 'local_ui',
                        status VARCHAR(32) NOT NULL DEFAULT 'accepted',
                        tool_name VARCHAR(128) NULL,
                        created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                        metadata_json JSONB NULL
                    )
                    """
                )
                cur.execute(
                    """
                    CREATE INDEX IF NOT EXISTS idx_integration_messages_thread_created
                    ON integration_messages (thread_id, created_at ASC)
                    """
                )

                cur.execute(
                    """
                    CREATE TABLE IF NOT EXISTS integration_message_attachments (
                        id BIGSERIAL PRIMARY KEY,
                        attachment_id VARCHAR(64) NOT NULL UNIQUE,
                        message_id VARCHAR(64) NOT NULL REFERENCES integration_messages(message_id) ON DELETE CASCADE,
                        file_name VARCHAR(255) NOT NULL,
                        mime_type VARCHAR(128) NULL,
                        size_bytes BIGINT NULL,
                        storage_ref TEXT NULL,
                        created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                        metadata_json JSONB NULL
                    )
                    """
                )
                cur.execute(
                    """
                    CREATE INDEX IF NOT EXISTS idx_integration_attachments_message
                    ON integration_message_attachments (message_id, created_at ASC)
                    """
                )

                cur.execute(
                    """
                    CREATE TABLE IF NOT EXISTS integration_events (
                        id BIGSERIAL PRIMARY KEY,
                        event_id VARCHAR(64) NOT NULL UNIQUE,
                        event_type VARCHAR(64) NOT NULL,
                        thread_id VARCHAR(64) NULL,
                        message_id VARCHAR(64) NULL,
                        source VARCHAR(64) NOT NULL DEFAULT 'web_panel',
                        payload_json JSONB NULL,
                        created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
                    )
                    """
                )
                cur.execute(
                    """
                    CREATE INDEX IF NOT EXISTS idx_integration_events_created
                    ON integration_events (created_at DESC)
                    """
                )
                cur.execute(
                    """
                    CREATE INDEX IF NOT EXISTS idx_integration_events_thread_created
                    ON integration_events (thread_id, created_at DESC)
                    """
                )

    def recover_open_sessions(self, reason: str) -> int:
        conn = self._connect()
        with conn:
            with conn.cursor() as cur:
                cur.execute(
                    """
                    UPDATE auth_sessions
                    SET
                        state = 'AUTH_UNKNOWN',
                        stopped_at = NOW(),
                        metadata_json = COALESCE(metadata_json, '{}'::jsonb)
                            || jsonb_build_object('recovery_reason', %s)
                    WHERE stopped_at IS NULL
                    """,
                    (reason,),
                )
                return int(cur.rowcount or 0)

    def upsert_login_session(self, record: LoginSessionRecord, metadata: dict[str, Any] | None = None) -> None:
        metadata_raw = json.dumps(metadata or {}, ensure_ascii=False)
        conn = self._connect()
        with conn:
            with conn.cursor() as cur:
                cur.execute(
                    """
                    INSERT INTO auth_sessions (
                        session_id, target_name, login_url, novnc_url, state, started_at, last_check_at, metadata_json
                    )
                    VALUES (%s, %s, %s, %s, %s, NOW(), NOW(), %s::jsonb)
                    ON CONFLICT (session_id) DO UPDATE
                    SET
                        target_name = EXCLUDED.target_name,
                        login_url = EXCLUDED.login_url,
                        novnc_url = EXCLUDED.novnc_url,
                        state = EXCLUDED.state,
                        last_check_at = NOW(),
                        metadata_json = COALESCE(auth_sessions.metadata_json, '{}'::jsonb)
                            || COALESCE(EXCLUDED.metadata_json, '{}'::jsonb)
                    """,
                    (
                        record.session_id,
                        record.target_name,
                        record.login_url,
                        record.novnc_url,
                        record.state,
                        metadata_raw,
                    ),
                )

    def update_session_state(
        self,
        session_id: str,
        state: str,
        metadata: dict[str, Any] | None = None,
    ) -> None:
        metadata_raw = json.dumps(metadata or {}, ensure_ascii=False)
        conn = self._connect()
        with conn:
            with conn.cursor() as cur:
                cur.execute(
                    """
                    UPDATE auth_sessions
                    SET
                        state = %s,
                        last_check_at = NOW(),
                        metadata_json = COALESCE(metadata_json, '{}'::jsonb)
                            || COALESCE(%s::jsonb, '{}'::jsonb)
                    WHERE session_id = %s
                    """,
                    (state, metadata_raw, session_id),
                )

    def stop_session(self, session_id: str, final_state: str, metadata: dict[str, Any] | None = None) -> None:
        metadata_raw = json.dumps(metadata or {}, ensure_ascii=False)
        conn = self._connect()
        with conn:
            with conn.cursor() as cur:
                cur.execute(
                    """
                    UPDATE auth_sessions
                    SET
                        state = %s,
                        stopped_at = NOW(),
                        last_check_at = NOW(),
                        metadata_json = COALESCE(metadata_json, '{}'::jsonb)
                            || COALESCE(%s::jsonb, '{}'::jsonb)
                    WHERE session_id = %s
                    """,
                    (final_state, metadata_raw, session_id),
                )

    def append_event(self, session_id: str, event_type: str, payload: dict[str, Any] | None = None) -> None:
        payload_raw = json.dumps(payload or {}, ensure_ascii=False)
        conn = self._connect()
        with conn:
            with conn.cursor() as cur:
                cur.execute(
                    """
                    INSERT INTO auth_events (session_id, event_type, payload_json)
                    VALUES (%s, %s, %s::jsonb)
                    """,
                    (session_id, event_type, payload_raw),
                )

    def latest_session(self) -> dict[str, Any] | None:
        conn = self._connect()
        with conn:
            with conn.cursor(cursor_factory=RealDictCursor) as cur:
                cur.execute(
                    """
                    SELECT
                        session_id,
                        target_name,
                        login_url,
                        novnc_url,
                        state,
                        started_at,
                        stopped_at,
                        last_check_at,
                        metadata_json
                    FROM auth_sessions
                    ORDER BY started_at DESC
                    LIMIT 1
                    """
                )
                row = cur.fetchone()
                return dict(row) if row else None

    def recent_events(self, limit: int = 50) -> list[dict[str, Any]]:
        limit = max(1, min(int(limit), 500))
        conn = self._connect()
        with conn:
            with conn.cursor(cursor_factory=RealDictCursor) as cur:
                cur.execute(
                    """
                    SELECT session_id, event_type, payload_json, created_at
                    FROM auth_events
                    ORDER BY created_at DESC
                    LIMIT %s
                    """,
                    (limit,),
                )
                rows = cur.fetchall() or []
                return [dict(row) for row in rows]

    def upsert_thread(self, record: ThreadRecord) -> dict[str, Any]:
        metadata_raw = json.dumps(record.metadata or {}, ensure_ascii=False)
        conn = self._connect()
        with conn:
            with conn.cursor() as cur:
                cur.execute(
                    """
                    INSERT INTO integration_threads (
                        thread_id, title, project_id, assistant_id, status, created_at, updated_at, metadata_json
                    )
                    VALUES (%s, %s, %s, %s, %s, NOW(), NOW(), %s::jsonb)
                    ON CONFLICT (thread_id) DO UPDATE
                    SET
                        title = EXCLUDED.title,
                        project_id = EXCLUDED.project_id,
                        assistant_id = EXCLUDED.assistant_id,
                        status = EXCLUDED.status,
                        updated_at = NOW(),
                        metadata_json = COALESCE(integration_threads.metadata_json, '{}'::jsonb)
                            || COALESCE(EXCLUDED.metadata_json, '{}'::jsonb)
                    """,
                    (
                        record.thread_id,
                        record.title,
                        record.project_id,
                        record.assistant_id,
                        record.status,
                        metadata_raw,
                    ),
                )
        return self.get_thread(record.thread_id) or {}

    def get_thread(self, thread_id: str) -> dict[str, Any] | None:
        conn = self._connect()
        with conn:
            with conn.cursor(cursor_factory=RealDictCursor) as cur:
                cur.execute(
                    """
                    SELECT
                        t.thread_id,
                        t.title,
                        t.project_id,
                        t.assistant_id,
                        t.status,
                        t.created_at,
                        t.updated_at,
                        t.metadata_json,
                        COALESCE(m.cnt, 0) AS message_count
                    FROM integration_threads t
                    LEFT JOIN (
                        SELECT thread_id, COUNT(*)::BIGINT AS cnt
                        FROM integration_messages
                        GROUP BY thread_id
                    ) m ON m.thread_id = t.thread_id
                    WHERE t.thread_id = %s
                    LIMIT 1
                    """,
                    (thread_id,),
                )
                row = cur.fetchone()
                return dict(row) if row else None

    def update_thread_metadata(self, thread_id: str, metadata: dict[str, Any] | None = None) -> dict[str, Any] | None:
        metadata_raw = json.dumps(metadata or {}, ensure_ascii=False)
        conn = self._connect()
        with conn:
            with conn.cursor() as cur:
                cur.execute(
                    """
                    UPDATE integration_threads
                    SET
                        updated_at = NOW(),
                        metadata_json = COALESCE(metadata_json, '{}'::jsonb)
                            || COALESCE(%s::jsonb, '{}'::jsonb)
                    WHERE thread_id = %s
                    """,
                    (metadata_raw, thread_id),
                )
                if int(cur.rowcount or 0) <= 0:
                    return None
        return self.get_thread(thread_id)

    def list_threads(
        self,
        *,
        limit: int = 100,
        project_id: str | None = None,
        assistant_id: str | None = None,
        q: str | None = None,
    ) -> list[dict[str, Any]]:
        limit = max(1, min(int(limit), 500))
        params: list[Any] = []
        where: list[str] = []

        if project_id:
            params.append(project_id)
            where.append(f"t.project_id = %s")
        if assistant_id:
            params.append(assistant_id)
            where.append(f"t.assistant_id = %s")
        if q:
            params.append(f"%{q.strip()}%")
            where.append("t.title ILIKE %s")

        sql = """
            SELECT
                t.thread_id,
                t.title,
                t.project_id,
                t.assistant_id,
                t.status,
                t.created_at,
                t.updated_at,
                t.metadata_json,
                COALESCE(m.cnt, 0) AS message_count
            FROM integration_threads t
            LEFT JOIN (
                SELECT thread_id, COUNT(*)::BIGINT AS cnt
                FROM integration_messages
                GROUP BY thread_id
            ) m ON m.thread_id = t.thread_id
        """
        if where:
            sql += " WHERE " + " AND ".join(where)
        sql += " ORDER BY t.updated_at DESC LIMIT %s"
        params.append(limit)

        conn = self._connect()
        with conn:
            with conn.cursor(cursor_factory=RealDictCursor) as cur:
                cur.execute(sql, tuple(params))
                rows = cur.fetchall() or []
                return [dict(row) for row in rows]

    def list_threads_for_sync(
        self,
        *,
        limit: int = 1000,
        project_id: str | None = None,
        assistant_id: str | None = None,
    ) -> list[dict[str, Any]]:
        limit = max(1, min(int(limit), 5000))
        params: list[Any] = []
        where: list[str] = []
        if project_id:
            params.append(project_id)
            where.append("t.project_id = %s")
        if assistant_id:
            params.append(assistant_id)
            where.append("t.assistant_id = %s")

        sql = """
            SELECT
                t.thread_id,
                t.title,
                t.project_id,
                t.assistant_id,
                t.status,
                t.created_at,
                t.updated_at,
                t.metadata_json,
                COALESCE(m.cnt, 0) AS message_count
            FROM integration_threads t
            LEFT JOIN (
                SELECT thread_id, COUNT(*)::BIGINT AS cnt
                FROM integration_messages
                GROUP BY thread_id
            ) m ON m.thread_id = t.thread_id
        """
        if where:
            sql += " WHERE " + " AND ".join(where)
        sql += " ORDER BY t.updated_at DESC LIMIT %s"
        params.append(limit)

        conn = self._connect()
        with conn:
            with conn.cursor(cursor_factory=RealDictCursor) as cur:
                cur.execute(sql, tuple(params))
                rows = cur.fetchall() or []
                return [dict(row) for row in rows]

    def find_thread_by_remote_thread_id(self, remote_thread_id: str) -> dict[str, Any] | None:
        remote_id = str(remote_thread_id or "").strip()
        if remote_id == "":
            return None
        conn = self._connect()
        with conn:
            with conn.cursor(cursor_factory=RealDictCursor) as cur:
                cur.execute(
                    """
                    SELECT
                        t.thread_id,
                        t.title,
                        t.project_id,
                        t.assistant_id,
                        t.status,
                        t.created_at,
                        t.updated_at,
                        t.metadata_json,
                        COALESCE(m.cnt, 0) AS message_count
                    FROM integration_threads t
                    LEFT JOIN (
                        SELECT thread_id, COUNT(*)::BIGINT AS cnt
                        FROM integration_messages
                        GROUP BY thread_id
                    ) m ON m.thread_id = t.thread_id
                    WHERE COALESCE(t.metadata_json -> 'chatgpt' ->> 'remote_thread_id', '') = %s
                    ORDER BY t.updated_at DESC
                    LIMIT 1
                    """,
                    (remote_id,),
                )
                row = cur.fetchone()
                return dict(row) if row else None

    def find_thread_by_conversation_url(self, conversation_url: str) -> dict[str, Any] | None:
        conv = str(conversation_url or "").strip()
        if conv == "":
            return None
        conn = self._connect()
        with conn:
            with conn.cursor(cursor_factory=RealDictCursor) as cur:
                cur.execute(
                    """
                    SELECT
                        t.thread_id,
                        t.title,
                        t.project_id,
                        t.assistant_id,
                        t.status,
                        t.created_at,
                        t.updated_at,
                        t.metadata_json,
                        COALESCE(m.cnt, 0) AS message_count
                    FROM integration_threads t
                    LEFT JOIN (
                        SELECT thread_id, COUNT(*)::BIGINT AS cnt
                        FROM integration_messages
                        GROUP BY thread_id
                    ) m ON m.thread_id = t.thread_id
                    WHERE COALESCE(t.metadata_json -> 'chatgpt' ->> 'conversation_url', '') = %s
                    ORDER BY t.updated_at DESC
                    LIMIT 1
                    """,
                    (conv,),
                )
                row = cur.fetchone()
                return dict(row) if row else None

    def delete_thread(self, thread_id: str) -> int:
        tid = str(thread_id or "").strip()
        if tid == "":
            return 0
        conn = self._connect()
        with conn:
            with conn.cursor() as cur:
                cur.execute(
                    """
                    DELETE FROM integration_threads
                    WHERE thread_id = %s
                    """,
                    (tid,),
                )
                return int(cur.rowcount or 0)

    def create_message(self, record: MessageRecord, attachments: list[AttachmentRecord] | None = None) -> dict[str, Any]:
        metadata_raw = json.dumps(record.metadata or {}, ensure_ascii=False)
        attachments = attachments or []

        conn = self._connect()
        with conn:
            with conn.cursor() as cur:
                cur.execute("SELECT 1 FROM integration_threads WHERE thread_id = %s LIMIT 1", (record.thread_id,))
                if cur.fetchone() is None:
                    raise ValueError(f"Thread not found: {record.thread_id}")

                cur.execute(
                    """
                    INSERT INTO integration_messages (
                        message_id,
                        thread_id,
                        parent_message_id,
                        role,
                        content_text,
                        mode,
                        source,
                        status,
                        tool_name,
                        created_at,
                        metadata_json
                    )
                    VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, NOW(), %s::jsonb)
                    """,
                    (
                        record.message_id,
                        record.thread_id,
                        record.parent_message_id,
                        record.role,
                        record.content_text,
                        record.mode,
                        record.source,
                        record.status,
                        record.tool_name,
                        metadata_raw,
                    ),
                )

                for att in attachments:
                    cur.execute(
                        """
                        SELECT 1
                        FROM integration_message_attachments
                        WHERE message_id = %s
                          AND COALESCE(storage_ref, '') = COALESCE(%s, '')
                        LIMIT 1
                        """,
                        (record.message_id, att.storage_ref),
                    )
                    if cur.fetchone() is not None:
                        continue
                    cur.execute(
                        """
                        INSERT INTO integration_message_attachments (
                            attachment_id,
                            message_id,
                            file_name,
                            mime_type,
                            size_bytes,
                            storage_ref,
                            metadata_json
                        )
                        VALUES (%s, %s, %s, %s, %s, %s, %s::jsonb)
                        """,
                        (
                            att.attachment_id,
                            record.message_id,
                            att.file_name,
                            att.mime_type,
                            att.size_bytes,
                            att.storage_ref,
                            json.dumps(att.metadata or {}, ensure_ascii=False),
                        ),
                    )

                cur.execute(
                    """
                    UPDATE integration_threads
                    SET updated_at = NOW()
                    WHERE thread_id = %s
                    """,
                    (record.thread_id,),
                )

        return self.get_message(record.message_id) or {}

    def update_message(
        self,
        message_id: str,
        *,
        content_text: str | None = None,
        status: str | None = None,
        metadata: dict[str, Any] | None = None,
    ) -> dict[str, Any] | None:
        metadata_raw = json.dumps(metadata or {}, ensure_ascii=False)
        conn = self._connect()
        with conn:
            with conn.cursor() as cur:
                cur.execute(
                    """
                    UPDATE integration_messages
                    SET
                        content_text = COALESCE(%s, content_text),
                        status = COALESCE(%s, status),
                        metadata_json = COALESCE(metadata_json, '{}'::jsonb)
                            || COALESCE(%s::jsonb, '{}'::jsonb)
                    WHERE message_id = %s
                    """,
                    (
                        content_text,
                        status,
                        metadata_raw,
                        message_id,
                    ),
                )
                if int(cur.rowcount or 0) <= 0:
                    return None
                cur.execute(
                    """
                    UPDATE integration_threads
                    SET updated_at = NOW()
                    WHERE thread_id = (
                        SELECT thread_id
                        FROM integration_messages
                        WHERE message_id = %s
                        LIMIT 1
                    )
                    """,
                    (message_id,),
                )
        return self.get_message(message_id)

    def get_message(self, message_id: str) -> dict[str, Any] | None:
        conn = self._connect()
        with conn:
            with conn.cursor(cursor_factory=RealDictCursor) as cur:
                cur.execute(
                    """
                    SELECT
                        message_id,
                        thread_id,
                        parent_message_id,
                        role,
                        content_text,
                        mode,
                        source,
                        status,
                        tool_name,
                        created_at,
                        metadata_json
                    FROM integration_messages
                    WHERE message_id = %s
                    LIMIT 1
                    """,
                    (message_id,),
                )
                row = cur.fetchone()
                if row is None:
                    return None
                message = dict(row)
                message["attachments"] = self.list_attachments(message_id=message_id)
                return message

    def list_attachments(self, message_id: str) -> list[dict[str, Any]]:
        conn = self._connect()
        with conn:
            with conn.cursor(cursor_factory=RealDictCursor) as cur:
                cur.execute(
                    """
                    SELECT
                        attachment_id,
                        message_id,
                        file_name,
                        mime_type,
                        size_bytes,
                        storage_ref,
                        created_at,
                        metadata_json
                    FROM integration_message_attachments
                    WHERE message_id = %s
                    ORDER BY created_at ASC
                    """,
                    (message_id,),
                )
                rows = cur.fetchall() or []
                return [dict(row) for row in rows]

    def list_messages(self, thread_id: str, *, limit: int = 200) -> list[dict[str, Any]]:
        limit = max(1, min(int(limit), 5000))
        conn = self._connect()
        with conn:
            with conn.cursor(cursor_factory=RealDictCursor) as cur:
                cur.execute(
                    """
                    WITH latest AS (
                        SELECT
                            id,
                            message_id,
                            thread_id,
                            parent_message_id,
                            role,
                            content_text,
                            mode,
                            source,
                            status,
                            tool_name,
                            created_at,
                            metadata_json
                        FROM integration_messages
                        WHERE thread_id = %s
                        ORDER BY created_at DESC, id DESC
                        LIMIT %s
                    )
                    SELECT
                        message_id,
                        thread_id,
                        parent_message_id,
                        role,
                        content_text,
                        mode,
                        source,
                        status,
                        tool_name,
                        created_at,
                        metadata_json
                    FROM latest
                    ORDER BY created_at ASC, id ASC
                    """,
                    (thread_id, limit),
                )
                rows = cur.fetchall() or []
                messages = [dict(row) for row in rows]

                message_ids = [str(m["message_id"]) for m in messages if m.get("message_id")]
                attachment_map: dict[str, list[dict[str, Any]]] = {mid: [] for mid in message_ids}
                if message_ids:
                    cur.execute(
                        """
                        SELECT
                            attachment_id,
                            message_id,
                            file_name,
                            mime_type,
                            size_bytes,
                            storage_ref,
                            created_at,
                            metadata_json
                        FROM integration_message_attachments
                        WHERE message_id = ANY(%s::varchar[])
                        ORDER BY created_at ASC
                        """,
                        (message_ids,),
                    )
                    for row in cur.fetchall() or []:
                        drow = dict(row)
                        mid = str(drow.get("message_id") or "")
                        if mid in attachment_map:
                            attachment_map[mid].append(drow)

                for m in messages:
                    mid = str(m.get("message_id") or "")
                    m["attachments"] = attachment_map.get(mid, [])

                return messages

    def delete_messages_by_ids(self, message_ids: list[str]) -> int:
        ids = [str(mid or "").strip() for mid in message_ids if str(mid or "").strip() != ""]
        if not ids:
            return 0
        conn = self._connect()
        with conn:
            with conn.cursor() as cur:
                cur.execute(
                    """
                    DELETE FROM integration_messages
                    WHERE message_id = ANY(%s::varchar[])
                    """,
                    (ids,),
                )
                return int(cur.rowcount or 0)

    def append_attachments(self, message_id: str, attachments: list[AttachmentRecord]) -> int:
        mid = str(message_id or "").strip()
        if mid == "" or not attachments:
            return 0
        inserted = 0
        conn = self._connect()
        with conn:
            with conn.cursor() as cur:
                for att in attachments:
                    cur.execute(
                        """
                        SELECT 1
                        FROM integration_message_attachments
                        WHERE message_id = %s
                          AND COALESCE(storage_ref, '') = COALESCE(%s, '')
                        LIMIT 1
                        """,
                        (mid, att.storage_ref),
                    )
                    if cur.fetchone() is not None:
                        continue
                    cur.execute(
                        """
                        INSERT INTO integration_message_attachments (
                            attachment_id,
                            message_id,
                            file_name,
                            mime_type,
                            size_bytes,
                            storage_ref,
                            metadata_json
                        )
                        VALUES (%s, %s, %s, %s, %s, %s, %s::jsonb)
                        ON CONFLICT (attachment_id) DO NOTHING
                        """,
                        (
                            att.attachment_id,
                            mid,
                            att.file_name,
                            att.mime_type,
                            att.size_bytes,
                            att.storage_ref,
                            json.dumps(att.metadata or {}, ensure_ascii=False),
                        ),
                    )
                    inserted += int(cur.rowcount or 0)
        return inserted

    def append_integration_event(self, record: IntegrationEventRecord) -> None:
        payload_raw = json.dumps(record.payload or {}, ensure_ascii=False)
        conn = self._connect()
        with conn:
            with conn.cursor() as cur:
                cur.execute(
                    """
                    INSERT INTO integration_events (
                        event_id, event_type, thread_id, message_id, source, payload_json
                    )
                    VALUES (%s, %s, %s, %s, %s, %s::jsonb)
                    ON CONFLICT (event_id) DO NOTHING
                    """,
                    (
                        record.event_id,
                        record.event_type,
                        record.thread_id,
                        record.message_id,
                        record.source,
                        payload_raw,
                    ),
                )

    def list_integration_events(self, *, limit: int = 100, thread_id: str | None = None) -> list[dict[str, Any]]:
        limit = max(1, min(int(limit), 1000))
        conn = self._connect()
        with conn:
            with conn.cursor(cursor_factory=RealDictCursor) as cur:
                if thread_id:
                    cur.execute(
                        """
                        SELECT event_id, event_type, thread_id, message_id, source, payload_json, created_at
                        FROM integration_events
                        WHERE thread_id = %s
                        ORDER BY created_at DESC
                        LIMIT %s
                        """,
                        (thread_id, limit),
                    )
                else:
                    cur.execute(
                        """
                        SELECT event_id, event_type, thread_id, message_id, source, payload_json, created_at
                        FROM integration_events
                        ORDER BY created_at DESC
                        LIMIT %s
                        """,
                        (limit,),
                    )
                rows = cur.fetchall() or []
                return [dict(row) for row in rows]
