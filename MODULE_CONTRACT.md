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

## Module HTTP/AJAX Contract (current)

Handled by:
- `web/modules/chatgpt/http/ajax.php`

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
- HTTP code passthrough where available
- `detail` used for error description

## Module View Contract (current)

- `web/modules/chatgpt/views/session.php` expects the same variable scope as previous `web/index.php` inline block, including:
  - auth/session vars (`$chatgptAuthState`, `$chatgptHasLoginSession`, etc.)
  - thread/message vars (`$chatgptThreadId`, `$chatgptThreads`, `$chatgptMessages`)
  - catalog vars (`$chatgptModels`, `$chatgptProjects`, `$chatgptGroups`)
  - gateway/schema vars (`$chatgptGatewayState`, `$chatgptSchema`)

## Pending Contract Cleanup

1. Move ChatGPT CSS to module asset file and load only for ChatGPT route.
2. Move ChatGPT JS runtime to module asset file with namespaced boot function.
3. Replace variable-scope coupling with explicit view context array or controller DTO.
