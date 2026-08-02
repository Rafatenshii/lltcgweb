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
        $this->assertFalse(EffectRegistry::hasHandler('add_from_wr_max_cost'));
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
