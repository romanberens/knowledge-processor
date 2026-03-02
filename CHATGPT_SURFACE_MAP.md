# CHATGPT_SURFACE_MAP

## 1. UI Mount Points (PHP SSR)

- App context switch:
  - `web/index.php:30-37` (`$appContext`)
  - `web/index.php:3445-3460` (top bar tab "ChatGPT")
- ChatGPT page mount:
  - `web/index.php:5638` (module view include)
  - `web/modules/chatgpt/views/session.php` (SSR structure)
- ChatGPT runtime JS:
  - `web/modules/chatgpt/views/session.php:669-1912`

## 2. ChatGPT AJAX Endpoints

- Dispatch hook in core:
  - `web/index.php:74` (`chatgpt_module_handle_ajax_request()`)
- Module handler file:
  - `web/modules/chatgpt/http/ajax.php`
- Endpoint branches:
  - `chatgpt_auth` -> `web/modules/chatgpt/http/ajax.php:7`
  - `chatgpt_exchange_start` -> `web/modules/chatgpt/http/ajax.php:15`
  - `chatgpt_exchange_status` -> `web/modules/chatgpt/http/ajax.php:145`
  - `chatgpt_telemetry` -> `web/modules/chatgpt/http/ajax.php:177`
  - `chatgpt_sync_start` -> `web/modules/chatgpt/http/ajax.php:228`
  - `chatgpt_sync_job_status` -> `web/modules/chatgpt/http/ajax.php:286`
  - `chatgpt_sync_history` -> `web/modules/chatgpt/http/ajax.php:318`

## 3. ChatGPT Server-side Data Bootstrap in `web/index.php`

- Gateway/auth/thread selection:
  - `web/index.php:1084-1150`
- Thread message preload:
  - `web/index.php:1153-1164`
- Catalog source:
  - `chatgpt_module_catalog()` -> `web/index.php:1102`
  - implementation in `web/modules/chatgpt/module.php`

## 4. ChatGPT UI Components (SSR, module file)

- Left rail:
  - models/projects/groups/history toggles -> `web/modules/chatgpt/views/session.php`
- Account/system modal trigger:
  - `web/modules/chatgpt/views/session.php`
- Main stage:
  - thread panel + message list -> `web/modules/chatgpt/views/session.php`
- History modal + sync controls:
  - `web/modules/chatgpt/views/session.php`
- "More features" modal:
  - `web/modules/chatgpt/views/session.php`

## 5. ChatGPT UI Runtime Functions (JS, module file)

- Collapse sections:
  - `web/modules/chatgpt/views/session.php`
- Scroll/autofollow:
  - `nearBottom`, `scheduleStickToBottom`, `revealLatest`
  - `web/modules/chatgpt/views/session.php`
- Message render/update:
  - builders and update path:
    - `web/modules/chatgpt/views/session.php`
- Sync status polling UX:
  - `web/modules/chatgpt/views/session.php:1287-1464`
- Exchange polling:
  - `web/modules/chatgpt/views/session.php:1496-1565`
- Optimistic send + form reset + placeholder:
  - `web/modules/chatgpt/views/session.php:1587-1655`
- Mode map and telemetry events:
  - `toolModeMap` and `emitTelemetry`
  - `web/modules/chatgpt/views/session.php:1672-1719`
  - telemetry emit calls:
    - `tool_selected` / `composer_mode_changed`
    - `web/modules/chatgpt/views/session.php:1779-1791`

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

- Core keeps routing/shell/data bootstrap and mounts module entry points:
  - `web/index.php:74` (AJAX delegate)
  - `web/index.php:5638` (view include)
- Module view still depends on parent variable scope (transitional coupling):
  - `web/modules/chatgpt/views/session.php`
- ChatGPT tab shares topbar and app shell logic with LinkedIn and Editorial:
  - `web/index.php` topbar section
