<?php
/**
 * PR pack vol.9 / Anniversary 2026 promo gap handlers.
 */

function prVol9EffectTypes(): array {
    return [
        'mill_fill_wr_optional_live_deck_top',
        'optional_opp_wr_members_to_deck_bottom_then_wait',
    ];
}

function prVol9IsEffectType(string $type): bool {
    return in_array($type, prVol9EffectTypes(), true);
}

function prVol9ResolveEffect(array $state, string $pid, array $source, array $ab, array $ctx = []): array {
    $type = $ab['type'] ?? '';
    if (!prVol9IsEffectType($type)) {
        return $state;
    }
    $p = &$state['players'][$pid];
    $name = $source['name_en'] ?? $source['name'] ?? 'Card';

    switch ($type) {
        case 'mill_fill_wr_optional_live_deck_top':
            if (!empty($state['pending_prompt'])) {
                break;
            }
            $target = intval($ab['target_wr'] ?? 8);
            $wrCount = count($p['waiting_room'] ?? []);
            if ($wrCount >= $target) {
                break;
            }
            $need = $target - $wrCount;
            $milled = takeFromMainDeckTop($state, $pid, $need);
            if (empty($milled)) {
                break;
            }
            $p['waiting_room'] = array_merge($p['waiting_room'], $milled);
            if (function_exists('spBp5NotifyCardsToWr')) {
                $state = spBp5NotifyCardsToWr($state, $pid, $milled);
            }
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] milled " . count($milled) . " to Waiting Room (fill to $target).");
            $liveCandidates = [];
            foreach ($milled as $c) {
                if (isLiveTypeCard($c)) {
                    $liveCandidates[] = cardPromptSummary($c);
                }
            }
            if (empty($liveCandidates)) {
                break;
            }
            $state['pending_prompt'] = [
                'type'          => 'mill_fill_wr_optional_live_deck_top',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_name'   => $name,
                'milled_ids'    => array_values(array_map(
                    fn($c) => (string)($c['instance_id'] ?? ''),
                    $milled
                )),
                'candidates'    => $liveCandidates,
                'prompt'        => 'Put 1 milled Live on top of your deck? (or Skip)',
                'choices'       => array_merge(
                    ['skip'],
                    array_map(fn($c) => (string)($c['instance_id'] ?? ''), $liveCandidates)
                ),
                'choice_labels' => array_merge(
                    ['Skip'],
                    array_map(fn($c) => (string)($c['name_en'] ?? $c['name'] ?? 'Live'), $liveCandidates)
                ),
                'ability'       => $ab,
            ];
            break;

        case 'optional_opp_wr_members_to_deck_bottom_then_wait':
            if (!empty($state['pending_prompt'])) {
                break;
            }
            $opp = ($pid === 'p1') ? 'p2' : 'p1';
            $need = intval($ab['wr_count'] ?? 3);
            $candidates = [];
            foreach ($state['players'][$opp]['waiting_room'] ?? [] as $c) {
                if (($c['card_type'] ?? '') === 'メンバー') {
                    $candidates[] = cardPromptSummary($c);
                }
            }
            if (count($candidates) < $need) {
                break;
            }
            $state['pending_prompt'] = [
                'type'          => 'optional_opp_wr_members_to_deck_bottom_then_wait',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_name'   => $name,
                'opp'           => $opp,
                'need'          => $need,
                'max_blade'     => intval($ab['max_original_blade'] ?? 3),
                'candidates'    => $candidates,
                'prompt'        => "Optional: put $need opponent WR Members on deck bottom, then Wait 1 (original Blade ≤"
                    . intval($ab['max_original_blade'] ?? 3) . ')?',
                'choices'       => ['yes', 'skip'],
                'choice_labels' => ['Yes — pick Members', 'Skip'],
                'ability'       => $ab,
            ];
            break;
    }

    return $state;
}

function prVol9ResolvePrompt(array $state, string $owner, array $prompt, string $choice, array $data): ?array {
    $type = $prompt['type'] ?? '';
    if ($type === 'mill_fill_wr_optional_live_deck_top') {
        if ($choice === 'skip' || $choice === 'no' || $choice === 'cancel' || $choice === '') {
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Member') . '] skipped Live to deck top.');
            unset($state['pending_prompt']);
            $state['seq']++;
            return finishPromptEffects($state);
        }
        $pickId = $data['card_id'] ?? $choice;
        $milledIds = array_fill_keys($prompt['milled_ids'] ?? [], true);
        if ($pickId === '' || empty($milledIds[$pickId])) {
            throw new Exception('Choose a Live milled by this effect, or Skip');
        }
        $p = &$state['players'][$owner];
        $picked = null;
        $rest = [];
        foreach ($p['waiting_room'] as $c) {
            if (!$picked && ($c['instance_id'] ?? '') === $pickId && isLiveTypeCard($c)) {
                $picked = $c;
            } else {
                $rest[] = $c;
            }
        }
        if (!$picked) {
            throw new Exception('Live card not found in Waiting Room');
        }
        $p['waiting_room'] = $rest;
        array_unshift($p['main_deck'], $picked);
        $state = addLog($state, $state['players'][$owner]['name'] .
            ' — [' . ($prompt['source_name'] ?? 'Member') . '] put '
            . cardDisplayName($picked) . ' on deck top.');
        unset($state['pending_prompt']);
        $state['seq']++;
        return finishPromptEffects($state);
    }

    if ($type === 'optional_opp_wr_members_to_deck_bottom_then_wait') {
        if ($choice === 'skip' || $choice === 'no' || $choice === 'cancel') {
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Member') . '] skipped WR→deck bottom.');
            unset($state['pending_prompt']);
            $state['seq']++;
            return finishPromptEffects($state);
        }
        if ($choice !== 'yes') {
            // Second step: card ids selected
            $ids = $data['card_ids'] ?? $data['ids'] ?? [];
            if (!is_array($ids) || empty($ids)) {
                if ($choice !== '' && $choice !== 'yes') {
                    $ids = array_values(array_filter(array_map('trim', explode(',', $choice))));
                }
            }
            $need = intval($prompt['need'] ?? 3);
            if (count($ids) !== $need) {
                throw new Exception("Select exactly $need opponent Waiting Room Members");
            }
            $opp = $prompt['opp'] ?? (($owner === 'p1') ? 'p2' : 'p1');
            $oppP = &$state['players'][$opp];
            $picked = [];
            $rest = [];
            $want = array_fill_keys($ids, true);
            foreach ($oppP['waiting_room'] as $c) {
                $iid = (string)($c['instance_id'] ?? '');
                if (isset($want[$iid]) && ($c['card_type'] ?? '') === 'メンバー' && !isset($picked[$iid])) {
                    $picked[$iid] = $c;
                } else {
                    $rest[] = $c;
                }
            }
            if (count($picked) !== $need) {
                throw new Exception('Invalid opponent WR Member selection');
            }
            // Preserve pick order for deck bottom (first selected = deepest? Rule: any order — append in pick order so last is bottom-most near end).
            $ordered = [];
            foreach ($ids as $id) {
                if (isset($picked[$id])) {
                    $ordered[] = $picked[$id];
                }
            }
            $oppP['waiting_room'] = $rest;
            $oppP['main_deck'] = array_merge($oppP['main_deck'], $ordered);
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Member') . "] put $need opponent WR Members on deck bottom.");
            unset($state['pending_prompt']);
            // Chain: wait opp member with original blade ≤ max
            $maxBlade = intval($prompt['max_blade'] ?? 3);
            $waitCands = [];
            foreach ($oppP['stage'] as $slot => $mbr) {
                if (!$mbr) {
                    continue;
                }
                $blade = isset($mbr['printed_blade_override'])
                    ? intval($mbr['printed_blade_override'])
                    : intval($mbr['blade'] ?? 0);
                if ($blade <= $maxBlade && !memberIsInWait($mbr)) {
                    $waitCands[] = array_merge(cardPromptSummary($mbr), ['slot' => $slot]);
                }
            }
            if (empty($waitCands)) {
                $state['seq']++;
                return finishPromptEffects($state);
            }
            if (count($waitCands) === 1) {
                $slot = $waitCands[0]['slot'];
                waitMember($oppP['stage'][$slot], $state);
                $state = addLog($state, $state['players'][$owner]['name'] .
                    ' — Waited ' . ($waitCands[0]['name_en'] ?? 'Member') .
                    " (original Blade ≤$maxBlade).");
                $state['seq']++;
                return finishPromptEffects($state);
            }
            $state['pending_prompt'] = [
                'type'          => 'pr_vol9_wait_opp_max_blade',
                'owner'         => $owner,
                'responder'     => $owner,
                'opp'           => $opp,
                'max_blade'     => $maxBlade,
                'candidates'    => $waitCands,
                'source_name'   => $prompt['source_name'] ?? 'Member',
                'prompt'        => "Choose 1 opponent Stage Member (original Blade ≤$maxBlade) to Wait.",
                'ability'       => $prompt['ability'] ?? [],
            ];
            $state['seq']++;
            return $state;
        }
        // choice === yes → ask for multi-pick (client sends card_ids)
        $state['pending_prompt'] = array_merge($prompt, [
            'step'   => 'pick',
            'prompt' => 'Select ' . intval($prompt['need'] ?? 3)
                . ' opponent WR Members (order = deck bottom order).',
        ]);
        $state['seq']++;
        return $state;
    }

    if ($type === 'pr_vol9_wait_opp_max_blade') {
        $opp = $prompt['opp'] ?? (($owner === 'p1') ? 'p2' : 'p1');
        $slot = $data['slot'] ?? $choice;
        $valid = [];
        foreach ($prompt['candidates'] ?? [] as $c) {
            if (($c['slot'] ?? '') !== '') {
                $valid[$c['slot']] = true;
            }
        }
        if ($slot === '' || empty($valid[$slot]) || empty($state['players'][$opp]['stage'][$slot])) {
            throw new Exception('Choose a valid opponent Stage Member');
        }
        waitMember($state['players'][$opp]['stage'][$slot], $state);
        $mName = $state['players'][$opp]['stage'][$slot]['name_en']
            ?? $state['players'][$opp]['stage'][$slot]['name'] ?? 'Member';
        $state = addLog($state, $state['players'][$owner]['name'] .
            " — Waited $mName (original Blade ≤" . intval($prompt['max_blade'] ?? 3) . ').');
        unset($state['pending_prompt']);
        $state['seq']++;
        return finishPromptEffects($state);
    }

    return null;
}
