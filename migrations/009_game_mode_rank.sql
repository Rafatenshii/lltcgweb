-- PvP game modes: per-mode ranked ELO + same-mode matchmaking queues.
-- Applied via tcgDbRunMigrationOnce('game_mode_rank_20260731') in db.php
-- (SQLite table rebuilds need PRAGMA checks; this file bumps the migrator fingerprint).

-- Target schema (also reflected in tcgDbMigrateBootstrap):
--   tcg_rank (discord_id, game_mode, …) PRIMARY KEY (discord_id, game_mode)
--   tcg_match_queue (discord_id, game_mode, …) PRIMARY KEY (discord_id, game_mode)
--   tcg_ranked_matches.game_mode
--   tcg_casual_queue.game_mode
--   tcg_users.ranked_starter_key
