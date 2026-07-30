<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/** Regression tests for GitHub issue #78 (Edel Note softlocks / Wait choice / Live Start order). */
final class Issue78EdelnoteFixesTest extends TestCase
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

    public function testCerasAutoOpensOppChoosesWaitPick(): void
    {
        $ceras = $this->cardByNo('PL!HS-bp6-007-P', 'ceras');
        // No On Enter prompt — Ceras Auto should open Wait immediately (#80 defers when On Enter pending).
        $edel = $this->cardByNo('PL!HS-sd1-015-SD', 'edel_enter');
        $oppA = $this->cardByNo('PL!HS-sd1-015-SD', 'opp_a');
        $oppB = $this->cardByNo('PL!HS-sd1-015-SD', 'opp_b');

        $p1 = $this->emptyPlayer('p1', 'P1');
        $p1['stage']['left'] = $ceras;
        $p1['hand'] = [$edel];
        $p1['energy_zone'] = array_map(
            static fn(int $i): array => ['instance_id' => "e$i", 'active' => true],
            range(0, 10)
        );

        $p2 = $this->emptyPlayer('p2', 'P2');
        $p2['stage'] = [
            'left' => $oppA,
            'center' => $oppB,
            'right' => null,
        ];

        $state = [
            'room_id' => 'ISSUE78',
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
            'card_id' => 'edel_enter',
            'slot' => 'center',
        ]);

        $this->assertSame('wait_opponent_stage_pick', $state['pending_prompt']['type'] ?? null);
        $this->assertSame('p2', $state['pending_prompt']['responder'] ?? null);
        $this->assertTrue(!empty($state['pending_prompt']['opp_chooses']));
        $this->assertTrue(!empty($state['pending_prompt']['ability']['active_only']));

        $blocked = applyAction($state, 'p1', 'resolve_prompt', ['slot' => 'left']);
        $this->assertTrue(!empty($blocked['_resolve_prompt_noop']));

        $state = applyAction($state, 'p2', 'resolve_prompt', ['slot' => 'center']);
        $this->assertEmpty($state['pending_prompt'] ?? null);
        $this->assertTrue(memberIsInWait($state['players']['p2']['stage']['center']));
        $this->assertFalse(memberIsInWait($state['players']['p2']['stage']['left']));
    }

    public function testTwoCerasesEachForceOppWait(): void
    {
        $cerasL = $this->cardByNo('PL!HS-bp6-007-P', 'ceras_l');
        $cerasR = $this->cardByNo('PL!HS-bp6-007-R', 'ceras_r');
        // No On Enter prompt so dual-Ceras Wait chain is tested directly (#80).
        $edel = $this->cardByNo('PL!HS-sd1-015-SD', 'edel_enter');
        $oppA = $this->cardByNo('PL!HS-sd1-015-SD', 'opp_a');
        $oppB = $this->cardByNo('PL!HS-sd1-015-SD', 'opp_b');
        $oppC = $this->cardByNo('PL!HS-sd1-015-SD', 'opp_c');

        $p1 = $this->emptyPlayer('p1', 'P1');
        $p1['stage']['left'] = $cerasL;
        $p1['stage']['right'] = $cerasR;
        $p1['hand'] = [$edel];
        $p1['energy_zone'] = array_map(
            static fn(int $i): array => ['instance_id' => "e$i", 'active' => true],
            range(0, 10)
        );

        $p2 = $this->emptyPlayer('p2', 'P2');
        // Three actives so the second Ceras still needs an opponent choice (not auto-1).
        $p2['stage'] = [
            'left' => $oppA,
            'center' => $oppB,
            'right' => $oppC,
        ];

        $state = [
            'room_id' => 'ISSUE78B',
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
            'card_id' => 'edel_enter',
            'slot' => 'center',
        ]);
        $this->assertSame('wait_opponent_stage_pick', $state['pending_prompt']['type'] ?? null);
        $this->assertSame('p2', $state['pending_prompt']['responder'] ?? null);

        $state = applyAction($state, 'p2', 'resolve_prompt', ['slot' => 'left']);
        $this->assertTrue(memberIsInWait($state['players']['p2']['stage']['left']));
        // Second Ceras should open another opp wait pick.
        $this->assertSame('wait_opponent_stage_pick', $state['pending_prompt']['type'] ?? null);
        $this->assertSame('p2', $state['pending_prompt']['responder'] ?? null);

        $state = applyAction($state, 'p2', 'resolve_prompt', ['slot' => 'center']);
        $this->assertEmpty($state['pending_prompt'] ?? null);
        $this->assertTrue(memberIsInWait($state['players']['p2']['stage']['center']));
        $this->assertFalse(memberIsInWait($state['players']['p2']['stage']['right']));
    }

    public function testLiveStartOptionalBeforeMandatoryByLiveSlot(): void
    {
        // Retrofuture (optional pay) in slot 0 must prompt before Edelied (mandatory) in slot 1.
        $retro = $this->cardByNo('PL!HS-bp5-022-L', 'retro');
        $retro['live_slot'] = 0;
        $edelied = $this->cardByNo('PL!HS-pb1-030-L', 'edelied');
        $edelied['live_slot'] = 1;
        $izumi = $this->cardByNo('PL!HS-pb1-007-R', 'izumi9'); // cost 9+ Edel

        $p1 = $this->emptyPlayer('p1', 'P1');
        $p1['stage']['center'] = $izumi;
        $p1['live_zone'] = [$edelied, $retro]; // array order opposite of live_slot on purpose
        $p1['energy_zone'] = array_map(
            static fn(int $i): array => ['instance_id' => "e$i", 'active' => true],
            range(0, 5)
        );

        $p2 = $this->emptyPlayer('p2', 'P2');

        $state = [
            'room_id' => 'ISSUE78LS',
            'status' => 'playing',
            'seq' => 1,
            'turn' => 1,
            'phase' => 'live_set',
            'first_player' => 'p1',
            'active_player' => 'p1',
            'live_attempt' => ['p1'],
            'log' => [],
            'players' => ['p1' => $p1, 'p2' => $p2],
        ];

        $state = beginLiveStartEffectPhase($state, true, false);
        $this->assertSame('optional_live_start', $state['pending_prompt']['type'] ?? null);
        $this->assertSame('retro', $state['pending_prompt']['source_id'] ?? null);
        $this->assertSame('optional_pay_energy', $state['pending_prompt']['ability']['type'] ?? null);
    }

    public function testPr023OpensWrMembersDeckTopPick(): void
    {
        $izumi = $this->cardByNo('PL!HS-PR-023-PR', 'pr023');
        $wr1 = $this->cardByNo('PL!HS-sd1-015-SD', 'wr1');
        $wr2 = $this->cardByNo('PL!HS-sd1-015-SD', 'wr2');
        $wr3 = $this->cardByNo('PL!HS-sd1-015-SD', 'wr3');

        $p1 = $this->emptyPlayer('p1', 'P1');
        $p1['stage']['center'] = $izumi;
        $p1['waiting_room'] = [$wr1, $wr2, $wr3];
        $p1['energy_zone'] = [['instance_id' => 'e0', 'active' => true]];
        $p1['live_zone'] = [[
            'instance_id' => 'live1',
            'card_type' => 'ライブ',
            'name_en' => 'Dummy Live',
            'live_slot' => 0,
        ]];

        $p2 = $this->emptyPlayer('p2', 'P2');

        $state = [
            'room_id' => 'ISSUE78PR',
            'status' => 'playing',
            'seq' => 1,
            'turn' => 1,
            'phase' => 'live_set',
            'first_player' => 'p1',
            'active_player' => 'p1',
            'live_attempt' => ['p1'],
            'log' => [],
            'players' => ['p1' => $p1, 'p2' => $p2],
        ];

        $GLOBALS['TUT_PERF_MANUAL_PHASES'] = true;
        try {
            $state = beginLiveStartEffectPhase($state, true, false);
            $this->assertSame('optional_live_start', $state['pending_prompt']['type'] ?? null);
            $this->assertSame('pr023', $state['pending_prompt']['source_id'] ?? null);

            $state = applyAction($state, 'p1', 'resolve_prompt', [
                'choice' => 'yes',
                'pay' => true,
            ]);
            $this->assertSame('pick_wr_members_deck_top', $state['pending_prompt']['type'] ?? null);
            $this->assertSame(2, intval($state['pending_prompt']['pick_count'] ?? 0));

            $state = applyAction($state, 'p1', 'resolve_prompt', [
                'card_ids' => ['wr2', 'wr1'],
            ]);
            $this->assertEmpty($state['pending_prompt'] ?? null);
            // Resolver reverses pick order so the last chosen id sits on deck top.
            $deckTop = array_slice($state['players']['p1']['main_deck'], 0, 2);
            $this->assertSame(['wr1', 'wr2'], array_column($deckTop, 'instance_id'));
            $wrIds = array_column($state['players']['p1']['waiting_room'], 'instance_id');
            $this->assertSame(['wr3'], $wrIds);
        } finally {
            unset($GLOBALS['TUT_PERF_MANUAL_PHASES']);
        }
    }
}
