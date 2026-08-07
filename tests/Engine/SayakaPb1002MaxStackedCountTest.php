<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * PL!HS-pb1-002 Sayaka — max 3 Members under her for stack skill + Live Start counting.
 */
final class SayakaPb1002MaxStackedCountTest extends TestCase
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
            'live_zone' => [],
            'success_lives' => [],
        ];
    }

    public function testFourStacksStillCapLiveStartAtThree(): void
    {
        $sayaka = $this->cardByNo('PL!HS-pb1-002-R', 'sayaka');
        $ab = $sayaka['abilities'][1] ?? [];
        $this->assertSame('live_start_cost_hearts_per_stacked', $ab['type'] ?? null);
        $this->assertSame(3, intval($ab['max_stacked'] ?? 0));

        $sayaka['stacked_members'] = [];
        for ($i = 1; $i <= 4; $i++) {
            $sayaka['stacked_members'][] = [
                'instance_id' => 's' . $i,
                'card_type' => 'メンバー',
                'card_type_en' => 'Member',
                'name_en' => 'Sayaka Murano',
                'name' => '村野さやか',
            ];
        }

        $p1 = $this->emptyPlayer('p1', 'P1');
        $p1['stage']['center'] = $sayaka;
        $state = [
            'status' => 'playing',
            'phase' => 'live_start_effects',
            'seq' => 1,
            'turn' => 2,
            'first_player' => 'p1',
            'live_attempt' => ['p1'],
            'log' => [],
            'players' => [
                'p1' => $p1,
                'p2' => $this->emptyPlayer('p2', 'P2'),
            ],
        ];

        $GLOBALS['TUT_PERF_MANUAL_PHASES'] = true;
        try {
            $state = \resolveLiveStartAbilities($state, 'p1');
            $m = $state['players']['p1']['stage']['center'];
            $this->assertCount(4, $m['stacked_members'] ?? []);
            $this->assertSame(12, intval($m['live_cost_bonus'] ?? 0));
            $this->assertSame(14, \getEffectiveStageMemberCost($state, 'p1', $m));
            $blues = array_values(array_filter(
                $state['live_modifiers']['p1']['bonus_hearts'] ?? [],
                static fn($c) => $c === 'blue'
            ));
            $this->assertCount(3, $blues);
        } finally {
            unset($GLOBALS['TUT_PERF_MANUAL_PHASES']);
        }
    }

    public function testActivateRefusesFourthStack(): void
    {
        $sayaka = $this->cardByNo('PL!HS-pb1-002-R', 'sayaka');
        $this->assertSame(3, intval($sayaka['abilities'][0]['max_stacked'] ?? 0));

        $sayaka['stacked_members'] = [];
        for ($i = 1; $i <= 3; $i++) {
            $sayaka['stacked_members'][] = [
                'instance_id' => 's' . $i,
                'card_type' => 'メンバー',
                'name_en' => 'Sayaka Murano',
            ];
        }

        $extra = $this->cardByNo('PL!HS-pb1-002-R', 'extra');
        $extra['name'] = '村野さやか';
        $extra['name_en'] = 'Sayaka Murano';

        $p1 = $this->emptyPlayer('p1', 'P1');
        $p1['stage']['center'] = $sayaka;
        $p1['hand'] = [$extra];
        $state = [
            'status' => 'playing',
            'phase' => 'main_first',
            'seq' => 1,
            'turn' => 4,
            'first_player' => 'p1',
            'active_player' => 'p1',
            'log' => [],
            'players' => [
                'p1' => $p1,
                'p2' => $this->emptyPlayer('p2', 'P2'),
            ],
        ];

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/maximum of 3/');
        \actionActivateAbility($state, 'p1', [
            'card_id' => 'sayaka',
            'ability_index' => 0,
        ]);
    }
}
