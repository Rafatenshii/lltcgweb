# Thai (th) localization progress

`document.documentElement.lang = 'th'`; `body.locale-th` applies `Noto Sans Thai` (falls back to `Nunito`).

Like **ko** (and unlike **es**, which keeps `name_en` for characters/songs), Thai uses **phonetic Thai transliteration** for characters and song titles, resolved at render time via `TH_NAME_MAP` / `TH_SONG_MAP` (see [Naming policy](#naming-policy) and `docs/locales/TH_NAMES.md`). Card rules will use **`text_th`** with Thai skill brackets once M5 lands.

## Milestones

| ID | Description | Status |
|----|-------------|--------|
| M0 | Infrastructure (`LOCALE_ORDER`, `inject_i18n_th.py`, `inject_i18n_th_maps.py`, `validate_i18n.php`, flag asset, cursor rules) | ☑ |
| M1 | Core menus + auth/hub hardcoded strings (`locales/th.json`) | ☑ |
| M2 | In-game UI, prompts, toasts | ☑ |
| M3 | Interactive tutorial (`tutorial_th.json`) + legacy `tutorial.*` keys | ☐ |
| M4 | Game log localization (`log_i18n.js` TH rules) | ☐ |
| M5 | Card `text_th` pipeline | ☐ |
| M6 | News, booster rates, Star Gems, stamps | ☑ |
| M7 | Card `text_th` **quality pass** (full Thai body, no EN leaks) | ☐ |

### M0 — done

- `scripts/i18n_inject_lib.py`: `LOCALE_ORDER` includes `th`; locale-block regex matches `th`.
- `scripts/inject_i18n_th.py` / `scripts/inject_i18n_th_maps.py` inject `STRINGS.th`, `TH_NAME_MAP`, `TH_SONG_MAP`.
- `scripts/validate_i18n.php` checks `locales/th.json`.
- Thai flag asset: `assets/flags/TH_Thailand_rect.png` (already in `assets/flags.json`).
- Cursor rules treat `th` as a mandatory locale alongside ko/zh.

### M1 / M2 — done

- `locales/th.json` populated and injected into `i18n.js` → `STRINGS.th`.
- `LOCALES` includes `'th'`; `mergeLocaleAliases(STRINGS.th)`; `body.locale-th` + Noto Sans Thai; language picker options for ไทย.
- `TH_NAME_MAP` / `TH_SONG_MAP` from `locales/th_names.json` / `locales/th_songs.json`.
- In-game UI / prompt / skill / phase namespaces covered in `locales/th.json`.

### M6 — done (2026-07-25)

- **News:** every post in `news.json` has `title.th` / `body.th`; new announcement `2026-07-thai-locale` (2026-07-25) in en/ja/es/ko/zh/th.
- **Stamps:** all 133 stamp labels in `stamps_i18n.json` include `th` (124 non-empty; 9 intentionally blank like other locales). `scripts/inject_stamps_th.py` + `scripts/build_stamp_i18n.py` preserve `th` on rebuild (same pattern as ko/zh).
- **Booster rates / Star Gems:** player-facing copy lives in `locales/th.json` (`booster.*`, missions / gem strings).

### Still open (M3–M5, M7)

- `tutorial_th.json` + `loadTutorialTh()` wiring completion — M3.
- `log_i18n.js` TH rule tables (`STRUCTURAL_PHRASE_RULES_TH` / `PHRASE_RULES_TH` / `EFFECT_RULES_TH`, etc.) — M4.
- Card `text_th` batch pipeline — M5; quality pass — M7 (do not mark until ship).

## Naming policy

Thai **follows the Korean pattern** (character/song names are localized, not kept as `name_en`):

- **Characters**: transliterated using **Royal Institute (ราชบัณฑิตยสถาน) phonetic Thai transliteration** conventions — the same authority used for Thai transcription standards — for consistent, community-legible phonetic spelling of English/Japanese-romaji names in Thai script. See `docs/locales/TH_NAMES.md` for the full policy and worked examples.
- **Brands stay Latin**: idol-group / unit brand marks that are already Latin-script marks — **μ's**, **Aqours**, **Saint Snow**, **Nijigasaki**-style transliteration exceptions aside, **Liella!**, **A-RISE**, **QU4RTZ**, **R3BIRTH**, **CatChu!**, **5yncri5e!**, **Mira-Cra Park!**, etc. — are **not** transliterated; keep them exactly as printed in `name_en`.
- **Songs**: same three methods as `ko`/`zh` (`phonetic` / `semantic` / `keep`), decided per-title in `th_songs.json` (see `docs/locales/TH_NAMES.md`).

## Skill bracket glossary (locked — from the localization plan)

These are **fixed** and must stay identical across `text_th`, `skillKw`, tutorial copy, and `log_i18n.js` once each surface starts using them (mirrors the `ko`/`zh` "locked brackets" pattern):

| EN | TH |
|----|-----|
| On Enter | เมื่อเข้าสนาม |
| On Leave | เมื่อออกจากสนาม |
| Live Start | เริ่ม Live |
| Live Success | Live สำเร็จ |
| Activated | เปิดใช้ |
| Always | ต่อเนื่อง |
| Once per Turn | เทิร์นละ 1 ครั้ง |
| Center | เซ็นเตอร์ |

## Game term glossary (from the localization plan)

| EN | TH |
|----|-----|
| Main Phase | เฟสหลัก |
| Live Phase | เฟส Live |
| Stage | เวที |
| Waiting Room | ห้องรอ |
| Energy | พลังงาน |
| Hearts | หัวใจ |
| Blade | เบลด |
| mulligan | มัลลิแกน |
| Member | สมาชิก |
| Baton Touch | บาตองทัช |
| Performance | การแสดง |
| Success | สำเร็จ |

Confirm any additional terms (Twice per Turn, Left/Right Side, Yell, etc. — not yet specified in the plan) with a fluent reviewer before M4/M5 lock the full glossary; treat the table above as authoritative for the terms it covers.

## Pipeline

```bash
# Inject UI strings (run after any locales/th.json edit)
python scripts/inject_i18n_th.py

# Regenerate TH_NAME_MAP / TH_SONG_MAP (run after any th_names.json / th_songs.json edit)
python scripts/inject_i18n_th_maps.py

# Stamp labels (hand map; rebuild preserves th)
python scripts/inject_stamps_th.py
python scripts/build_stamp_i18n.py

# Validate STRINGS.es + STRINGS.ko + STRINGS.zh + STRINGS.th against STRINGS.en
php scripts/validate_i18n.php
```

Card `text_th` batch translation and quality-audit scripts do not exist yet — model them on `scripts/translate_text_zh_batch.py` / `scripts/audit_text_zh_quality.py` when M5 begins.

## Deploy cache-bust (when shipping)

Bump when Thai surfaces go live:

- `i18n.js?v=…`
- `log_i18n.js?v=…` (once M4 adds TH rule tables)
- `tutorial_th.json?v=…` (once created)
- `index.html` (language picker / font / `body.locale-th`)
- `stamps_i18n.json?v=…` / `news.json?v=…` (M6 — `th` fields present)
