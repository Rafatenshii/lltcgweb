<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/** Issue #77: Retrofuture WR play UI path + Izumi up-to-2 Wait selection. */
final class Issue77RetrofutureIzumiWaitTest extends TestCase
{
    private function cardByNo(string $cardNo, string $instanceId): array {
        $data = json_decode((string) file_get_contents(CARDS_FILE), true);
        $this->assertIsArray($data);
        foreach ($data['cards'] ?? [] as $card) {
            if (($card['card_no'] ?? '') === $cardNo) {
                $card['instance_id'] = $instanceId;
                return $card;
            }
        }
        $this->fail('Missing test card ' . $cardNo);
    }

    private function emptyPlayer(string $id, string $name): array {
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

    public function testIzumiOnEnterOpensUpToTwoWaitPick(): void {
        $izumi = $this->cardByNo('PL!HS-bp5-016-N', 'izumi');
        $handCard = $this->cardByNo('PL!HS-sd1-015-SD', 'hand_discard');
        $oppLow1 = $this->cardByNo('PL!HS-bp5-008-R', 'opp_low_1'); // cost 4 Edel
        $oppLow2 = $this->cardByNo('PL!HS-sd1-015-SD', 'opp_low_2'); // cost 2
        $oppHigh = $this->cardByNo('PL!HS-pb1-007-R', 'opp_high'); // cost 11

        $p1 = $this->emptyPlayer('p1', 'P1');
        $p1['hand'] = [$izumi, $handCard];
        $p1['energy_zone'] = array_map(
            static fn(int $i): array => ['instance_id' => "e$i", 'active' => true],
            range(0, 11)
        );

        $p2 = $this->emptyPlayer('p2', 'P2');
        $p2['stage'] = [
            'left' => $oppLow1,
            'center' => $oppLow2,
            'right' => $oppHigh,
        ];

        $state = [
            'room_id' => 'ISSUE77',
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
            'card_id' => 'izumi',
            'slot' => 'center',
        ]);
        $this->assertSame('optional_discard_prompt', $state['pending_prompt']['type'] ?? null);

        $state = applyAction($state, 'p1', 'resolve_prompt', [
            'choice' => 'yes',
            'discard_ids' => ['hand_discard'],
        ]);

        $this->assertSame('wait_opponent_stage_pick', $state['pending_prompt']['type'] ?? null);
        $this->assertSame(2, intval($state['pending_prompt']['pick_count'] ?? 0));
        $this->assertTrue(!empty($state['pending_prompt']['up_to']));
        $candSlots = array_column($state['pending_prompt']['candidates'] ?? [], 'slot');
        sort($candSlots);
        $this->assertSame(['center', 'left'], $candSlots);

        // Pick only left — right (cost 11) was never a candidate; center left active.
        $state = applyAction($state, 'p1', 'resolve_prompt', [
            'slots' => ['left'],
        ]);
        $this->assertEmpty($state['pending_prompt'] ?? null);
        $this->assertTrue(memberIsInWait($state['players']['p2']['stage']['left']));
        $this->assertFalse(memberIsInWait($state['players']['p2']['stage']['center']));
        $this->assertFalse(memberIsInWait($state['players']['p2']['stage']['right']));
    }

    public function testRetrofuturePlayChoiceOpensWrMemberPick(): void {
        $live = $this->cardByNo('PL!HS-bp5-022-L', 'retro');
        $wrMember = $this->cardByNo('PL!HS-bp5-008-R', 'wr_edel_4'); // Edel Note cost 4
        $center = $this->cardByNo('PL!HS-pb1-007-R', 'center_11'); // Edel Note cost 11

        $p1 = $this->emptyPlayer('p1', 'P1');
        $p1['stage']['center'] = $center;
        $p1['waiting_room'] = [$wrMember];
        $p1['live_zone'] = [$live];
        $p1['energy_zone'] = array_map(
            static fn(int $i): array => ['instance_id' => "e$i", 'active' => true],
            range(0, 5)
        );

        $p2 = $this->emptyPlayer('p2', 'P2');
        $p2['stage']['center'] = $this->cardByNo('PL!HS-sd1-001-SD', 'opp_kaho');

        $then = $live['abilities'][0]['then'];
        $state = [
            'room_id' => 'ISSUE77R',
            'status' => 'playing',
            'seq' => 1,
            'turn' => 1,
            'phase' => 'live_start_effects',
            'first_player' => 'p1',
            'active_player' => 'p1',
            'log' => [],
            'players' => ['p1' => $p1, 'p2' => $p2],
        ];

        $state = resolveAbilityEffect($state, 'p1', $live, $then, [
            'phase' => 'live_start',
        ]);

        $this->assertSame('live_start_edel_choice', $state['pending_prompt']['type'] ?? null);
        $this->assertContains('play', $state['pending_prompt']['choices'] ?? []);

        $state = applyAction($state, 'p1', 'resolve_prompt', ['choice' => 'play']);
        $this->assertSame('live_start_edel_play_wr', $state['pending_prompt']['type'] ?? null);
        $this->assertNotEmpty($state['pending_prompt']['candidates'] ?? []);
        $this->assertSame('Edel Note', $state['pending_prompt']['subunit'] ?? '');
        $this->assertSame(4, intval($state['pending_prompt']['max_cost'] ?? 0));
        $this->assertSame('member', $state['pending_prompt']['wr_pick_cfg']['filter'] ?? null);

        $state = applyAction($state, 'p1', 'resolve_prompt', ['card_id' => 'wr_edel_4']);
        $playedId = $state['players']['p1']['stage']['left']['instance_id']
            ?? $state['players']['p1']['stage']['right']['instance_id']
            ?? null;
        $this->assertSame('wr_edel_4', $playedId);
        $wrIds = array_column($state['players']['p1']['waiting_room'], 'instance_id');
        $this->assertNotContains('wr_edel_4', $wrIds);
    }
}
