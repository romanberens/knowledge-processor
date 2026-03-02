# ARCHITECTURE_OVERVIEW

## System Type

Server-rendered PHP control panel with two Python FastAPI backends:

- LinkedIn scraper service (`scraper`)
- ChatGPT UI-session gateway (`ai_session_gateway`)

## Runtime Topology

- Main stack (`docker-compose.yml`):
  - `db` MySQL 8 (`docker-compose.yml:2-24`)
  - `scraper` FastAPI + Playwright (`docker-compose.yml:25-53`)
  - `web` PHP 8.3 Apache (`docker-compose.yml:54-82`)
- AI session stack (`docker-compose.ai-session.yml`):
  - `ai_session_db` Postgres 16 (`docker-compose.ai-session.yml:2-18`)
  - `ai_session_gateway` FastAPI + Playwright + noVNC (`docker-compose.ai-session.yml:19-56`)

## Layered View

1. Presentation layer (PHP SSR + inline JS)
   - Main shell, top navigation, chat UI, sync controls:
     - `web/index.php:3438-3514`
     - `web/index.php:6002-7914`
2. Web integration layer (PHP include adapters)
   - ChatGPT adapter: `web/includes/chatgpt_api.php:7-255`
   - Scraper adapter: `web/includes/scraper_api.php:7-260`
3. ChatGPT gateway domain/API layer
   - FastAPI endpoints and worker orchestration:
     - `ai_session_gateway/app/main.py:128-1690`
   - Playwright automation + scraping of UI state:
     - `ai_session_gateway/app/exchange.py:543-692`
     - `ai_session_gateway/app/exchange.py:697-765`
     - `ai_session_gateway/app/exchange.py:768-860`
4. Scraper domain/API layer
   - Scrape/hydrate jobs:
     - `scraper/app/main.py:223-280`
5. Persistence layer
   - MySQL archive/editorial schema:
     - `db/init/001_schema.sql`
     - `db/init/002_editorial.sql`
     - `db/init/003_cms_integrations.sql`
   - Postgres ChatGPT integration schema:
     - `ai_session_gateway/app/db.py:90-240`

## Data Stores by Domain

- MySQL (`db`):
  - LinkedIn ingest, normalized items/contexts, user notes, editorial drafts, CMS integration config
- Postgres (`ai_session_db`):
  - auth sessions/events
  - integration threads/messages/attachments/events

## Communication Paths

1. Chat UI send flow:
   - Browser JS in `web/index.php` calls:
     - `/?view=chatgpt&tab=session&ajax=chatgpt_exchange_start`
   - PHP proxy calls gateway `/v1/threads/{id}/exchange/start` via `chatgpt_api.php`
   - Gateway worker executes Playwright exchange and updates Postgres
   - Browser polls `chatgpt_exchange_status` (`web/index.php:7497-7566`)

2. Chat history sync flow:
   - Browser JS starts sync with `chatgpt_sync_start` (`web/index.php:7430-7465`)
   - Gateway launches async sync thread (`ai_session_gateway/app/main.py:1380-1423`)
   - Browser polls sync status (`web/index.php:7288-7428`)

3. Auth/login flow for ChatGPT:
   - PHP AJAX `chatgpt_auth` (`web/index.php:71-77`)
   - Gateway auth endpoints (`ai_session_gateway/app/main.py:143-208`)
   - noVNC URL exposed by gateway (`docker-compose.ai-session.yml:39`)

## Key Architectural Characteristic

ChatGPT integration is a UI-automation adapter architecture:

- contract between UI and gateway is HTTP JSON (stable locally),
- contract between gateway and ChatGPT is DOM/browser behavior (external and mutable).
