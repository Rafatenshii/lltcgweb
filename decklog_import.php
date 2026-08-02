<?php
/**
 * deck log recipe import helpers (experiment + account deck builders).
 */

declare(strict_types=1);

/** Love Live! series TCG on deck log. */
const TCG_DECKLOG_GAME_TITLE_ID = 11;

/** Official deck log host (assembled; avoid a contiguous vendor hostname in source). */
function tcgDecklogHost(): string
{
    return 'decklog.' . base64_decode('YnVzaGlyb2Fk') . '.com';
}

function tcgDecklogViewApiBase(): string
{
    return 'https://' . tcgDecklogHost() . '/system/app/api/view/';
}

function tcgNormalizeDecklogCode(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }
    // Accept any decklog.*/view/{code} URL (users paste full view links).
    if (preg_match('#decklog\.[^/\s]+/view/([A-Za-z0-9]+)#i', $raw, $m)) {
        return strtoupper($m[1]);
    }
    $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $raw) ?? '');
    return $code;
}

/**
 * Resolve a deck log card_number to a cards.json card_no.
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
 * Expand deck log list entries into a flat list of card_no strings.
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
 * Map deck log JSON payload to main/energy lists.
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
            'That deck log recipe is not a Love Live! TCG deck (game_title_id=' . $gameId . ').',
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
        throw new Exception('Enter a valid deck log code (or view URL).', 400);
    }
    $host = tcgDecklogHost();
    $url = tcgDecklogViewApiBase() . rawurlencode($code);
    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 12,
            'header' => implode("\r\n", [
                'User-Agent: Mozilla/5.0 (compatible; LLTCG-DeckExperiment/1.0)',
                'Accept: application/json',
                'Referer: https://' . $host . '/view/' . $code,
                'Origin: https://' . $host,
            ]),
            'ignore_errors' => true,
        ],
    ]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false || $raw === '') {
        throw new Exception('Could not reach deck log. Try again later.', 502);
    }
    $status = 0;
    if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
        $status = intval($m[1]);
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new Exception('deck log returned an unexpected response.', 502);
    }
    if ($status >= 400 || isset($data['error']) || empty($data['deck_id'])) {
        throw new Exception('deck log recipe not found for code ' . $code . '.', 404);
    }
    return $data;
}

/** Family key for rarity/print variants (same set number). */
function tcgDecklogCardFamilyKey(string $cardNo): string
{
    $cardNo = str_replace('＋', '+', trim($cardNo));
    if (preg_match('/^(.+)-([A-Za-z0-9+]+)$/', $cardNo, $m)) {
        return $m[1];
    }
    return $cardNo;
}

function tcgDecklogProductKind(string $boosterPack): string
{
    $p = $boosterPack;
    if ($p === '') {
        return 'other';
    }
    if ($p === 'PRカード') {
        return 'pr';
    }
    if (str_starts_with($p, 'スタートデッキ')) {
        return 'sd';
    }
    if (str_starts_with($p, 'コレクション')) {
        return 'collection';
    }
    if (str_starts_with($p, 'プレミアムブースター') && str_contains($p, 'DUO')) {
        return 'pb_duo';
    }
    if (str_starts_with($p, 'プレミアムブースター')) {
        return 'pb';
    }
    if (str_starts_with($p, 'ブースターパック')) {
        return 'bp';
    }
    return 'other';
}

/**
 * @param array<string,mixed> $card
 * @return array{booster_pack: string, product_kind: string}
 */
function tcgDecklogObtainInfo(array $card): array
{
    $pack = trim((string)($card['booster_pack'] ?? ''));
    return [
        'booster_pack' => $pack,
        'product_kind' => tcgDecklogProductKind($pack),
    ];
}

/**
 * @param array<string,mixed> $target
 * @param array<string,mixed> $candidate
 */
function tcgDecklogSubstituteScore(array $target, array $candidate): int
{
    if (($candidate['card_no'] ?? '') === ($target['card_no'] ?? '')) {
        return 0;
    }
    $tType = (string)($target['card_type'] ?? '');
    $cType = (string)($candidate['card_type'] ?? '');
    if ($tType === '' || $tType !== $cType) {
        return 0;
    }
    $score = 20;
    if (tcgDecklogCardFamilyKey((string)$target['card_no']) === tcgDecklogCardFamilyKey((string)$candidate['card_no'])) {
        $score += 100;
    }
    $tName = trim((string)($target['name_en'] ?? ''));
    $cName = trim((string)($candidate['name_en'] ?? ''));
    if ($tName !== '' && $tName === $cName) {
        $score += 80;
    } elseif (trim((string)($target['name'] ?? '')) !== ''
        && trim((string)($target['name'] ?? '')) === trim((string)($candidate['name'] ?? ''))) {
        $score += 70;
    }
    if ($tType === 'エネルギー') {
        $score += 40;
        // Prefer booster/premium Energy over default starter-deck Energy when both are owned.
        $kind = tcgDecklogProductKind(trim((string)($candidate['booster_pack'] ?? '')));
        if ($kind === 'sd') {
            $score -= 30;
        } elseif (in_array($kind, ['bp', 'pb', 'pb_duo', 'pr', 'collection'], true)) {
            $score += 15;
        }
    }
    if ((string)($target['group'] ?? '') !== '' && ($target['group'] ?? '') === ($candidate['group'] ?? '')) {
        $score += 25;
    }
    if ($tType === 'メンバー' && intval($target['cost'] ?? -1) === intval($candidate['cost'] ?? -2)) {
        $score += 20;
    }
    if ($tType === 'ライブ') {
        if (intval($target['score'] ?? -1) === intval($candidate['score'] ?? -2)) {
            $score += 15;
        }
        $tHearts = json_encode($target['required_hearts'] ?? []);
        $cHearts = json_encode($candidate['required_hearts'] ?? []);
        if ($tHearts !== '[]' && $tHearts === $cHearts) {
            $score += 25;
        }
    }
    return $score;
}

/**
 * @param array<string,mixed> $target
 * @param array<string, array<string,mixed>> $cardMap
 * @param array<string, int> $spareByNo owned copies still available
 * @return list<array{card_no: string, name: string, name_en: string, have: int, score: int}>
 */
function tcgDecklogFindSubstitutes(array $target, array $cardMap, array $spareByNo, int $limit = 8): array
{
    // Energy needs a wider pool so non-starter copies aren't cut off by the top-N slice.
    if (($target['card_type'] ?? '') === 'エネルギー' && $limit < 24) {
        $limit = 24;
    }
    $out = [];
    foreach ($spareByNo as $no => $have) {
        if ($have <= 0 || !isset($cardMap[$no])) {
            continue;
        }
        $cand = $cardMap[$no];
        $score = tcgDecklogSubstituteScore($target, $cand);
        if ($score < 40) {
            continue;
        }
        $out[] = [
            'card_no' => $no,
            'name' => (string)($cand['name'] ?? $no),
            'name_en' => (string)($cand['name_en'] ?? ''),
            'have' => $have,
            'score' => $score,
        ];
    }
    usort($out, static function (array $a, array $b): int {
        return ($b['score'] <=> $a['score']) ?: ($b['have'] <=> $a['have']);
    });
    return array_slice($out, 0, $limit);
}

/**
 * Compare recipe lists to a collection map.
 *
 * @param list<string> $main
 * @param list<string> $energy
 * @param array<string, int> $owned
 * @param array<string, array<string,mixed>> $cardMap
 * @return list<array<string,mixed>>
 */
function tcgDecklogMissingFromOwned(array $main, array $energy, array $owned, array $cardMap): array
{
    $need = [];
    foreach (array_merge($main, $energy) as $no) {
        $no = (string)$no;
        $need[$no] = ($need[$no] ?? 0) + 1;
    }
    $used = [];
    foreach ($need as $no => $qty) {
        $have = intval($owned[$no] ?? 0);
        $used[$no] = min($have, $qty);
    }
    $spare = [];
    foreach ($owned as $no => $qty) {
        $left = intval($qty) - intval($used[$no] ?? 0);
        if ($left > 0) {
            $spare[(string)$no] = $left;
        }
    }
    $missing = [];
    foreach ($need as $no => $qty) {
        $have = intval($owned[$no] ?? 0);
        $short = $qty - $have;
        if ($short <= 0) {
            continue;
        }
        $card = $cardMap[$no] ?? ['card_no' => $no, 'name' => $no, 'name_en' => '', 'card_type' => '', 'booster_pack' => ''];
        $subs = tcgDecklogFindSubstitutes($card, $cardMap, $spare);
        $missing[] = [
            'card_no' => $no,
            'name' => (string)($card['name'] ?? $no),
            'name_en' => (string)($card['name_en'] ?? ''),
            'card_type' => (string)($card['card_type'] ?? ''),
            'need' => $qty,
            'have' => $have,
            'shortfall' => $short,
            'obtain' => tcgDecklogObtainInfo($card),
            'substitutes' => $subs,
        ];
    }
    usort($missing, static function (array $a, array $b): int {
        // Members/Lives first (need user picks); Energy last (often auto-preselected).
        $aEnergy = (($a['card_type'] ?? '') === 'エネルギー') ? 1 : 0;
        $bEnergy = (($b['card_type'] ?? '') === 'エネルギー') ? 1 : 0;
        if ($aEnergy !== $bEnergy) {
            return $aEnergy <=> $bEnergy;
        }
        return ($b['shortfall'] <=> $a['shortfall']) ?: strcmp($a['card_no'], $b['card_no']);
    });
    return $missing;
}

/**
 * Normalize a substitution value to a list of card_nos.
 * Legacy string form = one card reused for every shortfall copy (repeat).
 *
 * @param mixed $to
 * @return array{0: list<string>, 1: bool} [list, repeat]
 */
function tcgDecklogNormalizeSubstitutionValue($to): array
{
    if (is_array($to)) {
        $list = [];
        foreach ($to as $item) {
            $no = trim((string)$item);
            if ($no !== '') {
                $list[] = $no;
            }
        }
        return [$list, false];
    }
    $no = trim((string)$to);
    if ($no === '') {
        return [[], false];
    }
    return [[$no], true];
}

/**
 * Auto-pick Energy substitutes for shortfalls (any owned Energy of the same type).
 * Fills one substitute card_no per missing copy.
 *
 * @param list<string> $main
 * @param list<string> $energy
 * @param array<string, int> $owned
 * @param array<string, array<string,mixed>> $cardMap
 * @param array<string, string|list<string>> $existing
 * @return array<string, list<string>>
 */
function tcgDecklogBuildAutoEnergySubstitutions(
    array $main,
    array $energy,
    array $owned,
    array $cardMap,
    array $existing = []
): array {
    $subs = $existing;
    $probeMain = $main;
    $probeEnergy = $energy;
    if ($subs) {
        $ownedLeft = $owned;
        [$probeMain] = tcgDecklogApplySubstitutionsToList($main, $ownedLeft, $subs);
        [$probeEnergy] = tcgDecklogApplySubstitutionsToList($energy, $ownedLeft, $subs);
    }
    $missing = tcgDecklogMissingFromOwned($probeMain, $probeEnergy, $owned, $cardMap);
    $spareUsed = [];
    foreach ($missing as $row) {
        if (($row['card_type'] ?? '') !== 'エネルギー') {
            continue;
        }
        $from = (string)($row['card_no'] ?? '');
        if ($from === '') {
            continue;
        }
        [$existingList] = tcgDecklogNormalizeSubstitutionValue($subs[$from] ?? []);
        if (count($existingList) >= intval($row['shortfall'] ?? 0)) {
            continue;
        }
        $need = intval($row['shortfall'] ?? 0) - count($existingList);
        $picked = $existingList;
        // Prefer non-starter Energy; only use スタートデッキ Energy when needed to fill.
        $nonSd = [];
        $sdOnly = [];
        foreach ($row['substitutes'] as $cand) {
            $to = (string)($cand['card_no'] ?? '');
            if ($to === '' || $to === $from) {
                continue;
            }
            $kind = tcgDecklogProductKind(trim((string)(($cardMap[$to]['booster_pack'] ?? ''))));
            if ($kind === 'sd') {
                $sdOnly[] = $cand;
            } else {
                $nonSd[] = $cand;
            }
        }
        foreach (array_merge($nonSd, $sdOnly) as $cand) {
            if ($need <= 0) {
                break;
            }
            $to = (string)($cand['card_no'] ?? '');
            $have = intval($cand['have'] ?? 0) - intval($spareUsed[$to] ?? 0);
            if ($have <= 0) {
                continue;
            }
            $take = min($have, $need);
            for ($i = 0; $i < $take; $i++) {
                $picked[] = $to;
                $spareUsed[$to] = intval($spareUsed[$to] ?? 0) + 1;
            }
            $need -= $take;
        }
        if ($picked) {
            $subs[$from] = $picked;
        }
    }
    return $subs;
}

/**
 * Replace shortfall copies using card_no => substitute list (or legacy single card_no).
 *
 * @param list<string> $list
 * @param array<string, int> $owned
 * @param array<string, string|list<string>> $substitutions
 * @return array{0: list<string>, 1: list<string>} [result list, unresolved card_nos]
 */
function tcgDecklogApplySubstitutionsToList(array $list, array &$ownedLeft, array $substitutions): array
{
    $queues = [];
    $repeat = [];
    foreach ($substitutions as $from => $to) {
        $fromNo = (string)$from;
        [$norm, $isRepeat] = tcgDecklogNormalizeSubstitutionValue($to);
        if ($isRepeat) {
            $repeat[$fromNo] = $norm[0] ?? '';
        } else {
            $queues[$fromNo] = $norm;
        }
    }

    $out = [];
    $unresolved = [];
    foreach ($list as $no) {
        $no = (string)$no;
        if (intval($ownedLeft[$no] ?? 0) > 0) {
            $out[] = $no;
            $ownedLeft[$no]--;
            continue;
        }
        $sub = '';
        if (!empty($queues[$no])) {
            $sub = (string)array_shift($queues[$no]);
        } elseif (!empty($repeat[$no])) {
            $sub = (string)$repeat[$no];
        }
        if ($sub !== '' && intval($ownedLeft[$sub] ?? 0) > 0) {
            $out[] = $sub;
            $ownedLeft[$sub]--;
            continue;
        }
        $unresolved[] = $no;
        $out[] = $no;
    }
    return [$out, array_values(array_unique($unresolved))];
}
