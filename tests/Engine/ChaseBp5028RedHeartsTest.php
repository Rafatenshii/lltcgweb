<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/** PL!N-bp5-028-L CHASE! — Live Start checks Red hearts (not Yellow). */
final class ChaseBp5028RedHeartsTest extends TestCase
{
    private function cardByNo(string $cardNo, string $instanceId): array
    {
        $data = json_decode((string) file_get_contents((string) constant('CARDS_FILE')), true);
        foreach ($data['cards'] ?? [] as $card) {
            if (($card['card_no'] ?? '') === $cardNo) {
                $card['instance_id'] = $instanceId;
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
            'energy_deck' => [],
            'main_deck' => [],
            'live_zone' => [],
            'success_lives' => [],
        ];
    }

    private function runChaseLiveStart(array $chase, array $member, array $energyZone = []): array
    {
        $p1 = $this->emptyPlayer('p1', 'P1');
        $p1['stage']['center'] = $member;
        $p1['energy_zone'] = $energyZone;
        $p1['live_zone'] = [$chase];
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
            return \resolveLiveStartAbilities($state, 'p1');
        } finally {
            unset($GLOBALS['TUT_PERF_MANUAL_PHASES']);
        }
    }

    public function testCatalogUsesRed(): void
    {
        $chase = $this->cardByNo('PL!N-bp5-028-L', 'chase');
        $ab = $chase['abilities'][0] ?? [];
        $this->assertSame('red', $ab['color'] ?? null);
        $this->assertSame('red', $ab['required_hearts'][0]['color'] ?? null);
        $this->assertStringContainsString('Red', $chase['text'] ?? '');
        $this->assertStringNotContainsString('Yellow', $chase['text'] ?? '');
    }

    public function testLiveStartTriggersOnFourRedNotYellow(): void
    {
        $chase = $this->cardByNo('PL!N-bp5-028-L', 'chase');
        $printedScore = intval($chase['score'] ?? 0);

        $yellowHeavy = [
            'instance_id' => 'y1',
            'card_type' => 'メンバー',
            'name_en' => 'Yellow Member',
            'hearts' => [['color' => 'yellow', 'count' => 4]],
        ];
        $redHeavy = [
            'instance_id' => 'r1',
            'card_type' => 'メンバー',
            'name_en' => 'Red Member',
            'hearts' => [['color' => 'red', 'count' => 4]],
        ];

        $p1 = $this->emptyPlayer('p1', 'P1');
        $p1['stage']['center'] = $yellowHeavy;
        $p1['live_zone'] = [$chase];
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
            $lc = $state['players']['p1']['live_zone'][0];
            $this->assertSame($printedScore, intval($lc['score'] ?? 0), 'Yellow×4 must not trigger CHASE');
            $this->assertSame(2, intval(($lc['required_hearts'][0]['count'] ?? 0)));

            $state['players']['p1']['stage']['center'] = $redHeavy;
            $state['players']['p1']['live_zone'][0] = $chase;
            unset($state['live_start_mandatory_resolved'], $state['live_start_entry_applied']);
            $state = \resolveLiveStartAbilities($state, 'p1');
            $lc = $state['players']['p1']['live_zone'][0];
            $this->assertSame($printedScore + 2, intval($lc['score'] ?? 0));
            $this->assertSame('red', $lc['required_hearts'][0]['color'] ?? null);
            $this->assertSame(5, intval($lc['required_hearts'][0]['count'] ?? 0));
        } finally {
            unset($GLOBALS['TUT_PERF_MANUAL_PHASES']);
        }
    }

    public function testLiveStartCountsBp7SetsuStackedEnergyReds(): void
    {
        $chase = $this->cardByNo('PL!N-bp5-028-L', 'chase');
        $printedScore = intval($chase['score'] ?? 0);
        $setsu = $this->cardByNo('PL!N-bp7-007-SEC', 'setsu');
        $setsu['stacked_energy'] = [
            ['instance_id' => 'se1', 'card_type' => 'エネルギー'],
            ['instance_id' => 'se2', 'card_type' => 'エネルギー'],
            ['instance_id' => 'se3', 'card_type' => 'エネルギー'],
        ];

        $state = $this->runChaseLiveStart($chase, $setsu);
        $lc = $state['players']['p1']['live_zone'][0];
        $this->assertSame($printedScore + 2, intval($lc['score'] ?? 0), '1 printed Red + 3 stacked Energy Reds must trigger CHASE');
        $this->assertSame('red', $lc['required_hearts'][0]['color'] ?? null);
        $this->assertSame(5, intval($lc['required_hearts'][0]['count'] ?? 0));
    }

    public function testLiveStartIgnoresSetsuWithOnlyThreeReds(): void
    {
        $chase = $this->cardByNo('PL!N-bp5-028-L', 'chase');
        $printedScore = intval($chase['score'] ?? 0);
        $setsu = $this->cardByNo('PL!N-bp7-007-SEC', 'setsu');
        $setsu['stacked_energy'] = [
            ['instance_id' => 'se1', 'card_type' => 'エネルギー'],
            ['instance_id' => 'se2', 'card_type' => 'エネルギー'],
        ];

        $state = $this->runChaseLiveStart($chase, $setsu);
        $lc = $state['players']['p1']['live_zone'][0];
        $this->assertSame($printedScore, intval($lc['score'] ?? 0), '1 printed + 2 stacked = 3 Reds must not trigger CHASE');
    }

    public function testLiveStartCountsSetsuRedsFromEnergyAboveSix(): void
    {
        $chase = $this->cardByNo('PL!N-bp5-028-L', 'chase');
        $printedScore = intval($chase['score'] ?? 0);
        $setsu = $this->cardByNo('PL!N-bp7-007-SEC', 'setsu');
        $zone = [];
        for ($i = 0; $i < 9; $i++) {
            $zone[] = ['instance_id' => 'ez' . $i, 'card_type' => 'エネルギー', 'active' => true];
        }

        $state = $this->runChaseLiveStart($chase, $setsu, $zone);
        $lc = $state['players']['p1']['live_zone'][0];
        $this->assertSame($printedScore + 2, intval($lc['score'] ?? 0), '1 printed Red + 3 from Energy above 6 must trigger CHASE');
        $this->assertSame(5, intval($lc['required_hearts'][0]['count'] ?? 0));
    }
}
