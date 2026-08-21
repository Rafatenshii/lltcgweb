<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * PL!SP-bp4-011 Tomari — On Enter / area move must prompt when multiple
 * opponent Stage Members have ≤3 printed hearts (not auto-left).
 */
final class TomariBp4011WaitPickTest extends TestCase
{
    private function cardByNo(string $cardNo, string $instanceId): array
    {
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

    private function baseState(array $p1, array $p2): array
    {
        return [
            'room_id' => 'TOMARI4011',
            'status' => 'playing',
            'seq' => 1,
            'turn' => 1,
            'phase' => 'main_first',
            'first_player' => 'p1',
            'active_player' => 'p1',
            'log' => [],
            'players' => ['p1' => $p1, 'p2' => $p2],
        ];
    }

    public function testOnEnterOpensPickWhenMultipleLegalTargets(): void
    {
        $tomari = $this->cardByNo('PL!SP-bp4-011-P＋', 'tomari');
        $oppLeft = $this->cardByNo('PL!HS-sd1-015-SD', 'opp_left'); // 1 heart
        $oppRight = $this->cardByNo('PL!HS-bp5-008-R', 'opp_right'); // 1 heart

        $p1 = $this->emptyPlayer('p1', 'P1');
        $p1['hand'] = [$tomari];
        $p1['energy_zone'] = array_map(
            static fn (int $i): array => ['instance_id' => "e$i", 'active' => true],
            range(0, 9)
        );

        $p2 = $this->emptyPlayer('p2', 'P2');
        $p2['stage'] = [
            'left' => $oppLeft,
            'center' => null,
            'right' => $oppRight,
        ];

        $state = $this->baseState($p1, $p2);
        $state = applyAction($state, 'p1', 'play_member', [
            'card_id' => 'tomari',
            'slot' => 'center',
        ]);

        $this->assertSame('wait_opponent_stage_pick', $state['pending_prompt']['type'] ?? null);
        $this->assertSame('p1', $state['pending_prompt']['responder'] ?? null);
        $candSlots = array_column($state['pending_prompt']['candidates'] ?? [], 'slot');
        sort($candSlots);
        $this->assertSame(['left', 'right'], $candSlots);
        $this->assertFalse(memberIsInWait($state['players']['p2']['stage']['left']));
        $this->assertFalse(memberIsInWait($state['players']['p2']['stage']['right']));

        $state = applyAction($state, 'p1', 'resolve_prompt', [
            'slots' => ['right'],
        ]);
        $this->assertEmpty($state['pending_prompt'] ?? null);
        $this->assertFalse(memberIsInWait($state['players']['p2']['stage']['left']));
        $this->assertTrue(memberIsInWait($state['players']['p2']['stage']['right']));
    }

    public function testOnEnterAutoWaitsSingleLegalTarget(): void
    {
        $tomari = $this->cardByNo('PL!SP-bp4-011-P＋', 'tomari');
        $oppLeft = $this->cardByNo('PL!HS-sd1-015-SD', 'opp_left'); // 1 heart
        $oppHigh = $this->cardByNo('PL!SP-sd1-002-SD', 'opp_high'); // 3 hearts? wait — ≤3 still legal

        // Use a member with >3 printed hearts so only left qualifies.
        $oppHigh['hearts'] = [
            ['color' => 'red', 'count' => 2],
            ['color' => 'yellow', 'count' => 2],
        ];

        $p1 = $this->emptyPlayer('p1', 'P1');
        $p1['hand'] = [$tomari];
        $p1['energy_zone'] = array_map(
            static fn (int $i): array => ['instance_id' => "e$i", 'active' => true],
            range(0, 9)
        );

        $p2 = $this->emptyPlayer('p2', 'P2');
        $p2['stage'] = [
            'left' => $oppLeft,
            'center' => null,
            'right' => $oppHigh,
        ];

        $state = $this->baseState($p1, $p2);
        $state = applyAction($state, 'p1', 'play_member', [
            'card_id' => 'tomari',
            'slot' => 'center',
        ]);

        $this->assertNull($state['pending_prompt'] ?? null);
        $this->assertTrue(memberIsInWait($state['players']['p2']['stage']['left']));
        $this->assertFalse(memberIsInWait($state['players']['p2']['stage']['right']));
    }

    public function testAreaMoveOpensPickWhenMultipleLegalTargets(): void
    {
        $tomari = $this->cardByNo('PL!SP-bp4-011-P＋', 'tomari');
        $oppLeft = $this->cardByNo('PL!HS-sd1-015-SD', 'opp_left');
        $oppRight = $this->cardByNo('PL!HS-bp5-008-R', 'opp_right');

        $p1 = $this->emptyPlayer('p1', 'P1');
        $p1['stage'] = [
            'left' => null,
            'center' => null,
            'right' => $tomari,
        ];

        $p2 = $this->emptyPlayer('p2', 'P2');
        $p2['stage'] = [
            'left' => $oppLeft,
            'center' => null,
            'right' => $oppRight,
        ];

        $state = $this->baseState($p1, $p2);
        $state = resolveAutoAreaMoveAbilities($state, 'p1', 'tomari', 'left');

        $this->assertSame('wait_opponent_stage_pick', $state['pending_prompt']['type'] ?? null);
        $candSlots = array_column($state['pending_prompt']['candidates'] ?? [], 'slot');
        sort($candSlots);
        $this->assertSame(['left', 'right'], $candSlots);
        $this->assertFalse(memberIsInWait($state['players']['p2']['stage']['left']));
        $this->assertFalse(memberIsInWait($state['players']['p2']['stage']['right']));
    }
}
