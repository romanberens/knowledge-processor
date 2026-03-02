# AI Session Gateway

Isolated login/session gateway with:
- FastAPI control API,
- Playwright persistent browser profile,
- Google Chrome login window exposed via noVNC,
- noVNC access for manual login,
- dedicated Postgres state store.

Current scope:
- session bootstrap (start/login status/stop),
- local integration contract for threads/messages/attachments/events,
- UI exchange endpoint using the logged browser profile.

## Endpoints

Auth/session:
- `GET /health`
- `GET /status`
- `GET /auth/status`
- `POST /auth/login/start`
- `GET /auth/login/status?session_id=...`
- `POST /auth/login/stop?session_id=...`

Integration contract (local canonical model):
- `GET /v1/schema`
- `GET /v1/threads?limit=100&project_id=&assistant_id=&q=`
- `POST /v1/threads`
- `GET /v1/threads/{thread_id}`
- `GET /v1/threads/{thread_id}/messages?limit=200`
- `POST /v1/threads/{thread_id}/messages`
- `POST /v1/threads/{thread_id}/exchange`
- `GET /v1/events?limit=100&thread_id=`
- `POST /v1/events`

### Minimal payloads

Create thread:

```json
{
  "title": "Integracja ChatGPT bez API",
  "project_id": "lab-onenetworks",
  "assistant_id": "chatgpt-5.2",
  "status": "active",
  "metadata": {
    "source": "web-panel"
  }
}
```

Create message:

```json
{
  "role": "user",
  "content_text": "Zrób plan integracji",
  "mode": "deep_research",
  "attachments": [
    {
      "file_name": "brief.md",
      "mime_type": "text/markdown",
      "size_bytes": 1204,
      "storage_ref": "local://briefs/brief.md"
    }
  ],
  "metadata": {
    "composer_mode": "deep_research"
  }
}
```

Exchange via ChatGPT UI:

```json
{
  "prompt": "Stwórz krótki plan wpisu LinkedIn o bezpieczeństwie OT.",
  "mode": "deep_research",
  "source": "web_panel",
  "comparison_preference": "first",
  "metadata": {
    "composer_mode": "deep_research"
  }
}
```

Notes:
- endpoint zapisuje lokalnie wiadomość `user`, wysyła prompt do ChatGPT UI i zapisuje wiadomość `assistant`,
- jeśli pojawi się ekran porównania odpowiedzi (`Odpowiedź 1/2` + `Wolę tę odpowiedź`), gateway automatycznie wybiera kartę wg `comparison_preference`,
- jeśli okno logowania noVNC jest otwarte, endpoint zwróci `409` (`LOGIN_SESSION_OPEN` lub `PROFILE_BUSY`),
- jeśli sesja wygasła, endpoint zwróci `412` (`AUTH_REQUIRED`).

## Runtime env

Defaults in project compose:
- `SESSION_TARGET_NAME=chatgpt`
- `SESSION_LOGIN_URL=https://chatgpt.com/auth/login`
- `SESSION_AUTH_CHECK_URL=https://chatgpt.com/`
- `SESSION_HEADLESS_EXCHANGE=false`
- `SESSION_EXCHANGE_TIMEOUT_MS=180000`
- `SESSION_EXCHANGE_RESPONSE_STABLE_MS=2500`

You can override them with environment variables in `docker-compose.ai-session.yml`.
