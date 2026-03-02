# CHATGPT_SURFACE_MAP

## 1. UI Mount Points (PHP SSR)

- App context switch:
  - `web/index.php:30-37` (`$appContext`)
  - `web/index.php:3445-3460` (top bar tab "ChatGPT")
- ChatGPT page mount:
  - `web/index.php:6002-6612` (SSR structure)
- ChatGPT runtime JS:
  - `web/index.php:6644-7914`

## 2. ChatGPT AJAX Endpoints in `web/index.php`

- Auth poll:
  - `chatgpt_auth` -> `web/index.php:71-77`
- Exchange:
  - start -> `web/index.php:79-207`
  - status -> `web/index.php:209-239`
- Telemetry:
  - `chatgpt_telemetry` -> `web/index.php:241-290`
- Sync jobs:
  - start -> `web/index.php:292-348`
  - status -> `web/index.php:350-380`
  - single-thread sync history -> `web/index.php:382-420`

## 3. ChatGPT Server-side Data Bootstrap in `web/index.php`

- Gateway/auth/thread selection:
  - `web/index.php:1440-1509`
- Thread message preload:
  - `web/index.php:1511-1522`

## 4. ChatGPT UI Components (SSR)

- Left rail:
  - models/projects/groups/history toggles -> `web/index.php:6051-6130`
- Account/system modal trigger:
  - `web/index.php:6132-6147`
- Main stage:
  - thread panel + message list -> `web/index.php:6177-6539`
- History modal + sync controls:
  - `web/index.php:6565-6612`
- "More features" modal:
  - `web/index.php:6613-6642`

## 5. ChatGPT UI Runtime Functions (JS)

- Collapse sections:
  - `web/index.php:6649-6666`
- Scroll/autofollow:
  - `nearBottom`, `scheduleStickToBottom`, `revealLatest`
  - `web/index.php:6789-6836`
- Message render/update:
  - builders and update path:
    - `web/index.php:6916-7057`
    - `web/index.php:7058-7157`
- Sync status polling UX:
  - `web/index.php:7288-7428`
- Exchange polling:
  - `web/index.php:7497-7566`
- Optimistic send + form reset + placeholder:
  - `web/index.php:7587-7655`
- Mode map and telemetry events:
  - `toolModeMap` and `emitTelemetry`
  - `web/index.php:7673-7719`
  - telemetry emit calls:
    - `tool_selected` / `composer_mode_changed`
    - `web/index.php:7780-7791`

## 6. PHP -> Gateway Adapter Surface (`web/includes/chatgpt_api.php`)

- Base request wrapper:
  - `chatgpt_request` -> `web/includes/chatgpt_api.php:12-63`
- Auth/session:
  - `chatgpt_status`, `chatgpt_auth_status`, `chatgpt_auth_login_*`
  - `web/includes/chatgpt_api.php:65-119`
- Data model endpoints:
  - schema/threads/messages/events
  - `web/includes/chatgpt_api.php:121-255`
- Exchange/sync endpoints:
  - exchange start/status/history sync/sync jobs
  - `web/includes/chatgpt_api.php:185-241`

## 7. Gateway API Surface (`ai_session_gateway/app/main.py`)

- Health/status/auth:
  - `/health`, `/status`, `/auth/*`
  - `ai_session_gateway/app/main.py:128-208`
- Data entities:
  - `/v1/schema`, `/v1/threads`, `/v1/threads/{id}/messages`
  - `ai_session_gateway/app/main.py:211-358`
- Exchange async:
  - `/v1/threads/{id}/exchange/start` + `/v1/exchanges/{exchange_id}`
  - `ai_session_gateway/app/main.py:947-1085`
- Sync async:
  - `/v1/sync/*/start` + `/v1/sync/jobs/{job_id}`
  - `ai_session_gateway/app/main.py:1380-1430`
- Sync single thread:
  - `/v1/threads/{id}/sync_history`
  - `ai_session_gateway/app/main.py:1433-1484`
- Legacy synchronous exchange endpoint still present:
  - `/v1/threads/{id}/exchange`
  - `ai_session_gateway/app/main.py:1487-1662`
- Event write/read:
  - `/v1/events`
  - `ai_session_gateway/app/main.py:1665-1690`

## 8. Gateway Internal ChatGPT Surface

- In-memory task registries:
  - `exchange_tasks`, `sync_tasks`
  - `ai_session_gateway/app/main.py:42-45`
- Shared browser profile lock:
  - `profile_lock`
  - `ai_session_gateway/app/main.py:37`
- Playwright exchange and history adapters:
  - `exchange_once` -> `ai_session_gateway/app/exchange.py:768-860`
  - `sync_history_once` -> `ai_session_gateway/app/exchange.py:697-765`
  - `scan_threads_index_once` -> `ai_session_gateway/app/exchange.py:543-692`

## 9. ChatGPT Persistence Surface (Postgres)

- Schema creation:
  - `auth_sessions`, `auth_events`
  - `integration_threads`, `integration_messages`
  - `integration_message_attachments`, `integration_events`
  - `ai_session_gateway/app/db.py:90-240`
- Payload contracts:
  - Pydantic models for threads/messages/exchange/sync/events
  - `ai_session_gateway/app/contracts.py:19-87`

## 10. Coupling Points to Core App

- ChatGPT and non-ChatGPT views are coupled in a single monolithic file:
  - `web/index.php` (routing + rendering + handlers + JS runtime)
- ChatGPT tab shares topbar and app shell logic with LinkedIn and Editorial:
  - `web/index.php:3445-3484`
