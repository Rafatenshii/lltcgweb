<?php
/**
 * PvP game mode IDs (ranked + casual queues / ranked ELO boards).
 */

const TCG_GAME_MODE_STANDARD = 'standard';
const TCG_GAME_MODE_STARTERS = 'starters';

/** @return list<string> */
function tcgGameModeIds(): array {
    return [TCG_GAME_MODE_STANDARD, TCG_GAME_MODE_STARTERS];
}

function tcgNormalizeGameMode(mixed $raw): string {
    $m = strtolower(trim((string)$raw));
    if ($m === 'starters' || $m === 'starter' || $m === 'starter_decks' || $m === 'starter-decks') {
        return TCG_GAME_MODE_STARTERS;
    }
    return TCG_GAME_MODE_STANDARD;
}

function tcgIsStartersGameMode(mixed $raw): bool {
    return tcgNormalizeGameMode($raw) === TCG_GAME_MODE_STARTERS;
}
