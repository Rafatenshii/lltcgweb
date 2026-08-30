-- Public tournament game replays (durable past-bracket viewing).
-- Also applied via tcgDbEnsureTournamentSchema in db.php.
CREATE TABLE IF NOT EXISTS tcg_tournament_replays (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tournament_id TEXT NOT NULL,
    match_id TEXT NOT NULL,
    room_id TEXT NOT NULL,
    game_index INTEGER NOT NULL DEFAULT 1,
    winner_discord_id TEXT,
    end_reason TEXT,
    action_count INTEGER NOT NULL DEFAULT 0,
    duration_seconds INTEGER NOT NULL DEFAULT 0,
    payload_json TEXT NOT NULL,
    saved_at INTEGER NOT NULL,
    UNIQUE (tournament_id, room_id),
    FOREIGN KEY (tournament_id) REFERENCES tcg_tournaments(id) ON DELETE CASCADE,
    FOREIGN KEY (match_id) REFERENCES tcg_tournament_matches(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_tcg_tournament_replays_match
    ON tcg_tournament_replays(match_id);

CREATE INDEX IF NOT EXISTS idx_tcg_tournament_replays_tournament
    ON tcg_tournament_replays(tournament_id, saved_at DESC);
