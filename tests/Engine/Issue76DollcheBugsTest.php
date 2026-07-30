<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/** GitHub #76 — Dollche / Hasunosora ability bugs. */
final class Issue76DollcheBugsTest extends TestCase
{
    private function cardByNo(string $cardNo, string $instanceId): array
    {
        $data = json_decode((string) file_get_contents((string) constant('CARDS_FILE')), true);
        foreach ($data['cards'] ?? [] as $card) {
            if (($card['card_no'] ?? '') === $cardNo) {
                $card['instance_id'] = $instanceId;
                $card['active'] = true;
                return $card;
            }
        }
        $this->fail('Missing card ' . $cardNo);
    }

    public function testSayakaStackIsActivatedAndRaisesCost(): void
    {
        $sayaka = $this->cardByNo('PL!HS-pb1-002-R', 'sayaka');
        $this->assertSame('activated', $sayaka['abilities'][0]['trigger'] ?? null);

        $other = $this->cardByNo('PL!HS-bp1-004-P', 'other_sayaka_name');
        // Force name match for stack filter (any Murano Sayaka).
        $other['name'] = '村野さやか';
        $other['name_en'] = 'Sayaka Murano';
        $other['card_type'] = 'メンバー';

        $state = [
            'status' => 'playing',
            'phase' => 'main_first',
            'seq' => 1,
            'turn' => 2,
            'first_player' => 'p1',
            'active_player' => 'p1',
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [$other],
                    'stage' => ['left' => null, 'center' => $sayaka, 'right' => null],
                    'waiting_room' => [],
                    'energy_zone' => [],
                    'main_deck' => [],
                    'live_zone' => [],
                    'success_lives' => [],
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'P2',
                    'hand' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'waiting_room' => [],
                    'energy_zone' => [],
                    'main_deck' => [],
                    'live_zone' => [],
                    'success_lives' => [],
                ],
            ],
        ];

        $state = \actionActivateAbility($state, 'p1', [
            'card_id' => 'sayaka',
            'ability_index' => 0,
        ]);
        $this->assertSame('reveal_hand_named_stack_under', $state['pending_prompt']['type'] ?? null);

        $state = \actionResolvePrompt($state, 'p1', ['card_id' => 'other_sayaka_name']);
        $this->assertNull($state['pending_prompt'] ?? null);
        $stacked = $state['players']['p1']['stage']['center']['stacked_members'] ?? [];
        $this->assertCount(1, $stacked);
        $this->assertSame('other_sayaka_name', $stacked[0]['instance_id'] ?? null);
        $this->assertSame(
            2,
            \getEffectiveStageMemberCost($state, 'p1', $state['players']['p1']['stage']['center']),
            'Stacking alone does not raise cost until Live Start (#79)'
        );
        $this->assertEmpty($state['players']['p1']['hand']);

        $GLOBALS['TUT_PERF_MANUAL_PHASES'] = true;
        try {
            $state['phase'] = 'live_start_effects';
            $state['live_attempt'] = ['p1'];
            $state = \resolveLiveStartAbilities($state, 'p1');
            $this->assertNull($state['pending_prompt'] ?? null);
            $this->assertSame(
                6,
                \getEffectiveStageMemberCost($state, 'p1', $state['players']['p1']['stage']['center']),
                'Live Start: base 2 + 4 per stacked = 6'
            );
        } finally {
            unset($GLOBALS['TUT_PERF_MANUAL_PHASES']);
        }
    }

    public function testBlueMomentSurplusUsesExcessHeartsKey(): void
    {
        $live = $this->cardByNo('PL!HS-bp6-028-L', 'blue_moment');
        $state = [
            'status' => 'playing',
            'phase' => 'live_success_effects',
            'seq' => 1,
            'turn' => 3,
            'first_player' => 'p1',
            'active_player' => 'p1',
            'log' => [],
            '_live_excess_hearts' => ['p1' => 2, 'p2' => 0],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'waiting_room' => [],
                    'energy_zone' => [],
                    'main_deck' => [
                        ['instance_id' => 'd1', 'card_type' => 'メンバー'],
                        ['instance_id' => 'd2', 'card_type' => 'メンバー'],
                    ],
                    'live_zone' => [$live],
                    'success_lives' => [],
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'P2',
                    'hand' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'waiting_room' => [],
                    'energy_zone' => [],
                    'main_deck' => [],
                    'live_zone' => [],
                    'success_lives' => [],
                ],
            ],
        ];
        $ab = $live['abilities'][0];
        $state = \hsResolveHasunosoraEffect($state, 'p1', $live, $ab, [
            'excess_hearts' => 2,
            'phase' => 'live_success',
        ]);
        $this->assertSame('surveil_arrange', $state['pending_prompt']['type'] ?? null);
    }

    public function testKosuzuDiscardOpensStageSubunitPick(): void
    {
        $kosuzu = $this->cardByNo('PL!HS-bp5-005-R', 'kosuzu');
        $doll = $this->cardByNo('PL!HS-bp1-004-P', 'doll_on_stage');
        $doll['subunit'] = 'DOLLCHESTRA';
        $handDoll = [
            'instance_id' => 'hand_doll',
            'card_type' => 'メンバー',
            'group' => 'Hasunosora',
            'subunit' => 'DOLLCHESTRA',
            'name_en' => 'Hand Doll',
        ];
        $state = [
            'status' => 'playing',
            'phase' => 'live_start_effects',
            'seq' => 1,
            'turn' => 2,
            'first_player' => 'p1',
            'active_player' => 'p1',
            'live_attempt' => ['p1'],
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [$handDoll],
                    'stage' => ['left' => null, 'center' => $kosuzu, 'right' => $doll],
                    'waiting_room' => [],
                    'energy_zone' => [],
                    'main_deck' => [],
                    'live_zone' => [[
                        'instance_id' => 'lz',
                        'card_type' => 'ライブ',
                        'card_type_en' => 'Live',
                        'score' => 1,
                        'required_hearts' => [['color' => 'any', 'count' => 1]],
                    ]],
                    'success_lives' => [],
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'P2',
                    'hand' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'waiting_room' => [],
                    'energy_zone' => [],
                    'main_deck' => [],
                    'live_zone' => [],
                    'success_lives' => [],
                ],
            ],
        ];
        $GLOBALS['TUT_PERF_MANUAL_PHASES'] = true;
        try {
            $state = \resolveAbilityEffect($state, 'p1', $kosuzu, $kosuzu['abilities'][0], [
                'phase' => 'live_start',
                'slot' => 'center',
            ]);
            $this->assertSame('optional_discard_prompt', $state['pending_prompt']['type'] ?? null);
            $state = \actionResolvePrompt($state, 'p1', [
                'choice' => 'yes',
                'discard_ids' => ['hand_doll'],
            ]);
            $this->assertSame('live_cost_from_subunit_pick', $state['pending_prompt']['type'] ?? null);
            $this->assertNotEmpty($state['pending_prompt']['candidates'] ?? []);
        } finally {
            unset($GLOBALS['TUT_PERF_MANUAL_PHASES']);
        }
    }

    public function testLinkToFutureDiscardOpensBladePick(): void
    {
        $live = $this->cardByNo('PL!HS-sd1-020-SD', 'link_live');
        $member = [
            'instance_id' => 'hs_m',
            'card_type' => 'メンバー',
            'group' => 'Hasunosora',
            'name_en' => 'HS',
            'blade' => 1,
            'active' => true,
        ];
        $hand1 = [
            'instance_id' => 'h1',
            'card_type' => 'メンバー',
            'group' => 'Hasunosora',
            'name_en' => 'H1',
        ];
        $hand2 = [
            'instance_id' => 'h2',
            'card_type' => 'メンバー',
            'group' => 'Hasunosora',
            'name_en' => 'H2',
        ];
        $state = [
            'status' => 'playing',
            'phase' => 'live_start_effects',
            'seq' => 1,
            'turn' => 2,
            'first_player' => 'p1',
            'active_player' => 'p1',
            'live_attempt' => ['p1'],
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [$hand1, $hand2],
                    'stage' => [
                        'left' => $member,
                        'center' => array_merge($member, ['instance_id' => 'hs_m2', 'name_en' => 'HS2']),
                        'right' => null,
                    ],
                    'waiting_room' => [],
                    'energy_zone' => [],
                    'main_deck' => [],
                    'live_zone' => [$live],
                    'success_lives' => [],
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'P2',
                    'hand' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'waiting_room' => [],
                    'energy_zone' => [],
                    'main_deck' => [],
                    'live_zone' => [],
                    'success_lives' => [],
                ],
            ],
        ];
        $GLOBALS['TUT_PERF_MANUAL_PHASES'] = true;
        try {
            $ab = $live['abilities'][1];
            $state = \resolveAbilityEffect($state, 'p1', $live, $ab, ['phase' => 'live_start']);
            $this->assertSame('optional_discard_prompt', $state['pending_prompt']['type'] ?? null);
            $state = \actionResolvePrompt($state, 'p1', [
                'choice' => 'yes',
                'discard_ids' => ['h1', 'h2'],
            ]);
            $this->assertSame('blade_per_discarded_pick_member', $state['pending_prompt']['type'] ?? null);
        } finally {
            unset($GLOBALS['TUT_PERF_MANUAL_PHASES']);
        }
    }

    public function testKotoriDiaKosuzuLookAddsTwoToHand(): void
    {
        $trio = $this->cardByNo('LL-bp6-001-R＋', 'trio');
        $deck = [];
        for ($i = 1; $i <= 6; $i++) {
            $deck[] = [
                'instance_id' => "d$i",
                'card_type' => 'メンバー',
                'group' => "μ's",
                'name_en' => "D$i",
            ];
        }
        $state = [
            'status' => 'playing',
            'phase' => 'main_first',
            'seq' => 1,
            'turn' => 1,
            'first_player' => 'p1',
            'active_player' => 'p1',
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [],
                    'stage' => ['left' => null, 'center' => $trio, 'right' => null],
                    'waiting_room' => [],
                    'energy_zone' => [],
                    'main_deck' => $deck,
                    'live_zone' => [],
                    'success_lives' => [],
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'P2',
                    'hand' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'waiting_room' => [],
                    'energy_zone' => [],
                    'main_deck' => [],
                    'live_zone' => [],
                    'success_lives' => [],
                ],
            ],
        ];
        $state = \resolveAbilityEffect($state, 'p1', $trio, $trio['abilities'][0], [
            'phase' => 'on_enter',
            'slot' => 'center',
        ]);
        $this->assertSame('pick_looked_deck_hand', $state['pending_prompt']['type'] ?? null);
        $this->assertSame(2, intval($state['pending_prompt']['pick_count'] ?? 0));

        $state = \actionResolvePrompt($state, 'p1', [
            'card_ids' => ['d1', 'd2'],
        ]);
        $handIds = array_column($state['players']['p1']['hand'], 'instance_id');
        $this->assertContains('d1', $handIds);
        $this->assertContains('d2', $handIds);
        $wrIds = array_column($state['players']['p1']['waiting_room'], 'instance_id');
        $this->assertContains('d3', $wrIds);
        $this->assertContains('d6', $wrIds);
        $this->assertCount(0, $state['players']['p1']['main_deck']);
    }
}
