<?php

declare(strict_types=1);

namespace LLTCG\Game;

/**
 * Shared numeric / no-prompt handlers registered on EffectRegistry.
 * High-frequency types migrate here; switch modules call through or fall back.
 */
final class EffectHandlers
{
    /**
     * @param array<string,mixed> $state
     * @param array<string,mixed> $source
     * @param array<string,mixed> $ab
     * @param array<string,mixed> $ctx
     * @param array<string,mixed> $p
     * @return array<string,mixed>|null null = not handled
     */
    public static function tryHandle(
        array $state,
        string $pid,
        array $source,
        array $ab,
        array $ctx,
        string $type,
        array &$p,
        string $name
    ): ?array {
        return match ($type) {
            'draw' => self::draw($state, $pid, $ab, $name),
            'draw_if_success_lives' => self::drawIfSuccessLives($state, $pid, $ab, $p, $name),
            'draw_if_bonus_hearts_on_stage' => self::drawIfBonusHearts($state, $pid, $ab, $p, $name),
            'draw_if_wr_min' => self::drawIfWrMin($state, $pid, $ab, $p, $name),
            'grant_hearts' => self::grantHearts($state, $pid, $ab, $name),
            'grant_live_score_if_success' => self::grantLiveScoreIfSuccess($state, $pid, $ab, $p, $name),
            'blade_bonus' => self::bladeBonus($state, $pid, $source, $ab, $p, $name),
            'blade_per_hand_cards' => self::bladePerHandCards($state, $pid, $source, $ab, $ctx, $p, $name),
            default => null,
        };
    }

    /** @param array<string,mixed> $state @param array<string,mixed> $ab */
    private static function draw(array $state, string $pid, array $ab, string $name): array
    {
        $n = max(1, intval($ab['draw'] ?? $ab['count'] ?? 1));
        $drawn = drawCardsForPlayer($state, $pid, $n);
        return addLog($state, $state['players'][$pid]['name'] . " — [$name] drew $drawn.");
    }

    /** @param array<string,mixed> $state @param array<string,mixed> $ab @param array<string,mixed> $p */
    private static function drawIfSuccessLives(array $state, string $pid, array $ab, array $p, string $name): array
    {
        $succ = $p['success_lives'] ?? [];
        if (!empty($ab['group'])) {
            $succ = array_values(array_filter(
                $succ,
                static fn($c) => ($c['group'] ?? '') === ($ab['group'] ?? '')
            ));
        }
        if (!empty($succ)) {
            $drawn = drawCardsForPlayer($state, $pid, intval($ab['draw'] ?? 1));
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] drew $drawn (Success Live area not empty).");
        }
        return $state;
    }

    /** @param array<string,mixed> $state @param array<string,mixed> $ab @param array<string,mixed> $p */
    private static function drawIfBonusHearts(array $state, string $pid, array $ab, array $p, string $name): array
    {
        if (function_exists('stageHasMemberWithExtraHearts') && stageHasMemberWithExtraHearts($p)) {
            $drawn = drawCardsForPlayer($state, $pid, intval($ab['draw'] ?? 1));
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] drew $drawn (Member with bonus hearts on Stage).");
        }
        return $state;
    }

    /** @param array<string,mixed> $state @param array<string,mixed> $ab @param array<string,mixed> $p */
    private static function drawIfWrMin(array $state, string $pid, array $ab, array $p, string $name): array
    {
        if (count($p['waiting_room'] ?? []) >= intval($ab['min_wr'] ?? 10)) {
            $drawn = drawCardsForPlayer($state, $pid, intval($ab['draw'] ?? 1));
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] drew $drawn (Waiting Room has " . intval($ab['min_wr'] ?? 10) . "+ cards).");
        }
        return $state;
    }

    /** @param array<string,mixed> $state @param array<string,mixed> $ab */
    private static function grantHearts(array $state, string $pid, array $ab, string $name): array
    {
        if (!empty($ab['hearts'])) {
            addBonusHeartsToModifier($state, $pid, $ab['hearts']);
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] gained bonus heart(s) until this Live ends.");
        }
        return $state;
    }

    /** @param array<string,mixed> $state @param array<string,mixed> $ab @param array<string,mixed> $p */
    private static function grantLiveScoreIfSuccess(
        array $state,
        string $pid,
        array $ab,
        array $p,
        string $name
    ): array {
        $succCount = count($p['success_lives'] ?? []);
        $scoreSum = sumSuccessLiveScores($p);
        if ($succCount >= intval($ab['min_success_count'] ?? 1)
            && $scoreSum <= intval($ab['max_success_score_sum'] ?? 1)) {
            $state = applyModifierEffect($state, $pid, [
                'type'   => 'live_score_bonus',
                'amount' => intval($ab['amount'] ?? 1),
            ]);
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] Live total score +' . intval($ab['amount'] ?? 1) . ' until Live ends.');
        }
        return $state;
    }

    /**
     * @param array<string,mixed> $state
     * @param array<string,mixed> $source
     * @param array<string,mixed> $ab
     * @param array<string,mixed> $p
     */
    private static function bladeBonus(
        array $state,
        string $pid,
        array $source,
        array $ab,
        array &$p,
        string $name
    ): array {
        $bladeAmt = intval($ab['amount'] ?? 1);
        $srcSlot = findMemberSlot($p, $source['instance_id'] ?? '');
        if ($srcSlot !== '' && !empty($p['stage'][$srcSlot])) {
            $p['stage'][$srcSlot]['live_blade_bonus'] =
                intval($p['stage'][$srcSlot]['live_blade_bonus'] ?? 0) + $bladeAmt;
        } else {
            $state = applyModifierEffect($state, $pid, $ab);
        }
        return addLog($state, $state['players'][$pid]['name'] .
            ' — [' . $name . '] gains +' . $bladeAmt . ' Blade until this Live ends.');
    }

    /**
     * @param array<string,mixed> $state
     * @param array<string,mixed> $source
     * @param array<string,mixed> $ab
     * @param array<string,mixed> $ctx
     * @param array<string,mixed> $p
     */
    private static function bladePerHandCards(
        array $state,
        string $pid,
        array $source,
        array $ab,
        array $ctx,
        array &$p,
        string $name
    ): array {
        if (($ab['trigger'] ?? '') === 'live_start' || ($ctx['phase'] ?? '') === 'live_start') {
            $handCount = count($p['hand'] ?? []);
            $div = max(1, intval($ab['per_cards'] ?? 2));
            $bonus = intdiv($handCount, $div) * intval($ab['amount'] ?? 1);
            if ($bonus > 0) {
                $srcSlot = findMemberSlot($p, $source['instance_id'] ?? '');
                if ($srcSlot !== '' && !empty($p['stage'][$srcSlot])) {
                    $p['stage'][$srcSlot]['live_blade_bonus'] =
                        intval($p['stage'][$srcSlot]['live_blade_bonus'] ?? 0) + $bonus;
                } else {
                    $state = applyModifierEffect($state, $pid, [
                        'type'   => 'blade_bonus',
                        'amount' => $bonus,
                    ]);
                }
            }
            return addLog($state, $state['players'][$pid]['name'] .
                " — [$name] gains +$bonus Blade until Live ends (+1 per " .
                intval($ab['per_cards'] ?? 2) . " cards in hand at Live Start, hand was $handCount).");
        }
        $state = initLiveModifiers($state);
        $state['live_modifiers'][$pid]['blade_per_hand_divisor'] = max(1, intval($ab['per_cards'] ?? 2));
        $state['live_modifiers'][$pid]['blade_per_hand_amount'] = intval($ab['amount'] ?? 1);
        return addLog($state, $state['players'][$pid]['name'] .
            ' — [' . $name . '] +1 Blade per ' . intval($ab['per_cards'] ?? 2) . ' cards in hand until Live ends.');
    }
}
