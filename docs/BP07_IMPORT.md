# BP07 — Booster Pack MELLOW MOMENT

Official expansion: **BP07**  
Product: ブースターパック MELLOW MOMENT  
Web booster id: **`bp_mellow`** (10 packs/box)  
Filter: `ブースターパック MELLOW MOMENT`

## Groups

Aqours (`PL!S-bp7-*`), Nijigasaki (`PL!N-bp7-*`), Liella! (`PL!SP-bp7-*`), plus crossover `LL-bp7-*` and SECL reprints.

## New mechanic

**Double colorless blade heart** (`b_heart07` → `all2`): Yell resolves to **two** any-color hearts. Lives: Cheer Mode, 恋になりたいAQUARIUM, 未来の音が聴こえる.

## Tooling

```bash
python tools/scrape_expansion.py BP07
# Chiichan:
python tools/import_cards_json_to_db.py all_cards_bp07_*.json
# lltcgweb:
python import_from_db.py --prefix ../Chiichan/database.db "PL!N-bp7-"
python import_from_db.py --prefix ../Chiichan/database.db "PL!S-bp7-"
python import_from_db.py --prefix ../Chiichan/database.db "PL!SP-bp7-"
python import_from_db.py --prefix ../Chiichan/database.db "LL-bp7-"
python scripts/translate_bp07_locales.py
python sync_discord_card_skills.py --db ../Chiichan/database.db --prefix "PL!N-bp7-"
# (repeat prefixes or use scripts/_tmp_sync_bp07_discord.py)
```

Abilities: `n_bp7_abilities.py`, `s_bp7_abilities.py`, `sp_bp7_abilities.py`, `ll_bp7_abilities.py`  
Engine: `bp7_effects.php` + `EffectHandlers` BP07 entries.

## Tests

```bash
./vendor/bin/phpunit tests/Engine/Bp7All2BladeHeartTest.php
php scripts/validate_ability_ir.php
php scripts/validate_cards.php
```
