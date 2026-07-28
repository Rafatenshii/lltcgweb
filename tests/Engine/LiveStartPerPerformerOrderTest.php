<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * Official 8.3: each performer resolves Live Start then Yell.
 * Second player's Live Start must not run before first player's Yell
 * (JP report: Karin Wait before first-player Yell).
 */
final class LiveStartPerPerformerOrderTest extends TestCase
{
    private function karin(): array
    {
        $data = json_decode((string) file_get_contents((string) constant('CARDS_FILE')), true);
        foreach ($data['cards'] ?? [] as $card) {
            if (($card['card_no'] ?? '') === 'PL!N-bp4-004-R＋') {
                $card['instance_id'] = 'karin';
                $card['active'] = true;
                return $card;
            }
        }
        $this->fail('Missing Karin PL!N-bp4-004-R＋');
    }

    private function cheapMember(string $id): array
    {
        return [
            'instance_id' => $id,
            'card_type' => 'メンバー',
            'card_type_en' => 'Member',
            'group' => "μ's",
            'name_en' => 'Cheap',
            'cost' => 3,
            'blade' => 1,
            'active' => true,
            'hearts' => [['color' => 'pink', 'count' => 1]],
        ];
    }

    private function dummyLive(string $id): array
    {
        return [
            'instance_id' => $id,
            'card_type' => 'ライブ',
            'card_type_en' => 'Live',
            'group' => "μ's",
            'name_en' => 'Dummy Live',
            'score' => 1,
            'required_hearts' => [['color' => 'any', 'count' => 1]],
            'abilities' => [],
        ];
    }

    public function testSecondPlayerLiveStartDoesNotRunBeforeFirstYell(): void
    {
        $karin = $this->karin();
        $state = [
            'room_id' => 'LSORDER',
            'status' => 'playing',
            'seq' => 1,
            'turn' => 3,
            'phase' => 'live_start_effects',
            'first_player' => 'p1',
            'active_player' => 'p1',
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'First',
                    'hand' => [],
                    'stage' => [
                        'left' => null,
                        'center' => $this->cheapMember('p1c'),
                        'right' => null,
                    ],
                    'waiting_room' => [],
                    'energy_zone' => [],
                    'energy_deck' => [],
                    'main_deck' => [
                        ['instance_id' => 'y1', 'card_type' => 'メンバー', 'group' => "μ's", 'hearts' => [['color' => 'pink', 'count' => 1]]],
                        ['instance_id' => 'y2', 'card_type' => 'メンバー', 'group' => "μ's"],
                    ],
                    'live_zone' => [$this->dummyLive('p1live')],
                    'success_lives' => [],
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'Second',
                    'hand' => [
                        ['instance_id' => 'd1', 'card_type' => 'メンバー', 'group' => 'Nijigasaki'],
                    ],
                    'stage' => [
                        'left' => null,
                        'center' => $karin,
                        'right' => null,
                    ],
                    'waiting_room' => [
                        [
                            'instance_id' => 'wr_niji',
                            'card_type' => 'メンバー',
                            'group' => 'Nijigasaki',
                            'name_en' => 'WR Niji',
                        ],
                    ],
                    'energy_zone' => [],
                    'energy_deck' => [],
                    'main_deck' => [
                        ['instance_id' => 'p2y1', 'card_type' => 'メンバー', 'group' => 'Nijigasaki'],
                    ],
                    'live_zone' => [$this->dummyLive('p2live')],
                    'success_lives' => [],
                ],
            ],
        ];

        $GLOBALS['TUT_PERF_MANUAL_PHASES'] = true;
        try {
            $state = \beginLiveStartEffectPhase($state, true, true);

            // Only first player's Live Starts run in the opening phase.
            $this->assertSame('p1', $state['_live_start_perf_pid'] ?? null);
            $this->assertTrue(!empty($state['_live_start_done']['p1']));
            $this->assertTrue(empty($state['_live_start_done']['p2']));

            // First player's center must still be Active (not Waited by Karin yet).
            $this->assertTrue(!empty($state['players']['p1']['stage']['center']['active']));
            $this->assertTrue(empty($state['players']['p1']['stage']['center']['waiting']));

            // Manual: run first Yell, then continue to second (which should open Karin LS).
            $state['phase'] = 'live_performance_first';
            $state = \resolvePerformancePhase($state, 'p1', false);
            $this->assertNotEmpty($state['players']['p1']['yell_cards'] ?? []);

            $state = \continuePerformanceYellPhase($state, 'p1');
            // Second Live Starts should now be pending or resolving (Karin Wait prompt).
            $this->assertSame('p2', $state['_live_start_perf_pid'] ?? null);
            $promptType = $state['pending_prompt']['type'] ?? '';
            $this->assertTrue(
                $promptType !== '' || !empty($state['_live_start_done']['p2']),
                'Second performer Live Start should start after first Yell'
            );
            // Still before second Yell completes — first member may now be Wait-targeted.
            if ($promptType === 'wait_opponent_stage_max_cost'
                || $promptType === 'pick_stage_member'
                || str_contains($promptType, 'wait')) {
                $this->assertSame('p2', $state['pending_prompt']['owner'] ?? $state['pending_prompt']['responder'] ?? null);
            }
        } finally {
            unset($GLOBALS['TUT_PERF_MANUAL_PHASES']);
        }
    }

    public function testBeginLiveStartOrdersByFirstPlayerWhenP2GoesFirst(): void
    {
        $state = [
            'status' => 'playing',
            'phase' => 'live_start_effects',
            'seq' => 1,
            'turn' => 2,
            'first_player' => 'p2',
            'active_player' => 'p2',
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'waiting_room' => [],
                    'energy_zone' => [],
                    'main_deck' => [],
                    'live_zone' => [$this->dummyLive('p1l')],
                    'success_lives' => [],
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'P2',
                    'hand' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'waiting_room' => [],
                    'energy_zone' => [],
                    'main_deck' => [],
                    'live_zone' => [$this->dummyLive('p2l')],
                    'success_lives' => [],
                ],
            ],
        ];
        $GLOBALS['TUT_PERF_MANUAL_PHASES'] = true;
        try {
            $state = \beginLiveStartEffectPhase($state, true, true);
            $this->assertSame(['p2', 'p1'], $state['live_attempt']);
            $this->assertSame('p2', $state['_live_start_perf_pid'] ?? null);
            $this->assertTrue(!empty($state['_live_start_done']['p2']));
            $this->assertTrue(empty($state['_live_start_done']['p1']));
        } finally {
            unset($GLOBALS['TUT_PERF_MANUAL_PHASES']);
        }
    }
}
