<?php
/**
 * PvP game mode IDs (ranked + casual queues / ranked ELO boards).
 * Free Mode is unranked-only (Deck Experiment decks; no leaderboards).
 */

const TCG_GAME_MODE_STANDARD = 'standard';
const TCG_GAME_MODE_STARTERS = 'starters';
const TCG_GAME_MODE_FREE = 'free';

/** @return list<string> */
function tcgGameModeIds(): array {
    return [TCG_GAME_MODE_STANDARD, TCG_GAME_MODE_STARTERS, TCG_GAME_MODE_FREE];
}

/** Modes that appear on ranked / public leaderboards. */
function tcgRankedGameModeIds(): array {
    return [TCG_GAME_MODE_STANDARD, TCG_GAME_MODE_STARTERS];
}

function tcgNormalizeGameMode(mixed $raw): string {
    $m = strtolower(trim((string)$raw));
    if ($m === 'starters' || $m === 'starter' || $m === 'starter_decks' || $m === 'starter-decks') {
        return TCG_GAME_MODE_STARTERS;
    }
    if ($m === 'free' || $m === 'free_mode' || $m === 'freemode' || $m === 'experiment') {
        return TCG_GAME_MODE_FREE;
    }
    return TCG_GAME_MODE_STANDARD;
}

function tcgIsStartersGameMode(mixed $raw): bool {
    return tcgNormalizeGameMode($raw) === TCG_GAME_MODE_STARTERS;
}

function tcgIsFreeGameMode(mixed $raw): bool {
    return tcgNormalizeGameMode($raw) === TCG_GAME_MODE_FREE;
}

/** Ranked queues must never accept Free Mode. */
function tcgNormalizeRankedGameMode(mixed $raw): string {
    $m = tcgNormalizeGameMode($raw);
    if ($m === TCG_GAME_MODE_FREE) {
        return TCG_GAME_MODE_STANDARD;
    }
    return $m;
}
