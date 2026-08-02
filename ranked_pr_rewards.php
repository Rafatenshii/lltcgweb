<?php
/**
 * Ranked PvP win PR pack rewards (5 packs/day JST, 3 cards each = 15/day).
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/booster.php';
require_once __DIR__ . '/deck_validate.php';
require_once __DIR__ . '/missions.php';

const TCG_RANKED_PR_DAILY_LIMIT = 5;
const TCG_RANKED_PR_PACK_SIZE = 3;

function tcgRankedPrDailyAllowance(string $discordId): array {
    $db = tcgDb();
    $today = tcgTodayJst();
    $stmt = $db->prepare('SELECT ranked_pr_date, ranked_pr_today FROM tcg_daily_state WHERE discord_id = ?');
    $stmt->execute([$discordId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        tcgEnsureUser($discordId);
        $row = ['ranked_pr_date' => null, 'ranked_pr_today' => 0];
    }
    $awarded = (($row['ranked_pr_date'] ?? '') === $today) ? max(0, intval($row['ranked_pr_today'] ?? 0)) : 0;
    return [
        'date_jst' => $today,
        'limit' => TCG_RANKED_PR_DAILY_LIMIT,
        'pack_size' => TCG_RANKED_PR_PACK_SIZE,
        'awarded_today' => $awarded,
        'remaining' => max(0, TCG_RANKED_PR_DAILY_LIMIT - $awarded),
        'cards_remaining' => max(0, TCG_RANKED_PR_DAILY_LIMIT - $awarded) * TCG_RANKED_PR_PACK_SIZE,
    ];
}

function tcgRecordRankedPrReward(string $discordId): void {
    $allow = tcgRankedPrDailyAllowance($discordId);
    if ($allow['remaining'] <= 0) {
        throw new Exception('Ranked PR reward daily cap reached');
    }
    $db = tcgDb();
    $today = tcgTodayJst();
    if ($allow['awarded_today'] === 0) {
        $db->prepare('UPDATE tcg_daily_state SET ranked_pr_date = ?, ranked_pr_today = 1 WHERE discord_id = ?')
            ->execute([$today, $discordId]);
    } else {
        $db->prepare('UPDATE tcg_daily_state SET ranked_pr_today = ranked_pr_today + 1 WHERE discord_id = ?')
            ->execute([$discordId]);
    }
}

function tcgRollSinglePrCard(array $cardsData): ?string {
    $box = tcgPrCardPoolBox();
    $pools = tcgBuildBoxPools($cardsData, $box);
    $prPool = $pools['PR'] ?? [];
    $prPlusPool = $pools['PR+'] ?? [];
    if (empty($prPool) && empty($prPlusPool)) {
        return null;
    }
    $fallback = static function () use ($prPool, $prPlusPool): ?string {
        return tcgPickFromPool($prPool) ?: tcgPickFromPool($prPlusPool);
    };
    return tcgPickWeightedRarity($pools, tcgPrFifthSlotRarityWeights()) ?: $fallback();
}

/**
 * Roll and grant a PR pack (3 cards) without consuming the ranked daily PR cap.
 * Used by login bonuses and any non-ranked PR grants.
 *
 * @return array<string, mixed>
 */
function tcgGrantPrPackCards(string $discordId): array {
    if (!file_exists(CARDS_FILE)) {
        throw new Exception('Card pool unavailable');
    }
    $cardsData = json_decode((string)file_get_contents(CARDS_FILE), true) ?: [];
    $cardNos = [];
    for ($i = 0; $i < TCG_RANKED_PR_PACK_SIZE; $i++) {
        $cardNo = tcgRollSinglePrCard($cardsData);
        if ($cardNo) {
            $cardNos[] = $cardNo;
        }
    }
    if ($cardNos === []) {
        throw new Exception('PR card pool empty');
    }

    $cardMap = tcgBuildCardMap($cardsData);
    $gemResult = tcgApplyBoosterPullWithGems($discordId, $cardNos, $cardMap);
    $cards = [];
    foreach ($gemResult['pulls'] ?? [] as $pull) {
        $no = (string)($pull['card_no'] ?? '');
        if ($no === '') {
            continue;
        }
        $base = $cardMap[$no] ?? ['card_no' => $no];
        $cards[] = array_merge($base, [
            'converted' => !empty($pull['converted']),
            'star_gems' => intval($pull['star_gems'] ?? 0),
        ]);
    }
    if ($cards === []) {
        foreach ($cardNos as $no) {
            $base = $cardMap[$no] ?? ['card_no' => $no];
            $cards[] = array_merge($base, ['converted' => false, 'star_gems' => 0]);
        }
    }
    $first = $cards[0];

    return [
        'pack_size' => count($cards),
        'cards' => $cards,
        'card_no' => $first['card_no'] ?? null,
        'card' => $first,
        'converted' => !empty($first['converted']),
        'star_gems_earned' => intval($gemResult['star_gems_earned'] ?? 0),
        'star_gems' => intval($gemResult['star_gems'] ?? 0),
    ];
}

/**
 * @return array<string, mixed>
 */
function tcgGrantRankedWinPrReward(string $discordId): array {
    $allow = tcgRankedPrDailyAllowance($discordId);
    if ($allow['remaining'] <= 0) {
        return [
            'skipped' => true,
            'reason' => 'daily_cap',
            'daily' => $allow,
        ];
    }

    try {
        $pack = tcgGrantPrPackCards($discordId);
    } catch (Throwable $e) {
        return [
            'skipped' => true,
            'reason' => 'empty_pool',
            'daily' => $allow,
        ];
    }

    tcgRecordRankedPrReward($discordId);

    return array_merge($pack, [
        'daily' => tcgRankedPrDailyAllowance($discordId),
    ]);
}

function tcgRankedPrRewardForPlayer(array $state, string $playerId): ?array {
    if (($state['mode'] ?? '') !== 'ranked' || ($state['status'] ?? '') !== 'finished') {
        return null;
    }
    $entry = $state['ranked']['pr_reward'] ?? null;
    if (!is_array($entry) || ($entry['player_id'] ?? '') !== $playerId) {
        return null;
    }
    $reward = $entry['reward'] ?? null;
    return is_array($reward) ? $reward : null;
}

function tcgApplyRankedPrRewardOnFinish(array &$state): void {
    if (($state['mode'] ?? '') !== 'ranked' || ($state['status'] ?? '') !== 'finished') {
        return;
    }
    $ranked = is_array($state['ranked'] ?? null) ? $state['ranked'] : [];
    if (!empty($ranked['pr_reward_applied'])) {
        return;
    }
    $winnerPid = $state['winner'] ?? null;
    if (!is_string($winnerPid) || !in_array($winnerPid, ['p1', 'p2'], true)) {
        return;
    }
    // Prefer ranked matchmaking IDs so a bad/missing seat discord_id cannot grant to the loser.
    $rankedKey = $winnerPid . '_discord_id';
    $discordId = !empty($ranked[$rankedKey])
        ? (string)$ranked[$rankedKey]
        : tcgPlayerDiscordId($state, $winnerPid);
    if (!$discordId) {
        return;
    }

    $reward = tcgGrantRankedWinPrReward($discordId);
    if (!isset($state['ranked']) || !is_array($state['ranked'])) {
        $state['ranked'] = [];
    }
    $state['ranked']['pr_reward_applied'] = true;
    $state['ranked']['pr_reward'] = [
        'player_id' => $winnerPid,
        'reward' => $reward,
    ];
}
