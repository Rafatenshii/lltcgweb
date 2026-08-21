-- Tournament account history (W/L, H2H, coin earned / contributed).
CREATE TABLE IF NOT EXISTS tcg_tournament_user_stats (
    discord_id TEXT PRIMARY KEY,
    match_wins INTEGER NOT NULL DEFAULT 0,
    match_losses INTEGER NOT NULL DEFAULT 0,
    coins_earned INTEGER NOT NULL DEFAULT 0,
    coins_contributed INTEGER NOT NULL DEFAULT 0,
    updated_at INTEGER NOT NULL DEFAULT 0,
    FOREIGN KEY (discord_id) REFERENCES tcg_users(discord_id)
);

CREATE TABLE IF NOT EXISTS tcg_tournament_h2h (
    discord_id TEXT NOT NULL,
    opponent_discord_id TEXT NOT NULL,
    wins INTEGER NOT NULL DEFAULT 0,
    losses INTEGER NOT NULL DEFAULT 0,
    updated_at INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (discord_id, opponent_discord_id),
    FOREIGN KEY (discord_id) REFERENCES tcg_users(discord_id),
    FOREIGN KEY (opponent_discord_id) REFERENCES tcg_users(discord_id)
);

CREATE INDEX IF NOT EXISTS idx_tcg_tournament_h2h_opp
    ON tcg_tournament_h2h(opponent_discord_id);
