<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * Karin PL!N-bp4-004 Live Start: Wait then WR Members to deck top.
 * CPU Hard/Expert used to send { card_id } for pick_count=1; server required card_ids[] → softlock.
 */
final class KarinLiveStartWrDeckTopTest extends TestCase
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
            'main_deck' => [
                ['instance_id' => $id . '_d1', 'card_type' => 'メンバー', 'group' => 'Nijigasaki'],
                ['instance_id' => $id . '_d2', 'card_type' => 'メンバー', 'group' => 'Nijigasaki'],
            ],
            'energy_deck' => [],
            'live_zone' => [],
            'success_lives' => [],
        ];
    }

    private function dummyLive(string $id): array
    {
        return [
            'instance_id' => $id,
            'card_type' => 'ライブ',
            'card_type_en' => 'Live',
            'group' => 'Nijigasaki',
            'name_en' => 'Dummy Live',
            'score' => 1,
            'required_hearts' => [['color' => 'any', 'count' => 1]],
            'abilities' => [],
        ];
    }

    private function nijiWr(string $id): array
    {
        return [
            'instance_id' => $id,
            'card_type' => 'メンバー',
            'card_type_en' => 'Member',
            'group' => 'Nijigasaki',
            'name_en' => 'WR Niji ' . $id,
            'cost' => 3,
            'blade' => 1,
        ];
    }

    public function testKarinLiveStartWaitThenWrDeckTopAcceptsCardIdPayload(): void
    {
        $karin = $this->cardByNo('PL!N-bp4-004-SEC', 'karin');
        $oppA = $this->cardByNo('PL!N-bp4-005-P', 'opp_a');
        $oppB = $this->cardByNo('PL!N-bp4-005-P', 'opp_b');
        // Force two distinct instance ids / costs under 9.
        $oppA['cost'] = 4;
        $oppB['cost'] = 5;
        $oppA['instance_id'] = 'opp_a';
        $oppB['instance_id'] = 'opp_b';

        $p1 = $this->emptyPlayer('p1', 'P1');
        $p1['stage']['center'] = $karin;
        $p1['waiting_room'] = [
            $this->nijiWr('wr1'),
            $this->nijiWr('wr2'),
        ];
        $p1['live_zone'] = [$this->dummyLive('p1live')];

        $p2 = $this->emptyPlayer('p2', 'P2');
        $p2['stage'] = [
            'left' => $oppA,
            'center' => $oppB,
            'right' => null,
        ];
        $p2['live_zone'] = [$this->dummyLive('p2live')];

        $state = [
            'room_id' => 'KARIN_LS',
            'status' => 'playing',
            'seq' => 1,
            'turn' => 3,
            'phase' => 'live_start_effects',
            'first_player' => 'p1',
            'active_player' => 'p1',
            'log' => [],
            'players' => ['p1' => $p1, 'p2' => $p2],
            '_live_start_perf_pid' => 'p1',
            '_live_start_done' => [],
            'live_attempt' => ['p1', 'p2'],
        ];

        $GLOBALS['TUT_PERF_MANUAL_PHASES'] = true;
        try {
            $state = \resolveLiveStartAbilities($state, 'p1');
            // Draw + Wait: with 2 eligible opp Members, Wait should prompt.
            $this->assertSame('wait_opponent_stage_pick', $state['pending_prompt']['type'] ?? null);
            $this->assertSame('p1', $state['pending_prompt']['responder'] ?? null);

            $state = applyAction($state, 'p1', 'resolve_prompt', ['slot' => 'left']);
            $this->assertTrue(\memberIsInWait($state['players']['p2']['stage']['left']));

            // Two WR Nijigasaki Members and need=1 → pick prompt (candidates > pick).
            $this->assertSame('pick_wr_members_deck_top', $state['pending_prompt']['type'] ?? null);
            $this->assertSame(1, intval($state['pending_prompt']['pick_count'] ?? 0));
            $this->assertTrue(!empty($state['pending_prompt']['up_to']));

            // Simulate buggy CPU Hard payload: single card_id (not card_ids).
            $state = applyAction($state, 'p1', 'resolve_prompt', ['card_id' => 'wr1']);
            $this->assertEmpty($state['pending_prompt'] ?? null);
            $this->assertSame('wr1', $state['players']['p1']['main_deck'][0]['instance_id'] ?? null);
            $wrIds = array_map(
                static fn($c) => $c['instance_id'] ?? '',
                $state['players']['p1']['waiting_room']
            );
            $this->assertNotContains('wr1', $wrIds);
            $this->assertContains('wr2', $wrIds);
        } finally {
            unset($GLOBALS['TUT_PERF_MANUAL_PHASES']);
        }
    }

    public function testPickWrMembersDeckTopUpToAllowsEmpty(): void
    {
        $p1 = $this->emptyPlayer('p1', 'P1');
        $p1['waiting_room'] = [$this->nijiWr('wr1'), $this->nijiWr('wr2')];
        $p2 = $this->emptyPlayer('p2', 'P2');

        $state = [
            'room_id' => 'KARIN_EMPTY',
            'status' => 'playing',
            'seq' => 1,
            'turn' => 1,
            'phase' => 'main_first',
            'first_player' => 'p1',
            'active_player' => 'p1',
            'log' => [],
            'players' => ['p1' => $p1, 'p2' => $p2],
            'pending_prompt' => [
                'type' => 'pick_wr_members_deck_top',
                'owner' => 'p1',
                'responder' => 'p1',
                'source_name' => 'Karin',
                'prompt' => 'Choose up to 1 Nijigasaki Member(s) from Waiting Room for deck top.',
                'candidates' => [
                    ['instance_id' => 'wr1', 'card_type' => 'メンバー'],
                    ['instance_id' => 'wr2', 'card_type' => 'メンバー'],
                ],
                'pick_count' => 1,
                'up_to' => true,
                'ability' => ['type' => 'pick_wr_members_deck_top_by_opp_wait'],
            ],
        ];

        $state = applyAction($state, 'p1', 'resolve_prompt', ['card_ids' => []]);
        $this->assertEmpty($state['pending_prompt'] ?? null);
        $this->assertCount(2, $state['players']['p1']['waiting_room']);
    }
}
