# NSD02 — Nijigasaki cheer Start Deck

Official expansion: **NSD02**  
Product: スタートデッキ ラブライブ！虹ヶ咲学園スクールアイドル同好会 cheer  
Web starter key: **`nijigasaki_cheer`**  
Milestone to unlock the 7th starter: **`ms_cards_2400`** (Own 2,400 cards)

## Card prefixes

| Prefix | Role |
|--------|------|
| `PL!N-sd2-*` | New cheer members / lives / energy art |
| `PL!N-sd1-*-SD2` / `-P` | NSD01 reprints with new art |

## Provisional 60-card list

Official published counts cover lives and reprints only. Member 1-ofs among the 24 new types are unpublished; this list mirrors SPSD02 spirit:

- Skill-bearing new members ×2; vanilla `002`×2; vanillas `018`,`020`,`022`,`023`×1
- Lives: `025`×2, `026`×4, `027`×4; reprint live `sd1-026`×2
- Reprint members `sd1-001/004/006/008`×1
- Energy: 12× `LL-E-003-SD`

Adjust `cards.json` → `starter_decks.nijigasaki_cheer` if Bushiroad publishes full copy counts.

## Tooling

```bash
python tools/scrape_expansion.py NSD02
# Chiichan:
python tools/import_cards_json_to_db.py all_cards_nsd02_*.json
# lltcgweb:
python import_from_db.py --prefix ../Chiichan/database.db "PL!N-sd2-"
python import_from_db.py --prefix ../Chiichan/database.db "PL!N-sd1-"
# then --refresh after ability edits
```

Abilities: `pl_n_sd2_abilities.py` → `abilities_for_n_sd2()`.  
NSD01 reprint rarities inherit IR via startswith alias in `abilities_for_sd1()`.

## Tests

```bash
./vendor/bin/phpunit tests/Engine/Nsd02CheerSkillsTest.php
php scripts/validate_ability_ir.php
php scripts/validate_cards.php
```
