# FLOWS

## 1. Open ChatGPT Session View

1. User navigates to `/?view=chatgpt&tab=session`.
2. `web/index.php` resolves context/tab and loads ChatGPT bootstrap data:
   - auth state, threads list, selected thread, initial messages
   - `web/index.php:1440-1522`.
3. SSR renders shell + side rail + thread log:
   - `web/index.php:6034-6612`.
4. Runtime JS initializes collapse panels, scroll, modals, polling handlers:
   - `web/index.php:6644-7914`.

## 2. Send Message + Streaming-like Polling

1. Submit in composer (`#chatgpt-send-form`) triggers JS handler:
   - `web/index.php:7587-7655`.
2. UI performs optimistic render:
   - append user bubble (`buildTextMessage` + `appendToThread`)
   - clear input + autosize
   - append assistant placeholder `...`.
3. Front sends `POST ?ajax=chatgpt_exchange_start`:
   - `web/index.php:7623-7627`.
4. PHP endpoint:
   - upserts/creates thread (`chatgpt_thread_upsert`)
   - starts async exchange (`chatgpt_thread_exchange_start`)
   - `web/index.php:79-207`.
5. Gateway endpoint `/v1/threads/{id}/exchange/start`:
   - creates user+assistant queued messages in Postgres
   - stores task in `exchange_tasks`
   - starts worker thread `_run_exchange_task`
   - `ai_session_gateway/app/main.py:947-1064`.
6. Front polls `?ajax=chatgpt_exchange_status` every ~700ms:
   - `web/index.php:7497-7566`.
7. Gateway worker updates same assistant message while partials arrive:
   - `exchange_once(..., on_partial=...)`
   - `ai_session_gateway/app/main.py:750-944`
   - `ai_session_gateway/app/exchange.py:840-853`.
8. On completion:
   - assistant message status becomes `received`
   - UI stops polling and unlocks composer.

## 3. Auth/Login Session Flow (ChatGPT)

1. User starts login window (session modal controls in ChatGPT view).
2. PHP calls gateway auth endpoints via adapter:
   - `web/includes/chatgpt_api.php:96-119`.
3. Gateway starts VNC/noVNC + browser login stack:
   - `ai_session_gateway/app/auth.py:222-280`.
4. Browser polls `?ajax=chatgpt_auth` every 2.5s:
   - `web/index.php:7833-7903`.
5. On `AUTH_OK`, UI reloads page to refresh state:
   - `web/index.php:7891-7893`.

## 4. Full History Sync Job

1. User clicks one of:
   - `Skanuj listę wątków`
   - `Dociągnij komplet rozmów`
   - `Pełna synchronizacja`
   - controls at `web/index.php:6575-6578`.
2. JS sends `POST ?ajax=chatgpt_sync_start`:
   - `web/index.php:7430-7465`.
3. PHP forwards to gateway `/v1/sync/*/start`:
   - `web/index.php:292-348`
   - `web/includes/chatgpt_api.php:215-231`.
4. Gateway queues sync task and starts worker:
   - `ai_session_gateway/app/main.py:1380-1423`.
5. Worker executes:
   - threads scan (`scan_threads_index_once`) and/or
   - messages pull (`_sync_single_thread_history`)
   - `ai_session_gateway/app/main.py:1152-1318`.
6. JS polls `?ajax=chatgpt_sync_job_status` and updates:
   - progress bar
   - live telemetry text ("czyta", "przewija", "dociaga")
   - `web/index.php:7288-7428`.
7. On completion UI reports counters and reloads:
   - `web/index.php:7375-7411`.

## 5. Open Existing Thread and Continue

1. User clicks thread in "Twoje czaty" list (recent or modal):
   - links in `web/index.php:6115-6126` and `6595-6606`.
2. Full page reload with `thread=<id>`.
3. Server loads messages with `/v1/threads/{id}/messages`:
   - `web/index.php:1513-1522`.
4. Runtime `revealLatest()` applies autoscroll to bottom:
   - `web/index.php:6829-6836`, `7568-7570`.
5. New sends continue with hidden `chatgpt_thread_id` in same thread:
   - `web/index.php:6671`, `7629-7633`.
