<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use LLTCG\Game\EffectRegistry;
use PHPUnit\Framework\TestCase;

final class EffectRegistryDispatchTest extends TestCase
{
    public function testHandlerOwnedDrawTypes(): void
    {
        $this->assertTrue(EffectRegistry::hasHandler('draw_if_wr_min'));
        $this->assertTrue(EffectRegistry::hasHandler('draw_if_success_lives'));
        $this->assertTrue(EffectRegistry::hasHandler('grant_hearts'));
        $this->assertTrue(EffectRegistry::hasHandler('blade_bonus'));
        $this->assertTrue(EffectRegistry::hasHandler('blade_per_hand_cards'));
        $this->assertTrue(EffectRegistry::hasHandler('grant_live_score_if_success'));
        $this->assertFalse(EffectRegistry::hasHandler('add_from_wr_max_cost'));
    }

    public function testDispatchBladeBonusOnStageMember(): void
    {
        require_once dirname(__DIR__, 2) . '/effects.php';

        $member = [
            'instance_id' => 'm1',
            'card_no' => 'X',
            'name_en' => 'Member',
            'live_blade_bonus' => 0,
        ];
        $state = [
            'players' => [
                'p1' => [
                    'name' => 'P1',
                    'hand' => [],
                    'main_deck' => [],
                    'waiting_room' => [],
                    'stage' => ['center' => $member, 'back_left' => null, 'back_right' => null],
                    'energy_zone' => [],
                    'live_zone' => [],
                    'success_lives' => [],
                ],
            ],
            'log' => [],
        ];
        $p = &$state['players']['p1'];
        $ab = ['type' => 'blade_bonus', 'amount' => 2];
        $out = EffectRegistry::dispatch(
            $state,
            'p1',
            $member,
            $ab,
            [],
            'blade_bonus',
            $p,
            'Member'
        );
        $this->assertSame(2, intval($out['players']['p1']['stage']['center']['live_blade_bonus'] ?? 0));
    }

    public function testDispatchDrawIfWrMin(): void
    {
        require_once dirname(__DIR__, 2) . '/effects.php';

        $state = [
            'players' => [
                'p1' => [
                    'name' => 'P1',
                    'main_deck' => [
                        ['instance_id' => 'd1', 'card_no' => 'X', 'name_en' => 'A'],
                        ['instance_id' => 'd2', 'card_no' => 'X', 'name_en' => 'B'],
                    ],
                    'hand' => [],
                    'waiting_room' => array_fill(0, 10, ['instance_id' => 'w', 'card_no' => 'W']),
                    'stage' => [],
                    'energy_zone' => [],
                    'live_zone' => [],
                    'success_lives' => [],
                ],
            ],
            'log' => [],
        ];
        $p = &$state['players']['p1'];
        $ab = ['type' => 'draw_if_wr_min', 'draw' => 1, 'min_wr' => 10];
        $out = EffectRegistry::dispatch($state, 'p1', ['name_en' => 'Test'], $ab, [], 'draw_if_wr_min', $p, 'Test');
        $this->assertCount(1, $out['players']['p1']['hand']);
    }
}
