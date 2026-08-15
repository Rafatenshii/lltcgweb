<?php
/**
 * Opaque Discord Rich Presence action tokens (spectate / ranked queue join).
 * Secrets are short-lived and single-use; never put seat tokens in Discord payloads.
 */

declare(strict_types=1);

const TCG_PRESENCE_ACTION_SPECTATE = 'spectate';
const TCG_PRESENCE_ACTION_RANKED_QUEUE = 'ranked_queue';
const TCG_PRESENCE_ACTION_TTL_SPECTATE = 7200;
const TCG_PRESENCE_ACTION_TTL_QUEUE = 1800;

function tcgPresenceActionsEnsureTable(PDO $db): void {
    static $done = false;
    if ($done) {
        return;
    }
    $db->exec('CREATE TABLE IF NOT EXISTS tcg_presence_actions (
        token TEXT PRIMARY KEY,
        discord_id TEXT NOT NULL,
        action_type TEXT NOT NULL,
        payload_json TEXT NOT NULL,
        created_at INTEGER NOT NULL,
        expires_at INTEGER NOT NULL,
        redeemed_at INTEGER,
        FOREIGN KEY (discord_id) REFERENCES tcg_users(discord_id) ON DELETE CASCADE
    )');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_tcg_presence_actions_owner
        ON tcg_presence_actions(discord_id, action_type)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_tcg_presence_actions_expires
        ON tcg_presence_actions(expires_at)');
    $done = true;
}

function tcgPresenceActionToken(): string {
    return bin2hex(random_bytes(24));
}

/**
 * @param array<string,mixed> $payload
 */
function tcgPresenceActionMint(string $discordId, string $actionType, array $payload, ?int $ttlSec = null): array {
    $discordId = trim($discordId);
    if ($discordId === '' || !preg_match('/^\d{5,32}$/', $discordId)) {
        throw new Exception('Invalid discord id', 400);
    }
    $actionType = trim($actionType);
    if ($actionType !== TCG_PRESENCE_ACTION_SPECTATE && $actionType !== TCG_PRESENCE_ACTION_RANKED_QUEUE) {
        throw new Exception('Unknown presence action type', 400);
    }
    if ($ttlSec === null) {
        $ttlSec = $actionType === TCG_PRESENCE_ACTION_SPECTATE
            ? TCG_PRESENCE_ACTION_TTL_SPECTATE
            : TCG_PRESENCE_ACTION_TTL_QUEUE;
    }
    $ttlSec = max(60, min(86400, (int)$ttlSec));
    $now = time();
    $token = tcgPresenceActionToken();
    $db = tcgDb();
    tcgPresenceActionsEnsureTable($db);

    // One live token per owner+type — revoke older unused ones.
    $db->prepare(
        'UPDATE tcg_presence_actions SET redeemed_at = ?
         WHERE discord_id = ? AND action_type = ? AND redeemed_at IS NULL AND expires_at > ?'
    )->execute([$now, $discordId, $actionType, $now]);

    $db->prepare(
        'INSERT INTO tcg_presence_actions
            (token, discord_id, action_type, payload_json, created_at, expires_at, redeemed_at)
         VALUES (?, ?, ?, ?, ?, ?, NULL)'
    )->execute([
        $token,
        $discordId,
        $actionType,
        json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        $now,
        $now + $ttlSec,
    ]);

    return [
        'token' => $token,
        'action_type' => $actionType,
        'expires_at' => $now + $ttlSec,
        'deep_link' => tcgPresenceActionDeepLink($token),
    ];
}

function tcgPresenceActionDeepLink(string $token): string {
    return 'https://loveliveradio.ca/tcg/?presence_action=' . rawurlencode($token) . '&play=portrait';
}

/**
 * @return array<string,mixed>
 */
function tcgPresenceActionRedeem(string $token, bool $consume = true): array {
    $token = trim($token);
    if ($token === '' || !preg_match('/^[a-f0-9]{32,96}$/i', $token)) {
        throw new Exception('Invalid presence action', 400);
    }
    $db = tcgDb();
    tcgPresenceActionsEnsureTable($db);
    $stmt = $db->prepare(
        'SELECT token, discord_id, action_type, payload_json, created_at, expires_at, redeemed_at
         FROM tcg_presence_actions WHERE token = ? LIMIT 1'
    );
    $stmt->execute([$token]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new Exception('Presence action not found or already used', 404);
    }
    $now = time();
    if (!empty($row['redeemed_at'])) {
        throw new Exception('Presence action already used', 409);
    }
    if ((int)($row['expires_at'] ?? 0) < $now) {
        throw new Exception('Presence action expired', 410);
    }
    $payload = json_decode((string)($row['payload_json'] ?? '{}'), true);
    if (!is_array($payload)) {
        $payload = [];
    }
    if ($consume) {
        $upd = $db->prepare(
            'UPDATE tcg_presence_actions SET redeemed_at = ?
             WHERE token = ? AND redeemed_at IS NULL'
        );
        $upd->execute([$now, $token]);
        if ($upd->rowCount() < 1) {
            throw new Exception('Presence action already used', 409);
        }
    }
    return [
        'token' => (string)$row['token'],
        'owner_discord_id' => (string)$row['discord_id'],
        'action_type' => (string)$row['action_type'],
        'payload' => $payload,
        'expires_at' => (int)$row['expires_at'],
    ];
}

function tcgPresenceActionPurgeExpired(?int $now = null): int {
    $now = $now ?? time();
    $db = tcgDb();
    tcgPresenceActionsEnsureTable($db);
    $stmt = $db->prepare('DELETE FROM tcg_presence_actions WHERE expires_at < ? OR redeemed_at IS NOT NULL AND redeemed_at < ?');
    // Keep redeemed rows briefly for debugging; purge redeemed older than 1 day and expired unused.
    $stmt->execute([$now, $now - 86400]);
    return $stmt->rowCount();
}

/**
 * Validate that a spectate presence action still points at a live PvP room.
 *
 * @param array<string,mixed> $payload
 * @return array{room_id:string,spectate_url:string}
 */
function tcgPresenceActionLoadGame(string $roomId): ?array {
    if (function_exists('loadGame')) {
        return loadGame($roomId);
    }
    if (!defined('GAMES_DIR')) {
        return null;
    }
    $safe = preg_replace('/[^A-Z0-9]/', '', strtoupper($roomId));
    if ($safe === '') {
        return null;
    }
    $path = GAMES_DIR . $safe . '.json';
    if (!is_file($path)) {
        return null;
    }
    $raw = @file_get_contents($path);
    if ($raw === false) {
        return null;
    }
    $state = json_decode($raw, true);
    return is_array($state) ? $state : null;
}

function tcgPresenceActionValidateSpectate(array $payload): array {
    $roomId = strtoupper(trim((string)($payload['room_id'] ?? '')));
    if ($roomId === '' || !preg_match('/^[A-Z0-9]{4,16}$/', $roomId)) {
        throw new Exception('Invalid spectate room', 400);
    }
    if (!function_exists('tcgIsSpectatableHumanGame')) {
        require_once __DIR__ . '/spectate.php';
    }
    $state = tcgPresenceActionLoadGame($roomId);
    if (!$state || !tcgIsSpectatableHumanGame($state, $roomId)) {
        throw new Exception('That match is no longer available to spectate', 409);
    }
    $spectateUrl = function_exists('tcgPublicSpectateUrl')
        ? tcgPublicSpectateUrl($roomId)
        : ('https://loveliveradio.ca/tcg/?spectate=' . rawurlencode($roomId));
    return [
        'room_id' => $roomId,
        'spectate_url' => $spectateUrl,
    ];
}

/**
 * @return array{discord_id:string,game_mode:string,joined_at:int,rating:int}|null
 */
function tcgPresenceQueueRow(string $discordId, ?string $gameMode = null): ?array {
    require_once __DIR__ . '/matchmaking.php';
    $db = tcgDb();
    if ($gameMode !== null && $gameMode !== '') {
        $gameMode = tcgNormalizeGameMode($gameMode);
        $stmt = $db->prepare(
            'SELECT discord_id, game_mode, joined_at, rating FROM tcg_match_queue
             WHERE discord_id = ? AND game_mode = ? LIMIT 1'
        );
        $stmt->execute([$discordId, $gameMode]);
    } else {
        $stmt = $db->prepare(
            'SELECT discord_id, game_mode, joined_at, rating FROM tcg_match_queue
             WHERE discord_id = ? LIMIT 1'
        );
        $stmt->execute([$discordId]);
    }
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Validate that a ranked-queue presence action still has the owner waiting.
 *
 * @param array<string,mixed> $payload
 * @return array{challenge_discord_id:string,game_mode:string}
 */
function tcgPresenceActionValidateRankedQueue(string $ownerDiscordId, array $payload): array {
    require_once __DIR__ . '/game_mode.php';
    $gameMode = tcgNormalizeRankedGameMode($payload['game_mode'] ?? TCG_GAME_MODE_STANDARD);
    $ownerDiscordId = trim($ownerDiscordId);
    if ($ownerDiscordId === '') {
        throw new Exception('Missing queue owner', 400);
    }
    $row = tcgPresenceQueueRow($ownerDiscordId, $gameMode);
    if (!$row) {
        $row = tcgPresenceQueueRow($ownerDiscordId, null);
        if (!$row) {
            throw new Exception('That player is no longer waiting for a ranked match', 409);
        }
        $gameMode = tcgNormalizeRankedGameMode($row['game_mode'] ?? $gameMode);
    }
    return [
        'challenge_discord_id' => $ownerDiscordId,
        'game_mode' => $gameMode,
    ];
}

/**
 * @param array<string,mixed> $body
 * @return array<string,mixed>
 */
function tcgApiPresenceActionMint(array $body): array {
    tcgRateLimitForAction('presence_action_mint', $body);
    $uid = tcgRequireAuthUser($body);
    tcgEnsureUser($uid, tcgAuthUserProfile($uid));
    $actionType = trim((string)($body['action_type'] ?? ''));
    $payload = [];

    if ($actionType === TCG_PRESENCE_ACTION_SPECTATE) {
        $roomId = strtoupper(trim((string)($body['room_id'] ?? '')));
        $payload = tcgPresenceActionValidateSpectate(['room_id' => $roomId]);
        // Confirm the caller is seated in that room.
        $state = tcgPresenceActionLoadGame($roomId) ?: [];
        $p1 = (string)($state['players']['p1']['discord_id'] ?? ($state['ranked']['p1_discord_id'] ?? ''));
        $p2 = (string)($state['players']['p2']['discord_id'] ?? ($state['ranked']['p2_discord_id'] ?? ''));
        if ($uid !== $p1 && $uid !== $p2) {
            throw new Exception('You are not in that match', 403);
        }
    } elseif ($actionType === TCG_PRESENCE_ACTION_RANKED_QUEUE) {
        require_once __DIR__ . '/game_mode.php';
        $gameMode = tcgNormalizeRankedGameMode($body['game_mode'] ?? TCG_GAME_MODE_STANDARD);
        $row = tcgPresenceQueueRow($uid, $gameMode) ?: tcgPresenceQueueRow($uid, null);
        if (!$row) {
            throw new Exception('You are not waiting in ranked queue', 409);
        }
        $gameMode = tcgNormalizeRankedGameMode($row['game_mode'] ?? $gameMode);
        $payload = ['game_mode' => $gameMode];
    } else {
        throw new Exception('Unknown presence action type', 400);
    }

    $minted = tcgPresenceActionMint($uid, $actionType, $payload);
    return ['success' => true] + $minted;
}

/**
 * Peek or redeem. Auth required for ranked_queue redeem (join); spectate redeem is public.
 *
 * @param array<string,mixed> $body
 * @return array<string,mixed>
 */
function tcgApiPresenceActionRedeem(array $body): array {
    tcgRateLimitForAction('presence_action_redeem', $body);
    $token = trim((string)($body['token'] ?? ($_GET['token'] ?? '')));
    $peek = !empty($body['peek']);
    $row = tcgPresenceActionRedeem($token, !$peek);
    $actionType = (string)$row['action_type'];
    $payload = $row['payload'];
    $owner = (string)$row['owner_discord_id'];

    if ($actionType === TCG_PRESENCE_ACTION_SPECTATE) {
        if ($peek) {
            return [
                'success' => true,
                'action' => 'spectate',
                'room_id' => strtoupper(trim((string)($payload['room_id'] ?? ''))),
                'peek' => true,
            ];
        }
        $validated = tcgPresenceActionValidateSpectate($payload);
        return [
            'success' => true,
            'action' => 'spectate',
            'room_id' => $validated['room_id'],
            'spectate_url' => $validated['spectate_url'],
            'peek' => false,
        ];
    }

    if ($actionType === TCG_PRESENCE_ACTION_RANKED_QUEUE) {
        require_once __DIR__ . '/game_mode.php';
        $gameMode = tcgNormalizeRankedGameMode($payload['game_mode'] ?? TCG_GAME_MODE_STANDARD);
        if ($peek) {
            return [
                'success' => true,
                'action' => 'ranked_queue',
                'game_mode' => $gameMode,
                'peek' => true,
            ];
        }
        // Joining a ranked queue always requires Loveca website sign-in.
        $uid = tcgRequireAuthUser($body);
        if ($uid === $owner) {
            throw new Exception('That presence link is for your own queue', 400);
        }
        $validated = tcgPresenceActionValidateRankedQueue($owner, $payload);
        return [
            'success' => true,
            'action' => 'ranked_queue',
            'challenge_discord_id' => $validated['challenge_discord_id'],
            'game_mode' => $validated['game_mode'],
            'peek' => false,
        ];
    }

    throw new Exception('Unknown presence action type', 400);
}
