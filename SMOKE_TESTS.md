# SMOKE_TESTS

## Phase

- `PHASE-2 / EXTRACTION` (steps 1-3)
- Date: 2026-03-02

## Checks Executed

| Check | Command / Method | Result |
| --- | --- | --- |
| Core PHP syntax | `php -l web/index.php` | PASS |
| Module bootstrap syntax | `php -l web/modules/chatgpt/module.php` | PASS |
| Module manifest syntax | `php -l web/modules/chatgpt/manifest.php` | PASS |
| Module AJAX dispatcher syntax | `php -l web/modules/chatgpt/http/ajax.php` | PASS |
| Module API routes syntax | `php -l web/modules/chatgpt/routes/api.php` | PASS |
| Module API controller syntax | `php -l web/modules/chatgpt/controllers/ChatApiController.php` | PASS |
| Module orchestrator syntax | `php -l web/modules/chatgpt/services/ChatOrchestrator.php` | PASS |
| Module session manager syntax | `php -l web/modules/chatgpt/services/SessionManager.php` | PASS |
| Module gateway provider syntax | `php -l web/modules/chatgpt/providers/GatewayProvider.php` | PASS |
| Module session view syntax | `php -l web/modules/chatgpt/views/session.php` | PASS |

## Notes

- This iteration focused on structural extraction with stable behavior contracts.
- Browser-level smoke (send message, stream update, sync jobs, auth modal refresh) was not executed in this step.
