# REFACTOR_PLAN

## Phase

- Current: `PHASE-2 / EXTRACTION (step 1 - shell + safe moves)`
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

## Old -> New Mapping

| Old location | New location | Status |
| --- | --- | --- |
| `web/index.php` (`chatgpt_*` AJAX handlers) | `web/modules/chatgpt/http/ajax.php` | migrated |
| `web/index.php` (`if ($view === 'chatgpt')` large SSR + JS block) | `web/modules/chatgpt/views/session.php` | migrated |
| `web/index.php` (`$chatgptModels/$chatgptProjects/$chatgptGroups`) | `web/modules/chatgpt/module.php` (`chatgpt_module_catalog`) | migrated |
| `web/index.php` (module bootstrapping absent) | `require_once web/modules/chatgpt/module.php` + `chatgpt_module_handle_ajax_request()` | migrated |

## Pending Step 2 (next)

1. Move ChatGPT CSS from inline `<style>` block into:
   - `web/modules/chatgpt/assets/css/chatgpt.module.css`
2. Move ChatGPT JS runtime from `views/session.php` into:
   - `web/modules/chatgpt/assets/js/chatgpt.module.js`
3. Introduce module route/controller/service wrappers and migrate glue logic:
   - `controllers/ChatController.php`
   - `controllers/ChatApiController.php`
   - `services/ChatOrchestrator.php`

## Validation Checklist for this step

- `php -l web/index.php` pass
- `php -l web/modules/chatgpt/module.php` pass
- `php -l web/modules/chatgpt/http/ajax.php` pass
- `php -l web/modules/chatgpt/views/session.php` pass
