# Agent Recap — Heat Liga (PHP)

Handoff notes for the next agent continuing work on the PHP app in this
folder. Session of 2026-11-13: Flask→PHP port, player leaderboard, roles
framework (dormant), JSON→SQLite storage migration.

## TL;DR

- **This folder is the active app.** The original Flask app in the parent
  directory (`../app.py`, `../index.html`, `../data.json`) is legacy — do NOT
  modify it ("PHP only!" was an explicit user instruction).
- The user has **no PHP installed** — the agent must verify all work via
  Docker (setup below). The user tests the UI in a browser at
  http://127.0.0.1:8000, sometimes *while the agent is working* — data file
  can change out from under you; re-read before writing.
- Live data: **`data.sqlite`** (SQLite). **`data.json.bak`** is the
  pre-migration backup and the restore source (real league data — protect it).

## Files

| file          | what                                                        |
| ------------- | ----------------------------------------------------------- |
| index.php     | entry point: serves index.html, routes any `.../api` path to api.php |
| api.php       | all backend logic: 17 actions, leaderboard, input validation, dispatch. Contains the commented-out roles framework |
| storage.php   | storage layer (SQLite). Contract: `storage_load(): array` / `storage_save(array $d): void`. Replace this file to swap databases |
| migrate.php   | one-time JSON → SQLite import: `php migrate.php [--force] [source.json]` |
| index.html    | SPA frontend, vanilla JS, Czech UI (copied from original, unchanged) |
| data.sqlite   | the database — all app data                                 |
| data.json.bak | backup of the pre-migration JSON (not read by the app)      |
| .htaccess     | Apache routing (for XAMPP/htdocs deployments)               |
| README.md     | run instructions                                            |

## How to run / test (no PHP on this PC)

```bash
# 1. Docker Desktop may be stopped — start it and wait for the engine:
powershell -NoProfile -Command "Start-Process 'C:\Program Files\Docker\Docker\Docker Desktop.exe'"
docker info   # retry until it works

# 2. Lint (no server needed):
MSYS_NO_PATHCONV=1 docker run --rm -v "C:\Users\lumi\NotProjects\HEAT\heat-php":/app -w /app php:8.2-cli php -l /app/api.php

# 3. One-shot scripts (migrate, dump DB):
MSYS_NO_PATHCONV=1 docker run --rm -v "C:\Users\lumi\NotProjects\HEAT\heat-php":/var/www/html -w /var/www/html php:8.2-cli php migrate.php --force data.json.bak

# 4. Run the app (bind-mounted — code edits are live immediately, no restart):
MSYS_NO_PATHCONV=1 docker run -d --rm --name heat-php -p 8000:8000 -v "C:\Users\lumi\NotProjects\HEAT\heat-php":/var/www/html -w /var/www/html php:8.2-cli php -S 0.0.0.0:8000 index.php

# Stop when done:
docker stop heat-php
```

Gotchas:
- **Git Bash mangles unix-style paths in docker args** (`-w /app` becomes
  `C:/Program Files/Git/app`). Prefix docker commands with
  `MSYS_NO_PATHCONV=1`.
- `php:8.2-cli` image has pdo_sqlite + sqlite3 enabled (verified).
- PHP built-in server: no opcache — editing api.php/storage.php is picked up
  on the next request.
- For tests: write Python scripts to a **file** and run them (long inline
  heredocs break bash quoting). Python 3 is available on the host; curl too.
- SQLite runs over the Docker-for-Windows bind mount: keep default journal
  mode + `busy_timeout` (already set); don't enable WAL over the share.

## Domain rules (scoring — the part most likely to get changed)

- Points by **rank** (not position): 1st..6th → `9 6 4 3 2 1`, else 0.
  `POINTS_BY_RANK` in api.php.
- **Competition ranking**: players sharing a position share the rank
  (rank = 1 + number of *distinct* better positions). Unplaced players
  (position < 1) get rank 0, 0 points, listed after placed ones.
- **Team board** (`board`): per match only the team's best-placed member's
  points count; sum over the team's **7 best matches**; sort by points desc,
  then name asc (case-insensitive).
- **Player board** (`playerBoard`): sum of the player's **7 best** match
  points; `matches` column = total matches participated (NOT the capped
  count); same sort as team board.
- **Match locking**: manual `locked`/`unlocked` flags (unlock overrides
  date); otherwise a match is auto-locked when its date < (today − 1 day).
  Locked matches reject edits AND deletion with 400 "Match is locked".
- `team_delete`: members are NOT orphaned — each gets an own team named
  after the player (case-insensitive reuse of an existing team name).
- **IDs are never reused**: seq counters persist (SQLite `meta` table, key
  `seq`). Currently `seq.team = 12` (teams created+deleted during testing) —
  correct behavior, don't "fix" it.
- `match_add` pre-enrolls all current players at position 0.
- `match_set_position`: 0 = unplace; same position twice → 400
  "Position already taken".
- Deleting a non-existent player → **200 no-op** (parity with the original
  Flask app — intentional, don't "fix" it).
- Names trimmed + truncated to 40 chars; upgrades: max 8 unique ints,
  1–4 digits, stored sorted.
- UI language: Czech. Keep new UI text in Czech.

## API contract

- `POST /api` with JSON body `{action, ...}` (form POST fallback;
  `GET /api?action=...` also works — action from query string takes
  precedence over body).
- Responses: `200 {"ok":true, ...}` or `400 {"ok":false,"error":...}`.
- Actions: `state`, `team_add`, `team_rename`, `team_delete`, `player_add`,
  `player_update`, `player_delete`, `map_add`, `map_delete`, `match_add`,
  `match_update`, `match_delete`, `match_add_player`, `match_set_position`,
  `match_remove_player`, `match_unlock`, `match_lock`, `wipe`.
- `state` returns: `teams`, `players`, `maps`, `matches` (+computed
  `locked`), `board`, `playerBoard`, `ranked` (per match: rows with
  playerId/position/rank/points, keyed by match id).

## Roles framework (dormant — built, tested, then commented out on request)

Purpose: plug-in for a **bigger app** the user is building.
Location: `api.php` — two blocks wrapped in:

```
/* ROLES-FRAMEWORK:START - disabled. To enable, delete this line and the matching :END line.
...
ROLES-FRAMEWORK:END - delete this line and the matching :START line to enable. */
```

**To re-enable: delete the 4 marker lines (2 per block).** Verified to lint
and behave correctly.

Semantics (tested: 16/16 scenarios):
- `modify` → all actions; `view-only` → only `state`; no role assigned →
  open access; any other role value → 403 (fail-closed).
- `current_role()` is a **test stub** reading `?role=` / `X-Role` header.
  The bigger app MUST replace it with server-side auth (session/SSO) — never
  trust client input.
- Frontend does NOT hide edit controls for view-only (API enforces 403 →
  error toast). Hiding UI client-side is a possible follow-up.

## Data-safety rules (learned the hard way)

- `data.json.bak` / `data.sqlite` contain the **user's real league data**.
  Current state: 5 teams (a, asd, s, e, w), 5 players, 2 maps, 2 matches
  (dated 2026-11-13). Board: a 15, w 11, asd 8, e 0, s 0. Player board:
  a 15, w 11, asd 8, s 8, e 6.
- Before any fixture/wipe test: snapshot (cp / hash), test, **restore**
  (`php migrate.php --force data.json.bak`), verify the restore.
- When the storage backend switches, do it while the server is stopped so no
  request lands between the old and new stores.
- After `wipe` in tests, always re-import from the backup.

## Open items / likely next steps

1. **Plug the roles framework into the bigger app** (re-enable markers,
   replace `current_role()` stub with real auth).
2. Possibly swap SQLite for another DB later — the seam is `storage.php`
   (keep the `storage_load`/`storage_save` contract).
3. Optional: client-side UI adaptation for view-only (hide/disable edit
   controls) — would need the role exposed to the frontend (e.g. in `state`).
4. Flask original is untouched legacy; decide whether to retire it.
5. No real auth, no deployment story beyond `php -S` / htdocs — both
   delegated to the bigger app.

## What was done this session (chronological)

1. Ported the Flask app 1:1 to PHP (same endpoints, same error strings,
   same data semantics); frontend copied verbatim. Verified end-to-end in
   Docker (the user has no PHP).
2. Added per-player leaderboard table (second card on the Žebříček tab).
3. Applied the "7 best matches" rule to player points too (user request).
4. Built + tested the roles framework (modify / view-only), then commented
   it out per user request ("to be plugged in bigger app").
5. Migrated storage from data.json to SQLite (schema in storage.php),
   one-time migration of live data, full regression (35/35), data.json
   kept as data.json.bak.

## Verification bar (what worked for this user)

The user cannot test PHP themselves, so every change was verified before
reporting: `php -l`, running server, API-level tests (python + urllib),
board values recomputed independently, error paths, and data restored after
destructive tests. Keep doing this — it was implicitly accepted.
