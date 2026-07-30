# lltcgweb

An English web version of a certain idol tabletop game.

- English and Japanese UI (`i18n.js`, `tutorial_ja.json`)
- Interactive UI and animations
- 2000+ cards
- Unique skills fully implemented
- Deck builder with autobuild
- Expand your starter deck with more cards from daily booster packs
- Ranked and unranked online PvP
- CPU opponent with three difficulty settings
- Interactive how-to-play tutorial

Playable at [https://loveliveradio.ca/tcg](https://loveliveradio.ca/tcg)

## Debugging tools

Launch with `?debug` ([loveliveradio.ca/tcg/?debug](https://loveliveradio.ca/tcg/?debug)) for extra QA tooling:

- **Card Effect Test** — pick a card by ID and jump into a CPU scenario with that card seeded (conditions are best-effort).
- **Replay** — save a match replay from the sidebar or win screen; open **Replay Viewer** from the hub (signed-in library) or import a `.json` file on the replay screen. Step through actions, then take control as the saver vs CPU at the end if desired.
- **Debug log** — save the full match log as `.txt` or copy the last 200 lines from the in-game debug row.

---

# Repository guide

What lives in **git** (source + card data). Runtime state, art, and secrets stay on the server — see [Not in git](#not-in-git-by-design) and [docs/RUNTIME.md](docs/RUNTIME.md).

---

## Quick map — where to edit what

| Goal | Start here |
|------|------------|
| Hub, board, animations, CPU helpers (large inline areas) | `index.html` |
| HTTP client, poll/SSE sync, state apply, prompts, CPU entry | `client/js/*.js` (see below) |
| Copy / locales | `i18n.js`, `log_i18n.js`, `tutorial_ja.json` |
| Rules engine, card effects, prompts (server) | `effects.php`, `src/Game/*`, set-specific `*_effects.php` |
| Path, CORS, rate limits, public errors | `config/*.php` |
| Card definitions & abilities | `cards.json` |
| Interactive tutorial script | `tutorial_guide.json`, `client/js/tutorial-interactive.js` |
| Legacy slideshow tutorial build | `tutorial.json`, `tutorial_script.json`, `gen_tutorial_guide.py` |
| Deck legality | `deck_validate.php` |
| Account / collection / boosters / ranked / replays | `account.php`, `db.php`, `booster.php`, `matchmaking.php` |
| Multiplayer rooms, actions, spectate | `api.php`, `spectate.php`, `casual_matchmaking.php` |
| Automated regression | `tests/`, `scripts/`, `composer.json` |

---

## Frontend modules (`client/js/`)

Loaded from `index.html` after `i18n.js`. Prefer new UI logic here instead of growing the inline bundle.

| File | Role |
|------|------|
| `api-client.js` | `apiPost`, account fetch helpers, API error popup, sync metadata |
| `game-sync.js` | Poll loop, SSE sync stream, `pullLatestState`, `startPoll` / `stopPoll` |
| `state-apply.js` | `onState`, pending state queue, presentation gates |
| `prompt-renderer.js` | Prompt UI, submit guards, `renderPrompt` |
| `cpu-ai.js` | CPU prompt/action entry (handlers may still live inline) |
| `replay-debug.js` | Replay export/save helpers |
| `tutorial-interactive.js` | Live interactive tutorial (`tutorial_guide.json`) |

---

## Config & extracted PHP

| Path | Role |
|------|------|
| `config/paths.php` | `tcgPath()`, runtime dir constants (`TCG_DATA_DIR`, `TCG_GAMES_DIR`, …) |
| `config/cors.php` | Browser origin allowlist (`TCG_CORS_ORIGINS`) |
| `config/rate_limit.php` | Per-action file-based rate limits |
| `config/errors.php` | Production-safe API error messages (`TCG_DEBUG`, `TCG_PRODUCTION`) |
| `src/Game/*` | Extracted rules helpers (prompts, abilities, live modifiers, zone moves, …) |
| `src/Db/Migrator.php` | Versioned SQLite migrations under `migrations/` |
| `migrations/*.sql` | Schema migration files |

Root `*.php` entry points (`api.php`, `account.php`, …) remain the stable URLs on Hostinger.

---

## Runtime directories (on server, mostly not in git)

| Directory | Role |
|-----------|------|
| `data/` | SQLite (`tcg.db`), rate-limit state; **HTTP blocked** via `data/.htaccess` |
| `games/` | Live match JSON per room; **HTTP blocked** via `games/.htaccess` |
| `experiment_decks/` | Guest deck-experiment saves; **HTTP blocked** via `experiment_decks/.htaccess` |
| `cardimg/` | Cached card faces (server-resolved URLs only) |
| `exports/` | Operator exports (optional) |
| `assets/`, `bg/`, `icons/` | UI art and audio — not in git |

Override paths with env vars — see [`.env.example`](.env.example) and [docs/RUNTIME.md](docs/RUNTIME.md).

---

## Core application (PHP + client shell)

| File | Role |
|------|------|
| **`index.html`** | Client shell: screens, board DOM, CSS, large game loop; loads `client/js/*` |
| **`api.php`** | Game server: rooms, long-poll / state, actions, catalog, experiment decks, replay API, presence |
| **`effects.php`** | Main rules engine; includes `*_effects.php` and `src/Game/*` |
| **`cards.json`** | Master card catalog: stats, text, `abilities[]`, starters, image URLs |
| **`i18n.js`** | UI strings (en/ja), locale helpers, tutorial dialogue lookup |
| **`spectate.php`** | Spectator join/list helpers used by `api.php` |
| **`tcg_sync.php`** | PvP SSE notify ticket (Hostinger → VPS wrapped API) |
| **`subunits.php`** | JP ↔ EN subunit name map |

### Account, collection, ranked

| File | Role |
|------|------|
| `account.php` | Profile, starter, collection, boosters, presets, ranked queue, replay library, reset |
| `db.php` | SQLite schema + helpers |
| `booster.php` | Booster catalog, pull rates, `open_booster` |
| `deck_validate.php` | Legal deck rules (48 / 12 / 12, copy limits, ownership) |
| `deckgen.php` | Random / auto-build decks |
| `matchmaking.php` | Ranked queue and Elo pairing |
| `ranked_room.php` | Ranked room creation and rating on finish |
| `casual_matchmaking.php` | Casual queue |
| `experiment_decks.php` | Guest deck experiment save/load |
| `replay.php` | Replay export, start, step API |
| `llr_auth_load.php` | Loads production `llr_auth.php` or `llr_auth_offline.php` |

### Card images

| File | Role |
|------|------|
| `cardimg.php` | Serves cached faces from `cardimg/` |
| `cardimg_cache.php` | Cache helpers; `cache_card_image` resolves URLs from `cards.json` only |

### Debug / test harness

| File | Role |
|------|------|
| `debug_card_test.php` | `?debug` single-card CPU scenarios |

---

## Effect modules (`*_effects.php`)

Split by product line / import batch. Each is `require_once` from `effects.php`.

| File | Typical scope |
|------|----------------|
| `nijigasaki_effects.php` | Nijigasaki general |
| `n_bp5_effects.php` | Nijigasaki BP5 |
| `hs_bp6_effects.php` | Hasunosora BP6 |
| `hs_pb1_effects.php` | Hasunosora premium PB1 |
| `hs_cl1_effects.php` | Hasunosora CL1 |
| `s_bp5_effects.php` | Sunshine BP5 |
| `s_bp6_effects.php` | Sunshine BP6 |
| `s_sd1_effects.php` | Sunshine start deck |
| `sp_bp2_effects.php` | Superstar BP2 |
| `sp_bp5_effects.php` | Superstar BP5 |
| `pl_muse_gap_effects.php` | μ's gap / misc PL |
| `pl_sp_sd2_effects.php` | Superstar SD2 |
| `batch99_effects.php` | Late import batch |

---

## Data JSON (in git)

| File | Role |
|------|------|
| `pack_listings.json` | Pack wrapper art URLs (`booster.php`) |
| `playmat_zones.json` | Board zone hitboxes (`index.html`) |
| `tutorial_guide.json` | Interactive tutorial steps (live mode) |
| `tutorial_ja.json` | Japanese tutorial bubble text keyed by step id |
| `tutorial.json` | Legacy built tutorial (slideshow / embedded states) |
| `tutorial_script.json` | Source for rebuilding `tutorial.json` locally |

---

## Not in git (by design)

| Category | Examples |
|----------|----------|
| **Game art & audio** | `assets/`, `bg/`, `icons/`, `cardimg/`, root `*.png` / `*.jpg` / `*.m4a` |
| **Runtime state** | `data/tcg.db`, `games/*.json`, `experiment_decks/*.json`, `exports/` |
| **Secrets & deploy config** | `llr_auth.php`, `tcg_sync.local.php`, `.env`, `.env.deploy` |
| **Local dev tooling** | `scripts/`, `tests/`, `*.py`, operator scratch files |

See `.gitignore` for the full list.

---

## Local development

```bash
# Docker (recommended)
docker compose up
# http://localhost:8080/index.html

# PHP built-in server (fallback)
php -S localhost:8080
```

**Verification before deploy:**

```bash
composer install
composer test
php scripts/validate_json.php
php scripts/validate_cards.php
bash scripts/lint_php.sh
node scripts/validate_index_js.mjs   # optional: index.html + client/js sanity
```

See [docs/RUNTIME.md](docs/RUNTIME.md), [docs/SECURITY.md](docs/SECURITY.md), and [docs/DEPLOY.md](docs/DEPLOY.md).

Guest lobby, CPU, tutorial, and `?debug` work without accounts. Collection, boosters, ranked, and replay library need a writable `data/` directory and art under `assets/`, `bg/`, `icons/`, and `cardimg/`.

**Typical effect change:**

1. Edit ability in `cards.json`.
2. Implement or adjust handler in `effects.php`, `src/Game/*`, or the set’s `*_effects.php`.
3. Mirror prompt UX in `client/js/prompt-renderer.js` / `index.html` if the server adds a new `pending_prompt.type`.
4. Add or extend a PHPUnit test under `tests/`.
5. Test via guest CPU match, `?debug` Card Effect Test, or a golden replay fixture.

---

## Deploy (loveliveradio.ca)

Production static/PHP deploy runs from the **Chiichan** repo: `scripts/deploy-loveliveradio-ca.sh`.

| Setting | Meaning |
|---------|---------|
| `LLR_TCG_ROOT` | Path to this repo (default: `../lltcgweb` next to Chiichan) |
| `LLR_SITE_FILES` | Space-separated **remote** paths under `tcg/` (e.g. `tcg/index.html tcg/client/js/game-sync.js`) |
| `LLR_LLTCGWEB_COMMIT_SUMMARY` | One-line subject for the post-upload GitHub commit here |
| `LLR_SKIP_LLTCGWEB_PUSH=1` | Hostinger-only upload; skip git push |
| `LLR_SKIP_TCG_CORE=1` | Skip auto core bundle (not recommended) |

**Auto core bundle:** whenever `LLR_SITE_FILES` includes any `tcg/…` path, Chiichan also uploads everything in `Chiichan/scripts/tcg_deploy_core_manifest.txt` — auth loaders, `account.php`, `config/*`, runtime `.htaccess` files, `i18n.js`, SFX manifest, etc. That keeps partial deploys from leaving `api.php` without `config/errors.php` or `llr_auth_load.php`.

**Example (frontend + sync):**

```bash
export LLR_LLTCGWEB_COMMIT_SUMMARY='Fix poll backoff'
export LLR_SITE_FILES='tcg/index.html tcg/client/js/game-sync.js tcg/client/js/api-client.js'
./scripts/deploy-loveliveradio-ca.sh
```

**Do not upload:** `tcg/games/*.json`, `tcg/data/*.db`, or other live runtime state.

**On the host:** ensure `data/`, `games/`, `experiment_decks/`, and `cardimg/` are writable; populate art dirs separately. Ship `games/.htaccess` and `experiment_decks/.htaccess` via the core bundle (or explicit `LLR_SITE_FILES`).

**Docs-only changes** (this README, `docs/*`) are not served from Hostinger but should still be pushed to GitHub:

```bash
# From Chiichan repo root
LLR_LLTCGWEB_REPO_FILES='README.md docs/DEPLOY.md' \
LLR_LLTCGWEB_COMMIT_SUMMARY='Update deploy docs' \
python scripts/lltcgweb_git_push.py
```

Future **production Docker** layout and VPS API cutover are in [docs/DEPLOY.md](docs/DEPLOY.md).

### Multiplayer sync (SSE via VPS)

PvP uses **Server-Sent Events**. Browsers connect to **`/tcg/sync-stream`** (Hostinger Apache proxies to VPS `:5001` — **no PHP worker**). Fallback: `wrapped/api.php?action=tcg_sync_stream`. Game rules and room JSON stay on Hostinger `tcg/api.php` until Phase 2 API cutover.

**On Hostinger (`tcg/`):** copy [`tcg_sync.local.php.example`](tcg_sync.local.php.example) to gitignored `tcg_sync.local.php`:

- `TCG_SYNC_PUBLISH_URL` — VPS notify URL (e.g. `http://YOUR_VPS:5001/api/tcg/sync/notify`)
- `TCG_SYNC_INTERNAL_TOKEN` — same as `LLR_SITE_INTERNAL_TOKEN` in Chiichan `wrapped/api.php`
- `TCG_SYNC_SHARED_SECRET` — shared hex secret; must match VPS

**On VPS:** Chiichan `wrapped/tcg_sync.py` + `wrapped/wrapped_api.py`; set `TCG_SYNC_SHARED_SECRET` on the `wrapped-api` unit. Restart **`wrapped-api`** after deploy (operator confirmation required).

If sync is unset, clients fall back to short `poll=0` loops automatically (no 25s long-poll).

---

## License

This project is licensed under the [MIT License](LICENSE).
