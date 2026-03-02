# PROJECT_MAP

## Scope

- Repository root: `/home/roman/linkedin_profleTools`
- Main domains:
  - LinkedIn archive + scraper (`web`, `scraper`, `db/init`)
  - ChatGPT overlay (`web` + `ai_session_gateway`)
  - Editorial panel (`web` + MySQL schema extensions)

## Directory Map (max 3 levels)

```text
.
├── web/
│   ├── index.php
│   ├── Dockerfile
│   ├── includes/
│       ├── bootstrap.php
│       ├── db.php
│       ├── repository.php
│       ├── scraper_api.php
│       ├── chatgpt_api.php
│       └── strapi_api.php
│   └── modules/
│       └── chatgpt/
│           ├── module.php
│           ├── manifest.php
│           ├── http/ajax.php
│           ├── views/session.php
│           └── assets/
├── ai_session_gateway/
│   ├── Dockerfile
│   ├── requirements.txt
│   └── app/
│       ├── main.py
│       ├── exchange.py
│       ├── db.py
│       ├── auth.py
│       ├── contracts.py
│       └── config.py
├── scraper/
│   ├── Dockerfile
│   ├── requirements.txt
│   └── app/
│       ├── main.py
│       ├── linkedin_activity.py
│       ├── db.py
│       ├── auth.py
│       └── config.py
├── db/
│   └── init/
│       ├── 001_schema.sql
│       ├── 002_editorial.sql
│       └── 003_cms_integrations.sql
├── docker-compose.yml
├── docker-compose.ai-session.yml
├── README.md
├── CODEX_AGENT_PLAYBOOK.md
└── REFACTOR_PROGRESS.md
```

## Entrypoints

- Web panel entrypoint:
  - `web/index.php:1-8` (global includes)
  - `web/index.php:10-61` (view/tab routing)
- Web DB bootstrap:
  - `web/includes/db.php:7-30`
- ChatGPT gateway entrypoint:
  - `ai_session_gateway/app/main.py:35-45` (global app state)
  - `ai_session_gateway/app/main.py:120-126` (startup schema/recovery)
- LinkedIn scraper API entrypoint:
  - `scraper/app/main.py:76-83`
  - `scraper/app/main.py:97-103` (startup recovery)

## Ownership Map

- `web/index.php`
  - SSR layout + top nav + all page views
  - AJAX dispatch for scraper + generic app actions
  - mounts ChatGPT module entry points
- `web/modules/chatgpt/`
  - extracted ChatGPT module shell + AJAX dispatcher + session view/runtime
- `web/includes/chatgpt_api.php`
  - HTTP adapter/proxy from PHP to `ai_session_gateway`
  - One function per gateway endpoint surface
- `ai_session_gateway/app/main.py`
  - ChatGPT domain API (threads/messages/events/exchange/sync)
  - In-memory task registries for exchange/sync
  - Threaded workers with shared `profile_lock`
- `ai_session_gateway/app/exchange.py`
  - Playwright UI automation for:
    - message exchange,
    - thread index scan,
    - history pull with attachments extraction
- `ai_session_gateway/app/db.py`
  - Postgres schema and persistence primitives for ChatGPT domain
- `scraper/app/main.py`
  - LinkedIn scrape/hydrate jobs and auth-gated execution
- `db/init/*.sql`
  - MySQL schema for archive/editorial/CMS config

## Runtime-only Directories (not source modules)

- `profile/` and `ai_session_profile/`
  - Persistent Chromium profile/session data
  - Runtime state, not application source logic
