<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * GitHub #104 — bp5-003 Position Change must not cancel On Enter;
 * bp6-003 Wait-activate On Enter after leave; bp6-007 Ceras self-enter.
 */
final class Issue104CardFixesTest extends TestCase
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

    private function emptyPlayer(string $id, string $name): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'hand' => [],
            'stage' => ['left' => null, 'center' => null, 'right' => null],
            'waiting_room' => [],
            'energy_zone' => [],
            'main_deck' => [],
            'energy_deck' => [],
            'live_zone' => [],
            'success_lives' => [],
        ];
    }

    private function energy(int $n): array
    {
        return array_map(
            static fn(int $i): array => ['instance_id' => "e$i", 'active' => true],
            range(0, $n - 1)
        );
    }

    private function miraCraStub(string $instanceId): array
    {
        return [
            'instance_id' => $instanceId,
            'card_no' => 'PL!HS-stub-mira',
            'card_type' => 'メンバー',
            'name_en' => 'Mira Stub',
            'name' => 'Mira',
            'group' => 'Hasunosora',
            'subunit' => 'みらくらぱーく！',
            'active' => true,
            'cost' => 3,
            'blade' => 1,
        ];
    }

    private function baseState(array $p1, array $p2): array
    {
        return [
            'room_id' => 'ISSUE104',
            'status' => 'playing',
            'seq' => 1,
            'turn' => 3,
            'phase' => 'main_first',
            'first_player' => 'p1',
            'active_player' => 'p1',
            'log' => [],
            'players' => ['p1' => $p1, 'p2' => $p2],
        ];
    }

    public function testCerasSelfEnterOpensOppWaitImmediately(): void
    {
        $ceras = $this->cardByNo('PL!HS-bp6-007-P', 'ceras_self');
        $oppA = $this->cardByNo('PL!HS-sd1-015-SD', 'opp_a');
        $oppB = $this->cardByNo('PL!HS-sd1-015-SD', 'opp_b');

        $p1 = $this->emptyPlayer('p1', 'P1');
        $p1['hand'] = [$ceras];
        $p1['energy_zone'] = $this->energy(16);

        $p2 = $this->emptyPlayer('p2', 'P2');
        $p2['stage'] = [
            'left' => $oppA,
            'center' => $oppB,
            'right' => null,
        ];

        $state = $this->baseState($p1, $p2);
        $state = \applyAction($state, 'p1', 'play_member', [
            'card_id' => 'ceras_self',
            'slot' => 'center',
        ]);

        $this->assertSame('wait_opponent_stage_pick', $state['pending_prompt']['type'] ?? null);
        $this->assertSame('p2', $state['pending_prompt']['responder'] ?? null);
        $this->assertTrue(!empty($state['pending_prompt']['opp_chooses']));
    }

    public function testSoloOverplayBp5003OffersPositionChangeThenAllowsEnter(): void
    {
        $leave = $this->cardByNo('PL!HS-bp5-003-P', 'ruri_leave');
        // Mark as entered prior turn so replace is legal.
        $leave['entered_turn'] = 1;
        $enter = $this->cardByNo('PL!HS-bp6-003-P', 'ruri_enter');
        $wait = $this->miraCraStub('wait_mira');
        $live = $this->cardByNo('PL!HS-bp6-030-L', 'mira_live');

        $p1 = $this->emptyPlayer('p1', 'P1');
        $p1['stage']['center'] = $leave;
        $p1['stage']['left'] = $wait;
        \waitMember($p1['stage']['left'], [
            'phase' => 'main_first',
            'turn' => 3,
            'active_player' => 'p1',
        ]);
        $p1['hand'] = [$enter];
        $p1['waiting_room'] = [$live];
        // Non-empty deck so baton→WR does not refresh the Live into main_deck.
        $p1['main_deck'] = [
            [
                'instance_id' => 'deck_pad',
                'card_type' => 'メンバー',
                'name_en' => 'Pad',
                'group' => 'Hasunosora',
            ],
        ];
        $p1['energy_zone'] = $this->energy(20);

        $state = $this->baseState($p1, $this->emptyPlayer('p2', 'P2'));
        $state = \applyAction($state, 'p1', 'play_member', [
            'card_id' => 'ruri_enter',
            'slot' => 'center',
            'baton_id' => 'ruri_leave',
        ]);

        $this->assertSame('optional_stage_reposition', $state['pending_prompt']['type'] ?? null);
        $this->assertNotEmpty($state['_resume_on_enter'] ?? null);
        $candIds = array_column($state['pending_prompt']['candidates'] ?? [], 'instance_id');
        $this->assertContains('ruri_enter', $candIds);
        $this->assertContains('wait_mira', $candIds);

        $state = \applyAction($state, 'p1', 'resolve_prompt', ['choice' => 'no']);
        $this->assertSame(
            'optional_activate_wait_subunit_add_live_wr',
            $state['pending_prompt']['type'] ?? null
        );

        $state = \applyAction($state, 'p1', 'resolve_prompt', ['choice' => 'yes']);
        $this->assertSame('pick_wr_to_hand', $state['pending_prompt']['type'] ?? null);
        $this->assertFalse(\memberIsInWait($state['players']['p1']['stage']['left']));

        $state = \applyAction($state, 'p1', 'resolve_prompt', ['card_id' => 'mira_live']);
        $this->assertNull($state['pending_prompt'] ?? null);
        $this->assertSame('mira_live', $state['players']['p1']['hand'][0]['instance_id'] ?? null);
    }

    public function testBp5003SoloReplaceStillOffersPositionChange(): void
    {
        $leave = $this->cardByNo('PL!HS-bp5-003-P', 'solo_leave');
        $leave['entered_turn'] = 1;
        $enter = $this->miraCraStub('solo_enter');
        $enter['cost'] = 2;

        $p1 = $this->emptyPlayer('p1', 'P1');
        $p1['stage']['center'] = $leave;
        $p1['hand'] = [$enter];
        $p1['energy_zone'] = $this->energy(8);

        $state = $this->baseState($p1, $this->emptyPlayer('p2', 'P2'));
        $state = \applyAction($state, 'p1', 'play_member', [
            'card_id' => 'solo_enter',
            'slot' => 'center',
        ]);

        $this->assertSame('optional_stage_reposition', $state['pending_prompt']['type'] ?? null);
        $candIds = array_column($state['pending_prompt']['candidates'] ?? [], 'instance_id');
        $this->assertSame(['solo_enter'], $candIds);
    }
}
