# TCG Architecture Overhaul — Progress

Phased plan: Networking → Frontend → Rules IR.  
Defaults: PHP rules on VPS + Redis rooms; native Web Components; Hostinger kept for stateless hub/account.

| Phase | Status | Notes |
|-------|--------|-------|
| Part 0 — Tracker + design stubs | Done | `docs/overhaul/*` |
| Part 1A — GameStore abstraction | Done | `TCG_GAME_STORE=file\|redis`, `src/Game/Store/*` |
| Part 1B — VPS match-primary cutover | Done (flagged) | `runtime-flags.js` + `TCG_MATCH_API_PRIMARY` |
| Part 1C — Realtime (SSE / WS note) | Done | Keep SSE; see `docs/overhaul/04-realtime.md` |
| Replay frames (schema v2) | Done | Seek installs board snapshots; see `docs/overhaul/05-replay-frames.md` |
| Part 1D — Hostinger PHP scope | Done (cutover) | `MATCH_WRITES_DISABLED` + `.htaccess` SetEnv; client match-primary on |
| Part 2A — Extract CSS / JS from shell | Done | `client/css/shell-all.css`, `board-render.js`, `cpu-loop.js` |
| Part 2B — Zone web components | Done | `<ll-*>` in `client/js/components/board-zones.js` |
| Part 2C — Playwright smoke | Done | `node scripts/overhaul_smoke.mjs` |
| Part 3A — Ability IR lint | Done | `scripts/validate_ability_ir.php` in CI |
| Part 3B — Registry migration | Ongoing | draw + grant_hearts + blade_bonus + blade_per_hand_cards + grant_live_score_if_success |

## Definition of done (by pillar)

### Networking
- [x] GameStore abstraction + Redis backend behind env flag
- [x] Client can route match API to stream (`TCG_MATCH_API_PRIMARY` / `runtime-flags.js`)
- [x] Hostinger match-write kill switch (`TCG_HOSTINGER_MATCH_WRITES`)
- [x] Redis integration test (skips without `TCG_REDIS_URL`) + `verify_match_primary.sh`
- [x] Operator flip: VPS Redis + match-primary client + Hostinger writes disabled

### Frontend
- [x] `index.html` line count reduced (~40k → ~26k) via CSS/JS extraction
- [x] Board CSS in `client/css/`; paint in `board-render.js` (not sync modules)
- [x] Playwright smoke green (`overhaul_smoke.mjs`)

### Rules IR
- [x] Ability IR lint in CI
- [x] High-frequency draw + grant/blade types via `EffectRegistry` → `EffectHandlers`
- [ ] Continue migrating WR / remaining grant/blade variants; no new `*_effects.php` for routine cards

## Cutover / rollback

- Flags: `TCG_GAME_STORE`, `client/js/runtime-flags.js` (`TCG_MATCH_API_PRIMARY`), `TCG_HOSTINGER_MATCH_WRITES`
- Readiness: `bash scripts/verify_match_primary.sh` (read-only)
- Rollback: `DEFAULT_MATCH_API_PRIMARY = false`; Hostinger `TCG_HOSTINGER_MATCH_WRITES=1` or unset; `TCG_GAME_STORE=file`
- VPS service restarts require explicit operator OK (Chiichan VPS rules)

## Changelog

| Date | Slice |
|------|-------|
| 2026-08-02 | Part 0 docs created |
| 2026-08-02 | Part 1 GameStore + Redis + match-primary client flag + SSE notes |
| 2026-08-02 | Part 2 CSS/JS extract, zone web components, Playwright smoke |
| 2026-08-02 | Part 3 Ability IR lint + EffectRegistry draw handlers |
| 2026-08-02 | Cursor rules updated (`lltcgweb-overhaul.mdc` + architecture/frontend/php/cards) so agents do not regress |
| 2026-08-02 | Remaining: Hostinger kill switch, runtime-flags, Redis test, verify script, grant/blade handler migration |
| 2026-08-02 | Production cutover: vps_overflow_up (Redis), DEFAULT_MATCH_API_PRIMARY=true, MATCH_WRITES_DISABLED |
