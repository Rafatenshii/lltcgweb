<?php
/**
 * Tournament Mode v1 — tick, bracket start, room seed, advance, payout.
 */

/** @param array<string,mixed> $body */
function tcgApiTournamentTick(array $body): array {
    $uid = tcgRequireAuthUser($body);
    tcgRequireTournamentsEnabled($uid);
    $id = strtoupper(trim((string)($body['tournament_id'] ?? $_GET['tournament_id'] ?? '')));
    if ($id === '') {
        $stmt = tcgDb()->query(
            'SELECT id FROM tcg_tournaments WHERE status IN ("open","checkin","running") ORDER BY start_at ASC LIMIT 50'
        );
        $ids = $stmt ? ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: []) : [];
        $results = [];
        foreach ($ids as $tid) {
            $results[] = tcgTournamentTickOne((string)$tid);
        }
        return ['success' => true, 'ticked' => $results];
    }
    return tcgTournamentTickOne($id);
}

/** @return array<string,mixed> */
function tcgTournamentTickOne(string $id): array {
    $row = tcgTournamentFetch($id);
    if (!$row) {
        throw new Exception('Tournament not found', 404);
    }
    $now = time();
    $status = (string)$row['status'];
    $events = [];

    if ($status === 'open') {
        $opens = (int)$row['start_at'] - ((int)$row['checkin_mins'] * 60);
        if ($now >= $opens) {
            tcgDb()->prepare('UPDATE tcg_tournaments SET status = "checkin", updated_at = ? WHERE id = ? AND status = "open"')
                ->execute([$now, $id]);
            $events[] = 'entered_checkin';
            $status = 'checkin';
            $row = tcgTournamentFetch($id) ?: $row;
        }
    }

    if ($status === 'checkin' && $now >= (int)$row['start_at']) {
        $started = tcgTournamentStartBracket($id);
        $events[] = $started ? 'bracket_started' : 'start_deferred';
        $row = tcgTournamentFetch($id) ?: $row;
        $status = (string)$row['status'];
    }

    if ($status === 'running') {
        tcgTournamentApplyRoomResults($id);
        tcgTournamentApplyConnectForfeits($id, $now);
        tcgTournamentSeedPendingRooms($id);
        tcgTournamentAdvanceCompletedRounds($id);
        if (tcgTournamentTryFinish($id)) {
            $events[] = 'finished';
        }
        $row = tcgTournamentFetch($id) ?: $row;
    }

    $entrants = tcgTournamentFetchEntrants($id);
    $matches = tcgTournamentFetchMatches($id);
    return [
        'success' => true,
        'tournament' => tcgTournamentPublicRow($row, [
            'total' => count($entrants),
            'checked_in' => count(array_filter(
                $entrants,
                static fn($e) => in_array((string)$e['status'], ['checked_in', 'playing', 'eliminated'], true)
            )),
        ]),
        'matches' => array_map('tcgTournamentPublicMatch', $matches),
        'events' => $events,
        'server_now' => $now,
    ];
}

function tcgTournamentStartBracket(string $id): bool {
    $row = tcgTournamentFetch($id);
    if (!$row || (string)$row['status'] !== 'checkin') {
        return false;
    }
    $now = time();
    tcgDb()->prepare(
        'UPDATE tcg_tournament_entrants SET status = "no_show"
         WHERE tournament_id = ? AND status = "registered"'
    )->execute([$id]);

    $stmt = tcgDb()->prepare(
        'SELECT * FROM tcg_tournament_entrants WHERE tournament_id = ? AND status = "checked_in" ORDER BY registered_at ASC'
    );
    $stmt->execute([$id]);
    $checked = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (count($checked) < (int)$row['min_players']) {
        tcgTournamentCancelAndRefund($id, 'min_players');
        return false;
    }

    shuffle($checked);
    $playerIds = [];
    foreach ($checked as $i => $e) {
        $seed = $i + 1;
        tcgDb()->prepare(
            'UPDATE tcg_tournament_entrants SET seed = ?, status = "playing" WHERE tournament_id = ? AND discord_id = ?'
        )->execute([$seed, $id, $e['discord_id']]);
        $playerIds[] = (string)$e['discord_id'];
    }

    $pairings = tcgTournamentBuildRound1Pairings($playerIds);
    $created = time();
    foreach ($pairings as $slot => $pair) {
        $mid = tcgTournamentMatchNewId();
        $p1 = $pair['p1'];
        $p2 = $pair['p2'];
        $bye = $pair['bye'];
        if ($bye !== null) {
            tcgDb()->prepare(
                'INSERT INTO tcg_tournament_matches
                 (id, tournament_id, round, bracket_slot, p1_discord_id, p2_discord_id, room_id, p1_token, p2_token,
                  status, winner_discord_id, connect_deadline_at, created_at, updated_at)
                 VALUES (?, ?, 1, ?, ?, NULL, NULL, NULL, NULL, "done", ?, NULL, ?, ?)'
            )->execute([$mid, $id, $slot, $bye, $bye, $created, $created]);
            continue;
        }
        tcgDb()->prepare(
            'INSERT INTO tcg_tournament_matches
             (id, tournament_id, round, bracket_slot, p1_discord_id, p2_discord_id, room_id, p1_token, p2_token,
              status, winner_discord_id, connect_deadline_at, created_at, updated_at)
             VALUES (?, ?, 1, ?, ?, ?, NULL, NULL, NULL, "pending", NULL, NULL, ?, ?)'
        )->execute([$mid, $id, $slot, $p1, $p2, $created, $created]);
    }

    tcgDb()->prepare('UPDATE tcg_tournaments SET status = "running", updated_at = ? WHERE id = ?')
        ->execute([$now, $id]);

    tcgTournamentSeedPendingRooms($id);
    tcgTournamentAdvanceCompletedRounds($id);
    return true;
}

function tcgTournamentCancelAndRefund(string $id, string $reason = 'cancel'): void {
    $row = tcgTournamentFetch($id);
    if (!$row || in_array((string)$row['status'], ['finished', 'cancelled'], true)) {
        return;
    }
    $entrants = tcgTournamentFetchEntrants($id);
    $db = tcgDb();
    $db->beginTransaction();
    try {
        $entryRefunded = 0;
        foreach ($entrants as $e) {
            $paid = (int)($e['paid_coins'] ?? 0);
            $did = (string)$e['discord_id'];
            $key = 'refund_cancel:' . $id . ':' . $did;
            if ($paid > 0 && tcgTournamentLedgerWrite($id, $did, 'refund', $paid, $key, ['reason' => $reason])) {
                tcgAddCoins($did, $paid);
                $entryRefunded += $paid;
            }
        }
        $fresh = tcgTournamentFetch($id);
        $pool = (int)($fresh['prize_pool_coins'] ?? 0);
        $newPool = max(0, $pool - $entryRefunded);
        $hostKey = 'refund_host_pool:' . $id;
        if ($newPool > 0 && tcgTournamentLedgerWrite($id, (string)$row['host_discord_id'], 'refund', $newPool, $hostKey, ['reason' => $reason])) {
            tcgAddCoins((string)$row['host_discord_id'], $newPool);
            $newPool = 0;
        }
        $db->prepare(
            'UPDATE tcg_tournaments SET status = "cancelled", prize_pool_coins = ?, updated_at = ? WHERE id = ?'
        )->execute([$newPool, time(), $id]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

function tcgTournamentResolveMatch(string $matchId, string $winnerDiscordId, string $reason): void {
    $stmt = tcgDb()->prepare('SELECT * FROM tcg_tournament_matches WHERE id = ?');
    $stmt->execute([$matchId]);
    $m = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$m) {
        throw new Exception('Match not found', 404);
    }
    if ((string)$m['status'] === 'done') {
        return;
    }
    $p1 = (string)($m['p1_discord_id'] ?? '');
    $p2 = (string)($m['p2_discord_id'] ?? '');
    if ($winnerDiscordId !== $p1 && $winnerDiscordId !== $p2) {
        throw new Exception('Winner must be a match participant', 400);
    }
    $loser = ($winnerDiscordId === $p1) ? $p2 : $p1;
    $now = time();
    tcgDb()->prepare(
        'UPDATE tcg_tournament_matches SET status = "done", winner_discord_id = ?, updated_at = ? WHERE id = ?'
    )->execute([$winnerDiscordId, $now, $matchId]);
    if ($loser !== '') {
        tcgDb()->prepare(
            'UPDATE tcg_tournament_entrants SET status = "eliminated"
             WHERE tournament_id = ? AND discord_id = ? AND status = "playing"'
        )->execute([(string)$m['tournament_id'], $loser]);
    }
    if (!empty($m['room_id'])) {
        tcgTournamentEnsureApi();
        try {
            $state = loadGame((string)$m['room_id']);
            if (is_array($state) && ($state['status'] ?? '') !== 'finished') {
                $state['status'] = 'finished';
                $state['end_reason'] = $reason;
                $winnerPid = null;
                foreach (['p1', 'p2'] as $pid) {
                    if ((string)(($state['players'][$pid]['discord_id'] ?? '')) === $winnerDiscordId) {
                        $winnerPid = $pid;
                        break;
                    }
                }
                if ($winnerPid) {
                    $state['winner'] = $winnerPid;
                }
                saveGame((string)$m['room_id'], $state);
            }
        } catch (Throwable $e) {
            // ignore
        }
    }
}

function tcgTournamentEnsureApi(): void {
    if (function_exists('loadGame') && function_exists('saveGame') && function_exists('initGameState')) {
        return;
    }
    if (!defined('TCG_API_LIB_ONLY')) {
        define('TCG_API_LIB_ONLY', true);
    }
    require_once __DIR__ . '/api.php';
}

function tcgTournamentApplyRoomResults(string $tournamentId): void {
    tcgTournamentEnsureApi();
    $matches = tcgTournamentFetchMatches($tournamentId);
    foreach ($matches as $m) {
        if (!in_array((string)$m['status'], ['ready', 'live'], true)) {
            continue;
        }
        $roomId = (string)($m['room_id'] ?? '');
        if ($roomId === '') {
            continue;
        }
        $state = loadGame($roomId);
        if (!$state || ($state['status'] ?? '') !== 'finished') {
            continue;
        }
        $winnerPid = (string)($state['winner'] ?? '');
        $winnerDid = '';
        if ($winnerPid !== '' && isset($state['players'][$winnerPid]['discord_id'])) {
            $winnerDid = (string)$state['players'][$winnerPid]['discord_id'];
        }
        if ($winnerDid === '') {
            continue;
        }
        tcgTournamentResolveMatch((string)$m['id'], $winnerDid, (string)($state['end_reason'] ?? 'game'));
    }
}

function tcgTournamentApplyConnectForfeits(string $tournamentId, int $now): void {
    tcgTournamentEnsureApi();
    $matches = tcgTournamentFetchMatches($tournamentId);
    foreach ($matches as $m) {
        if ((string)$m['status'] !== 'ready') {
            continue;
        }
        $deadline = (int)($m['connect_deadline_at'] ?? 0);
        if ($deadline <= 0 || $now < $deadline) {
            continue;
        }
        $roomId = (string)($m['room_id'] ?? '');
        $state = $roomId !== '' ? loadGame($roomId) : null;
        $p1Connected = false;
        $p2Connected = false;
        if (is_array($state)) {
            if (intval($state['turn'] ?? 0) >= 1 || ($state['status'] ?? '') === 'finished') {
                continue;
            }
            $p1Connected = !empty($state['players']['p1']['connected'])
                || !empty($state['players']['p1']['last_seen']);
            $p2Connected = !empty($state['players']['p2']['connected'])
                || !empty($state['players']['p2']['last_seen']);
        }
        $p1 = (string)($m['p1_discord_id'] ?? '');
        $p2 = (string)($m['p2_discord_id'] ?? '');
        if ($p1Connected && !$p2Connected && $p1 !== '') {
            tcgTournamentResolveMatch((string)$m['id'], $p1, 'connect_forfeit');
        } elseif ($p2Connected && !$p1Connected && $p2 !== '') {
            tcgTournamentResolveMatch((string)$m['id'], $p2, 'connect_forfeit');
        } elseif ($p1 !== '' && $p2 !== '') {
            tcgTournamentResolveMatch((string)$m['id'], $p1, 'connect_forfeit');
        }
    }
}

function tcgTournamentSeedPendingRooms(string $tournamentId): void {
    $row = tcgTournamentFetch($tournamentId);
    if (!$row) {
        return;
    }
    $matches = tcgTournamentFetchMatches($tournamentId);
    foreach ($matches as $m) {
        if ((string)$m['status'] !== 'pending') {
            continue;
        }
        $p1 = (string)($m['p1_discord_id'] ?? '');
        $p2 = (string)($m['p2_discord_id'] ?? '');
        if ($p1 === '' || $p2 === '') {
            continue;
        }
        try {
            tcgTournamentCreateRoomPair($tournamentId, (string)$m['id'], $p1, $p2, (string)$row['game_mode']);
        } catch (Throwable $e) {
            // retry next tick
        }
    }
}

/**
 * @return array{room_id:string,p1_token:string,p2_token:string}|null
 */
function tcgTournamentCreateRoomPair(
    string $tournamentId,
    string $matchId,
    string $p1DiscordId,
    string $p2DiscordId,
    string $gameMode
): ?array {
    tcgTournamentEnsureApi();
    require_once __DIR__ . '/ranked_room.php';
    require_once __DIR__ . '/sleeves.php';
    require_once __DIR__ . '/playmats.php';

    $gameMode = tcgNormalizeGameMode($gameMode);
    $stmt = tcgDb()->prepare(
        'SELECT discord_id, deck_snapshot FROM tcg_tournament_entrants WHERE tournament_id = ? AND discord_id IN (?, ?)'
    );
    $stmt->execute([$tournamentId, $p1DiscordId, $p2DiscordId]);
    $snaps = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
        $decoded = json_decode((string)($r['deck_snapshot'] ?? '{}'), true);
        if (!is_array($decoded)) {
            return null;
        }
        $snaps[(string)$r['discord_id']] = $decoded;
    }
    if (!isset($snaps[$p1DiscordId], $snaps[$p2DiscordId])) {
        return null;
    }

    $cards = tcgLoadCardsData();
    $allCards = $cards['cards'] ?? [];
    $deck1 = $snaps[$p1DiscordId];
    $deck2 = $snaps[$p2DiscordId];

    $roomId = strtoupper(substr(md5(uniqid((string)mt_rand(), true)), 0, 6));
    $p1Token = generateToken();
    $p2Token = generateToken();

    $main1 = buildDeck($allCards, $deck1['main_nos'] ?? []);
    $energy1 = buildDeck($allCards, $deck1['energy_nos'] ?? []);
    shuffle($main1);
    shuffle($energy1);

    $state = initGameState($roomId, [
        'id' => 'p1',
        'token' => $p1Token,
        'name' => tcgGetUserDisplayName($p1DiscordId),
        'deck_choice' => 'tournament',
        'deck_label' => (string)($deck1['name'] ?? 'Tournament Deck'),
        'main_deck' => $main1,
        'energy_deck' => $energy1,
        'discord_id' => $p1DiscordId,
        'sleeve_id' => tcgNormalizeSleeveId($deck1['sleeve_id'] ?? ''),
        'playmat_id' => tcgNormalizePlaymatId($deck1['playmat_id'] ?? ''),
        'playmat_brightness' => tcgNormalizePlaymatBrightness($deck1['playmat_brightness'] ?? 1.0),
        'deck_snapshot' => [
            'main_nos' => $deck1['main_nos'] ?? [],
            'energy_nos' => $deck1['energy_nos'] ?? [],
        ],
    ]);
    $state['mode'] = 'tournament';
    $state['game_mode'] = $gameMode;
    $state['tournament'] = [
        'id' => $tournamentId,
        'match_id' => $matchId,
        'p1_discord_id' => $p1DiscordId,
        'p2_discord_id' => $p2DiscordId,
    ];
    $state['spectate_hidden_hands'] = true;

    $main2 = buildDeck($allCards, $deck2['main_nos'] ?? []);
    $energy2 = buildDeck($allCards, $deck2['energy_nos'] ?? []);
    shuffle($main2);
    shuffle($energy2);

    $state = addSecondPlayer($state, [
        'id' => 'p2',
        'token' => $p2Token,
        'name' => tcgGetUserDisplayName($p2DiscordId),
        'deck_choice' => 'tournament',
        'deck_label' => (string)($deck2['name'] ?? 'Tournament Deck'),
        'main_deck' => $main2,
        'energy_deck' => $energy2,
        'discord_id' => $p2DiscordId,
        'sleeve_id' => tcgNormalizeSleeveId($deck2['sleeve_id'] ?? ''),
        'playmat_id' => tcgNormalizePlaymatId($deck2['playmat_id'] ?? ''),
        'playmat_brightness' => tcgNormalizePlaymatBrightness($deck2['playmat_brightness'] ?? 1.0),
        'deck_snapshot' => [
            'main_nos' => $deck2['main_nos'] ?? [],
            'energy_nos' => $deck2['energy_nos'] ?? [],
        ],
    ], null);

    $state['phase_timer_cfg'] = ['enabled' => true, 'duration' => defined('PHASE_TIMER_MAX') ? PHASE_TIMER_MAX : 90];
    saveGame($roomId, $state);

    $deadline = time() + TCG_TOURNAMENT_CONNECT_SECS;
    tcgDb()->prepare(
        'UPDATE tcg_tournament_matches
         SET room_id = ?, p1_token = ?, p2_token = ?, status = "ready", connect_deadline_at = ?, updated_at = ?
         WHERE id = ? AND status = "pending"'
    )->execute([$roomId, $p1Token, $p2Token, $deadline, time(), $matchId]);

    return ['room_id' => $roomId, 'p1_token' => $p1Token, 'p2_token' => $p2Token];
}

function tcgTournamentAdvanceCompletedRounds(string $tournamentId): void {
    $matches = tcgTournamentFetchMatches($tournamentId);
    if (!$matches) {
        return;
    }
    $byRound = [];
    $maxRound = 1;
    foreach ($matches as $m) {
        $r = (int)$m['round'];
        $maxRound = max($maxRound, $r);
        $byRound[$r][] = $m;
    }
    for ($r = 1; $r <= $maxRound; $r++) {
        $roundMatches = $byRound[$r] ?? [];
        if (!$roundMatches) {
            continue;
        }
        $allDone = true;
        foreach ($roundMatches as $m) {
            if ((string)$m['status'] !== 'done' || empty($m['winner_discord_id'])) {
                $allDone = false;
                break;
            }
        }
        if (!$allDone || count($roundMatches) === 1) {
            continue;
        }
        $nextRound = $r + 1;
        $nextExisting = $byRound[$nextRound] ?? [];
        usort($roundMatches, static fn($a, $b) => (int)$a['bracket_slot'] <=> (int)$b['bracket_slot']);
        $winners = [];
        foreach ($roundMatches as $m) {
            $winners[] = (string)$m['winner_discord_id'];
        }
        $needed = (int)(count($winners) / 2);
        if ($needed < 1) {
            continue;
        }
        if (count($nextExisting) === 0) {
            $created = time();
            for ($i = 0; $i < $needed; $i++) {
                $mid = tcgTournamentMatchNewId();
                $wp1 = $winners[$i * 2] ?? null;
                $wp2 = $winners[$i * 2 + 1] ?? null;
                tcgDb()->prepare(
                    'INSERT INTO tcg_tournament_matches
                     (id, tournament_id, round, bracket_slot, p1_discord_id, p2_discord_id, room_id, p1_token, p2_token,
                      status, winner_discord_id, connect_deadline_at, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, NULL, NULL, NULL, "pending", NULL, NULL, ?, ?)'
                )->execute([$mid, $tournamentId, $nextRound, $i, $wp1, $wp2, $created, $created]);
            }
            $all = tcgTournamentFetchMatches($tournamentId);
            $byRound = [];
            foreach ($all as $mm) {
                $byRound[(int)$mm['round']][] = $mm;
            }
            $maxRound = max($maxRound, $nextRound);
        } else {
            usort($nextExisting, static fn($a, $b) => (int)$a['bracket_slot'] <=> (int)$b['bracket_slot']);
            for ($i = 0; $i < $needed; $i++) {
                $wp1 = $winners[$i * 2] ?? null;
                $wp2 = $winners[$i * 2 + 1] ?? null;
                $nm = $nextExisting[$i] ?? null;
                if (!$nm) {
                    continue;
                }
                if ((string)($nm['status'] ?? '') === 'pending'
                    && (empty($nm['p1_discord_id']) || empty($nm['p2_discord_id']))) {
                    tcgDb()->prepare(
                        'UPDATE tcg_tournament_matches SET p1_discord_id = ?, p2_discord_id = ?, updated_at = ? WHERE id = ?'
                    )->execute([$wp1, $wp2, time(), $nm['id']]);
                }
            }
        }
    }
    tcgTournamentSeedPendingRooms($tournamentId);
}

function tcgTournamentTryFinish(string $tournamentId): bool {
    $row = tcgTournamentFetch($tournamentId);
    if (!$row || (string)$row['status'] !== 'running') {
        return false;
    }
    $matches = tcgTournamentFetchMatches($tournamentId);
    if (!$matches) {
        return false;
    }
    $maxRound = 0;
    foreach ($matches as $m) {
        $maxRound = max($maxRound, (int)$m['round']);
    }
    $finals = array_values(array_filter($matches, static fn($m) => (int)$m['round'] === $maxRound));
    if (count($finals) !== 1) {
        return false;
    }
    $final = $finals[0];
    if ((string)$final['status'] !== 'done' || empty($final['winner_discord_id'])) {
        return false;
    }

    $pool = (int)$row['prize_pool_coins'];
    $winner = (string)$final['winner_discord_id'];
    $p1 = (string)($final['p1_discord_id'] ?? '');
    $p2 = (string)($final['p2_discord_id'] ?? '');
    $runner = ($winner === $p1) ? $p2 : (($winner === $p2) ? $p1 : '');

    $places = [];
    if ($winner !== '') {
        $places[] = $winner;
    }
    if ($runner !== '') {
        $places[] = $runner;
    }
    $percents = tcgTournamentPrizePercents(count($places));
    $db = tcgDb();
    $db->beginTransaction();
    try {
        $paidOut = 0;
        foreach ($places as $i => $did) {
            $pct = $percents[$i] ?? 0;
            $amount = (int)floor($pool * $pct / 100);
            if ($i === count($places) - 1) {
                $amount = $pool - $paidOut;
            }
            if ($amount <= 0) {
                continue;
            }
            $key = 'payout:' . $tournamentId . ':place' . ($i + 1) . ':' . $did;
            if (tcgTournamentLedgerWrite($tournamentId, $did, 'payout', $amount, $key, ['place' => $i + 1])) {
                tcgAddCoins($did, $amount);
                $paidOut += $amount;
            }
        }
        $db->prepare(
            'UPDATE tcg_tournaments SET status = "finished", prize_pool_coins = 0, updated_at = ? WHERE id = ?'
        )->execute([time(), $tournamentId]);
        $db->prepare(
            'UPDATE tcg_tournament_entrants SET status = "eliminated"
             WHERE tournament_id = ? AND status = "playing" AND discord_id != ?'
        )->execute([$tournamentId, $winner]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
    return true;
}

/** @param array<string,mixed> $state */
function tcgOnTournamentGameFinished(array &$state): void {
    $meta = is_array($state['tournament'] ?? null) ? $state['tournament'] : [];
    $tid = strtoupper(trim((string)($meta['id'] ?? '')));
    if ($tid === '' || !tcgTournamentsEnabled()) {
        return;
    }
    try {
        tcgTournamentApplyRoomResults($tid);
        tcgTournamentAdvanceCompletedRounds($tid);
        tcgTournamentTryFinish($tid);
    } catch (Throwable $e) {
        // tick retries
    }
}
