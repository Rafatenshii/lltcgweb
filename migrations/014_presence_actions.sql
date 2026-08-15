-- Opaque Discord Rich Presence join/spectate action tokens (Android Social SDK).

CREATE TABLE IF NOT EXISTS tcg_presence_actions (
    token TEXT PRIMARY KEY,
    discord_id TEXT NOT NULL,
    action_type TEXT NOT NULL,
    payload_json TEXT NOT NULL,
    created_at INTEGER NOT NULL,
    expires_at INTEGER NOT NULL,
    redeemed_at INTEGER,
    FOREIGN KEY (discord_id) REFERENCES tcg_users(discord_id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_tcg_presence_actions_owner
    ON tcg_presence_actions(discord_id, action_type);

CREATE INDEX IF NOT EXISTS idx_tcg_presence_actions_expires
    ON tcg_presence_actions(expires_at);
