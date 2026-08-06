<?php
/**
 * Operator CLI: advance a room stuck on live_show performance after Yell
 * (hearts never resolved). Usage:
 *   php scripts/heal_live_show_hearts.php ROOM_ID [--dry-run]
 */
declare(strict_types=1);

$roomId = $argv[1] ?? '';
$dryRun = in_array('--dry-run', $argv, true);
if ($roomId === '' || str_starts_with($roomId, '-')) {
    fwrite(STDERR, "Usage: php scripts/heal_live_show_hearts.php ROOM_ID [--dry-run]\n");
    exit(1);
}

define('TCG_API_LIB_ONLY', true);
require_once dirname(__DIR__) . '/api.php';

$roomId = strtoupper(preg_replace('/[^A-Z0-9]/', '', $roomId) ?? '');

try {
    $out = withLock($roomId, static function () use ($roomId, $dryRun) {
        $state = loadGame($roomId);
        if (!$state) {
            throw new RuntimeException("Room not found: $roomId");
        }
        $before = [
            'seq' => intval($state['seq'] ?? 0),
            'phase' => (string)($state['phase'] ?? ''),
            'stage' => (string)($state['live_show']['stage'] ?? ''),
            'stage_seq' => intval($state['live_show']['stage_seq'] ?? 0),
            'yell_both' => !empty($state['_perf_yell_both_done']),
            'hearts' => $state['_perf_hearts_resolved'] ?? null,
            'acks' => $state['live_show']['acks'] ?? null,
            'prompt' => $state['pending_prompt']['type'] ?? null,
            'sig' => liveShowProgressSignature($state),
            'log_n' => count($state['log'] ?? []),
        ];

        if (($state['live_show']['stage'] ?? '') !== 'performance') {
            return ['ok' => false, 'reason' => 'not_performance', 'before' => $before];
        }
        if (empty($state['_perf_yell_both_done'])) {
            return ['ok' => false, 'reason' => 'yell_not_done', 'before' => $before];
        }
        if (!empty($state['pending_prompt'])) {
            return ['ok' => false, 'reason' => 'pending_prompt', 'before' => $before];
        }

        // Force the same path as a full live_show ack / timeout advance.
        $state = resolvePerformanceHeartsAfterYell($state);
        if (liveShowProgressSignature($state) === $before['sig']) {
            $state = healStalledLiveShowPerformance($state);
        }
        if (liveShowProgressSignature($state) === $before['sig']) {
            // Last resort: bypass finishYellRetryAndHearts intentional hold.
            $state = resolvePerformanceHeartsAfterYell($state);
        }

        $after = [
            'seq' => intval($state['seq'] ?? 0),
            'phase' => (string)($state['phase'] ?? ''),
            'stage' => (string)($state['live_show']['stage'] ?? ''),
            'stage_seq' => intval($state['live_show']['stage_seq'] ?? 0),
            'yell_both' => !empty($state['_perf_yell_both_done']),
            'hearts' => $state['_perf_hearts_resolved'] ?? null,
            'acks' => $state['live_show']['acks'] ?? null,
            'prompt' => $state['pending_prompt']['type'] ?? null,
            'sig' => liveShowProgressSignature($state),
            'log_n' => count($state['log'] ?? []),
        ];

        $changed = $after['sig'] !== $before['sig'] || $after['phase'] !== $before['phase'];
        if ($changed) {
            $state['seq'] = intval($state['seq'] ?? 0) + 1;
            $after['seq'] = $state['seq'];
            if (!$dryRun) {
                saveGame($roomId, $state);
            }
        }

        return [
            'ok' => $changed,
            'dry_run' => $dryRun,
            'before' => $before,
            'after' => $after,
            'log_tail' => array_slice(array_map(
                static fn($e) => is_array($e) ? (string)($e['msg'] ?? '') : (string)$e,
                $state['log'] ?? []
            ), -8),
        ];
    }, 20.0);

    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit(!empty($out['ok']) ? 0 : 2);
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    exit(1);
}
