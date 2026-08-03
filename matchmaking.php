<?php
/**
 * Ranked PvP matchmaking queue (SQLite-backed).
 *
 * Pairs players by ELO band (TCG_RATING_BAND) within the same game_mode, creates
 * ranked game rooms via ranked_room.php, and tracks queue/active-game rows for reconnect.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/game_mode.php';

const TCG_QUEUE_MAX_WAIT = 300;
const TCG_RATING_BAND = 150;

function tcgRankRow(string $discordId, string $gameMode = TCG_GAME_MODE_STANDARD): array {
    $gameMode = tcgNormalizeGameMode($gameMode);
    $db = tcgDb();
    $stmt = $db->prepare('SELECT * FROM tcg_rank WHERE discord_id = ? AND game_mode = ?');
    $stmt->execute([$discordId, $gameMode]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        return $row;
    }
    $now = time();
    $db->prepare('INSERT INTO tcg_rank (discord_id, game_mode, updated_at) VALUES (?, ?, ?)')
        ->execute([$discordId, $gameMode, $now]);
    return [
        'discord_id' => $discordId,
        'game_mode' => $gameMode,
        'rating' => 1000,
        'wins' => 0,
        'losses' => 0,
        'draws' => 0,
        'games' => 0,
        'updated_at' => $now,
    ];
}

function tcgQueueJoin(string $discordId, string $gameMode = TCG_GAME_MODE_STANDARD): array {
    $gameMode = tcgNormalizeGameMode($gameMode);
    return tcgDbRetry(function () use ($discordId, $gameMode) {
        $rank = tcgRankRow($discordId, $gameMode);
        $db = tcgDb();
        $now = time();
        // One active ranked search at a time across modes.
        $db->prepare('DELETE FROM tcg_match_queue WHERE discord_id = ?')->execute([$discordId]);
        $db->prepare('INSERT INTO tcg_match_queue (discord_id, game_mode, rating, joined_at) VALUES (?, ?, ?, ?)
            ON CONFLICT(discord_id, game_mode) DO UPDATE SET rating = excluded.rating, joined_at = excluded.joined_at')
            ->execute([$discordId, $gameMode, intval($rank['rating']), $now]);
        return [
            'queued' => true,
            'rating' => intval($rank['rating']),
            'joined_at' => $now,
            'game_mode' => $gameMode,
        ];
    });
}

function tcgQueueLeave(string $discordId, ?string $gameMode = null): array {
    $db = tcgDb();
    if ($gameMode === null || $gameMode === '') {
        $db->prepare('DELETE FROM tcg_match_queue WHERE discord_id = ?')->execute([$discordId]);
    } else {
        $gameMode = tcgNormalizeGameMode($gameMode);
        $db->prepare('DELETE FROM tcg_match_queue WHERE discord_id = ? AND game_mode = ?')
            ->execute([$discordId, $gameMode]);
    }
    return ['queued' => false];
}

function tcgQueueStatus(string $discordId): array {
    $db = tcgDb();
    $stmt = $db->prepare('SELECT joined_at, rating, game_mode FROM tcg_match_queue WHERE discord_id = ? LIMIT 1');
    $stmt->execute([$discordId]);
    $q = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt = $db->prepare('SELECT * FROM tcg_ranked_matches WHERE (p1_id = ? OR p2_id = ?) AND status = "pending" ORDER BY created_at DESC LIMIT 1');
    $stmt->execute([$discordId, $discordId]);
    $match = tcgSanitizeRankedMatchRow($stmt->fetch(PDO::FETCH_ASSOC));

    if ($match) {
        $isP1 = $match['p1_id'] === $discordId;
        $roomId = (string)($match['room_id'] ?? '');
        $localFile = $roomId !== '' && is_file(tcgRankedGameFilePath($roomId));
        return [
            'status' => 'matched',
            'room_id' => $match['room_id'],
            'player_token' => $isP1 ? $match['p1_token'] : $match['p2_token'],
            'player_id' => $isP1 ? 'p1' : 'p2',
            'opponent_id' => $isP1 ? $match['p2_id'] : $match['p1_id'],
            'match_id' => $match['match_id'],
            'game_mode' => tcgNormalizeGameMode($match['game_mode'] ?? TCG_GAME_MODE_STANDARD),
            'match_api' => $localFile ? 'hostinger' : 'overflow',
        ];
    }

    if ($q) {
        $wait = time() - intval($q['joined_at']);
        return [
            'status' => 'searching',
            'rating' => intval($q['rating']),
            'wait_seconds' => $wait,
            'game_mode' => tcgNormalizeGameMode($q['game_mode'] ?? TCG_GAME_MODE_STANDARD),
        ];
    }

    return ['status' => 'idle'];
}

function tcgFindQueueOpponent(string $discordId, int $rating, string $gameMode = TCG_GAME_MODE_STANDARD): ?array {
    $gameMode = tcgNormalizeGameMode($gameMode);
    $db = tcgDb();
    $stmt = $db->prepare('SELECT discord_id, rating, joined_at, game_mode FROM tcg_match_queue
        WHERE discord_id != ? AND game_mode = ?
        ORDER BY ABS(rating - ?) ASC, joined_at ASC
        LIMIT 10');
    $stmt->execute([$discordId, $gameMode, $rating]);
    $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($candidates)) {
        return null;
    }
    foreach ($candidates as $c) {
        if (abs(intval($c['rating']) - $rating) <= TCG_RATING_BAND) {
            return $c;
        }
    }
    return $candidates[0];
}

function tcgCreateRankedMatchRecord(
    string $roomId,
    string $p1Id,
    string $p2Id,
    string $p1Token,
    string $p2Token,
    string $gameMode = TCG_GAME_MODE_STANDARD
): string {
    $gameMode = tcgNormalizeGameMode($gameMode);
    $db = tcgDb();
    $matchId = strtoupper(substr(md5(uniqid((string)mt_rand(), true)), 0, 12));
    $now = time();
    $db->prepare('INSERT INTO tcg_ranked_matches
        (match_id, room_id, p1_id, p2_id, p1_token, p2_token, status, created_at, game_mode)
        VALUES (?, ?, ?, ?, ?, ?, "pending", ?, ?)')
        ->execute([$matchId, $roomId, $p1Id, $p2Id, $p1Token, $p2Token, $now, $gameMode]);
    $db->prepare('DELETE FROM tcg_match_queue WHERE discord_id IN (?, ?)')->execute([$p1Id, $p2Id]);
    return $matchId;
}

function tcgApplyRankResult(
    string $winnerId,
    string $loserId,
    bool $isDraw = false,
    string $gameMode = TCG_GAME_MODE_STANDARD
): void {
    $gameMode = tcgNormalizeGameMode($gameMode);
    $db = tcgDb();
    $now = time();
    if ($isDraw) {
        foreach ([$winnerId, $loserId] as $uid) {
            tcgRankRow($uid, $gameMode);
            $db->prepare('UPDATE tcg_rank SET draws = draws + 1, games = games + 1, updated_at = ?
                WHERE discord_id = ? AND game_mode = ?')
                ->execute([$now, $uid, $gameMode]);
        }
        return;
    }
    $w = tcgRankRow($winnerId, $gameMode);
    $l = tcgRankRow($loserId, $gameMode);
    $wRating = intval($w['rating']);
    $lRating = intval($l['rating']);
    $k = 32;
    $expectedW = 1 / (1 + pow(10, ($lRating - $wRating) / 400));
    $delta = (int)round($k * (1 - $expectedW));
    $db->prepare('UPDATE tcg_rank SET rating = rating + ?, wins = wins + 1, games = games + 1, updated_at = ?
        WHERE discord_id = ? AND game_mode = ?')
        ->execute([$delta, $now, $winnerId, $gameMode]);
    $db->prepare('UPDATE tcg_rank SET rating = MAX(100, rating - ?), losses = losses + 1, games = games + 1, updated_at = ?
        WHERE discord_id = ? AND game_mode = ?')
        ->execute([$delta, $now, $loserId, $gameMode]);
}

function tcgCompleteRankedMatch(string $roomId): void {
    tcgDb()->prepare('UPDATE tcg_ranked_matches SET status = "done" WHERE room_id = ?')->execute([$roomId]);
}

/**
 * Apply Elo/PR from a VPS finish webhook (Hostinger account DB).
 *
 * @param array<string,mixed> $body
 * @return array<string,mixed>
 */
function tcgApplyRankedResultFromWebhook(array $body): array {
    require_once __DIR__ . '/game_mode.php';
    $roomId = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string)($body['room_id'] ?? '')) ?? '');
    if ($roomId === '') {
        throw new Exception('room_id required', 400);
    }
    $p1Id = trim((string)($body['p1_discord_id'] ?? ''));
    $p2Id = trim((string)($body['p2_discord_id'] ?? ''));
    if ($p1Id === '' || $p2Id === '') {
        throw new Exception('p1_discord_id and p2_discord_id required', 400);
    }
    $gameMode = tcgNormalizeGameMode($body['game_mode'] ?? TCG_GAME_MODE_STANDARD);

    // Idempotent: pending row already done.
    $db = tcgDb();
    $stmt = $db->prepare('SELECT status FROM tcg_ranked_matches WHERE room_id = ? ORDER BY created_at DESC LIMIT 1');
    $stmt->execute([$roomId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row && ($row['status'] ?? '') === 'done') {
        return ['success' => true, 'already_applied' => true, 'room_id' => $roomId];
    }

    $winnerPid = $body['winner'] ?? null;
    if ($winnerPid === 'p1') {
        tcgApplyRankResult($p1Id, $p2Id, false, $gameMode);
    } elseif ($winnerPid === 'p2') {
        tcgApplyRankResult($p2Id, $p1Id, false, $gameMode);
    } else {
        tcgApplyRankResult($p1Id, $p2Id, true, $gameMode);
    }
    tcgCompleteRankedMatch($roomId);

    try {
        require_once __DIR__ . '/ranked_pr_rewards.php';
        $fakeState = [
            'room_id' => $roomId,
            'mode' => 'ranked',
            'winner' => $winnerPid,
            'end_reason' => $body['end_reason'] ?? null,
            'resigned_by' => $body['resigned_by'] ?? null,
            'disconnected_player' => $body['disconnected_player'] ?? null,
            'ranked' => [
                'p1_discord_id' => $p1Id,
                'p2_discord_id' => $p2Id,
                'game_mode' => $gameMode,
                'applied' => true,
            ],
            'players' => [
                'p1' => ['discord_id' => $p1Id],
                'p2' => ['discord_id' => $p2Id],
            ],
        ];
        tcgApplyRankedPrRewardOnFinish($fakeState);
    } catch (Throwable $e) {
        // Elo already applied.
    }

    return ['success' => true, 'room_id' => $roomId];
}

/** Drop pending ranked rows whose game is missing or already finished (Hostinger file or VPS Redis). */
function tcgSanitizeRankedMatchRow(array|false|null $row): ?array {
    if (!is_array($row)) {
        return null;
    }
    $roomId = $row['room_id'] ?? '';
    if ($roomId === '') {
        return null;
    }
    $path = tcgRankedGameFilePath($roomId);
    if (!is_file($path)) {
        // New ranked rooms live on VPS Redis — probe before clearing the Hostinger row.
        require_once __DIR__ . '/match_bridge.php';
        $token = (string)($row['p1_token'] ?? '');
        if ($token === '') {
            $token = (string)($row['p2_token'] ?? '');
        }
        $probe = tcgProbeOverflowRankedRoom($roomId, $token);
        if ($probe === 'live' || $probe === 'unknown') {
            return $row;
        }
        tcgCompleteRankedMatch($roomId);
        return null;
    }
    $state = json_decode((string)file_get_contents($path), true);
    if (!is_array($state) || ($state['mode'] ?? '') !== 'ranked') {
        tcgCompleteRankedMatch($roomId);
        return null;
    }
    if (($state['status'] ?? '') === 'finished') {
        if (empty($state['ranked']['applied'])) {
            require_once __DIR__ . '/ranked_room.php';
            tcgOnGameFinished($state);
            file_put_contents($path, json_encode($state, JSON_UNESCAPED_UNICODE));
        }
        tcgCompleteRankedMatch($roomId);
        return null;
    }
    if (tcgRankedMatchRowIsStale($roomId, $state, $row)) {
        tcgCompleteRankedMatch($roomId);
        return null;
    }
    return $row;
}

/** Clear abandoned ranked rows (no ELO change) so players can queue again. */
function tcgRankedMatchRowIsStale(string $roomId, array $state, array $row): bool {
    $path = tcgRankedGameFilePath($roomId);
    if (!is_file($path)) {
        return true;
    }
    $now = time();
    $fileAge = $now - filemtime($path);
    $created = intval($row['created_at'] ?? 0);
    $matchAge = $created > 0 ? ($now - $created) : $fileAge;

    if ($matchAge >= 6 * 3600) {
        return true;
    }
    if ($fileAge >= 45 * 60) {
        return true;
    }

    $p1Token = $state['players']['p1']['token'] ?? '';
    $p2Token = $state['players']['p2']['token'] ?? '';
    $presenceFile = tcgPath('games') . 'presence_' . preg_replace('/[^A-Z0-9]/', '', strtoupper($roomId)) . '.json';
    if (!is_file($presenceFile)) {
        return $matchAge >= 5 * 60;
    }
    $presence = json_decode((string)file_get_contents($presenceFile), true);
    if (!is_array($presence)) {
        return $matchAge >= 5 * 60;
    }
    $last1 = intval($presence[$p1Token] ?? 0);
    $last2 = intval($presence[$p2Token] ?? 0);
    $latest = max($last1, $last2);
    if ($latest === 0) {
        return $matchAge >= 5 * 60;
    }
    return ($now - $latest) >= 10 * 60 && $fileAge >= 5 * 60;
}

/**
 * Resign or clear a stuck ranked match so the player can return to the hub.
 *
 * Without confirm_resign: only clear the DB row when the room is missing/finished.
 * Never auto-concede a live room (VPS miss / reconnect cleanup used to free-win the opponent).
 * Options "Leave active match" must pass confirm_resign=1.
 * Overflow-seeded rooms resign via the VPS match API (Elo webhook on finish).
 */
function tcgAbandonActiveRankedGame(string $discordId, array $opts = []): array {
    $confirmResign = !empty($opts['confirm_resign']) || !empty($opts['force']);
    $db = tcgDb();
    $stmt = $db->prepare('SELECT room_id, p1_id, p2_id, p1_token, p2_token FROM tcg_ranked_matches
        WHERE status = "pending" AND (p1_id = ? OR p2_id = ?) ORDER BY created_at DESC LIMIT 1');
    $stmt->execute([$discordId, $discordId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return ['left' => false];
    }

    $roomId = $row['room_id'] ?? '';
    $isP1 = ($row['p1_id'] ?? '') === $discordId;
    $token = $isP1 ? ($row['p1_token'] ?? '') : ($row['p2_token'] ?? '');
    $localPath = $roomId !== '' ? tcgRankedGameFilePath($roomId) : '';
    $hasLocalFile = $localPath !== '' && is_file($localPath);

    if ($roomId !== '' && $token !== '') {
        if (!defined('TCG_API_LIB_ONLY')) {
            define('TCG_API_LIB_ONLY', true);
        }
        require_once __DIR__ . '/api.php';
        require_once __DIR__ . '/ranked_room.php';
        require_once __DIR__ . '/match_bridge.php';

        if ($hasLocalFile) {
            try {
                $guard = withLock($roomId, function () use ($roomId, $token, $confirmResign) {
                    $state = loadGame($roomId);
                    if (!$state) {
                        return ['missing' => true];
                    }
                    if (($state['status'] ?? '') === 'finished') {
                        if (($state['mode'] ?? '') === 'ranked' && empty($state['ranked']['applied'])) {
                            tcgOnGameFinished($state);
                            saveGame($roomId, $state);
                        }
                        return ['finished' => true];
                    }

                    // Live match: only Options/explicit leave may concede.
                    if (!$confirmResign) {
                        return ['blocked' => true, 'code' => 'match_still_live'];
                    }

                    $playerId = getPlayerIdByToken($state, $token);
                    if (!$playerId) {
                        return ['missing' => true];
                    }
                    $state = applyAction($state, $playerId, 'resign', []);
                    saveGame($roomId, $state);
                    tcgOnGameFinished($state);
                    saveGame($roomId, $state);
                    return ['resigned' => true];
                });
                if (is_array($guard) && !empty($guard['blocked'])) {
                    return [
                        'left' => false,
                        'code' => (string)($guard['code'] ?? 'match_still_live'),
                        'room_id' => $roomId,
                    ];
                }
            } catch (Throwable $e) {
                // Game file missing or lock failed — still clear the ranked row below.
            }
        } else {
            // VPS Redis room (no Hostinger games/*.json).
            $probe = tcgProbeOverflowRankedRoom($roomId, $token);
            if ($probe === 'live' || $probe === 'unknown') {
                if (!$confirmResign) {
                    return [
                        'left' => false,
                        'code' => 'match_still_live',
                        'room_id' => $roomId,
                    ];
                }
                tcgResignRankedRoomOnVps($roomId, $token);
            }
            // missing/finished: clear Hostinger pending row (Elo already applied via webhook if finished).
        }
    }
    tcgCompleteRankedMatch($roomId);

    return ['left' => true, 'room_id' => $roomId];
}

function tcgRankedGameFilePath(string $roomId): string {
    return tcgPath('games') . preg_replace('/[^A-Z0-9]/', '', strtoupper($roomId)) . '.json';
}

/** Public queue stats for the ranked menu (waiting in lobby vs in active ranked games). */
function tcgQueuePublicStats(?string $gameMode = null): array {
    $gameMode = tcgNormalizeGameMode($gameMode ?? TCG_GAME_MODE_STANDARD);
    $cacheFile = tcgPath('data') . 'queue_stats_cache_' . preg_replace('/[^a-z0-9_]/', '', $gameMode) . '.json';
    if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < 5) {
        $cached = json_decode((string)file_get_contents($cacheFile), true);
        if (is_array($cached) && isset($cached['waiting'], $cached['in_game'])) {
            return $cached;
        }
    }

    $db = tcgDb();
    $stmt = $db->prepare('SELECT COUNT(*) FROM tcg_match_queue WHERE game_mode = ?');
    $stmt->execute([$gameMode]);
    $waiting = (int)$stmt->fetchColumn();

    $inGame = 0;
    $seen = [];
    $stmt = $db->prepare('SELECT room_id, p1_id, p2_id, game_mode FROM tcg_ranked_matches WHERE status = "pending" AND game_mode = ?');
    $stmt->execute([$gameMode]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $roomId = $row['room_id'] ?? '';
        $path = tcgRankedGameFilePath($roomId);
        if (!is_file($path)) {
            continue;
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            continue;
        }
        $state = json_decode($raw, true);
        if (!is_array($state) || ($state['mode'] ?? '') !== 'ranked') {
            continue;
        }
        if (($state['status'] ?? '') === 'finished') {
            if ($roomId !== '') {
                tcgCompleteRankedMatch($roomId);
            }
            continue;
        }
        foreach (['p1_id', 'p2_id'] as $col) {
            $uid = $row[$col] ?? '';
            if ($uid && !isset($seen[$uid])) {
                $seen[$uid] = true;
                $inGame++;
            }
        }
    }

    $stats = ['waiting' => $waiting, 'in_game' => $inGame, 'game_mode' => $gameMode];
    @file_put_contents($cacheFile, json_encode($stats), LOCK_EX);
    return $stats;
}

/** Active ranked game for a logged-in player (reconnect after refresh / new tab). */
function tcgGetActiveRankedGame(string $discordId): ?array {
    $db = tcgDb();
    $stmt = $db->prepare('SELECT room_id, p1_id, p2_id, p1_token, p2_token, created_at, game_mode FROM tcg_ranked_matches
        WHERE status = "pending" AND (p1_id = ? OR p2_id = ?) ORDER BY created_at DESC LIMIT 1');
    $stmt->execute([$discordId, $discordId]);
    $row = tcgSanitizeRankedMatchRow($stmt->fetch(PDO::FETCH_ASSOC));
    if (!$row) {
        return null;
    }
    $roomId = $row['room_id'] ?? '';
    $isP1 = ($row['p1_id'] ?? '') === $discordId;
    $localFile = $roomId !== '' && is_file(tcgRankedGameFilePath($roomId));
    return [
        'room_id' => $roomId,
        'player_token' => $isP1 ? ($row['p1_token'] ?? '') : ($row['p2_token'] ?? ''),
        'player_id' => $isP1 ? 'p1' : 'p2',
        'mode' => 'ranked',
        'match_api' => $localFile ? 'hostinger' : 'overflow',
        'game_mode' => tcgNormalizeGameMode($row['game_mode'] ?? TCG_GAME_MODE_STANDARD),
    ];
}
