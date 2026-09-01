<?php
/**
 * FCM tokens + friend queue/invite notifications (Android Loveca shell).
 *
 * Server send credentials (pick one):
 * - FCM HTTP v1 (recommended): service account JSON via TCG_FCM_SERVICE_ACCOUNT or
 *   data/fcm_service_account.json (gitignored).
 * - Legacy server key (deprecated): TCG_FCM_SERVER_KEY or data/fcm_server_key.txt.
 *
 * Without credentials, tokens still register and in-app invite polling works; pushes are no-ops.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/social.php';
require_once __DIR__ . '/game_mode.php';

/** Optional Android FCM lead times before a scheduled tournament starts. */
const TCG_PUSH_TOURNAMENT_START_OFFSETS = [300, 600, 1800, 3600, 10800, 36000];
const TCG_PUSH_TOURNAMENT_START_GRACE_SEC = 90;

const TCG_PUSH_QUEUE_COOLDOWN_SEC = 180;
const TCG_PUSH_INVITE_TTL_SEC = 900;

function tcgPushEnsureSchema(): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $db = tcgDb();
    $db->exec('CREATE TABLE IF NOT EXISTS tcg_push_tokens (
        token TEXT PRIMARY KEY,
        discord_id TEXT NOT NULL,
        platform TEXT NOT NULL DEFAULT \'android\',
        updated_at INTEGER NOT NULL
    )');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_tcg_push_tokens_user ON tcg_push_tokens(discord_id, updated_at)');
    $db->exec('CREATE TABLE IF NOT EXISTS tcg_match_invites (
        id TEXT PRIMARY KEY,
        from_id TEXT NOT NULL,
        to_id TEXT NOT NULL,
        lane TEXT NOT NULL,
        game_mode TEXT NOT NULL,
        room_id TEXT NOT NULL DEFAULT \'\',
        status TEXT NOT NULL DEFAULT \'pending\',
        created_at INTEGER NOT NULL,
        expires_at INTEGER NOT NULL
    )');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_tcg_match_invites_to ON tcg_match_invites(to_id, status, expires_at)');
    $db->exec('CREATE TABLE IF NOT EXISTS tcg_push_queue_ping (
        from_id TEXT NOT NULL,
        to_id TEXT NOT NULL,
        lane TEXT NOT NULL,
        game_mode TEXT NOT NULL,
        sent_at INTEGER NOT NULL,
        PRIMARY KEY (from_id, to_id, lane, game_mode)
    )');
    tcgPushEnsureTournamentStartReminderSchema($db);
}

function tcgPushEnsureTournamentStartReminderSchema(?PDO $db = null): void {
    $db = $db ?? tcgDb();
    $db->exec('CREATE TABLE IF NOT EXISTS tcg_tournament_start_reminders (
        discord_id TEXT NOT NULL,
        tournament_id TEXT NOT NULL,
        offset_sec INTEGER NOT NULL,
        sent_at INTEGER,
        PRIMARY KEY (discord_id, tournament_id, offset_sec)
    )');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_tcg_tournament_start_reminders_due
        ON tcg_tournament_start_reminders(sent_at, tournament_id)');
}

function tcgPushFcmServerKey(): string {
    $env = trim((string)getenv('TCG_FCM_SERVER_KEY'));
    if ($env !== '') {
        return $env;
    }
    $path = (defined('TCG_DATA_DIR') ? TCG_DATA_DIR : (__DIR__ . '/data/')) . 'fcm_server_key.txt';
    if (is_file($path)) {
        return trim((string)file_get_contents($path));
    }
    return '';
}

/** @return array<string,mixed>|null */
function tcgPushFcmServiceAccount(): ?array {
    $path = trim((string)getenv('TCG_FCM_SERVICE_ACCOUNT'));
    if ($path === '') {
        $path = (defined('TCG_DATA_DIR') ? TCG_DATA_DIR : (__DIR__ . '/data/')) . 'fcm_service_account.json';
    }
    if (!is_file($path)) {
        return null;
    }
    $json = json_decode((string)file_get_contents($path), true);
    if (!is_array($json)) {
        return null;
    }
    $email = trim((string)($json['client_email'] ?? ''));
    $key = trim((string)($json['private_key'] ?? ''));
    $project = trim((string)($json['project_id'] ?? ''));
    if ($email === '' || $key === '' || $project === '') {
        return null;
    }
    return [
        'client_email' => $email,
        'private_key' => $key,
        'project_id' => $project,
    ];
}

function tcgPushFcmConfigured(): bool {
    return tcgPushFcmServiceAccount() !== null || tcgPushFcmServerKey() !== '';
}

function tcgPushSetLastFcmError(string $message): void {
    $GLOBALS['tcg_push_last_fcm_error'] = trim($message);
}

function tcgPushLastFcmError(): string {
    return trim((string)($GLOBALS['tcg_push_last_fcm_error'] ?? ''));
}

/** POST body to URL; prefers curl when available (Hostinger-safe). */
function tcgPushHttpPost(string $url, array $headers, string $body, int $timeoutSec = 8): ?string {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => min(5, $timeoutSec),
            CURLOPT_TIMEOUT => $timeoutSec,
        ]);
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (!is_string($raw)) {
            return null;
        }
        if ($code >= 400) {
            tcgPushSetLastFcmError('HTTP ' . $code . ': ' . substr(trim($raw), 0, 240));
        }
        return $raw;
    }
    $headerLines = implode("\r\n", $headers);
    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => $headerLines . "\r\n",
            'content' => $body,
            'timeout' => $timeoutSec,
            'ignore_errors' => true,
        ],
    ]);
    $raw = @file_get_contents($url, false, $ctx);
    return is_string($raw) ? $raw : null;
}

function tcgPushFcmBase64Url(string $raw): string {
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}

function tcgPushFcmAccessToken(): ?string {
    $sa = tcgPushFcmServiceAccount();
    if ($sa === null) {
        tcgPushSetLastFcmError('No FCM service account configured');
        return null;
    }
    tcgPushSetLastFcmError('');
    $cachePath = (defined('TCG_DATA_DIR') ? TCG_DATA_DIR : (__DIR__ . '/data/')) . 'fcm_oauth_cache.json';
    $now = time();
    if (is_file($cachePath)) {
        $cache = json_decode((string)file_get_contents($cachePath), true);
        if (is_array($cache)) {
            $tok = trim((string)($cache['access_token'] ?? ''));
            $exp = intval($cache['expires_at'] ?? 0);
            if ($tok !== '' && $exp > ($now + 60)) {
                return $tok;
            }
        }
    }
    $header = tcgPushFcmBase64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_UNESCAPED_SLASHES));
    $claims = tcgPushFcmBase64Url(json_encode([
        'iss' => $sa['client_email'],
        'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
        'aud' => 'https://oauth2.googleapis.com/token',
        'iat' => $now,
        'exp' => $now + 3600,
    ], JSON_UNESCAPED_SLASHES));
    $input = $header . '.' . $claims;
    $sig = '';
    if (!openssl_sign($input, $sig, $sa['private_key'], OPENSSL_ALGO_SHA256)) {
        $msg = 'OAuth JWT sign failed — check service account private_key in fcm_service_account.json';
        error_log('tcgPushFcmAccessToken: openssl_sign failed');
        tcgPushSetLastFcmError($msg);
        return null;
    }
    $jwt = $input . '.' . tcgPushFcmBase64Url($sig);
    $raw = tcgPushHttpPost(
        'https://oauth2.googleapis.com/token',
        ['Content-Type: application/x-www-form-urlencoded'],
        http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt,
        ]),
        8
    );
    if ($raw === null) {
        tcgPushSetLastFcmError('OAuth token request failed (network or curl)');
        return null;
    }
    $json = json_decode($raw, true);
    $token = trim((string)($json['access_token'] ?? ''));
    $expiresIn = intval($json['expires_in'] ?? 0);
    if ($token === '' || $expiresIn <= 0) {
        $err = trim((string)($json['error_description'] ?? $json['error'] ?? ''));
        if ($err === '') {
            $err = substr(trim($raw), 0, 200);
        }
        error_log('tcgPushFcmAccessToken: token exchange failed: ' . $err);
        tcgPushSetLastFcmError('OAuth failed: ' . $err);
        return null;
    }
    @file_put_contents($cachePath, json_encode([
        'access_token' => $token,
        'expires_at' => $now + $expiresIn,
    ], JSON_UNESCAPED_SLASHES));
    return $token;
}

/**
 * @param array<string,string> $data
 */
function tcgPushSendFcmV1(string $token, string $title, string $body, array $data): bool {
    $sa = tcgPushFcmServiceAccount();
    $access = tcgPushFcmAccessToken();
    if ($sa === null || $access === null) {
        return false;
    }
    $payload = [
        'message' => [
            'token' => $token,
            'notification' => [
                'title' => $title,
                'body' => $body,
            ],
            'data' => array_map('strval', $data),
            'android' => ['priority' => 'HIGH'],
        ],
    ];
    $url = 'https://fcm.googleapis.com/v1/projects/' . rawurlencode($sa['project_id']) . '/messages:send';
    $raw = tcgPushHttpPost(
        $url,
        [
            'Authorization: Bearer ' . $access,
            'Content-Type: application/json; charset=UTF-8',
        ],
        json_encode($payload, JSON_UNESCAPED_UNICODE) ?: '{}',
        6
    );
    if ($raw === null) {
        if (tcgPushLastFcmError() === '') {
            tcgPushSetLastFcmError('FCM send request failed (network)');
        }
        return false;
    }
    $json = json_decode($raw, true);
    if (!empty($json['name'])) {
        return true;
    }
    $err = '';
    if (is_array($json['error'] ?? null)) {
        $err = (string)($json['error']['message'] ?? '');
        $details = $json['error']['details'] ?? [];
        if (is_array($details)) {
            foreach ($details as $detail) {
                if (!is_array($detail)) {
                    continue;
                }
                $code = (string)($detail['errorCode'] ?? '');
                if ($code === 'UNREGISTERED' || $code === 'INVALID_ARGUMENT') {
                    tcgPushDropToken($token);
                    break;
                }
            }
        }
    }
    if ($err !== '' && tcgPushFcmErrorInvalidatesToken($err)) {
        tcgPushDropToken($token);
    }
    if ($err !== '') {
        error_log('tcgPushSendFcmV1: ' . $err);
        tcgPushSetLastFcmError($err);
    } elseif (tcgPushLastFcmError() === '') {
        tcgPushSetLastFcmError('FCM rejected the message (unknown error)');
    }
    return false;
}

function tcgPushSendFcmLegacy(string $token, string $title, string $body, array $data, string $key): bool {
    $payload = [
        'to' => $token,
        'priority' => 'high',
        'notification' => [
            'title' => $title,
            'body' => $body,
            'sound' => 'default',
        ],
        'data' => array_map('strval', $data),
    ];
    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Authorization: key={$key}\r\nContent-Type: application/json\r\n",
            'content' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'timeout' => 4,
            'ignore_errors' => true,
        ],
    ]);
    $raw = @file_get_contents('https://fcm.googleapis.com/fcm/send', false, $ctx);
    if ($raw === false) {
        return false;
    }
    $json = json_decode($raw, true);
    if (!empty($json['success'])) {
        return true;
    }
    if (!empty($json['results'][0]['error']) && in_array($json['results'][0]['error'], ['NotRegistered', 'InvalidRegistration'], true)) {
        tcgDb()->prepare('DELETE FROM tcg_push_tokens WHERE token = ?')->execute([$token]);
    }
    return false;
}

function tcgPushGameModeLabel(string $mode): string {
    $mode = tcgNormalizeGameMode($mode);
    return match ($mode) {
        TCG_GAME_MODE_STARTERS => 'Starters',
        TCG_GAME_MODE_RANDOMIZED => 'Randomized',
        TCG_GAME_MODE_FREE => 'Free',
        default => 'Standard',
    };
}

function tcgPushLaneLabel(string $lane): string {
    return $lane === 'ranked' ? 'ranked' : 'unranked';
}

function tcgPushDeepLink(array $query): string {
    $q = http_build_query($query);
    return 'https://loveliveradio.ca/tcg/' . ($q !== '' ? '?' . $q : '');
}

function tcgPushLooksLikeFcmToken(string $token): bool {
    $token = trim($token);
    return $token !== ''
        && strlen($token) >= 80
        && strlen($token) <= 4096
        && preg_match('/^[a-zA-Z0-9_:-]+$/', $token) === 1;
}

function tcgPushFcmErrorInvalidatesToken(string $err): bool {
    $e = strtolower(trim($err));
    if ($e === '') {
        return false;
    }
    return str_contains($e, 'not a valid fcm registration token')
        || str_contains($e, 'registration token is not a valid')
        || str_contains($e, 'not registered')
        || str_contains($e, 'unregistered');
}

function tcgPushDropToken(string $token): void {
    $token = trim($token);
    if ($token === '') {
        return;
    }
    tcgPushEnsureSchema();
    tcgDb()->prepare('DELETE FROM tcg_push_tokens WHERE token = ?')->execute([$token]);
}

function tcgPushRegisterToken(string $discordId, string $token, string $platform = 'android'): void {
    tcgPushEnsureSchema();
    $token = trim($token);
    if (!tcgPushLooksLikeFcmToken($token)) {
        throw new Exception('Invalid push token', 400);
    }
    $platform = strtolower(trim($platform)) === 'web' ? 'web' : 'android';
    $db = tcgDb();
    $db->prepare('DELETE FROM tcg_push_tokens WHERE token = ? OR (discord_id = ? AND platform = ?)')
        ->execute([$token, $discordId, $platform]);
    $db->prepare('INSERT INTO tcg_push_tokens (token, discord_id, platform, updated_at) VALUES (?, ?, ?, ?)')
        ->execute([$token, $discordId, $platform, time()]);
}

function tcgPushUnregisterToken(string $discordId, string $token = ''): void {
    tcgPushEnsureSchema();
    if ($token !== '') {
        tcgDb()->prepare('DELETE FROM tcg_push_tokens WHERE discord_id = ? AND token = ?')
            ->execute([$discordId, $token]);
        return;
    }
    tcgDb()->prepare('DELETE FROM tcg_push_tokens WHERE discord_id = ?')->execute([$discordId]);
}

/** @return list<string> */
function tcgPushTokensForUser(string $discordId): array {
    tcgPushEnsureSchema();
    $stmt = tcgDb()->prepare('SELECT token FROM tcg_push_tokens WHERE discord_id = ?');
    $stmt->execute([$discordId]);
    $out = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $tok = trim((string)($row['token'] ?? ''));
        if ($tok !== '') {
            $out[] = $tok;
        }
    }
    return $out;
}

/**
 * @param array<string,string> $data
 */
function tcgPushSendFcm(array $tokens, string $title, string $body, array $data): int {
    if (empty($tokens) || !tcgPushFcmConfigured()) {
        return 0;
    }
    $useV1 = tcgPushFcmServiceAccount() !== null;
    $legacyKey = $useV1 ? '' : tcgPushFcmServerKey();
    $sent = 0;
    foreach ($tokens as $token) {
        $ok = $useV1
            ? tcgPushSendFcmV1($token, $title, $body, $data)
            : tcgPushSendFcmLegacy($token, $title, $body, $data, $legacyKey);
        if ($ok) {
            $sent++;
        }
    }
    return $sent;
}

function tcgPushNotifyFriendsQueued(string $fromId, string $lane, string $gameMode): void {
    try {
        $lane = $lane === 'ranked' ? 'ranked' : 'unranked';
        $gameMode = tcgNormalizeGameMode($gameMode);
        $friends = tcgSocialAcceptedFriendIds($fromId);
        if (!$friends) {
            return;
        }
        tcgPushEnsureSchema();
        $now = time();
        $fromName = tcgSocialUserStub($fromId)['username'] ?? 'A friend';
        $modeLabel = tcgPushGameModeLabel($gameMode);
        $laneLabel = tcgPushLaneLabel($lane);
        $title = 'Loveca';
        $body = $fromName . ' is queueing for ' . $modeLabel . ' ' . $laneLabel;
        $db = tcgDb();
        foreach ($friends as $toId) {
            $chk = $db->prepare(
                'SELECT sent_at FROM tcg_push_queue_ping WHERE from_id = ? AND to_id = ? AND lane = ? AND game_mode = ?'
            );
            $chk->execute([$fromId, $toId, $lane, $gameMode]);
            $prev = intval($chk->fetchColumn() ?: 0);
            if ($prev > 0 && ($now - $prev) < TCG_PUSH_QUEUE_COOLDOWN_SEC) {
                continue;
            }
            $tokens = tcgPushTokensForUser($toId);
            if (!$tokens) {
                continue;
            }
            $data = [
                'type' => 'friend_queue',
                'lane' => $lane,
                'game_mode' => $gameMode,
                'from_id' => $fromId,
                'from_name' => $fromName,
                'url' => tcgPushDeepLink([
                    'friend_queue' => $lane,
                    'game_mode' => $gameMode,
                ]),
            ];
            tcgPushSendFcm($tokens, $title, $body, $data);
            $db->prepare(
                'INSERT INTO tcg_push_queue_ping (from_id, to_id, lane, game_mode, sent_at)
                 VALUES (?, ?, ?, ?, ?)
                 ON CONFLICT(from_id, to_id, lane, game_mode) DO UPDATE SET sent_at = excluded.sent_at'
            )->execute([$fromId, $toId, $lane, $gameMode, $now]);
        }
    } catch (Throwable $e) {
        error_log('tcgPushNotifyFriendsQueued: ' . $e->getMessage());
    }
}

function tcgApiPushRegister(array $body): array {
    $uid = tcgRequireAuthUser($body);
    tcgPushRegisterToken($uid, (string)($body['token'] ?? ''), (string)($body['platform'] ?? 'android'));
    return ['success' => true];
}

function tcgApiPushUnregister(array $body): array {
    $uid = tcgRequireAuthUser($body);
    tcgPushUnregisterToken($uid, trim((string)($body['token'] ?? '')));
    return ['success' => true];
}

/** Owner-only: send a test notification to the caller's registered device tokens. */
function tcgApiPushTest(array $body): array {
    $uid = tcgRequireAuthUser($body);
    if (!tcgSocialIsOwner($uid)) {
        throw new Exception('Forbidden', 403);
    }
    $tokens = tcgPushTokensForUser($uid);
    $configured = tcgPushFcmConfigured();
    $sa = tcgPushFcmServiceAccount();
    $useV1 = $sa !== null;
    $oauthOk = !$useV1 || tcgPushFcmAccessToken() !== null;
    $sent = 0;
    tcgPushSetLastFcmError('');
    $tokensBefore = count($tokens);
    if ($configured && $tokens && $oauthOk) {
        $sent = tcgPushSendFcm(
            $tokens,
            'Loveca test',
            'Push test — if you see this, notifications work.',
            [
                'type' => 'test',
                'url' => tcgPushDeepLink([]),
            ]
        );
    }
    $tokensAfter = count(tcgPushTokensForUser($uid));
    $err = tcgPushLastFcmError();
    return [
        'success' => true,
        'sent' => $sent,
        'tokens' => $tokensAfter,
        'tokens_cleared' => $tokensBefore > $tokensAfter,
        'token_invalid' => $err !== '' && tcgPushFcmErrorInvalidatesToken($err),
        'fcm_configured' => $configured,
        'fcm_v1' => $useV1,
        'oauth_ok' => $oauthOk,
        'fcm_project' => $useV1 ? (string)($sa['project_id'] ?? '') : '',
        'fcm_error' => $err !== '' ? $err : null,
    ];
}

function tcgApiMatchInvite(array $body): array {
    $uid = tcgRequireAuthUser($body);
    require_once __DIR__ . '/game_mode.php';
    $toId = trim((string)($body['friend_id'] ?? $body['to_id'] ?? ''));
    $roomId = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string)($body['room_id'] ?? '')) ?? '');
    $gameMode = tcgNormalizeGameMode($body['game_mode'] ?? TCG_GAME_MODE_STANDARD);
    if ($toId === '' || !preg_match('/^\d{5,32}$/', $toId)) {
        throw new Exception('Choose a friend', 400);
    }
    if ($roomId === '' || strlen($roomId) < 6) {
        throw new Exception('Create a room first', 400);
    }
    if (!tcgSocialAreFriends($uid, $toId)) {
        throw new Exception('You can only invite friends', 403);
    }
    tcgPushEnsureSchema();
    $now = time();
    $id = bin2hex(random_bytes(12));
    tcgDb()->prepare(
        'INSERT INTO tcg_match_invites (id, from_id, to_id, lane, game_mode, room_id, status, created_at, expires_at)
         VALUES (?, ?, ?, \'unranked\', ?, ?, \'pending\', ?, ?)'
    )->execute([$id, $uid, $toId, $gameMode, $roomId, $now, $now + TCG_PUSH_INVITE_TTL_SEC]);
    $fromName = tcgSocialUserStub($uid)['username'] ?? 'A friend';
    $modeLabel = tcgPushGameModeLabel($gameMode);
    $bodyText = $fromName . ' has invited you to a ' . $modeLabel . ' match!';
    tcgPushSendFcm(
        tcgPushTokensForUser($toId),
        'Loveca',
        $bodyText,
        [
            'type' => 'friend_invite',
            'invite_id' => $id,
            'lane' => 'unranked',
            'game_mode' => $gameMode,
            'room_id' => $roomId,
            'from_id' => $uid,
            'from_name' => $fromName,
            'url' => tcgPushDeepLink(['friend_invite' => $id]),
        ]
    );
    return [
        'success' => true,
        'invite_id' => $id,
        'room_id' => $roomId,
        'to' => tcgSocialUserStub($toId),
    ];
}

function tcgApiMatchInvitesPending(array $body): array {
    $uid = tcgRequireAuthUser($body);
    tcgPushEnsureSchema();
    $now = time();
    tcgDb()->prepare("UPDATE tcg_match_invites SET status = 'expired' WHERE to_id = ? AND status = 'pending' AND expires_at < ?")
        ->execute([$uid, $now]);
    $stmt = tcgDb()->prepare(
        "SELECT id, from_id, game_mode, room_id, created_at, expires_at
         FROM tcg_match_invites WHERE to_id = ? AND status = 'pending' AND expires_at >= ? ORDER BY created_at DESC"
    );
    $stmt->execute([$uid, $now]);
    $invites = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $from = tcgSocialUserStub((string)$row['from_id']);
        $invites[] = [
            'id' => $row['id'],
            'from' => $from,
            'game_mode' => $row['game_mode'],
            'room_id' => $row['room_id'],
            'expires_at' => intval($row['expires_at']),
        ];
    }
    return ['success' => true, 'invites' => $invites];
}

function tcgApiMatchInviteRespond(array $body, bool $accept): array {
    $uid = tcgRequireAuthUser($body);
    $id = preg_replace('/[^a-f0-9]/', '', strtolower((string)($body['invite_id'] ?? ''))) ?? '';
    if ($id === '') {
        throw new Exception('Missing invite', 400);
    }
    tcgPushEnsureSchema();
    $stmt = tcgDb()->prepare('SELECT * FROM tcg_match_invites WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || (string)$row['to_id'] !== $uid) {
        throw new Exception('Invite not found', 404);
    }
    if ((string)$row['status'] !== 'pending' || intval($row['expires_at']) < time()) {
        throw new Exception('That invite expired', 409);
    }
    $status = $accept ? 'accepted' : 'declined';
    tcgDb()->prepare('UPDATE tcg_match_invites SET status = ? WHERE id = ?')->execute([$status, $id]);
    if (!$accept) {
        return ['success' => true, 'accepted' => false];
    }
    return [
        'success' => true,
        'accepted' => true,
        'room_id' => (string)$row['room_id'],
        'game_mode' => (string)$row['game_mode'],
        'from' => tcgSocialUserStub((string)$row['from_id']),
    ];
}

function tcgPushTournamentStartOffsetLabel(int $sec): string {
    return match ($sec) {
        300 => '5 min',
        600 => '10 min',
        1800 => '30 min',
        3600 => '1 hour',
        10800 => '3 hours',
        36000 => '10 hours',
        default => $sec . 's',
    };
}

/** @param list<mixed> $raw
 *  @return list<int> */
function tcgPushNormalizeTournamentStartOffsets(array $raw): array {
    $allowed = array_fill_keys(TCG_PUSH_TOURNAMENT_START_OFFSETS, true);
    $out = [];
    foreach ($raw as $v) {
        $n = (int)$v;
        if (isset($allowed[$n])) {
            $out[$n] = $n;
        }
    }
    $list = array_values($out);
    sort($list, SORT_NUMERIC);
    return $list;
}

/**
 * @param list<string> $tournamentIds
 * @return array<string,list<int>>
 */
function tcgPushTournamentStartOffsetsForUser(string $discordId, array $tournamentIds): array {
    tcgPushEnsureSchema();
    $ids = [];
    foreach ($tournamentIds as $id) {
        $tid = strtoupper(trim((string)$id));
        if ($tid !== '') {
            $ids[$tid] = $tid;
        }
    }
    if ($discordId === '' || !$ids) {
        return [];
    }
    $list = array_values($ids);
    $placeholders = implode(',', array_fill(0, count($list), '?'));
    $stmt = tcgDb()->prepare(
        "SELECT tournament_id, offset_sec FROM tcg_tournament_start_reminders
         WHERE discord_id = ? AND tournament_id IN ($placeholders)
         ORDER BY offset_sec ASC"
    );
    $stmt->execute(array_merge([$discordId], $list));
    $map = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $tid = strtoupper((string)($row['tournament_id'] ?? ''));
        $map[$tid] = $map[$tid] ?? [];
        $map[$tid][] = (int)$row['offset_sec'];
    }
    return $map;
}

/**
 * Send due start-soon FCM globally (all tournaments), not just the ticked id.
 * Safe to call often: rows are claimed via sent_at.
 */
function tcgPushDispatchTournamentStartReminders(): int {
    try {
        tcgPushEnsureSchema();
        $now = time();
        $grace = TCG_PUSH_TOURNAMENT_START_GRACE_SEC;
        $stmt = tcgDb()->query(
            "SELECT r.discord_id, r.tournament_id, r.offset_sec, t.title, t.start_at, t.status
             FROM tcg_tournament_start_reminders r
             INNER JOIN tcg_tournaments t ON t.id = r.tournament_id
             WHERE r.sent_at IS NULL
               AND t.status IN ('open','checkin')"
        );
        if (!$stmt) {
            return 0;
        }
        $sent = 0;
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $startAt = (int)($row['start_at'] ?? 0);
            $offset = (int)($row['offset_sec'] ?? 0);
            if ($startAt <= 0 || $offset <= 0) {
                continue;
            }
            if ($now < ($startAt - $offset) || $now >= ($startAt + $grace)) {
                continue;
            }
            $uid = (string)$row['discord_id'];
            $tid = strtoupper((string)$row['tournament_id']);
            $tokens = tcgPushTokensForUser($uid);
            if (!$tokens) {
                continue;
            }
            $claim = tcgDb()->prepare(
                'UPDATE tcg_tournament_start_reminders SET sent_at = ?
                 WHERE discord_id = ? AND tournament_id = ? AND offset_sec = ? AND sent_at IS NULL'
            );
            $claim->execute([$now, $uid, $tid, $offset]);
            if ($claim->rowCount() < 1) {
                continue;
            }
            $title = trim((string)($row['title'] ?? ''));
            if ($title === '') {
                $title = $tid;
            }
            $when = tcgPushTournamentStartOffsetLabel($offset);
            $bodyText = $title . ' starts in ' . $when;
            tcgPushSendFcm(
                $tokens,
                'Loveca',
                $bodyText,
                [
                    'type' => 'tournament_start',
                    'tournament_id' => $tid,
                    'offset_sec' => (string)$offset,
                    'url' => tcgPushDeepLink(['tournament' => $tid]),
                ]
            );
            $sent++;
        }
        return $sent;
    } catch (Throwable $e) {
        error_log('tcgPushDispatchTournamentStartReminders: ' . $e->getMessage());
        return 0;
    }
}

/** @param array<string,mixed> $body */
function tcgApiTournamentStartRemindersGet(array $body): array {
    $uid = tcgRequireAuthUser($body);
    $id = strtoupper(trim((string)($body['tournament_id'] ?? '')));
    if ($id === '') {
        throw new Exception('tournament_id required', 400);
    }
    $map = tcgPushTournamentStartOffsetsForUser($uid, [$id]);
    return [
        'success' => true,
        'tournament_id' => $id,
        'offsets' => $map[$id] ?? [],
    ];
}

/** @param array<string,mixed> $body */
function tcgApiTournamentStartRemindersSet(array $body): array {
    $uid = tcgRequireAuthUser($body);
    $id = strtoupper(trim((string)($body['tournament_id'] ?? '')));
    if ($id === '') {
        throw new Exception('tournament_id required', 400);
    }
    if (!function_exists('tcgTournamentFetch')) {
        require_once __DIR__ . '/tournament_lib.php';
    }
    $row = tcgTournamentFetch($id);
    if (!$row) {
        throw new Exception('Tournament not found', 404);
    }
    $raw = $body['offsets'] ?? [];
    if (!is_array($raw)) {
        $raw = [];
    }
    $offsets = tcgPushNormalizeTournamentStartOffsets($raw);
    tcgPushEnsureSchema();
    $db = tcgDb();
    if (!$offsets) {
        $db->prepare('DELETE FROM tcg_tournament_start_reminders WHERE discord_id = ? AND tournament_id = ?')
            ->execute([$uid, $id]);
        return ['success' => true, 'tournament_id' => $id, 'offsets' => []];
    }
    $keep = implode(',', array_map('intval', $offsets));
    $db->prepare(
        "DELETE FROM tcg_tournament_start_reminders
         WHERE discord_id = ? AND tournament_id = ? AND offset_sec NOT IN ($keep)"
    )->execute([$uid, $id]);
    $ins = $db->prepare(
        'INSERT OR IGNORE INTO tcg_tournament_start_reminders
         (discord_id, tournament_id, offset_sec, sent_at) VALUES (?, ?, ?, NULL)'
    );
    foreach ($offsets as $sec) {
        $ins->execute([$uid, $id, $sec]);
    }
    // Late opt-in: fire immediately if the lead window is already open.
    tcgPushDispatchTournamentStartReminders();
    $map = tcgPushTournamentStartOffsetsForUser($uid, [$id]);
    return [
        'success' => true,
        'tournament_id' => $id,
        'offsets' => $map[$id] ?? $offsets,
    ];
}
