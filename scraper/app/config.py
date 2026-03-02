from __future__ import annotations

import os
from dataclasses import dataclass


def _env_bool(name: str, default: bool) -> bool:
    raw = os.getenv(name)
    if raw is None:
        return default
    return raw.strip().lower() in {"1", "true", "yes", "on"}


def _env_int(name: str, default: int) -> int:
    raw = os.getenv(name)
    if raw is None:
        return default
    try:
        return int(raw)
    except ValueError:
        return default


@dataclass(frozen=True)
class Settings:
    db_host: str
    db_port: int
    db_name: str
    db_user: str
    db_password: str
    profile_dir: str
    linkedin_profile_slug: str
    scraper_host: str
    scraper_port: int
    headless: bool
    deep_scroll_limit: int
    update_scroll_limit: int
    stagnation_limit: int
    known_streak_limit: int
    scroll_delay_min_ms: int
    scroll_delay_max_ms: int
    step_wait_timeout_ms: int
    permalink_hydrate_limit_deep: int
    permalink_hydrate_limit_update: int
    permalink_goto_timeout_ms: int


def get_settings() -> Settings:
    return Settings(
        db_host=os.getenv("DB_HOST", "db"),
        db_port=_env_int("DB_PORT", 3306),
        db_name=os.getenv("DB_NAME", "linkedin_archive"),
        db_user=os.getenv("DB_USER", "li_user"),
        db_password=os.getenv("DB_PASSWORD", ""),
        profile_dir=os.getenv("PROFILE_DIR", "/profile"),
        linkedin_profile_slug=os.getenv("LINKEDIN_PROFILE_SLUG", "wirtualnaredakcja-pl"),
        scraper_host=os.getenv("SCRAPER_HOST", "0.0.0.0"),
        scraper_port=_env_int("SCRAPER_PORT", 8090),
        headless=_env_bool("HEADLESS", True),
        deep_scroll_limit=_env_int("DEEP_SCROLL_LIMIT", 700),
        update_scroll_limit=_env_int("UPDATE_SCROLL_LIMIT", 15),
        stagnation_limit=_env_int("STAGNATION_LIMIT", 6),
        known_streak_limit=_env_int("KNOWN_STREAK_LIMIT", 25),
        scroll_delay_min_ms=_env_int("SCROLL_DELAY_MIN_MS", 1200),
        scroll_delay_max_ms=_env_int("SCROLL_DELAY_MAX_MS", 2500),
        step_wait_timeout_ms=_env_int("STEP_WAIT_TIMEOUT_MS", 10000),
        permalink_hydrate_limit_deep=_env_int("PERMALINK_HYDRATE_LIMIT_DEEP", 0),
        permalink_hydrate_limit_update=_env_int("PERMALINK_HYDRATE_LIMIT_UPDATE", 8),
        permalink_goto_timeout_ms=_env_int("PERMALINK_GOTO_TIMEOUT_MS", 45000),
    )
