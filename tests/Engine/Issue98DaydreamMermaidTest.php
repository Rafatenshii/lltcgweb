<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/** Regression: Daydream Mermaid (PL!N-bp4-030-L) WR Member must be player-chosen (#98). */
final class Issue98DaydreamMermaidTest extends TestCase
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

    private function baseState(array $liveZone): array
    {
        return [
            'status' => 'playing',
            'phase' => 'live_success_effects',
            'seq' => 1,
            'turn' => 2,
            'first_player' => 'p1',
            'active_player' => 'p1',
            'live_attempt' => ['p1', 'p2'],
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [],
                    'waiting_room' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'energy_zone' => [],
                    'energy_deck' => [
                        ['instance_id' => 'e1', 'card_type' => 'エネルギー', 'active' => false],
                    ],
                    'main_deck' => [['instance_id' => 'pad']],
                    'success_lives' => [],
                    'live_zone' => $liveZone,
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
            ],
            '_live_success_ctx' => [
                'pid' => 'p1',
                'success_cards' => $liveZone,
                'excess_hearts' => 0,
                'excess_colors' => [],
                'yell_cards' => [],
            ],
        ];
    }

    public function testMemberChoiceOpensWrPickNotAutoFirst(): void
    {
        $mermaid = $this->cardByNo('PL!N-bp4-030-L', 'mermaid98');
        $first = [
            'instance_id' => 'wr_bottom',
            'card_type' => 'メンバー',
            'name_en' => 'First In WR',
            'cost' => 1,
        ];
        $wanted = [
            'instance_id' => 'wr_wanted',
            'card_type' => 'メンバー',
            'name_en' => 'Wanted Member',
            'cost' => 9,
        ];

        $state = $this->baseState([$mermaid]);
        $state['players']['p1']['waiting_room'] = [$first, $wanted];

        $state = \resolveLiveSuccessAbilities($state, 'p1', [$mermaid], 0, [], []);
        $this->assertSame('live_success_pick_energy_or_member', $state['pending_prompt']['type'] ?? null);

        $state = \actionResolvePrompt($state, 'p1', ['choice' => 'member']);
        $this->assertSame('pick_wr_to_hand', $state['pending_prompt']['type'] ?? null);
        $candIds = array_column($state['pending_prompt']['candidates'] ?? [], 'instance_id');
        $this->assertContains('wr_bottom', $candIds);
        $this->assertContains('wr_wanted', $candIds);
        // Must not have auto-added the first WR Member.
        $this->assertSame(['wr_bottom', 'wr_wanted'], array_column($state['players']['p1']['waiting_room'], 'instance_id'));
        $this->assertCount(0, $state['players']['p1']['hand']);

        $state = \actionResolvePrompt($state, 'p1', ['card_id' => 'wr_wanted']);
        $this->assertNull($state['pending_prompt'] ?? null);
        $handIds = array_column($state['players']['p1']['hand'], 'instance_id');
        $this->assertContains('wr_wanted', $handIds);
        $this->assertNotContains('wr_bottom', $handIds, 'Must add chosen Member, not auto-first');
    }

    public function testBothDoesEnergyThenOpensMemberPick(): void
    {
        $mermaid = $this->cardByNo('PL!N-bp4-030-L', 'mermaid98b');
        $nijiSuccess = $this->cardByNo('PL!N-bp4-027-L', 'niji_success');
        $member = [
            'instance_id' => 'wr_m',
            'card_type' => 'メンバー',
            'name_en' => 'WR Member',
            'cost' => 2,
        ];

        $state = $this->baseState([$mermaid]);
        $state['players']['p1']['success_lives'] = [$nijiSuccess];
        $state['players']['p1']['waiting_room'] = [$member];

        $state = \resolveLiveSuccessAbilities($state, 'p1', [$mermaid], 0, [], []);
        $this->assertTrue(!empty($state['pending_prompt']['can_both']));

        $state = \actionResolvePrompt($state, 'p1', ['choice' => 'both']);
        $this->assertSame('pick_wr_to_hand', $state['pending_prompt']['type'] ?? null);
        $zoneIds = array_column($state['players']['p1']['energy_zone'] ?? [], 'instance_id');
        $this->assertContains('e1', $zoneIds, 'Energy Wait should happen before Member pick');
        $this->assertCount(0, $state['players']['p1']['energy_deck']);

        $state = \actionResolvePrompt($state, 'p1', ['card_id' => 'wr_m']);
        $this->assertContains('wr_m', array_column($state['players']['p1']['hand'], 'instance_id'));
    }
}
