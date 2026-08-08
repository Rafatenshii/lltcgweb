<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * PL!-PR-015-PR Maki: baton from lower cost may play any Member ≤4 from hand
 * (engine wrongly defaulted the filter to Nijigasaki).
 */
final class MakiPr015BatonPlayHandTest extends TestCase
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
        $this->fail('Missing card ' . $cardNo);
    }

    public function testBatonOnEnterOffersAnyMemberNotJustNijigasaki(): void
    {
        $maki = $this->cardByNo('PL!-PR-015-PR', 'maki_pr015');
        $maki['baton_from_cost'] = 5;
        $maki['entered_via_baton'] = true;
        $museMember = $this->cardByNo('PL!-PR-014-PR', 'umi_hand'); // μ's cost 2

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
                    'hand' => [$museMember],
                    'waiting_room' => [],
                    'stage' => [
                        'left' => null,
                        'center' => null,
                        'right' => $maki,
                    ],
                    'energy_zone' => [],
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
                    'main_deck' => [],
                    'success_lives' => [],
                    'live_zone' => [],
                ],
            ],
        ];

        $state = \resolveOnEnterAbilities($state, 'p1', $maki, 'right');
        $this->assertSame(
            'optional_pay_play_hand_member',
            $state['pending_prompt']['type'] ?? null,
            'Maki baton On Enter must open play-from-hand when a μ\'s Member ≤4 is in hand'
        );
        $ids = array_column($state['pending_prompt']['candidates'] ?? [], 'instance_id');
        $this->assertContains('umi_hand', $ids);
        $this->assertTrue(!empty($state['pending_prompt']['ability']['any_group']));
    }

    public function testBatonOnEnterOffersMuseCost4WhenCenterEmpty(): void
    {
        $maki = $this->cardByNo('PL!-PR-015-PR', 'maki_pr015_b');
        $maki['baton_from_cost'] = 11;
        $maki['entered_via_baton'] = true;
        $nozomi = $this->cardByNo('PL!-bp5-016-N', 'nozomi_hand');
        $this->assertSame(4, intval($nozomi['cost'] ?? 0));
        $this->assertSame("μ's", $nozomi['group'] ?? '');

        $left = $this->cardByNo('PL!-bp6-012-N', 'kotori_left');
        $state = [
            'status' => 'playing',
            'phase' => 'main_first',
            'seq' => 1,
            'turn' => 5,
            'first_player' => 'p1',
            'active_player' => 'p1',
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [$nozomi],
                    'waiting_room' => [],
                    'stage' => [
                        'left' => $left,
                        'center' => null,
                        'right' => $maki,
                    ],
                    'energy_zone' => [],
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
                    'main_deck' => [],
                    'success_lives' => [],
                    'live_zone' => [],
                ],
            ],
        ];

        $state = \resolveOnEnterAbilities($state, 'p1', $maki, 'right');
        $this->assertSame('optional_pay_play_hand_member', $state['pending_prompt']['type'] ?? null);
        $ids = array_column($state['pending_prompt']['candidates'] ?? [], 'instance_id');
        $this->assertContains('nozomi_hand', $ids);
    }
}
