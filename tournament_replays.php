<?php
/**
 * Durable tournament game replays: archive on resolve, public watch, personal autosave.
 */

/**
 * @return array<string,mixed>|null
 */
function tcgTournamentReplayFindByRoom(string $tournamentId, string $roomId): ?array {
    $tournamentId = strtoupper(trim($tournamentId));
    $roomId = strtoupper(trim($roomId));
    if ($tournamentId === '' || $roomId === '') {
        return null;
    }
    $stmt = tcgDb()->prepare(
        'SELECT * FROM tcg_tournament_replays WHERE tournament_id = ? AND room_id = ? LIMIT 1'
    );
    $stmt->execute([$tournamentId, $roomId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * @return array<string,mixed>|null
 */
function tcgTournamentReplayLoad(int $id): ?array {
    if ($id <= 0) {
        return null;
    }
    $stmt = tcgDb()->prepare('SELECT * FROM tcg_tournament_replays WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * @param array<string,mixed> $row
 * @return array<string,mixed>
 */
function tcgTournamentReplayPublicSummary(array $row): array {
    return [
        'id' => (int)$row['id'],
        'tournament_id' => (string)$row['tournament_id'],
        'match_id' => (string)$row['match_id'],
        'room_id' => (string)$row['room_id'],
        'game_index' => (int)($row['game_index'] ?? 1),
        'winner_discord_id' => $row['winner_discord_id'] !== null && $row['winner_discord_id'] !== ''
            ? (string)$row['winner_discord_id'] : null,
        'end_reason' => $row['end_reason'] !== null && $row['end_reason'] !== ''
            ? (string)$row['end_reason'] : null,
        'action_count' => (int)($row['action_count'] ?? 0),
        'duration_seconds' => (int)($row['duration_seconds'] ?? 0),
        'saved_at' => (int)($row['saved_at'] ?? 0),
    ];
}

function tcgTournamentReplayUserName(string $discordId): string {
    if ($discordId === '') {
        return '';
    }
    $stmt = tcgDb()->prepare('SELECT username FROM tcg_users WHERE discord_id = ?');
    $stmt->execute([$discordId]);
    $name = trim((string)($stmt->fetchColumn() ?: ''));
    return $name !== '' ? $name : $discordId;
}

/**
 * Export a finished tournament room from local store or VPS overflow.
 *
 * @return array<string,mixed>|null payload or null when unavailable / empty
 */
function tcgTournamentExportRoomReplay(string $roomId, string $token): ?array {
    $roomId = strtoupper(trim($roomId));
    $token = trim($token);
    if ($roomId === '' || $token === '') {
        return null;
    }

    if (function_exists('loadGame') && function_exists('getPlayerIdByToken') && function_exists('buildReplayExportPayload')) {
        $state = loadGame($roomId);
        if (is_array($state) && ($state['status'] ?? '') === 'finished') {
            $pid = getPlayerIdByToken($state, $token);
            if ($pid === 'p1' || $pid === 'p2') {
                $payload = buildReplayExportPayload($state, $pid);
                if (is_array($payload) && count($payload['actions'] ?? []) > 0) {
                    return ensureReplayPayloadV2($payload);
                }
            }
        }
    }

    if (!function_exists('tcgFetchOverflowReplayExportWithRetry')) {
        require_once __DIR__ . '/match_bridge.php';
    }
    try {
        $payload = tcgFetchOverflowReplayExportWithRetry(
            $roomId,
            $token,
            [0, 400, 1200, 2800]
        );
        if (!is_array($payload)) {
            return null;
        }
        validateReplayFile($payload);
        $payload = ensureReplayPayloadV2($payload);
        if (count($payload['actions'] ?? []) === 0) {
            return null;
        }
        return $payload;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Insert / skip personal Recent autosave for one seat (server-side, no client auth).
 *
 * @param array<string,mixed> $payload
 */
function tcgTournamentAutosavePersonalFromPayload(
    string $discordId,
    string $saverPid,
    string $opponentName,
    array $payload,
    string $roomId
): void {
    if ($discordId === '' || ($saverPid !== 'p1' && $saverPid !== 'p2')) {
        return;
    }
    if (!function_exists('tcgReplayFindOwnedByRoom') || !function_exists('replayPayloadEncodeForStorage')) {
        return;
    }
    $existing = tcgReplayFindOwnedByRoom($discordId, $roomId);
    if ($existing && function_exists('tcgReplayRowNeedsRepair') && !tcgReplayRowNeedsRepair($existing)) {
        return;
    }
    if ($existing) {
        tcgDb()->prepare('DELETE FROM tcg_replays WHERE id = ? AND discord_id = ?')
            ->execute([(int)$existing['id'], $discordId]);
    }

    if (function_exists('tcgEnsureUser')) {
        tcgEnsureUser($discordId, []);
    }

    $seatPayload = $payload;
    $seatPayload['meta'] = is_array($seatPayload['meta'] ?? null) ? $seatPayload['meta'] : [];
    $seatPayload['meta']['saver_player_id'] = $saverPid;
    $seatName = (string)($seatPayload['baseline']['players'][$saverPid]['name'] ?? $saverPid);
    $seatPayload['meta']['saver_name'] = $seatName;

    $meta = $seatPayload['meta'];
    $winner = $seatPayload['baseline']['winner'] ?? null;
    $endReason = $seatPayload['baseline']['end_reason'] ?? null;
    $frames = is_array($seatPayload['frames'] ?? null) ? $seatPayload['frames'] : [];
    if (($winner === null || $winner === '') && $frames !== []) {
        $last = $frames[count($frames) - 1];
        if (is_array($last)) {
            $winner = $last['winner'] ?? $winner;
            $endReason = $last['end_reason'] ?? $endReason;
        }
    }

    $payloadJson = replayPayloadEncodeForStorage($seatPayload);
    $db = tcgDb();
    $db->prepare('INSERT INTO tcg_replays (
            discord_id, room_id, saver_player_id, saver_name, opponent_name, winner, end_reason,
            turn, phase, action_count, duration_seconds, payload_json, saved_at, preserved
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)')
        ->execute([
            $discordId,
            (string)($meta['room_id'] ?? $roomId),
            $saverPid,
            $seatName,
            $opponentName,
            $winner,
            $endReason,
            (int)($meta['turn'] ?? ($seatPayload['baseline']['turn'] ?? 0)),
            (string)($meta['phase'] ?? ($seatPayload['baseline']['phase'] ?? '')),
            count($seatPayload['actions'] ?? []),
            (int)($meta['duration_seconds'] ?? 0),
            $payloadJson,
            time(),
        ]);
    if (function_exists('tcgReplayTrimAutosaves')) {
        tcgReplayTrimAutosaves($discordId, 10);
    }
}

/**
 * Archive one finished tournament game while match tokens are still valid.
 * Also mirrors into both players' personal Recent replay lists.
 *
 * @param array<string,mixed> $matchRow raw tcg_tournament_matches row (with tokens)
 * @return int|null public replay id
 */
function tcgTournamentArchiveFinishedGameReplay(
    array $matchRow,
    string $roomId,
    string $winnerDiscordId,
    string $reason,
    int $gameIndex
): ?int {
    $tournamentId = strtoupper(trim((string)($matchRow['tournament_id'] ?? '')));
    $matchId = (string)($matchRow['id'] ?? '');
    $roomId = strtoupper(trim($roomId));
    if ($tournamentId === '' || $matchId === '' || $roomId === '') {
        return null;
    }

    $existing = tcgTournamentReplayFindByRoom($tournamentId, $roomId);
    if ($existing) {
        return (int)$existing['id'];
    }

    $token = trim((string)($matchRow['p1_token'] ?? ''));
    if ($token === '') {
        $token = trim((string)($matchRow['p2_token'] ?? ''));
    }
    $payload = tcgTournamentExportRoomReplay($roomId, $token);
    if ($payload === null && trim((string)($matchRow['p2_token'] ?? '')) !== ''
        && trim((string)($matchRow['p2_token'] ?? '')) !== $token) {
        $payload = tcgTournamentExportRoomReplay($roomId, trim((string)$matchRow['p2_token']));
    }
    if ($payload === null) {
        return null;
    }

    $title = '';
    $tRow = function_exists('tcgTournamentFetch') ? tcgTournamentFetch($tournamentId) : null;
    if (is_array($tRow)) {
        $title = trim((string)($tRow['title'] ?? ''));
    }
    $titleTag = $title !== '' ? $title : 'Tournament';
    if (function_exists('mb_strlen') && mb_strlen($titleTag) > 28) {
        $titleTag = mb_substr($titleTag, 0, 27) . '…';
    } elseif (strlen($titleTag) > 28) {
        $titleTag = substr($titleTag, 0, 27) . '...';
    }

    $meta = is_array($payload['meta'] ?? null) ? $payload['meta'] : [];
    $meta['room_id'] = $roomId;
    $meta['mode'] = 'tournament';
    $meta['tournament_id'] = $tournamentId;
    $meta['tournament_match_id'] = $matchId;
    $meta['tournament_game_index'] = max(1, $gameIndex);
    $payload['meta'] = $meta;

    $payloadJson = replayPayloadEncodeForStorage($payload);
    $now = time();
    $db = tcgDb();
    try {
        $db->prepare('INSERT INTO tcg_tournament_replays (
                tournament_id, match_id, room_id, game_index, winner_discord_id, end_reason,
                action_count, duration_seconds, payload_json, saved_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([
                $tournamentId,
                $matchId,
                $roomId,
                max(1, $gameIndex),
                $winnerDiscordId !== '' ? $winnerDiscordId : null,
                $reason !== '' ? $reason : null,
                count($payload['actions'] ?? []),
                (int)($meta['duration_seconds'] ?? 0),
                $payloadJson,
                $now,
            ]);
    } catch (Throwable $e) {
        $again = tcgTournamentReplayFindByRoom($tournamentId, $roomId);
        return $again ? (int)$again['id'] : null;
    }
    $publicId = (int)$db->lastInsertId();

    $p1 = (string)($matchRow['p1_discord_id'] ?? '');
    $p2 = (string)($matchRow['p2_discord_id'] ?? '');
    $p1Name = tcgTournamentReplayUserName($p1);
    $p2Name = tcgTournamentReplayUserName($p2);
    if ($p1 !== '') {
        tcgTournamentAutosavePersonalFromPayload(
            $p1,
            'p1',
            trim($p2Name . ' · ' . $titleTag),
            $payload,
            $roomId
        );
    }
    if ($p2 !== '') {
        tcgTournamentAutosavePersonalFromPayload(
            $p2,
            'p2',
            trim($p1Name . ' · ' . $titleTag),
            $payload,
            $roomId
        );
    }

    return $publicId > 0 ? $publicId : null;
}
