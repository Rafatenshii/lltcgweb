<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * Regression: Nijigasaki Wait activate / Live Start / Live Success bugs
 * (Emma PB1-008, Cara Tesoro PB1-037, La Bella Patria BP3-027, Emma BP3-008).
 */
final class NijiWaitActivateAndLiveBugsTest extends TestCase
{
    private function cardByNo(string $cardNo, string $instanceId): array
    {
        $data = json_decode((string) file_get_contents((string) constant('CARDS_FILE')), true);
        $this->assertIsArray($data);
        foreach ($data['cards'] ?? [] as $card) {
            if (($card['card_no'] ?? '') === $cardNo) {
                $card['instance_id'] = $instanceId;
                $card['active'] = true;
                return $card;
            }
        }
        $this->fail('Missing test card ' . $cardNo);
    }

    private function basePlayers(): array
    {
        return [
            'p1' => [
                'id' => 'p1',
                'name' => 'P1',
                'hand' => [],
                'waiting_room' => [],
                'stage' => ['left' => null, 'center' => null, 'right' => null],
                'energy_zone' => [],
                'energy_deck' => [],
                'main_deck' => [],
                'success_lives' => [],
                'live_zone' => [],
            ],
            'p2' => [
                'id' => 'p2',
                'name' => 'P2',
                'hand' => [],
                'waiting_room' => [],
                'stage' => ['left' => null, 'center' => null, 'right' => null],
                'energy_zone' => [],
                'energy_deck' => [],
                'main_deck' => [],
                'success_lives' => [],
                'live_zone' => [],
            ],
        ];
    }

    public function testEmmaPb1008HandCostReducedWithWaitNijigasaki(): void
    {
        $emma = $this->cardByNo('PL!N-pb1-008-R', 'emma_hand');
        $waiter = $this->cardByNo('PL!N-bp3-008-SEC', 'wait_niji');
        $state = [
            'status' => 'playing',
            'phase' => 'main_first',
            'seq' => 1,
            'turn' => 2,
            'players' => $this->basePlayers(),
        ];
        waitMember($waiter, $state);
        $state['players']['p1']['hand'] = [$emma];
        $state['players']['p1']['stage']['left'] = $waiter;

        $printed = intval($emma['cost'] ?? 0);
        $this->assertSame($printed - 2, getEffectiveHandCost($state, 'p1', $emma));
    }

    public function testEmmaPb1008OnEnterActivatesWaitMember(): void
    {
        $emma = $this->cardByNo('PL!N-pb1-008-R', 'emma');
        $waiter = $this->cardByNo('PL!N-bp3-009-P', 'wait_rina');
        $state = [
            'status' => 'playing',
            'phase' => 'main_first',
            'seq' => 1,
            'turn' => 2,
            'players' => $this->basePlayers(),
        ];
        waitMember($waiter, $state);
        $this->assertTrue(memberIsInWait($waiter));
        $state['players']['p1']['stage']['center'] = $emma;
        $state['players']['p1']['stage']['left'] = $waiter;

        $onEnter = null;
        foreach ($emma['abilities'] as $ab) {
            if (($ab['trigger'] ?? '') === 'on_enter') {
                $onEnter = $ab;
                break;
            }
        }
        $this->assertNotNull($onEnter);
        $state = resolveAbilityEffect($state, 'p1', $emma, $onEnter, ['phase' => 'on_enter']);
        $this->assertSame('player_choice', $state['pending_prompt']['type'] ?? null);

        $state = \actionResolvePrompt($state, 'p1', ['choice' => 'activate_member']);
        $left = $state['players']['p1']['stage']['left'];
        $this->assertFalse(memberIsInWait($left));
        $this->assertTrue(!empty($state['players']['p1']['_niji_turn_flags']['activated_wait_member']));
    }

    public function testCaraTesoroLiveStartScorePlusTwo(): void
    {
        $live = $this->cardByNo('PL!N-pb1-037-L', 'cara');
        $state = [
            'status' => 'playing',
            'phase' => 'live_start_effects',
            'seq' => 1,
            'turn' => 3,
            'players' => $this->basePlayers(),
        ];
        $state['players']['p1']['live_zone'] = [$live];
        $state['players']['p1']['_niji_turn_flags'] = [
            'activated_wait_energy' => true,
            'activated_wait_member' => true,
        ];

        $baseScore = intval($live['score'] ?? 0);
        $ab = $live['abilities'][0];
        $state = resolveAbilityEffect($state, 'p1', $live, $ab, ['phase' => 'live_start']);
        $scored = $state['players']['p1']['live_zone'][0];
        $this->assertSame($baseScore + 2, intval($scored['score'] ?? 0));
    }

    public function testCaraTesoroLiveStartScorePlusOneEnergyOnly(): void
    {
        $live = $this->cardByNo('PL!N-pb1-037-L', 'cara');
        $state = [
            'status' => 'playing',
            'phase' => 'live_start_effects',
            'seq' => 1,
            'turn' => 3,
            'players' => $this->basePlayers(),
        ];
        $state['players']['p1']['live_zone'] = [$live];
        $state['players']['p1']['_niji_turn_flags'] = [
            'activated_wait_energy' => true,
        ];

        $baseScore = intval($live['score'] ?? 0);
        $state = resolveAbilityEffect($state, 'p1', $live, $live['abilities'][0], ['phase' => 'live_start']);
        $scored = $state['players']['p1']['live_zone'][0];
        $this->assertSame($baseScore + 1, intval($scored['score'] ?? 0));
    }

    public function testCaraTesoroTracksNijiActivateEnergy(): void
    {
        $emma = $this->cardByNo('PL!N-pb1-008-R', 'emma');
        $state = [
            'status' => 'playing',
            'phase' => 'main_first',
            'seq' => 1,
            'turn' => 2,
            'players' => $this->basePlayers(),
        ];
        $state['players']['p1']['energy_zone'] = [
            ['instance_id' => 'e1', 'active' => false],
            ['instance_id' => 'e2', 'active' => false],
        ];

        $state = resolveAbilityEffect($state, 'p1', $emma, [
            'type' => 'activate_energy',
            'count' => 2,
        ], ['phase' => 'choice']);

        $this->assertTrue(!empty($state['players']['p1']['_niji_turn_flags']['activated_wait_energy']));
        $this->assertTrue($state['players']['p1']['energy_zone'][0]['active']);
        $this->assertTrue($state['players']['p1']['energy_zone'][1]['active']);
    }

    public function testKanataWaitSelfActivateEnergyTracksForCaraTesoro(): void
    {
        $kanata = $this->cardByNo('PL!N-pb1-006-R', 'kanata1');
        $live = $this->cardByNo('PL!N-pb1-037-L', 'cara');
        $state = [
            'status' => 'playing',
            'phase' => 'main_first',
            'seq' => 1,
            'turn' => 3,
            'active_player' => 'p1',
            'players' => $this->basePlayers(),
        ];
        $state['players']['p1']['stage']['left'] = $kanata;
        $state['players']['p1']['energy_zone'] = [
            ['instance_id' => 'e1', 'active' => false],
            ['instance_id' => 'e2', 'active' => true],
        ];

        $state = applyAction($state, 'p1', 'activate_ability', [
            'card_id' => 'kanata1',
            'ability_index' => 0,
        ]);
        $this->assertTrue(memberIsInWait($state['players']['p1']['stage']['left']));
        $this->assertTrue(
            !empty($state['players']['p1']['_niji_turn_flags']['activated_wait_energy']),
            'Kanata Wait→activate Energy must count for Cara Tesoro'
        );

        // Also activated a Wait Member later the same turn → Cara scores +2.
        $state['players']['p1']['_niji_turn_flags']['activated_wait_member'] = true;
        $state['players']['p1']['live_zone'] = [$live];
        $baseScore = intval($live['score'] ?? 0);
        $state = resolveAbilityEffect($state, 'p1', $live, $live['abilities'][0], ['phase' => 'live_start']);
        $scored = $state['players']['p1']['live_zone'][0];
        $this->assertSame($baseScore + 2, intval($scored['score'] ?? 0));
    }

    public function testLaBellaPatriaLiveSuccessPutsEnergyInWaitOnGreenExcess(): void
    {
        $live = $this->cardByNo('PL!N-bp3-027-L', 'patria');
        $niji = $this->cardByNo('PL!N-bp3-008-SEC', 'emma_stage');
        $energyCard = ['instance_id' => 'ed1', 'card_type' => 'エネルギー', 'active' => true];

        $state = [
            'status' => 'playing',
            'phase' => 'live_performance',
            'seq' => 1,
            'turn' => 4,
            'players' => $this->basePlayers(),
        ];
        $state['players']['p1']['stage']['center'] = $niji;
        $state['players']['p1']['energy_deck'] = [$energyCard];
        $state['players']['p1']['energy_zone'] = [];

        $state = resolveAbilityEffect($state, 'p1', $live, $live['abilities'][0], [
            'phase' => 'live_success',
            'excess_hearts' => 1,
            'excess_heart_colors' => ['green'],
        ]);

        $this->assertCount(1, $state['players']['p1']['energy_zone']);
        $this->assertFalse($state['players']['p1']['energy_zone'][0]['active'] ?? true);
        $this->assertEmpty($state['players']['p1']['energy_deck']);
    }

    public function testEmmaBp3008LiveStartActivatesOtherWaitMember(): void
    {
        $emma = $this->cardByNo('PL!N-bp3-008-SEC', 'emma_sec');
        $waiter = $this->cardByNo('PL!N-bp3-009-P', 'wait_rina');
        $handA = $this->cardByNo('PL!N-bp3-027-L', 'h1');
        $handB = $this->cardByNo('PL!N-pb1-037-L', 'h2');

        $state = [
            'status' => 'playing',
            'phase' => 'live_start_effects',
            'seq' => 1,
            'turn' => 3,
            'players' => $this->basePlayers(),
        ];
        waitMember($waiter, $state);
        $state['players']['p1']['stage']['center'] = $emma;
        $state['players']['p1']['stage']['left'] = $waiter;
        $state['players']['p1']['hand'] = [$handA, $handB];

        $liveStart = null;
        foreach ($emma['abilities'] as $ab) {
            if (($ab['type'] ?? '') === 'optional_discard_activate_wait_hearts') {
                $liveStart = $ab;
                break;
            }
        }
        $this->assertNotNull($liveStart);
        $state = resolveAbilityEffect($state, 'p1', $emma, $liveStart, ['phase' => 'live_start']);
        $this->assertSame('optional_discard_activate_wait_hearts', $state['pending_prompt']['type'] ?? null);

        $GLOBALS['TUT_PERF_MANUAL_PHASES'] = true;
        try {
            $state = \actionResolvePrompt($state, 'p1', [
                'choice' => 'yes',
                'discard_ids' => ['h1', 'h2'],
            ]);
        } finally {
            unset($GLOBALS['TUT_PERF_MANUAL_PHASES']);
        }

        $left = $state['players']['p1']['stage']['left'];
        $this->assertFalse(memberIsInWait($left));
        $this->assertTrue(!empty($state['players']['p1']['_niji_turn_flags']['activated_wait_member']));
        $this->assertCount(2, $state['players']['p1']['waiting_room']);
    }
}
