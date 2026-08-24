<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * PL!N-bp5-003 Shizuku — activated: discard 1, then optionally pay Energy
 * equal to a WR Live's score to add that Live to hand.
 */
final class ShizukuBp5003DiscardPayWrLiveTest extends TestCase
{
    private function shizukuMember(): array
    {
        return [
            'instance_id' => 'shizuku_1',
            'card_no' => 'PL!N-bp5-003-AR',
            'card_type' => 'メンバー',
            'name_en' => 'Shizuku Osaka',
            'group' => 'Nijigasaki',
            'abilities' => [[
                'trigger' => 'activated',
                'type' => 'activated_discard_pay_wr_live_score',
                'discard' => 1,
                'once_per_turn' => true,
            ]],
        ];
    }

    private function baseState(array $overrides = []): array
    {
        $hand = [];
        for ($i = 0; $i < 3; $i++) {
            $hand[] = [
                'instance_id' => "hand_$i",
                'card_no' => "H$i",
                'card_type' => 'メンバー',
                'name_en' => "Hand $i",
            ];
        }
        $liveWr = [
            'instance_id' => 'live_wr_1',
            'card_no' => 'PL!N-LIVE-1',
            'card_type' => 'ライブ',
            'card_type_en' => 'Live',
            'name_en' => 'Niji Live',
            'group' => 'Nijigasaki',
            'score' => 2,
        ];
        $energy = [];
        for ($i = 0; $i < 3; $i++) {
            $energy[] = [
                'instance_id' => "e$i",
                'card_type' => 'エネルギー',
                'active' => true,
            ];
        }
        $state = [
            'room_id' => 'SHIZUKU003',
            'status' => 'playing',
            'seq' => 5,
            'turn' => 2,
            'phase' => 'main_first',
            'active_player' => 'p1',
            'first_player' => 'p1',
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'token' => 'tok1',
                    'hand' => $hand,
                    'waiting_room' => [$liveWr],
                    'stage' => ['left' => null, 'center' => $this->shizukuMember(), 'right' => null],
                    'live_zone' => [],
                    'main_deck' => [],
                    'energy_zone' => $energy,
                    'energy_deck' => [],
                    'success_lives' => [],
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'P2',
                    'token' => 'tok2',
                    'hand' => [],
                    'waiting_room' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'live_zone' => [],
                    'main_deck' => [],
                    'energy_zone' => [],
                    'energy_deck' => [],
                ],
            ],
        ];
        return array_replace_recursive($state, $overrides);
    }

    public function testActivateOpensDiscardPromptInsteadOfError(): void
    {
        $state = $this->baseState();
        $out = actionActivateAbility($state, 'p1', [
            'card_id' => 'shizuku_1',
            'ability_index' => 0,
        ]);
        $this->assertSame('bp5_discard_pay_wr_live_score', $out['pending_prompt']['type'] ?? null);
        $this->assertSame('discard', $out['pending_prompt']['step'] ?? null);
        $this->assertSame(1, $out['pending_prompt']['discard_count'] ?? null);
        $this->assertCount(3, $out['players']['p1']['hand']);
    }

    public function testDiscardThenPickLivePaysScoreAndAddsToHand(): void
    {
        $state = $this->baseState();
        $state = actionActivateAbility($state, 'p1', [
            'card_id' => 'shizuku_1',
            'ability_index' => 0,
        ]);
        $state = actionResolvePrompt($state, 'p1', [
            'discard_ids' => ['hand_0'],
        ]);
        $this->assertSame('bp5_discard_pay_wr_live_score', $state['pending_prompt']['type'] ?? null);
        $this->assertSame('pick_live', $state['pending_prompt']['step'] ?? null);
        $this->assertCount(2, $state['players']['p1']['hand']);
        $this->assertTrue(isAbilityUsed($state['players']['p1']['stage']['center'], 0));

        $state = actionResolvePrompt($state, 'p1', [
            'card_id' => 'live_wr_1',
        ]);
        $this->assertArrayNotHasKey('pending_prompt', $state);
        $handIds = array_column($state['players']['p1']['hand'], 'instance_id');
        $this->assertContains('live_wr_1', $handIds);
        $wrIds = array_column($state['players']['p1']['waiting_room'], 'instance_id');
        $this->assertNotContains('live_wr_1', $wrIds);
        $this->assertContains('hand_0', $wrIds);
        $this->assertSame(1, countActiveEnergyInZone($state['players']['p1']));
    }

    public function testPickLiveCanSkip(): void
    {
        $state = $this->baseState();
        $state = actionActivateAbility($state, 'p1', [
            'card_id' => 'shizuku_1',
            'ability_index' => 0,
        ]);
        $state = actionResolvePrompt($state, 'p1', [
            'discard_ids' => ['hand_1'],
        ]);
        $state = actionResolvePrompt($state, 'p1', [
            'choice' => 'skip',
        ]);
        $this->assertArrayNotHasKey('pending_prompt', $state);
        $handIds = array_column($state['players']['p1']['hand'], 'instance_id');
        $this->assertNotContains('live_wr_1', $handIds);
        $this->assertContains('live_wr_1', array_column($state['players']['p1']['waiting_room'], 'instance_id'));
    }

    public function testSolitudeRainAfterLiveStartPaysPrintedZero(): void
    {
        $state = $this->baseState([
            'players' => [
                'p1' => [
                    'waiting_room' => [[
                        'instance_id' => 'solitude_wr',
                        'card_no' => 'PL!N-bp1-027-L',
                        'card_type' => 'ライブ',
                        'card_type_en' => 'Live',
                        'name_en' => 'Solitude Rain',
                        'group' => 'Nijigasaki',
                        'score' => 4,
                        '_printed_score' => 0,
                        '_effect_score_bonus' => 4,
                    ]],
                ],
            ],
        ]);
        $beforeEnergy = countActiveEnergyInZone($state['players']['p1']);
        $state = actionActivateAbility($state, 'p1', [
            'card_id' => 'shizuku_1',
            'ability_index' => 0,
        ]);
        $state = actionResolvePrompt($state, 'p1', [
            'discard_ids' => ['hand_0'],
        ]);
        $this->assertSame('bp5_discard_pay_wr_live_score', $state['pending_prompt']['type'] ?? null);
        $candIds = array_column($state['pending_prompt']['candidates'] ?? [], 'instance_id');
        $this->assertContains('solitude_wr', $candIds);
        $this->assertSame(0, intval(($state['pending_prompt']['candidates'][0]['score'] ?? -1)));

        $state = actionResolvePrompt($state, 'p1', [
            'card_id' => 'solitude_wr',
        ]);
        $this->assertArrayNotHasKey('pending_prompt', $state);
        $this->assertContains('solitude_wr', array_column($state['players']['p1']['hand'], 'instance_id'));
        $this->assertSame($beforeEnergy, countActiveEnergyInZone($state['players']['p1']));
        foreach ($state['players']['p1']['hand'] as $c) {
            if (($c['instance_id'] ?? '') === 'solitude_wr') {
                $this->assertSame(0, intval($c['score'] ?? -1));
            }
        }
    }
}
