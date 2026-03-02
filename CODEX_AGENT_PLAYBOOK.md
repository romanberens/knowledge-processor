# CODEX_AGENT_PLAYBOOK

## 1. Purpose

This document defines how Codex must execute the ChatGPT module refactor in this repository.

Primary goals:

- map architecture and ownership first,
- extract ChatGPT code into a dedicated module without breaking behavior,
- integrate and clean up with explicit regression checks,
- keep documentation synchronized with each step.

## 2. Scope

In scope:

- ChatGPT UI, routes, endpoint glue, orchestration services, assets.
- Integration points between ChatGPT and the main panel shell.
- Refactor documentation and migration markers.

Out of scope:

- rewriting unrelated LinkedIn/scraper functionality,
- large behavior changes during extraction,
- unplanned redesign of the whole app.

## 3. Execution Modes

Codex must explicitly state mode before work starts.

| Mode | Description | Code Changes |
| --- | --- | --- |
| `ANALYSIS` | Project mapping and contracts | No |
| `EXTRACTION` | Module extraction and file moves | Yes |
| `INTEGRATION` | Wiring, cleanup, regression validation | Yes |

## 4. Global Rules

1. In `ANALYSIS`, no code edits.
2. In `EXTRACTION`, preserve behavior; move structure first.
3. In `INTEGRATION`, clean only after migration is verified.
4. Every claim in analysis docs must reference `file:line` where possible.
5. Every phase must end with updated `.md` outputs.

## 5. Required Documentation Artifacts

Codex must create and maintain:

- `PROJECT_MAP.md`
- `ARCHITECTURE_OVERVIEW.md`
- `CHATGPT_SURFACE_MAP.md`
- `FLOWS.md`
- `RISK_REGISTER.md`
- `REFACTOR_PLAN.md`
- `MODULE_CONTRACT.md`
- `SMOKE_TESTS.md`
- `INTEGRATION_NOTES.md`
- `REFACTOR_PROGRESS.md`

## 6. Refactor Marker System

Use markers only where migration clarity is needed.

Allowed tags:

- `[REF-MOD-CHATGPT]`
- `[REF-COUPLING]`
- `[REF-GLOBAL-STATE]`
- `[REF-SIDE-EFFECT]`
- `[REF-LEGACY]`
- `[REF-CONTRACT]`

### 6.1 Marker Format

Each marker must include:

- migration goal,
- target location,
- iteration number,
- status (`planned`, `migrated`, `deprecated`).

Example:

```php
// [REF-MOD-CHATGPT]
// Goal: move to modules/chatgpt/services/ChatOrchestrator.php
// Iteration: 2
// Status: planned
```

## 7. Phase 1: Analysis (Read-Only)

### 7.1 Objective

Build a deterministic project map before moving code.

### 7.2 Mandatory Outputs

- full ChatGPT surface map (routes, controllers, assets, services, DB touchpoints),
- entrypoint map,
- runtime flow descriptions,
- risk register with severity.

### 7.3 Prohibitions

- no file moves,
- no renames,
- no logic edits.

## 8. Phase 2: Extraction

### 8.1 Objective

Move ChatGPT implementation into:

```text
modules/chatgpt/
  module.php
  manifest.php
  routes/
  controllers/
  services/
  providers/
  views/
  assets/
```

### 8.2 Strategy

Apply strangler pattern:

1. register module shell and routes,
2. migrate views/assets,
3. migrate API handlers/controllers,
4. migrate service logic.

### 8.3 Constraints

- Keep JSON contracts stable.
- Keep route behavior stable unless documented in `MODULE_CONTRACT.md`.
- Do not refactor unrelated modules.

## 9. Phase 2.5: Validation

Codex must run and log smoke checks in `SMOKE_TESTS.md`.

Minimum checks:

1. Open ChatGPT module from top navigation.
2. Send message.
3. Receive and render assistant response.
4. Reload and verify thread/session continuity.
5. Verify context save/load.
6. Verify graceful error handling (gateway unavailable, auth required, busy profile).

## 10. Phase 3: Integration and Cleanup

### 10.1 Objective

- finalize module integration in panel shell,
- remove migrated markers from core,
- keep only valid contracts/docblocks.

### 10.2 Cleanup Rules

- remove markers with `Status: migrated`,
- keep markers with `planned` only if explicitly justified in `REFACTOR_PROGRESS.md`,
- update architecture docs to final state.

## 11. Contract Standard

Functions touching ChatGPT flow should have concise docblocks:

```php
/**
 * Purpose:
 * Input:
 * Output:
 * Side effects:
 * Dependencies:
 */
```

## 12. Commit Policy

Commit format:

```text
[PHASE-X][SCOPE] Short description
```

Examples:

- `[PHASE-1][DOCS] Build ChatGPT surface map`
- `[PHASE-2][MODULE] Extract ChatGPT views and assets`
- `[PHASE-3][INTEGRATION] Wire module routes and cleanup markers`

## 13. Definition of Done

Done when all are true:

1. ChatGPT code is isolated under `modules/chatgpt/`.
2. Core panel contains only integration hooks, not ChatGPT business logic.
3. All required docs are updated and consistent.
4. Smoke tests pass and are documented.
5. No stale `[REF-*]` markers remain with `planned` status without justification.

## 14. Strict Prohibitions

Codex must not:

- rewrite the entire app outside agreed scope,
- delete code without traceable migration,
- change unrelated LinkedIn/scraper architecture,
- skip documentation updates per phase.

## 15. Work Ethos

Priority order:

1. clarity of ownership,
2. separation of concerns,
3. runtime stability,
4. optimization last.
