<?php

declare(strict_types=1);

namespace LLTCG\Game;

final class EffectRegistry
{
    /**
     * Continuous / inline ability types matched via if-checks in effects.php
     * (or ActivateAbility) rather than AbilityResolverSwitch case labels.
     *
     * @var list<string>
     */
    private const INLINE_ABILITY_TYPES = [
        'blade_if_either_stage_cost_min',
        'leave_stage_add_from_wr',
    ];

    /**
     * Types owned by EffectHandlers (sole dispatcher path when registered).
     *
     * @var list<string>
     */
    private const HANDLER_OWNED_TYPES = [
        'draw',
        'draw_if_success_lives',
        'draw_if_bonus_hearts_on_stage',
        'draw_if_wr_min',
        'grant_hearts',
        'grant_live_score_if_success',
        'blade_bonus',
        'blade_per_hand_cards',
    ];

    /**
     * Ability IR: required keys per high-frequency type (lint).
     * Empty list = type must exist; only `type` (+ usually trigger) required.
     *
     * @var array<string, list<string>>
     */
    private const TYPE_PARAM_SCHEMA = [
        'draw' => [],
        'draw_if_success_lives' => [],
        'draw_if_bonus_hearts_on_stage' => [],
        'draw_if_wr_min' => ['min_wr'],
        'add_from_wr_max_cost' => ['count'],
        'blade_bonus' => [],
        'grant_hearts' => [],
        'grant_named_members_blade' => [], // names | name | member_names variants
        'reduce_hearts_by_color' => [],
        'wait_self_draw_discard' => [],
    ];

    /** @return list<string> */
    public static function handlerOwnedTypes(): array
    {
        return self::HANDLER_OWNED_TYPES;
    }

    public static function hasHandler(string $type): bool
    {
        return in_array($type, self::HANDLER_OWNED_TYPES, true);
    }

    /**
     * @param array<string,mixed> $state
     * @param array<string,mixed> $source
     * @param array<string,mixed> $ab
     * @param array<string,mixed> $ctx
     * @param array<string,mixed> $p
     * @return array<string,mixed>
     */
    public static function dispatch(
        array $state,
        string $pid,
        array $source,
        array $ab,
        array $ctx,
        string $type,
        array &$p,
        string $name
    ): array {
        if (!class_exists(EffectHandlers::class)) {
            $root = dirname(__DIR__, 2);
            require_once $root . '/src/Game/EffectHandlers.php';
        }
        $out = EffectHandlers::tryHandle($state, $pid, $source, $ab, $ctx, $type, $p, $name);
        if ($out === null) {
            throw new \RuntimeException("EffectRegistry: no handler body for type '$type'");
        }
        return $out;
    }

    /** @return array<string, list<string>> */
    public static function typeParamSchema(): array
    {
        return self::TYPE_PARAM_SCHEMA;
    }

    /** @return list<string> */
    public static function knownAbilityTypes(): array
    {
        static $types = null;
        if ($types !== null) {
            return $types;
        }
        $root = dirname(__DIR__, 2);
        $all = [];

        $paths = array_merge(
            glob($root . '/src/Game/AbilityResolverSwitch*.php') ?: [],
            glob($root . '/src/Game/*.php') ?: [],
            glob($root . '/*_effects.php') ?: [],
            is_file($root . '/effects.php') ? [$root . '/effects.php'] : [],
            is_file($root . '/src/Game/ActivateAbility.php') ? [$root . '/src/Game/ActivateAbility.php'] : [],
            is_file($root . '/src/Game/EffectHandlers.php') ? [$root . '/src/Game/EffectHandlers.php'] : []
        );
        $paths = array_values(array_unique($paths));

        foreach ($paths as $path) {
            $src = (string) file_get_contents($path);
            if (preg_match_all("/case '([a-z0-9_]+)':/", $src, $m)) {
                foreach ($m[1] as $type) {
                    $all[$type] = true;
                }
            }
            if (preg_match_all(
                '/function\s+\w+EffectTypes\s*\(\s*\)\s*:\s*array\s*\{.*?return\s*\[(.*?)\];/s',
                $src,
                $blocks
            )) {
                foreach ($blocks[1] as $body) {
                    if (preg_match_all("/'([a-z0-9_]+)'/", $body, $tm)) {
                        foreach ($tm[1] as $type) {
                            $all[$type] = true;
                        }
                    }
                }
            }
            // niji / set modules: $types = [ 'foo', 'bar', … ]; return in_array(...)
            if (preg_match_all('/\$types\s*=\s*\[(.*?)\];/s', $src, $tb)) {
                foreach ($tb[1] as $body) {
                    if (preg_match_all("/'([a-z0-9_]+)'/", $body, $tm)) {
                        foreach ($tm[1] as $type) {
                            if (strlen($type) > 2) {
                                $all[$type] = true;
                            }
                        }
                    }
                }
            }
            if (preg_match_all(
                "/\\\$ab\['type'\]\s*\?\?\s*''\)\s*===\s*'([a-z0-9_]+)'/",
                $src,
                $im
            )) {
                foreach ($im[1] as $type) {
                    $all[$type] = true;
                }
            }
            if (preg_match_all("/\\\$type\s*===\s*'([a-z0-9_]+)'/", $src, $tm)) {
                foreach ($tm[1] as $type) {
                    $all[$type] = true;
                }
            }
            if (preg_match_all("/\\\$type\s*!==\s*'([a-z0-9_]+)'/", $src, $tm)) {
                foreach ($tm[1] as $type) {
                    $all[$type] = true;
                }
            }
            if (preg_match_all(
                "/\\\$ab\['type'\]\s*\?\?\s*''\)\s*(?:===|!==)\s*'([a-z0-9_]+)'/",
                $src,
                $im2
            )) {
                foreach ($im2[1] as $type) {
                    $all[$type] = true;
                }
            }
            if (preg_match_all("/'([a-z0-9_]+)'\s*=>\s*self::/", $src, $hm)) {
                foreach ($hm[1] as $type) {
                    $all[$type] = true;
                }
            }
        }

        foreach (self::INLINE_ABILITY_TYPES as $type) {
            $all[$type] = true;
        }
        foreach (self::HANDLER_OWNED_TYPES as $type) {
            $all[$type] = true;
        }

        $types = array_keys($all);
        sort($types);
        return $types;
    }

    public static function isKnownType(string $type): bool
    {
        return in_array($type, self::knownAbilityTypes(), true);
    }
}
