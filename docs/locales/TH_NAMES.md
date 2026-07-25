# Thai (th) name maps for Loveca

Prep for Thai language support in Love Live TCG (Loveca). **Naming policy + wiring only** in this change — `locales/th_names.json` / `locales/th_songs.json` do not exist yet; this document defines the conventions the future data files must follow.

## Transliteration standard

- **Characters**: **Royal Institute (ราชบัณฑิตยสถาน) phonetic Thai transliteration** — follow the Royal Institute of Thailand's transliteration conventions for rendering English/Japanese-romaji personal names in Thai script, the same standard body used for the reverse (Thai → Latin) Royal Thai General System of Transcription. Prefer the spelling a Thai reader would recognize as a natural phonetic rendering of the source name over an invented or overly literal one.
- **Cross-check**: established Thai fan-community spellings (Thai LoveLive!/Aikatsu-adjacent idol fandom wikis, Thai voice-actor/anime databases) take priority over a from-scratch phonetic derivation when a widely-used community spelling already exists — mirrors the `zh` policy of preferring LLWiki/community forms over literal dictionary readings.
- Katakana / foreign given names → phonetic Thai consonant/vowel clusters matching how the name is pronounced in the source (English or Japanese-romanized), not a Thai-native reinterpretation.

## Brands stay Latin

Idol-group / unit / sub-unit brand marks that are already Latin-script marks are **not** transliterated — keep them exactly as printed in `name_en`:

- **μ's**, **Aqours**, **Nijigasaki** (unit name itself may stay Latin per plan — confirm with reviewer if a Thai reading is preferred for the JP-origin unit names, mirroring the `ko`/`zh` partial-localization approach for `Nijigasaki`/`Hasunosora`), **Liella!**, **A-RISE**, **Saint Snow**
- Sub-brand / gimmick marks: **QU4RTZ**, **R3BIRTH**, **CatChu!**, **5yncri5e!**, **Mira-Cra Park!** (みらくらぱーく!), **DiverDiva**, **AZALEA**, **Guilty Kiss**

This matches the `zh` rule ("Keep Latin brand units: μ's, Aqours, Liella!, QU4RTZ, R3BIRTH, CatChu!, etc.") and the `ko` rule (Latin-script brand marks stay as-is; only JP-origin unit names get localized readings).

## Files (to be created in a future change — not part of this M0 infra change)

| File | Role |
|------|------|
| `locales/th_names.json` | Characters, JP keys, groups, schools, subunits — shape mirrors `locales/ko_names.json` / `locales/zh_names.json` |
| `locales/th_songs.json` | Live titles → `{ "th": "...", "method": "phonetic" \| "semantic" \| "keep" }` — shape mirrors `locales/ko_songs.json` |

Map keys are TCG `name_en` (and JP `name` under `characters_jp`), consumed by `scripts/inject_i18n_th_maps.py` (already created this change; see `docs/locales/TH_PROGRESS.md`) into `TH_NAME_MAP` / `TH_SONG_MAP` in `i18n.js`.

## Character conventions (policy, to apply when `th_names.json` is authored)

1. Use the most common Thai fan-community spelling if one is established; otherwise apply Royal Institute phonetic transliteration to the English `name_en` (or Japanese `name` for JP-origin given names).
2. Prefer natural Thai phonetic renderings over overly literal transliteration — same spirit as the `zh` rule preferring community forms (e.g. 南小鸟 over 南琴梨) over a dictionary-literal reading.
3. Western / mixed names (e.g. `Mia Taylor`, `Emma Verde`) transliterate each name part phonetically in sequence; no special separator punctuation is mandated (unlike `zh`'s middle dot `·`) — use natural Thai syllable spacing.
4. Keep Latin brand units exactly as listed in [Brands stay Latin](#brands-stay-latin) above.
5. Translated school/club phrases (if localized) should follow whatever Thai community convention already exists for the franchise; when none exists, keep the phrase in English pending reviewer sign-off rather than inventing a translation.

## Song / Live conventions (policy, to apply when `th_songs.json` is authored)

Same three methods as `ko` (see `docs/locales/KO_PROGRESS.md` → Naming policy) and `zh` (see `docs/locales/ZH_NAMES.md` → Song / Live conventions):

| Method | Meaning |
|--------|---------|
| `phonetic` | Direct sound transliteration into Thai script (e.g. an English-titled song rendered by Thai phonetic spelling) |
| `semantic` | Meaning-based Thai translation, used for Japanese-romaji titles with a translatable meaning |
| `keep` | Retain Latin / EN (or JP original) as the Thai display — default for English-primary titles where no established Thai community translation exists |

Song titles should default to `keep` until a community-established Thai rendering is confirmed, same caution the `zh` policy applies (`zh` reserved `phonetic` mostly for `ko`, defaulting to `keep` for English-primary titles).

## Coverage (TCG catalog)

Not started — `locales/th_names.json` / `locales/th_songs.json` do not exist yet. Once authored, track coverage here the same way `docs/locales/ZH_NAMES.md` does (Members EN / JP keys / Lives in map / song methods breakdown).

## Wiring already done (this change)

1. `scripts/inject_i18n_th_maps.py` created (clone of `scripts/inject_i18n_ko_maps.py` / `scripts/inject_i18n_zh_maps.py`) — flattens `th_names.json` `characters` + `characters_jp` into `TH_NAME_MAP`, and `th_songs.json` `songs` into `TH_SONG_MAP`, injecting both above `cardLocaleName()` in `i18n.js`.
2. `assets/flags/TH_Thailand_rect.png` already exists and is already listed in `assets/flags.json` — no flag work needed.

## Wiring still needed (out of scope for this change)

1. Author `locales/th_names.json` / `locales/th_songs.json` following the conventions above.
2. Run `python scripts/inject_i18n_th_maps.py` to generate `TH_NAME_MAP` / `TH_SONG_MAP` in `i18n.js`.
3. Extend `cardLocaleName()` / `cardLocaleType()` in `i18n.js` for `th` (mirrors the `ko`/`zh` branches).
4. Full UI pack `locales/th.json` (`python scripts/inject_i18n_th.py`), tutorials (`tutorial_th.json`), stamps, news — separate M1+ work tracked in `docs/locales/TH_PROGRESS.md`.

## References

- Royal Institute of Thailand (ราชบัณฑิตยสถาน) transliteration conventions
- Existing KO pipeline: `scripts/inject_i18n_ko_maps.py`, `docs/locales/KO_PROGRESS.md`
- Existing ZH pipeline: `scripts/inject_i18n_zh_maps.py`, `docs/locales/ZH_NAMES.md`
- `cardLocaleName()` in `i18n.js`
