-- Account-saved Deck Experiment presets (full card pool; Free Mode only).
-- Applied via tcgDbRunMigrationOnce('experiment_presets_20260802') in db.php

CREATE TABLE IF NOT EXISTS tcg_experiment_presets (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    discord_id TEXT NOT NULL,
    slot INTEGER NOT NULL,
    name TEXT NOT NULL,
    main_deck TEXT NOT NULL,
    energy_deck TEXT NOT NULL,
    share_password TEXT,
    updated_at INTEGER NOT NULL,
    UNIQUE (discord_id, slot),
    FOREIGN KEY (discord_id) REFERENCES tcg_users(discord_id) ON DELETE CASCADE
);
