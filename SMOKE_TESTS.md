# SMOKE_TESTS

## Phase

- `PHASE-2 / EXTRACTION` (step 1)
- Date: 2026-03-02

## Checks Executed

| Check | Command / Method | Result |
| --- | --- | --- |
| Core PHP syntax | `php -l web/index.php` | PASS |
| Module bootstrap syntax | `php -l web/modules/chatgpt/module.php` | PASS |
| Module manifest syntax | `php -l web/modules/chatgpt/manifest.php` | PASS |
| Module AJAX syntax | `php -l web/modules/chatgpt/http/ajax.php` | PASS |
| Module view syntax | `php -l web/modules/chatgpt/views/session.php` | PASS |

## Notes

- This iteration focused on structural extraction without behavior changes.
- Runtime browser smoke (send message, sync history, auth modal) was not executed in this step.
