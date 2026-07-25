# Thai (th) localization progress

`document.documentElement.lang = 'th'`; `body.locale-th` applies `Noto Sans Thai` (falls back to `Nunito`).

Like **ko** (and unlike **es**, which keeps `name_en` for characters/songs), Thai uses **phonetic Thai transliteration** for characters and song titles, resolved at render time via `TH_NAME_MAP` / `TH_SONG_MAP` (see [Naming policy](#naming-policy) and `docs/locales/TH_NAMES.md`). Card rules use **`text_th`** with locked Thai skill brackets.

## Milestones

| ID | Description | Status |
|----|-------------|--------|
| M0 | Infrastructure (`LOCALE_ORDER`, `inject_i18n_th.py`, `inject_i18n_th_maps.py`, `validate_i18n.php`, flag asset, cursor rules) | ☑ |
| M1 | Core menus + auth/hub hardcoded strings (`locales/th.json`) | ☑ |
| M2 | In-game UI, prompts, toasts | ☑ |
| M3 | Interactive tutorial (`tutorial_th.json`) + legacy `tutorial.*` keys | ☑ |
| M4 | Game log localization (`log_i18n.js` TH rules) | ☑ |
| M5 | Card `text_th` pipeline | ☑ |
| M6 | News, booster rates, Star Gems, stamps | ☑ |
| M7 | Ship (validate, cache-bust, Hostinger deploy) | ☑ |

### M0 — done

- `scripts/i18n_inject_lib.py`: `LOCALE_ORDER` includes `th`; locale-block regex matches `th`.
- `scripts/inject_i18n_th.py` / `scripts/inject_i18n_th_maps.py` inject `STRINGS.th`, `TH_NAME_MAP`, `TH_SONG_MAP`.
- `scripts/validate_i18n.php` checks `locales/th.json`.
- Thai flag asset: `assets/flags/TH_Thailand_rect.png`.
- Cursor rules treat `th` as a mandatory locale alongside ko/zh.

### M1 / M2 — done

- `locales/th.json` populated and injected into `i18n.js` → `STRINGS.th`.
- `LOCALES` includes `'th'`; `mergeLocaleAliases(STRINGS.th)`; `body.locale-th` + Noto Sans Thai; language picker options for ไทย.
- `TH_NAME_MAP` / `TH_SONG_MAP` from `locales/th_names.json` / `locales/th_songs.json`.
- In-game UI / prompt / skill / phase namespaces covered in `locales/th.json`.

### M3 — done

- `tutorial_th.json` (91 steps) via `build_tutorial_locale_json.py --locale th`.
- Glossary + validate scripts include `th`; `loadTutorialTh()` wired in `i18n.js` + `index.html` boot.

### M4 — done

- `log_i18n.js`: `STRUCTURAL_PHRASE_RULES_TH`, phrase/effect/prompt tables, `localizeLogMessageTh` / `localizePromptTextTh`.
- `index.html`: `RULE_KW_BRACKET_LABELS_TH`, `RULE_KW_TH_TO_EN`, `cardRulesDisplayText` → `text_th`.

### M5 — done

- `text_th` on all 1362 rules-bearing cards (`scripts/translate_text_th_batch.py`).
- Audit clean: `scripts/audit_text_th_quality.py` (0 bracket leaks, 0 residual EN).
- Coverage: `scripts/card_text_th_coverage.php` → 100%.

### M6 — done (2026-07-25)

- **News:** every post in `news.json` has `title.th` / `body.th`; announcement `2026-07-thai-locale` (en/ja/es/ko/zh/th).
- **Stamps:** all 133 stamp labels include `th` (124 non-empty). `inject_stamps_th.py` + `build_stamp_i18n.py` preserve `th`.

### M7 — done

- Validated: `validate_i18n.php`, `validate_tutorial_i18n.py --locale th`, `audit_text_th_quality.py`, `card_text_th_coverage.php`.
- Cache-bust bumped for `i18n.js`, `log_i18n.js`, `tutorial_th.json`, `stamps_i18n.json`, `news.json`.
- Deployed via Chiichan `scripts/deploy-loveliveradio-ca.sh`.

## Naming policy

Thai **follows the Korean pattern** (character/song names are localized, not kept as `name_en`):

- **Characters**: transliterated using **Royal Institute (ราชบัณฑิตยสถาน) phonetic Thai transliteration** conventions — see `docs/locales/TH_NAMES.md`.
- **Brands stay Latin**: **μ's**, **Aqours**, **Liella!**, **A-RISE**, **QU4RTZ**, **R3BIRTH**, **CatChu!**, **5yncri5e!**, **Mira-Cra Park!**, etc.
- **Songs**: methods in `th_songs.json` (`phonetic` / `semantic` / `keep`).

## Skill bracket glossary (locked)

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

## Game term glossary

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

## Pipeline

```bash
python scripts/inject_i18n_th.py
python scripts/inject_i18n_th_maps.py
python scripts/build_tutorial_locale_json.py --locale th
python scripts/validate_tutorial_i18n.py --locale th
python scripts/translate_text_th_batch.py --rebuild-all-th
python scripts/audit_text_th_quality.py
php scripts/card_text_th_coverage.php
php scripts/validate_i18n.php
```
