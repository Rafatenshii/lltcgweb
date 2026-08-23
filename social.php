<?php
/**
 * Profile, friends, featured deck, and owner moderation (Hostinger account API).
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/chat_moderation.php';
// Cards / collection / equipped-deck helpers are already loaded by account.php
// (play_stats.php, cards_data.php, deck_validate.php, booster.php). Do not
// re-require those with mismatched casings — Linux Hostinger would 500 /me.

const TCG_SOCIAL_OWNER_ID = '213038604975472640';
const TCG_SOCIAL_FRIEND_CAP = 25;
const TCG_SOCIAL_BIO_MAX = 100;
const TCG_SOCIAL_DECK_DESC_MAX = 200;
const TCG_SOCIAL_CROCKFORD = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

function tcgSocialIsOwner(string $discordId): bool {
    return $discordId === TCG_SOCIAL_OWNER_ID;
}

function tcgSocialEnsureSchema(): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $db = tcgDb();
    try {
        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_tcg_users_friend_code ON tcg_users(friend_code) WHERE friend_code IS NOT NULL AND friend_code != \'\'' );
    } catch (Throwable $e) {
        // Unique index may fail if duplicate empties exist; friend-code assign still retries.
    }
    $db->exec('CREATE TABLE IF NOT EXISTS tcg_profile_showcase (
        discord_id TEXT NOT NULL,
        slot INTEGER NOT NULL,
        card_no TEXT NOT NULL DEFAULT \'\',
        PRIMARY KEY (discord_id, slot)
    )');
    $db->exec('CREATE TABLE IF NOT EXISTS tcg_friends (
        user_lo TEXT NOT NULL,
        user_hi TEXT NOT NULL,
        requester_id TEXT NOT NULL,
        status TEXT NOT NULL,
        created_at INTEGER NOT NULL,
        updated_at INTEGER NOT NULL,
        PRIMARY KEY (user_lo, user_hi)
    )');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_tcg_friends_status ON tcg_friends(status, updated_at)');
    $db->exec('CREATE TABLE IF NOT EXISTS tcg_pvp_results (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        room_id TEXT NOT NULL UNIQUE,
        mode TEXT NOT NULL,
        p1_id TEXT NOT NULL,
        p2_id TEXT NOT NULL,
        winner_id TEXT,
        ended_at INTEGER NOT NULL
    )');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_tcg_pvp_p1 ON tcg_pvp_results(p1_id, ended_at)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_tcg_pvp_p2 ON tcg_pvp_results(p2_id, ended_at)');
    $db->exec('CREATE TABLE IF NOT EXISTS tcg_profile_reports (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        reporter_id TEXT NOT NULL,
        target_id TEXT NOT NULL,
        field TEXT NOT NULL,
        snippet TEXT NOT NULL DEFAULT \'\',
        status TEXT NOT NULL DEFAULT \'open\',
        created_at INTEGER NOT NULL
    )');
    $db->exec('CREATE TABLE IF NOT EXISTS tcg_profile_mod_actions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        target_id TEXT NOT NULL,
        actor_id TEXT NOT NULL,
        action TEXT NOT NULL,
        note TEXT NOT NULL DEFAULT \'\',
        created_at INTEGER NOT NULL
    )');
}

function tcgSocialRandomFriendCode(): string {
    $alpha = TCG_SOCIAL_CROCKFORD;
    $code = 'LC';
    $max = strlen($alpha) - 1;
    for ($i = 0; $i < 6; $i++) {
        $code .= $alpha[random_int(0, $max)];
    }
    return $code;
}

function tcgSocialNormalizeFriendCode(string $raw): string {
    $s = strtoupper(preg_replace('/\s+/', '', trim($raw)) ?? '');
    return strtr($s, ['I' => '1', 'L' => '1', 'O' => '0', 'U' => 'V']);
}

function tcgSocialEnsureFriendCode(string $discordId): string {
    tcgSocialEnsureSchema();
    $db = tcgDb();
    $stmt = $db->prepare('SELECT friend_code FROM tcg_users WHERE discord_id = ?');
    $stmt->execute([$discordId]);
    $existing = trim((string)$stmt->fetchColumn());
    if ($existing !== '' && preg_match('/^LC[0-9A-HJKMNP-TV-Z]{6}$/', $existing)) {
        return $existing;
    }
    for ($i = 0; $i < 24; $i++) {
        $code = tcgSocialRandomFriendCode();
        try {
            $db->prepare(
                'UPDATE tcg_users SET friend_code = ? WHERE discord_id = ? AND (friend_code IS NULL OR friend_code = \'\')'
            )->execute([$code, $discordId]);
            $stmt->execute([$discordId]);
            $got = trim((string)$stmt->fetchColumn());
            if ($got !== '') {
                return $got;
            }
        } catch (Throwable $e) {
            continue;
        }
    }
    return $existing;
}

function tcgSocialPair(string $a, string $b): array {
    if ($a === $b) {
        throw new Exception('Cannot friend yourself', 400);
    }
    return $a < $b ? [$a, $b] : [$b, $a];
}

function tcgSocialAreFriends(string $a, string $b): bool {
    if ($a === '' || $b === '' || $a === $b) {
        return false;
    }
    [$lo, $hi] = tcgSocialPair($a, $b);
    $stmt = tcgDb()->prepare('SELECT 1 FROM tcg_friends WHERE user_lo = ? AND user_hi = ? AND status = ?');
    $stmt->execute([$lo, $hi, 'accepted']);
    return (bool)$stmt->fetchColumn();
}

function tcgSocialFriendCount(string $discordId): int {
    $stmt = tcgDb()->prepare(
        'SELECT COUNT(*) FROM tcg_friends WHERE status = \'accepted\' AND (user_lo = ? OR user_hi = ?)'
    );
    $stmt->execute([$discordId, $discordId]);
    return intval($stmt->fetchColumn());
}

function tcgSocialUserStub(string $discordId): array {
    $stmt = tcgDb()->prepare('SELECT discord_id, username, avatar_url, friend_code FROM tcg_users WHERE discord_id = ?');
    $stmt->execute([$discordId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    return [
        'id' => $discordId,
        'username' => (string)($row['username'] ?? 'Player'),
        'avatar_url' => $row['avatar_url'] ?? null,
        'friend_code' => (string)($row['friend_code'] ?? ''),
    ];
}

function tcgRecordPvpResult(string $roomId, string $mode, string $p1Id, string $p2Id, ?string $winnerId): void {
    $roomId = strtoupper(preg_replace('/[^A-Z0-9]/', '', $roomId) ?? '');
    $p1Id = trim($p1Id);
    $p2Id = trim($p2Id);
    if ($roomId === '' || $p1Id === '' || $p2Id === '' || $p1Id === $p2Id) {
        return;
    }
    if (strcasecmp($p1Id, 'cpu') === 0 || strcasecmp($p2Id, 'cpu') === 0) {
        return;
    }
    $mode = strtolower(trim($mode));
    if ($mode === '' || $mode === 'cpu' || str_contains($mode, 'cpu')) {
        return;
    }
    tcgSocialEnsureSchema();
    $win = $winnerId !== null ? trim($winnerId) : '';
    tcgDb()->prepare(
        'INSERT OR IGNORE INTO tcg_pvp_results (room_id, mode, p1_id, p2_id, winner_id, ended_at)
         VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([$roomId, $mode, $p1Id, $p2Id, $win === '' ? null : $win, time()]);
}

function tcgSocialCostBucket(int $cost): string {
    if ($cost <= 3) {
        return '1-3';
    }
    if ($cost === 4) {
        return '4';
    }
    if ($cost <= 8) {
        return '5-8';
    }
    if ($cost === 9) {
        return '9';
    }
    if ($cost <= 11) {
        return '10-11';
    }
    if ($cost <= 15) {
        return '12-15';
    }
    return '16+';
}

function tcgSocialDeckComposition(array $main, array $energy, array $cardMap): array {
    $buckets = ['1-3' => 0, '4' => 0, '5-8' => 0, '9' => 0, '10-11' => 0, '12-15' => 0, '16+' => 0];
    $blades = [];
    $types = ['member' => 0, 'live' => 0, 'energy' => 0];
    $countCard = static function (string $no) use (&$buckets, &$blades, &$types, $cardMap): void {
        $card = $cardMap[$no] ?? null;
        if (!is_array($card)) {
            return;
        }
        $en = strtolower((string)($card['card_type_en'] ?? ''));
        if ($en === 'member' || ($card['card_type'] ?? '') === 'メンバー') {
            $types['member']++;
        } elseif ($en === 'live' || ($card['card_type'] ?? '') === 'ライブ') {
            $types['live']++;
        } elseif ($en === 'energy' || ($card['card_type'] ?? '') === 'エネルギー') {
            $types['energy']++;
        }
        $cost = intval($card['cost'] ?? 0);
        if ($cost > 0) {
            $b = tcgSocialCostBucket($cost);
            $buckets[$b] = ($buckets[$b] ?? 0) + 1;
        }
        $bh = $card['blade_hearts'] ?? [];
        if (is_array($bh)) {
            foreach ($bh as $h) {
                $color = is_array($h) ? (string)($h['color'] ?? '') : (string)$h;
                if ($color === '') {
                    continue;
                }
                $n = is_array($h) ? max(1, intval($h['count'] ?? 1)) : 1;
                $blades[$color] = ($blades[$color] ?? 0) + $n;
            }
        }
    };
    foreach ($main as $no) {
        $countCard((string)$no);
    }
    foreach ($energy as $no) {
        $countCard((string)$no);
    }
    return ['cost_buckets' => $buckets, 'blade_hearts' => $blades, 'types' => $types];
}

function tcgSocialFeaturedDeckPayload(array $user, string $viewerId, bool $areFriends): array {
    $vis = (string)($user['featured_deck_visibility'] ?? 'private');
    if (!in_array($vis, ['private', 'friends', 'public'], true)) {
        $vis = 'private';
    }
    $ownerId = (string)$user['discord_id'];
    $isOwner = $viewerId === $ownerId;
    $canSee = $isOwner || $vis === 'public' || ($vis === 'friends' && $areFriends);
    $meta = [
        'visibility' => $vis,
        'name' => null,
        'desc' => ($isOwner || $canSee) ? (string)($user['featured_deck_desc'] ?? '') : '',
        'visible' => $canSee,
        'composition' => null,
        'cards' => null,
        'decks' => null,
        'featured_deck_id' => intval($user['featured_deck_id'] ?? 0) ?: null,
    ];
    if ($isOwner) {
        $stmt = tcgDb()->prepare('SELECT id, slot, name FROM tcg_deck_presets WHERE discord_id = ? ORDER BY slot ASC');
        $stmt->execute([$ownerId]);
        $meta['decks'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    if (!$canSee) {
        return $meta;
    }
    $deckId = intval($user['featured_deck_id'] ?? 0);
    $row = null;
    if ($deckId > 0) {
        $st = tcgDb()->prepare('SELECT * FROM tcg_deck_presets WHERE id = ? AND discord_id = ?');
        $st->execute([$deckId, $ownerId]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if (!$row) {
        $row = tcgGetEquippedDeckRow($ownerId);
    }
    if (!$row) {
        return $meta;
    }
    $main = is_string($row['main_deck'] ?? null) ? (json_decode((string)$row['main_deck'], true) ?: []) : ($row['main_deck'] ?? []);
    $energy = is_string($row['energy_deck'] ?? null) ? (json_decode((string)$row['energy_deck'], true) ?: []) : ($row['energy_deck'] ?? []);
    $cardMap = tcgBuildCardMap(tcgLoadCardsData());
    $cards = [];
    foreach (array_merge($main, $energy) as $no) {
        $no = (string)$no;
        $c = $cardMap[$no] ?? null;
        $cards[] = [
            'card_no' => $no,
            'name' => is_array($c) ? (string)($c['name_en'] ?? $c['name'] ?? $no) : $no,
            'card_type_en' => is_array($c) ? (string)($c['card_type_en'] ?? '') : '',
        ];
    }
    $meta['name'] = tcgNormalizeDeckPresetName((string)($row['name'] ?? 'Deck'));
    $meta['composition'] = tcgSocialDeckComposition($main, $energy, $cardMap);
    $meta['cards'] = $cards;
    $meta['featured_deck_id'] = intval($row['id'] ?? $deckId) ?: null;
    return $meta;
}

function tcgSocialShowcase(string $discordId): array {
    $owned = tcgGetCollectionMap($discordId);
    $stmt = tcgDb()->prepare('SELECT slot, card_no FROM tcg_profile_showcase WHERE discord_id = ? ORDER BY slot ASC');
    $stmt->execute([$discordId]);
    $slots = [1 => '', 2 => '', 3 => ''];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $slot = intval($row['slot']);
        $no = (string)($row['card_no'] ?? '');
        if ($slot >= 1 && $slot <= 3 && $no !== '' && intval($owned[$no] ?? 0) > 0) {
            $slots[$slot] = $no;
        }
    }
    $cardMap = tcgBuildCardMap(tcgLoadCardsData());
    $out = [];
    for ($i = 1; $i <= 3; $i++) {
        $no = $slots[$i];
        $c = $no !== '' ? ($cardMap[$no] ?? null) : null;
        $out[] = [
            'slot' => $i,
            'card_no' => $no,
            'name' => is_array($c) ? (string)($c['name_en'] ?? $c['name'] ?? $no) : '',
        ];
    }
    return $out;
}

function tcgSocialRankedWl(string $discordId): array {
    $stmt = tcgDb()->prepare(
        'SELECT COALESCE(SUM(wins),0), COALESCE(SUM(losses),0), COALESCE(SUM(games),0) FROM tcg_rank WHERE discord_id = ?'
    );
    $stmt->execute([$discordId]);
    $row = $stmt->fetch(PDO::FETCH_NUM) ?: [0, 0, 0];
    return ['wins' => intval($row[0]), 'losses' => intval($row[1]), 'games' => intval($row[2])];
}

function tcgSocialModeStats(string $discordId): array {
    $stmt = tcgDb()->prepare(
        'SELECT mode,
                COUNT(*) AS games,
                SUM(CASE WHEN winner_id = ? THEN 1 ELSE 0 END) AS wins,
                SUM(CASE WHEN winner_id IS NOT NULL AND winner_id != ? THEN 1 ELSE 0 END) AS losses
         FROM tcg_pvp_results
         WHERE p1_id = ? OR p2_id = ?
         GROUP BY mode'
    );
    $stmt->execute([$discordId, $discordId, $discordId, $discordId]);
    $out = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $g = intval($row['games']);
        $w = intval($row['wins']);
        $out[] = [
            'mode' => (string)$row['mode'],
            'games' => $g,
            'wins' => $w,
            'losses' => intval($row['losses']),
            'win_pct' => $g > 0 ? round(100 * $w / $g, 1) : 0,
        ];
    }
    return $out;
}

function tcgSocialOpponents(string $discordId, int $limit = 8): array {
    $stmt = tcgDb()->prepare(
        'SELECT CASE WHEN p1_id = ? THEN p2_id ELSE p1_id END AS opp,
                SUM(CASE WHEN winner_id = ? THEN 1 ELSE 0 END) AS wins,
                SUM(CASE WHEN winner_id IS NOT NULL AND winner_id != ? THEN 1 ELSE 0 END) AS losses,
                COUNT(*) AS games
         FROM tcg_pvp_results
         WHERE p1_id = ? OR p2_id = ?
         GROUP BY opp
         ORDER BY games DESC
         LIMIT ?'
    );
    $stmt->execute([$discordId, $discordId, $discordId, $discordId, $discordId, $limit]);
    $out = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $stub = tcgSocialUserStub((string)$row['opp']);
        $stub['wins'] = intval($row['wins']);
        $stub['losses'] = intval($row['losses']);
        $stub['games'] = intval($row['games']);
        $out[] = $stub;
    }
    return $out;
}

function tcgSocialMatchHistory(string $discordId, int $offset = 0, int $limit = 20): array {
    $limit = max(1, min(50, $limit));
    $offset = max(0, $offset);
    $stmt = tcgDb()->prepare(
        'SELECT * FROM tcg_pvp_results WHERE p1_id = ? OR p2_id = ? ORDER BY ended_at DESC LIMIT ? OFFSET ?'
    );
    $stmt->execute([$discordId, $discordId, $limit, $offset]);
    $rows = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $oppId = $row['p1_id'] === $discordId ? $row['p2_id'] : $row['p1_id'];
        $win = ($row['winner_id'] === null || $row['winner_id'] === '')
            ? 'draw'
            : ($row['winner_id'] === $discordId ? 'win' : 'loss');
        $rows[] = [
            'room_id' => $row['room_id'],
            'mode' => $row['mode'],
            'result' => $win,
            'ended_at' => intval($row['ended_at']),
            'opponent' => tcgSocialUserStub((string)$oppId),
        ];
    }
    return $rows;
}

function tcgSocialIdolUsage(string $discordId): array {
    $rows = tcgListPlayStats($discordId, TCG_PLAY_TRACKER_STAGE, TCG_PLAY_DIM_IDOL);
    $out = [];
    foreach (array_slice($rows, 0, 12) as $row) {
        $out[] = ['idol' => (string)$row['key'], 'count' => intval($row['count'])];
    }
    return $out;
}

function tcgApiSocialGetProfile(array $body): array {
    $viewer = tcgRequireAuthUser($body);
    tcgEnsureUser($viewer, tcgAuthUserProfile($viewer));
    tcgSocialEnsureSchema();
    tcgSocialEnsureFriendCode($viewer);
    $target = trim((string)($body['user_id'] ?? $body['discord_id'] ?? $viewer));
    if ($target === '') {
        $target = $viewer;
    }
    $stmt = tcgDb()->prepare('SELECT * FROM tcg_users WHERE discord_id = ?');
    $stmt->execute([$target]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        throw new Exception('Player not found', 404);
    }
    $isSelf = $viewer === $target;
    $friends = tcgSocialAreFriends($viewer, $target);
    return [
        'success' => true,
        'is_self' => $isSelf,
        'is_friend' => $friends,
        'is_mod' => tcgSocialIsOwner($viewer),
        'profile' => [
            'id' => $target,
            'username' => (string)($user['username'] ?? 'Player'),
            'avatar_url' => $user['avatar_url'] ?? null,
            'friend_code' => (string)($user['friend_code'] ?? ''),
            'bio' => (string)($user['bio'] ?? ''),
            'bio_locked' => intval($user['bio_locked'] ?? 0) === 1,
            'title_id' => $user['title_id'] ?? null,
            'profile_warnings' => tcgSocialIsOwner($viewer) ? intval($user['profile_warnings'] ?? 0) : null,
            'ranked' => tcgSocialRankedWl($target),
            'unranked_games' => intval($user['unranked_games'] ?? 0),
            'showcase' => tcgSocialShowcase($target),
            'featured_deck' => tcgSocialFeaturedDeckPayload($user, $viewer, $friends),
        ],
    ];
}

function tcgApiSocialGetStats(array $body): array {
    $viewer = tcgRequireAuthUser($body);
    $target = trim((string)($body['user_id'] ?? $viewer));
    if ($target === '') {
        $target = $viewer;
    }
    tcgSocialEnsureSchema();
    return [
        'success' => true,
        'modes' => tcgSocialModeStats($target),
        'opponents' => tcgSocialOpponents($target),
        'idols' => tcgSocialIdolUsage($target),
        'history' => tcgSocialMatchHistory($target, intval($body['offset'] ?? 0), intval($body['limit'] ?? 20)),
    ];
}

function tcgApiSocialSaveProfile(array $body): array {
    $uid = tcgRequireAuthUser($body);
    tcgSocialEnsureSchema();
    $row = tcgEnsureUser($uid, tcgAuthUserProfile($uid));
    if (intval($row['bio_locked'] ?? 0) === 1 && array_key_exists('bio', $body)) {
        throw new Exception('Bio is locked', 403);
    }
    $sets = [];
    $params = [];
    if (array_key_exists('bio', $body)) {
        $bio = trim((string)$body['bio']);
        tcgAssertProfileTextAllowed($bio, 'bio', TCG_SOCIAL_BIO_MAX);
        $sets[] = 'bio = ?';
        $params[] = $bio;
    }
    if (isset($body['featured_deck_visibility'])) {
        $vis = (string)$body['featured_deck_visibility'];
        if (!in_array($vis, ['private', 'friends', 'public'], true)) {
            throw new Exception('Invalid visibility', 400);
        }
        $sets[] = 'featured_deck_visibility = ?';
        $params[] = $vis;
    }
    if (array_key_exists('featured_deck_desc', $body)) {
        $desc = trim((string)$body['featured_deck_desc']);
        tcgAssertProfileTextAllowed($desc, 'deck description', TCG_SOCIAL_DECK_DESC_MAX);
        $sets[] = 'featured_deck_desc = ?';
        $params[] = $desc;
    }
    if (array_key_exists('featured_deck_id', $body)) {
        $did = intval($body['featured_deck_id']);
        if ($did > 0) {
            $chk = tcgDb()->prepare('SELECT 1 FROM tcg_deck_presets WHERE id = ? AND discord_id = ?');
            $chk->execute([$did, $uid]);
            if (!$chk->fetchColumn()) {
                throw new Exception('Deck not found', 400);
            }
        }
        $sets[] = 'featured_deck_id = ?';
        $params[] = $did > 0 ? $did : null;
    }
    if ($sets) {
        $params[] = time();
        $params[] = $uid;
        tcgDb()->prepare('UPDATE tcg_users SET ' . implode(', ', $sets) . ', updated_at = ? WHERE discord_id = ?')
            ->execute($params);
    }
    if (isset($body['showcase']) && is_array($body['showcase'])) {
        $owned = tcgGetCollectionMap($uid);
        $up = tcgDb()->prepare(
            'INSERT INTO tcg_profile_showcase (discord_id, slot, card_no) VALUES (?, ?, ?)
             ON CONFLICT(discord_id, slot) DO UPDATE SET card_no = excluded.card_no'
        );
        foreach ($body['showcase'] as $slot => $no) {
            $s = intval(is_array($no) ? ($no['slot'] ?? $slot) : $slot);
            $cardNo = trim((string)(is_array($no) ? ($no['card_no'] ?? '') : $no));
            if ($s < 1 || $s > 3) {
                continue;
            }
            if ($cardNo !== '' && intval($owned[$cardNo] ?? 0) < 1) {
                throw new Exception('You do not own that card', 400);
            }
            $up->execute([$uid, $s, $cardNo]);
        }
    }
    return tcgApiSocialGetProfile(['user_id' => $uid] + $body);
}

function tcgApiSocialFriends(array $body): array {
    $uid = tcgRequireAuthUser($body);
    tcgSocialEnsureSchema();
    $code = tcgSocialEnsureFriendCode($uid);
    $friends = [];
    $incoming = [];
    $outgoing = [];
    $st = tcgDb()->prepare('SELECT * FROM tcg_friends WHERE user_lo = ? OR user_hi = ?');
    $st->execute([$uid, $uid]);
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $other = $row['user_lo'] === $uid ? $row['user_hi'] : $row['user_lo'];
        $stub = tcgSocialUserStub($other);
        $stub['status'] = $row['status'];
        if ($row['status'] === 'accepted') {
            $friends[] = $stub;
        } elseif ($row['requester_id'] === $uid) {
            $outgoing[] = $stub;
        } else {
            $incoming[] = $stub;
        }
    }
    $recent = [];
    $seen = [];
    foreach (tcgSocialMatchHistory($uid, 0, 40) as $h) {
        $oid = $h['opponent']['id'] ?? '';
        if ($oid === '' || isset($seen[$oid])) {
            continue;
        }
        $seen[$oid] = true;
        $recent[] = $h['opponent'];
        if (count($recent) >= 10) {
            break;
        }
    }
    return [
        'success' => true,
        'friend_code' => $code,
        'cap' => TCG_SOCIAL_FRIEND_CAP,
        'count' => count($friends),
        'friends' => $friends,
        'incoming' => $incoming,
        'outgoing' => $outgoing,
        'recent' => $recent,
        'is_mod' => tcgSocialIsOwner($uid),
    ];
}

function tcgApiSocialFriendAdd(array $body): array {
    $uid = tcgRequireAuthUser($body);
    tcgSocialEnsureSchema();
    $code = tcgSocialNormalizeFriendCode((string)($body['friend_code'] ?? ''));
    if (!preg_match('/^LC[0-9A-HJKMNP-TV-Z]{6}$/', $code)) {
        throw new Exception('Invalid friend code', 400);
    }
    $st = tcgDb()->prepare('SELECT discord_id FROM tcg_users WHERE friend_code = ?');
    $st->execute([$code]);
    $other = (string)$st->fetchColumn();
    if ($other === '') {
        throw new Exception('No player with that code', 404);
    }
    if ($other === $uid) {
        throw new Exception('That is your own code', 400);
    }
    if (tcgSocialFriendCount($uid) >= TCG_SOCIAL_FRIEND_CAP) {
        throw new Exception('Friend list is full (25)', 400);
    }
    [$lo, $hi] = tcgSocialPair($uid, $other);
    $now = time();
    $exist = tcgDb()->prepare('SELECT * FROM tcg_friends WHERE user_lo = ? AND user_hi = ?');
    $exist->execute([$lo, $hi]);
    $row = $exist->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        if ($row['status'] === 'accepted') {
            throw new Exception('Already friends', 400);
        }
        if ($row['requester_id'] !== $uid) {
            tcgDb()->prepare('UPDATE tcg_friends SET status = ?, updated_at = ? WHERE user_lo = ? AND user_hi = ?')
                ->execute(['accepted', $now, $lo, $hi]);
            return ['success' => true, 'accepted' => true];
        }
        return ['success' => true, 'pending' => true];
    }
    tcgDb()->prepare(
        'INSERT INTO tcg_friends (user_lo, user_hi, requester_id, status, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([$lo, $hi, $uid, 'pending', $now, $now]);
    return ['success' => true, 'pending' => true];
}

function tcgApiSocialFriendRespond(array $body, bool $accept): array {
    $uid = tcgRequireAuthUser($body);
    $other = trim((string)($body['user_id'] ?? ''));
    if ($other === '') {
        throw new Exception('user_id required', 400);
    }
    [$lo, $hi] = tcgSocialPair($uid, $other);
    $st = tcgDb()->prepare('SELECT * FROM tcg_friends WHERE user_lo = ? AND user_hi = ?');
    $st->execute([$lo, $hi]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row || $row['status'] !== 'pending') {
        throw new Exception('No pending request', 400);
    }
    if ($accept) {
        if ($row['requester_id'] === $uid) {
            throw new Exception('Waiting for them to accept', 400);
        }
        if (tcgSocialFriendCount($uid) >= TCG_SOCIAL_FRIEND_CAP || tcgSocialFriendCount($other) >= TCG_SOCIAL_FRIEND_CAP) {
            throw new Exception('Friend list is full (25)', 400);
        }
        tcgDb()->prepare('UPDATE tcg_friends SET status = ?, updated_at = ? WHERE user_lo = ? AND user_hi = ?')
            ->execute(['accepted', time(), $lo, $hi]);
    } else {
        tcgDb()->prepare('DELETE FROM tcg_friends WHERE user_lo = ? AND user_hi = ?')->execute([$lo, $hi]);
    }
    return ['success' => true];
}

function tcgApiSocialFriendRemove(array $body): array {
    $uid = tcgRequireAuthUser($body);
    $other = trim((string)($body['user_id'] ?? ''));
    [$lo, $hi] = tcgSocialPair($uid, $other);
    tcgDb()->prepare('DELETE FROM tcg_friends WHERE user_lo = ? AND user_hi = ?')->execute([$lo, $hi]);
    return ['success' => true];
}

function tcgApiSocialReport(array $body): array {
    $uid = tcgRequireAuthUser($body);
    $target = trim((string)($body['user_id'] ?? ''));
    $field = (string)($body['field'] ?? 'bio');
    if (!in_array($field, ['bio', 'deck_desc'], true)) {
        $field = 'bio';
    }
    if ($target === '' || $target === $uid) {
        throw new Exception('Invalid report target', 400);
    }
    tcgSocialEnsureSchema();
    $snippet = mb_substr(trim((string)($body['snippet'] ?? '')), 0, 200);
    tcgDb()->prepare(
        'INSERT INTO tcg_profile_reports (reporter_id, target_id, field, snippet, status, created_at)
         VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([$uid, $target, $field, $snippet, 'open', time()]);
    return ['success' => true];
}

function tcgApiSocialModInbox(array $body): array {
    $uid = tcgRequireAuthUser($body);
    if (!tcgSocialIsOwner($uid)) {
        throw new Exception('Forbidden', 403);
    }
    tcgSocialEnsureSchema();
    $st = tcgDb()->query(
        'SELECT r.*, u.username, u.bio, u.profile_warnings, u.bio_locked
         FROM tcg_profile_reports r
         LEFT JOIN tcg_users u ON u.discord_id = r.target_id
         WHERE r.status = \'open\'
         ORDER BY r.created_at DESC
         LIMIT 80'
    );
    return ['success' => true, 'reports' => $st ? $st->fetchAll(PDO::FETCH_ASSOC) : []];
}

function tcgApiSocialModAction(array $body): array {
    $uid = tcgRequireAuthUser($body);
    if (!tcgSocialIsOwner($uid)) {
        throw new Exception('Forbidden', 403);
    }
    $target = trim((string)($body['user_id'] ?? ''));
    $action = (string)($body['mod_action'] ?? '');
    if ($target === '' || !in_array($action, ['clear_bio', 'warn', 'lock_bio', 'unlock_bio', 'dismiss'], true)) {
        throw new Exception('Invalid action', 400);
    }
    tcgSocialEnsureSchema();
    $db = tcgDb();
    $now = time();
    if ($action === 'clear_bio') {
        $db->prepare('UPDATE tcg_users SET bio = ?, updated_at = ? WHERE discord_id = ?')->execute(['', $now, $target]);
    } elseif ($action === 'warn') {
        $db->prepare('UPDATE tcg_users SET profile_warnings = COALESCE(profile_warnings,0)+1, updated_at = ? WHERE discord_id = ?')
            ->execute([$now, $target]);
    } elseif ($action === 'lock_bio') {
        $db->prepare('UPDATE tcg_users SET bio_locked = 1, updated_at = ? WHERE discord_id = ?')->execute([$now, $target]);
    } elseif ($action === 'unlock_bio') {
        $db->prepare('UPDATE tcg_users SET bio_locked = 0, updated_at = ? WHERE discord_id = ?')->execute([$now, $target]);
    }
    $rid = intval($body['report_id'] ?? 0);
    if ($rid > 0) {
        $db->prepare('UPDATE tcg_profile_reports SET status = ? WHERE id = ?')->execute(['closed', $rid]);
    } elseif ($action === 'dismiss') {
        $db->prepare('UPDATE tcg_profile_reports SET status = ? WHERE target_id = ? AND status = ?')
            ->execute(['closed', $target, 'open']);
    }
    $db->prepare('INSERT INTO tcg_profile_mod_actions (target_id, actor_id, action, note, created_at) VALUES (?, ?, ?, ?, ?)')
        ->execute([$target, $uid, $action, (string)($body['note'] ?? ''), $now]);
    return ['success' => true];
}
