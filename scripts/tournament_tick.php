#!/usr/bin/env php
<?php
/**
 * Advance open tournaments without a browser tab (local cron / CLI).
 *
 * Usage:
 *   TCG_TOURNAMENTS_ENABLED=1 php scripts/tournament_tick.php
 *   TCG_TOURNAMENTS_ENABLED=1 php scripts/tournament_tick.php ABCDEF1234
 */
putenv('TCG_TOURNAMENTS_ENABLED=' . (getenv('TCG_TOURNAMENTS_ENABLED') ?: '1'));

if (!defined('TCG_ACCOUNT_LIB_ONLY')) {
    define('TCG_ACCOUNT_LIB_ONLY', true);
}
require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/tournament.php';
require_once dirname(__DIR__) . '/push.php';

if (!tcgTournamentsEnabled()) {
    fwrite(STDERR, "Tournaments disabled (set TCG_TOURNAMENTS_ENABLED=1)\n");
    exit(1);
}

$tid = isset($argv[1]) ? strtoupper(trim((string)$argv[1])) : '';

// CLI has no Discord session — call tick internals directly.
if ($tid !== '') {
    $out = tcgTournamentTickOne($tid);
    if (function_exists('tcgPushDispatchTournamentStartReminders')) {
        tcgPushDispatchTournamentStartReminders();
    }
    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit(0);
}

$stmt = tcgDb()->query(
    'SELECT id FROM tcg_tournaments WHERE status IN ("open","checkin","running") ORDER BY start_at ASC LIMIT 50'
);
$ids = $stmt ? ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: []) : [];
$results = [];
foreach ($ids as $id) {
    $results[] = tcgTournamentTickOne((string)$id);
}
if (function_exists('tcgPushDispatchTournamentStartReminders')) {
    tcgPushDispatchTournamentStartReminders();
}
echo json_encode(['success' => true, 'ticked' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
