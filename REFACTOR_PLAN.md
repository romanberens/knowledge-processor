# REFACTOR_PLAN

## Phase

- Current: `PHASE-2 / EXTRACTION (steps 1-9 done)`
- Strategy: extraction-first, behavior-preserving (no business logic rewrites)

## Constraint Applied

- Runtime note: PHP container mounts only `./web` (`docker-compose.yml:70-71`),
  therefore module extraction path is currently `web/modules/chatgpt/`.

## Completed in Step 1

1. Module shell created:
   - `web/modules/chatgpt/manifest.php`
   - `web/modules/chatgpt/module.php`
2. Module structure scaffolded:
   - `web/modules/chatgpt/{routes,controllers,services,providers,assets,views,http}/...`
3. ChatGPT AJAX dispatch moved from monolith:
   - source removed from `web/index.php` (old inline block)
   - new location: `web/modules/chatgpt/http/ajax.php`
4. ChatGPT view/runtime block moved from monolith:
   - new location: `web/modules/chatgpt/views/session.php`
   - `web/index.php` now includes module view file.
5. Static model/project/group catalog moved:
   - new location: `chatgpt_module_catalog()` in `web/modules/chatgpt/module.php`

## Completed in Step 2

1. ChatGPT CSS moved from inline `<style>` block to:
   - `web/modules/chatgpt/assets/css/chatgpt.module.css`
2. ChatGPT JS runtime moved from inline `<script>` to:
   - `web/modules/chatgpt/assets/js/chatgpt.module.js`
3. Module assets mounted from core shell:
   - `web/index.php` loads `chatgpt.module.css` only for `view=chatgpt`
   - `web/modules/chatgpt/views/session.php` loads `chatgpt.module.js`

## Completed in Step 3

1. Module API routing contract introduced:
   - `web/modules/chatgpt/routes/api.php` (`chatgpt_module_api_routes()`)
2. AJAX dispatcher reduced to module route dispatch:
   - `web/modules/chatgpt/http/ajax.php`
3. Controller/service/provider wrapper flow introduced:
   - `controllers/ChatApiController.php`
   - `services/ChatOrchestrator.php`
   - `services/SessionManager.php`
   - `providers/GatewayProvider.php`
4. Module bootstrap now wires internal layers explicitly:
   - `web/modules/chatgpt/module.php`

## Completed in Step 4

1. ChatGPT page data bootstrap extracted from core:
   - new service: `web/modules/chatgpt/services/ChatViewContextBuilder.php`
2. Core now calls module context entrypoint instead of inline bootstrap logic:
   - `chatgpt_module_build_view_context(...)` in `web/modules/chatgpt/module.php`
   - usage in `web/index.php`

## Completed in Step 5

1. Module SSR render wrapper introduced:
   - `chatgpt_module_render_session(...)` in `web/modules/chatgpt/module.php`
   - controller mount: `controllers/ChatController.php`
2. Core now mounts ChatGPT session via module function instead of direct `require`:
   - `web/index.php`
3. Module web route map introduced:
   - `web/modules/chatgpt/routes/web.php` (`chatgpt_module_web_routes()`)

## Completed in Step 6

1. Core shell now keeps only minimal ChatGPT context fields for topbar:
   - `chatgptGatewayOk`
   - `chatgptAuthState`
2. Strict DTO whitelist introduced for module view rendering:
   - `web/modules/chatgpt/controllers/ChatController.php` (`buildViewModel`)
3. Session view receives only whitelisted keys from controller bridge (reduced implicit coupling).

## Completed in Step 7

1. HTTP smoke/regression checks executed against local runtime (`localhost:8080`):
   - `GET /?view=chatgpt&tab=session` -> `HTTP 200`
   - `GET /?view=chatgpt&tab=session&ajax=chatgpt_auth` -> `HTTP 200`, `AUTH_OK`
   - `POST /?view=chatgpt&tab=session&ajax=chatgpt_exchange_start` with empty prompt -> `HTTP 400`, `EMPTY_PROMPT`
   - `POST /?view=chatgpt&tab=session&ajax=chatgpt_sync_start` with invalid kind -> `HTTP 400`, `SYNC_KIND_REQUIRED`
   - `GET /?view=chatgpt&tab=session&ajax=chatgpt_sync_job_status` without id -> `HTTP 400`, `JOB_ID_REQUIRED`
2. Status view endpoint verified:
   - `GET /?view=chatgpt&tab=status` -> renders AUTH/Gateway cards and module JS include.

## Completed in Step 8

1. Legacy form submit path (`action=chatgpt_send_message`) was unified with async module orchestration:
   - now delegates to `ChatOrchestrator::startExchange($_POST)` instead of using separate synchronous exchange logic.
2. This removes dual message-exchange logic between:
   - legacy form POST in `web/index.php`
   - AJAX `chatgpt_exchange_start` route in module API.
3. Redirect/flash behavior preserved for non-JS fallback.

## Completed in Step 9

1. Module context payload was minimized in `ChatViewContextBuilder`:
   - removed redundant context keys not used by topbar/view DTO bridge.
2. Contract now stays focused on rendering needs:
   - topbar status fields + session/status template data.

## Old -> New Mapping

| Old location | New location | Status |
| --- | --- | --- |
| `web/index.php` (`chatgpt_*` AJAX handlers) | `web/modules/chatgpt/http/ajax.php` + `controllers/ChatApiController.php` | migrated |
| `web/index.php` (`if ($view === 'chatgpt')` large SSR + JS block) | `web/modules/chatgpt/views/session.php` + `assets/js/chatgpt.module.js` | migrated |
| `web/index.php` inline ChatGPT CSS | `web/modules/chatgpt/assets/css/chatgpt.module.css` | migrated |
| `web/index.php` (`$chatgptModels/$chatgptProjects/$chatgptGroups`) | `web/modules/chatgpt/module.php` (`chatgpt_module_catalog`) | migrated |
| `web/index.php` (module bootstrapping absent) | `require_once web/modules/chatgpt/module.php` + `chatgpt_module_handle_ajax_request()` | migrated |

## Pending (next)

1. Run interactive browser smoke validation (VNC/UI) for send/poll/sync flows.
2. Prepare PHASE-3 integration pass and marker cleanup.
3. Optionally remove legacy form POST path fully after manual UX confirmation.

## Validation Checklist

- `php -l web/index.php` pass
- `php -l web/modules/chatgpt/module.php` pass
- `php -l web/modules/chatgpt/http/ajax.php` pass
- `php -l web/modules/chatgpt/routes/api.php` pass
- `php -l web/modules/chatgpt/controllers/ChatApiController.php` pass
- `php -l web/modules/chatgpt/services/ChatOrchestrator.php` pass
- `php -l web/modules/chatgpt/services/SessionManager.php` pass
- `php -l web/modules/chatgpt/providers/GatewayProvider.php` pass
- `php -l web/modules/chatgpt/services/ChatViewContextBuilder.php` pass
- `php -l web/modules/chatgpt/controllers/ChatController.php` pass
- `php -l web/modules/chatgpt/routes/web.php` pass
- `php -l web/modules/chatgpt/views/session.php` pass
