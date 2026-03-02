# LinkedIn Activity Archive

Lokalne narzędzie kontenerowe do archiwizacji aktywności LinkedIn:
- własne publikacje (`all`),
- polubione wpisy (`reactions`),
- komentowane wpisy (`comments`).

Stack:
- Scraper API: Python + FastAPI + Playwright,
- Baza: MySQL 8,
- Panel: PHP 8.3 + Foundation,
- Orkiestracja: Docker Compose.

## Sekrety i konfiguracja

Repozytorium nie powinno zawierać sekretów. Przed uruchomieniem:

```bash
cp .env.example .env
```

Następnie ustaw własne wartości w `.env`:
- `MYSQL_ROOT_PASSWORD`
- `MYSQL_PASSWORD`
- `APP_SECRET`
- `AI_SESSION_POSTGRES_PASSWORD`

`APP_SECRET` wygenerujesz np.:

```bash
openssl rand -hex 32
```

Nie commituj `.env` ani katalogów profili przeglądarki (`profile/`, `ai_session_profile/`).

## Start

```bash
docker compose up -d --build
```

Panel:
- UI: `http://127.0.0.1:8080`
- Scraper API: `http://127.0.0.1:8090`
- noVNC (logowanie): `http://127.0.0.1:7900/vnc.html`
- Adminer (opcjonalnie): `http://127.0.0.1:8081` (`--profile debug`)

## Tryby scrapera

- `deep`: długi skan historyczny (dużo scrolli),
- `update`: szybki skan aktualizacji (mało scrolli + stop przy znanych wpisach).

Endpointy:
- `POST /scrape?mode=deep`
- `POST /scrape?mode=update`
- `GET /status`
- `GET /health`

## Pierwsze logowanie LinkedIn

Scraper korzysta z trwałego profilu Chromium w wolumenie `./profile`.

Po pierwszym uruchomieniu trzeba mieć aktywną sesję LinkedIn w tym profilu. Jeśli sesja nie istnieje,
run zakończy się błędem `LinkedIn session is not authenticated`.

### Logowanie w UI (zalecane)

1. Otwórz panel `http://127.0.0.1:8080` i przejdź do zakładki `Login`.
2. Kliknij `Uruchom okno logowania`.
3. Zaloguj się ręcznie w osadzonym noVNC (to jest przeglądarka uruchomiona w kontenerze).
4. Gdy status zmieni się na `AUTH_OK`, kliknij `Zamknij okno`.

### Alternatywa: skrypt logowania (GUI na hoście)

```bash
docker compose run --rm scraper python -m app.login
```

Uwaga: skrypt otwiera okno przeglądarki (`headless=False`), więc wymaga środowiska, które potrafi wyświetlić GUI.

## Dane

MySQL tworzy tabele:
- `posts` (z deduplikacją: `content_hash`, `source_page + url_hash`),
- `runs` (historia uruchomień + statusy),
- `tags`, `post_tags` (pod dalszy etap tagowania).

Wyszukiwanie:
- FULLTEXT po `posts.content, posts.author`,
- filtry: źródło, zakres dat, tag.

## Konfiguracja (env)

Najważniejsze zmienne po stronie scrapera:
- `LINKEDIN_PROFILE_SLUG` (np. `wirtualnaredakcja-pl`),
- `HEADLESS` (`true/false`),
- `DEEP_SCROLL_LIMIT` (domyślnie `700`),
- `UPDATE_SCROLL_LIMIT` (domyślnie `15`),
- `STAGNATION_LIMIT` (domyślnie `6`),
- `KNOWN_STREAK_LIMIT` (domyślnie `25`).

## Ograniczenia praktyczne

- LinkedIn ładuje feed dynamicznie, więc scraper używa pętli `scroll -> wait -> extract` i kończy przy stagnacji.
- Selektory DOM mogą się zmieniać; parser ma fallbacki, ale okresowo będzie wymagał korekt.
- Narzędzie jest zaprojektowane do użytku lokalnego i ręcznego.

## AI Session Gateway (izolowany login + sesja)

Dodany został oddzielny stack (osobna sieć i osobny Postgres) do samego etapu logowania/sesji:
- Compose: `docker-compose.ai-session.yml`
- API: `http://127.0.0.1:8190`
- noVNC: `http://127.0.0.1:7790/vnc.html`

Start:

```bash
cd /home/roman/linkedin_profleTools
docker compose -f docker-compose.ai-session.yml up -d --build
```

Domyślnie gateway startuje z:
- `SESSION_TARGET_NAME=chatgpt`
- `SESSION_LOGIN_URL=https://chatgpt.com/auth/login`
- `SESSION_AUTH_CHECK_URL=https://chatgpt.com/`
- `SESSION_HEADLESS_EXCHANGE=false`
- `SESSION_EXCHANGE_TIMEOUT_MS=180000`
- `SESSION_EXCHANGE_RESPONSE_STABLE_MS=2500`

Okno logowania noVNC używa `google-chrome-stable` (nie testowego Chromium Playwright), co poprawia kompatybilność z logowaniem Google.

Przykładowy flow:

```bash
curl -X POST http://127.0.0.1:8190/auth/login/start
curl \"http://127.0.0.1:8190/auth/login/status?session_id=<SESSION_ID>\"
curl -X POST \"http://127.0.0.1:8190/auth/login/stop?session_id=<SESSION_ID>\"
curl http://127.0.0.1:8190/status
```

Lokalny model wymiany danych (threads/messages/events):

```bash
curl -X POST http://127.0.0.1:8190/v1/threads \
  -H 'content-type: application/json' \
  -d '{"title":"Integracja ChatGPT","project_id":"lab-onenetworks","assistant_id":"chatgpt-5.2"}'

curl http://127.0.0.1:8190/v1/threads

curl -X POST "http://127.0.0.1:8190/v1/threads/<THREAD_ID>/messages" \
  -H 'content-type: application/json' \
  -d '{"role":"user","content_text":"Zrób plan","mode":"deep_research"}'

curl "http://127.0.0.1:8190/v1/threads/<THREAD_ID>/messages"
curl "http://127.0.0.1:8190/v1/events?thread_id=<THREAD_ID>"

curl -X POST "http://127.0.0.1:8190/v1/threads/<THREAD_ID>/exchange" \
  -H 'content-type: application/json' \
  -d '{"prompt":"Napisz 3 punkty planu publikacji","mode":"default","source":"web_panel","comparison_preference":"first"}'
```

Uwagi:
- exchange zapisuje lokalnie oba kroki (`user` + `assistant`),
- gdy pojawia się ekran porównania odpowiedzi (`Wolę tę odpowiedź`), gateway automatycznie wybiera kartę zgodnie z `comparison_preference`.
