<?php
/**
 * Debug replay: export action sequences from live rooms and step through them in
 * replay_view mode. Client must send debug_mode=true (?debug URL flag).
 */

const REPLAY_SCHEMA_VERSION = 1;
const REPLAY_LOCK_TIMEOUT = 120.0;

function assertReplayDebugAllowed(array $body): void {
    if (empty($body['debug_mode'])) {
        throw new Exception('Replay requires debug_mode');
    }
}

function assertReplayExportAllowed(array $body, array $state): void {
    if (($state['status'] ?? '') === 'finished') {
        return;
    }
    assertReplayDebugAllowed($body);
}

function replayShouldRecordActions(array $state): bool {
    $mode = $state['mode'] ?? '';
    if ($mode === 'replay_view') {
        return false;
    }
    if (!empty($state['replay_handoff'])) {
        return false;
    }
    return true;
}

function cloneStateForReplayBaseline(array $state): array {
    $copy = json_decode(json_encode($state), true);
    unset(
        $copy['action_log'],
        $copy['replay_baseline'],
        $copy['replay'],
        $copy['replay_handoff']
    );
    return $copy;
}

function captureReplayBaselineIfNeeded(array $state): array {
    if (!replayShouldRecordActions($state)) {
        return $state;
    }
    if (!empty($state['action_log']) || !empty($state['replay_baseline'])) {
        return $state;
    }
    $state['replay_baseline'] = cloneStateForReplayBaseline($state);
    return $state;
}

function appendReplayAction(array $state, string $playerId, string $type, array $data): array {
    if (!replayShouldRecordActions($state)) {
        return $state;
    }
    if ($type === 'mulligan') {
        $deck = $state['players'][$playerId]['main_deck'] ?? [];
        $order = array_column($deck, 'instance_id');
        if ($order !== []) {
            $data['main_deck_order'] = $order;
        }
    }
    if (!isset($state['action_log']) || !is_array($state['action_log'])) {
        $state['action_log'] = [];
    }
    $state['action_log'][] = [
        'index'    => count($state['action_log']) + 1,
        'player'   => $playerId,
        'type'     => $type,
        'data'     => $data,
        'ts'       => time(),
        'game_seq' => intval($state['seq'] ?? 0),
    ];
    return $state;
}

function validateReplayFile(array $replay): void {
    if (intval($replay['schema_version'] ?? 0) !== REPLAY_SCHEMA_VERSION) {
        throw new Exception('Unsupported replay schema version');
    }
    if (empty($replay['baseline']) || !is_array($replay['baseline'])) {
        throw new Exception('Replay missing baseline state');
    }
    if (!isset($replay['actions']) || !is_array($replay['actions'])) {
        throw new Exception('Replay missing actions array');
    }
    $saver = $replay['meta']['saver_player_id'] ?? '';
    if ($saver !== 'p1' && $saver !== 'p2') {
        throw new Exception('Replay missing saver_player_id');
    }
}

function buildReplayExportPayload(array $state, string $saverPid): array {
    $baseline = $state['replay_baseline'] ?? null;
    $actions = $state['action_log'] ?? [];
    if (!$baseline) {
        $baseline = cloneStateForReplayBaseline($state);
    }
    if ($saverPid !== 'p1' && $saverPid !== 'p2') {
        throw new Exception('Invalid saver player');
    }
    $saverName = $state['players'][$saverPid]['name'] ?? $saverPid;
    return [
        'schema_version' => REPLAY_SCHEMA_VERSION,
        'meta' => [
            'saved_at'         => gmdate('c'),
            'saver_player_id'  => $saverPid,
            'saver_name'       => $saverName,
            'room_id'          => $state['room_id'] ?? '',
            'turn'             => intval($state['turn'] ?? 0),
            'phase'            => (string)($state['phase'] ?? ''),
            'game_seq'         => intval($state['seq'] ?? 0),
            'client_version'   => '0.1.6',
            'mode'             => $state['mode'] ?? null,
            'cpu_difficulty'   => $state['cpu_difficulty'] ?? null,
            'timing_source'    => !empty($state['phase_timer']) ? 'phase_timer' : 'action_timestamps',
            'duration_seconds' => replayDurationSeconds($actions),
        ],
        'baseline' => $baseline,
        'actions'  => $actions,
    ];
}

function replayDurationSeconds(array $actions): int {
    $first = null;
    $last = null;
    foreach ($actions as $a) {
        $ts = intval($a['ts'] ?? 0);
        if ($ts <= 0) {
            continue;
        }
        if ($first === null) {
            $first = $ts;
        }
        $last = $ts;
    }
    if ($first === null || $last === null) {
        return 0;
    }
    return max(0, $last - $first);
}

function replayActionBlockedByPendingPrompt(Throwable $e): bool {
    return str_contains($e->getMessage(), 'pending skill prompt');
}

function replayTransientPromptStateKeys(): array {
    return [
        'pending_prompt',
        'surveil_stash',
        '_surveil_chain',
        'look_stash',
        '_look_chain',
    ];
}

/** Strip unresolved prompt UI state — replay viewing is passive playback only. */
function replaySanitizeViewingState(array $state, array $actions = [], int $step = 0): array {
    foreach (replayTransientPromptStateKeys() as $key) {
        unset($state[$key]);
    }
    unset($state['pending_prompt_meta']);
    $state = replayNormalizePhaseForViewing($state, $actions, $step);
    return $state;
}

/** True when applied actions mean the match already left the coin-flip UI. */
function replayActionsPastCoinFlip(array $actions, int $step): bool {
    $step = max(0, min($step, count($actions)));
    for ($i = 0; $i < $step; $i++) {
        $type = (string)($actions[$i]['type'] ?? '');
        if ($type === '' || $type === 'ack_coin_flip') {
            continue;
        }
        // choose_first_player and everything after must not keep the coin overlay.
        return true;
    }
    return false;
}

/** True when applied actions mean mulligan / main / live has started. */
function replayActionsPastSetup(array $actions, int $step): bool {
    $step = max(0, min($step, count($actions)));
    for ($i = 0; $i < $step; $i++) {
        $type = (string)($actions[$i]['type'] ?? '');
        if (in_array($type, ['ack_coin_flip', 'choose_first_player', ''], true)) {
            continue;
        }
        return true;
    }
    return false;
}

/**
 * After seek, drop leftover coin_flip/setup phases that soft-skips left behind.
 * Viewing is a snapshot — never keep First Player modal on mid-match steps.
 */
function replayNormalizePhaseForViewing(array $state, array $actions, int $step): array {
    $phase = (string)($state['phase'] ?? '');
    $pid = $state['first_player'] ?? $state['active_player'] ?? 'p1';
    if ($pid !== 'p1' && $pid !== 'p2') {
        $pid = 'p1';
    }

    if (replayActionsPastCoinFlip($actions, $step) && ($phase === 'coin_flip' || !empty($state['coin_flip']))) {
        $state = replayForceLeaveCoinFlip($state, $pid);
        $phase = (string)($state['phase'] ?? '');
    }

    if (replayActionsPastSetup($actions, $step) && in_array($phase, ['coin_flip', 'setup', 'waiting', ''], true)) {
        $state = replayForceLeaveCoinFlip($state, $pid);
        $state = replayEnsureMulligansDone($state);
        if (($state['first_player'] ?? null) !== 'p1' && ($state['first_player'] ?? null) !== 'p2') {
            $state['first_player'] = $pid;
        }
        // Prefer an existing gameplay phase from applied actions; otherwise land on main.
        $phase = (string)($state['phase'] ?? '');
        if (in_array($phase, ['coin_flip', 'setup', 'waiting', ''], true)) {
            if (empty($state['players'][$pid]['energy_zone'])) {
                try {
                    $state = startTurn($state);
                } catch (Throwable $e) {
                    $state['phase'] = 'main_first';
                    $state['active_player'] = $pid;
                }
            } else {
                $first = $state['first_player'] ?? $pid;
                $state['phase'] = ($first === $pid) ? 'main_first' : 'main_second';
                $state['active_player'] = $state['active_player'] ?? $first;
            }
        }
    }

    if (($state['phase'] ?? '') !== 'coin_flip') {
        unset($state['coin_flip']);
    }
    return $state;
}

function replayRestoreMainDeckOrder(array $state, string $pid, array $order): array {
    if ($pid !== 'p1' && $pid !== 'p2') {
        return $state;
    }
    $p = &$state['players'][$pid];
    $byId = [];
    foreach ($p['main_deck'] ?? [] as $c) {
        $id = $c['instance_id'] ?? '';
        if ($id !== '') {
            $byId[$id] = $c;
        }
    }
    $ordered = [];
    foreach ($order as $id) {
        if (isset($byId[$id])) {
            $ordered[] = $byId[$id];
            unset($byId[$id]);
        }
    }
    foreach ($byId as $c) {
        $ordered[] = $c;
    }
    $p['main_deck'] = $ordered;
    return $state;
}

function replayExtractCardFromPlayer(array &$player, string $instanceId): ?array {
    foreach (['main_deck', 'hand', 'waiting_room'] as $zone) {
        foreach ($player[$zone] ?? [] as $i => $c) {
            if (($c['instance_id'] ?? '') === $instanceId) {
                array_splice($player[$zone], $i, 1);
                return $c;
            }
        }
    }
    return null;
}

/** Best-effort: put a recorded card back in hand when replay state drifted (legacy exports). */
function replayEnsureCardInHand(array &$state, string $pid, string $cardId): void {
    if ($cardId === '' || ($pid !== 'p1' && $pid !== 'p2')) {
        return;
    }
    $p = &$state['players'][$pid];
    if (findInHand($p['hand'], $cardId) !== false) {
        return;
    }
    // Do not yank cards that are already correctly on Stage or in Live storage —
    // live_start_order_sources (and similar) list those instance ids in card_ids (#111).
    foreach ($p['stage'] ?? [] as $mbr) {
        if ($mbr && ($mbr['instance_id'] ?? '') === $cardId) {
            return;
        }
    }
    foreach ($p['live_zone'] ?? [] as $c) {
        if (($c['instance_id'] ?? '') === $cardId) {
            return;
        }
    }
    foreach (['main_deck', 'waiting_room', 'success_lives', 'energy_deck'] as $zone) {
        foreach ($p[$zone] ?? [] as $i => $c) {
            if (($c['instance_id'] ?? '') === $cardId) {
                $p['hand'][] = $c;
                array_splice($p[$zone], $i, 1);
                return;
            }
        }
    }
    foreach ($p['energy_zone'] ?? [] as $i => $c) {
        if (($c['instance_id'] ?? '') === $cardId) {
            unset($c['active'], $c['skip_activate_next_turn']);
            $p['hand'][] = $c;
            array_splice($p['energy_zone'], $i, 1);
            return;
        }
    }
    // Stacked under Stage Members (hand→stack effects often leave the card here after drift).
    foreach ($p['stage'] ?? [] as $slot => &$mbr) {
        if (!$mbr || empty($mbr['stacked_members']) || !is_array($mbr['stacked_members'])) {
            continue;
        }
        foreach ($mbr['stacked_members'] as $i => $c) {
            if (($c['instance_id'] ?? '') === $cardId) {
                $p['hand'][] = $c;
                array_splice($mbr['stacked_members'], $i, 1);
                return;
            }
        }
    }
    unset($mbr);
}

/** Collect every card instance id a recorded resolve_prompt may need. */
function replayPromptCardIdsFromData(array $data): array {
    $ids = [];
    foreach (['card_id', 'pick_id', 'member_id', 'baton_id', 'baton_id2'] as $key) {
        if (!empty($data[$key]) && is_string($data[$key])) {
            $ids[] = $data[$key];
        }
    }
    foreach (['card_ids', 'discard_ids', 'member_ids', 'top_ids', 'wr_ids'] as $key) {
        foreach ($data[$key] ?? [] as $cid) {
            if (is_string($cid) && $cid !== '') {
                $ids[] = $cid;
            }
        }
    }
    // Some older clients stuffed the instance id into choice.
    $choice = $data['choice'] ?? '';
    if (is_string($choice) && str_starts_with($choice, 'card_')) {
        $ids[] = $choice;
    }
    return array_values(array_unique($ids));
}

function replayNormalizePromptData(array $data): array {
    if (empty($data['card_id'])) {
        foreach (['pick_id', 'member_id'] as $key) {
            if (!empty($data[$key]) && is_string($data[$key])) {
                $data['card_id'] = $data[$key];
                break;
            }
        }
        $choice = $data['choice'] ?? '';
        if (empty($data['card_id']) && is_string($choice) && str_starts_with($choice, 'card_')) {
            $data['card_id'] = $choice;
        }
    }
    return $data;
}

/** Clear a stuck pending prompt so seek can keep applying later recorded actions. */
function replaySoftSkipPendingPrompt(array $state, string $note = '', string $forType = ''): array {
    foreach (replayTransientPromptStateKeys() as $key) {
        unset($state[$key]);
    }
    if ($note !== '') {
        $state = addLog($state, 'Replay seek — skipped unresolved prompt (' . $note . ').');
    }
    // Soft-skipping a post-coin action must not leave the viewer parked on coin_flip.
    if ($forType !== '' && $forType !== 'ack_coin_flip'
        && (($state['phase'] ?? '') === 'coin_flip' || !empty($state['coin_flip']))) {
        $pid = $state['first_player'] ?? $state['active_player'] ?? 'p1';
        if ($pid !== 'p1' && $pid !== 'p2') {
            $pid = 'p1';
        }
        $state = replayForceLeaveCoinFlip($state, $pid);
        if ($forType === 'mulligan') {
            $state['phase'] = 'setup';
        } elseif (!in_array($forType, ['choose_first_player', ''], true)) {
            $state = replayForcePhaseForAction($state, $pid, $forType);
        }
    }
    try {
        $state = finishPromptEffects($state);
    } catch (Throwable $e) {
        // Keep seeking even if resume hooks fail after a soft skip.
    }
    // finishPromptEffects may chain another interactive prompt — drop it so seek
    // stays driven by recorded actions, not live UI validation.
    foreach (replayTransientPromptStateKeys() as $key) {
        unset($state[$key]);
    }
    unset($state['pending_prompt_meta']);
    return $state;
}

function replayPrepareRecordedPlayAction(array $state, string $pid, string $type, array $data): array {
    if ($type === 'set_live_cards') {
        foreach ($data['card_ids'] ?? [] as $cid) {
            replayEnsureCardInHand($state, $pid, (string)$cid);
        }
    } elseif ($type === 'play_member' && !empty($data['card_id'])) {
        replayEnsureCardInHand($state, $pid, (string)$data['card_id']);
    } elseif ($type === 'resolve_prompt' || $type === 'anti_softlock_skip') {
        $owner = $pid;
        $pr = $state['pending_prompt'] ?? null;
        if (is_array($pr) && (($pr['owner'] ?? '') === 'p1' || ($pr['owner'] ?? '') === 'p2')) {
            $owner = (string)$pr['owner'];
        }
        foreach (replayPromptCardIdsFromData($data) as $cid) {
            replayEnsureCardInHand($state, $owner, $cid);
            if ($owner !== $pid) {
                replayEnsureCardInHand($state, $pid, $cid);
            }
        }
    }
    return $state;
}

/** Apply surveil_arrange from recorded top/wr ids when stash cards diverged (e.g. mulligan shuffle). */
function replayApplySurveilArrangeFromRecorded(array $state, string $owner, array $data): array {
    $prompt = $state['pending_prompt'] ?? null;
    if (!is_array($prompt) || ($prompt['type'] ?? '') !== 'surveil_arrange') {
        throw new Exception('Replay surveil fallback: no surveil_arrange prompt');
    }
    $topIds = $data['top_ids'] ?? [];
    $wrIds = $data['wr_ids'] ?? [];
    $allIds = array_merge($topIds, $wrIds);
    if ($allIds === []) {
        throw new Exception('Replay surveil fallback: missing card ids');
    }
    $chain = $state['_surveil_chain'] ?? null;
    $arrangeTarget = $chain['target'] ?? $owner;
    $p = &$state['players'][$arrangeTarget];
    $looked = [];
    foreach ($allIds as $id) {
        $card = replayExtractCardFromPlayer($p, $id);
        if ($card) {
            $looked[] = $card;
        }
    }
    if (count($looked) < count($allIds)) {
        foreach ($state['surveil_stash'] ?? [] as $c) {
            $id = $c['instance_id'] ?? '';
            if ($id !== '' && in_array($id, $allIds, true)) {
                $have = array_column($looked, 'instance_id');
                if (!in_array($id, $have, true)) {
                    $looked[] = $c;
                }
            }
        }
    }
    applySurveilArrangement($p, $looked, $topIds, $wrIds);
    unset($state['surveil_stash'], $state['pending_prompt'], $state['_surveil_chain']);
    $state = addLog($state, $state['players'][$owner]['name'] .
        ' — [' . ($prompt['source_name'] ?? 'Member') . '] arranged ' . count($looked) . ' looked card(s).');
    if ($chain && ($chain['type'] ?? '') === 'reveal_top_live_score') {
        $source = findSourceCard($state, $owner, $chain['source_id'] ?? '');
        if ($source) {
            $state = revealDeckTopLiveScore(
                $state,
                $owner,
                $source,
                intval($chain['score_amount'] ?? 1)
            );
        }
    }
    $state['seq']++;
    $state = finishPromptEffects($state);
    return $state;
}

/** Apply surveil_pick_one_* from recorded card_id when look_cards diverged. */
function replayApplySurveilPickOneFromRecorded(array $state, string $owner, array $data): array {
    $prompt = $state['pending_prompt'] ?? null;
    if (!is_array($prompt)) {
        throw new Exception('Replay surveil pick fallback: no pending prompt');
    }
    $pickId = (string)($data['card_id'] ?? $data['choice'] ?? '');
    if ($pickId === '' || $pickId === 'pick') {
        throw new Exception('Choose 1 looked card');
    }
    $ownerP = &$state['players'][$owner];
    $looked = $prompt['look_cards'] ?? $state['surveil_stash'] ?? [];
    $picked = null;
    $rest = [];
    foreach ($looked as $c) {
        if (($c['instance_id'] ?? '') === $pickId) {
            $picked = $c;
        } else {
            $rest[] = $c;
        }
    }
    if (!$picked) {
        $picked = replayExtractCardFromPlayer($ownerP, $pickId);
        if (!$picked) {
            throw new Exception('Choose 1 looked card');
        }
        $rest = array_values(array_filter(
            $looked,
            static fn(array $c): bool => ($c['instance_id'] ?? '') !== $pickId
        ));
    }
    array_unshift($ownerP['main_deck'], $picked);
    if ($rest !== []) {
        $ownerP['waiting_room'] = array_merge($ownerP['waiting_room'] ?? [], $rest);
    }
    unset($state['pending_prompt'], $state['surveil_stash']);
    $state = addLog($state, $state['players'][$owner]['name'] .
        ' — [' . ($prompt['source_name'] ?? 'Member') . '] arranged deck top.');
    $state['seq']++;
    return $state;
}

function replayForceLeaveCoinFlip(array $state, string $pid): array {
    if (($state['phase'] ?? '') !== 'coin_flip') {
        return $state;
    }
    $flip = is_array($state['coin_flip'] ?? null) ? $state['coin_flip'] : [];
    $winner = $flip['winner'] ?? null;
    if ($winner !== 'p1' && $winner !== 'p2') {
        $winner = ($pid === 'p2') ? 'p2' : 'p1';
    }
    $first = $state['first_player'] ?? null;
    if ($first !== 'p1' && $first !== 'p2') {
        $first = $winner;
    }
    $state['first_player'] = $first;
    $state['active_player'] = $first;
    $state['phase'] = 'setup';
    unset($state['coin_flip']);
    return $state;
}

function replayEnsureMulligansDone(array $state): array {
    foreach (['p1', 'p2'] as $id) {
        if (empty($state['players'][$id])) {
            continue;
        }
        $state['players'][$id]['ready_mulligan'] = true;
        if (!isset($state['players'][$id]['mulligan_redrawn'])) {
            $state['players'][$id]['mulligan_redrawn'] = 0;
        }
    }
    return $state;
}

/** Advance a stuck coin/setup snapshot so recorded main/live actions can apply. */
function replayForcePhaseForAction(array $state, string $pid, string $type): array {
    $phase = (string)($state['phase'] ?? '');
    if ($type === 'choose_first_player') {
        if ($phase !== 'coin_flip') {
            $state['phase'] = 'coin_flip';
        }
        $flip = is_array($state['coin_flip'] ?? null) ? $state['coin_flip'] : [];
        $winner = $flip['winner'] ?? $pid;
        if ($winner !== 'p1' && $winner !== 'p2') {
            $winner = $pid;
        }
        $state['coin_flip'] = [
            'winner' => $winner,
            'ready'  => ['p1' => true, 'p2' => true],
            'since'  => intval($flip['since'] ?? time()),
        ];
        return $state;
    }
    if ($type === 'mulligan') {
        $state = replayForceLeaveCoinFlip($state, $pid);
        $state['phase'] = 'setup';
        if (($state['first_player'] ?? null) !== 'p1' && ($state['first_player'] ?? null) !== 'p2') {
            $state['first_player'] = $pid;
            $state['active_player'] = $pid;
        }
        return $state;
    }

    $mainish = in_array($type, ['play_member', 'activate_ability', 'end_main', 'force_own_timeout', 'resolve_prompt'], true);
    $liveish = in_array($type, ['set_live_cards', 'end_live_set', 'confirm_live', 'live_show_ack'], true);
    if (!$mainish && !$liveish) {
        return $state;
    }

    $state = replayForceLeaveCoinFlip($state, $pid);
    $phase = (string)($state['phase'] ?? '');
    if ($phase === 'setup' || $phase === 'waiting' || $phase === '') {
        $state = replayEnsureMulligansDone($state);
        if (($state['first_player'] ?? null) !== 'p1' && ($state['first_player'] ?? null) !== 'p2') {
            $state['first_player'] = $pid;
        }
        $state['active_player'] = $pid;
        $state = startTurn($state);
        $phase = (string)($state['phase'] ?? '');
    }
    if ($mainish && !in_array($phase, ['main_first', 'main_second'], true)) {
        $first = $state['first_player'] ?? $pid;
        $state['phase'] = ($first === $pid) ? 'main_first' : 'main_second';
        $state['active_player'] = $pid;
    }
    if ($liveish && ($state['phase'] ?? '') !== 'live_set') {
        $state['phase'] = 'live_set';
        $state['active_player'] = $pid;
    }
    return $state;
}

function replayPrepareStateForRecordedAction(array $state, string $pid, string $type): array {
    if (in_array($type, ['resolve_prompt', 'anti_softlock_skip'], true)) {
        $pr = $state['pending_prompt'] ?? null;
        if (is_array($pr) && ($pr['responder'] ?? '') !== $pid) {
            unset($state['pending_prompt']);
        }
        $phase = (string)($state['phase'] ?? '');
        // resolve_prompt used to return early and leave seek stuck on coin_flip.
        if (in_array($phase, ['coin_flip', 'setup', 'waiting', ''], true)) {
            $state = replayForcePhaseForAction($state, $pid, 'resolve_prompt');
        }
        return $state;
    }
    $pr = $state['pending_prompt'] ?? null;
    if (is_array($pr) && ($pr['responder'] ?? '') !== $pid) {
        unset($state['pending_prompt']);
    }

    $phase = (string)($state['phase'] ?? '');
    if ($type === 'choose_first_player' && $phase === 'coin_flip') {
        $flip = is_array($state['coin_flip'] ?? null) ? $state['coin_flip'] : [];
        if (empty($flip['ready']['p1']) || empty($flip['ready']['p2'])) {
            $state['coin_flip']['ready']['p1'] = true;
            $state['coin_flip']['ready']['p2'] = true;
        }
    }
    if ($type === 'mulligan' && in_array($phase, ['coin_flip', 'waiting', ''], true)) {
        $state = replayForcePhaseForAction($state, $pid, $type);
    }
    $gameplayTypes = [
        'play_member', 'activate_ability', 'end_main', 'force_own_timeout',
        'set_live_cards', 'end_live_set', 'confirm_live', 'live_show_ack',
        'resolve_prompt',
    ];
    if (in_array($type, $gameplayTypes, true)
        && in_array($phase, ['coin_flip', 'setup', 'waiting', ''], true)) {
        $state = replayForcePhaseForAction($state, $pid, $type);
    }
    return $state;
}

function replayLooksLikePromptInteractionError(string $msg): bool {
    $needles = [
        'Choose a card',
        'Choose 1',
        'Choose cards',
        'Card not in hand',
        'pending skill prompt',
        'No pending prompt',
        'No skill prompt',
        'That card is not',
        'Invalid choice',
        'must pick',
        'Must assign',
        'Not a valid',
        'from your hand',
        'from the Waiting Room',
        'from Waiting Room',
    ];
    foreach ($needles as $needle) {
        if (str_contains($msg, $needle)) {
            return true;
        }
    }
    return false;
}

/** Best-effort state repair before retrying a desynced recorded action. */
function replayApplyFixForRetry(array $state, string $pid, string $type, array $data, Throwable $e): array {
    $msg = $e->getMessage();
    if (str_contains($msg, 'Not in correct phase')
        || str_contains($msg, 'Not in coin flip')
        || str_contains($msg, 'Not in mulligan')
        || str_contains($msg, 'Wait for the coin flip')) {
        $state = replayForcePhaseForAction($state, $pid, $type);
    }
    if (str_contains($msg, 'Not your turn')) {
        $state['active_player'] = $pid;
        $pr = $state['pending_prompt'] ?? null;
        if (is_array($pr) && !empty($pr['responder'])) {
            $state['active_player'] = (string)$pr['responder'];
        }
    }
    if (str_contains($msg, 'Card not in hand')
        || str_contains($msg, 'Not a member card')
        || str_contains($msg, 'Choose a card')
        || str_contains($msg, 'from your hand')) {
        foreach (replayPromptCardIdsFromData($data) as $cid) {
            replayEnsureCardInHand($state, $pid, $cid);
        }
        if (!empty($data['card_id'])) {
            replayEnsureCardInHand($state, $pid, (string)$data['card_id']);
        }
    }
    if ($type === 'set_live_cards') {
        foreach ($data['card_ids'] ?? [] as $cid) {
            replayEnsureCardInHand($state, $pid, (string)$cid);
        }
    }
    if ($type === 'play_member' && !empty($data['card_id'])) {
        replayEnsureCardInHand($state, $pid, (string)$data['card_id']);
    }
    if ($type === 'activate_ability' && !empty($data['card_id'])) {
        replayEnsureCardInHand($state, $pid, (string)$data['card_id']);
    }
    if ($type === 'resolve_prompt' || $type === 'anti_softlock_skip') {
        $owner = $pid;
        $pr = $state['pending_prompt'] ?? null;
        if (is_array($pr) && (($pr['owner'] ?? '') === 'p1' || ($pr['owner'] ?? '') === 'p2')) {
            $owner = (string)$pr['owner'];
        }
        foreach (replayPromptCardIdsFromData($data) as $cid) {
            replayEnsureCardInHand($state, $owner, $cid);
            if ($owner !== $pid) {
                replayEnsureCardInHand($state, $pid, $cid);
            }
        }
        if (str_contains($msg, 'Not your turn')) {
            if (is_array($pr) && !empty($pr['responder'])) {
                $state['active_player'] = (string)$pr['responder'];
            } else {
                $state['active_player'] = $pid;
            }
        }
    }
    return $state;
}

function replayFinishRecordedAction(array $state, string $pid, string $type, array $data): array {
    if ($type === 'mulligan' && !empty($data['main_deck_order']) && is_array($data['main_deck_order'])) {
        $state = replayRestoreMainDeckOrder($state, $pid, $data['main_deck_order']);
    }
    return $state;
}

function replayTryApplyRecordedActionOnce(array $state, string $pid, string $type, array $data): array {
    try {
        $state = applyAction($state, $pid, $type, $data);
        return replayFinishRecordedAction($state, $pid, $type, $data);
    } catch (Throwable $e) {
        if ($type !== 'resolve_prompt'
            && $type !== 'anti_softlock_skip'
            && !empty($state['pending_prompt'])
            && replayActionBlockedByPendingPrompt($e)) {
            $state = replaySoftSkipPendingPrompt($state, 'cleared for ' . $type, $type);
            $state = applyAction($state, $pid, $type, $data);
            return replayFinishRecordedAction($state, $pid, $type, $data);
        }
        if ($type === 'resolve_prompt') {
            $msg = $e->getMessage();
            if (str_contains($msg, 'Must assign every looked card')) {
                return replayApplySurveilArrangeFromRecorded($state, $pid, $data);
            }
            if (str_contains($msg, 'Choose 1 looked card')) {
                return replayApplySurveilPickOneFromRecorded($state, $pid, $data);
            }
            if (str_contains($msg, 'No pending prompt')) {
                return $state;
            }
        }
        if ($type === 'anti_softlock_skip' && str_contains($e->getMessage(), 'No skill prompt')) {
            return $state;
        }
        throw $e;
    }
}

function replayApplyRecordedAction(array $state, string $pid, string $type, array $data, int $index): array {
    if ($type === 'resolve_prompt' || $type === 'anti_softlock_skip') {
        $data = replayNormalizePromptData($data);
    }
    $lastError = null;
    for ($attempt = 0; $attempt < 3; $attempt++) {
        $state = replayPrepareStateForRecordedAction($state, $pid, $type);
        $state = replayPrepareRecordedPlayAction($state, $pid, $type, $data);
        try {
            return replayTryApplyRecordedActionOnce($state, $pid, $type, $data);
        } catch (Throwable $e) {
            $lastError = $e;
            if ($attempt >= 2) {
                break;
            }
            $state = replayApplyFixForRetry($state, $pid, $type, $data, $e);
        }
    }
    $msg = $lastError ? $lastError->getMessage() : 'failed';
    // Seek must not stop on interactive prompt validation — soft-skip and continue.
    if ($type === 'resolve_prompt'
        || $type === 'anti_softlock_skip'
        || replayLooksLikePromptInteractionError($msg)
        || str_contains($msg, 'Cannot replace a Member that was played this turn')
        || str_contains($msg, 'Not enough active energy')) {
        return replaySoftSkipPendingPrompt(
            $state,
            '#' . $index . ' ' . $type . ': ' . $msg,
            $type
        );
    }
    throw $lastError ?? new Exception('Replay action #' . $index . ' failed');
}

function replayRestoreFromBaseline(
    array $baseline,
    string $roomId,
    string $p1Token,
    string $p2Token
): array {
    $state = json_decode(json_encode($baseline), true);
    $state['room_id'] = $roomId;
    if (!isset($state['players']['p1']) || !isset($state['players']['p2'])) {
        throw new Exception('Replay baseline missing both players');
    }
    $state['players']['p1']['token'] = $p1Token;
    $state['players']['p2']['token'] = $p2Token;
    unset(
        $state['action_log'],
        $state['replay_baseline'],
        $state['replay'],
        $state['replay_handoff']
    );
    if (($state['status'] ?? '') === 'finished') {
        $state['status'] = 'playing';
        unset($state['winner'], $state['end_reason'], $state['resigned_by']);
    }
    return $state;
}

function replayApplyActionsThrough(array $state, array $actions, int $step): array {
    $step = max(0, min($step, count($actions)));
    for ($i = 0; $i < $step; $i++) {
        $a = $actions[$i];
        $pid = $a['player'] ?? '';
        $type = $a['type'] ?? '';
        if ($pid !== 'p1' && $pid !== 'p2') {
            throw new Exception('Replay action #' . ($i + 1) . ' has invalid player');
        }
        if ($type === '') {
            throw new Exception('Replay action #' . ($i + 1) . ' missing type');
        }
        try {
            $state = replayApplyRecordedAction($state, $pid, $type, is_array($a['data'] ?? null) ? $a['data'] : [], $i + 1);
        } catch (Throwable $e) {
            throw new Exception(
                'Replay action #' . ($i + 1) . ' (' . $type . ' / ' . $pid . '): ' . $e->getMessage(),
                0,
                $e
            );
        }
        if (empty($state['pending_prompt'])) {
            $state = flushAutoOnWaitAbilities($state);
        }
    }
    return $state;
}

function apiReplayExport(array $body): array {
    $roomId = strtoupper(trim((string)($body['room_id'] ?? '')));
    $token = (string)($body['token'] ?? '');
    if ($roomId === '' || $token === '') {
        throw new Exception('room_id and token required');
    }
    $state = loadGame($roomId);
    if (!$state) {
        throw new Exception('Room not found');
    }
    $playerId = getPlayerIdByToken($state, $token);
    if (!$playerId) {
        throw new Exception('Invalid player token');
    }
    assertReplayExportAllowed($body, $state);
    if (($state['mode'] ?? '') === 'replay_view') {
        throw new Exception('Cannot export from a replay viewer room');
    }
    $actions = $state['action_log'] ?? [];
    if (count($actions) === 0) {
        throw new Exception('No recorded actions yet — play at least one move after this update');
    }
    return [
        'ok'     => true,
        'replay' => buildReplayExportPayload($state, $playerId),
    ];
}

function apiReplayStart(array $body): array {
    $replay = $body['replay'] ?? null;
    if (!is_array($replay)) {
        throw new Exception('replay object required');
    }
    validateReplayFile($replay);

    $baseline = $replay['baseline'];
    $actions = $replay['actions'] ?? [];
    $saverPid = $replay['meta']['saver_player_id'] ?? 'p1';
    $cpuDiff = in_array($replay['meta']['cpu_difficulty'] ?? '', ['easy', 'normal', 'hard', 'expert'], true)
        ? $replay['meta']['cpu_difficulty'] : 'normal';

    $roomId = strtoupper(substr(md5(uniqid('rpl', true)), 0, 6));
    $p1Token = generateToken();
    $p2Token = generateToken();

    $state = replayRestoreFromBaseline($baseline, $roomId, $p1Token, $p2Token);
    $state['cpu_difficulty'] = $cpuDiff;
    $state['mode'] = 'replay_view';
    $state['cpu_solo'] = true;
    $state['replay'] = [
        'saver_pid' => $saverPid,
        'actions'   => $actions,
        'baseline'  => $baseline,
        'step'      => 0,
        'handoff'   => false,
    ];
    $state = addLog($state, 'Replay loaded — ' . count($actions) . ' action(s). Use replay controls to play or seek.');
    $state['seq']++;
    $state = replaySanitizeViewingState($state, $actions, 0);

    saveGame($roomId, $state);

    $humanToken = ($saverPid === 'p1') ? $p1Token : $p2Token;
    $cpuToken = ($saverPid === 'p1') ? $p2Token : $p1Token;

    return [
        'ok'           => true,
        'room_id'      => $roomId,
        'player_token' => $humanToken,
        'cpu_token'    => $cpuToken,
        'player_id'    => $saverPid,
        'total_steps'  => count($actions),
        'saver_name'   => $replay['meta']['saver_name'] ?? $saverPid,
    ];
}

function apiReplayGoto(array $body): array {
    $roomId = strtoupper(trim((string)($body['room_id'] ?? '')));
    $token = (string)($body['token'] ?? '');
    $step = intval($body['step'] ?? -1);
    $wantsHandoff = !empty($body['handoff']);
    if ($roomId === '' || $token === '') {
        throw new Exception('room_id and token required');
    }
    if ($step < 0) {
        throw new Exception('step required');
    }

    return withLock($roomId, function () use ($roomId, $token, $step, $wantsHandoff) {
        $state = loadGame($roomId);
        if (!$state) {
            throw new Exception('Room not found');
        }
        $playerId = getPlayerIdByToken($state, $token);
        if (!$playerId) {
            throw new Exception('Invalid player token');
        }
        $replay = $state['replay'] ?? null;
        if (!$replay || ($state['mode'] ?? '') !== 'replay_view') {
            throw new Exception('Not in replay mode');
        }
        if (($replay['saver_pid'] ?? '') !== $playerId) {
            throw new Exception('Only the saver perspective can control replay');
        }

        $actions = is_array($replay['actions'] ?? null) ? $replay['actions'] : [];
        $maxStep = count($actions);
        $step = max(0, min($step, $maxStep));
        $baseline = $replay['baseline'] ?? null;
        if (!$baseline) {
            throw new Exception('Replay baseline missing from room');
        }

        $p1Token = $state['players']['p1']['token'] ?? generateToken();
        $p2Token = $state['players']['p2']['token'] ?? generateToken();
        $cpuDiff = $state['cpu_difficulty'] ?? 'normal';

        $newState = replayRestoreFromBaseline($baseline, $roomId, $p1Token, $p2Token);
        $newState['cpu_difficulty'] = $cpuDiff;
        $newState = replayApplyActionsThrough($newState, $actions, $step);
        $newState = replaySanitizeViewingState($newState, $actions, $step);

        $handoff = $wantsHandoff && $step >= $maxStep;
        if ($handoff) {
            unset($newState['replay']);
            $newState['mode'] = null;
            $newState['replay_handoff'] = true;
            $newState['cpu_solo'] = true;
            $newState = addLog(
                $newState,
                'Replay complete — you control ' . ($newState['players'][$playerId]['name'] ?? $playerId)
                . '. CPU plays the opponent.'
            );
            $newState['seq']++;
        } else {
            $newState['mode'] = 'replay_view';
            $newState['cpu_solo'] = true;
            $newState['replay'] = [
                'saver_pid' => $replay['saver_pid'],
                'actions'   => $actions,
                'baseline'  => $baseline,
                'step'      => $step,
                'handoff'   => false,
            ];
        }

        saveGame($roomId, $newState);

        return [
            'ok'      => true,
            'step'    => $step,
            'total'   => $maxStep,
            'handoff' => $handoff,
            'seq'     => $newState['seq'],
        ];
    }, REPLAY_LOCK_TIMEOUT);
}

function enrichReplayFieldsForClient(array $filtered, array $state): array {
    if (!empty($state['replay']) && is_array($state['replay'])) {
        $filtered['replay'] = [
            'step'    => intval($state['replay']['step'] ?? 0),
            'total'   => count($state['replay']['actions'] ?? []),
            'handoff' => !empty($state['replay']['handoff']),
            'saver_pid' => $state['replay']['saver_pid'] ?? null,
        ];
    }
    if (!empty($state['replay_handoff'])) {
        $filtered['replay_handoff'] = true;
    }
    return $filtered;
}
