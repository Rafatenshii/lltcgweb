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
            glob($root . '/*_effects.php') ?: [],
            is_file($root . '/effects.php') ? [$root . '/effects.php'] : [],
            is_file($root . '/src/Game/ActivateAbility.php') ? [$root . '/src/Game/ActivateAbility.php'] : []
        );

        foreach ($paths as $path) {
            $src = (string) file_get_contents($path);
            // Switch / match case labels.
            if (preg_match_all("/case '([a-z0-9_]+)':/", $src, $m)) {
                foreach ($m[1] as $type) {
                    $all[$type] = true;
                }
            }
            // Set-module *EffectTypes() return lists.
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
            // Inline continuous / ability type string compares: ($ab['type'] ?? '') === '…'
            if (preg_match_all(
                "/\\\$ab\['type'\]\s*\?\?\s*''\)\s*===\s*'([a-z0-9_]+)'/",
                $src,
                $im
            )) {
                foreach ($im[1] as $type) {
                    $all[$type] = true;
                }
            }
        }

        foreach (self::INLINE_ABILITY_TYPES as $type) {
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
