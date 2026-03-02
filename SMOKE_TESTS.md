# SMOKE_TESTS

## Phase

- `PHASE-2 / EXTRACTION` (steps 1-7)
- Date: 2026-03-02

## Checks Executed

| Check | Command / Method | Result |
| --- | --- | --- |
| Core PHP syntax | `php -l web/index.php` | PASS |
| Module bootstrap syntax | `php -l web/modules/chatgpt/module.php` | PASS |
| Module manifest syntax | `php -l web/modules/chatgpt/manifest.php` | PASS |
| Module AJAX dispatcher syntax | `php -l web/modules/chatgpt/http/ajax.php` | PASS |
| Module API routes syntax | `php -l web/modules/chatgpt/routes/api.php` | PASS |
| Module web routes syntax | `php -l web/modules/chatgpt/routes/web.php` | PASS |
| Module API controller syntax | `php -l web/modules/chatgpt/controllers/ChatApiController.php` | PASS |
| Module web controller syntax | `php -l web/modules/chatgpt/controllers/ChatController.php` | PASS |
| Module orchestrator syntax | `php -l web/modules/chatgpt/services/ChatOrchestrator.php` | PASS |
| Module session manager syntax | `php -l web/modules/chatgpt/services/SessionManager.php` | PASS |
| Module gateway provider syntax | `php -l web/modules/chatgpt/providers/GatewayProvider.php` | PASS |
| Module view-context builder syntax | `php -l web/modules/chatgpt/services/ChatViewContextBuilder.php` | PASS |
| Module session view syntax | `php -l web/modules/chatgpt/views/session.php` | PASS |

## Notes

- This iteration focused on structural extraction with stable behavior contracts.
- Interactive browser-level smoke (send message, stream update, sync jobs, auth modal refresh) is pending.

## Runtime HTTP Smoke

| Check | Method | Result |
| --- | --- | --- |
| Session page render | `GET /?view=chatgpt&tab=session` | PASS (`HTTP 200`) |
| Auth AJAX | `GET /?view=chatgpt&tab=session&ajax=chatgpt_auth` | PASS (`HTTP 200`, `state=AUTH_OK`) |
| Exchange start validation | `POST /?view=chatgpt&tab=session&ajax=chatgpt_exchange_start` with empty prompt | PASS (`HTTP 400`, `EMPTY_PROMPT`) |
| Sync start validation | `POST /?view=chatgpt&tab=session&ajax=chatgpt_sync_start` with invalid kind | PASS (`HTTP 400`, `SYNC_KIND_REQUIRED`) |
| Sync job status validation | `GET /?view=chatgpt&tab=session&ajax=chatgpt_sync_job_status` without `job_id` | PASS (`HTTP 400`, `JOB_ID_REQUIRED`) |
| Status page render | `GET /?view=chatgpt&tab=status` | PASS (AUTH/Gateway cards rendered, module JS include present) |
| Legacy form fallback validation | `POST /` with `action=chatgpt_send_message` and empty prompt | PASS (`HTTP 302`, redirect to ChatGPT session with `new_chat=1`) |
