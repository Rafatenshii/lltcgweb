<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/** Regression: GitHub #99 — BP07 Nijigasaki card text/engine mistakes. */
final class Issue99Bp7CardFixesTest extends TestCase
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

    private function energy(string $id, bool $active = true): array
    {
        return [
            'instance_id' => $id,
            'card_type' => 'エネルギー',
            'card_type_en' => 'Energy',
            'name_en' => 'Energy',
            'active' => $active,
        ];
    }

    private function baseState(string $phase = 'main_first'): array
    {
        return [
            'status' => 'playing',
            'phase' => $phase,
            'seq' => 1,
            'turn' => 2,
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
                    'energy_deck' => [],
                    'main_deck' => [],
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
                    'energy_deck' => [],
                    'main_deck' => [],
                    'success_lives' => [],
                    'live_zone' => [],
                ],
            ],
        ];
    }

    public function testBladelessMeansNoBladeHeartsNotPrintedBladeZero(): void
    {
        $kanata = $this->cardByNo('PL!N-bp7-006-P', 'issue99_kanata');
        $this->assertSame(5, intval($kanata['blade'] ?? 0));
        $this->assertTrue(\bp7IsBladelessMember($kanata), 'Printed Blade 5 + empty blade_hearts is still “no Blade hearts”');

        $withHearts = $kanata;
        $withHearts['blade_hearts'] = ['pink'];
        $this->assertFalse(\bp7IsBladelessMember($withHearts));
    }

    public function testKanata018LookTop5AfterOptionalDiscard(): void
    {
        $kanata = $this->cardByNo('PL!N-bp7-018-N', 'issue99_k18');
        $hit = $this->cardByNo('PL!N-bp7-006-P', 'issue99_hit');
        $miss = $this->cardByNo('PL!N-bp7-001-P', 'issue99_miss');

        $state = $this->baseState();
        $state['players']['p1']['stage']['center'] = $kanata;
        $state['players']['p1']['hand'] = [
            ['instance_id' => 'h_disc', 'card_type' => 'メンバー', 'name_en' => 'Fodder'],
        ];
        $state['players']['p1']['main_deck'] = [
            $hit,
            $miss,
            ['instance_id' => 'd3', 'card_type' => 'ライブ', 'group' => 'Nijigasaki'],
            ['instance_id' => 'd4', 'card_type' => 'エネルギー'],
            ['instance_id' => 'd5', 'card_type' => 'エネルギー'],
        ];

        $state = \resolveAbilityEffect($state, 'p1', $kanata, $kanata['abilities'][0], [
            'phase' => 'on_enter',
            'slot' => 'center',
        ]);
        $this->assertSame('optional_discard_prompt', $state['pending_prompt']['type'] ?? null);

        $state = \actionResolvePrompt($state, 'p1', [
            'choice' => 'yes',
            'discard_ids' => ['h_disc'],
        ]);
        $this->assertSame('bp7_pick_cards', $state['pending_prompt']['type'] ?? null);
        $candIds = array_column($state['pending_prompt']['candidates'] ?? [], 'instance_id');
        $this->assertContains('issue99_hit', $candIds);
        $this->assertNotContains('issue99_miss', $candIds);
    }

    public function testColorfulDreamsLiveStartAsksWhichNijiMember(): void
    {
        $live = $this->cardByNo('PL!N-bp7-025-SECL', 'issue99_cd');
        $this->assertSame('live_start_pick_group_member_blade', $live['abilities'][0]['type'] ?? null);

        $a = $this->cardByNo('PL!N-bp7-001-P', 'issue99_a');
        $b = $this->cardByNo('PL!N-bp7-004-P', 'issue99_b');

        $state = $this->baseState('live_start_effects');
        $state['players']['p1']['live_zone'] = [$live];
        $state['players']['p1']['stage']['left'] = $a;
        $state['players']['p1']['stage']['center'] = $b;

        $state = \resolveLiveStartAbilities($state, 'p1');
        $this->assertSame('bp7_pick_stage_member', $state['pending_prompt']['type'] ?? null);
        $this->assertSame('give_group_member_blade', $state['pending_prompt']['bp7_action'] ?? null);

        $state = \actionResolvePrompt($state, 'p1', [
            'choice' => 'issue99_b',
            'card_id' => 'issue99_b',
        ]);
        $msgs = array_map(static fn($e) => (string)($e['msg'] ?? ''), $state['log'] ?? []);
        $this->assertTrue(
            (bool) array_filter($msgs, static fn($m) => str_contains($m, 'gained +1 Blade')),
            'Choosing Karin should grant +1 Blade before Yell'
        );
    }

    public function testShizukuStackedMemberGoesToWaitingRoomOnBaton(): void
    {
        $shizuku = $this->cardByNo('PL!N-bp7-003-P', 'issue99_shizuku');
        $underIds = [];
        $stacked = [];
        for ($i = 1; $i <= 6; $i++) {
            $id = 'issue99_under_' . $i;
            $underIds[] = $id;
            $stacked[] = $this->cardByNo('PL!N-bp7-001-P', $id);
        }
        $incoming = $this->cardByNo('PL!N-bp7-004-P', 'issue99_in');
        $shizuku['stacked_members'] = $stacked;

        $state = $this->baseState();
        $state['players']['p1']['stage']['center'] = $shizuku;
        $state['players']['p1']['main_deck'] = [['instance_id' => 'deck_pad']];
        $state['players']['p1']['hand'] = [$incoming];
        $state['players']['p1']['waiting_room'] = [
            ['instance_id' => 'wr_pad', 'card_type' => 'ライブ'],
        ];
        $wrBefore = count($state['players']['p1']['waiting_room']);
        $state['players']['p1']['energy_zone'] = [
            $this->energy('e1'), $this->energy('e2'), $this->energy('e3'),
            $this->energy('e4'), $this->energy('e5'), $this->energy('e6'),
            $this->energy('e7'), $this->energy('e8'), $this->energy('e9'),
            $this->energy('e10'), $this->energy('e11'), $this->energy('e12'),
            $this->energy('e13'),
        ];

        $state = \actionPlayMember($state, 'p1', [
            'card_id' => 'issue99_in',
            'slot' => 'center',
            'baton_id' => 'issue99_shizuku',
        ]);

        $wr = $state['players']['p1']['waiting_room'] ?? [];
        $wrIds = array_column($wr, 'instance_id');
        $this->assertCount($wrBefore + 7, $wr, 'Host + 6 stacked Members must each become WR cards');
        $this->assertContains('issue99_shizuku', $wrIds);
        foreach ($underIds as $id) {
            $this->assertContains($id, $wrIds, 'Stacked Member ' . $id . ' must not vanish');
        }
        foreach ($wr as $c) {
            $this->assertEmpty($c['stacked_members'] ?? []);
        }
    }

    public function testRinaLookSkipMillsMiaAutoRecover(): void
    {
        $rina = $this->cardByNo('PL!N-bp5-009-R', 'issue99_rina');
        $mia = $this->cardByNo('PL!N-bp7-011-P', 'issue99_mia_mill');
        $other = $this->cardByNo('PL!N-bp7-006-P', 'issue99_other_hi');
        $state = $this->baseState();
        $state['players']['p1']['stage']['center'] = $rina;
        $state['players']['p1']['hand'] = [
            ['instance_id' => 'h_disc', 'card_type' => 'メンバー', 'name_en' => 'Fodder'],
            ['instance_id' => 'h_keep', 'card_type' => 'メンバー', 'name_en' => 'Keep'],
        ];
        $state['players']['p1']['main_deck'] = [
            $mia,
            $other,
            ['instance_id' => 'd3', 'card_type' => 'エネルギー'],
            ['instance_id' => 'd4', 'card_type' => 'エネルギー'],
            ['instance_id' => 'd5', 'card_type' => 'エネルギー'],
        ];

        $state = \resolveAbilityEffect($state, 'p1', $rina, $rina['abilities'][0], [
            'phase' => 'on_enter',
            'slot' => 'center',
        ]);
        $this->assertSame('bp5_wait_discard_look_reveal', $state['pending_prompt']['type'] ?? null);

        $state = \actionResolvePrompt($state, 'p1', ['choice' => 'yes']);
        $state = \actionResolvePrompt($state, 'p1', [
            'choice' => 'ok',
            'discard_ids' => ['h_disc'],
        ]);
        $this->assertSame('bp5_wait_discard_look_reveal', $state['pending_prompt']['type'] ?? null);
        $this->assertSame('pick', $state['pending_prompt']['step'] ?? null);

        $state = \actionResolvePrompt($state, 'p1', ['choice' => 'skip']);
        $this->assertSame('bp7_confirm', $state['pending_prompt']['type'] ?? null);
        $this->assertSame('self_milled_recover', $state['pending_prompt']['bp7_action'] ?? null);
        $wrIds = array_column($state['players']['p1']['waiting_room'] ?? [], 'instance_id');
        $this->assertContains('issue99_mia_mill', $wrIds);
    }

    public function testKarinPicksWhichEnergyThenWaitsByStackedPlusOne(): void
    {
        $karin = $this->cardByNo('PL!N-bp7-004-P', 'issue99_karin');
        $oppLo = $this->cardByNo('PL!N-bp7-022-N', 'issue99_opp_lo');
        $oppMid = $this->cardByNo('PL!N-bp7-010-P', 'issue99_opp_mid');
        $oppHi = $this->cardByNo('PL!N-bp7-006-P', 'issue99_opp_hi');

        $state = $this->baseState();
        $state['players']['p1']['stage']['center'] = $karin;
        $state['players']['p1']['energy_zone'] = [$this->energy('ez1'), $this->energy('ez2'), $this->energy('ez3')];
        $state['players']['p2']['stage']['left'] = $oppLo;
        $state['players']['p2']['stage']['right'] = $oppMid;
        $state['players']['p2']['stage']['center'] = $oppHi;

        $state = \actionActivateAbility($state, 'p1', [
            'card_id' => 'issue99_karin',
            'ability_index' => 0,
        ]);
        $this->assertSame('stack_energy_zone_pick', $state['pending_prompt']['type'] ?? null);

        $state = \actionResolvePrompt($state, 'p1', [
            'choice' => 'ez2',
            'energy_ids' => ['ez2'],
        ]);
        $this->assertSame('wait_opponent_stage_pick', $state['pending_prompt']['type'] ?? null);
        $this->assertSame(2, intval($state['pending_prompt']['max_original_blade'] ?? 0));
        $candIds = array_column($state['pending_prompt']['candidates'] ?? [], 'instance_id');
        $this->assertContains('issue99_opp_lo', $candIds);
        $this->assertContains('issue99_opp_mid', $candIds);
        $this->assertNotContains('issue99_opp_hi', $candIds, 'Original Blade 5 > stacked 1 + 1');
    }

    public function testShioriko022PromptsWhenAllyWaitsDuringLiveStart(): void
    {
        $shio = $this->cardByNo('PL!N-bp7-022-N', 'issue99_shio22');
        $ally = $this->cardByNo('PL!N-bp7-001-P', 'issue99_ally');

        $state = $this->baseState('live_start_effects');
        $state['players']['p1']['stage']['center'] = $shio;
        $state['players']['p1']['stage']['left'] = $ally;
        $state['players']['p1']['hand'] = [
            ['instance_id' => 'h1', 'card_type' => 'メンバー', 'name_en' => 'Fodder'],
        ];

        waitMember($state['players']['p1']['stage']['left'], $state);
        $this->assertTrue(!empty($state['players']['p1']['stage']['left']['_ally_wait_pending']));

        $state = \bp7FlushPendingAllyWaits($state);
        $this->assertSame('bp7_confirm', $state['pending_prompt']['type'] ?? null);
        $this->assertSame('ally_wait_activate', $state['pending_prompt']['bp7_action'] ?? null);
    }

    public function testTwinAyumuBothPutEnergyToWait(): void
    {
        $a1 = $this->cardByNo('PL!N-bp7-001-P', 'issue99_ay1');
        $a2 = $this->cardByNo('PL!N-bp7-001-R', 'issue99_ay2');

        $state = $this->baseState();
        $state['players']['p1']['stage']['left'] = $a1;
        $state['players']['p1']['stage']['right'] = $a2;
        $state['players']['p1']['energy_deck'] = [$this->energy('ed1', false), $this->energy('ed2', false)];
        $state['players']['p1']['energy_zone'] = [$this->energy('ez_stack')];

        $host = $this->cardByNo('PL!N-bp7-010-P', 'issue99_host');
        $state['players']['p1']['stage']['center'] = $host;
        $slot = 'center';
        $moved = \bp7ZoneEnergyUnderMember($state, 'p1', $slot, 1);
        $this->assertSame(1, $moved);

        $zoneIds = array_column($state['players']['p1']['energy_zone'] ?? [], 'instance_id');
        $this->assertContains('ed1', $zoneIds);
        $this->assertContains('ed2', $zoneIds);
        $this->assertCount(0, $state['players']['p1']['energy_deck']);
    }

    public function testMiaShuffleWrMembersReducesPlayCost(): void
    {
        $mia = $this->cardByNo('PL!N-bp7-011-P', 'issue99_mia');
        $wrM = $this->cardByNo('PL!N-bp7-022-N', 'issue99_wr_m');
        $wrLive = [
            'instance_id' => 'issue99_wr_live',
            'card_type' => 'ライブ',
            'card_type_en' => 'Live',
            'group' => 'Nijigasaki',
            'name_en' => 'Niji Live',
        ];

        $state = $this->baseState();
        $state['players']['p1']['hand'] = [$mia];
        $state['players']['p1']['waiting_room'] = [$wrM, $wrLive];
        $state['players']['p1']['main_deck'] = [['instance_id' => 'pad']];
        for ($i = 0; $i < 11; $i++) {
            $state['players']['p1']['energy_zone'][] = $this->energy('pay' . $i);
        }

        $state = \actionPlayMember($state, 'p1', [
            'card_id' => 'issue99_mia',
            'slot' => 'center',
            'bp7_shuffle_wr_members' => true,
        ]);

        $this->assertSame('issue99_mia', $state['players']['p1']['stage']['center']['instance_id'] ?? null);
        $wrIds = array_column($state['players']['p1']['waiting_room'] ?? [], 'instance_id');
        $this->assertNotContains('issue99_wr_m', $wrIds);
        $this->assertContains('issue99_wr_live', $wrIds);
        $this->assertSame('issue99_wr_m', $state['players']['p1']['main_deck'][count($state['players']['p1']['main_deck']) - 1]['instance_id'] ?? null);
        $this->assertCount(0, array_filter(
            $state['players']['p1']['energy_zone'],
            static fn($e) => !empty($e['active'])
        ), 'Cost 13 − 2 = 11 Energy paid');
    }

    public function testSetsuna019BatonStacksEnergyDeckUnderIncoming(): void
    {
        $setsu = $this->cardByNo('PL!N-bp7-019-N', 'bp7_019');
        $setsu['entered_turn'] = 1;
        $incoming = $this->cardByNo('PL!N-bp7-007-P', 'bp7_in');
        $state = $this->baseState();
        $state['turn'] = 4;
        $state['players']['p1']['stage']['center'] = $setsu;
        $state['players']['p1']['hand'] = [$incoming];
        $state['players']['p1']['main_deck'] = [['instance_id' => 'deck_pad', 'card_type' => 'ライブ']];
        $state['players']['p1']['energy_deck'] = [
            $this->energy('ed1'),
            $this->energy('ed2'),
        ];
        for ($i = 1; $i <= 12; $i++) {
            $state['players']['p1']['energy_zone'][] = $this->energy('ez' . $i);
        }

        $state = \actionPlayMember($state, 'p1', [
            'card_id' => 'bp7_in',
            'slot' => 'center',
            'baton_id' => 'bp7_019',
        ]);

        $onStage = $state['players']['p1']['stage']['center'] ?? null;
        $this->assertSame('bp7_in', $onStage['instance_id'] ?? null);
        $stacked = $onStage['stacked_energy'] ?? [];
        $this->assertCount(1, $stacked, '019 must put 1 Energy from the Energy deck under the Baton incoming');
        $this->assertSame('ed1', $stacked[0]['instance_id'] ?? null);
        $this->assertSame(['ed2'], array_column($state['players']['p1']['energy_deck'] ?? [], 'instance_id'));
        $this->assertContains('bp7_019', array_column($state['players']['p1']['waiting_room'] ?? [], 'instance_id'));
        $msgs = array_map(static fn($e) => (string)($e['msg'] ?? ''), $state['log'] ?? []);
        $this->assertTrue(
            (bool) array_filter($msgs, static fn($m) => str_contains($m, 'Energy card') && str_contains($m, 'Baton Touch')),
            'Leave ability should log the Energy-deck stack'
        );
    }
}
