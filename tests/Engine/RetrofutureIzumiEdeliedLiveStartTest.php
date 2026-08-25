<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * Retrofuture WR summon → Izumi On Enter declined must not skip Edelied Live Start.
 */
final class RetrofutureIzumiEdeliedLiveStartTest extends TestCase
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
            'main_deck' => array_fill(0, 10, ['instance_id' => 'deck_filler', 'card_type' => 'エネルギー']),
            'energy_deck' => [],
            'live_zone' => [],
            'success_lives' => [],
        ];
    }

    public function testDecliningIzumiOnEnterStillOpensEdeliedLiveStart(): void
    {
        $retro = $this->cardByNo('PL!HS-bp5-022-L', 'retro');
        $retro['live_slot'] = 0;
        $edelied = $this->cardByNo('PL!HS-pb1-030-L', 'edelied');
        $edelied['live_slot'] = 1;
        $izumi9 = $this->cardByNo('PL!HS-pb1-007-R', 'izumi9');
        $izumiWr = $this->cardByNo('PL!HS-bp5-008-R', 'izumi_wr');

        $p1 = $this->emptyPlayer('p1', 'P1');
        $p1['stage']['center'] = $izumi9;
        $p1['waiting_room'] = [$izumiWr];
        $p1['live_zone'] = [$edelied, $retro];
        $p1['energy_zone'] = array_map(
            static fn(int $i): array => ['instance_id' => "e$i", 'active' => true],
            range(0, 5)
        );

        $state = [
            'room_id' => 'RETROIZUMI',
            'status' => 'playing',
            'seq' => 1,
            'turn' => 2,
            'phase' => 'live_set',
            'first_player' => 'p1',
            'active_player' => 'p1',
            'live_attempt' => ['p1'],
            'log' => [],
            'players' => [
                'p1' => $p1,
                'p2' => $this->emptyPlayer('p2', 'P2'),
            ],
        ];

        $GLOBALS['TUT_PERF_MANUAL_PHASES'] = true;
        try {
            $state = \beginLiveStartEffectPhase($state, true, false);
            if (($state['pending_prompt']['type'] ?? '') === 'live_start_order_sources') {
                $orderIds = array_column($state['pending_prompt']['candidates'] ?? [], 'instance_id');
                $state = \applyAction($state, 'p1', 'resolve_prompt', ['card_ids' => $orderIds]);
            }
            $this->assertSame('optional_live_start', $state['pending_prompt']['type'] ?? null);
            $this->assertSame('retro', $state['pending_prompt']['source_id'] ?? null);

            $state = \applyAction($state, 'p1', 'resolve_prompt', [
                'choice' => 'yes',
                'pay' => true,
            ]);
            $this->assertSame('live_start_edel_choice', $state['pending_prompt']['type'] ?? null);

            $state = \applyAction($state, 'p1', 'resolve_prompt', ['choice' => 'play']);
            $this->assertSame('live_start_edel_play_wr', $state['pending_prompt']['type'] ?? null);

            $state = \applyAction($state, 'p1', 'resolve_prompt', ['card_id' => 'izumi_wr']);
            $this->assertSame(
                'optional_wait_self_look_reveal',
                $state['pending_prompt']['type'] ?? null,
                'Izumi On Enter should open before Edelied Live Start'
            );

            $state = \applyAction($state, 'p1', 'resolve_prompt', ['choice' => 'no']);
            $this->assertSame(
                'live_start_edel_note_dual_pick_buff',
                $state['pending_prompt']['type'] ?? null,
                'Edelied Live Start must still resolve after declining Izumi On Enter'
            );
            $this->assertSame('Edelied', $state['pending_prompt']['source_name'] ?? null);
        } finally {
            unset($GLOBALS['TUT_PERF_MANUAL_PHASES']);
        }
    }
}
