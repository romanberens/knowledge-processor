from __future__ import annotations

import hashlib
import random
import time
from dataclasses import dataclass, field
from typing import Callable
from urllib.parse import urlparse, urlunparse

from playwright.sync_api import BrowserContext, Page, TimeoutError as PlaywrightTimeoutError, sync_playwright

from .config import Settings
from .db import Database, ItemRecord, PostRecord


SECTION_URLS = {
    "all": "https://www.linkedin.com/in/{slug}/recent-activity/all/",
    "reactions": "https://www.linkedin.com/in/{slug}/recent-activity/reactions/",
    "comments": "https://www.linkedin.com/in/{slug}/recent-activity/comments/",
    # Saved items live under "My items" and use tabs for posts vs articles.
    "saved_posts": "https://www.linkedin.com/my-items/saved-posts/",
    "saved_articles": "https://www.linkedin.com/my-items/saved-posts/",
}

CONTEXT_SOURCE_MAP = {
    "all": "activity_all",
    "reactions": "activity_reactions",
    "comments": "activity_comments",
    "saved_posts": "saved_posts",
    "saved_articles": "saved_articles",
}


@dataclass
class SectionResult:
    source_page: str
    status: str
    seen: int
    inserted: int
    contexts_inserted: int
    with_urn: int
    with_https_url: int
    see_more_clicks: int
    permalink_hydrate_attempted: int
    permalink_hydrate_ok: int
    permalink_hydrate_upgraded: int
    permalink_hydrate_failed: int
    content_type_counts: dict[str, int]
    scrolls: int
    stop_reason: str
    error_message: str | None


@dataclass
class PermalinkHydrationBudget:
    remaining: int
    hydrated_keys: set[str] = field(default_factory=set)


class LinkedInActivityScraper:
    def __init__(self, settings: Settings, db: Database) -> None:
        self.settings = settings
        self.db = db

    def hydrate_only(
        self,
        *,
        run_id: int,
        limit: int,
        max_content_len: int = 1200,
        only_without_notes: bool = False,
        source: str | None = None,
        activity_kind: str | None = None,
        progress_cb: Callable[[dict], None] | None = None,
    ) -> dict:
        """
        Hydrate items directly from DB (no scrolling through sections).

        This is useful for backfilling older items that won't appear in the
        current feed during update runs.
        """
        candidates = self.db.hydration_candidates(
            limit=int(limit),
            max_content_len=int(max_content_len),
            only_without_notes=bool(only_without_notes),
            source=source,
            activity_kind=activity_kind,
        )

        attempted = 0
        ok = 0
        upgraded = 0
        failed = 0

        if progress_cb:
            progress_cb(
                {
                    "message": f"hydrate-only: selected {len(candidates)} candidates",
                    "job": "hydrate_only",
                    "selected": len(candidates),
                }
            )

        db_conn = self.db.open_session()
        try:
            with sync_playwright() as p:
                context = p.chromium.launch_persistent_context(
                    user_data_dir=self.settings.profile_dir,
                    headless=self.settings.headless,
                    viewport={"width": 1400, "height": 1000},
                )
                try:
                    page = context.new_page()
                    page.set_default_timeout(60_000)

                    for idx, cand in enumerate(candidates):
                        attempted += 1
                        if progress_cb:
                            progress_cb(
                                {
                                    "message": f"hydrate-only: {idx + 1}/{len(candidates)}",
                                    "job": "hydrate_only",
                                    "idx": idx + 1,
                                    "total": len(candidates),
                                    "attempted": attempted,
                                    "ok": ok,
                                    "upgraded": upgraded,
                                    "failed": failed,
                                }
                            )

                        url = str(cand.get("canonical_url") or "")
                        if not url.startswith("https://"):
                            failed += 1
                            continue

                        base_content = str(cand.get("content") or "")
                        base_len = len(base_content)
                        activity_urn = str(cand.get("item_urn") or "").strip() or None

                        try:
                            hydrated_row = self._hydrate_from_permalink(
                                page,
                                url=url,
                                activity_urn=activity_urn,
                            )
                            hydrated_content = self._clean(
                                hydrated_row.get("content") if hydrated_row else None,
                                limit=50000,
                            )
                            if hydrated_row and hydrated_content:
                                ok += 1
                                if len(hydrated_content) > base_len + 10:
                                    upgraded += 1

                                hydrated_item = ItemRecord(
                                    canonical_url=url,
                                    url_hash=str(cand.get("url_hash") or hashlib.sha256(url.encode("utf-8")).hexdigest()),
                                    item_urn=activity_urn,
                                    author=self._clean(hydrated_row.get("author")),
                                    content=hydrated_content,
                                    content_type=self._classify_content_type(hydrated_row),
                                    published_label=self._clean(hydrated_row.get("published_label"), limit=128),
                                )
                                self.db.upsert_item_with_connection(db_conn, hydrated_item)
                            else:
                                failed += 1
                        except AuthRequiredError:
                            raise
                        except Exception:
                            failed += 1
                        finally:
                            # Be conservative: small jitter between permalinks.
                            try:
                                page.wait_for_timeout(random.randint(450, 1100))
                            except Exception:
                                pass
                finally:
                    context.close()
        finally:
            self.db.close_session(db_conn)

        return {
            "job": "hydrate_only",
            "candidates_selected": len(candidates),
            "max_content_len": int(max_content_len),
            "only_without_notes": bool(only_without_notes),
            "source": source,
            "activity_kind": activity_kind,
            "permalink_hydrate_limit": int(limit),
            "permalink_hydrate_attempted": attempted,
            "permalink_hydrate_ok": ok,
            "permalink_hydrate_upgraded": upgraded,
            "permalink_hydrate_failed": failed,
        }

    def run(
        self,
        mode: str,
        run_id: int,
        progress_cb: Callable[[dict], None] | None = None,
        max_scrolls_override: int | None = None,
        hydrate_limit_override: int | None = None,
    ) -> dict:
        if mode not in {"deep", "update"}:
            raise ValueError(f"Unsupported mode: {mode}")

        max_scrolls = (
            self.settings.deep_scroll_limit
            if mode == "deep"
            else self.settings.update_scroll_limit
        )
        if max_scrolls_override is not None:
            try:
                requested = int(max_scrolls_override)
            except Exception:
                requested = None
            if requested is not None:
                # Safety clamp: never exceed configured deep limit.
                max_scrolls = max(1, min(requested, int(self.settings.deep_scroll_limit)))

        results: list[SectionResult] = []
        total_seen = 0
        total_inserted = 0
        total_contexts_inserted = 0
        total_with_urn = 0
        total_with_https_url = 0
        total_see_more_clicks = 0
        total_permalink_attempted = 0
        total_permalink_ok = 0
        total_permalink_upgraded = 0
        total_permalink_failed = 0
        total_content_type_counts: dict[str, int] = {}

        permalink_limit = (
            int(self.settings.permalink_hydrate_limit_deep)
            if mode == "deep"
            else int(self.settings.permalink_hydrate_limit_update)
        )
        if hydrate_limit_override is not None:
            try:
                requested = int(hydrate_limit_override)
            except Exception:
                requested = None
            if requested is not None:
                # Safety clamp: avoid hammering LinkedIn.
                permalink_limit = max(0, min(requested, 200))

        hydration: PermalinkHydrationBudget | None = (
            PermalinkHydrationBudget(remaining=permalink_limit) if permalink_limit > 0 else None
        )

        with sync_playwright() as p:
            context = p.chromium.launch_persistent_context(
                user_data_dir=self.settings.profile_dir,
                headless=self.settings.headless,
                viewport={"width": 1400, "height": 1000},
            )
            try:
                for source_page, template in SECTION_URLS.items():
                    url = template.format(slug=self.settings.linkedin_profile_slug)
                    page = context.new_page()
                    page.set_default_timeout(60_000)
                    try:
                        result = self._collect_section(
                            page=page,
                            run_id=run_id,
                            source_page=source_page,
                            section_url=url,
                            mode=mode,
                            max_scrolls=max_scrolls,
                            progress_cb=progress_cb,
                            hydration=hydration,
                        )
                    except AuthRequiredError:
                        raise
                    except Exception as exc:
                        result = SectionResult(
                            source_page=source_page,
                            status="error",
                            seen=0,
                            inserted=0,
                            contexts_inserted=0,
                            with_urn=0,
                            with_https_url=0,
                            see_more_clicks=0,
                            permalink_hydrate_attempted=0,
                            permalink_hydrate_ok=0,
                            permalink_hydrate_upgraded=0,
                            permalink_hydrate_failed=0,
                            content_type_counts={},
                            scrolls=0,
                            stop_reason="error",
                            error_message=str(exc),
                        )
                    finally:
                        try:
                            page.close()
                        except Exception:
                            pass
                    results.append(result)
                    total_seen += result.seen
                    total_inserted += result.inserted
                    total_contexts_inserted += result.contexts_inserted
                    total_with_urn += result.with_urn
                    total_with_https_url += result.with_https_url
                    total_see_more_clicks += result.see_more_clicks
                    total_permalink_attempted += result.permalink_hydrate_attempted
                    total_permalink_ok += result.permalink_hydrate_ok
                    total_permalink_upgraded += result.permalink_hydrate_upgraded
                    total_permalink_failed += result.permalink_hydrate_failed
                    for k, v in (result.content_type_counts or {}).items():
                        total_content_type_counts[k] = int(total_content_type_counts.get(k, 0)) + int(v)
            finally:
                context.close()

        section_errors = [r.__dict__ for r in results if r.status != "ok"]
        return {
            "mode": mode,
            "max_scrolls": max_scrolls,
            "seen": total_seen,
            "inserted": total_inserted,
            "contexts_inserted": total_contexts_inserted,
            "with_urn": total_with_urn,
            "with_https_url": total_with_https_url,
            "see_more_clicks": total_see_more_clicks,
            "permalink_hydrate_limit": permalink_limit,
            "permalink_hydrate_attempted": total_permalink_attempted,
            "permalink_hydrate_ok": total_permalink_ok,
            "permalink_hydrate_upgraded": total_permalink_upgraded,
            "permalink_hydrate_failed": total_permalink_failed,
            "content_type_counts": total_content_type_counts,
            "sections": [r.__dict__ for r in results],
            "section_errors": section_errors,
        }

    def _collect_section(
        self,
        page: Page,
        run_id: int,
        source_page: str,
        section_url: str,
        mode: str,
        max_scrolls: int,
        progress_cb: Callable[[dict], None] | None,
        hydration: PermalinkHydrationBudget | None,
    ) -> SectionResult:
        if progress_cb:
            progress_cb({"message": f"opening section: {source_page}", "source_page": source_page})

        allow_empty = source_page.startswith("saved_")

        last_exc: Exception | None = None
        for attempt in range(2):
            try:
                page.goto(section_url, wait_until="domcontentloaded", timeout=60_000)
                last_exc = None
                break
            except PlaywrightTimeoutError as exc:
                last_exc = exc
                page.wait_for_timeout(1500 + attempt * 800)
        if last_exc is not None:
            raise last_exc

        self._assert_logged_in(page)

        if not self._wait_for_content(page, timeout_ms=22000):
            page.reload(wait_until="domcontentloaded")
            self._assert_logged_in(page)
            if not self._wait_for_content(page, timeout_ms=22000):
                if allow_empty:
                    return SectionResult(
                        source_page=source_page,
                        status="ok",
                        seen=0,
                        inserted=0,
                        contexts_inserted=0,
                        with_urn=0,
                        with_https_url=0,
                        see_more_clicks=0,
                        content_type_counts={},
                        scrolls=0,
                        stop_reason="empty",
                        error_message=None,
                    )
                raise RuntimeError(
                    f"Section {source_page} did not load content. Spinner timeout or empty feed."
                )

        # Saved items page uses tabs (All/Articles). Select before scanning.
        if source_page == "saved_articles":
            self._select_saved_tab(page, tab="articles")
            if not self._wait_for_content(page, timeout_ms=18000) and allow_empty:
                return SectionResult(
                    source_page=source_page,
                    status="ok",
                    seen=0,
                    inserted=0,
                    contexts_inserted=0,
                    with_urn=0,
                    with_https_url=0,
                    see_more_clicks=0,
                    content_type_counts={},
                    scrolls=0,
                    stop_reason="empty",
                    error_message=None,
                )
        elif source_page == "saved_posts":
            self._select_saved_tab(page, tab="all")

        seen_hashes: set[str] = set()
        seen = 0
        inserted = 0
        contexts_inserted = 0
        with_urn = 0
        with_https_url = 0
        see_more_clicks = 0
        permalink_attempted = 0
        permalink_ok = 0
        permalink_upgraded = 0
        permalink_failed = 0
        content_type_counts: dict[str, int] = {}
        stagnation = 0
        known_streak = 0
        stop_reason = "max_scrolls"
        scrolls = 0
        db_conn = self.db.open_session()
        write_legacy_posts = source_page in {"all", "reactions", "comments"}
        permalink_page: Page | None = None

        try:
            for scroll_idx in range(max_scrolls):
                scrolls = scroll_idx + 1
                clicked = self._expand_see_more(page)
                if clicked:
                    # Give the DOM a moment to expand the text blocks.
                    page.wait_for_timeout(180)
                see_more_clicks += clicked
                rows = self._extract_rows(page)
                seen_before = seen

                for row in rows:
                    record = self._to_record(row, source_page)
                    if record.content_hash in seen_hashes:
                        continue
                    seen_hashes.add(record.content_hash)
                    seen += 1
                    if record.activity_urn:
                        with_urn += 1
                    if record.post_url.startswith("https://"):
                        with_https_url += 1

                    # Normalized model (items + contexts)
                    content_type = self._classify_content_type(row)
                    content_type_counts[content_type] = int(content_type_counts.get(content_type, 0)) + 1
                    item = ItemRecord(
                        canonical_url=record.post_url,
                        url_hash=record.url_hash,
                        item_urn=record.activity_urn,
                        author=record.author,
                        content=record.content,
                        content_type=content_type,
                        published_label=record.published_label,
                    )
                    item_id = self.db.upsert_item_with_connection(db_conn, item)

                    ctx_source = CONTEXT_SOURCE_MAP.get(source_page, "activity_all")
                    if source_page.startswith("saved_"):
                        ctx_kind = "save"
                    else:
                        ctx_kind = record.activity_type or (
                            "reaction"
                            if source_page == "reactions"
                            else "comment"
                            if source_page == "comments"
                            else "post"
                        )
                    ctx_hash = self.db.context_hash_for(
                        source=ctx_source,
                        activity_kind=ctx_kind,
                        item_key=record.activity_urn or record.post_url,
                        content_hash=record.content_hash,
                        activity_label=record.activity_label,
                        published_label=record.published_label,
                        context_text=None,
                    )
                    ctx_new = self.db.insert_item_context_with_connection(
                        db_conn,
                        item_id=item_id,
                        source=ctx_source,
                        activity_kind=ctx_kind,
                        activity_label=record.activity_label,
                        context_text=None,
                        context_hash=ctx_hash,
                        run_id=run_id,
                    )
                    if ctx_new:
                        contexts_inserted += 1

                    # Optional: fetch full content from the permalink when we still see a collapsed "...więcej/see more".
                    needs_hydration = bool(row.get("needs_hydration"))
                    item_key = (record.activity_urn or record.post_url or "").strip()
                    if (
                        needs_hydration
                        and hydration is not None
                        and hydration.remaining > 0
                        and item_key
                        and item_key not in hydration.hydrated_keys
                        and record.post_url.startswith("https://")
                    ):
                        hydration.remaining -= 1
                        hydration.hydrated_keys.add(item_key)
                        permalink_attempted += 1

                        if permalink_page is None:
                            permalink_page = page.context.new_page()
                            permalink_page.set_default_timeout(60_000)

                        try:
                            hydrated_row = self._hydrate_from_permalink(
                                permalink_page,
                                url=record.post_url,
                                activity_urn=record.activity_urn,
                            )
                            hydrated_content = self._clean(
                                hydrated_row.get("content") if hydrated_row else None,
                                limit=50000,
                            )
                            if hydrated_row and hydrated_content:
                                permalink_ok += 1
                                hydrated_item = ItemRecord(
                                    canonical_url=record.post_url,
                                    url_hash=record.url_hash,
                                    item_urn=record.activity_urn,
                                    author=self._clean(hydrated_row.get("author")),
                                    content=hydrated_content,
                                    content_type=self._classify_content_type(hydrated_row),
                                    published_label=self._clean(
                                        hydrated_row.get("published_label"), limit=128
                                    ),
                                )
                                self.db.upsert_item_with_connection(db_conn, hydrated_item)

                                base_len = len(record.content or "")
                                if len(hydrated_content) > base_len + 10:
                                    permalink_upgraded += 1
                            else:
                                permalink_failed += 1
                        except AuthRequiredError:
                            raise
                        except Exception:
                            permalink_failed += 1
                        finally:
                            # Be conservative with extra navigations.
                            try:
                                permalink_page.wait_for_timeout(random.randint(250, 650))
                            except Exception:
                                pass

                    if write_legacy_posts:
                        if self.db.insert_post_with_connection(db_conn, record, run_id=run_id):
                            inserted += 1
                            known_streak = 0
                        else:
                            known_streak += 1
                    else:
                        # Saved: treat "newness" as a new save-context, not a new legacy post row.
                        if ctx_new:
                            known_streak = 0
                        else:
                            known_streak += 1

                round_seen_new = seen - seen_before
                if round_seen_new == 0:
                    stagnation += 1
                else:
                    stagnation = 0

                if progress_cb:
                    progress_cb(
                        {
                            "message": f"{source_page}: scroll {scroll_idx + 1}/{max_scrolls}",
                            "source_page": source_page,
                            "scroll": scroll_idx + 1,
                            "seen": seen,
                            "inserted": inserted,
                            "contexts_inserted": contexts_inserted,
                            "with_urn": with_urn,
                            "with_https_url": with_https_url,
                            "see_more_clicks": see_more_clicks,
                            "content_type_counts": content_type_counts,
                            "mode": mode,
                        }
                    )

                if mode == "update" and known_streak >= self.settings.known_streak_limit:
                    stop_reason = "known_streak"
                    break

                if stagnation >= self.settings.stagnation_limit:
                    stop_reason = "stagnation"
                    break

                before_count = len(rows)
                self._scroll_once(page)
                self._wait_after_scroll(page, before_count)
        finally:
            if permalink_page is not None:
                try:
                    permalink_page.close()
                except Exception:
                    pass
            self.db.close_session(db_conn)

        return SectionResult(
            source_page=source_page,
            status="ok",
            seen=seen,
            inserted=inserted,
            contexts_inserted=contexts_inserted,
            with_urn=with_urn,
            with_https_url=with_https_url,
            see_more_clicks=see_more_clicks,
            permalink_hydrate_attempted=permalink_attempted,
            permalink_hydrate_ok=permalink_ok,
            permalink_hydrate_upgraded=permalink_upgraded,
            permalink_hydrate_failed=permalink_failed,
            content_type_counts=content_type_counts,
            scrolls=scrolls,
            stop_reason=stop_reason,
            error_message=None,
        )

    def _hydrate_from_permalink(self, page: Page, *, url: str, activity_urn: str | None) -> dict | None:
        """Open the permalink and extract a (hopefully) full content card. Best-effort and safe."""
        last_exc: Exception | None = None
        for attempt in range(2):
            try:
                page.goto(
                    url,
                    wait_until="domcontentloaded",
                    timeout=int(self.settings.permalink_goto_timeout_ms),
                )
                last_exc = None
                break
            except PlaywrightTimeoutError as exc:
                last_exc = exc
                page.wait_for_timeout(900 + attempt * 700)
        if last_exc is not None:
            raise last_exc

        self._assert_logged_in(page)

        if not self._wait_for_content(page, timeout_ms=25000):
            page.reload(wait_until="domcontentloaded")
            self._assert_logged_in(page)
            if not self._wait_for_content(page, timeout_ms=25000):
                return None

        clicked = self._expand_see_more(page)
        if clicked:
            page.wait_for_timeout(220)

        rows = self._extract_rows(page)
        if not rows:
            return None

        wanted_urn = (activity_urn or "").strip()
        if wanted_urn:
            for row in rows:
                if str(row.get("activity_urn") or "").strip() == wanted_urn:
                    return row

        normalized = self._normalize_url(url)
        for row in rows:
            row_url = self._normalize_url(str(row.get("post_url") or ""))
            if row_url and row_url == normalized:
                return row

        return rows[0]

    def _select_saved_tab(self, page: Page, tab: str) -> None:
        """Saved items page has tabs; click the one we want. Best-effort, language-agnostic."""
        wanted = {
            "all": ["Wszystkie", "All"],
            "articles": ["Artykuły", "Articles"],
        }.get(tab, [])
        if not wanted:
            return

        try:
            clicked = page.evaluate(
                """
                (labels) => {
                  const norm = (s) => (s || '').replace(/\\s+/g, ' ').trim().toLowerCase();
                  const wanted = new Set(labels.map((l) => norm(l)));
                  const buttons = Array.from(document.querySelectorAll('button'));
                  for (const b of buttons) {
                    const t = norm(b.textContent || '');
                    if (!t) continue;
                    if (wanted.has(t)) {
                      try { b.click(); return true; } catch (e) { return false; }
                    }
                  }
                  return false;
                }
                """,
                wanted,
            )
            if clicked:
                page.wait_for_timeout(700)
        except Exception:
            return

    def _assert_logged_in(self, page: Page) -> None:
        login_detected = page.evaluate(
            """
            () => {
              const onLoginUrl = window.location.href.includes('/login') || window.location.href.includes('/checkpoint/challenge');
              const loginInput = document.querySelector('input#username, input[name="session_key"], input#password');
              return Boolean(onLoginUrl || loginInput);
            }
            """
        )
        if login_detected:
            raise AuthRequiredError(
                "LinkedIn session is not authenticated. Open scraper profile and log in first."
            )

    def _wait_for_content(self, page: Page, timeout_ms: int) -> bool:
        started = time.monotonic()
        while (time.monotonic() - started) * 1000 < timeout_ms:
            count = self._card_count(page)
            if count > 0:
                return True
            spinner = self._spinner_visible(page)
            if not spinner:
                page.wait_for_timeout(400)
            else:
                page.wait_for_timeout(800)
        return False

    def _scroll_once(self, page: Page) -> None:
        distance = random.randint(700, 1100)
        page.evaluate("(dist) => window.scrollBy(0, dist)", distance)
        delay = random.randint(
            self.settings.scroll_delay_min_ms,
            self.settings.scroll_delay_max_ms,
        )
        page.wait_for_timeout(delay)

    def _wait_after_scroll(self, page: Page, previous_count: int) -> None:
        started = time.monotonic()
        stable_ticks = 0

        while (time.monotonic() - started) * 1000 < self.settings.step_wait_timeout_ms:
            current_count = self._card_count(page)
            if current_count > previous_count:
                return

            if self._spinner_visible(page):
                stable_ticks = 0
                page.wait_for_timeout(450)
                continue

            stable_ticks += 1
            if stable_ticks >= 3:
                return
            page.wait_for_timeout(350)

    def _spinner_visible(self, page: Page) -> bool:
        return bool(
            page.evaluate(
                """
                () => {
                  const selectors = [
                    '.artdeco-loader',
                    '.scaffold-finite-scroll__content--loading',
                    '.ember-view.artdeco-spinner',
                    '[role="status"] .artdeco-spinner'
                  ];
                  return selectors.some((sel) => {
                    return Array.from(document.querySelectorAll(sel)).some((el) => {
                      const style = window.getComputedStyle(el);
                      return style.display !== 'none' && style.visibility !== 'hidden' && el.offsetParent !== null;
                    });
                  });
                }
                """
            )
        )

    def _card_count(self, page: Page) -> int:
        return int(
            page.evaluate(
                """
                () => {
                  const selectors = [
                    'div.feed-shared-update-v2',
                    'article.feed-shared-update-v2',
                    'div.occludable-update',
                    'li.profile-creator-shared-feed-update__container',
                    // Saved items (My items -> saved posts) uses a different template.
                    'div[data-chameleon-result-urn]'
                  ];
                  const nodes = new Set();
                  selectors.forEach((sel) => {
                    document.querySelectorAll(sel).forEach((el) => nodes.add(el));
                  });
                  return nodes.size;
                }
                """
            )
        )

    def _extract_rows(self, page: Page) -> list[dict]:
        rows = page.evaluate(
            """
            () => {
              const selectors = [
                'div.feed-shared-update-v2',
                'article.feed-shared-update-v2',
                'div.occludable-update',
                'li.profile-creator-shared-feed-update__container',
                'div[data-chameleon-result-urn]'
              ];
              const nodes = [];
              const seen = new WeakSet();

              selectors.forEach((sel) => {
                document.querySelectorAll(sel).forEach((el) => {
                  if (!seen.has(el)) {
                    nodes.push(el);
                    seen.add(el);
                  }
                });
              });

              const clean = (value) => (value || '').replace(/\s+/g, ' ').trim();

              const firstText = (root, candidates) => {
                for (const sel of candidates) {
                  const el = root.querySelector(sel);
                  if (el) {
                    const txt = clean(el.textContent || '');
                    if (txt) {
                      return txt;
                    }
                  }
                }
                return '';
              };

              const output = [];
              for (const node of nodes) {
                const extractActivityUrn = (root) => {
                  const urnFromString = (value) => {
                    if (!value) return '';
                    const m = String(value).match(/urn:li:activity:\\d+/);
                    return m ? m[0] : '';
                  };

                  // Prefer canonical permalink anchors if present.
                  const updateLinks = Array.from(root.querySelectorAll('a[href*=\"/feed/update/\"]'));
                  for (const a of updateLinks) {
                    const u = urnFromString(a.href || '');
                    if (u) return u;
                  }

                  // Fallback: any anchor that contains an activity URN.
                  const hrefs = Array.from(root.querySelectorAll('a[href]')).map((a) => a.href || '');
                  for (const href of hrefs) {
                    const u = urnFromString(href);
                    if (u) return u;
                  }

                  const attrs = ['data-activity-urn', 'data-entity-urn', 'data-urn', 'data-id', 'data-entity-id', 'data-chameleon-result-urn'];
                  const sel = attrs.map((a) => `[${a}]`).join(',');
                  const candidates = [root, ...Array.from(root.querySelectorAll(sel))];
                  for (const el of candidates) {
                    for (const a of attrs) {
                      const v = el.getAttribute(a);
                      const u = urnFromString(v);
                      if (u) return u;
                    }
                  }
                  return '';
                };

                const activityUrn = extractActivityUrn(node);

                let postUrl = '';
                if (activityUrn) {
                  postUrl = `https://www.linkedin.com/feed/update/${activityUrn}`;
                } else {
                  const linkEl = node.querySelector('a[href*="/feed/update/"], a[href*="/posts/"], a[href*="/activity/"]');
                  postUrl = linkEl ? (linkEl.href || '') : '';
                }

                const author = firstText(node, [
                  '.update-components-actor__name',
                  '.feed-shared-actor__name',
                  'span[dir="ltr"]',
                  'a.app-aware-link span[aria-hidden="true"]'
                ]);

                const lines = (node.innerText || '').split('\\n').map(clean).filter(Boolean);
                const labelHints = [
                  'udostępni',
                  'shared',
                  'zareagowa',
                  'reacted',
                  'skomentowa',
                  'commented'
                ];

                let activityLabel = '';
                for (const line of lines.slice(0, 8)) {
                  const low = line.toLowerCase();
                  if (labelHints.some((h) => low.includes(h))) {
                    activityLabel = line.slice(0, 128);
                    break;
                  }
                }
                if (!activityLabel) {
                  // Fallback: still store *some* short descriptor from the card header.
                  activityLabel = firstText(node, [
                    '.update-components-actor__description',
                    '.feed-shared-actor__description',
                    '.update-components-actor__description span',
                    '.feed-shared-actor__description span'
                  ]) || '';
                }

                const content = firstText(node, [
                  '.update-components-text',
                  '.feed-shared-inline-show-more-text',
                  '.feed-shared-text',
                  'span.break-words'
                ]) || clean((node.innerText || '').slice(0, 50000));

                // Detect still-collapsed "see more / ... więcej" toggles after best-effort expand.
                const norm = (s) => clean(s).toLowerCase();
                const isBadToggle = (t) => t.includes('komentarz') || t.includes('comment');
                const isSeeMoreToggle = (t) => {
                  if (!t) return false;
                  if (t.includes('see less') || t.includes('mniej')) return false;
                  if (isBadToggle(t)) return false;
                  return (
                    t === '… więcej' ||
                    t === '... więcej' ||
                    t === 'więcej' ||
                    t.includes('pokaż więcej') ||
                    t === 'see more' ||
                    t.includes('see more')
                  );
                };
                let needsHydration = false;
                const toggleCandidates = node.querySelectorAll('button, a, [role=\"button\"]');
                for (const el of toggleCandidates) {
                  const t = norm(el.textContent || '');
                  const aria = norm((el.getAttribute && (el.getAttribute('aria-label') || '')) || '');
                  if (isSeeMoreToggle(t) || isSeeMoreToggle(aria)) { needsHydration = true; break; }
                }

                const hasDocument = Boolean(node.querySelector(
                  '.update-components-document, .feed-shared-document, .feed-shared-document__container, a[href*=\"/document/\"]'
                ));
                const hasVideo = Boolean(node.querySelector(
                  'video, .update-components-video, .feed-shared-video, .feed-shared-external-video, .update-components-external-video'
                ));
                const imageContainers = node.querySelectorAll(
                  '.update-components-image, .feed-shared-image, .feed-shared-image__container, .update-components-image__container, .update-components-image__carousel, .feed-shared-image__gallery'
                );
                let imageCount = 0;
                imageContainers.forEach((c) => {
                  imageCount += c.querySelectorAll('img').length;
                });
                const hasArticle = Boolean(node.querySelector(
                  '.update-components-article, .feed-shared-article, .feed-shared-external-card, .update-components-link-preview, a[href*=\"/pulse/\"]'
                ));

                const publishedLabel = firstText(node, [
                  '.update-components-actor__sub-description',
                  '.feed-shared-actor__sub-description',
                  'span.visually-hidden'
                ]);

                if (!postUrl && !content) {
                  continue;
                }

                output.push({
                  post_url: postUrl,
                  activity_urn: activityUrn,
                  activity_label: activityLabel,
                  author,
                  content,
                  published_label: publishedLabel,
                  needs_hydration: needsHydration,
                  has_video: hasVideo,
                  has_document: hasDocument,
                  image_count: imageCount,
                  has_article: hasArticle
                });
              }

              return output;
            }
            """
        )

        if not rows:
            return []

        return [row for row in rows if isinstance(row, dict)]

    @staticmethod
    def _classify_content_type(row: dict) -> str:
        """
        Best-effort content type classification based on DOM hints extracted in _extract_rows.

        Precedence: document > video > image > article > text > unknown
        """
        try:
            if bool(row.get("has_document")):
                return "document"
            if bool(row.get("has_video")):
                return "video"
            image_count = int(row.get("image_count") or 0)
            if image_count > 0:
                return "image"
            if bool(row.get("has_article")):
                return "article"
            content = str(row.get("content") or "").strip()
            if content:
                return "text"
            return "unknown"
        except Exception:
            return "unknown"

    def _to_record(self, row: dict, source_page: str) -> PostRecord:
        raw_url = str(row.get("post_url") or "").strip()
        normalized_url = self._normalize_url(raw_url)
        author = self._clean(row.get("author"))
        content = self._clean(row.get("content"), limit=50000)
        activity_label = self._clean(row.get("activity_label"), limit=128)
        activity_urn = self._clean(row.get("activity_urn"), limit=255)
        published_label = self._clean(row.get("published_label"), limit=128)

        if not normalized_url:
            synthetic_key = hashlib.sha256(
                f"{source_page}|{author}|{content}".encode("utf-8")
            ).hexdigest()
            normalized_url = f"urn:linkedin-archive:{synthetic_key}"

        activity_type: str | None
        if source_page == "reactions":
            activity_type = "reaction"
        elif source_page == "comments":
            activity_type = "comment"
        else:
            label = (activity_label or "").lower()
            if "zareagowa" in label or "reacted" in label:
                activity_type = "reaction"
            elif "skomentowa" in label or "commented" in label:
                activity_type = "comment"
            elif "udostępni" in label or "shared" in label:
                activity_type = "share"
            else:
                activity_type = "post"

        url_hash = hashlib.sha256(normalized_url.encode("utf-8")).hexdigest()
        content_hash = hashlib.sha256(
            f"{source_page}|{normalized_url}|{author}|{content}".encode("utf-8")
        ).hexdigest()

        return PostRecord(
            post_url=normalized_url,
            url_hash=url_hash,
            author=author,
            content=content,
            source_page=source_page,
            activity_type=activity_type,
            activity_label=activity_label,
            activity_urn=activity_urn,
            published_label=published_label,
            content_hash=content_hash,
        )

    @staticmethod
    def _clean(value: object, limit: int | None = None) -> str | None:
        if value is None:
            return None
        text = str(value).strip()
        if not text:
            return None
        if limit is not None and len(text) > limit:
            return text[:limit]
        return text

    @staticmethod
    def _normalize_url(raw_url: str) -> str:
        if not raw_url:
            return ""

        parsed = urlparse(raw_url)
        if not parsed.scheme or not parsed.netloc:
            return ""

        clean_path = parsed.path.rstrip("/")
        return urlunparse((parsed.scheme, parsed.netloc, clean_path, "", "", ""))

    def _expand_see_more(self, page: Page) -> int:
        """Best-effort: expand collapsed 'see more / ...więcej' blocks in visible cards."""
        try:
            return int(
                page.evaluate(
                    """
                    () => {
                      const text = (el) => (el.textContent || '').replace(/\\s+/g,' ').trim().toLowerCase();
                      let clicked = 0;

                      const cards = document.querySelectorAll('div.feed-shared-update-v2, article.feed-shared-update-v2, div.occludable-update, li.profile-creator-shared-feed-update__container, div[data-chameleon-result-urn]');
                      const max = Math.min(cards.length, 30);

                      const isBad = (t) => t.includes('komentarz') || t.includes('comment');
                      const isSeeMore = (t) => {
                        if (!t) return false;
                        if (t.includes('see less') || t.includes('mniej')) return false;
                        if (isBad(t)) return false;
                        return (
                          t === '… więcej' ||
                          t === '... więcej' ||
                          t === 'więcej' ||
                          t.includes('pokaż więcej') ||
                          t === 'see more' ||
                          t.includes('see more')
                        );
                      };

                      for (let i = 0; i < max; i++) {
                        const root = cards[i];
                        const candidates = root.querySelectorAll('button, a, [role=\"button\"]');
                        for (const el of candidates) {
                          const t = text(el);
                          const aria = (el.getAttribute && (el.getAttribute('aria-label') || '')) ? (el.getAttribute('aria-label') || '').toLowerCase() : '';
                          if (!isSeeMore(t) && !isSeeMore(aria)) continue;
                          try { el.scrollIntoView({block: 'center', inline: 'nearest'}); } catch (e) {}
                          if (el.disabled) continue;
                          if (el.getAttribute && el.getAttribute('aria-disabled') === 'true') continue;
                            try { el.click(); clicked++; } catch (e) {}
                        }
                      }

                      return clicked;
                    }
                    """
                )
            )
        except Exception:
            return 0


class AuthRequiredError(RuntimeError):
    pass
