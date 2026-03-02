# RISK_REGISTER

Scale:

- Severity: `P0` critical, `P1` high, `P2` medium
- Type: reliability, consistency, performance, coupling

## Current Risks

| ID | Severity | Type | Risk | Evidence |
| --- | --- | --- | --- | --- |
| R-01 | P0 | reliability | Exchange and sync task states are in-memory only; restart loses task state. | `ai_session_gateway/app/main.py:42-45`, `ai_session_gateway/app/main.py:56-117` |
| R-02 | P0 | consistency | Single `profile_lock` for interactive exchange and sync can fail operations with `PROFILE_BUSY` after user message creation. | `ai_session_gateway/app/main.py:37`, `ai_session_gateway/app/main.py:780-944`, `ai_session_gateway/app/main.py:1125-1134` |
| R-03 | P0 | external dependency | Core exchange relies on mutable third-party web DOM selectors/behavior. | `ai_session_gateway/app/exchange.py:56-67`, `ai_session_gateway/app/exchange.py:380-390`, `ai_session_gateway/app/exchange.py:768-860` |
| R-04 | P1 | performance | No virtualization of long chat logs; DOM grows linearly with message count. | `web/index.php:6180-6539`, `web/index.php:6916-7057` |
| R-05 | P1 | performance | Multiple periodic pollers (`exchange`, `sync`, auth heartbeat) can accumulate frontend load in long sessions. | `web/index.php:7497-7566`, `web/index.php:7288-7428`, `web/index.php:7901-7902` |
| R-06 | P1 | consistency | Dual exchange surfaces exist (`/exchange/start` async and `/exchange` sync), increasing semantic divergence risk. | `ai_session_gateway/app/main.py:947-1085`, `ai_session_gateway/app/main.py:1487-1662`, `web/includes/chatgpt_api.php:175-203` |
| R-07 | P1 | coupling | Core is less monolithic, but module still uses implicit parent variable scope (`session.php`) and tightly coupled include contract. | `web/index.php:5638`, `web/modules/chatgpt/views/session.php` |
| R-08 | P2 | lifecycle | Message status model lacks dedicated states like `canceled`, `retrying`, `timeout`, `interrupted`. | observed statuses in gateway flow `submitted/queued/streaming/received/failed` (`ai_session_gateway/app/main.py:967-1000`, `900-942`) |
| R-09 | P2 | data mapping | Sync history merge/delete logic uses remote_key and fallback heuristics, sensitive to remote message structure drift. | `_sync_single_thread_history` usage + mirror delete path `ai_session_gateway/app/main.py:1240-1275`, `1298-1308` |
| R-10 | P2 | operations | Runtime profile directories are mutable state outside app code; permission/lock issues can break sessions. | `docker-compose.yml:43`, `docker-compose.ai-session.yml:45`, lock cleanup in `ai_session_gateway/app/auth.py:136-141` |

## Notes for Phase-2 Extraction

- R-07 (monolith coupling) is the main extraction target.
- R-01 and R-02 should remain visible as known operational constraints during module split.
- R-03 remains intrinsic while architecture depends on browser automation against external UI.
