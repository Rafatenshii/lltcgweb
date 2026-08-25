-- Optional Android FCM start-soon reminders per tournament (lead-time offsets).

CREATE TABLE IF NOT EXISTS tcg_tournament_start_reminders (
    discord_id TEXT NOT NULL,
    tournament_id TEXT NOT NULL,
    offset_sec INTEGER NOT NULL,
    sent_at INTEGER,
    PRIMARY KEY (discord_id, tournament_id, offset_sec)
);

CREATE INDEX IF NOT EXISTS idx_tcg_tournament_start_reminders_due
    ON tcg_tournament_start_reminders(sent_at, tournament_id);
