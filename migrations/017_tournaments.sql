-- Tournament Mode v1: registry, entrants, bracket matches, coin ledger.
CREATE TABLE IF NOT EXISTS tcg_tournaments (
    id TEXT PRIMARY KEY,
    host_discord_id TEXT NOT NULL,
    title TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'open',
    game_mode TEXT NOT NULL DEFAULT 'standard',
    start_at INTEGER NOT NULL,
    checkin_mins INTEGER NOT NULL DEFAULT 10,
    min_players INTEGER NOT NULL DEFAULT 2,
    max_players INTEGER NOT NULL DEFAULT 16,
    entry_fee_coins INTEGER NOT NULL DEFAULT 0,
    prize_pool_coins INTEGER NOT NULL DEFAULT 0,
    settings_json TEXT NOT NULL DEFAULT '{}',
    created_at INTEGER NOT NULL,
    updated_at INTEGER NOT NULL,
    FOREIGN KEY (host_discord_id) REFERENCES tcg_users(discord_id)
);

CREATE INDEX IF NOT EXISTS idx_tcg_tournaments_status_start
    ON tcg_tournaments(status, start_at);

CREATE TABLE IF NOT EXISTS tcg_tournament_entrants (
    tournament_id TEXT NOT NULL,
    discord_id TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'registered',
    seed INTEGER,
    deck_snapshot TEXT NOT NULL,
    paid_coins INTEGER NOT NULL DEFAULT 0,
    registered_at INTEGER NOT NULL,
    checked_in_at INTEGER,
    PRIMARY KEY (tournament_id, discord_id),
    FOREIGN KEY (tournament_id) REFERENCES tcg_tournaments(id) ON DELETE CASCADE,
    FOREIGN KEY (discord_id) REFERENCES tcg_users(discord_id)
);

CREATE INDEX IF NOT EXISTS idx_tcg_tournament_entrants_status
    ON tcg_tournament_entrants(tournament_id, status);

CREATE TABLE IF NOT EXISTS tcg_tournament_matches (
    id TEXT PRIMARY KEY,
    tournament_id TEXT NOT NULL,
    round INTEGER NOT NULL,
    bracket_slot INTEGER NOT NULL,
    p1_discord_id TEXT,
    p2_discord_id TEXT,
    room_id TEXT,
    p1_token TEXT,
    p2_token TEXT,
    status TEXT NOT NULL DEFAULT 'pending',
    winner_discord_id TEXT,
    connect_deadline_at INTEGER,
    created_at INTEGER NOT NULL,
    updated_at INTEGER NOT NULL,
    FOREIGN KEY (tournament_id) REFERENCES tcg_tournaments(id) ON DELETE CASCADE
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_tcg_tournament_matches_slot
    ON tcg_tournament_matches(tournament_id, round, bracket_slot);

CREATE INDEX IF NOT EXISTS idx_tcg_tournament_matches_room
    ON tcg_tournament_matches(room_id);

CREATE TABLE IF NOT EXISTS tcg_tournament_ledger (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tournament_id TEXT NOT NULL,
    discord_id TEXT,
    kind TEXT NOT NULL,
    amount INTEGER NOT NULL,
    idempotency_key TEXT NOT NULL UNIQUE,
    meta_json TEXT NOT NULL DEFAULT '{}',
    created_at INTEGER NOT NULL,
    FOREIGN KEY (tournament_id) REFERENCES tcg_tournaments(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_tcg_tournament_ledger_tid
    ON tcg_tournament_ledger(tournament_id);
