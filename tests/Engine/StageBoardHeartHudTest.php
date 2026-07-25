<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * Stage Board HUD must show current stage hearts in Main — not last Live's
 * performance snapshot (which is kept only for spectacle playback).
 */
final class StageBoardHeartHudTest extends TestCase
{
    public function testMainPhaseUsesLiveStageHeartsNotPerfSnapshot(): void
    {
        $state = [
            'status' => 'playing',
            'phase' => 'main_first',
            'seq' => 20,
            'turn' => 3,
            'first_player' => 'p1',
            'active_player' => 'p1',
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'token' => 'tok1',
                    'hand' => [],
                    'waiting_room' => [],
                    'stage' => [
                        'left' => null,
                        'center' => [
                            'instance_id' => 'ginko',
                            'name_en' => 'Ginko Momose',
                            'card_type' => 'メンバー',
                            'hearts' => [['color' => 'green', 'count' => 3]],
                        ],
                        'right' => [
                            'instance_id' => 'kaho',
                            'name_en' => 'Kaho Hinoshita',
                            'card_type' => 'メンバー',
                            'hearts' => [['color' => 'pink', 'count' => 2]],
                        ],
                    ],
                    'energy_zone' => [],
                    'main_deck' => [],
                    'live_zone' => [],
                    'success_lives' => [],
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'P2',
                    'token' => 'tok2',
                    'hand' => [],
                    'waiting_room' => [],
                    'stage' => [
                        'left' => null,
                        'center' => [
                            'instance_id' => 'opp',
                            'name_en' => 'Opp',
                            'card_type' => 'メンバー',
                            'hearts' => [['color' => 'pink', 'count' => 1]],
                        ],
                        'right' => null,
                    ],
                    'energy_zone' => [],
                    'main_deck' => [],
                    'live_zone' => [],
                    'success_lives' => [],
                ],
            ],
            // Stale last-Live totals (would wrongly show yellow 3 / pink 9 on the HUD).
            '_stage_hearts_snapshot' => [
                'p1' => [
                    ['color' => 'yellow', 'count' => 3],
                    ['color' => 'pink', 'count' => 9],
                ],
                'p2' => [
                    ['color' => 'pink', 'count' => 9],
                    ['color' => 'green', 'count' => 1],
                ],
            ],
            'live_modifiers' => [
                'p1' => ['bonus_hearts' => [], 'score_bonus' => 0, 'blade_bonus' => 0],
                'p2' => ['bonus_hearts' => [], 'score_bonus' => 0, 'blade_bonus' => 0],
            ],
        ];

        $filtered = \filterStateForPlayer($state, 'tok1');
        $mine = $filtered['stage_board']['mine']['stage_hearts'] ?? [];
        $byColor = [];
        foreach ($mine as $row) {
            $byColor[$row['color']] = $row['count'];
        }
        $this->assertSame(3, $byColor['green'] ?? 0, 'Main Phase must show current green hearts');
        $this->assertSame(2, $byColor['pink'] ?? 0, 'Main Phase must show current pink hearts');
        $this->assertArrayNotHasKey('yellow', $byColor, 'Must not show last Live yellow total');

        $perf = $filtered['stage_board']['mine']['perf_stage_hearts'] ?? [];
        $perfBy = [];
        foreach ($perf as $row) {
            $perfBy[$row['color']] = $row['count'];
        }
        $this->assertSame(3, $perfBy['yellow'] ?? 0, 'Spectacle snapshot still available');
        $this->assertSame(9, $perfBy['pink'] ?? 0);
    }
}
