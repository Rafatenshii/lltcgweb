<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * Issue #74:
 * - Tsukuyomi Kurage (PL!HS-bp6-027-L): Auto may not mill Yell cards with Score icons (FAQ Q251).
 * - Kaho Hinoshita (PL!HS-pb1-009): Auto fires when she herself enters Center (FAQ Q245).
 */
final class Issue74KurageScoreAndKahoEnterTest extends TestCase
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

    public function testScoreIconYellCardNotEligibleForKurageMill(): void
    {
        $plain = [
            'instance_id' => 'plain',
            'card_type' => 'メンバー',
            'group' => 'Hasunosora',
            'name_en' => 'Plain',
            'blade_hearts' => [],
        ];
        $withBlade = [
            'instance_id' => 'blade',
            'card_type' => 'メンバー',
            'group' => 'Hasunosora',
            'name_en' => 'Blade',
            'blade_hearts' => ['green'],
        ];
        $withScore = [
            'instance_id' => 'score',
            'card_type' => 'メンバー',
            'group' => 'Hasunosora',
            'name_en' => 'Score',
            'blade_hearts' => [],
            'yell_score_icon' => true,
            'special_heart' => 'icon_score.png',
        ];
        $this->assertTrue(\yellCardEligibleForKurageMill($plain));
        $this->assertFalse(\yellCardEligibleForKurageMill($withBlade));
        $this->assertFalse(\yellCardEligibleForKurageMill($withScore));
        $this->assertTrue(\yellCardHasScoreIcon($withScore));
    }

    public function testKuragePromptExcludesScoreIconCandidates(): void
    {
        $kurage = $this->cardByNo('PL!HS-bp6-027-L', 'kurage');
        $state = [
            'status' => 'playing',
            'phase' => 'live_performance_first',
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
                    'hand' => [],
                    'waiting_room' => [],
                    'main_deck' => [],
                    'energy_zone' => [],
                    'energy_deck' => [],
                    'live_zone' => [$kurage],
                    'success_lives' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'yell_cards' => [],
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'P2',
                    'hand' => [],
                    'waiting_room' => [],
                    'main_deck' => [],
                    'energy_zone' => [],
                    'energy_deck' => [],
                    'live_zone' => [],
                    'success_lives' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                ],
            ],
        ];
        $yell = [
            [
                'instance_id' => 'ok1',
                'card_type' => 'メンバー',
                'group' => 'Hasunosora',
                'name_en' => 'OK',
                'blade_hearts' => [],
            ],
            [
                'instance_id' => 'score1',
                'card_type' => 'メンバー',
                'group' => 'Hasunosora',
                'name_en' => 'Score Card',
                'blade_hearts' => [],
                'yell_score_icon' => true,
                'special_heart' => 'icon_score.png',
            ],
        ];
        $state = \hsResolveHasunosoraEffect($state, 'p1', $kurage, $kurage['abilities'][0], [
            'yell_cards' => $yell,
            'live_zone_index' => 0,
            'ability_index' => 0,
        ]);
        $this->assertSame('auto_yell_mill_extra_yell', $state['pending_prompt']['type'] ?? null);
        $candIds = array_map(
            static fn($c) => $c['instance_id'] ?? '',
            $state['pending_prompt']['candidates'] ?? []
        );
        $this->assertContains('ok1', $candIds);
        $this->assertNotContains('score1', $candIds, 'Score-icon Yell cards must not be mill targets');
    }

    public function testKahoAutoFiresWhenSheEntersCenter(): void
    {
        $kaho = $this->cardByNo('PL!HS-pb1-009-R', 'kaho');
        $state = [
            'status' => 'playing',
            'phase' => 'main_first',
            'seq' => 1,
            'turn' => 1,
            'first_player' => 'p1',
            'active_player' => 'p1',
            'log' => [],
            'live_modifiers' => [
                'p1' => ['blade_bonus' => 0, 'bonus_hearts' => [], 'score_bonus' => 0],
                'p2' => ['blade_bonus' => 0, 'bonus_hearts' => [], 'score_bonus' => 0],
            ],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [],
                    'waiting_room' => [],
                    'main_deck' => [],
                    'energy_zone' => [],
                    'energy_deck' => [],
                    'live_zone' => [],
                    'success_lives' => [],
                    'stage' => [
                        'left' => null,
                        'center' => $kaho,
                        'right' => null,
                    ],
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'P2',
                    'hand' => [],
                    'waiting_room' => [],
                    'main_deck' => [],
                    'energy_zone' => [],
                    'energy_deck' => [],
                    'live_zone' => [],
                    'success_lives' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                ],
            ],
        ];

        $state = \resolveOnEnterAbilities($state, 'p1', $kaho, 'center');
        $bonus = intval($state['players']['p1']['stage']['center']['live_blade_bonus'] ?? 0);
        $this->assertSame(2, $bonus, 'Kaho entering Center must grant her own +2 Blade Auto');
        $this->assertSame(1, intval($state['players']['p1']['stage']['center']['_auto_uses_0'] ?? 0));
    }

    public function testKahoAutoStillFiresForOtherHasunosoraEnter(): void
    {
        $kaho = $this->cardByNo('PL!HS-pb1-009-R', 'kaho');
        $mate = $this->cardByNo('PL!HS-bp5-003-R＋', 'mate');
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
                    'waiting_room' => [],
                    'main_deck' => [],
                    'energy_zone' => [],
                    'energy_deck' => [],
                    'live_zone' => [],
                    'success_lives' => [],
                    'stage' => [
                        'left' => null,
                        'center' => $kaho,
                        'right' => null,
                    ],
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'P2',
                    'hand' => [],
                    'waiting_room' => [],
                    'main_deck' => [],
                    'energy_zone' => [],
                    'energy_deck' => [],
                    'live_zone' => [],
                    'success_lives' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                ],
            ],
        ];
        $state['players']['p1']['stage']['left'] = $mate;
        $state = \resolveOnEnterAbilities($state, 'p1', $mate, 'left');
        $this->assertSame(2, intval($state['players']['p1']['stage']['center']['live_blade_bonus'] ?? 0));
    }

    public function testKahoAutoDoesNotFireWhenEnteringNonCenter(): void
    {
        $kaho = $this->cardByNo('PL!HS-pb1-009-R', 'kaho');
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
                    'waiting_room' => [],
                    'main_deck' => [],
                    'energy_zone' => [],
                    'energy_deck' => [],
                    'live_zone' => [],
                    'success_lives' => [],
                    'stage' => [
                        'left' => $kaho,
                        'center' => null,
                        'right' => null,
                    ],
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'P2',
                    'hand' => [],
                    'waiting_room' => [],
                    'main_deck' => [],
                    'energy_zone' => [],
                    'energy_deck' => [],
                    'live_zone' => [],
                    'success_lives' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                ],
            ],
        ];
        $state = \resolveOnEnterAbilities($state, 'p1', $kaho, 'left');
        $this->assertSame(0, intval($state['players']['p1']['stage']['left']['live_blade_bonus'] ?? 0));
    }
}
