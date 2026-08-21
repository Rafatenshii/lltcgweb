<?php
/**
 * Tournament Phase 3 — Swiss / double-elim / best-of series helpers.
 */

/** @return array{p1_wins:int,p2_wins:int,best_of:int,games:list<array<string,mixed>>} */
function tcgTournamentDecodeMatchMeta(?string $json): array {
    $raw = json_decode((string)$json, true);
    if (!is_array($raw)) {
        $raw = [];
    }
    $bestOf = (int)($raw['best_of'] ?? 1);
    if ($bestOf !== 3) {
        $bestOf = 1;
    }
    $games = is_array($raw['games'] ?? null) ? $raw['games'] : [];
    return [
        'p1_wins' => max(0, (int)($raw['p1_wins'] ?? 0)),
        'p2_wins' => max(0, (int)($raw['p2_wins'] ?? 0)),
        'best_of' => $bestOf,
        'games' => $games,
    ];
}

/** @param array<string,mixed> $meta */
function tcgTournamentEncodeMatchMeta(array $meta): string {
    return json_encode($meta, JSON_UNESCAPED_UNICODE) ?: '{}';
}

function tcgTournamentSwissRoundCount(int $playerCount): int {
    $n = max(2, $playerCount);
    $rounds = (int)ceil(log($n, 2));
    return max(3, min(5, $rounds));
}

/**
 * Pair players for a Swiss round. Prefer similar records; avoid rematches when possible.
 *
 * @param list<string> $playerIds
 * @param array<string,array{wins:int,losses:int}> $records
 * @param list<array{0:string,1:string}> $priorPairs unordered pairs already played
 * @return list<array{p1:?string,p2:?string,bye:?string}>
 */
function tcgTournamentBuildSwissPairings(array $playerIds, array $records, array $priorPairs = []): array {
    $playerIds = array_values(array_filter(array_map('strval', $playerIds), static fn($id) => $id !== ''));
    usort($playerIds, static function ($a, $b) use ($records) {
        $wa = (int)($records[$a]['wins'] ?? 0);
        $wb = (int)($records[$b]['wins'] ?? 0);
        if ($wa !== $wb) {
            return $wb <=> $wa;
        }
        $la = (int)($records[$a]['losses'] ?? 0);
        $lb = (int)($records[$b]['losses'] ?? 0);
        if ($la !== $lb) {
            return $la <=> $lb;
        }
        return strcmp($a, $b);
    });

    $played = [];
    foreach ($priorPairs as $pair) {
        $a = (string)($pair[0] ?? '');
        $b = (string)($pair[1] ?? '');
        if ($a === '' || $b === '') {
            continue;
        }
        $key = $a < $b ? ($a . '|' . $b) : ($b . '|' . $a);
        $played[$key] = true;
    }

    $pairings = [];
    $remaining = $playerIds;
    while (count($remaining) >= 2) {
        $p1 = array_shift($remaining);
        $pickIdx = null;
        foreach ($remaining as $i => $cand) {
            $key = $p1 < $cand ? ($p1 . '|' . $cand) : ($cand . '|' . $p1);
            if (!isset($played[$key])) {
                $pickIdx = $i;
                break;
            }
        }
        if ($pickIdx === null) {
            $pickIdx = 0;
        }
        $p2 = $remaining[$pickIdx];
        array_splice($remaining, $pickIdx, 1);
        $pairings[] = ['p1' => $p1, 'p2' => $p2, 'bye' => null];
    }
    if (count($remaining) === 1) {
        $bye = $remaining[0];
        $pairings[] = ['p1' => $bye, 'p2' => null, 'bye' => $bye];
    }
    return $pairings;
}

/**
 * @param list<array<string,mixed>> $matches
 * @return array<string,array{wins:int,losses:int}>
 */
function tcgTournamentRecordsFromMatches(array $matches): array {
    $out = [];
    foreach ($matches as $m) {
        if ((string)($m['status'] ?? '') !== 'done') {
            continue;
        }
        $w = (string)($m['winner_discord_id'] ?? '');
        $p1 = (string)($m['p1_discord_id'] ?? '');
        $p2 = (string)($m['p2_discord_id'] ?? '');
        if ($w === '') {
            continue;
        }
        foreach ([$p1, $p2] as $pid) {
            if ($pid === '') {
                continue;
            }
            if (!isset($out[$pid])) {
                $out[$pid] = ['wins' => 0, 'losses' => 0];
            }
        }
        $out[$w]['wins']++;
        $loser = ($w === $p1) ? $p2 : (($w === $p2) ? $p1 : '');
        if ($loser !== '') {
            $out[$loser]['losses']++;
        }
    }
    return $out;
}

/**
 * Prior unordered pairs from completed matches (for Swiss rematch avoidance).
 *
 * @param list<array<string,mixed>> $matches
 * @return list<array{0:string,1:string}>
 */
function tcgTournamentPriorPairsFromMatches(array $matches): array {
    $pairs = [];
    foreach ($matches as $m) {
        $p1 = (string)($m['p1_discord_id'] ?? '');
        $p2 = (string)($m['p2_discord_id'] ?? '');
        if ($p1 !== '' && $p2 !== '') {
            $pairs[] = [$p1, $p2];
        }
    }
    return $pairs;
}

/**
 * Preview empty bracket slots for UI before the event starts.
 *
 * @return list<array{round:int,bracket_slot:int,label:string,bracket_side:string}>
 */
function tcgTournamentBracketPreview(int $playerCap, string $format = 'single_elim'): array {
    $format = in_array($format, ['single_elim', 'double_elim', 'swiss'], true) ? $format : 'single_elim';
    $n = max(2, $playerCap);
    $out = [];
    if ($format === 'swiss') {
        $rounds = tcgTournamentSwissRoundCount($n);
        $slots = (int)ceil($n / 2);
        for ($r = 1; $r <= $rounds; $r++) {
            for ($s = 0; $s < $slots; $s++) {
                $out[] = [
                    'round' => $r,
                    'bracket_slot' => $s,
                    'label' => 'Swiss R' . $r,
                    'bracket_side' => 'swiss',
                ];
            }
        }
        return $out;
    }
    $size = tcgTournamentBracketSize($n);
    $round = 1;
    for ($slots = (int)($size / 2); $slots >= 1; $slots = (int)($slots / 2), $round++) {
        $label = $slots === 1 ? 'Final' : ($slots === 2 ? 'Semifinals' : ('Round of ' . ($slots * 2)));
        for ($s = 0; $s < $slots; $s++) {
            $out[] = [
                'round' => $round,
                'bracket_slot' => $s,
                'label' => $label,
                'bracket_side' => 'winners',
            ];
        }
    }
    if ($format === 'double_elim') {
        // Rough losers-bracket skeleton sized to winners R1 count.
        $lSlots = (int)($size / 2);
        for ($s = 0; $s < max(1, $lSlots - 1); $s++) {
            $out[] = [
                'round' => 1,
                'bracket_slot' => $s,
                'label' => 'Losers',
                'bracket_side' => 'losers',
            ];
        }
        $out[] = [
            'round' => 1,
            'bracket_slot' => 0,
            'label' => 'Grand Final',
            'bracket_side' => 'grand_final',
        ];
    }
    return $out;
}
