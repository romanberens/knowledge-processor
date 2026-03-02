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


def _env_str(name: str, default: str = "") -> str:
    raw = os.getenv(name)
    if raw is None:
        return default
    return raw.strip()


@dataclass(frozen=True)
class Settings:
    db_host: str
    db_port: int
    db_name: str
    db_user: str
    db_password: str

    session_host: str
    session_port: int
    status_cache_ttl_seconds: int

    profile_dir: str
    target_name: str
    login_url: str
    auth_check_url: str
    headless_auth_check: bool
    auth_check_timeout_ms: int
    headless_exchange: bool
    exchange_timeout_ms: int
    exchange_response_stable_ms: int

    novnc_public_url: str
    xvfb_display: str


def get_settings() -> Settings:
    return Settings(
        db_host=_env_str("DB_HOST", "ai_session_db"),
        db_port=_env_int("DB_PORT", 5432),
        db_name=_env_str("DB_NAME", "ai_session"),
        db_user=_env_str("DB_USER", "ai_session"),
        db_password=_env_str("DB_PASSWORD", ""),
        session_host=_env_str("SESSION_HOST", "0.0.0.0"),
        session_port=_env_int("SESSION_PORT", 8190),
        status_cache_ttl_seconds=max(1, _env_int("STATUS_CACHE_TTL_SECONDS", 30)),
        profile_dir=_env_str("PROFILE_DIR", "/profile"),
        target_name=_env_str("SESSION_TARGET_NAME", "generic-ai-ui"),
        login_url=_env_str("SESSION_LOGIN_URL", ""),
        auth_check_url=_env_str("SESSION_AUTH_CHECK_URL", ""),
        headless_auth_check=_env_bool("SESSION_HEADLESS_AUTH_CHECK", True),
        auth_check_timeout_ms=max(5_000, _env_int("SESSION_AUTH_CHECK_TIMEOUT_MS", 20_000)),
        headless_exchange=_env_bool("SESSION_HEADLESS_EXCHANGE", False),
        exchange_timeout_ms=max(15_000, _env_int("SESSION_EXCHANGE_TIMEOUT_MS", 120_000)),
        exchange_response_stable_ms=max(800, _env_int("SESSION_EXCHANGE_RESPONSE_STABLE_MS", 2_000)),
        novnc_public_url=_env_str(
            "NOVNC_PUBLIC_URL",
            "http://127.0.0.1:7790/vnc.html?autoconnect=1&resize=remote&reconnect=1",
        ),
        xvfb_display=_env_str("XVFB_DISPLAY", ":99"),
    )
