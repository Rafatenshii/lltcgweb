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
}
