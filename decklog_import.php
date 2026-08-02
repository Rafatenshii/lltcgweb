<?php
/**
 * Bushiroad Deck Log → Deck Experiment import helpers.
 * API: GET https://decklog.bushiroad.com/system/app/api/view/{CODE}
 */

declare(strict_types=1);

/** Love Live! series TCG on Deck Log. */
const TCG_DECKLOG_GAME_TITLE_ID = 11;
const TCG_DECKLOG_VIEW_API = 'https://decklog.bushiroad.com/system/app/api/view/';

function tcgNormalizeDecklogCode(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }
    if (preg_match('#decklog\.bushiroad\.com/view/([A-Za-z0-9]+)#i', $raw, $m)) {
        return strtoupper($m[1]);
    }
    $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $raw) ?? '');
    return $code;
}

/**
 * Resolve a Deck Log card_number to a cards.json card_no.
 *
 * @param array<string, true> $cardNos
 */
function tcgResolveDecklogCardNo(string $cardNumber, array $cardNos): ?string
{
    $cardNumber = trim($cardNumber);
    if ($cardNumber === '') {
        return null;
    }
    $candidates = [
        $cardNumber,
        str_replace('＋', '+', $cardNumber),
        str_replace('+', '＋', $cardNumber),
    ];
    foreach ($candidates as $c) {
        if (isset($cardNos[$c])) {
            return $c;
        }
    }
    return null;
}

/**
 * Expand Deck Log list entries into a flat list of card_no strings.
 *
 * @param list<array<string,mixed>> $entries
 * @param array<string, true> $cardNos
 * @return array{0: list<string>, 1: list<string>} [resolved copies, missing card_numbers]
 */
function tcgExpandDecklogEntries(array $entries, array $cardNos): array
{
    $out = [];
    $missing = [];
    foreach ($entries as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $rawNo = (string)($entry['card_number'] ?? '');
        $n = max(0, intval($entry['num'] ?? $entry['_num'] ?? 0));
        if ($n <= 0 || $rawNo === '') {
            continue;
        }
        $resolved = tcgResolveDecklogCardNo($rawNo, $cardNos);
        if ($resolved === null) {
            $missing[] = $rawNo;
            continue;
        }
        for ($i = 0; $i < $n; $i++) {
            $out[] = $resolved;
        }
    }
    return [$out, array_values(array_unique($missing))];
}

/**
 * Map Deck Log JSON payload to main/energy lists.
 *
 * @param array<string,mixed> $payload
 * @param array<string,mixed> $cardsData cards.json root
 * @return array{main_deck: list<string>, energy_deck: list<string>, title: string, deck_id: string}
 */
function tcgMapDecklogPayloadToExperimentLists(array $payload, array $cardsData): array
{
    $gameId = intval($payload['game_title_id'] ?? 0);
    if ($gameId !== TCG_DECKLOG_GAME_TITLE_ID) {
        throw new Exception(
            'That Deck Log recipe is not a Love Live! TCG deck (game_title_id=' . $gameId . ').',
            400
        );
    }
    $cardNos = [];
    foreach ($cardsData['cards'] ?? [] as $card) {
        $no = trim((string)($card['card_no'] ?? ''));
        if ($no !== '') {
            $cardNos[$no] = true;
        }
    }
    [$main, $missMain] = tcgExpandDecklogEntries($payload['list'] ?? [], $cardNos);
    [$energy, $missEnergy] = tcgExpandDecklogEntries($payload['sub_list'] ?? [], $cardNos);
    $missing = array_values(array_unique(array_merge($missMain, $missEnergy)));
    if ($missing) {
        throw new Exception(
            'Unknown card(s) in recipe: ' . implode(', ', array_slice($missing, 0, 8)),
            400
        );
    }
    return [
        'main_deck' => $main,
        'energy_deck' => $energy,
        'title' => trim((string)($payload['title'] ?? '')),
        'deck_id' => strtoupper((string)($payload['deck_id'] ?? '')),
    ];
}

/**
 * @return array<string,mixed>
 */
function tcgFetchDecklogView(string $code): array
{
    $code = tcgNormalizeDecklogCode($code);
    if ($code === '' || strlen($code) < 3 || strlen($code) > 16) {
        throw new Exception('Enter a valid Deck Log code (or view URL).', 400);
    }
    $url = TCG_DECKLOG_VIEW_API . rawurlencode($code);
    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 12,
            'header' => implode("\r\n", [
                'User-Agent: Mozilla/5.0 (compatible; LLTCG-DeckExperiment/1.0)',
                'Accept: application/json',
                'Referer: https://decklog.bushiroad.com/view/' . $code,
                'Origin: https://decklog.bushiroad.com',
            ]),
            'ignore_errors' => true,
        ],
    ]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false || $raw === '') {
        throw new Exception('Could not reach Bushiroad Deck Log. Try again later.', 502);
    }
    $status = 0;
    if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
        $status = intval($m[1]);
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new Exception('Deck Log returned an unexpected response.', 502);
    }
    if ($status >= 400 || isset($data['error']) || empty($data['deck_id'])) {
        throw new Exception('Deck Log recipe not found for code ' . $code . '.', 404);
    }
    return $data;
}
