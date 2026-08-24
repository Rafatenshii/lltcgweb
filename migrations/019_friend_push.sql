-- Android FCM tokens + friend match invites / queue pings.

CREATE TABLE IF NOT EXISTS tcg_push_tokens (
    token TEXT PRIMARY KEY,
    discord_id TEXT NOT NULL,
    platform TEXT NOT NULL DEFAULT 'android',
    updated_at INTEGER NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_tcg_push_tokens_user
    ON tcg_push_tokens(discord_id, updated_at);

CREATE TABLE IF NOT EXISTS tcg_match_invites (
    id TEXT PRIMARY KEY,
    from_id TEXT NOT NULL,
    to_id TEXT NOT NULL,
    lane TEXT NOT NULL,
    game_mode TEXT NOT NULL,
    room_id TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL DEFAULT 'pending',
    created_at INTEGER NOT NULL,
    expires_at INTEGER NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_tcg_match_invites_to
    ON tcg_match_invites(to_id, status, expires_at);

CREATE TABLE IF NOT EXISTS tcg_push_queue_ping (
    from_id TEXT NOT NULL,
    to_id TEXT NOT NULL,
    lane TEXT NOT NULL,
    game_mode TEXT NOT NULL,
    sent_at INTEGER NOT NULL,
    PRIMARY KEY (from_id, to_id, lane, game_mode)
);
