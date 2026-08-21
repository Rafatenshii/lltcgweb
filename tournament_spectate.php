<?php
/**
 * Tournament spectate helpers — stream-delay ring + spectator totals.
 */
require_once __DIR__ . '/config/paths.php';

function tcgTournamentDelayDir(): string {
    $dir = rtrim(tcgPath('data'), '/\\') . DIRECTORY_SEPARATOR . 'spectate_delay';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return $dir;
}

function tcgTournamentDelayFile(string $roomId): string {
    $safe = strtoupper(preg_replace('/[^A-Z0-9]/', '', $roomId) ?? '');
    return tcgTournamentDelayDir() . DIRECTORY_SEPARATOR . $safe . '.json';
}

/** @return int seconds from room state / tournament blob */
function tcgTournamentStreamDelaySecs(array $state): int {
    $delay = (int)($state['spectate_stream_delay_secs']
        ?? $state['tournament']['stream_delay_secs']
        ?? 0);
    return in_array($delay, [0, 15, 30, 60], true) ? $delay : 0;
}

/**
 * Append a lightweight snapshot for delayed spectate (players unaffected).
 * Keeps ~2 minutes of history at ~2s spacing max.
 */
function tcgTournamentRecordDelayedSnapshot(string $roomId, array $state): void {
    if (($state['mode'] ?? '') !== 'tournament') {
        return;
    }
    $delay = tcgTournamentStreamDelaySecs($state);
    if ($delay <= 0) {
        return;
    }
    $path = tcgTournamentDelayFile($roomId);
    $now = time();
    $ring = [];
    if (is_file($path)) {
        $raw = json_decode((string)@file_get_contents($path), true);
        if (is_array($raw) && isset($raw['entries']) && is_array($raw['entries'])) {
            $ring = $raw['entries'];
        }
    }
    $lastTs = 0;
    if ($ring) {
        $last = $ring[count($ring) - 1];
        $lastTs = (int)($last['ts'] ?? 0);
    }
    if ($lastTs > 0 && ($now - $lastTs) < 2) {
        return;
    }
    // Store filtered-ish state without tokens / full decks to keep files small.
    $snap = $state;
    foreach (['p1', 'p2'] as $pid) {
        if (!isset($snap['players'][$pid]) || !is_array($snap['players'][$pid])) {
            continue;
        }
        unset($snap['players'][$pid]['token'], $snap['players'][$pid]['main_deck'], $snap['players'][$pid]['energy_deck']);
    }
    $ring[] = ['ts' => $now, 'seq' => (int)($state['seq'] ?? 0), 'state' => $snap];
    $keepAfter = $now - max(120, $delay + 30);
    $ring = array_values(array_filter($ring, static fn($e) => (int)($e['ts'] ?? 0) >= $keepAfter));
    if (count($ring) > 90) {
        $ring = array_slice($ring, -90);
    }
    @file_put_contents($path, json_encode([
        'room_id' => strtoupper($roomId),
        'delay_secs' => $delay,
        'entries' => $ring,
    ], JSON_UNESCAPED_UNICODE), LOCK_EX);
}

/**
 * For spectators: return the newest snapshot older than stream_delay_secs, else live.
 *
 * @return array{state: array, delayed: bool, delay_secs: int}
 */
function tcgTournamentApplyStreamDelay(string $roomId, array $liveState): array {
    $delay = tcgTournamentStreamDelaySecs($liveState);
    if ($delay <= 0 || ($liveState['mode'] ?? '') !== 'tournament') {
        return ['state' => $liveState, 'delayed' => false, 'delay_secs' => 0];
    }
    $path = tcgTournamentDelayFile($roomId);
    if (!is_file($path)) {
        // No history yet — hold spectators on empty-ish wait by returning live with flag.
        return ['state' => $liveState, 'delayed' => true, 'delay_secs' => $delay];
    }
    $raw = json_decode((string)@file_get_contents($path), true);
    $entries = is_array($raw['entries'] ?? null) ? $raw['entries'] : [];
    $cutoff = time() - $delay;
    $pick = null;
    foreach ($entries as $e) {
        if (!is_array($e)) {
            continue;
        }
        if ((int)($e['ts'] ?? 0) <= $cutoff && isset($e['state']) && is_array($e['state'])) {
            $pick = $e;
        }
    }
    if ($pick === null) {
        return ['state' => $liveState, 'delayed' => true, 'delay_secs' => $delay];
    }
    $state = $pick['state'];
    // Preserve live spectator_count / hands policy from live room if missing on snap.
    if (!isset($state['spectate_hidden_hands']) && isset($liveState['spectate_hidden_hands'])) {
        $state['spectate_hidden_hands'] = $liveState['spectate_hidden_hands'];
    }
    $state['spectate_stream_delayed'] = true;
    $state['spectate_stream_delay_secs'] = $delay;
    return ['state' => $state, 'delayed' => true, 'delay_secs' => $delay];
}
