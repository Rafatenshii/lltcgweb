# PBSP02 — Premium Booster Superstar!! DUO

Official expansion code: **PBSP02**  
JP product name: `プレミアムブースター ラブライブ！スーパースター!! DUO`  
Card list API: `cardsearch_ex?expansion=PBSP02&view=text&page=N` (9 pages, **122 cards**)

## Card prefixes

| Prefix | Role |
|--------|------|
| `PL!SP-pb2-*` | **New DUO cards** (members, lives, energy, parallels) — official numbering |
| `PL!SP-bp2-*` | **SRL live reprints** only (`023`–`027` in this box) |
| `PL!SP-bp1-*` / `PL!SP-sd1-*` | Reprints / alternate rarities from prior products |

> **Note:** Early drafts used `PL!SP-bp2-001` style numbers; the live official IDs are `PL!SP-pb2-*` (matching vol.1 `pb1`). Legacy `PL!SP-bp2-*` rows in `cards.json` (wrong numbering) should be ignored — use scrape output.

Image folder on official CDN: **`PBSP02/`** (paths may also appear under `PBSP/` for parallels).

Booster box id in `booster.php`: **`pb_superstar_duo`**

## Tooling

### Scrape expansion → Chiichan JSON

From **lltcgweb** repo:

```bash
python tools/scrape_expansion.py PBSP02
# optional: --output-dir /path/to/Chiichan
```

Writes `Chiichan/all_cards_pbsp02_<timestamp>.json`.

### Import JSON → Chiichan `database.db`

From **Chiichan** repo:

```bash
python tools/import_cards_json_to_db.py all_cards_pbsp02_*.json
# update existing rows after re-scrape:
python tools/import_cards_json_to_db.py all_cards_pbsp02_*.json --force-update
```

### Import DB → `lltcgweb/cards.json`

From **lltcgweb** repo:

```bash
python import_from_db.py --prefix "C:/Users/super/OneDrive/Documents/GitHub/Chiichan/database.db" "PL!SP-pb2-"
python import_from_db.py --refresh "C:/Users/super/OneDrive/Documents/GitHub/Chiichan/database.db" "PL!SP-pb2-"
python import_from_db.py --refresh "C:/Users/super/OneDrive/Documents/GitHub/Chiichan/database.db" "PL!SP-bp2-"
```

Ability routing: `sp_bp2_abilities.py` → `abilities_for_sp_bp2()` (English in `_SP_BP2_TRANSLATIONS`).

### Ability bracket audit (2026-07-01)

EN skill lines must use **official bracket labels only** (`[Activated]`, `[On Enter]`, `[Always]`, `[Automatic]`/`[Auto]`, `[Live Start]`, `[Live Success]`, slot tags like `[Left Side]`, etc.). Invalid engine-style labels (`[On Play]`, `[Continuous]`) were removed.

Verified all 46 ability-bearing `PL!SP-pb2-*` bases (+ `PL!SP-bp2-023`–`025` SRL) against official JP texticon HTML from the PBSP02 scrape. Key fixes:

| Card | Was | Now |
|------|-----|-----|
| `pb2-000` | `[Activated]` / `[On Play]` (legacy) | `[Always]` + `[On Enter]` |
| `pb2-002` | `[On Enter]` | `[Activated] [Once per turn]` |
| `pb2-004`, `007`, `008` | wrong trigger bracket | `[Live Success]` |
| `pb2-005` | missing inherit line | `[On Enter]` + `[Always]` |
| `pb2-009`, `029` | single bracket | `[On Enter] / [Live Start]` |
| `pb2-010` | `[On Enter]` | `[Live Start]` + `[Live Success]` |
| `pb2-011` | `[Auto]` only | `[Automatic] [Once per turn]` + `[Live Start]` |
| `pb2-018`, `025` | `[Activated]` or `[On Enter]` wrong | `[Live Start]` / `[On Enter]` per official |
| `pb2-046` | two `[Always]` | `[Always]` (negates Live Start) + `[Live Success]` |

Audit helpers: `tools/audit_pb2_brackets.py`, `tools/audit_pb2_official_icons.py`. Defensive legacy aliases: `index.html` (`[On Play]`→`[Always]`, `[Continuous]`→`[Always]`), `tcg_cards_en_bridge.py` (`SKILL_BRACKET_RE`).

After `_SP_BP2_TRANSLATIONS` edits: `import_from_db.py --refresh`, `sync_discord_card_skills.py --write-only --prefix PL!SP-pb2-`, deploy `tcg/cards.json` + `tcg/index.html`, then VPS `!!tcgimport_app_dual`.

## Import status (2026-07-01)

| Scope | Count | Notes |
|-------|------:|-------|
| **PBSP02 expansion total** | 122 | Official cardsearch |
| **DUO-tagged in `cards.json`** | **122/122** | Full pool — `booster_pack` = `プレミアムブースター ラブライブ！スーパースター!! DUO` |
| **`PL!SP-pb2-*` new DUO cards** | 93 | All rarities / parallels |
| **Unique pb2 base numbers** | 51 | `000`–`050` + extras |
| **Bases with engine abilities** | 46 | All non-vanilla bases wired |
| **Bases text-only / no ability** | 5 | `034`, `038`, `039`, `042`–`044` (vanilla) |
| **Bases blocked** | 0 | Batch 3 complete |

### Reprint import (final 13, audit 947a091c)

Scrape JSON was already in `database.db`; imported into `cards.json` via `import_from_db.py --prefix`:

| Source set | Card numbers | Ability reuse |
|------------|--------------|---------------|
| **SAPPHIRE MOON** (`bp4`) | `PL!SP-bp4-023-SRL` … `030-SRL` (+ `024-SECL`) | `abilities_for_sp_bp4()` — same base numbers as `PL!SP-bp4-*-L` |
| **Premium vol.1** (`pb1`) | `PL!SP-pb1-023-SRL` … `026-SRL` | `abilities_for_sp_pb1()` — same base numbers as `PL!SP-pb1-*-L` |

Images use **`PBSP02/`** CDN paths; `booster_pack` set to DUO so `tcgBuildBoxPools()` includes them in `pb_superstar_duo`.

### Batch table

| Batch | Base numbers | Status |
|-------|----------------|--------|
| **1** | `pb2-000`–`pb2-030` | **Done** — batch 3 handlers for 003–006, 008–009, 011, 022, 026 |
| **2** | `pb2-031`–`pb2-050` | **Done** — includes `046` Butterfly Wing |
| **SRL lives** | `bp2-023`–`027`, `bp4-023`–`030`, `pb1-023`–`026`, `bp1/sd1` in box | **Done** — inherit bp2/bp4/pb1/sd1 handlers |
| **Reprints** | `bp1-*`, `sd1-*` members/lives | **Done** — inherit existing pb1/sd1 handlers |

### Batch 3 handlers (`sp_bp2_effects.php`)

| Base | Effect types |
|------|----------------|
| `pb2-003` | `score_if_moved_by_group_effect` (member Live Success; Liella effect move) |
| `pb2-004` | `draw_if_live_zone_score_up_or_yell_score_icon` |
| `pb2-005` | `stack_baton_wr_member_under`, `inherit_stacked_group_abilities` |
| `pb2-006` | `cost_per_stacked_group_member`, `auto_stack_wr_group_member_under` |
| `pb2-008` | `score_per_yell_group_no_blade` (deferred after Yell reveal) |
| `pb2-009` | `optional_wait_self_opp_heart_gap` (printed Blade gap, not hearts) |
| `pb2-011` | `auto_on_center_move_choose` (+ optional position change) |
| `pb2-022` | `auto_on_move_to_center_subunit_heart` |
| `pb2-026` | `hearts_if_active_energy` |
| `pb2-046` | `continuous_negate_stage_member_abilities`, `live_score_if_stage_has_ability_members` |

## Booster simulation (`pb_superstar_duo`)

Box id: **`pb_superstar_duo`** · kind: **`pb_duo`**

| | DUO (PBSP02) | Vol.1 (`pb_superstar`) |
|--|--|--|
| Cards / pack | **3** | 5 |
| Packs / box | **20** | 30 |
| Structure | Slot 1: N/R · Slots 2–3: guaranteed holo | 2×N, 1×R, 1× base, 1× foil |
| Box bonus | 2 all-holo packs per box | God pack (~1/480) if LLE in pool |
| Pool (2026-07-01) | **122 / 122** official PBSP02 | 84 |

### Pool completeness

All **122** official `cardsearch_ex?expansion=PBSP02` cards are in the pull pool via `booster_pack` = `プレミアムブースター ラブライブ！スーパースター!! DUO`.

Previously missing reprint SRL/SECL rows (`PL!SP-bp4-*-SRL`, `PL!SP-pb1-*-SRL`, `PL!SP-bp4-024-SECL`) are imported from Chiichan DB / scrape.

`tcgBuildBoxPools()` normalizes rarities for DUO-specific keys:

- `P＋` / `-P2` suffix → **`P+`**
- `-PE2` suffix → **`PE+`**
- `-SECL` / `-SRL` / `-DUO` / `-PP` / `-SECS` suffixes → matching pool band

### Holo weights (slots 2–3)

Tiered like `pb_superstar` foil slot but with DUO rarities: **SRL** (bulk holo, replaces vol.1 **SRE**), **PP**, **P+**, **PE+**, **SECL**, **LLE**, chase **DUO** / **SECS** / **SECE**. Pity counters scale to **20 packs/box** (not 30).

### UI

- **Box picker** (`booster.php` `image`): Official 3D box render from cardlist (`LLC_-PB06_box_image.png` on llofficial-cardgame.com).
- **Pack open** (`pack_images`): Amazon pack wrapper `pb_superstar_duo-a.jpg` (unchanged).

## Operator deploy checklist

1. Scrape + DB import if card data changed.
2. `import_from_db.py --refresh` for touched prefixes.
3. Deploy Hostinger: `tcg/cards.json`, `tcg/booster.php`, `tcg/effects.php`, `tcg/sp_bp2_effects.php`, `tcg/api.php`, `tcg/index.html`.
4. Hard-refresh https://loveliveradio.ca/tcg after deploy.

**Do not deploy** `tcg/games/*.json` or `tcg/data/*.db`.

## References

- Product page: https://llofficial-cardgame.com/products/pbsp_duo/
- Vol.1 premium (`pb_superstar`): `PL!SP-pb1-*`, `abilities_for_sp_pb1()`
