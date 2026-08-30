-- Persist podium / prize snapshot when a tournament finishes.
-- Also applied via once-migration tournament_results_20260830 in db.php.
ALTER TABLE tcg_tournaments ADD COLUMN results_json TEXT NOT NULL DEFAULT '';
