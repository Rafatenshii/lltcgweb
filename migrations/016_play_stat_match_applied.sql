-- Idempotent Hostinger apply of match play-stat deltas (room_id once).
CREATE TABLE IF NOT EXISTS tcg_play_stat_match_applied (
    room_id TEXT NOT NULL PRIMARY KEY,
    applied_at INTEGER NOT NULL
);
