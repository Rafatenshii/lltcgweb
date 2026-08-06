<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/** Regression: PL!SP-bp2-006 Kinako activated must open a hand Member pick (issue #86). */
final class Issue86KinakoBp2ActivatedTest extends TestCase
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

    private function baseState(string $phase = 'main_first'): array
    {
        return [
            'status' => 'playing',
            'phase' => $phase,
            'seq' => 1,
            'turn' => 1,
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
                    'main_deck' => [['instance_id' => 'deck_top']],
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
                    'main_deck' => [],
                    'success_lives' => [],
                    'live_zone' => [],
                ],
            ],
        ];
    }

    public function testKinakoBp2ActivatedOpensHandMemberPick(): void
    {
        $kinako = $this->cardByNo('PL!SP-bp2-006-P', 'issue86_kinako');
        $handMember = $this->cardByNo('PL!SP-PR-003-PR', 'issue86_hand_kanon');
        // No [On Enter] — must not appear as a candidate.
        $noEnter = $this->cardByNo('PL!SP-bp2-012-N', 'issue86_hand_kanon_blank');
        $expensive = $this->cardByNo('PL!SP-bp2-006-P', 'issue86_hand_kinako_self');
        $expensive['cost'] = 10;

        $state = $this->baseState();
        $state['players']['p1']['stage']['center'] = $kinako;
        $state['players']['p1']['hand'] = [$handMember, $noEnter, $expensive];

        $state = \actionActivateAbility($state, 'p1', [
            'card_id' => 'issue86_kinako',
            'ability_index' => 1,
        ]);

        $this->assertSame(
            'activated_discard_trigger_on_enter',
            $state['pending_prompt']['type'] ?? null
        );
        $candIds = array_column($state['pending_prompt']['candidates'] ?? [], 'instance_id');
        $this->assertSame(['issue86_hand_kanon'], $candIds);
        $this->assertCount(3, $state['players']['p1']['hand']);
    }

    public function testKinakoBp2HandPickTriggersDiscardedOnEnter(): void
    {
        $kinako = $this->cardByNo('PL!SP-bp2-006-P', 'issue86_kinako2');
        $handMember = $this->cardByNo('PL!SP-PR-003-PR', 'issue86_hand_kanon2');
        // draw_if_min_energy needs energy — give plenty so the effect applies.
        $energy = [];
        for ($i = 0; $i < 3; $i++) {
            $energy[] = [
                'instance_id' => 'issue86_en' . $i,
                'card_type' => 'エネルギー',
                'active' => true,
            ];
        }

        $state = $this->baseState();
        $state['players']['p1']['stage']['center'] = $kinako;
        $state['players']['p1']['hand'] = [$handMember];
        $state['players']['p1']['energy_zone'] = $energy;
        $state['players']['p1']['main_deck'] = [
            ['instance_id' => 'issue86_draw', 'card_type' => 'メンバー', 'name_en' => 'Drawn'],
        ];

        $state = \actionActivateAbility($state, 'p1', [
            'card_id' => 'issue86_kinako2',
            'ability_index' => 1,
        ]);
        $this->assertSame('activated_discard_trigger_on_enter', $state['pending_prompt']['type'] ?? null);

        $state = \actionResolvePrompt($state, 'p1', ['card_id' => 'issue86_hand_kanon2']);

        $this->assertEmpty($state['pending_prompt'] ?? null);
        $this->assertCount(0, $state['players']['p1']['hand']);
        $wrIds = array_column($state['players']['p1']['waiting_room'] ?? [], 'instance_id');
        $this->assertContains('issue86_hand_kanon2', $wrIds);
        $this->assertTrue(
            \isAbilityUsed($state['players']['p1']['stage']['center'], 1),
            'Kinako activated ability should be marked used'
        );
    }
}
