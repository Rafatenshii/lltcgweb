<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * Regression: GitHub issue #83 — Erena target Wait, Keke activate/blade, Chisato Live Start.
 */
final class Issue83WaitActiveTest extends TestCase
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

    private function bladelessMember(string $id): array
    {
        return [
            'instance_id' => $id,
            'card_type' => 'メンバー',
            'name_en' => 'Bladeless',
            'group' => 'Superstar',
            'blade' => null,
            'blade_hearts' => [],
            'hearts' => [['color' => 'pink', 'count' => 1]],
            'active' => true,
        ];
    }

    public function testErenaOptionalWaitOpensOpponentStagePick(): void
    {
        $erena = $this->cardByNo('PL!-bp5-333-R', 'erena');
        $oppA = $this->cardByNo('PL!HS-sd1-015-SD', 'opp_a');
        $oppB = $this->cardByNo('PL!HS-sd1-015-SD', 'opp_b');
        $oppA['cost'] = 5;
        $oppB['cost'] = 7;

        $p1 = $this->emptyPlayer('p1', 'P1');
        $p1['stage']['center'] = null;
        $p1['hand'] = [$erena];
        $p1['energy_zone'] = array_map(
            static fn(int $i): array => ['instance_id' => "e$i", 'active' => true],
            range(0, 10)
        );

        $p2 = $this->emptyPlayer('p2', 'P2');
        $p2['stage'] = ['left' => $oppA, 'center' => $oppB, 'right' => null];

        $state = [
            'room_id' => 'ISSUE83',
            'status' => 'playing',
            'seq' => 1,
            'turn' => 2,
            'phase' => 'main_first',
            'first_player' => 'p1',
            'active_player' => 'p1',
            'log' => [],
            'players' => ['p1' => $p1, 'p2' => $p2],
        ];

        $state = \applyAction($state, 'p1', 'play_member', [
            'card_id' => 'erena',
            'slot' => 'center',
        ]);
        $this->assertSame('optional_wait_self', $state['pending_prompt']['type'] ?? null);

        $state = \actionResolvePrompt($state, 'p1', ['choice' => 'yes']);
        $this->assertTrue(\memberIsInWait($state['players']['p1']['stage']['center']));
        $this->assertSame('wait_opponent_stage_pick', $state['pending_prompt']['type'] ?? null);
        $this->assertGreaterThanOrEqual(2, count($state['pending_prompt']['candidates'] ?? []));

        $state = \actionResolvePrompt($state, 'p1', ['slot' => 'center']);
        $this->assertTrue(\memberIsInWait($state['players']['p2']['stage']['center']));
        $this->assertFalse(\memberIsInWait($state['players']['p2']['stage']['left'] ?? []));
    }

    public function testKekeDiscardBladelessActivatesAndGrantsBlade(): void
    {
        $keke = $this->cardByNo('PL!SP-bp5-002-R＋', 'keke');
        $b1 = $this->bladelessMember('bl1');
        $b2 = $this->bladelessMember('bl2');
        $filler = $this->bladelessMember('fill');

        $p1 = $this->emptyPlayer('p1', 'P1');
        $p1['stage']['left'] = $keke;
        $p1['hand'] = [];
        $p1['main_deck'] = [$b1, $b2, $filler];

        $state = [
            'room_id' => 'ISSUE83K',
            'status' => 'playing',
            'seq' => 1,
            'turn' => 2,
            'phase' => 'main_first',
            'first_player' => 'p1',
            'active_player' => 'p1',
            'log' => [],
            'players' => [
                'p1' => $p1,
                'p2' => $this->emptyPlayer('p2', 'P2'),
            ],
        ];

        $state = \actionActivateAbility($state, 'p1', [
            'card_id' => 'keke',
            'ability_index' => 0,
        ]);
        $this->assertTrue(\memberIsInWait($state['players']['p1']['stage']['left']));
        $this->assertSame('spbp5_wait_draw_discard', $state['pending_prompt']['type'] ?? null);
        $this->assertCount(3, $state['players']['p1']['hand']);

        $state = \actionResolvePrompt($state, 'p1', [
            'discard_ids' => ['bl1', 'bl2'],
        ]);

        $after = $state['players']['p1']['stage']['left'];
        $this->assertFalse(\memberIsInWait($after));
        $this->assertTrue($after['active'] ?? false);
        $this->assertSame(2, intval($after['live_blade_bonus'] ?? 0));
        $this->assertSame(0, \getStageBladeBonus($state, 'p1'));
    }

    public function testChisatoLiveStartActivatesWaitedGroupAndEnergy(): void
    {
        $chisato = $this->cardByNo('PL!SP-bp5-003-R＋', 'chisato');
        $mate = $this->cardByNo('PL!SP-bp5-002-R＋', 'mate');
        $mate['group'] = 'Superstar';

        $p1 = $this->emptyPlayer('p1', 'P1');
        $p1['stage']['center'] = $chisato;
        $p1['stage']['left'] = $mate;
        $p1['energy_zone'] = [
            ['instance_id' => 'en1', 'active' => false],
            ['instance_id' => 'en2', 'active' => false],
        ];

        $state = [
            'room_id' => 'ISSUE83C',
            'status' => 'playing',
            'seq' => 1,
            'turn' => 2,
            'phase' => 'live_start_effects',
            'first_player' => 'p1',
            'active_player' => 'p1',
            'log' => [],
            'players' => [
                'p1' => $p1,
                'p2' => $this->emptyPlayer('p2', 'P2'),
            ],
        ];
        \waitMember($state['players']['p1']['stage']['left'], $state);
        \waitMember($state['players']['p1']['stage']['center'], $state);
        $this->assertTrue(\memberIsInWait($state['players']['p1']['stage']['left']));
        $this->assertTrue(\memberIsInWait($state['players']['p1']['stage']['center']));

        $state = \resolveLiveStartAbilities($state, 'p1');

        $this->assertFalse(\memberIsInWait($state['players']['p1']['stage']['left']));
        $this->assertFalse(\memberIsInWait($state['players']['p1']['stage']['center']));
        $this->assertTrue($state['players']['p1']['stage']['left']['active'] ?? false);
        $this->assertTrue($state['players']['p1']['energy_zone'][0]['active'] ?? false);
        $this->assertTrue($state['players']['p1']['energy_zone'][1]['active'] ?? false);
    }
}
