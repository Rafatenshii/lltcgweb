<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/** Regression: Tomari BP5 continuous_hearts_in_slot must not triple (#89). */
final class Issue89TomariBp5HeartsTest extends TestCase
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

    private function baseState(): array
    {
        return [
            'status' => 'playing',
            'phase' => 'live_performance_first',
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
    }

    /** @dataProvider tomariVariants */
    public function testTomariCenterGrantsThreeYellowNotNine(string $cardNo): void
    {
        $tomari = $this->cardByNo($cardNo, 'issue89_tomari');
        $state = $this->baseState();
        $state['players']['p1']['stage']['center'] = $tomari;

        $grants = \collectContinuousPerformanceHeartGrants($state, 'p1');
        $this->assertCount(1, $grants);
        $hearts = $grants[0]['hearts'] ?? [];
        $this->assertCount(3, $hearts, 'Center should grant exactly 3 continuous hearts');
        $this->assertSame(['yellow', 'yellow', 'yellow'], $hearts);

        $flat = \getContinuousPerformanceHearts($state, 'p1');
        $this->assertCount(3, $flat);
    }

    public function testTomariLeftAndRightAreSlotSpecific(): void
    {
        $tomari = $this->cardByNo('PL!SP-bp5-011-AR', 'issue89_tomari_side');
        $state = $this->baseState();
        $state['players']['p1']['stage']['left'] = $tomari;

        $hearts = \getContinuousPerformanceHearts($state, 'p1');
        $this->assertSame(['red', 'red', 'red'], $hearts);

        $state['players']['p1']['stage'] = [
            'left' => null,
            'center' => null,
            'right' => $tomari,
        ];
        $hearts = \getContinuousPerformanceHearts($state, 'p1');
        $this->assertSame(['blue', 'blue', 'blue'], $hearts);
    }

    /** @return list<array{0: string}> */
    public static function tomariVariants(): array
    {
        return [
            ['PL!SP-bp5-011-AR'],
            ['PL!SP-bp5-011-P'],
            ['PL!SP-bp5-011-R'],
        ];
    }
}
