# Production deployment

## Current (Hostinger static + selective VPS)

| Layer | Where | Role |
|-------|--------|------|
| Static UI | Hostinger `/tcg/` | `index.html`, `client/js/`, art, i18n |
| SSE notify | VPS `:5001` via Apache `sync-stream` proxy | Seq bumps only ([`wrapped/tcg_sync.py`](../Chiichan/wrapped/tcg_sync.py)) |
| Game API | Hostinger PHP **or** VPS `:5003` | `api.php`, `account.php`, room JSON, SQLite |

### Phase 1 — SSE off Hostinger PHP (default)

Browsers open EventSource on **`https://stream.loveliveradio.ca/tcg/sync/stream`** (VPS nginx → `wrapped_api` `:5001`). No Hostinger PHP worker is held.

Fallbacks in order: Hostinger `./sync-stream` (if Apache `[P]` works) → `/wrapped/api.php?action=tcg_sync_stream` → short `poll=0`.

After deploy, confirm in Network: EventSource URL is `stream.loveliveradio.ca`. Hostinger CPU should drop during multiplayer evenings.

Client helper: `tcgSyncStatsSnapshot()` in DevTools. Verify script: `bash scripts/verify_sync_stream.sh`.

### Phase 2 — Overflow Plan B (Hostinger primary, VPS standby)

Hostinger remains the default game/account API. The VPS runs a **capped** Docker API (`compose.overflow.yaml`, 0.5 CPU / 512 MB) behind:

`https://stream.loveliveradio.ca/tcg/api/`

**Client behavior** ([`client/js/api-client.js`](../client/js/api-client.js)):

- Counts Hostinger timeouts / 5xx / 429.
- After a short streak, opens an overflow window (~2 minutes).
- **May** retry hub reads (`me`, decks, …) and **new** casual rooms/joins on VPS.
- **Never** migrates an in-progress Hostinger match (room is locked to its origin).
- **Ranked matchmaking stays on Hostinger** (shared queue must not split).
- DevTools: `tcgOverflowStats()`.

**Operator setup:**

```bash
# From lltcgweb checkout (Git Bash)
bash scripts/vps_overflow_up.sh
bash scripts/sync_overflow_db.sh   # seed/refresh accounts DB on VPS
# Optional cron on operator machine every 10–15 min: sync_overflow_db.sh
```

Do **not** upload `USE_VPS_API` for this mode — that marker is full cutover only.

### Phase 3 — Match-primary on VPS + Redis (architecture overhaul)

Authoritative live rooms move off Hostinger `games/*.json` flock onto VPS Redis (`TCG_GAME_STORE=redis`). PHP rules still run in the overflow/match Docker API (`compose.overflow.yaml` includes Redis).

**Client:** set `window.TCG_MATCH_API_PRIMARY = true` (or deploy a small bootstrap) so create/join/`action` use `TCG_OVERFLOW_ORIGIN`. Account, ranked queue, login bonus stay on Hostinger until a shared DB strategy exists.

**Operator:**

```bash
bash scripts/vps_overflow_up.sh   # pulls compose.overflow.yaml (redis + tcg-api)
# Confirm redis health + api.php?action=ping on :5003
```

**Realtime:** SSE notify remains on `wrapped/tcg_sync.py` (seq-only wake → client `get_state`). Optional later: WebSocket fan-out from the same VPS process if poll pressure remains — see `docs/overhaul/01-match-store.md` and Part 1C in `docs/OVERHAUL_PROGRESS.md`.

**Rollback:** `TCG_MATCH_API_PRIMARY=false`; Hostinger `TCG_GAME_STORE=file`.

### Hostinger-only deploy (no VPS API yet)

Continue using Chiichan `deploy-loveliveradio-ca.sh` with `LLR_TCG_ROOT` → this repo. Runtime dirs stay under `/tcg/` until Phase 2 cutover.

### Ops notes

- **Healthcheck:** `GET /api.php?action=ping`
- **Rollback Phase 2:** remove `USE_VPS_API` (Apache stops proxying API).
- **Rollback Phase 1 SSE:** clients auto-fall back to PHP proxy; or revert `.htaccess` RewriteRule.
- **Backups:** cron `data/tcg.db` on whichever host owns SQLite.
- **wrapped-api restart:** only after explicit operator confirmation (Chiichan VPS rules).
