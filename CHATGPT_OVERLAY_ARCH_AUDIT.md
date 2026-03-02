# Audyt architektury nakladki ChatGPT (stan biezacy)

Data audytu: 2026-03-01
Zakres: `/home/roman/linkedin_profleTools`
Tryb: tylko inspekcja kodu (bez zmian implementacji)

## 1) Identyfikacja srodowiska

- Frontend stack:
  - PHP SSR: `php:8.3-apache` (`web/Dockerfile`)
  - UI/CSS: Foundation `6.8.1` (CDN)
  - JS helper: jQuery `3.7.1` (CDN)
  - Brak frameworka SPA (brak React/Vue/Next w tym projekcie).
- Backend istnieje: TAK (2 warstwy backendowe).
  - `web` (PHP/Apache) jako warstwa UI + AJAX endpointy.
  - `ai_session_gateway` (FastAPI `0.115.8`, Uvicorn `0.34.0`, Playwright `1.50.0`, psycopg2 `2.9.10`).
- Srodowisko uruchomieniowe:
  - Docker Compose (`docker-compose.yml` + `docker-compose.ai-session.yml`).
  - DB glowna: MySQL 8 (`db`), DB integracji ChatGPT: Postgres 16 (`ai_session_db`).
  - Aplikacja nie jest statycznym buildem.
- Sposob komunikacji z ChatGPT:
  - Nie iframe i nie oficjalne API LLM.
  - `web/index.php` -> `web/includes/chatgpt_api.php` (HTTP JSON do `ai_session_gateway`) -> Playwright steruje UI `chatgpt.com` (DOM automation, persistent profile).
  - noVNC/iframe jest tylko do logowania sesji, nie do samej wymiany wiadomosci.

## 2) Struktura katalogow (max 3 poziomy)

```text
.
├── README.md
├── docker-compose.yml
├── docker-compose.ai-session.yml
├── web
│   ├── Dockerfile
│   ├── index.php
│   └── includes
│       ├── bootstrap.php
│       ├── chatgpt_api.php
│       ├── db.php
│       ├── repository.php
│       ├── scraper_api.php
│       └── strapi_api.php
├── ai_session_gateway
│   ├── Dockerfile
│   ├── requirements.txt
│   └── app
│       ├── auth.py
│       ├── config.py
│       ├── contracts.py
│       ├── db.py
│       ├── exchange.py
│       └── main.py
├── scraper
│   ├── Dockerfile
│   ├── requirements.txt
│   └── app
│       ├── auth.py
│       ├── config.py
│       ├── db.py
│       ├── linkedin_activity.py
│       ├── login.py
│       └── main.py
└── db
    └── init
        ├── 001_schema.sql
        ├── 002_editorial.sql
        └── 003_cms_integrations.sql
```

- Entry point aplikacji web: `web/index.php`.
- Entry point gateway: `ai_session_gateway/app/main.py`.
- Komponent/obszar listy wiadomosci:
  - render SSR: `web/index.php` (`#chatgpt-thread-log`, petla `$chatgptMessages`).
  - aktualizacja runtime: JS `appendToThread`, `updateAssistantNode`.
- Komponent input/composer:
  - `web/index.php` (`#chatgpt-send-form`, `#chatgpt-composer-input`).
- Wysylanie zapytan:
  - JS submit handler formularza (`composerForm.addEventListener('submit', ...)`).
  - AJAX endpoint startu: `ajax=chatgpt_exchange_start`.
- Obsluga odpowiedzi:
  - JS polling `pollExchangeStatus(exchangeId)` -> `ajax=chatgpt_exchange_status`.

## 3) Model danych wiadomosci

### 3.1 Obiekt wiadomosci (API input contract)

`POST /v1/threads/{thread_id}/messages` przyjmuje:

```json
{
  "message_id": "string|null",
  "parent_message_id": "string|null",
  "role": "system|user|assistant|tool",
  "content_text": "string",
  "mode": "string",
  "source": "string",
  "status": "string",
  "tool_name": "string|null",
  "metadata": {},
  "attachments": [
    {
      "attachment_id": "string|null",
      "file_name": "string",
      "mime_type": "string|null",
      "size_bytes": "int|null",
      "storage_ref": "string|null",
      "metadata": {}
    }
  ]
}
```

### 3.2 Obiekt wiadomosci (API output listy)

`GET /v1/threads/{thread_id}/messages` zwraca:

```json
{
  "thread_id": "string",
  "items": [
    {
      "message_id": "string",
      "thread_id": "string",
      "parent_message_id": "string|null",
      "role": "string",
      "content_text": "string",
      "mode": "string",
      "source": "string",
      "status": "string",
      "tool_name": "string|null",
      "created_at": "datetime",
      "metadata_json": {},
      "attachments": [
        {
          "attachment_id": "string",
          "message_id": "string",
          "file_name": "string",
          "mime_type": "string|null",
          "size_bytes": "int|null",
          "storage_ref": "string|null",
          "created_at": "datetime",
          "metadata_json": {}
        }
      ]
    }
  ],
  "count": "int"
}
```

### 3.3 ID i statusy

- Lokalne UUID/ID:
  - TAK: `message_id` i `thread_id` sa generowane lokalnie przez `_new_id(prefix)` (`msg_...`, `thr_...`, `uuid4().hex[:12]` + prefix).
  - To nie jest pelny RFC UUID w payloadzie, ale lokalny unikalny identyfikator.
- Lokalne `conversation_id`:
  - Brak osobnego pola `conversation_id`.
  - Funkcje rozmowy pelni lokalne `thread_id`.
  - Zdalny identyfikator rozmowy ChatGPT jest trzymany w `integration_threads.metadata_json.chatgpt.remote_thread_id` + `conversation_url`.
- Status wiadomosci:
  - Wystepuje pole `status`.
  - W kodzie widoczne statusy: `accepted`, `submitted`, `queued`, `streaming`, `received`, `failed`.

## 4) Mechanizm wysylania wiadomosci (flow)

1. Po kliknieciu "Wyslij" (submit form):
   - JS blokuje default submit.
   - Sprawdza `submitBusy` i pusty prompt.
2. Input blokowanie:
   - `setComposerBusy(true)` ustawia `submitBusy=true` i `sendButton.disabled=true`.
3. Render optymistyczny:
   - Od razu dopina lokalny babel usera do logu.
   - Czyści input (`composerInput.value=''`) i autosize.
   - Dodaje placeholder asystenta (`...`, `is-streaming`).
4. Start exchange:
   - Front wywoluje `ajax=chatgpt_exchange_start`.
   - PHP upsertuje/rozpoznaje thread (`/v1/threads`).
   - Gateway (`/v1/threads/{id}/exchange/start`) zapisuje:
     - user message: `status=submitted`,
     - assistant message: `status=queued`,
     - startuje worker thread backendowy.
5. Streaming odpowiedzi:
   - Worker wywoluje `exchange_once(..., on_partial=...)`.
   - `on_partial` aktualizuje te sama assistant message w DB (`status=streaming`, rosnacy `content_text`).
   - Front polluje `ajax=chatgpt_exchange_status` co ~700ms i aktualizuje ten sam node (`updateAssistantNode`).
6. Zapis odpowiedzi koncowej:
   - Po zakonczeniu worker zapisuje assistant message `status=received` + metadata exchange.
   - Front przy `status=completed` odblokowuje composer i konczy polling.

## 5) Zarzadzanie stanem

- Global state manager (Redux/Zustand/Context): NIE.
- Stan lokalny:
  - PHP: zmienne renderu (`$chatgptThreadId`, `$chatgptMessages`, `$chatgptThreads` itd.).
  - JS (IIFE w `index.php`): `submitBusy`, `activeExchangeId`, `activeAssistantNode`, `autoFollow`, `syncHistoryBusy`, itp.
- Gdzie przechowywana aktywna rozmowa:
  - URL query param `thread` (`$_GET['thread']`),
  - hidden input `chatgpt_thread_id`,
  - w DB jako `integration_threads.thread_id`.
- Przelaczanie rozmow:
  - Linki w sidepanelu (`/?view=chatgpt&tab=session&thread=<id>`) robia pelne przeladowanie.
  - Historia watku jest wtedy pobierana z `/v1/threads/{id}/messages` (server-side w PHP podczas renderu).

## 6) Persistence

- Czy rozmowy sa zapisywane lokalnie: TAK.
- Gdzie:
  - Postgres kontener `ai_session_db`, tabele:
    - `integration_threads`,
    - `integration_messages`,
    - `integration_message_attachments`,
    - `integration_events`.
- Kiedy zapis:
  - Przy starcie exchange: user + placeholder assistant.
  - Podczas streamingu: aktualizacje assistant message (`update_message`).
  - Po zakonczeniu exchange: finalny status/tekst/metadata.
  - Przy sync historii: insert/update/delete (w tym mirror delete lokalne).
- Sync vs async:
  - Operacje DB sa synchroniczne w obrebie requestu/worker threada Python.
  - Sama wymiana async dla UI jest realizowana przez backendowy worker + polling statusu.

## 7) Obsluga bledow

- Error boundary: BRAK (brak React, brak dedykowanego error boundary).
- Retry logic requestow:
  - Brak ogolnego retry dla pojedynczego nieudanego requestu send/exchange.
  - Jest polling (powtarzanie odczytu statusu) dla exchange i sync jobow.
- Timeout:
  - Obslugiwany w gateway (exchange timeout, `Assistant response timeout`; w sync endpointach mapowanie m.in. na HTTP 504/502 zaleznie sciezki).
- HTTP 429:
  - Brak jawnej, dedykowanej obslugi 429 w kodzie.
- Przerwane polaczenie:
  - Front `fetchJson` rzuca blad i UI oznacza odpowiedz jako `failed`.
- Partial stream:
  - Obslugiwany (`on_partial` -> status `streaming` -> incremental update w UI).

## 8) Scroll i UX

- Kontrolowany autoscroll: TAK.
  - `nearBottom()`, `autoFollow`, `forceFollowUntil`, `scheduleStickToBottom()`, `revealLatest()`.
- Wykrywanie polozenia uzytkownika: TAK.
  - Listener scroll i warunek near-bottom decyduja czy utrzymywac auto-follow.
- Wirtualizacja dlugich rozmow (`react-window` itp.): NIE.
  - Lista wiadomosci jest zwyklym DOM bez wirtualizacji.

## 9) Identyfikacja ryzyk (fakty z aktualnej implementacji)

- Potencjalne race conditions:
  - Exchange i sync korzystaja z jednego `profile_lock`; przy kolizji task moze przejsc w `PROFILE_BUSY` po tym, jak user message zostal juz utworzony.
  - `exchange_tasks`/`sync_tasks` sa in-memory; po restarcie gatewaya stan taskow znika (nie ma trwalego storage task-state).
  - Jednoczesna praca z wielu kart/sesji moze generowac konkurencyjne zapisy do tego samego watku.
- Potencjalne memory leak / narastanie zasobow:
  - Brak wirtualizacji wiadomosci: dlugie watki rosna liniowo w DOM.
  - Polling `setInterval` auth i polling timeouty dzialaja stale w lifecycle strony (do reload/unload).
  - In-memory mapy taskow sa ograniczane cleanupem (max 300/120), wiec sa bounded, ale nadal tymczasowe.
- Potencjalne podwojne zapisy:
  - Istnieja dwa tory wysylki (`ajax=chatgpt_exchange_start` i POST `action=chatgpt_send_message`), co w okreslonych scenariuszach uzycia moze dublowac semantycznie akcje wysylki.
  - Sync historii mapuje czesc rekordow po `remote_key` i fallbackowo po indeksie/tekście; przy zmianach struktury remote mozliwe sa duplikaty lub przesuniecia mapowania.
- Brakujace stany lifecycle wiadomosci:
  - Brak jawnych stanow typu `canceled`, `retrying`, `timeout` (jako osobny status biznesowy), `interrupted`.
  - W praktyce timeout/error konczy jako `failed` + detal w metadata/error string.

## 10) Podsumowanie techniczne

```text
ARCHITEKTURA: Serwer-renderowany panel PHP + lokalny gateway FastAPI; gateway automatyzuje UI ChatGPT przez Playwright, a dane rozmow trzyma w osobnym Postgresie (threads/messages/attachments/events). Front komunikuje sie przez AJAX do PHP, PHP proxyuje do gateway.
POZIOM DOJRZALOSCI: MVP rozbudowane (eksperymentalno-operacyjne).
NAJWIEKSZE RYZYKO:
1. Silna zaleznosc krytycznej logiki od zmiennego DOM ChatGPT (UI automation).
2. Brak trwalego storage stanu jobow exchange/sync (in-memory task registry).
3. Brak wirtualizacji dlugich watkow + stale pollingi, co podnosi koszt zasobow przy dlugich sesjach.
```
