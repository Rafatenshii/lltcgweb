<?php
/**
 * Daily login bonuses (JST): 10-step cycle, advance only on login days.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/seals.php';
require_once __DIR__ . '/ranked_pr_rewards.php';

const TCG_LOGIN_BONUS_STEPS = 10;

/**
 * Ordered rewards. Index 0 = day 1.
 * Seal "SR" maps to sticker-shop R seals (no separate SR currency).
 *
 * @return list<array{type:string,amount?:int,tier?:string,label:string}>
 */
function tcgLoginBonusDefs(): array {
    return [
        ['type' => 'gems', 'amount' => 100, 'label' => 'gems'],
        ['type' => 'seals', 'tier' => 'N', 'amount' => 3, 'label' => 'seals_n'],
        ['type' => 'gems', 'amount' => 100, 'label' => 'gems'],
        ['type' => 'seals', 'tier' => 'R', 'amount' => 1, 'label' => 'seals_sr'],
        ['type' => 'pr_pack', 'amount' => 1, 'label' => 'pr_pack'],
        ['type' => 'gems', 'amount' => 100, 'label' => 'gems'],
        ['type' => 'seals', 'tier' => 'N', 'amount' => 3, 'label' => 'seals_n'],
        ['type' => 'gems', 'amount' => 200, 'label' => 'gems'],
        ['type' => 'seals', 'tier' => 'R', 'amount' => 1, 'label' => 'seals_sr'],
        ['type' => 'pr_pack', 'amount' => 1, 'label' => 'pr_pack'],
    ];
}

/**
 * @return array{step:int,last_date:?string}
 */
function tcgLoginBonusRow(string $discordId): array {
    $db = tcgDb();
    $stmt = $db->prepare('SELECT login_bonus_step, login_bonus_last_date FROM tcg_daily_state WHERE discord_id = ?');
    $stmt->execute([$discordId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        tcgEnsureUser($discordId);
        $row = ['login_bonus_step' => 0, 'login_bonus_last_date' => null];
    }
    $step = max(0, intval($row['login_bonus_step'] ?? 0)) % TCG_LOGIN_BONUS_STEPS;
    return [
        'step' => $step,
        'last_date' => isset($row['login_bonus_last_date']) && $row['login_bonus_last_date'] !== ''
            ? (string)$row['login_bonus_last_date']
            : null,
    ];
}

/**
 * @param array{type:string,amount?:int,tier?:string,label:string} $def
 * @return array<string,mixed>
 */
function tcgLoginBonusGrant(string $discordId, array $def): array {
    $type = (string)($def['type'] ?? '');
    $amount = max(1, intval($def['amount'] ?? 1));
    if ($type === 'gems') {
        $gems = tcgAddStarGems($discordId, $amount);
        return [
            'type' => 'gems',
            'amount' => $amount,
            'label' => $def['label'] ?? 'gems',
            'star_gems' => $gems,
        ];
    }
    if ($type === 'seals') {
        $tier = strtoupper((string)($def['tier'] ?? 'N'));
        $seals = tcgAddSeals($discordId, $tier, $amount);
        return [
            'type' => 'seals',
            'tier' => $tier,
            'amount' => $amount,
            'label' => $def['label'] ?? ('seals_' . strtolower($tier)),
            'seals' => $seals,
        ];
    }
    if ($type === 'pr_pack') {
        $pack = tcgGrantPrPackCards($discordId);
        return array_merge($pack, [
            'type' => 'pr_pack',
            'amount' => 1,
            'label' => 'pr_pack',
        ]);
    }
    throw new Exception('Unknown login bonus reward');
}

/**
 * Calendar cells for UI. $nextStep = index of the next unclaimed reward in the cycle.
 * Days with index < nextStep are completed this cycle (after wrap, none are).
 *
 * @return list<array<string,mixed>>
 */
function tcgLoginBonusDaysPayload(int $nextStep, ?int $justClaimedStep = null): array {
    $defs = tcgLoginBonusDefs();
    $days = [];
    // After claiming day 10, mark the whole cycle claimed (next cycle starts tomorrow).
    $wrappedClaim = ($justClaimedStep !== null
        && $nextStep === 0
        && $justClaimedStep === TCG_LOGIN_BONUS_STEPS - 1);
    foreach ($defs as $i => $def) {
        $status = 'locked';
        if ($justClaimedStep !== null && $i === $justClaimedStep) {
            $status = 'just_claimed';
        } elseif ($wrappedClaim) {
            $status = 'claimed';
        } elseif ($i < $nextStep) {
            $status = 'claimed';
        } elseif ($i === $nextStep) {
            $status = 'next';
        }
        $days[] = [
            'index' => $i,
            'day' => $i + 1,
            'type' => $def['type'],
            'amount' => intval($def['amount'] ?? 1),
            'tier' => $def['tier'] ?? null,
            'label' => $def['label'],
            'status' => $status,
        ];
    }
    return $days;
}

/**
 * @return array<string,mixed>
 */
function tcgLoginBonusStatus(string $discordId): array {
    $today = tcgTodayJst();
    $row = tcgLoginBonusRow($discordId);
    $claimedToday = ($row['last_date'] === $today);
    return [
        'success' => true,
        'date_jst' => $today,
        'claimed_today' => $claimedToday,
        'just_claimed' => false,
        'next_step' => $row['step'],
        'cycle_length' => TCG_LOGIN_BONUS_STEPS,
        'days' => tcgLoginBonusDaysPayload($row['step']),
        'star_gems' => tcgGetStarGems($discordId),
        'seals' => tcgSealBalances($discordId),
    ];
}

/**
 * Claim today's login bonus if not yet claimed (JST). Missing days do not skip steps.
 *
 * @return array<string,mixed>
 */
function tcgLoginBonusClaim(string $discordId): array {
    $today = tcgTodayJst();
    $row = tcgLoginBonusRow($discordId);
    if ($row['last_date'] === $today) {
        return array_merge(tcgLoginBonusStatus($discordId), [
            'just_claimed' => false,
            'reward' => null,
        ]);
    }

    $defs = tcgLoginBonusDefs();
    $step = $row['step'];
    $def = $defs[$step];
    $reward = tcgLoginBonusGrant($discordId, $def);
    $nextStep = ($step + 1) % TCG_LOGIN_BONUS_STEPS;

    $db = tcgDb();
    $db->prepare('UPDATE tcg_daily_state SET login_bonus_step = ?, login_bonus_last_date = ? WHERE discord_id = ?')
        ->execute([$nextStep, $today, $discordId]);

    return [
        'success' => true,
        'date_jst' => $today,
        'claimed_today' => true,
        'just_claimed' => true,
        'step_claimed' => $step,
        'next_step' => $nextStep,
        'cycle_length' => TCG_LOGIN_BONUS_STEPS,
        'days' => tcgLoginBonusDaysPayload($nextStep, $step),
        'reward' => $reward,
        'star_gems' => tcgGetStarGems($discordId),
        'seals' => tcgSealBalances($discordId),
    ];
}

function tcgApiLoginBonusStatus(array $body): array {
    $uid = tcgRequireAuthUser($body);
    tcgEnsureUser($uid, tcgAuthUserProfile($uid));
    return tcgLoginBonusStatus($uid);
}

function tcgApiLoginBonusClaim(array $body): array {
    $uid = tcgRequireAuthUser($body);
    tcgEnsureUser($uid, tcgAuthUserProfile($uid));
    return tcgLoginBonusClaim($uid);
}
