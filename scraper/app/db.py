from __future__ import annotations

import hashlib
import json
from dataclasses import dataclass
from datetime import datetime
from typing import Any

import pymysql
from pymysql.cursors import DictCursor

from .config import Settings


@dataclass
class PostRecord:
    post_url: str
    url_hash: str
    author: str | None
    content: str | None
    source_page: str
    activity_type: str | None
    activity_label: str | None
    activity_urn: str | None
    published_label: str | None
    content_hash: str


@dataclass
class ItemRecord:
    canonical_url: str
    url_hash: str
    item_urn: str | None
    author: str | None
    content: str | None
    content_type: str
    published_label: str | None


class Database:
    def __init__(self, settings: Settings) -> None:
        self.settings = settings

    def _connect(self) -> pymysql.connections.Connection:
        return pymysql.connect(
            host=self.settings.db_host,
            port=self.settings.db_port,
            user=self.settings.db_user,
            password=self.settings.db_password,
            database=self.settings.db_name,
            charset="utf8mb4",
            cursorclass=DictCursor,
            autocommit=True,
        )

    def open_session(self) -> pymysql.connections.Connection:
        return self._connect()

    @staticmethod
    def close_session(conn: pymysql.connections.Connection) -> None:
        try:
            conn.close()
        except Exception:
            pass

    def ping(self) -> bool:
        try:
            conn = self._connect()
            with conn:
                with conn.cursor() as cur:
                    cur.execute("SELECT 1")
            return True
        except Exception:
            return False

    def create_run(self, mode: str) -> int:
        conn = self._connect()
        with conn:
            with conn.cursor() as cur:
                cur.execute(
                    "INSERT INTO runs (mode, status, started_at) VALUES (%s, 'running', NOW())",
                    (mode,),
                )
                run_id = int(cur.lastrowid)
        return run_id

    def finish_run(
        self,
        run_id: int,
        status: str,
        new_posts: int,
        total_seen: int,
        error_message: str | None = None,
        details: dict[str, Any] | None = None,
    ) -> None:
        conn = self._connect()
        details_raw = json.dumps(details or {}, ensure_ascii=False)
        with conn:
            with conn.cursor() as cur:
                cur.execute(
                    """
                    UPDATE runs
                    SET status = %s,
                        finished_at = NOW(),
                        new_posts = %s,
                        total_seen = %s,
                        error_message = %s,
                        details_json = %s
                    WHERE id = %s
                    """,
                    (status, new_posts, total_seen, error_message, details_raw, run_id),
                )

    def recover_stale_running_runs(self, *, reason: str, min_age_minutes: int = 10) -> int:
        """
        Marks stale rows left in `runs.status='running'` as `error`.

        This protects UI/API state after ungraceful process restarts where the in-memory
        runtime state is reset but DB rows remained "running".
        """
        min_age_minutes = max(0, int(min_age_minutes))
        conn = self._connect()
        with conn:
            with conn.cursor() as cur:
                if min_age_minutes > 0:
                    cur.execute(
                        """
                        UPDATE runs
                        SET status = 'error',
                            finished_at = NOW(),
                            error_message = COALESCE(NULLIF(error_message, ''), %s)
                        WHERE status = 'running'
                          AND started_at < (NOW() - INTERVAL %s MINUTE)
                        """,
                        (reason, min_age_minutes),
                    )
                else:
                    cur.execute(
                        """
                        UPDATE runs
                        SET status = 'error',
                            finished_at = NOW(),
                            error_message = COALESCE(NULLIF(error_message, ''), %s)
                        WHERE status = 'running'
                        """,
                        (reason,),
                    )
                return int(cur.rowcount or 0)

    def insert_post(self, post: PostRecord, run_id: int) -> bool:
        conn = self.open_session()
        try:
            return self.insert_post_with_connection(conn, post, run_id)
        finally:
            self.close_session(conn)

    def insert_post_with_connection(
        self,
        conn: pymysql.connections.Connection,
        post: PostRecord,
        run_id: int,
    ) -> bool:
        try:
            with conn.cursor() as cur:
                cur.execute(
                    """
                    INSERT IGNORE INTO posts
                        (post_url, url_hash, author, content, source_page, activity_type, activity_label, activity_urn, published_label, content_hash, collected_at, run_id)
                    VALUES
                        (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, NOW(), %s)
                    """,
                    (
                        post.post_url,
                        post.url_hash,
                        post.author,
                        post.content,
                        post.source_page,
                        post.activity_type,
                        post.activity_label,
                        post.activity_urn,
                        post.published_label,
                        post.content_hash,
                        run_id,
                    ),
                )
                return cur.rowcount > 0
        except pymysql.err.IntegrityError:
            return False

    def upsert_item_with_connection(
        self,
        conn: pymysql.connections.Connection,
        item: ItemRecord,
    ) -> int:
        """
        Upserts a canonical item and returns its numeric id.

        Uses MySQL LAST_INSERT_ID trick to return the existing id on upsert.
        """
        with conn.cursor() as cur:
            cur.execute(
                """
                INSERT INTO items
                    (item_urn, canonical_url, url_hash, author, content, content_type, published_label, collected_first_at, collected_last_at)
                VALUES
                    (%s, %s, %s, %s, %s, %s, %s, NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                    id = LAST_INSERT_ID(id),
                    author = COALESCE(VALUES(author), author),
                    content = IF(
                        VALUES(content) IS NOT NULL
                        AND (content IS NULL OR CHAR_LENGTH(VALUES(content)) > CHAR_LENGTH(content)),
                        VALUES(content),
                        content
                    ),
                    content_type = CASE
                        WHEN VALUES(content_type) IS NULL OR VALUES(content_type) = 'unknown' THEN content_type
                        WHEN
                            (CASE content_type
                                WHEN 'unknown' THEN 0
                                WHEN 'text' THEN 1
                                WHEN 'article' THEN 2
                                WHEN 'image' THEN 3
                                WHEN 'video' THEN 4
                                WHEN 'document' THEN 5
                                ELSE 0
                            END)
                            <
                            (CASE VALUES(content_type)
                                WHEN 'unknown' THEN 0
                                WHEN 'text' THEN 1
                                WHEN 'article' THEN 2
                                WHEN 'image' THEN 3
                                WHEN 'video' THEN 4
                                WHEN 'document' THEN 5
                                ELSE 0
                            END)
                        THEN VALUES(content_type)
                        ELSE content_type
                    END,
                    published_label = COALESCE(VALUES(published_label), published_label),
                    canonical_url = IF(canonical_url LIKE 'urn:linkedin-archive:%%' AND VALUES(canonical_url) LIKE 'https://%%', VALUES(canonical_url), canonical_url),
                    url_hash = IF(canonical_url LIKE 'urn:linkedin-archive:%%' AND VALUES(canonical_url) LIKE 'https://%%', VALUES(url_hash), url_hash),
                    collected_last_at = NOW()
                """,
                (
                    item.item_urn,
                    item.canonical_url,
                    item.url_hash,
                    item.author,
                    item.content,
                    item.content_type,
                    item.published_label,
                ),
            )
            return int(cur.lastrowid)

    def insert_item_context_with_connection(
        self,
        conn: pymysql.connections.Connection,
        *,
        item_id: int,
        source: str,
        activity_kind: str,
        activity_label: str | None,
        context_text: str | None,
        context_hash: str,
        run_id: int,
    ) -> bool:
        try:
            with conn.cursor() as cur:
                cur.execute(
                    """
                    INSERT IGNORE INTO item_contexts
                        (item_id, source, activity_kind, activity_label, context_text, context_hash, collected_at, run_id)
                    VALUES
                        (%s, %s, %s, %s, %s, %s, NOW(), %s)
                    """,
                    (
                        item_id,
                        source,
                        activity_kind,
                        activity_label,
                        context_text,
                        context_hash,
                        run_id,
                    ),
                )
                return cur.rowcount > 0
        except pymysql.err.IntegrityError:
            return False

    @staticmethod
    def context_hash_for(
        *,
        source: str,
        activity_kind: str,
        item_key: str,
        content_hash: str,
        activity_label: str | None = None,
        published_label: str | None = None,
        context_text: str | None = None,
    ) -> str:
        # Deterministic dedupe key for the same observed card/event across runs.
        payload = "|".join(
            [
                source,
                activity_kind,
                item_key,
                content_hash,
                activity_label or "",
                published_label or "",
                context_text or "",
            ]
        )
        return hashlib.sha256(payload.encode("utf-8")).hexdigest()

    def latest_run(self) -> dict[str, Any] | None:
        conn = self._connect()
        with conn:
            with conn.cursor() as cur:
                cur.execute(
                    """
                    SELECT id, mode, status, started_at, finished_at, new_posts, total_seen, error_message
                    FROM runs
                    ORDER BY started_at DESC
                    LIMIT 1
                    """
                )
                row = cur.fetchone()
        return row

    def total_posts(self) -> int:
        conn = self._connect()
        with conn:
            with conn.cursor() as cur:
                cur.execute("SELECT COUNT(*) AS c FROM posts")
                row = cur.fetchone() or {"c": 0}
        return int(row["c"])

    def run_summary(self, limit: int = 20) -> list[dict[str, Any]]:
        conn = self._connect()
        with conn:
            with conn.cursor() as cur:
                cur.execute(
                    """
                    SELECT id, mode, status, started_at, finished_at, new_posts, total_seen, error_message
                    FROM runs
                    ORDER BY started_at DESC
                    LIMIT %s
                    """,
                    (limit,),
                )
                rows = cur.fetchall() or []
        return rows

    def recent_posts(self, limit: int = 100) -> list[dict[str, Any]]:
        conn = self._connect()
        with conn:
            with conn.cursor() as cur:
                cur.execute(
                    """
                    SELECT id, author, content, source_page, post_url, published_label, collected_at
                    FROM posts
                    ORDER BY collected_at DESC
                    LIMIT %s
                    """,
                    (limit,),
                )
                rows = cur.fetchall() or []
        return rows

    def hydration_candidates(
        self,
        *,
        limit: int,
        max_content_len: int = 1200,
        only_without_notes: bool = False,
        source: str | None = None,
        activity_kind: str | None = None,
    ) -> list[dict[str, Any]]:
        """
        Returns candidate items for hydrate-only runs.

        Heuristic: LinkedIn truncation is more common for text-like items; we bias
        towards items that are likely to benefit from permalink hydration.
        """
        limit = max(1, min(int(limit), 500))
        max_content_len = max(50, min(int(max_content_len), 20_000))

        where = [
            "i.canonical_url LIKE 'https://www.linkedin.com/feed/update/%%'",
            "i.content_type IN ('text','article')",
            "(i.content IS NULL OR CHAR_LENGTH(i.content) < %s OR "
            "i.content LIKE '%%… więcej%%' OR i.content LIKE '%%... więcej%%' OR "
            "i.content LIKE '%%pokaż więcej%%' OR i.content LIKE '%%see more%%')",
        ]
        params: list[Any] = [max_content_len]

        if only_without_notes:
            where.append("ud.item_id IS NULL")

        if source:
            where.append(
                "EXISTS (SELECT 1 FROM item_contexts cf WHERE cf.item_id = i.id AND cf.source = %s)"
            )
            params.append(source)

        if activity_kind:
            where.append(
                "EXISTS (SELECT 1 FROM item_contexts ck WHERE ck.item_id = i.id AND ck.activity_kind = %s)"
            )
            params.append(activity_kind)

        params.append(limit)

        conn = self._connect()
        with conn:
            with conn.cursor() as cur:
                cur.execute(
                    f"""
                    SELECT
                        i.id,
                        i.item_urn,
                        i.canonical_url,
                        i.url_hash,
                        i.author,
                        i.content,
                        i.content_type,
                        i.published_label,
                        MAX(c.collected_at) AS last_context_at
                    FROM items i
                    INNER JOIN item_contexts c ON c.item_id = i.id
                    LEFT JOIN item_user_data ud ON ud.item_id = i.id
                    WHERE {' AND '.join(where)}
                    GROUP BY i.id
                    ORDER BY last_context_at DESC
                    LIMIT %s
                    """,
                    tuple(params),
                )
                rows = cur.fetchall() or []
        return rows

    @staticmethod
    def iso_dt(value: datetime | None) -> str | None:
        if not value:
            return None
        return value.isoformat()
