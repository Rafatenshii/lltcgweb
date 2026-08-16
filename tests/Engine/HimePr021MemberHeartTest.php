<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/** Hime PR-021's matched mill grants the heart to Hime, not the player pool. */
final class HimePr021MemberHeartTest extends TestCase
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

    /** @return array<string, mixed> */
    private function pinkMember(string $instanceId): array
    {
        return [
            'instance_id' => $instanceId,
            'card_type' => 'メンバー',
            'name' => $instanceId,
            'name_en' => $instanceId,
            'hearts' => [['color' => 'pink', 'count' => 1]],
        ];
    }

    public function testMatchedMillAttachesPinkHeartToHime(): void
    {
        $hime = $this->cardByNo('PL!HS-PR-021-PR', 'hime');
        $state = [
            'phase' => 'main_first',
            'turn' => 1,
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [],
                    'waiting_room' => [],
                    'main_deck' => [
                        $this->pinkMember('pink-1'),
                        $this->pinkMember('pink-2'),
                        $this->pinkMember('pink-3'),
                    ],
                    'stage' => ['left' => null, 'center' => $hime, 'right' => null],
                    'energy_zone' => [],
                    'success_lives' => [],
                    'live_zone' => [],
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'P2',
                    'hand' => [],
                    'waiting_room' => [],
                    'main_deck' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'energy_zone' => [],
                    'success_lives' => [],
                    'live_zone' => [],
                ],
            ],
            'live_modifiers' => [
                'p1' => ['bonus_hearts' => []],
                'p2' => ['bonus_hearts' => []],
            ],
        ];

        $state = \resolveAbilityEffect($state, 'p1', $hime, $hime['abilities'][0], ['phase' => 'on_enter']);

        $himeAfter = $state['players']['p1']['stage']['center'];
        $this->assertSame(['pink'], $himeAfter['bonus_hearts'] ?? []);
        $this->assertSame(
            ['pink', 'pink'],
            \memberPerformanceHeartsFlat($himeAfter),
            'Hime contributes her printed and gained pink hearts.'
        );
        $this->assertSame([], \getBonusHeartsFlat($state, 'p1'));
    }
}
