# AGENT MEMORY – LinkedIn Archive

## 1. 🎯 GŁÓWNY PLAN (NIETYKALNE – KIERUNEK PROJEKTU)
> Ta sekcja zawiera aktualny, obowiązujący plan wysokopoziomowy.
> Może być aktualizowana TYLKO gdy:
> - zakończony zostanie etap,
> - zmieni się strategia projektu,
> - użytkownik wyraźnie zatwierdzi zmianę kierunku.

### 🧠 North Star (decyzja strategiczna)
Ten projekt jest **osobistym systemem wiedzy** opartym o treści z LinkedIna.  
**Scraper jest wyłącznie warstwą ingestu** (ETL) i może być “brudny/niestabilny” (DOM, lazy-load, checkpointy).  
**Produktem** jest Archiwum (`items + item_contexts`) i narzędzia eksploracji: Library, Search, filtry, (później) tagi i notatki.  
Wszystkie decyzje architektoniczne i UX mają wzmacniać: **szybkie znajdowanie**, **pełną treść**, **konteksty**, **deduplikację**.

### Roadmapa (etapy, priorytety)
1. **Etap 0 (zrobione): Fundament + logowanie HITL**
   - Docker Compose: `web` (PHP), `scraper` (FastAPI + Playwright), `db` (MySQL)
   - Logowanie LinkedIn w kontenerze przez noVNC (HITL), bez X11/GUI po stronie hosta
   - Podstawowe kolektory aktywności: `all`, `reactions`, `comments` (Recent Activity)

2. **Etap 1 (zrobione): Model danych “content + kontekst” + globalna deduplikacja**
   - Wprowadzony **globalny obiekt treści** (`items`) deduplikowany po **URN** (a fallback po canonical URL/hash)
   - Wprowadzone **konteksty/zdarzenia** (`item_contexts`): “co zrobiłem” (reaction/comment/share/post/save) + “skąd to pochodzi” (activity vs saved)
   - Ingest odporny na timeouts (sekcje nie wywracają całego runa), metryki jakości (URN/permalink) w `runs.details_json`
   - UI: Archiwum (items+contexts) + szczegóły itemu (konteksty)

3. **Etap 2 (zrobione): Klasyfikacja `content_type` (semantyka treści)**
   - Heurystyki DOM: `video` / `image` / `document` / `article` / `text`
   - Monotoniczny “upgrade” unknown→bardziej konkretne + backfill

4. **Etap 3 (zrobione): Kolektory “Saved” + spójne źródła**
   - Kolektory: `saved_posts`, `saved_articles` (My items)
   - Model “jedno item, wiele kontekstów” (np. to samo jako `reaction` i `save`)

5. **Etap 4 (zrobione): “Pełna treść” + kontrola jakości**
   - Hybryda: expand “...więcej” w feed + dogranie treści z permalinku tylko gdy trzeba
   - Ograniczenie kosztu: `hydrate_limit` per run
   - Maintenance: `POST /hydrate` (hydrate-only, DB-driven) bez scrollowania sekcji

6. **Etap 5 (później): Dodatkowe zakładki aktywności**
   - Aktywność: `videos`, `images` (i ew. inne widoczne typy z UI)
   - (Opcjonalnie) metadane: link-preview, tytuł, domena, lista mediów

7. **Etap 6 (zrobione: KB v1.2): Warstwa “knowledge base”**
   - Notatki użytkownika (`item_user_data.notes`) + statusy: `przejrzane` vs `merytoryczne`
   - Tagi (per item) + filtrowanie
   - Inbox Zero + Inbox Focus + Insights + KPI pracy z wiedzą
   - (później) Eksport (Markdown/JSONL)
   - (opcjonalnie) embeddings + semantyczne wyszukiwanie (RAG)

8. **Etap 7 (w trakcie): Redakcja (pipeline publikacji)**
   - UI: nowy obszar pracy `view=editorial` (Inbox redakcyjny + Drafty)
   - Model danych: `editorial_items` (kolejka) + `editorial_drafts` (warsztat)
   - Workflow redakcyjny: `selected → draft → in_progress → ready → published → archived`
   - Selekcja źródła: z Knowledge OS (`view=item`) akcją “Dodaj do Redakcji” (tylko `processed`)
   - (później) Integracja Strapi: push jako draft + webhook publikacji (v1.1)

---

## 2. 📐 ARCHITEKTURA DOCELOWA (NIETYKALNE – KONTRAKT SYSTEMU)
> Docelowy model danych, klasyfikacje, założenia architektoniczne.
> Ta sekcja definiuje „co jest czym” w systemie.
> Zmiany tylko po uzgodnieniu z użytkownikiem.

### Kontrakty pojęciowe
- **Item (treść / content object)**: pojedynczy, kanoniczny byt (post / artykuł / wideo / obraz), deduplikowany globalnie.
- **Context/Event (kontekst/zdarzenie)**: pojedyncza obserwacja “co zrobiłem” i “skąd to znam”.

### Klasyfikacje (docelowo)
- `activity_kind` (co zrobiłem): `post`, `share`, `reaction`, `comment`, `save`
- `content_type` (czym jest treść): `text`, `article`, `video`, `image`, `document`, `unknown`
- `source` (skąd pochodzi obserwacja):
  - `activity_all`, `activity_reactions`, `activity_comments`, `activity_videos`, `activity_images`
  - `saved_posts`, `saved_articles`

### Globalna deduplikacja
1. Primary key: **`item_urn`** (gdy dostępny, np. `urn:li:activity:...`)
2. Fallback: **canonical URL** (hash) gdy brak URN
3. Konteksty deduplikowane po deterministycznym **`context_hash`** (source + activity_kind + item_key + payload)

### Kolektory (docelowo)
- `ActivityCollector`:
  - `RecentActivityAllCollector`
  - `RecentActivityReactionsCollector`
  - `RecentActivityCommentsCollector`
  - (później) `RecentActivityVideosCollector`, `RecentActivityImagesCollector`
- `SavedCollector`:
  - `SavedPostsCollector`
  - `SavedArticlesCollector`

### Wymuszenia architektoniczne
- Login: HITL w noVNC; brak trzymania haseł; trwały profil Playwright (`/profile`)
- Scraper: uruchamiany ręcznie, lokalnie; brak “botowania 24/7”
- DB: MySQL (utf8mb4), full-text search na treści itemów
- UI: przegląd, wyszukiwanie, filtrowanie, status runów, weryfikacja jakości danych
- Knowledge Base (user-owned): notatki użytkownika trzymane osobno w `item_user_data` (nie mieszamy ingestu z danymi użytkownika)

### Semantyka przetwarzania wiedzy (KB v1.2)
- `Inbox` = brak notatki (`item_user_data` nie istnieje dla itemu)
- `przejrzane` = notatka jest wyłącznie markerem `✓ przejrzane [YYYY-MM-DD]`
  - detekcja: regex `^\\s*✓\\s*przejrzane(\\s+YYYY-MM-DD)?\\s*$` (case-insensitive)
- `opracowane merytorycznie` = notatka istnieje i zawiera coś więcej niż marker (własne słowa)

### Workflow redakcyjny (Redakcja v1.0)
- `editorial_items` = “zlecenie redakcyjne” powiązane z `items` (źródło prawdy o treści)
- `editorial_drafts` = warsztat pisarski (lokalny draft, wysyłka do CMS później)
- Statusy (`editorial_status`): `selected`, `draft`, `in_progress`, `ready`, `published`, `archived`
- Temat portalu (`portal_topic`): `ai`, `oss`, `programming`, `fundamentals`, `other`
- Zasada jakości: do Redakcji wpuszczamy tylko `processed` (notatka merytoryczna), nie `reviewed` (✓)
- Mapowania (docelowo):
  - Knowledge OS `item` → `editorial_item`
  - `editorial_item` → `editorial_draft`
  - `editorial_draft` → Strapi CMS (draft/published)

---

## 3. 🧩 STAN BIEŻĄCY (DYNAMICZNE – AS-IS)
> Aktualny stan kodu, znane problemy, braki, techniczny dług.
> Ta sekcja jest aktualizowana na bieżąco.

### Działa
- Kontenery: `db`, `scraper`, `web` (localhost: `8080/8090/7900`)
- Login noVNC (WSL-friendly), API `/auth/*` + blokada profilu (`profile_lock`)
- Kolektor aktywności: `all`, `reactions`, `comments`
- Kolektor “Saved”: `saved_posts`, `saved_articles` (My items -> saved posts, taby Wszystkie/Artykuły)
- Ekstrakcja `activity_urn` + budowa permalinków `https://www.linkedin.com/feed/update/<URN>`
- Best-effort rozwijanie “...więcej/see more” + metryki: `with_urn`, `with_https_url`, `see_more_clicks`
- Permalink hydration (pełna treść): gdy karta nadal ma “...więcej/see more”, dogrywanie treści z permalinku z budżetem `hydrate_limit` (per run)
- Tryb operatorski: `POST /hydrate` (hydrate-only, bez scrollowania sekcji; wybór kandydatów z DB) + metryki `permalink_hydrate_*` w `runs.details_json`
- Hydrate-only: wybór kandydatów z DB rozszerzony o filtry (`only_without_notes`, `source`, `kind`) + heurystyki ucięcia (frazy “… więcej/see more” + limit długości)
- Klasyfikacja `content_type` (DOM heurystyki) + zapis do `items` (monotoniczny upgrade typu treści)
- UI: Overview/Login/Logi scrapera (legacy)/Archiwum LinkedIn/Search/Runs + widok szczegółów wpisu (`view=post`) i itemu (`view=item`)
- UI: Overview jako „centrum dowodzenia” z zakładkami: `view=overview&tab=archive|knowledge|ingest|quality|runs` (redukcja przeciążenia poznawczego)
- UI: osobna zakładka `Inbox` (`view=inbox`) jako 1‑klik preset Search: itemy bez notatki + skrócone akcje (Inbox, Inbox+Saved) + link do Search (zaawansowane filtry)
- UI: Inbox Zero: w `view=inbox` przycisk „Oznacz jako przejrzane” zapisuje automatyczną notatkę (`✓ przejrzane YYYY-MM-DD`) i usuwa item z Inbox
- UI: Inbox Focus: `view=inbox&mode=focus` (losowa piątka z Inbox, pokazuje po jednym; akcje: Następny / przejrzane / notatka)
- Knowledge Base v1.2: rozróżnienie statusów notatek (`przejrzane` vs `opracowane merytorycznie`) + badge w listach (Search/Archiwum) + status w `view=item`
- UI: Insights / Trendy: `view=insights&tab=notes|authors|topics|velocity` (wnioski, top autorzy, tematy MVP, tempo ingest vs opracowania)
- Overview: KPI pracy z wiedzą (opracowane/przejrzane dziś + 7 dni) + tooltip definicji
- Search: działa na `items + item_contexts` (model produktowy) + naprawiony błąd PDO HY093 (unikalność placeholderów przy native prepares)
- Search: filtr `author` (exact match) do szybkich przejść z Insights (top autorzy → wyniki)
- UI: Archiwum LinkedIn (Library): filtr “Status wiedzy” (`inbox|reviewed|processed`) + filtr `tag` + presety (Inbox/Przejrzane/Opracowane)
- UI: Archiwum LinkedIn (Library): “Top tagi (30 dni)” jako szybkie filtry (pills → `lib_status=processed&lib_tag=...`)
- UI: Widok tematu: `view=topic&tag=<tag>` = tylko opracowane merytorycznie (sort po `item_user_data.updated_at`)
- UI: Widok tematu: nagłówek kontekstu (ile opracowanych + ostatnia notatka + tempo 7/30 dni)
- UI: Badge statusu wiedzy w listach (Search/Library): `inbox` / `przejrzane` / `notatka`
- UI: Overview → Wiedza: “Top tematy (30 dni)” jako szybkie przejście do Biblioteki (`processed + tag`)
- UI: Redakcja: `view=editorial&tab=inbox|drafts|draft` (kolejka + szkice lokalne) + akcja “Dodaj do Redakcji” w `view=item`
- DB: `items` + `item_contexts` (dual-write z aktywności; saved → tylko nowy model)
- DB: `editorial_items` + `editorial_drafts` (pipeline redakcyjny; osobna domena od Knowledge OS)
- Knowledge Base v1: `item_user_data.notes` + edycja notatek i tagów w `view=item` + opcjonalne wyszukiwanie w notatkach
- Knowledge Base v1.1: preset/filtr “Tylko z notatkami” (`only_notes=1`) + badge “notatka” + podgląd tagów (max 3) w listach (Search + Archiwum)
- Knowledge Base v1.1: filtr/preset “Inbox (bez notatek)” (`only_without_notes=1`) w Search (workflow collect→process)
- Overview: KPI “Inbox: bez notatek” (liczba itemów bez notatki)
- Search: preset “Inbox + Saved” (saved bez notatek)
- Timezone: czasy z DB interpretowane jako UTC i renderowane w `APP_TZ`; KPI i filtry dat konwertują granice do UTC

### Znane ograniczenia / dług
- “Pełna treść” nie jest gwarantowana w 100%: expand “więcej” jest best-effort; potrzebne jest dogrywanie z permalinku (Etap 4).
- `activity_label` jest zaszumiony (czasem łapie linie nagłówka typu job-title/followers); share vs post może być błędnie wykryty.
- Saved: `saved_posts` i `saved_articles` pochodzą z tej samej strony (`/my-items/saved-posts/`) i są rozróżniane po tabie; część itemów może wystąpić w obu kontekstach.
- Legacy `posts` pozostaje tylko do “Logi scrapera”; produktowo pracujemy na `items + item_contexts`.

### Ograniczenia LinkedIn
- Treści dynamiczne: infinite scroll + loader/spinner, konieczne wait/retry
- Wykrywanie automatyzacji: ryzyko checkpoint/CAPTCHA; narzędzie tylko lokalnie i sporadycznie

### Sprint log (wyciąg wdrożeń)
## 2026-02-14 / KB v2.1 + UX dashboardu
### 🎯 Tematy jako „mapa wiedzy” (Top tagi + widok tematu + skróty)
- Dodano “Top tagi (30 dni)” jako szybkie filtry (pills) w `view=library` (tylko opracowane merytorycznie).
- Dodano widok tematu `view=topic&tag=...` jako kolekcję opracowanych (sort po dacie notatki) + nagłówek kontekstu (łączna liczba, ostatnia notatka, tempo 7/30 dni).
- Dodano “Top tematy (30 dni)” w `view=overview&tab=knowledge` jako szybkie przejście do Biblioteki (`processed + tag`).
- Zmienione pliki: `web/index.php`, `web/includes/repository.php`, `agent.md`.
- Efekt produktowy: Biblioteka zachowuje się jak mapa aktualnych tematów, a temat ma własny “dashboard kompetencji”.
- QA: `php -l` (web/*), ręczne wejścia w: `/?view=library`, `/?view=topic&tag=...`, `/?view=overview&tab=knowledge`.

## 2026-02-14 / UI: poprawki overflow/scroll w Overview
### 🎯 Stabilizacja layoutu dashboardu (bez poziomych pasków)
- Dodano `min-width:0` dla `.content-wrap` i kafelków w `.kpi-grid` (eliminuje poziomy scroll w flex/grid).
- Sidebar ustawiony na stałą wysokość viewportu (`height: calc(100vh - topbar)`) + `overflow-y:auto` (żeby nie pompować wysokości strony).
- Zmienione pliki: `web/index.php`.
- QA: ręcznie: `/?view=overview&tab=archive` (brak scrolla poziomego), `/?view=overview&tab=runs` (przewija się tylko lista).

## 2026-02-14 / Redakcja v1.0 (Sprint 1)
### 🎯 Redakcyjny Inbox + szkic (local draft)
- Dodano tabele: `editorial_items` (kolejka) i `editorial_drafts` (warsztat) + indeksy i FK.
  - Uwaga: kolumna `lead_text` (zamiast `lead`, bo `LEAD` jest słowem kluczowym w MySQL 8).
- UI: nowy widok `view=editorial` z zakładkami `tab=inbox|drafts|draft`.
- UI: `view=item` ma akcję “Dodaj do Redakcji” (tylko `processed`; `reviewed` i `inbox` blokowane komunikatem).
- Akcje: dodanie źródła → `selected`, utworzenie szkicu → tworzy rekord w `editorial_drafts` i promuje `selected → draft`.
- Zmienione pliki: `db/init/002_editorial.sql`, `web/includes/repository.php`, `web/index.php`, `agent.md`.
- QA (manual):
  - `/?view=editorial&tab=inbox` (lista/filtry)
  - `POST action=editorial_add_source` (z `view=item`)
  - `POST action=editorial_create_draft` → redirect do `tab=draft`
  - `POST action=editorial_save_draft` (zapis pól)
  - Sprawdzenie braków: `curl` bez “Fatal error/PDOException”.
- Rollback (dev): `DROP TABLE editorial_drafts; DROP TABLE editorial_items;` (utrata danych redakcyjnych).
- Next: Sprint 2: ergonomia edytora (lepszy template), workflow `in_progress/ready/published`, integracja Strapi (push draft + webhook).

## 2026-02-14 / UI: przełącznik kontekstu + stabilizacja overflow
### 🎯 Knowledge OS ↔ Redakcja jako dwa tryby pracy (bez “przecieków”)
- Dodano przełącznik kontekstu w topbarze: `LinkedIn Archive` (Knowledge OS) ↔ `Redakcja` (Panel redakcyjny).
- Sidebar + mobile nav są zależne od kontekstu (`knowledge` vs `editorial`), bez mieszania linków (np. Search/Runs nie pojawiają się w Redakcji).
- Redakcja: `tab=draft` traktowany jako tryb edycji (wewnętrzny) i podświetla zakładkę `Drafty` w nawigacji.
- Redakcja: MVP UI ograniczone do `Inbox` + `Drafty` (bez “w budowie” dla Tematy/Publikacje/Narzędzia).
- Ujednolicono CSS wysokości: `--topbar-h` i kalkulacje dla `layout/sidebar/overview-workspace` (mniej przypadkowych scrolli).
- Poprawki overflow:
  - `html, body` + `.content-wrap` mają `overflow-x:hidden` (brak poziomego paska na Overview/Archiwum przy subpixel overflow).
  - `.overview-pane`/`.overview-pane-inner` mają `min-height:0` (żeby w `tab=runs` przewijała się tylko lista, a nie wrapper).
  - `kpi-grid` ma mniejszy `minmax()` (lepsze dopasowanie kafelków bez poziomego przewijania).
- Zmienione pliki: `web/index.php`, `agent.md`.
- QA (manual):
  - `/?view=overview&tab=archive` (brak poziomego scrolla)
  - `/?view=overview&tab=runs` (przewija się tylko tabela/lista)
  - `/?view=editorial&tab=inbox` (kontekst Redakcji + właściwe menu)
  - `/?view=editorial&tab=draft&draft_id=...` (podświetlenie `Drafty` w zakładkach)

## 2026-02-14 / Redakcja: Inbox jako kolejka produkcyjna (micro-sprint)
### 🎯 „Co dziś piszemy?”: liczniki statusów + badge + 1‑klik gotowe
- Dodano agregaty “Aktywna kolejka” (selected/draft/in_progress/ready) w nagłówku `view=editorial&tab=inbox` + szybkie linki filtrów.
- Dodano badge statusu w wierszu + pill priorytetu (czytelny skan kolejki bez dropdownów).
- Dodano 1‑klik akcję “Oznacz jako gotowe” (ustawia `editorial_status=ready`) dla wpisów ze szkicem.
- Zmienione pliki: `web/includes/repository.php`, `web/index.php`, `agent.md`.
- QA (manual):
  - `/?view=editorial&tab=inbox` (liczniki + filtry linkami)
  - klik “Oznacz jako gotowe” → status zmienia się na `ready` i redirect zachowuje filtry

## 2026-02-14 / Redakcja: Draft jako centrum pracy (micro-sprint)
### 🎯 Sterowanie pipeline’em bez wracania do Inbox
- W `view=editorial&tab=draft` dodano panel “Pipeline”:
  - edycja `editorial_status` / `portal_topic` / `priority` z zapisem i powrotem do tego samego draftu.
  - szybkie akcje: `W trakcie` → `in_progress`, `Gotowe` → `ready`.
- Rozszerzono `POST action=editorial_update_item` o opcjonalny powrót do `tab=draft` (`return_to=draft`, `return_draft_id=...`) bez zmiany zachowania dla Inboxu.
- Zmienione pliki: `web/index.php`, `agent.md`.
- QA (manual):
  - `/?view=editorial&tab=draft&draft_id=...` → zmiana statusu/tematu/priorytetu i powrót na ten sam draft

## 2026-02-14 / Redakcja: Auto-promocja + mini-header (micro-sprint)
### 🎯 Draft “rozumie”, że piszesz (selected/draft → in_progress) + szybki powrót do kolejki
- Dodano auto-promocję statusu przy zapisie szkicu:
  - jeśli `editorial_status` był `selected` albo `draft` i zmieniła się treść `lead_text`/`body` (oraz nie jest pusta) → ustaw `editorial_status = in_progress`.
  - implementacja w `POST action=editorial_save_draft` (porównanie “before” vs “after”).
- W `view=editorial&tab=draft` dodano mini-header kontekstowy (pills):
  - status/temat/priorytet + “ostatnio” + link “Zobacz w Inboxie” (filtr po bieżącym statusie).
- Zmienione pliki: `web/index.php`, `agent.md`.
- QA:
  - `php -l web/index.php`
  - ręcznie: zapisz draft w statusie `selected|draft` po edycji lead/body → status powinien przejść na `in_progress`.

## 2026-02-14 / Redakcja: Strapi push (micro-sprint)
### 🎯 Wysyłka szkicu do CMS jako draft (Strapi v4, Draft & Publish)
- Dodano integrację Strapi (ENV + HTTP) i 1 przycisk w widoku Draft:
  - `POST action=editorial_push_to_cms` tworzy/aktualizuje wpis w Strapi:
    - `cms_external_id` pusty → `POST /api/<content-type>`
    - `cms_external_id` ustawiony → `PUT /api/<content-type>/<id>`
  - zapisuje `editorial_drafts.cms_status = sent_to_cms` + `cms_external_id`.
  - po sukcesie promuje `editorial_status` do `ready` (jeśli był `selected|draft|in_progress`).
- UI: w `view=editorial&tab=draft`:
  - przycisk „Wyślij do CMS”
  - pills: `CMS: <status>` + `cms_id`
  - gdy brak konfiguracji: komunikat „CMS nie skonfigurowany”.
- Konfiguracja (web env): `STRAPI_BASE_URL`, `STRAPI_API_TOKEN`, `STRAPI_CONTENT_TYPE`.
- Zmienione pliki: `docker-compose.yml`, `web/index.php`, `web/includes/repository.php`, `web/includes/strapi_api.php`, `agent.md`.
- QA:
  - `php -l web/index.php web/includes/repository.php web/includes/strapi_api.php`
  - ręcznie: `view=editorial&tab=draft&draft_id=...` → „Wyślij do CMS” (wymaga ustawionych ENV).

## 2026-02-14 / Redakcja: Panel kontrolny CMS (micro-sprint)
### 🎯 Konfiguracja Strapi w UI (status + health-check + mapowanie)
- Dodano `tab=config` w Redakcji: `/?view=editorial&tab=config`.
- UI (read-only):
  - status konfiguracji (Base URL / Content Type / API Token maskowany) + status ogólny (gotowe / częściowe / brak).
  - przycisk „Testuj połączenie ze Strapi” (GET na `/api/<content-type>?pagination[pageSize]=1`).
  - sekcje: checklist konfiguracyjna + runtime mapowanie pól Redakcja → Strapi.
  - długie sekcje (Mapowanie/Checklist) przewijają się wewnątrz panelu (brak “rozjeżdżania” strony).
- Backend:
  - `POST action=strapi_healthcheck` z czytelnymi komunikatami (OK / auth error / no connection).
  - helper Strapi wspiera `GET` bez payloadu.
- Drobne usprawnienia:
  - komunikat success pokazuje content-type; błąd 404 wyjaśnia brak endpointu `/api/<content-type>` (STRAPI_CONTENT_TYPE).
  - zapisywany jest timestamp ostatniego udanego health-checka (`$_SESSION['strapi_last_ok_at']`) i pokazywany w UI.
- Zmienione pliki: `web/index.php`, `web/includes/strapi_api.php`, `agent.md`.
- QA:
  - `php -l web/index.php web/includes/strapi_api.php`
  - ręcznie: `/?view=editorial&tab=config` + „Testuj połączenie”.

## 2026-02-14 / Redakcja: Konfiguracja CMS w DB (micro-sprint)
### 🎯 Edytowalna konfiguracja Strapi bez .env (DB + szyfrowanie tokena)
- Dodano tabelę `cms_integrations` jako runtime “control plane” dla integracji CMS (source of truth w UI).
- Dodano repo helpers:
  - `get_cms_integration()` / `upsert_cms_integration()`
  - szyfrowanie tokena `encrypt_token()` / `decrypt_token()` (AES‑256‑GCM, klucz z `APP_SECRET`)
  - `get_strapi_config()` (DB-first, ENV jako bootstrap; `enabled=0` w DB wyłącza integrację bez fallbacku do ENV).
- UI: `view=editorial&tab=config` ma formularz edycji (Base URL / Content Type / Token / Enabled) + maskowanie tokena + status gotowości.
- Health-check i “Wyślij do CMS” korzystają z konfiguracji runtime (DB lub ENV bootstrap), a w Redakcji respektują `enabled`.
- Zmienione pliki: `db/init/003_cms_integrations.sql`, `web/includes/repository.php`, `web/index.php`.
- QA:
  - `php -l web/index.php web/includes/repository.php`
  - manual: utworzenie tabeli na istniejącym wolumenie DB (gdy init scripts się nie odpalą): `mysql < db/init/003_cms_integrations.sql`
  - ręcznie: zapis konfiguracji w UI → health-check → push draftu do CMS.

## 2026-02-14 / Redakcja: Dopasowanie payloadu do Strapi Article (v5) (micro-sprint)
### 🎯 Wysyłka draftu zgodna z modelem Article: title/slug/excerpt/content/publishOn/authorName
- Zmieniono payload wysyłki do Strapi, aby był zgodny z modelem **Article** (Strapi v5):
  - `title` ← `editorial_drafts.title`
  - `slug` ← `slugify(title)` (fallback: `article-<draft_id>`)
  - `excerpt` ← `editorial_drafts.lead_text`
  - `content` ← `editorial_drafts.body`
  - `authorName` ← stała wartość `Redakcja OneNetworks`
  - `publishOn` ← NOW() w UTC (ISO 8601)
- Usunięto z payloadu legacy pola nieistniejące w Strapi (`lead`, `body`, `topic`, `priority`, `format`, `source_url`, `source_item_id`, `tags_text`).
- Zaktualizowano runtime-dokumentację mapowania w `view=editorial&tab=config`.
- Zmienione pliki: `web/index.php`, `agent.md`.
- QA (manual):
  - `/?view=editorial&tab=draft&draft_id=...` → „Wyślij do CMS” → w Strapi powstaje/aktualizuje się Article (draft).
  - Sprawdź: `title`, `slug`, `excerpt`, `content`, `authorName`, `publishOn`.

## 2026-02-14 / Fix: APP_SECRET required for CMS config encryption (micro-sprint)
### 🎯 Czytelna obsługa braku APP_SECRET + blokada zapisu tokena
- Problem: brak `APP_SECRET` powodował błąd przy zapisie tokena API (nie można zaszyfrować).
- Poprawki:
  - UI `view=editorial&tab=config` pokazuje badge `APP_SECRET: OK/brak` i instrukcję jak ustawić (`docker compose up -d --force-recreate web`).
  - Pole tokena jest wyłączone (disabled), gdy `APP_SECRET` nie jest ustawiony.
  - Backend `POST action=cms_config_save` blokuje zapis tokena (i bootstrap z ENV) bez `APP_SECRET` z czytelnym komunikatem.
  - `get_strapi_config()` mapuje błąd decrypt na instruktażowy komunikat dla operatora.
- Zmienione pliki: `web/index.php`, `web/includes/repository.php`, `agent.md`.
- QA (manual):
  - Bez `APP_SECRET`: w Config widzisz instrukcję, token disabled, zapis tokena zablokowany.
  - Po ustawieniu `APP_SECRET` i restarcie web: zapis tokena działa, token szyfrowany w DB, health-check działa.

---

## 4. 🛠️ ZADANIA AKTYWNE (DYNAMICZNE – WORK IN PROGRESS)
- [x] Etap 1.1: Dodać tabele `items` + `item_contexts` + `item_tags` (schema + migracja)
- [x] Etap 1.2: Dual-write w scraperze: `posts` (legacy) + nowy model (items/contexts)
- [x] Etap 1.3: Metryki jakości: % z URN, % z permalinkiem (+ liczniki `see_more_clicks`, rozkład `content_type`)
- [x] Etap 1.4: UI: filtr po `activity_kind` i wgląd w konteksty itemu
- [x] Etap 1.5: UX: “Feed / Logs” jako `Logi scrapera` (legacy), “Library” jako `Archiwum LinkedIn` (centrum pracy)
- [x] Etap 1.6: UI: filtr `content_type` w Archiwum (Library)
- [x] Etap 2.1: Scraper: klasyfikacja `content_type` (DOM heurystyki) + zapis do `items`
- [x] Etap 2.2: Metryki: rozkład `content_type` w `runs.details_json`
- [x] Etap 2.3: Backfill `items.content_type` (deep-scan) żeby zbić `unknown`
- [x] Etap 3.1: Saved collectors: `saved_posts` + `saved_articles` (zapis do `items + item_contexts`)
- [x] Etap 3.2: UI: szybki filtr “Saved” (preset) + KPI liczby saved w Overview
- [x] Etap 4.1: Permalink hydration: gdy w karcie zostaje “...więcej/see more”, dograj treść z permalinku i zaktualizuj `items.content`
- [x] Etap 4.2: UI/ops: kontrola `hydrate_limit` (button/preset) + metryki w runie (attempted/ok/upgraded/failed) + KPI w Overview
- [x] Etap 4.3: Tryb `hydrate-only`: dogrywanie z permalinków bez scrollowania sekcji (kandydaci z DB; limit per run)
- [x] Bugfix: Search (PDO HY093 Invalid parameter number) – unikalne placeholdery, brak błędów przy `q` + filtry
- [x] KB v1: notatki użytkownika (`item_user_data`) + edycja w UI (`view=item`) + opcjonalne szukanie w notatkach
- [x] KB v1: tagowanie itemów (add/remove w `view=item`) + filtr `tag` w Search
- [x] KB v1.1: “Tylko z notatkami” (`only_notes=1`) + badge “notatka” + tag-pills w listach (Search + Archiwum)
- [x] KB v1.1: “Inbox (bez notatek)” (`only_without_notes=1`) + konflikt `only_notes` vs `only_without_notes` (preferuj `only_notes`)
- [x] KB v1.1: Overview KPI “Inbox: bez notatek”
- [x] KB v1.1: preset Search “Inbox + Saved”
- [x] UI: osobna zakładka `Inbox` (`view=inbox`) w menu (1‑klik workflow bez grzebania w filtrach)
- [x] Inbox Zero: akcja 1‑klik „Oznacz jako przejrzane” (auto-notatka `✓ przejrzane YYYY-MM-DD`)
- [x] KB v1.2: statusy notatek (`przejrzane` vs `merytoryczne`) + badge w listach (Search/Archiwum) + status w `view=item`
- [x] KB v1.2: Overview KPI pracy z wiedzą (przejrzane/opracowane dziś + 7 dni)
- [x] KB v1.2: widok `Insights` (`view=insights`) = notatki merytoryczne z ostatnich 7 dni
- [x] Insights / Trendy: rozbudowa `view=insights` o taby `authors` (top autorzy), `topics` (top frazy + top tagi), `velocity` (tempo ingest vs opracowania)
- [x] KB v1.2: `Inbox Focus` (`view=inbox&mode=focus`) = losowa piątka, nawigacja “Następny”
- [x] UX: Overview → Tabs (archive/knowledge/ingest/quality/runs) + ograniczenie scrolla na desktopie
- [x] UI polish: Overview→Archiwum: KPI w CSS-grid (bez poziomego scrolla); Overview→Runy: sticky nagłówek tabeli (przewija się tylko lista)
- [x] UI: tooltips/wyjaśnienia KPI (Saved: konteksty + metryki hydration) w Overview
- [x] UI: Archiwum LinkedIn (Library): filtr statusu wiedzy (`inbox|reviewed|processed`) + filtr `tag`
- [x] UI: Archiwum LinkedIn (Library): “Top tagi (30 dni)” jako szybkie filtry
- [x] UI: Widok tematu: `view=topic&tag=<tag>` (kolekcja opracowanych)
- [x] UI: Widok tematu: nagłówek kontekstu (stats łączna liczba + ostatnia notatka + tempo 7/30)
- [x] UI: Badge statusu wiedzy w listach (Search/Library): `inbox` / `przejrzane` / `notatka`
- [x] Hydrate-only: filtry query (`only_without_notes`, `source`, `kind`) + presety w UI (Inbox/All/Inbox+Saved)
- [x] DB: indeks `item_user_data.updated_at` (pod velocity/insights/topic view)
- [x] Redakcja v1.0: migracja DB `editorial_items` + `editorial_drafts` (FK + indeksy)
- [x] Redakcja v1.0: UI `view=editorial` (Inbox: lista + filtry + inline status/topic/priority)
- [x] Redakcja v1.0: `view=item` akcja “Dodaj do Redakcji” (tylko processed; dedup po source_item_id)
- [x] Redakcja v1.0: akcja “Utwórz szkic” (autopopulacja: title/lead/body z content + notes)
- [x] Redakcja v1.0 QA: ścieżka E2E `item(processed) → selected → draft`
- [x] Redakcja: runtime konfiguracja Strapi w DB (`cms_integrations`) + szyfrowanie tokena (`APP_SECRET`) + health-check/push z DB-first
- [x] Redakcja: payload wysyłki zgodny z modelem Strapi Article (v5): `title/slug/excerpt/content/authorName/publishOn`

---

## 5. 📚 NOTATKI TECHNICZNE / HEURYSTYKI (DYNAMICZNE – CACHE WIEDZY)
- URN regex: `urn:li:activity:\\d+`
- Permalink (posty): `https://www.linkedin.com/feed/update/<URN>`
- Expand “więcej”: w PL często `… więcej`, `pokaż więcej`; w EN `see more`
- `content_type` heurystyka (DOM, precedence): `document > video > image > article > text > unknown`
  - `document`: `.update-components-document`, `.feed-shared-document`, `a[href*="/document/"]`
  - `video`: `video`, `.update-components-video`, `.feed-shared-video`
  - `image`: obrazy w kontenerach `.update-components-image`, `.feed-shared-image` (liczony `image_count`)
  - `article`: `.update-components-article`, `.feed-shared-article`, `.feed-shared-external-card`
- PyMySQL: literalne `%` w SQL musi być `%%` (bo placeholdery `%s`)
- Sekcje aktywności (przykłady):
  - `/in/<slug>/recent-activity/all/`
  - `/in/<slug>/recent-activity/reactions/`
  - `/in/<slug>/recent-activity/comments/`
- Saved:
  - URL: `/my-items/saved-posts/`
  - Taby: `Wszystkie` / `Artykuły` (buttony, brak href) -> `saved_posts` / `saved_articles`
  - Selektor karty: `div[data-chameleon-result-urn]` (URN w `data-chameleon-result-urn`)
- Stabilność: `goto()` timeout 60s + retry; osobny `new_page()` per sekcja

---

## 6. 🗃️ DECYZJE ARCHITEKTONICZNE (HISTORIA)
- Wybrano login HITL przez noVNC w kontenerze (WSL bez GUI, spójny UX w UI).
- Wprowadzono `profile_lock`, bo persistent profil Chromium nie może być używany równolegle (login vs scrape).
- `/scrape` blokuje start jeśli istnieje aktywna sesja login (`login_session_id`), żeby uniknąć deadlock.
- “Partial run”: błąd jednej sekcji nie wywraca całego zbierania (run może kończyć się `partial`).
- Zmieniono kolejność roadmapy: `content_type` wdrożone przed kolektorami `Saved`, bo UI (Archiwum) zyskuje natychmiastową użyteczność (filtry działają).
- Backfill wymagał zmiany heurystyki “stagnation”: stop nie może zależeć od nowych insertów do `posts` (legacy), tylko od pojawiania się nowych kart w DOM (`seen`).
- Dodano `scroll_limit` (query param) jako bezpieczny override (clamp do `DEEP_SCROLL_LIMIT`) dla kontrolowanych skanów/backfill.
- North Star: Archiwum wiedzy (`items + item_contexts`) jest produktem; scraper to tylko ingest (ETL). Search/Library mają działać na `items`, a `posts` zostaje jako log techniczny.
- KB v1.2: statusy “przejrzane” vs “merytoryczne” wynikają wyłącznie z treści `item_user_data.notes` (marker `✓ przejrzane ...`), bez zmian schematu DB.
- Czas: DB trzyma `DATETIME` w UTC; UI interpretuje je jako UTC i renderuje w `APP_TZ`. KPI i filtry dat konwertują granice czasu do UTC na potrzeby zapytań.
