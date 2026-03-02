# Uruchomienie po pobraniu z GitHub

Ten dokument prowadzi krok po kroku przez uruchomienie całej aplikacji lokalnie.

## 1. Wymagania

- Linux / WSL2 / macOS z działającym Dockerem.
- Docker Engine + Docker Compose v2 (`docker compose`).
- Git.
- Wolne porty lokalne: `8080`, `8090`, `7900`, `8190`, `7790`.

Sprawdzenie:

```bash
docker --version
docker compose version
git --version
```

## 2. Pobranie repo

```bash
git clone https://github.com/romanberens/knowledge-processor.git
cd knowledge-processor
```

## 3. Konfiguracja sekretów i env

Skopiuj szablon:

```bash
cp .env.example .env
```

Uzupełnij w `.env` co najmniej:

- `MYSQL_ROOT_PASSWORD`
- `MYSQL_PASSWORD`
- `APP_SECRET`
- `AI_SESSION_POSTGRES_PASSWORD`

Przykład generowania `APP_SECRET`:

```bash
openssl rand -hex 32
```

## 4. Start głównego stacku (LinkedIn + panel + MySQL)

```bash
docker compose up -d --build
```

Po starcie dostępne:

- UI: `http://127.0.0.1:8080`
- Scraper API: `http://127.0.0.1:8090`
- noVNC LinkedIn login: `http://127.0.0.1:7900/vnc.html`
- Adminer (opcjonalnie): `http://127.0.0.1:8081` (uruchom z `--profile debug`)

## 5. Start stacku AI Session Gateway (ChatGPT + Postgres)

Uruchamiaj po kroku 4 (wymaga wspólnej sieci Docker utworzonej przez główny stack):

```bash
docker compose -f docker-compose.ai-session.yml up -d --build
```

Po starcie dostępne:

- AI Session Gateway API: `http://127.0.0.1:8190`
- noVNC ChatGPT login: `http://127.0.0.1:7790/vnc.html?autoconnect=1&resize=remote&reconnect=1`

## 6. Pierwsze logowanie sesji

Sesje przeglądarki nie są w repo i muszą zostać zbudowane lokalnie.

### LinkedIn

1. Wejdź na `http://127.0.0.1:8080`.
2. Otwórz zakładkę `Login`.
3. Uruchom okno logowania.
4. Zaloguj się przez noVNC.
5. Poczekaj na stan `AUTH_OK`.

### ChatGPT

1. W panelu `ChatGPT` uruchom okno logowania.
2. Otwórz noVNC (`7790`) i zaloguj konto.
3. Zamknij sesję logowania, gdy stan zmieni się na `AUTH_OK`.

## 7. Szybki test działania

```bash
curl http://127.0.0.1:8090/health
curl http://127.0.0.1:8190/health
curl http://127.0.0.1:8190/status
```

Jeśli endpointy zwracają `ok: true` i UI działa na `8080`, instalacja jest gotowa.

## 8. Typowe problemy

`MYSQL_PASSWORD not set` / `APP_SECRET not set`:

- Brakuje wartości w `.env`.

`network linkedin_profletools_li_net declared as external, but could not be found`:

- Uruchom najpierw `docker compose up -d --build` (krok 4).

`AUTH_REQUIRED` dla ChatGPT/LinkedIn:

- Sesja przeglądarki nie jest zalogowana lub wygasła.

`PROFILE_BUSY`:

- Jednocześnie działa inne okno logowania/noVNC albo inna operacja korzystająca z tego samego profilu.

## 9. Ważne uwagi bezpieczeństwa

- Nie commituj `.env`.
- Nie commituj katalogów sesji przeglądarki: `profile/`, `ai_session_profile/`.
- Traktuj lokalne profile jak dane wrażliwe (zawierają cookies/sesje).
