# MODULE_CONTRACT (ChatGPT)

## Module Root

- Runtime path: `web/modules/chatgpt/`
- Bootstrap:
  - `web/modules/chatgpt/module.php`
  - `web/modules/chatgpt/manifest.php`

## Module Integration Points in Core

1. Bootstrap include in core:
   - `web/index.php` -> `require_once __DIR__ . '/modules/chatgpt/module.php';`
2. AJAX delegation:
   - `web/index.php` -> `chatgpt_module_handle_ajax_request();`
3. Session UI mounting:
   - `web/index.php` -> `require __DIR__ . '/modules/chatgpt/views/session.php';`
4. Catalog data source:
   - `chatgpt_module_catalog()` for models/projects/groups.
5. Route-scoped stylesheet:
   - `web/index.php` -> `chatgpt.module.css` when `view=chatgpt`.
6. View context bootstrap:
   - `chatgpt_module_build_view_context(...)` in `web/modules/chatgpt/module.php`
   - powered by `services/ChatViewContextBuilder.php`

## Module HTTP/AJAX Contract (current)

Routing stack:
- `web/modules/chatgpt/http/ajax.php` (dispatcher)
- `web/modules/chatgpt/routes/api.php` (route map)
- `web/modules/chatgpt/controllers/ChatApiController.php` (transport controller)
- `web/modules/chatgpt/services/ChatOrchestrator.php` (business orchestration)
- `web/modules/chatgpt/services/SessionManager.php` (payload/session normalization)
- `web/modules/chatgpt/providers/GatewayProvider.php` (gateway adapter)

Supported actions:

- `ajax=chatgpt_auth`
- `ajax=chatgpt_exchange_start`
- `ajax=chatgpt_exchange_status`
- `ajax=chatgpt_telemetry`
- `ajax=chatgpt_sync_start`
- `ajax=chatgpt_sync_job_status`
- `ajax=chatgpt_sync_history`

Response style:

- JSON payloads with `ok: true|false`
- HTTP codes aligned to orchestration outcome
- `detail` used for error description

## Module View Contract (current)

- `web/modules/chatgpt/views/session.php` expects the same variable scope as previous `web/index.php` inline block, including:
  - auth/session vars (`$chatgptAuthState`, `$chatgptHasLoginSession`, etc.)
  - thread/message vars (`$chatgptThreadId`, `$chatgptThreads`, `$chatgptMessages`)
  - catalog vars (`$chatgptModels`, `$chatgptProjects`, `$chatgptGroups`)
  - gateway/schema vars (`$chatgptGatewayState`, `$chatgptSchema`)
- Runtime JS asset:
  - `web/modules/chatgpt/assets/js/chatgpt.module.js`

## Pending Contract Cleanup

1. Replace variable-scope coupling with explicit view context array or module controller DTO.
2. Define web route contract in `routes/web.php` and mount ChatGPT SSR via module controller.
3. Stabilize internal module contract for sync/exchange task lifecycle (to support next persistence refactor).
