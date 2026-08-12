-- Per-preset card sleeve (cosmetic). Empty string = default official back.
-- Applied via tcgDbEnsureColumn in db.php on boot.

ALTER TABLE tcg_deck_presets ADD COLUMN sleeve_id TEXT NOT NULL DEFAULT '';
ALTER TABLE tcg_experiment_presets ADD COLUMN sleeve_id TEXT NOT NULL DEFAULT '';
