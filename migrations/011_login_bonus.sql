-- Daily login bonus progress (JST cycle).
-- Also applied via tcgDbRunMigrationOnce('login_bonus_columns_20260802') in db.php for
-- production DBs that already have bootstrap_v2 (skips tcgDbMigrateBootstrap).

ALTER TABLE tcg_daily_state ADD COLUMN login_bonus_step INTEGER NOT NULL DEFAULT 0;
ALTER TABLE tcg_daily_state ADD COLUMN login_bonus_last_date TEXT;
