# TCG Architecture Overhaul — Progress

Phased plan: Networking → Frontend → Rules IR.  
Defaults: PHP rules on VPS + Redis rooms; native Web Components; Hostinger kept for stateless hub/account.

| Phase | Status | Notes |
|-------|--------|-------|
| Part 0 — Tracker + design stubs | Done | `docs/overhaul/*` |
| Part 1A — GameStore abstraction | Done | `TCG_GAME_STORE=file\|redis`, `src/Game/Store/*` |
| Part 1B — VPS match-primary cutover | Done (flagged) | Client `TCG_MATCH_API_PRIMARY`; compose Redis |
| Part 1C — Realtime (SSE / WS note) | Done | Keep SSE; see `docs/overhaul/04-realtime.md` |
| Part 1D — Hostinger PHP scope | Ready | Flip primary flag + VPS Redis; Hostinger actions off |
| Part 2A — Extract CSS / JS from shell | Done | `client/css/shell-all.css`, `board-render.js`, `cpu-loop.js` |
| Part 2B — Zone web components | Done | `<ll-*>` in `client/js/components/board-zones.js` |
| Part 2C — Playwright smoke | Done | `node scripts/overhaul_smoke.mjs` |
| Part 3A — Ability IR lint | Done | `scripts/validate_ability_ir.php` in CI |
| Part 3B — Registry migration | Seeded | Draw family on `EffectHandlers`; further types migrate the same way (no new batch files) |

## Definition of done (by pillar)

### Networking
- [x] GameStore abstraction + Redis backend behind env flag
- [x] Client can route match API to stream (`TCG_MATCH_API_PRIMARY`)
- [ ] Operator flip: peak-hour match `action` on VPS only (needs Redis up + flag)
- [x] PHPUnit: file store unit tests (+ Redis when available)

### Frontend
- [x] `index.html` line count reduced (~40k → ~26k) via CSS/JS extraction
- [x] Board CSS in `client/css/`; paint in `board-render.js` (not sync modules)
- [x] Playwright smoke green (`overhaul_smoke.mjs`)

### Rules IR
- [x] Ability IR lint in CI
- [x] High-frequency draw types via `EffectRegistry` → `EffectHandlers`
- [ ] Continue migrating WR / grant / blade; no new `*_effects.php` for routine cards

## Cutover / rollback

- Feature flags: `TCG_GAME_STORE`, `TCG_MATCH_API_PRIMARY` / overflow origin
- Rollback match API: `TCG_MATCH_API_PRIMARY=false`; Hostinger `TCG_GAME_STORE=file`
- VPS service restarts require explicit operator OK (Chiichan VPS rules)

## Changelog

| Date | Slice |
|------|-------|
| 2026-08-02 | Part 0 docs created |
| 2026-08-02 | Part 1 GameStore + Redis + match-primary client flag + SSE notes |
| 2026-08-02 | Part 2 CSS/JS extract, zone web components, Playwright smoke |
| 2026-08-02 | Part 3 Ability IR lint + EffectRegistry draw handlers |
| 2026-08-02 | Cursor rules updated (`lltcgweb-overhaul.mdc` + architecture/frontend/php/cards) so agents do not regress |
