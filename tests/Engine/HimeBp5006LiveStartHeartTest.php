<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/** PL!HS-bp5-006 — Live Start +2 pink on Hime (member), not player pool (#136). */
final class HimeBp5006LiveStartHeartTest extends TestCase
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

    public function testLiveStartGrantsPinkHeartsOnHimeMember(): void
    {
        $hime = $this->cardByNo('PL!HS-bp5-006-P', 'hime');
        $state = [
            'status' => 'playing',
            'phase' => 'live_start_effects',
            'seq' => 1,
            'turn' => 2,
            'first_player' => 'p1',
            'active_player' => 'p1',
            'live_attempt' => ['p1'],
            'log' => [],
            'live_modifiers' => [
                'p1' => ['bonus_hearts' => []],
                'p2' => ['bonus_hearts' => []],
            ],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [
                        ['instance_id' => 'h1', 'card_type' => 'メンバー', 'group' => 'Hasunosora', 'name_en' => 'H1'],
                        ['instance_id' => 'h2', 'card_type' => 'メンバー', 'group' => 'Hasunosora', 'name_en' => 'H2'],
                    ],
                    'waiting_room' => [],
                    'main_deck' => [],
                    'stage' => ['left' => null, 'center' => $hime, 'right' => null],
                    'energy_zone' => [],
                    'live_zone' => [],
                    'success_lives' => [],
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'P2',
                    'hand' => [],
                    'waiting_room' => [],
                    'main_deck' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'energy_zone' => [],
                    'live_zone' => [],
                    'success_lives' => [],
                ],
            ],
        ];

        $ab = $hime['abilities'][0];
        $state = \resolveAbilityEffect($state, 'p1', $hime, $ab, [
            'phase' => 'live_start',
            'confirm' => true,
            'discard_ids' => ['h1', 'h2'],
        ]);

        $himeAfter = $state['players']['p1']['stage']['center'];
        $this->assertCount(2, $himeAfter['bonus_hearts'] ?? []);
        $this->assertSame(['pink', 'pink'], $himeAfter['bonus_hearts']);
        $this->assertSame([], \getBonusHeartsFlat($state, 'p1'));
        $flat = \memberPerformanceHeartsFlat($himeAfter);
        $this->assertGreaterThanOrEqual(6, count($flat), '4 printed + 2 bonus pink');
    }
}
