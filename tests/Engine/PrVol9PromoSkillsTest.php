<?php
declare(strict_types=1);

namespace Tests\Engine;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/effects.php';

final class PrVol9PromoSkillsTest extends TestCase
{
    private function baseState(): array
    {
        return [
            'room_id' => 'TEST',
            'status' => 'playing',
            'seq' => 1,
            'turn' => 1,
            'phase' => 'main_first',
            'first_player' => 'p1',
            'active_player' => 'p1',
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [],
                    'main_deck' => [],
                    'waiting_room' => [],
                    'energy_zone' => [],
                    'stage' => ['center' => null, 'left' => null, 'right' => null],
                    'live_zone' => [],
                    'success_lives' => [],
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'P2',
                    'hand' => [],
                    'main_deck' => [],
                    'waiting_room' => [],
                    'energy_zone' => [],
                    'stage' => ['center' => null, 'left' => null, 'right' => null],
                    'live_zone' => [],
                    'success_lives' => [],
                ],
            ],
        ];
    }

    public function testSumireAutoYellLiveBlade(): void
    {
        $state = $this->baseState();
        $state['live_modifiers'] = ['p1' => ['blade_bonus' => 0, 'bonus_hearts' => []], 'p2' => ['blade_bonus' => 0, 'bonus_hearts' => []]];
        $sumire = [
            'instance_id' => 'sumire1',
            'card_no' => 'PL!SP-PR-024-PR',
            'name' => '平安名すみれ',
            'name_en' => 'Sumire Heanna',
            'card_type' => 'メンバー',
            'group' => 'Superstar',
            'abilities' => [[
                'trigger' => 'auto',
                'type' => 'auto_yell_live_blade',
                'group' => 'Superstar',
                'requires_score' => true,
                'amount' => 1,
                'once_per_turn' => true,
            ]],
        ];
        $state['players']['p1']['stage']['center'] = $sumire;
        $yell = [[
            'instance_id' => 'live1',
            'card_type' => 'ライブ',
            'group' => 'Superstar',
            'score' => 1,
            'yell_score' => 1,
        ]];
        $state = resolveAutoYellAbilities($state, 'p1', $yell);
        $this->assertGreaterThanOrEqual(1, intval($state['live_modifiers']['p1']['blade_bonus'] ?? 0));
    }

    public function testMillFillWrOptionalLiveDeckTop(): void
    {
        $state = $this->baseState();
        $state['players']['p1']['waiting_room'] = [];
        for ($i = 0; $i < 5; $i++) {
            $state['players']['p1']['main_deck'][] = [
                'instance_id' => 'd' . $i,
                'card_type' => $i === 2 ? 'ライブ' : 'メンバー',
                'name_en' => $i === 2 ? 'Test Live' : 'M' . $i,
                'group' => 'Sunshine',
            ];
        }
        $src = [
            'instance_id' => 'chika',
            'name_en' => 'Chika',
            'card_type' => 'メンバー',
            'abilities' => [[
                'trigger' => 'on_enter',
                'type' => 'mill_fill_wr_optional_live_deck_top',
                'target_wr' => 8,
            ]],
        ];
        $state = resolveAbilityEffect($state, 'p1', $src, $src['abilities'][0]);
        $this->assertCount(5, $state['players']['p1']['waiting_room']);
        $this->assertSame('mill_fill_wr_optional_live_deck_top', $state['pending_prompt']['type'] ?? null);
    }

    public function testBatonEnterDrawDiscardExactCost(): void
    {
        $state = $this->baseState();
        $state['players']['p1']['main_deck'] = [
            ['instance_id' => 'a', 'card_type' => 'メンバー'],
            ['instance_id' => 'b', 'card_type' => 'メンバー'],
            ['instance_id' => 'c', 'card_type' => 'メンバー'],
        ];
        $yoshiko = [
            'instance_id' => 'y1',
            'name_en' => 'Yoshiko',
            'card_type' => 'メンバー',
            'entered_via_baton' => true,
            'baton_from_cost' => 7,
            'abilities' => [[
                'trigger' => 'on_enter',
                'type' => 'baton_enter_draw_discard',
                'baton_cost_exact' => 7,
                'draw' => 2,
                'discard' => 1,
            ]],
        ];
        $state = resolveAbilityEffect($state, 'p1', $yoshiko, $yoshiko['abilities'][0]);
        $this->assertCount(2, $state['players']['p1']['hand']);
        $this->assertNotEmpty($state['pending_prompt'] ?? []);
    }
}
