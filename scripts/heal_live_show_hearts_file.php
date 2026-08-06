<?php
/**
 * Offline heart advance for a stuck live_show performance room JSON.
 *   php scripts/heal_live_show_hearts_file.php in.json out.json
 */
declare(strict_types=1);

$in = $argv[1] ?? '';
$out = $argv[2] ?? '';
if ($in === '' || $out === '' || !is_file($in)) {
    fwrite(STDERR, "Usage: php scripts/heal_live_show_hearts_file.php in.json out.json\n");
    exit(1);
}

$_SERVER['REQUEST_METHOD'] = 'GET';
define('TCG_API_LIB_ONLY', true);
require_once dirname(__DIR__) . '/api.php';

$raw = file_get_contents($in);
$state = json_decode($raw, true);
if (!is_array($state)) {
    fwrite(STDERR, "Invalid JSON\n");
    exit(1);
}

$before = [
    'seq' => intval($state['seq'] ?? 0),
    'phase' => (string)($state['phase'] ?? ''),
    'stage' => (string)($state['live_show']['stage'] ?? ''),
    'yell_both' => !empty($state['_perf_yell_both_done']),
    'prompt' => $state['pending_prompt']['type'] ?? null,
    'sig' => liveShowProgressSignature($state),
    'log_n' => count($state['log'] ?? []),
];

fwrite(STDERR, 'before ' . json_encode($before) . "\n");

if (($state['live_show']['stage'] ?? '') !== 'performance' || empty($state['_perf_yell_both_done'])) {
    fwrite(STDERR, "Not a yell-done performance stall\n");
    exit(2);
}

$t0 = microtime(true);
$state = resolvePerformanceHeartsAfterYell($state);
fwrite(STDERR, sprintf("resolve done in %.3fs\n", microtime(true) - $t0));

$after = [
    'seq' => intval($state['seq'] ?? 0),
    'phase' => (string)($state['phase'] ?? ''),
    'stage' => (string)($state['live_show']['stage'] ?? ''),
    'yell_both' => !empty($state['_perf_yell_both_done']),
    'prompt' => $state['pending_prompt']['type'] ?? null,
    'hearts' => $state['_perf_hearts_resolved'] ?? null,
    'sig' => liveShowProgressSignature($state),
    'log_n' => count($state['log'] ?? []),
];

if ($after['sig'] === $before['sig'] && $after['phase'] === $before['phase']) {
    fwrite(STDERR, "No change\n");
    exit(3);
}

$state['seq'] = intval($state['seq'] ?? 0) + 1;
$after['seq'] = $state['seq'];
// Clear stale partial acks so clients re-ack the new stage.
if (isset($state['live_show']['acks'])) {
    $state['live_show']['acks'] = [];
}
$state['live_show']['started_at'] = time();

file_put_contents($out, json_encode($state, JSON_UNESCAPED_UNICODE));
fwrite(STDERR, 'after ' . json_encode($after) . "\n");
fwrite(STDERR, 'log_tail:' . "\n");
foreach (array_slice($state['log'] ?? [], -8) as $e) {
    $msg = is_array($e) ? (string)($e['msg'] ?? '') : (string)$e;
    fwrite(STDERR, "  - $msg\n");
}
echo "OK wrote $out seq={$state['seq']}\n";
