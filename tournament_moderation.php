<?php
/**
 * Tournament Mode v1 — host kick/DQ/force/cancel + join match.
 */

/** @param array<string,mixed> $body */
function tcgApiTournamentKick(array $body): array {
    $uid = tcgRequireAuthUser($body);
    tcgRequireTournamentsEnabled($uid);
    $id = strtoupper(trim((string)($body['tournament_id'] ?? '')));
    $target = trim((string)($body['discord_id'] ?? ''));
    $row = tcgTournamentFetch($id);
    if (!$row) {
        throw new Exception('Tournament not found', 404);
    }
    tcgTournamentAssertHost($row, $uid);
    if (!in_array((string)$row['status'], ['open', 'checkin'], true)) {
        throw new Exception('Kick only before start', 400);
    }
    if ($target === '' || $target === $uid) {
        throw new Exception('Invalid target', 400);
    }

    $stmt = tcgDb()->prepare('SELECT * FROM tcg_tournament_entrants WHERE tournament_id = ? AND discord_id = ?');
    $stmt->execute([$id, $target]);
    $ent = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$ent) {
        throw new Exception('Entrant not found', 404);
    }
    $paid = (int)($ent['paid_coins'] ?? 0);
    $refundKey = 'refund_kick:' . $id . ':' . $target;

    return tcgDbRetry(function () use ($id, $target, $paid, $refundKey, $body) {
        $db = tcgDb();
        $db->beginTransaction();
        try {
            if ($paid > 0 && tcgTournamentLedgerWrite($id, $target, 'refund', $paid, $refundKey, ['reason' => 'kick'])) {
                tcgAddCoins($target, $paid);
                $db->prepare(
                    'UPDATE tcg_tournaments SET prize_pool_coins = MAX(0, prize_pool_coins - ?), updated_at = ? WHERE id = ?'
                )->execute([$paid, time(), $id]);
            }
            $db->prepare('DELETE FROM tcg_tournament_entrants WHERE tournament_id = ? AND discord_id = ?')
                ->execute([$id, $target]);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
        return tcgApiTournamentGet(array_merge($body, ['tournament_id' => $id]));
    });
}

/** @param array<string,mixed> $body */
function tcgApiTournamentDq(array $body): array {
    $uid = tcgRequireAuthUser($body);
    tcgRequireTournamentsEnabled($uid);
    $id = strtoupper(trim((string)($body['tournament_id'] ?? '')));
    $target = trim((string)($body['discord_id'] ?? ''));
    $row = tcgTournamentFetch($id);
    if (!$row) {
        throw new Exception('Tournament not found', 404);
    }
    tcgTournamentAssertHost($row, $uid);
    if ((string)$row['status'] !== 'running') {
        throw new Exception('DQ only while running', 400);
    }
    if ($target === '') {
        throw new Exception('discord_id required', 400);
    }

    tcgDb()->prepare(
        'UPDATE tcg_tournament_entrants SET status = "dq" WHERE tournament_id = ? AND discord_id = ?'
    )->execute([$id, $target]);

    $matches = tcgTournamentFetchMatches($id);
    foreach ($matches as $m) {
        if (!in_array((string)$m['status'], ['pending', 'ready', 'live'], true)) {
            continue;
        }
        $p1 = (string)($m['p1_discord_id'] ?? '');
        $p2 = (string)($m['p2_discord_id'] ?? '');
        $winner = null;
        if ($p1 === $target && $p2 !== '') {
            $winner = $p2;
        } elseif ($p2 === $target && $p1 !== '') {
            $winner = $p1;
        }
        if ($winner !== null) {
            tcgTournamentResolveMatch((string)$m['id'], $winner, 'dq');
        }
    }

    return tcgApiTournamentTick(array_merge($body, ['tournament_id' => $id]));
}

/** @param array<string,mixed> $body */
function tcgApiTournamentForceResult(array $body): array {
    $uid = tcgRequireAuthUser($body);
    tcgRequireTournamentsEnabled($uid);
    $id = strtoupper(trim((string)($body['tournament_id'] ?? '')));
    $matchId = strtoupper(trim((string)($body['match_id'] ?? '')));
    $winner = trim((string)($body['winner_discord_id'] ?? ''));
    $row = tcgTournamentFetch($id);
    if (!$row) {
        throw new Exception('Tournament not found', 404);
    }
    tcgTournamentAssertHost($row, $uid);
    if ($matchId === '' || $winner === '') {
        throw new Exception('match_id and winner_discord_id required', 400);
    }
    tcgTournamentResolveMatch($matchId, $winner, 'force');
    return tcgApiTournamentTick(array_merge($body, ['tournament_id' => $id]));
}

/** @param array<string,mixed> $body */
function tcgApiTournamentCancel(array $body): array {
    $uid = tcgRequireAuthUser($body);
    tcgRequireTournamentsEnabled($uid);
    $id = strtoupper(trim((string)($body['tournament_id'] ?? '')));
    $row = tcgTournamentFetch($id);
    if (!$row) {
        throw new Exception('Tournament not found', 404);
    }
    tcgTournamentAssertHost($row, $uid);
    if (in_array((string)$row['status'], ['finished', 'cancelled'], true)) {
        throw new Exception('Already closed', 400);
    }
    tcgTournamentCancelAndRefund($id);
    return tcgApiTournamentGet(array_merge($body, ['tournament_id' => $id]));
}

/** @param array<string,mixed> $body */
function tcgApiTournamentReport(array $body): array {
    $uid = tcgRequireAuthUser($body);
    tcgRequireTournamentsEnabled($uid);
    $id = strtoupper(trim((string)($body['tournament_id'] ?? '')));
    $matchId = strtoupper(trim((string)($body['match_id'] ?? '')));
    $concede = !empty($body['concede']);
    if (!$concede) {
        return tcgApiTournamentTick(array_merge($body, ['tournament_id' => $id]));
    }
    $stmt = tcgDb()->prepare('SELECT * FROM tcg_tournament_matches WHERE id = ? AND tournament_id = ?');
    $stmt->execute([$matchId, $id]);
    $m = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$m) {
        throw new Exception('Match not found', 404);
    }
    $p1 = (string)($m['p1_discord_id'] ?? '');
    $p2 = (string)($m['p2_discord_id'] ?? '');
    if ($uid !== $p1 && $uid !== $p2) {
        throw new Exception('Not a player in this match', 403);
    }
    $winner = ($uid === $p1) ? $p2 : $p1;
    if ($winner === '') {
        throw new Exception('No opponent to award', 400);
    }
    tcgTournamentResolveMatch($matchId, $winner, 'concede');
    return tcgApiTournamentTick(array_merge($body, ['tournament_id' => $id]));
}

/** @param array<string,mixed> $body */
function tcgApiTournamentJoinMatch(array $body): array {
    $uid = tcgRequireAuthUser($body);
    tcgRequireTournamentsEnabled($uid);
    $id = strtoupper(trim((string)($body['tournament_id'] ?? '')));
    $matchId = strtoupper(trim((string)($body['match_id'] ?? '')));

    $sql = 'SELECT * FROM tcg_tournament_matches WHERE tournament_id = ? AND status IN ("ready","live")';
    $params = [$id];
    if ($matchId !== '') {
        $sql .= ' AND id = ?';
        $params[] = $matchId;
    }
    $sql .= ' AND (p1_discord_id = ? OR p2_discord_id = ?) ORDER BY round ASC, bracket_slot ASC LIMIT 1';
    $params[] = $uid;
    $params[] = $uid;
    $stmt = tcgDb()->prepare($sql);
    $stmt->execute($params);
    $m = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$m || empty($m['room_id'])) {
        throw new Exception('No joinable match', 404);
    }
    $isP1 = (string)($m['p1_discord_id'] ?? '') === $uid;
    return [
        'success' => true,
        'room_id' => (string)$m['room_id'],
        'player_token' => $isP1 ? (string)($m['p1_token'] ?? '') : (string)($m['p2_token'] ?? ''),
        'player_id' => $isP1 ? 'p1' : 'p2',
        'match_id' => (string)$m['id'],
        'mode' => 'tournament',
        'match_api' => 'local',
    ];
}

/** @return array<string,mixed>|null */
function tcgGetActiveTournamentGame(string $discordId): ?array {
    if (!tcgTournamentsEnabled()) {
        return null;
    }
    $stmt = tcgDb()->prepare(
        'SELECT m.* FROM tcg_tournament_matches m
         INNER JOIN tcg_tournaments t ON t.id = m.tournament_id
         WHERE t.status = "running"
           AND m.status IN ("ready","live")
           AND (m.p1_discord_id = ? OR m.p2_discord_id = ?)
           AND m.room_id IS NOT NULL AND m.room_id != ""
         ORDER BY m.updated_at DESC LIMIT 1'
    );
    $stmt->execute([$discordId, $discordId]);
    $m = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$m) {
        return null;
    }
    $isP1 = (string)($m['p1_discord_id'] ?? '') === $discordId;
    return [
        'room_id' => (string)$m['room_id'],
        'player_token' => $isP1 ? (string)($m['p1_token'] ?? '') : (string)($m['p2_token'] ?? ''),
        'player_id' => $isP1 ? 'p1' : 'p2',
        'mode' => 'tournament',
        'match_api' => 'local',
        'tournament_id' => (string)$m['tournament_id'],
        'match_id' => (string)$m['id'],
        'game_mode' => TCG_GAME_MODE_STANDARD,
    ];
}
