<?php
/**
 * Tournament Mode v1 — CRUD / register / check-in / moderation APIs.
 */
require_once __DIR__ . '/tournament_lib.php';
require_once __DIR__ . '/chat_moderation.php';

/** @param array<string,mixed> $body */
function tcgApiTournamentEnabled(array $body = []): array {
    $uid = null;
    try {
        $uid = tcgRequireAuthUser($body);
    } catch (Throwable $e) {
        $uid = null;
    }
    return ['success' => true, 'enabled' => tcgUserMayUseTournaments($uid)];
}

/** @param array<string,mixed> $body */
function tcgApiTournamentList(array $body): array {
    $uid = tcgRequireAuthUser($body);
    tcgRequireTournamentsEnabled($uid);
    $status = trim((string)($body['status'] ?? $_GET['status'] ?? ''));
    $gameMode = trim((string)($body['game_mode'] ?? $_GET['game_mode'] ?? ''));
    $now = time();
    $sql = 'SELECT t.*,
            (SELECT COUNT(*) FROM tcg_tournament_entrants e WHERE e.tournament_id = t.id) AS entrant_count,
            (SELECT COUNT(*) FROM tcg_tournament_entrants e
             WHERE e.tournament_id = t.id AND e.status IN ("checked_in","playing","eliminated")) AS checked_in_count
            FROM tcg_tournaments t WHERE 1=1';
    $params = [];
    if ($status !== '') {
        $sql .= ' AND t.status = ?';
        $params[] = $status;
    } else {
        $sql .= ' AND t.status IN ("open","checkin","running")';
    }
    if ($gameMode !== '') {
        $sql .= ' AND t.game_mode = ?';
        $params[] = tcgNormalizeGameMode($gameMode);
    }
    $sql .= ' ORDER BY t.start_at ASC LIMIT 100';
    $stmt = tcgDb()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $list = [];
    foreach ($rows as $row) {
        $pub = tcgTournamentPublicRow($row, [
            'total' => (int)($row['entrant_count'] ?? 0),
            'checked_in' => (int)($row['checked_in_count'] ?? 0),
        ]);
        $pub['checkin_opens_at'] = (int)$row['start_at'] - ((int)$row['checkin_mins'] * 60);
        $pub['server_now'] = $now;
        $list[] = $pub;
    }
    return ['success' => true, 'tournaments' => $list, 'server_now' => $now];
}

/** @param array<string,mixed> $body */
function tcgApiTournamentGet(array $body): array {
    $uid = tcgRequireAuthUser($body);
    tcgRequireTournamentsEnabled($uid);
    $id = strtoupper(trim((string)($body['tournament_id'] ?? $_GET['tournament_id'] ?? '')));
    if ($id === '') {
        throw new Exception('tournament_id required', 400);
    }
    $row = tcgTournamentFetch($id);
    if (!$row) {
        throw new Exception('Tournament not found', 404);
    }
    $entrants = tcgTournamentFetchEntrants($id);
    $matches = tcgTournamentFetchMatches($id);
    $me = null;
    foreach ($entrants as $e) {
        if ((string)$e['discord_id'] === $uid) {
            $me = tcgTournamentPublicEntrant($e, true);
            break;
        }
    }
    $checked = 0;
    foreach ($entrants as $e) {
        if (in_array((string)$e['status'], ['checked_in', 'playing', 'eliminated'], true)) {
            $checked++;
        }
    }
    $pub = tcgTournamentPublicRow($row, ['total' => count($entrants), 'checked_in' => $checked]);
    $pub['checkin_opens_at'] = (int)$row['start_at'] - ((int)$row['checkin_mins'] * 60);
    $stmt = tcgDb()->prepare('SELECT username, avatar_url FROM tcg_users WHERE discord_id = ?');
    $stmt->execute([(string)$row['host_discord_id']]);
    $hostRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $hostName = (string)($hostRow['username'] ?? 'Host');
    $hostAvatar = $hostRow['avatar_url'] ?? null;

    return [
        'success' => true,
        'tournament' => $pub,
        'host_username' => $hostName,
        'host_avatar_url' => $hostAvatar,
        'host_discord_id' => (string)$row['host_discord_id'],
        'is_host' => (string)$row['host_discord_id'] === $uid,
        'me' => $me,
        'entrants' => array_map(static fn($e) => tcgTournamentPublicEntrant($e, false), $entrants),
        'matches' => array_map('tcgTournamentPublicMatch', $matches),
        'server_now' => time(),
    ];
}

/** @param array<string,mixed> $body */
function tcgApiTournamentCreate(array $body): array {
    $uid = tcgRequireAuthUser($body);
    tcgRequireTournamentsEnabled($uid);
    tcgEnsureUser($uid, tcgAuthUserProfile($uid));

    $title = trim((string)($body['title'] ?? ''));
    tcgAssertTournamentTitleAllowed($title);
    $startAt = (int)($body['start_at'] ?? 0);
    if ($startAt < time() + 60) {
        throw new Exception('start_at must be at least 1 minute from now', 400);
    }
    $checkin = (int)($body['checkin_mins'] ?? TCG_TOURNAMENT_DEFAULT_CHECKIN_MINS);
    $checkin = max(TCG_TOURNAMENT_MIN_CHECKIN_MINS, min(TCG_TOURNAMENT_MAX_CHECKIN_MINS, $checkin));
    $minP = max(TCG_TOURNAMENT_MIN_PLAYERS, (int)($body['min_players'] ?? 4));
    $maxP = max($minP, (int)($body['max_players'] ?? 8));
    $maxP = min(TCG_TOURNAMENT_MAX_PLAYERS_CAP, $maxP);
    $fee = max(0, (int)($body['entry_fee_coins'] ?? 0));
    if ($fee > 100000) {
        throw new Exception('entry_fee_coins too high', 400);
    }
    $gameMode = tcgNormalizeGameMode($body['game_mode'] ?? TCG_GAME_MODE_STANDARD);
    $settings = is_array($body['settings'] ?? null) ? $body['settings'] : [];
    $settings['connect_secs'] = TCG_TOURNAMENT_CONNECT_SECS;
    $settings['stream_delay_secs'] = max(0, (int)($settings['stream_delay_secs'] ?? 0));

    $id = tcgTournamentNewId();
    $now = time();
    tcgDb()->prepare(
        'INSERT INTO tcg_tournaments
         (id, host_discord_id, title, status, game_mode, start_at, checkin_mins,
          min_players, max_players, entry_fee_coins, prize_pool_coins, settings_json, created_at, updated_at)
         VALUES (?, ?, ?, "open", ?, ?, ?, ?, ?, ?, 0, ?, ?, ?)'
    )->execute([
        $id, $uid, $title, $gameMode, $startAt, $checkin,
        $minP, $maxP, $fee, tcgTournamentEncodeSettings($settings), $now, $now,
    ]);

    $row = tcgTournamentFetch($id);
    return [
        'success' => true,
        'tournament' => tcgTournamentPublicRow($row ?: ['id' => $id], ['total' => 0, 'checked_in' => 0]),
    ];
}

/** @param array<string,mixed> $body */
function tcgApiTournamentUpdate(array $body): array {
    $uid = tcgRequireAuthUser($body);
    tcgRequireTournamentsEnabled($uid);
    $id = strtoupper(trim((string)($body['tournament_id'] ?? '')));
    $row = tcgTournamentFetch($id);
    if (!$row) {
        throw new Exception('Tournament not found', 404);
    }
    tcgTournamentAssertHost($row, $uid);
    if (!in_array((string)$row['status'], ['open', 'draft'], true)) {
        throw new Exception('Can only edit before check-in', 400);
    }

    $title = array_key_exists('title', $body) ? trim((string)$body['title']) : (string)$row['title'];
    tcgAssertTournamentTitleAllowed($title);
    $startAt = array_key_exists('start_at', $body) ? (int)$body['start_at'] : (int)$row['start_at'];
    if ($startAt < time() + 30) {
        throw new Exception('start_at too soon', 400);
    }
    $checkin = array_key_exists('checkin_mins', $body)
        ? (int)$body['checkin_mins'] : (int)$row['checkin_mins'];
    $checkin = max(TCG_TOURNAMENT_MIN_CHECKIN_MINS, min(TCG_TOURNAMENT_MAX_CHECKIN_MINS, $checkin));
    $minP = array_key_exists('min_players', $body) ? (int)$body['min_players'] : (int)$row['min_players'];
    $maxP = array_key_exists('max_players', $body) ? (int)$body['max_players'] : (int)$row['max_players'];
    $minP = max(TCG_TOURNAMENT_MIN_PLAYERS, $minP);
    $maxP = min(TCG_TOURNAMENT_MAX_PLAYERS_CAP, max($minP, $maxP));
    $fee = array_key_exists('entry_fee_coins', $body)
        ? max(0, (int)$body['entry_fee_coins']) : (int)$row['entry_fee_coins'];
    $gameMode = array_key_exists('game_mode', $body)
        ? tcgNormalizeGameMode($body['game_mode'])
        : tcgNormalizeGameMode($row['game_mode']);

    $settings = tcgTournamentDecodeSettings($row['settings_json'] ?? '{}');
    if (is_array($body['settings'] ?? null)) {
        $settings = array_merge($settings, $body['settings']);
    }

    tcgDb()->prepare(
        'UPDATE tcg_tournaments SET title=?, start_at=?, checkin_mins=?, min_players=?, max_players=?,
         entry_fee_coins=?, game_mode=?, settings_json=?, updated_at=? WHERE id=?'
    )->execute([
        $title, $startAt, $checkin, $minP, $maxP, $fee, $gameMode,
        tcgTournamentEncodeSettings($settings), time(), $id,
    ]);

    return tcgApiTournamentGet(array_merge($body, ['tournament_id' => $id]));
}
