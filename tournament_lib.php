<?php
/**
 * Tournament Mode v1 — helpers (feature gate, bracket math, public shapes).
 * Loaded by tournament.php.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/coins.php';
require_once __DIR__ . '/game_mode.php';
require_once __DIR__ . '/deck_validate.php';

const TCG_TOURNAMENT_CONNECT_SECS = 180;
const TCG_TOURNAMENT_DEFAULT_CHECKIN_MINS = 10;
const TCG_TOURNAMENT_MIN_CHECKIN_MINS = 5;
const TCG_TOURNAMENT_MAX_CHECKIN_MINS = 10;
const TCG_TOURNAMENT_MIN_PLAYERS = 2;
const TCG_TOURNAMENT_MAX_PLAYERS_CAP = 32;

/**
 * Discord IDs that may use Tournament Mode while it stays Coming Soon for others.
 * Override with TCG_TOURNAMENT_ALLOWLIST (comma-separated). Use "*" to clear
 * (public when TCG_TOURNAMENTS_ENABLED=1).
 *
 * @return list<string>
 */
function tcgTournamentAllowlist(): array {
    $env = getenv('TCG_TOURNAMENT_ALLOWLIST');
    if (is_string($env)) {
        $env = trim($env);
        if ($env === '*' || $env === 'none' || $env === '-') {
            return [];
        }
        if ($env !== '') {
            return array_values(array_filter(array_map('trim', explode(',', $env)), static fn($id) => $id !== ''));
        }
    }
    // Preview allowlist until public launch.
    return ['213038604975472640'];
}

function tcgTournamentsEnvEnabled(): bool {
    $env = getenv('TCG_TOURNAMENTS_ENABLED');
    if ($env === false || $env === '') {
        return false;
    }
    $v = strtolower(trim((string)$env));
    return $v === '1' || $v === 'true' || $v === 'yes' || $v === 'on';
}

/** Feature shipped (env open and/or allowlist preview). */
function tcgTournamentsEnabled(): bool {
    if (tcgTournamentsEnvEnabled()) {
        return true;
    }
    return tcgTournamentAllowlist() !== [];
}

/** Per-user gate: allowlist when non-empty; otherwise anyone if env enabled. */
function tcgUserMayUseTournaments(?string $discordId): bool {
    if (!tcgTournamentsEnabled()) {
        return false;
    }
    $list = tcgTournamentAllowlist();
    if ($list === []) {
        return tcgTournamentsEnvEnabled();
    }
    if ($discordId === null || $discordId === '') {
        return false;
    }
    return in_array((string)$discordId, $list, true);
}

function tcgRequireTournamentsEnabled(?string $discordId = null): void {
    if ($discordId !== null) {
        if (!tcgUserMayUseTournaments($discordId)) {
            throw new Exception('Tournaments are disabled', 403);
        }
        return;
    }
    if (!tcgTournamentsEnabled()) {
        throw new Exception('Tournaments are disabled', 403);
    }
}

function tcgTournamentNewId(): string {
    return strtoupper(substr(bin2hex(random_bytes(8)), 0, 10));
}

function tcgTournamentMatchNewId(): string {
    return strtoupper(substr(bin2hex(random_bytes(6)), 0, 12));
}

/** @return array<string,mixed> */
function tcgTournamentDecodeSettings(?string $json): array {
    $raw = json_decode((string)$json, true);
    return tcgTournamentNormalizeSettings(is_array($raw) ? $raw : []);
}

/** @param array<string,mixed>|null $settings */
function tcgTournamentEncodeSettings(?array $settings): string {
    return json_encode(tcgTournamentNormalizeSettings($settings ?: []), JSON_UNESCAPED_UNICODE) ?: '{}';
}

/**
 * Normalize tournament settings_json (Phase 2+ fields).
 *
 * @param array<string,mixed> $settings
 * @return array<string,mixed>
 */
function tcgTournamentNormalizeSettings(array $settings): array {
    $fog = (string)($settings['fog'] ?? 'hidden_hands');
    if ($fog !== 'open_hands') {
        $fog = 'hidden_hands';
    }
    $delay = (int)($settings['stream_delay_secs'] ?? 0);
    if (!in_array($delay, [0, 15, 30, 60], true)) {
        $delay = 0;
    }
    $rules = (string)($settings['rules_template'] ?? 'standard');
    if (!in_array($rules, ['standard', 'starters_only', 'pauper', 'highlander'], true)) {
        $rules = 'standard';
    }
    // Phase 3 stub — only single_elim for now.
    $format = (string)($settings['format'] ?? 'single_elim');
    if ($format !== 'single_elim') {
        $format = 'single_elim';
    }
    return [
        'connect_secs' => max(30, (int)($settings['connect_secs'] ?? TCG_TOURNAMENT_CONNECT_SECS)),
        'fog' => $fog,
        'stream_delay_secs' => $delay,
        'rules_template' => $rules,
        'format' => $format,
    ];
}

/**
 * Optional Discord incoming webhook (Hostinger env TCG_TOURNAMENT_WEBHOOK_URL).
 *
 * @param array<string,mixed> $row tournament DB row
 */
function tcgTournamentNotifyWebhook(string $event, array $row): void {
    $url = getenv('TCG_TOURNAMENT_WEBHOOK_URL');
    if (!is_string($url) || trim($url) === '') {
        return;
    }
    $url = trim($url);
    if (!preg_match('#^https://(discord(?:app)?\.com|canary\.discord\.com)/api/webhooks/#i', $url)) {
        return;
    }
    $title = (string)($row['title'] ?? 'Tournament');
    $id = (string)($row['id'] ?? '');
    $start = (int)($row['start_at'] ?? 0);
    $link = 'https://loveliveradio.ca/tcg/';
    $lines = [
        'entered_checkin' => '**Check-in open** — ' . $title,
        'bracket_started' => '**Bracket started** — ' . $title,
        'finished' => '**Tournament finished** — ' . $title,
    ];
    $content = $lines[$event] ?? ('**Tournament update** (' . $event . ') — ' . $title);
    if ($id !== '') {
        $content .= "\nID `" . $id . "`";
    }
    if ($start > 0) {
        $content .= "\nStarts <t:" . $start . ":f>";
    }
    $content .= "\n" . $link;
    $payload = json_encode([
        'content' => $content,
        'allowed_mentions' => ['parse' => []],
    ], JSON_UNESCAPED_UNICODE);
    if ($payload === false) {
        return;
    }
    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => $payload,
            'timeout' => 3,
            'ignore_errors' => true,
        ],
    ]);
    @file_get_contents($url, false, $ctx);
}

/** Sum live spectators across seeded tournament rooms. */
function tcgTournamentSpectatorCount(string $tournamentId): int {
    if (!function_exists('tcgLiveSpectatorCount')) {
        require_once __DIR__ . '/spectate.php';
    }
    $n = 0;
    foreach (tcgTournamentFetchMatches($tournamentId) as $m) {
        $rid = trim((string)($m['room_id'] ?? ''));
        if ($rid === '') {
            continue;
        }
        $n += tcgLiveSpectatorCount($rid);
    }
    return $n;
}

/**
 * Enforce rules_template against a locked deck snapshot.
 *
 * @param array<string,mixed> $snap
 * @throws Exception
 */
function tcgTournamentAssertRulesTemplate(string $template, array $snap, string $gameMode): void {
    $template = (string)$template;
    if ($template === '' || $template === 'standard') {
        return;
    }
    if ($template === 'starters_only') {
        $src = (string)($snap['source'] ?? '');
        $starter = trim((string)($snap['starter_key'] ?? ''));
        if ($src !== 'starter' && $starter === '') {
            throw new Exception('This event requires an official starter deck', 400);
        }
        return;
    }
    require_once __DIR__ . '/cards_data.php';
    require_once __DIR__ . '/deck_validate.php';
    $main = is_array($snap['main_nos'] ?? null) ? $snap['main_nos'] : [];
    $energy = is_array($snap['energy_nos'] ?? null) ? $snap['energy_nos'] : [];
    $allNos = array_merge($main, $energy);
    if ($template === 'highlander') {
        $counts = [];
        foreach ($allNos as $no) {
            $no = (string)$no;
            $counts[$no] = ($counts[$no] ?? 0) + 1;
            if ($counts[$no] > 1) {
                throw new Exception('Highlander: only 1 copy of each card allowed (' . $no . ')', 400);
            }
        }
        return;
    }
    $cards = tcgLoadCardsData();
    $map = tcgBuildCardMap($cards);
    foreach ($allNos as $no) {
        $no = (string)$no;
        $card = $map[$no] ?? null;
        if (!$card) {
            throw new Exception('Unknown card in deck: ' . $no, 400);
        }
        $rarity = strtoupper(trim((string)($card['rarity'] ?? $card['rarity_en'] ?? '')));
        if ($template === 'pauper') {
            // Allow N / R / C / U / CL; reject SR+ / SEC / etc.
            if ($rarity !== '' && !in_array($rarity, ['N', 'R', 'C', 'U', 'CL'], true)) {
                throw new Exception('Pauper: card ' . $no . ' rarity ' . $rarity . ' not allowed', 400);
            }
        }
    }
}

function tcgTournamentBracketSize(int $n): int {
    $n = max(2, $n);
    $p = 1;
    while ($p < $n) {
        $p <<= 1;
    }
    return $p;
}

/**
 * @param list<string> $playerIds
 * @return list<array{p1:?string,p2:?string,bye:?string}>
 */
function tcgTournamentBuildRound1Pairings(array $playerIds): array {
    $playerIds = array_values(array_filter(array_map('strval', $playerIds), static fn($id) => $id !== ''));
    $n = count($playerIds);
    if ($n < 1) {
        return [];
    }
    $size = tcgTournamentBracketSize($n);
    $slots = array_fill(0, $size, null);
    for ($i = 0; $i < $n; $i++) {
        $slots[$i] = $playerIds[$i];
    }
    $pairings = [];
    for ($i = 0; $i < $size / 2; $i++) {
        $a = $slots[$i] ?? null;
        $b = $slots[$size - 1 - $i] ?? null;
        $bye = null;
        if ($a !== null && $b === null) {
            $bye = $a;
        } elseif ($b !== null && $a === null) {
            $bye = $b;
        }
        $pairings[] = ['p1' => $a, 'p2' => $b, 'bye' => $bye];
    }
    return $pairings;
}

/** @return list<int> */
function tcgTournamentPrizePercents(int $places): array {
    if ($places <= 1) {
        return [100];
    }
    if ($places === 2) {
        return [70, 30];
    }
    return [50, 30, 20];
}

function tcgTournamentAssertHost(array $row, string $uid): void {
    if ((string)($row['host_discord_id'] ?? '') !== $uid) {
        throw new Exception('Host only', 403);
    }
}

/** @return array<string,mixed>|null */
function tcgTournamentFetch(string $id): ?array {
    $stmt = tcgDb()->prepare('SELECT * FROM tcg_tournaments WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/** @return list<array<string,mixed>> */
function tcgTournamentFetchEntrants(string $tournamentId): array {
    $stmt = tcgDb()->prepare(
        'SELECT e.*, u.username, u.avatar_url
         FROM tcg_tournament_entrants e
         LEFT JOIN tcg_users u ON u.discord_id = e.discord_id
         WHERE e.tournament_id = ?
         ORDER BY CASE WHEN e.seed IS NULL THEN 9999 ELSE e.seed END, e.registered_at ASC'
    );
    $stmt->execute([$tournamentId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** @return list<array<string,mixed>> */
function tcgTournamentFetchMatches(string $tournamentId): array {
    $stmt = tcgDb()->prepare(
        'SELECT * FROM tcg_tournament_matches WHERE tournament_id = ?
         ORDER BY round ASC, bracket_slot ASC'
    );
    $stmt->execute([$tournamentId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function tcgTournamentLedgerWrite(
    string $tournamentId,
    ?string $discordId,
    string $kind,
    int $amount,
    string $idempotencyKey,
    array $meta = []
): bool {
    $db = tcgDb();
    try {
        $db->prepare(
            'INSERT INTO tcg_tournament_ledger
             (tournament_id, discord_id, kind, amount, idempotency_key, meta_json, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $tournamentId,
            $discordId,
            $kind,
            $amount,
            $idempotencyKey,
            json_encode($meta, JSON_UNESCAPED_UNICODE) ?: '{}',
            time(),
        ]);
        return true;
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), 'UNIQUE') || str_contains($e->getMessage(), 'unique')) {
            return false;
        }
        throw $e;
    }
}

/**
 * @param array{slot?:int,starter?:string}|null $choice
 * @return array{name:string,main_nos:list<string>,energy_nos:list<string>,sleeve_id:string,playmat_id:string,playmat_brightness:float,source:string,starter_key:string}
 */
function tcgTournamentDeckSnapshotForUser(string $discordId, string $gameMode, ?array $choice = null): array {
    require_once __DIR__ . '/ranked_room.php';
    require_once __DIR__ . '/booster.php';
    $gameMode = tcgNormalizeGameMode($gameMode);
    if (tcgIsRandomizedGameMode($gameMode)) {
        $cards = tcgLoadCardsData();
        $allCards = $cards['cards'] ?? [];
        $cardMap = tcgBuildCardMap($cards);
        $deck = tcgGenerateValidatedRandomDeckLists($allCards, $cardMap);
        if (!$deck) {
            throw new Exception('Could not generate a legal random deck', 400);
        }
        return [
            'name' => (string)$deck['name'],
            'main_nos' => $deck['main_nos'],
            'energy_nos' => $deck['energy_nos'],
            'sleeve_id' => '',
            'playmat_id' => '',
            'playmat_brightness' => 1.0,
            'source' => 'random',
            'starter_key' => '',
        ];
    }

    if (is_array($choice)) {
        $slot = isset($choice['slot']) ? (int)$choice['slot'] : 0;
        $starter = trim((string)($choice['starter'] ?? ''));
        if ($starter !== '') {
            if (!in_array($starter, tcgOwnedStarterKeys($discordId), true)) {
                throw new Exception('You do not own that starter deck', 400);
            }
            if ($gameMode === TCG_GAME_MODE_STARTERS || $gameMode === 'starters') {
                // ok
            }
            tcgSetRankedStarterEquip($discordId, $starter);
        } elseif ($slot > 0) {
            if ($gameMode === TCG_GAME_MODE_STARTERS || $gameMode === 'starters') {
                throw new Exception('Starters mode requires a starter deck', 400);
            }
            $db = tcgDb();
            $stmt = $db->prepare('SELECT slot FROM tcg_deck_presets WHERE discord_id = ? AND slot = ?');
            $stmt->execute([$discordId, $slot]);
            if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
                throw new Exception('Deck not found', 404);
            }
            $db->prepare('UPDATE tcg_deck_presets SET equipped = 0 WHERE discord_id = ?')->execute([$discordId]);
            $db->prepare('UPDATE tcg_deck_presets SET equipped = 1 WHERE discord_id = ? AND slot = ?')
                ->execute([$discordId, $slot]);
            tcgClearRankedStarterEquip($discordId);
        }
    }

    $row = tcgGetEquippedDeckRow($discordId);
    if (!$row) {
        throw new Exception('Equip a deck before registering', 400);
    }
    if ($gameMode === TCG_GAME_MODE_STARTERS || $gameMode === 'starters') {
        if (($row['source'] ?? '') !== 'starter') {
            throw new Exception('Starters mode requires an equipped starter deck', 400);
        }
        $starterKey = trim((string)($row['starter_key'] ?? ''));
        if ($starterKey === '' || !in_array($starterKey, tcgOwnedStarterKeys($discordId), true)) {
            throw new Exception('Invalid starter deck', 400);
        }
    }

    $main = json_decode($row['main_deck'] ?? '[]', true) ?: [];
    $energy = json_decode($row['energy_deck'] ?? '[]', true) ?: [];
    $main = array_values(array_map('strval', $main));
    $energy = array_values(array_map('strval', $energy));
    $cards = tcgLoadCardsData();
    $cardMap = tcgBuildCardMap($cards);
    $ownedCheck = (($row['source'] ?? '') === 'starter') ? null : tcgGetCollectionMap($discordId);
    $v = tcgValidateDeckLists($main, $energy, $cardMap, $ownedCheck);
    if (!$v['valid']) {
        throw new Exception('Equipped deck is not legal: ' . implode('; ', $v['errors'] ?? ['invalid']), 400);
    }

    return [
        'name' => tcgNormalizeDeckPresetName($row['name'] ?? 'Tournament Deck'),
        'main_nos' => $main,
        'energy_nos' => $energy,
        'sleeve_id' => tcgNormalizeSleeveId($row['sleeve_id'] ?? ''),
        'playmat_id' => tcgNormalizePlaymatId($row['playmat_id'] ?? ''),
        'playmat_brightness' => tcgNormalizePlaymatBrightness($row['playmat_brightness'] ?? 1.0),
        'source' => (string)($row['source'] ?? 'preset'),
        'starter_key' => (string)($row['starter_key'] ?? ''),
    ];
}

/**
 * Decks the user can lock in for a tournament game mode.
 * @return array{success:bool,game_mode:string,randomized:bool,decks:list<array<string,mixed>>,needs_deck_builder:bool}
 */
function tcgTournamentEligibleDecksForUser(string $discordId, string $gameMode): array {
    require_once __DIR__ . '/booster.php';
    $gameMode = tcgNormalizeGameMode($gameMode);
    if (tcgIsRandomizedGameMode($gameMode)) {
        return [
            'success' => true,
            'game_mode' => $gameMode,
            'randomized' => true,
            'decks' => [],
            'needs_deck_builder' => false,
        ];
    }

    $decks = [];
    $cards = tcgLoadCardsData();
    $cardMap = tcgBuildCardMap($cards);

    if ($gameMode === TCG_GAME_MODE_STARTERS || $gameMode === 'starters') {
        foreach (tcgOwnedStarterKeys($discordId) as $key) {
            $lists = tcgGetStarterDeckLists($key, $cards);
            $v = tcgValidateDeckLists($lists['main_deck'], $lists['energy_deck'], $cardMap, null);
            if (!$v['valid']) {
                continue;
            }
            $decks[] = [
                'type' => 'starter',
                'starter' => $key,
                'label' => tcgStarterLabel($key),
                'name' => (string)($lists['name'] ?? tcgStarterLabel($key)),
            ];
        }
    } else {
        $owned = tcgGetCollectionMap($discordId);
        $stmt = tcgDb()->prepare(
            'SELECT slot, name, main_deck, energy_deck, equipped FROM tcg_deck_presets
             WHERE discord_id = ? ORDER BY slot ASC'
        );
        $stmt->execute([$discordId]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $main = json_decode($row['main_deck'] ?? '[]', true) ?: [];
            $energy = json_decode($row['energy_deck'] ?? '[]', true) ?: [];
            $v = tcgValidateDeckLists($main, $energy, $cardMap, $owned);
            if (!$v['valid']) {
                continue;
            }
            $decks[] = [
                'type' => 'preset',
                'slot' => (int)$row['slot'],
                'name' => tcgNormalizeDeckPresetName($row['name'] ?? 'Deck'),
                'equipped' => (int)($row['equipped'] ?? 0) === 1,
            ];
        }
        foreach (tcgOwnedStarterKeys($discordId) as $key) {
            $lists = tcgGetStarterDeckLists($key, $cards);
            $v = tcgValidateDeckLists($lists['main_deck'], $lists['energy_deck'], $cardMap, null);
            if (!$v['valid']) {
                continue;
            }
            $decks[] = [
                'type' => 'starter',
                'starter' => $key,
                'label' => tcgStarterLabel($key),
                'name' => (string)($lists['name'] ?? tcgStarterLabel($key)),
            ];
        }
    }

    return [
        'success' => true,
        'game_mode' => $gameMode,
        'randomized' => false,
        'decks' => $decks,
        'needs_deck_builder' => count($decks) === 0,
    ];
}

/** @param array<string,mixed> $row */
function tcgTournamentPublicRow(array $row, ?array $counts = null): array {
    $settings = tcgTournamentDecodeSettings($row['settings_json'] ?? '{}');
    $out = [
        'id' => (string)$row['id'],
        'host_discord_id' => (string)$row['host_discord_id'],
        'title' => (string)$row['title'],
        'status' => (string)$row['status'],
        'game_mode' => tcgNormalizeGameMode($row['game_mode'] ?? 'standard'),
        'start_at' => (int)$row['start_at'],
        'checkin_mins' => (int)$row['checkin_mins'],
        'min_players' => (int)$row['min_players'],
        'max_players' => (int)$row['max_players'],
        'entry_fee_coins' => (int)$row['entry_fee_coins'],
        'prize_pool_coins' => (int)$row['prize_pool_coins'],
        'settings' => $settings,
        'created_at' => (int)$row['created_at'],
        'updated_at' => (int)$row['updated_at'],
    ];
    if ($counts !== null) {
        $out['entrant_count'] = (int)($counts['total'] ?? 0);
        $out['checked_in_count'] = (int)($counts['checked_in'] ?? 0);
    }
    return $out;
}

/** @param array<string,mixed> $e */
function tcgTournamentPublicEntrant(array $e, bool $includeDeck = false): array {
    $out = [
        'discord_id' => (string)$e['discord_id'],
        'username' => (string)($e['username'] ?? 'Player'),
        'avatar_url' => $e['avatar_url'] ?? null,
        'status' => (string)$e['status'],
        'seed' => isset($e['seed']) && $e['seed'] !== null ? (int)$e['seed'] : null,
        'paid_coins' => (int)($e['paid_coins'] ?? 0),
        'registered_at' => (int)($e['registered_at'] ?? 0),
        'checked_in_at' => isset($e['checked_in_at']) && $e['checked_in_at'] !== null
            ? (int)$e['checked_in_at'] : null,
    ];
    if ($includeDeck) {
        $snap = json_decode((string)($e['deck_snapshot'] ?? '{}'), true);
        $out['deck_snapshot'] = is_array($snap) ? $snap : null;
    }
    return $out;
}

/** @param array<string,mixed> $m */
function tcgTournamentPublicMatch(array $m): array {
    return [
        'id' => (string)$m['id'],
        'tournament_id' => (string)$m['tournament_id'],
        'round' => (int)$m['round'],
        'bracket_slot' => (int)$m['bracket_slot'],
        'p1_discord_id' => $m['p1_discord_id'] !== null ? (string)$m['p1_discord_id'] : null,
        'p2_discord_id' => $m['p2_discord_id'] !== null ? (string)$m['p2_discord_id'] : null,
        'room_id' => $m['room_id'] !== null && $m['room_id'] !== '' ? (string)$m['room_id'] : null,
        'status' => (string)$m['status'],
        'winner_discord_id' => $m['winner_discord_id'] !== null ? (string)$m['winner_discord_id'] : null,
        'connect_deadline_at' => isset($m['connect_deadline_at']) && $m['connect_deadline_at'] !== null
            ? (int)$m['connect_deadline_at'] : null,
    ];
}
