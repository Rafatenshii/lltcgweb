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

/**
 * Which game_mode's public queue stats to return for ranked_status.
 *
 * Clients poll via GET with ?game_mode=… while idle. Searching/matched rows
 * carry their own mode — prefer that so in-queue polls stay consistent.
 *
 * @param array<string,mixed> $status from tcgQueueStatus()
 * @param array<string,mixed> $body JSON body (often empty on GET)
 * @param array<string,mixed>|null $get defaults to $_GET
 */
function tcgRankedStatusStatsGameMode(array $status, array $body = [], ?array $get = null): string {
    $get = $get ?? $_GET;
    $requested = $body['game_mode'] ?? $get['game_mode'] ?? null;
    $st = (string)($status['status'] ?? 'idle');
    if ($st === 'searching' || $st === 'matched') {
        return tcgNormalizeRankedGameMode($status['game_mode'] ?? $requested ?? TCG_GAME_MODE_STANDARD);
    }
    return tcgNormalizeRankedGameMode($requested ?? TCG_GAME_MODE_STANDARD);
}
