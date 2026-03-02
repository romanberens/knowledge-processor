# CHATGPT_SURFACE_MAP

## 1. UI Mount Points (PHP SSR)

- App context switch:
  - `web/index.php:30-37` (`$appContext`)
  - `web/index.php:2731-2746` (top bar tab "ChatGPT")
- ChatGPT page mount:
  - `web/index.php:4445` (`chatgpt_module_render_session(...)`)
  - `web/modules/chatgpt/controllers/ChatController.php` (`renderSession`)
  - `web/modules/chatgpt/views/session.php` (SSR structure)
- ChatGPT styles/runtime assets:
  - `web/index.php:1909` (`/modules/chatgpt/assets/css/chatgpt.module.css?v=1`)
  - `web/modules/chatgpt/views/session.php:643` (`/modules/chatgpt/assets/js/chatgpt.module.js`)

## 2. ChatGPT AJAX Surface (module)

- Dispatch hook in core:
  - `web/index.php:74` (`chatgpt_module_handle_ajax_request()`)
- Dispatch entrypoint:
  - `web/modules/chatgpt/http/ajax.php`
- Route map:
  - `web/modules/chatgpt/routes/api.php` (`chatgpt_module_api_routes()`)
- Controller:
  - `web/modules/chatgpt/controllers/ChatApiController.php`
- Orchestration stack:
  - `web/modules/chatgpt/services/ChatOrchestrator.php`
  - `web/modules/chatgpt/services/SessionManager.php`
  - `web/modules/chatgpt/providers/GatewayProvider.php`

Supported actions:
- `chatgpt_auth`
- `chatgpt_exchange_start`
- `chatgpt_exchange_status`
- `chatgpt_telemetry`
- `chatgpt_sync_start`
- `chatgpt_sync_job_status`
- `chatgpt_sync_history`

Legacy non-AJAX fallback:
- `web/index.php` action `chatgpt_send_message` delegates to
  `ChatOrchestrator::startExchange($_POST)` for unified exchange-start semantics.

## 3. ChatGPT Server-side Data Bootstrap in `web/index.php`

- Core callsite:
  - `web/index.php:1087` (`chatgpt_module_build_view_context(...)`)
- Module context service:
  - `web/modules/chatgpt/services/ChatViewContextBuilder.php`
- Strict view DTO bridge:
  - `web/modules/chatgpt/controllers/ChatController.php` (`buildViewModel`)
- Catalog source:
  - `chatgpt_module_catalog()` in `web/modules/chatgpt/module.php`

## 4. ChatGPT UI Components (SSR)

- File:
  - `web/modules/chatgpt/views/session.php`
- Components rendered:
  - left rail (models/projects/groups/history)
  - account/system modal trigger
  - thread list + message panel
  - history modal + sync controls
  - "more" modal

## 5. ChatGPT UI Runtime (JS asset)

- File:
  - `web/modules/chatgpt/assets/js/chatgpt.module.js`
- Main behavior groups:
  - collapse sections and left-rail toggles
  - scroll/autofollow (`nearBottom`, `scheduleStickToBottom`, `revealLatest`)
  - optimistic send + placeholder + polling
  - exchange polling and streamed content updates
  - history sync progress and job status polling
  - telemetry emits (`tool_selected`, `composer_mode_changed`)
  - modal orchestration (ops/history/more)

## 6. PHP -> Gateway Adapter Surface

- File:
  - `web/includes/chatgpt_api.php`
- Adapter categories:
  - status/auth (`chatgpt_status`, `chatgpt_auth_*`)
  - schema/threads/messages (`chatgpt_schema`, `chatgpt_threads_list`, `chatgpt_messages_list`)
  - exchange/sync (`chatgpt_thread_exchange_start`, `chatgpt_exchange_status`, `chatgpt_sync_*`)
  - events (`chatgpt_event_create`, `chatgpt_events_list`)

## 7. Gateway API Surface (FastAPI)

- File:
  - `ai_session_gateway/app/main.py`
- Endpoint groups:
  - health/status/auth
  - schema/threads/messages
  - exchange start/status (async)
  - sync start/status/history
  - events write/read

## 8. Gateway Internal ChatGPT Surface

- File:
  - `ai_session_gateway/app/main.py`
  - `ai_session_gateway/app/exchange.py`
- Core internals:
  - in-memory task registries (`exchange_tasks`, `sync_tasks`)
  - shared browser profile lock (`profile_lock`)
  - Playwright exchange/sync scanners (`exchange_once`, `sync_history_once`, `scan_threads_index_once`)

## 9. ChatGPT Persistence Surface (Postgres)

- Schema/init:
  - `ai_session_gateway/app/db.py`
- Main tables:
  - `integration_threads`
  - `integration_messages`
  - `integration_message_attachments`
  - `integration_events`

## 10. Coupling Points to Core App

- Core keeps routing/shell/data bootstrap and mounts module entry points:
  - `web/index.php:74` (AJAX delegate)
  - `web/index.php:4445` (module session render call)
- Core ChatGPT topbar dependency is reduced to two fields from context:
  - `chatgptGatewayOk`
  - `chatgptAuthState`
- Module view still depends on module-provided context keys (transitional coupling):
  - `web/modules/chatgpt/views/session.php`
- ChatGPT shares topbar/shell with LinkedIn and Editorial:
  - `web/index.php` topbar section
