<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * Issue #80: Ceras Auto (opp Wait pick) must not overwrite the entering Edel Note
 * Member's On Enter prompt (replay 57B53D: bp6-007-P on stage, play bp5-007-R).
 */
final class Issue80CerasOnEnterAfterWaitTest extends TestCase
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

    public function testEnteringCerasOnEnterPromptBeforeOppWait(): void
    {
        $cerasAuto = $this->cardByNo('PL!HS-bp6-007-P', 'ceras_auto');
        $cerasEnter = $this->cardByNo('PL!HS-bp5-007-R', 'ceras_enter');
        $handA = $this->cardByNo('PL!HS-sd1-015-SD', 'hand_a');
        $handB = $this->cardByNo('PL!HS-sd1-015-SD', 'hand_b');
        $wrLive = $this->cardByNo('PL!HS-bp5-022-L', 'wr_live');
        $oppA = $this->cardByNo('PL!HS-sd1-015-SD', 'opp_a');
        $oppB = $this->cardByNo('PL!HS-sd1-015-SD', 'opp_b');

        $p1 = $this->emptyPlayer('p1', 'P1');
        $p1['stage']['center'] = $cerasAuto;
        $p1['hand'] = [$cerasEnter, $handA, $handB];
        $p1['waiting_room'] = [$wrLive];
        $p1['energy_zone'] = array_map(
            static fn(int $i): array => ['instance_id' => "e$i", 'active' => true],
            range(0, 14)
        );

        $p2 = $this->emptyPlayer('p2', 'P2');
        $p2['stage'] = [
            'left' => $oppA,
            'center' => $oppB,
            'right' => null,
        ];

        $state = [
            'room_id' => 'ISSUE80',
            'status' => 'playing',
            'seq' => 1,
            'turn' => 1,
            'phase' => 'main_first',
            'first_player' => 'p1',
            'active_player' => 'p1',
            'log' => [],
            'players' => ['p1' => $p1, 'p2' => $p2],
        ];

        $state = applyAction($state, 'p1', 'play_member', [
            'card_id' => 'ceras_enter',
            'slot' => 'left',
        ]);

        // On Enter must win over Ceras Auto — previously Wait clobbered this (#80).
        $this->assertSame('optional_discard_prompt', $state['pending_prompt']['type'] ?? null);
        $this->assertSame('p1', $state['pending_prompt']['responder'] ?? null);
        $this->assertNotEmpty($state['_resume_hs_auto_on_other_enter'] ?? null);

        $state = applyAction($state, 'p1', 'resolve_prompt', [
            'choice' => 'no',
        ]);

        $this->assertSame('wait_opponent_stage_pick', $state['pending_prompt']['type'] ?? null);
        $this->assertSame('p2', $state['pending_prompt']['responder'] ?? null);
        $this->assertTrue(!empty($state['pending_prompt']['opp_chooses']));

        $state = applyAction($state, 'p2', 'resolve_prompt', [
            'slot' => 'center',
        ]);
        $this->assertEmpty($state['pending_prompt'] ?? null);
        $this->assertTrue(memberIsInWait($state['players']['p2']['stage']['center']));
        $this->assertFalse(memberIsInWait($state['players']['p2']['stage']['left']));
    }

    public function testEnteringCerasOnEnterYesThenOppWait(): void
    {
        $cerasAuto = $this->cardByNo('PL!HS-bp6-007-P', 'ceras_auto');
        $cerasEnter = $this->cardByNo('PL!HS-bp5-007-R', 'ceras_enter');
        $handA = $this->cardByNo('PL!HS-sd1-015-SD', 'hand_a');
        $handB = $this->cardByNo('PL!HS-sd1-015-SD', 'hand_b');
        $wrLive = $this->cardByNo('PL!HS-bp5-022-L', 'wr_live');
        $oppA = $this->cardByNo('PL!HS-sd1-015-SD', 'opp_a');
        $oppB = $this->cardByNo('PL!HS-sd1-015-SD', 'opp_b');

        $p1 = $this->emptyPlayer('p1', 'P1');
        $p1['stage']['center'] = $cerasAuto;
        $p1['hand'] = [$cerasEnter, $handA, $handB];
        $p1['waiting_room'] = [$wrLive];
        $p1['energy_zone'] = array_map(
            static fn(int $i): array => ['instance_id' => "e$i", 'active' => true],
            range(0, 14)
        );

        $p2 = $this->emptyPlayer('p2', 'P2');
        $p2['stage'] = [
            'left' => $oppA,
            'center' => $oppB,
            'right' => null,
        ];

        $state = [
            'room_id' => 'ISSUE80B',
            'status' => 'playing',
            'seq' => 1,
            'turn' => 1,
            'phase' => 'main_first',
            'first_player' => 'p1',
            'active_player' => 'p1',
            'log' => [],
            'players' => ['p1' => $p1, 'p2' => $p2],
        ];

        $state = applyAction($state, 'p1', 'play_member', [
            'card_id' => 'ceras_enter',
            'slot' => 'left',
        ]);
        $this->assertSame('optional_discard_prompt', $state['pending_prompt']['type'] ?? null);

        $state = applyAction($state, 'p1', 'resolve_prompt', [
            'choice' => 'yes',
            'discard_ids' => ['hand_a', 'hand_b'],
        ]);

        // After discard, WR Live pick (or auto) — then Ceras Wait.
        if (($state['pending_prompt']['type'] ?? '') === 'pick_wr_to_hand') {
            $state = applyAction($state, 'p1', 'resolve_prompt', [
                'card_id' => 'wr_live',
            ]);
        }

        $this->assertSame('wait_opponent_stage_pick', $state['pending_prompt']['type'] ?? null);
        $this->assertSame('p2', $state['pending_prompt']['responder'] ?? null);

        $state = applyAction($state, 'p2', 'resolve_prompt', ['slot' => 'left']);
        $this->assertEmpty($state['pending_prompt'] ?? null);
        $this->assertTrue(memberIsInWait($state['players']['p2']['stage']['left']));
        $this->assertSame('wr_live', $state['players']['p1']['hand'][0]['instance_id'] ?? null);
    }
}
