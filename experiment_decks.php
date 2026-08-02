<?php
/**
 * Deck Experiment — save/load legal decks by short password or account preset.
 * Playable only in Free Mode (unranked). Password load stays open for sharing.
 */
require_once __DIR__ . '/config/paths.php';
require_once __DIR__ . '/deck_validate.php';
require_once __DIR__ . '/game_mode.php';
require_once __DIR__ . '/decklog_import.php';
tcgDefinePathConstants();

define('EXPERIMENT_PASSWORD_LEN', 8);
define('EXPERIMENT_PASSWORD_CHARS', 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789');
define('EXPERIMENT_PASSWORD_MAX', 16);
define('EXPERIMENT_DECK_MAX_AGE', 60 * 60 * 24 * 180); // 180 days
define('TCG_MAX_EXPERIMENT_PRESETS', 10);

function ensureExperimentDecksDir(): void {
    if (!is_dir(EXPERIMENT_DECKS_DIR)) {
        mkdir(EXPERIMENT_DECKS_DIR, 0755, true);
    }
}

function normalizeExperimentPassword(string $raw): string {
    return strtoupper(preg_replace('/[^A-Z0-9]/', '', $raw));
}

function generateExperimentPassword(): string {
    $chars = EXPERIMENT_PASSWORD_CHARS;
    $len = strlen($chars);
    $pw = '';
    for ($i = 0; $i < EXPERIMENT_PASSWORD_LEN; $i++) {
        $pw .= $chars[random_int(0, $len - 1)];
    }
    return $pw;
}

function experimentDeckPath(string $password): string {
    return EXPERIMENT_DECKS_DIR . $password . '.json';
}

/** True when the join/create body selects a Deck Experiment deck (password or account preset). */
function tcgBodyUsesExperimentDeck(array $body): bool {
    $deck = trim((string)($body['deck'] ?? ''));
    if ($deck === 'experiment'
        || str_starts_with($deck, 'experiment:')
        || str_starts_with($deck, 'experiment_preset:')) {
        return true;
    }
    if (normalizeExperimentPassword((string)($body['experiment_password'] ?? '')) !== '') {
        return true;
    }
    $slot = intval($body['experiment_slot'] ?? 0);
    return $slot >= 1;
}

/**
 * Experiment decks are Free Mode only. CPU seats are exempt.
 * Call before resolving player decks for unranked create/join/casual.
 */
function tcgAssertUnrankedDeckForGameMode(array $body): void {
    $deck = trim((string)($body['deck'] ?? ''));
    if ($deck === 'cpu' || str_starts_with($deck, 'cpu:')) {
        return;
    }
    $mode = tcgNormalizeGameMode($body['game_mode'] ?? TCG_GAME_MODE_STANDARD);
    $usesExp = tcgBodyUsesExperimentDeck($body);
    if (tcgIsFreeGameMode($mode)) {
        if (!$usesExp) {
            throw new Exception('Free requires a Deck Experiment deck (saved or password)', 400);
        }
        return;
    }
    if ($usesExp) {
        throw new Exception('Deck Experiment decks can only be used in Free', 400);
    }
}

function assertExperimentAllowedForRoom(array $body): void {
    $mode = tcgNormalizeGameMode($body['game_mode'] ?? '');
    if (!tcgIsFreeGameMode($mode)) {
        throw new Exception('Deck Experiment decks can only be used in Free', 400);
    }
}

function validateExperimentDeckPayload(array $main, array $energy, array $cardsData): array {
    $main = array_values(array_map('strval', $main));
    $energy = array_values(array_map('strval', $energy));
    $cardMap = tcgBuildCardMap($cardsData);
    $validation = tcgValidateDeckLists($main, $energy, $cardMap, null);
    if (!$validation['valid']) {
        throw new Exception('Invalid deck: ' . implode('; ', $validation['errors']));
    }
    return ['main' => $main, 'energy' => $energy];
}

function normalizeExperimentDeckName(string $name): string {
    $name = trim($name);
    if ($name === '') {
        return 'Deck Experiment';
    }
    if (function_exists('mb_substr')) {
        return mb_substr($name, 0, 40);
    }
    return substr($name, 0, 40);
}

function readExperimentDeckFile(string $password): ?array {
    $password = normalizeExperimentPassword($password);
    if ($password === '') {
        return null;
    }
    $path = experimentDeckPath($password);
    if (!is_file($path)) {
        return null;
    }
    $data = json_decode((string)file_get_contents($path), true);
    if (!is_array($data)) {
        return null;
    }
    $savedAt = intval($data['saved_at'] ?? 0);
    if ($savedAt > 0 && (time() - $savedAt) > EXPERIMENT_DECK_MAX_AGE) {
        @unlink($path);
        return null;
    }
    $main = $data['main_deck'] ?? null;
    $energy = $data['energy_deck'] ?? null;
    if (!is_array($main) || !is_array($energy)) {
        return null;
    }
    return [
        'password'     => $password,
        'name'         => normalizeExperimentDeckName((string)($data['name'] ?? '')),
        'main_deck'    => array_values(array_map('strval', $main)),
        'energy_deck'  => array_values(array_map('strval', $energy)),
        'saved_at'     => $savedAt,
    ];
}

function writeExperimentDeckFile(string $password, string $name, array $main, array $energy): void {
    ensureExperimentDecksDir();
    $payload = [
        'password'     => $password,
        'name'         => normalizeExperimentDeckName($name),
        'main_deck'    => $main,
        'energy_deck'  => $energy,
        'saved_at'     => time(),
    ];
    $path = experimentDeckPath($password);
    $tmp = $path . '.tmp.' . getmypid();
    file_put_contents($tmp, json_encode($payload, JSON_UNESCAPED_UNICODE), LOCK_EX);
    rename($tmp, $path);
}

function apiExperimentDeckSave(array $body): array {
    $cards = json_decode(file_get_contents(CARDS_FILE), true);
    $main = $body['main_deck'] ?? null;
    $energy = $body['energy_deck'] ?? null;
    if (!is_array($main) || !is_array($energy)) {
        throw new Exception('main_deck and energy_deck required');
    }
    $validated = validateExperimentDeckPayload($main, $energy, $cards);
    $name = normalizeExperimentDeckName((string)($body['name'] ?? ''));

    $password = normalizeExperimentPassword((string)($body['password'] ?? ''));
    if ($password !== '') {
        if (strlen($password) < 4 || strlen($password) > EXPERIMENT_PASSWORD_MAX) {
            throw new Exception('Password must be 4–' . EXPERIMENT_PASSWORD_MAX . ' letters/numbers');
        }
    } else {
        $attempts = 0;
        do {
            $password = generateExperimentPassword();
            $attempts++;
        } while (is_file(experimentDeckPath($password)) && $attempts < 50);
        if (is_file(experimentDeckPath($password))) {
            throw new Exception('Could not generate a unique password — try again');
        }
    }

    writeExperimentDeckFile($password, $name, $validated['main'], $validated['energy']);

    return [
        'success'  => true,
        'password' => $password,
        'name'     => $name,
        'main_count' => count($validated['main']),
        'energy_count' => count($validated['energy']),
    ];
}

function apiExperimentDeckLoad(array $body): array {
    $password = normalizeExperimentPassword((string)($body['password'] ?? $_GET['password'] ?? ''));
    if ($password === '') {
        throw new Exception('Password required');
    }
    $stored = readExperimentDeckFile($password);
    if (!$stored) {
        throw new Exception('No experiment deck found for that password');
    }

    $cards = json_decode(file_get_contents(CARDS_FILE), true);
    validateExperimentDeckPayload($stored['main_deck'], $stored['energy_deck'], $cards);

    return [
        'success'     => true,
        'password'    => $stored['password'],
        'name'        => $stored['name'],
        'main_deck'   => $stored['main_deck'],
        'energy_deck' => $stored['energy_deck'],
    ];
}

/**
 * Import a Bushiroad Deck Log recipe into Deck Experiment lists.
 * Optionally persists as an experiment password (default on).
 */
function apiExperimentDecklogImport(array $body): array {
    if (function_exists('tcgRateLimitForAction')) {
        tcgRateLimitForAction('experiment_decklog_import', $body);
    }
    $code = tcgNormalizeDecklogCode((string)($body['code'] ?? $body['url'] ?? $_GET['code'] ?? ''));
    $payload = tcgFetchDecklogView($code);
    $cards = json_decode((string)file_get_contents(CARDS_FILE), true);
    if (!is_array($cards)) {
        throw new Exception('Card database unavailable', 500);
    }
    $mapped = tcgMapDecklogPayloadToExperimentLists($payload, $cards);
    $validated = validateExperimentDeckPayload($mapped['main_deck'], $mapped['energy_deck'], $cards);
    $name = normalizeExperimentDeckName(
        $mapped['title'] !== '' ? $mapped['title'] : ('Deck Log ' . ($mapped['deck_id'] ?: $code))
    );
    $save = !isset($body['save']) || !in_array(
        strtolower((string)$body['save']),
        ['0', 'false', 'no', 'off'],
        true
    );
    $password = '';
    if ($save) {
        $attempts = 0;
        do {
            $password = generateExperimentPassword();
            $attempts++;
        } while (is_file(experimentDeckPath($password)) && $attempts < 50);
        if (is_file(experimentDeckPath($password))) {
            throw new Exception('Could not generate a unique password — try again');
        }
        writeExperimentDeckFile($password, $name, $validated['main'], $validated['energy']);
    }
    return [
        'success' => true,
        'decklog_code' => $mapped['deck_id'] !== '' ? $mapped['deck_id'] : $code,
        'name' => $name,
        'main_deck' => $validated['main'],
        'energy_deck' => $validated['energy'],
        'password' => $password,
        'saved' => $save && $password !== '',
    ];
}

function apiExperimentRandomDeck(array $body): array {
    require_once __DIR__ . '/deckgen.php';
    $data = json_decode(file_get_contents(CARDS_FILE), true);
    $cards = $data['cards'] ?? [];
    $tier = in_array((string)($body['tier'] ?? ''), ['easy', 'normal', 'hard'], true)
        ? (string)$body['tier']
        : 'normal';
    $gen = generateEnhancedCpuDeckLists($cards, $tier);
    validateExperimentDeckPayload($gen['main_deck'], $gen['energy_deck'], $data);
    return [
        'success'     => true,
        'name'        => 'Random Deck',
        'main_deck'   => array_values($gen['main_deck'] ?? []),
        'energy_deck' => array_values($gen['energy_deck'] ?? []),
    ];
}

function resolveExperimentDeckFromPassword(string $password, array $cardsData): array {
    $stored = readExperimentDeckFile($password);
    if (!$stored) {
        throw new Exception('Experiment deck not found for that password');
    }
    validateExperimentDeckPayload($stored['main_deck'], $stored['energy_deck'], $cardsData);
    return [
        'deck_choice' => 'experiment:' . $stored['password'],
        'deck_label'  => $stored['name'],
        'main_nos'    => $stored['main_deck'],
        'energy_nos'  => $stored['energy_deck'],
    ];
}

function resolveExperimentPresetDeckLists(array $body, array $cardsData, int $slot): array {
    if ($slot < 1 || $slot > TCG_MAX_EXPERIMENT_PRESETS) {
        throw new Exception('Experiment deck slot must be 1–' . TCG_MAX_EXPERIMENT_PRESETS, 400);
    }
    require_once __DIR__ . '/llr_auth_load.php';
    require_once __DIR__ . '/db.php';
    $uid = tcgRequireAuthUser($body);
    $db = tcgDb();
    $stmt = $db->prepare('SELECT name, main_deck, energy_deck FROM tcg_experiment_presets WHERE discord_id = ? AND slot = ?');
    $stmt->execute([$uid, $slot]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new Exception('Experiment deck #' . $slot . ' not found', 400);
    }
    $main = json_decode((string)$row['main_deck'], true) ?: [];
    $energy = json_decode((string)$row['energy_deck'], true) ?: [];
    $validated = validateExperimentDeckPayload($main, $energy, $cardsData);
    return [
        'deck_choice' => 'experiment_preset:' . $slot,
        'deck_label'  => normalizeExperimentDeckName((string)($row['name'] ?? ('Experiment ' . $slot))),
        'main_nos'    => $validated['main'],
        'energy_nos'  => $validated['energy'],
    ];
}
