<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * Chika Takami PL!S-bp3-001-R＋ — Wait a Stage Member, grant +1 Live total score.
 */
final class ChikaBp3001WaitGrantLiveScoreTest extends TestCase
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

    public function testActivateOpensWaitPickIncludingSelf(): void
    {
        $chika = $this->cardByNo('PL!S-bp3-001-R＋', 'chika');
        $riko = $this->cardByNo('PL!S-bp3-002-R', 'riko');

        $p1 = $this->emptyPlayer('p1', 'P1');
        $p1['stage']['center'] = $chika;
        $p1['stage']['left'] = $riko;
        $p2 = $this->emptyPlayer('p2', 'P2');

        $state = [
            'room_id' => 'CHIKA001',
            'status' => 'playing',
            'seq' => 1,
            'phase' => 'main_first',
            'turn' => 1,
            'active_player' => 'p1',
            'players' => ['p1' => $p1, 'p2' => $p2],
            'log' => [],
        ];

        $after = applyAction($state, 'p1', 'activate_ability', [
            'card_id' => 'chika',
            'ability_index' => 0,
        ]);

        $this->assertSame('wait_pick_member_grant_live_score', $after['pending_prompt']['type'] ?? null);
        $slots = array_column($after['pending_prompt']['candidates'] ?? [], 'slot');
        $this->assertContains('center', $slots, 'Self must be a valid Wait target');
        $this->assertContains('left', $slots);
        foreach ($after['pending_prompt']['candidates'] as $cand) {
            $this->assertArrayHasKey('instance_id', $cand);
            $this->assertArrayHasKey('slot', $cand);
        }
    }

    public function testResolveWaitsTargetAndGrantsLiveScoreBonus(): void
    {
        $chika = $this->cardByNo('PL!S-bp3-001-R＋', 'chika');
        $riko = $this->cardByNo('PL!S-bp3-002-R', 'riko');

        $p1 = $this->emptyPlayer('p1', 'P1');
        $p1['stage']['center'] = $chika;
        $p1['stage']['left'] = $riko;
        $p2 = $this->emptyPlayer('p2', 'P2');

        $state = [
            'room_id' => 'CHIKA001B',
            'status' => 'playing',
            'seq' => 1,
            'phase' => 'main_first',
            'turn' => 1,
            'active_player' => 'p1',
            'players' => ['p1' => $p1, 'p2' => $p2],
            'log' => [],
            'pending_prompt' => [
                'type' => 'wait_pick_member_grant_live_score',
                'owner' => 'p1',
                'responder' => 'p1',
                'source_id' => 'chika',
                'source_name' => 'Chika Takami',
                'candidates' => [
                    array_merge(cardPromptSummary($chika), ['slot' => 'center']),
                    array_merge(cardPromptSummary($riko), ['slot' => 'left']),
                ],
                'amount' => 1,
            ],
        ];

        $after = applyAction($state, 'p1', 'resolve_prompt', ['slot' => 'left']);
        $this->assertArrayNotHasKey('pending_prompt', $after);
        $this->assertFalse($after['players']['p1']['stage']['left']['active'] ?? true);
        $this->assertSame(1, intval($after['players']['p1']['stage']['left']['live_score_bonus'] ?? 0));
    }

    public function testSoloChikaCanWaitSelf(): void
    {
        $chika = $this->cardByNo('PL!S-bp3-001-R＋', 'chika_solo');
        $p1 = $this->emptyPlayer('p1', 'P1');
        $p1['stage']['center'] = $chika;
        $p2 = $this->emptyPlayer('p2', 'P2');

        $state = [
            'room_id' => 'CHIKA001C',
            'status' => 'playing',
            'seq' => 1,
            'phase' => 'main_first',
            'turn' => 1,
            'active_player' => 'p1',
            'players' => ['p1' => $p1, 'p2' => $p2],
            'log' => [],
        ];

        $after = applyAction($state, 'p1', 'activate_ability', [
            'card_id' => 'chika_solo',
            'ability_index' => 0,
        ]);
        $this->assertSame('wait_pick_member_grant_live_score', $after['pending_prompt']['type'] ?? null);
        $this->assertSame(['center'], array_column($after['pending_prompt']['candidates'] ?? [], 'slot'));

        $done = applyAction($after, 'p1', 'resolve_prompt', ['slot' => 'center']);
        $this->assertFalse($done['players']['p1']['stage']['center']['active'] ?? true);
        $this->assertSame(1, intval($done['players']['p1']['stage']['center']['live_score_bonus'] ?? 0));
    }
}
