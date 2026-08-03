<?php
/**
 * Hostinger ↔ VPS ranked match bridge (seed rooms, Elo apply webhook, resign).
 *
 * Secrets (prefer env; optional defines in gitignored tcg_sync.local.php):
 *   TCG_INTERNAL_MATCH_SECRET
 *   TCG_MATCH_SEED_URL   (Hostinger → VPS seed)
 *   TCG_ELO_APPLY_URL    (VPS → Hostinger Elo)
 */

function tcgMatchBridgeLoadLocalConfig(): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $local = __DIR__ . '/tcg_sync.local.php';
    if (is_file($local)) {
        require_once $local;
    }
}

function tcgInternalMatchSecret(): string {
    tcgMatchBridgeLoadLocalConfig();
    if (defined('TCG_INTERNAL_MATCH_SECRET')) {
        return trim((string)TCG_INTERNAL_MATCH_SECRET);
    }
    $env = getenv('TCG_INTERNAL_MATCH_SECRET');
    return is_string($env) ? trim($env) : '';
}

function tcgMatchSeedUrl(): string {
    tcgMatchBridgeLoadLocalConfig();
    if (defined('TCG_MATCH_SEED_URL')) {
        return trim((string)TCG_MATCH_SEED_URL);
    }
    $env = getenv('TCG_MATCH_SEED_URL');
    if (is_string($env) && trim($env) !== '') {
        return trim($env);
    }
    return 'https://stream.loveliveradio.ca/tcg/api/api.php?action=seed_ranked_room';
}

function tcgEloApplyUrl(): string {
    tcgMatchBridgeLoadLocalConfig();
    if (defined('TCG_ELO_APPLY_URL')) {
        return trim((string)TCG_ELO_APPLY_URL);
    }
    $env = getenv('TCG_ELO_APPLY_URL');
    if (is_string($env) && trim($env) !== '') {
        return trim($env);
    }
    return 'https://loveliveradio.ca/tcg/account.php?action=ranked_apply_result';
}

function tcgInternalMatchSecretOk(?string $provided): bool {
    $expected = tcgInternalMatchSecret();
    if ($expected === '' || $provided === null || $provided === '') {
        return false;
    }
    return hash_equals($expected, $provided);
}

function tcgRequestInternalMatchSecret(): string {
    $h = $_SERVER['HTTP_X_TCG_INTERNAL_SECRET'] ?? '';
    if (is_string($h) && $h !== '') {
        return $h;
    }
    return '';
}

function tcgRequireInternalMatchSecret(): void {
    if (!tcgInternalMatchSecretOk(tcgRequestInternalMatchSecret())) {
        throw new Exception('Unauthorized', 401);
    }
}

function tcgShouldApplyRankedEloRemotely(): bool {
    if (tcgInternalMatchSecret() === '' || tcgEloApplyUrl() === '') {
        return false;
    }
    $env = getenv('TCG_ELO_APPLY_REMOTE');
    if (is_string($env) && $env !== '') {
        return in_array(strtolower(trim($env)), ['1', 'true', 'yes'], true);
    }
    // VPS match API uses Redis; Hostinger keeps file store + applies Elo locally.
    $store = getenv('TCG_GAME_STORE');
    return is_string($store) && strtolower(trim($store)) === 'redis';
}

/**
 * POST JSON to URL with internal secret. Returns decoded array or null on failure.
 *
 * @param array<string,mixed> $payload
 * @return array<string,mixed>|null
 */
function tcgMatchBridgeHttpPostJson(string $url, array $payload, int $timeoutSec = 12): ?array {
    $secret = tcgInternalMatchSecret();
    if ($secret === '' || $url === '') {
        return null;
    }
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if ($body === false) {
        return null;
    }
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-TCG-Internal-Secret: ' . $secret,
            ],
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => min(5, $timeoutSec),
            CURLOPT_TIMEOUT => $timeoutSec,
        ]);
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (!is_string($raw) || $code < 200 || $code >= 300) {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }
    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nX-TCG-Internal-Secret: {$secret}\r\n",
            'content' => $body,
            'timeout' => $timeoutSec,
            'ignore_errors' => true,
        ],
    ]);
    $raw = @file_get_contents($url, false, $ctx);
    if (!is_string($raw) || $raw === '') {
        return null;
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

/** @param array<string,mixed> $state */
function tcgSeedRankedRoomToVps(array $state): bool {
    $url = tcgMatchSeedUrl();
    $res = tcgMatchBridgeHttpPostJson($url, ['state' => $state], 15);
    return is_array($res) && !empty($res['ok']) && empty($res['error']);
}

/**
 * @param array<string,mixed> $state
 */
function tcgPostRankedApplyResultToHostinger(array &$state): bool {
    $ranked = $state['ranked'] ?? [];
    if (!is_array($ranked)) {
        $ranked = [];
    }
    $payload = [
        'room_id' => (string)($state['room_id'] ?? ''),
        'winner' => $state['winner'] ?? null,
        'end_reason' => $state['end_reason'] ?? null,
        'p1_discord_id' => (string)($ranked['p1_discord_id'] ?? ''),
        'p2_discord_id' => (string)($ranked['p2_discord_id'] ?? ''),
        'game_mode' => (string)($ranked['game_mode'] ?? 'standard'),
        'resigned_by' => $state['resigned_by'] ?? null,
        'disconnected_player' => $state['disconnected_player'] ?? null,
    ];
    $res = tcgMatchBridgeHttpPostJson(tcgEloApplyUrl(), $payload, 15);
    if (!is_array($res) || empty($res['success']) || !empty($res['error'])) {
        return false;
    }
    // Hostinger grants the pack; stash on VPS room so the winner's client can show it.
    if (!empty($res['pr_reward_applied']) && is_array($res['pr_reward'] ?? null)) {
        if (!isset($state['ranked']) || !is_array($state['ranked'])) {
            $state['ranked'] = [];
        }
        $state['ranked']['pr_reward_applied'] = true;
        $state['ranked']['pr_reward'] = $res['pr_reward'];
    }
    return true;
}

function tcgOverflowMatchApiBase(): string {
    tcgMatchBridgeLoadLocalConfig();
    if (defined('TCG_OVERFLOW_MATCH_API')) {
        return rtrim((string)TCG_OVERFLOW_MATCH_API, '/');
    }
    $env = getenv('TCG_OVERFLOW_MATCH_API');
    if (is_string($env) && trim($env) !== '') {
        return rtrim(trim($env), '/');
    }
    return 'https://stream.loveliveradio.ca/tcg/api';
}

/**
 * Probe VPS ranked room. Returns live|finished|missing|unknown.
 */
function tcgProbeOverflowRankedRoom(string $roomId, string $token): string {
    $roomId = strtoupper(preg_replace('/[^A-Z0-9]/', '', $roomId) ?? '');
    $token = trim($token);
    if ($roomId === '' || $token === '') {
        return 'unknown';
    }
    $url = tcgOverflowMatchApiBase() . '/api.php?action=get_state'
        . '&room_id=' . rawurlencode($roomId)
        . '&token=' . rawurlencode($token)
        . '&seq=0&poll=0&resume=1';
    $raw = null;
    $code = 0;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch === false) {
            return 'unknown';
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 8,
        ]);
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    } else {
        $ctx = stream_context_create(['http' => ['timeout' => 8, 'ignore_errors' => true]]);
        $raw = @file_get_contents($url, false, $ctx);
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
            $code = (int)$m[1];
        }
    }
    if (!is_string($raw) || $code < 200 || $code >= 500) {
        return 'unknown';
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return 'unknown';
    }
    if (!empty($decoded['error']) && preg_match('/room not found/i', (string)$decoded['error'])) {
        return 'missing';
    }
    if (($decoded['status'] ?? '') === 'finished') {
        return 'finished';
    }
    if (!empty($decoded['my_id']) || ($decoded['mode'] ?? '') === 'ranked') {
        return 'live';
    }
    return 'unknown';
}

function tcgResignRankedRoomOnVps(string $roomId, string $token): bool {
    $url = tcgOverflowMatchApiBase() . '/api.php?action=action';
    $payload = json_encode([
        'room_id' => $roomId,
        'token' => $token,
        'type' => 'resign',
        'data' => [],
    ], JSON_UNESCAPED_UNICODE);
    if ($payload === false) {
        return false;
    }
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch === false) {
            return false;
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
        ]);
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (!is_string($raw) || $code < 200 || $code >= 300) {
            return false;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) && (isset($decoded['ok']) || isset($decoded['seq']) || empty($decoded['error']));
    }
    return false;
}
