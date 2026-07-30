# Production deployment

## Current (Hostinger static + selective VPS)

| Layer | Where | Role |
|-------|--------|------|
| Static UI | Hostinger `/tcg/` | `index.html`, `client/js/`, art, i18n |
| SSE notify | VPS `:5001` via Apache `sync-stream` proxy | Seq bumps only ([`wrapped/tcg_sync.py`](../Chiichan/wrapped/tcg_sync.py)) |
| Game API | Hostinger PHP **or** VPS `:5003` | `api.php`, `account.php`, room JSON, SQLite |

### Phase 1 — SSE off Hostinger PHP (default)

Deploy [`/.htaccess`](../.htaccess) so browsers open `EventSource('./sync-stream')` which Apache proxies to `http://VPS:5001/api/tcg/sync/stream` (no PHP worker).

Clients fall back to `/wrapped/api.php?action=tcg_sync_stream` only if the direct path fails.

After deploy, confirm in browser console / Network: EventSource URL is `/tcg/sync-stream` (not `wrapped/api.php`). Hostinger CPU should drop during multiplayer evenings.

### Phase 2 — Game API on VPS

1. On VPS, clone/sync **lltcgweb** and run:
   ```bash
   bash scripts/vps_tcg_api_cutover.sh
   # Optional seed: SYNC_DATA=1 bash scripts/vps_tcg_api_cutover.sh
   ```
2. Ensure `llr_auth.php` and `tcg_sync.local.php` exist on the VPS tree (same secrets as Hostinger).
3. Open firewall TCP **5003** from Hostinger (or bind via reverse proxy).
4. Health: `curl -fsS http://VPS:5003/api.php?action=ping`
5. On Hostinger, deploy `.htaccess` and an empty marker file `tcg/USE_VPS_API` (see `USE_VPS_API.example`).
6. Smoke-test login, CPU match, and one PvP room. Rollback = delete `USE_VPS_API` on Hostinger.

Docker layout:

| Service | Role |
|---------|------|
| `tcg-api` ([compose.prod.yaml](../compose.prod.yaml)) | PHP app on `:5003` |
| `wrapped-api` | Existing SSE hub on `:5001` |
| Hostinger Apache | TLS + static + `[P]` proxy |

Volumes (named): `data`, `games`, `experiment_decks`, `cardimg`.

### Hostinger-only deploy (no VPS API yet)

Continue using Chiichan `deploy-loveliveradio-ca.sh` with `LLR_TCG_ROOT` → this repo. Runtime dirs stay under `/tcg/` until Phase 2 cutover.

### Ops notes

- **Healthcheck:** `GET /api.php?action=ping`
- **Rollback Phase 2:** remove `USE_VPS_API` (Apache stops proxying API).
- **Rollback Phase 1 SSE:** clients auto-fall back to PHP proxy; or revert `.htaccess` RewriteRule.
- **Backups:** cron `data/tcg.db` on whichever host owns SQLite.
- **wrapped-api restart:** only after explicit operator confirmation (Chiichan VPS rules).
