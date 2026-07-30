<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * ?←HEARTBEAT Live Start must reduce gray by exactly 1 (not wipe all colorless).
 */
final class HeartbeatLiveStartGrayReduceTest extends TestCase
{
    private function cardByNo(string $cardNo, string $instanceId): array
    {
        $data = json_decode((string) file_get_contents((string) constant('CARDS_FILE')), true);
        $this->assertIsArray($data);
        foreach ($data['cards'] ?? [] as $card) {
            if (($card['card_no'] ?? '') === $cardNo) {
                $card['instance_id'] = $instanceId;
                return $card;
            }
        }
        $this->fail('Missing test card ' . $cardNo);
    }

    private function grayCount(array $req): int
    {
        $n = 0;
        foreach ($req as $h) {
            if (\normalizeRequiredHeartColor((string)($h['color'] ?? '')) === 'any') {
                $n += intval($h['count'] ?? 0);
            }
        }
        return $n;
    }

    public function testLiveStartReducesExactlyOneGrayNotAll(): void
    {
        $hb = $this->cardByNo('PL!-bp4-021-L', 'hb1');
        $this->assertSame(8, $this->grayCount($hb['required_hearts'] ?? []));

        $state = [
            'room_id' => 'HB1',
            'status' => 'playing',
            'seq' => 1,
            'turn' => 3,
            'phase' => 'live_start_effects',
            'first_player' => 'p1',
            'active_player' => 'p1',
            'log' => [],
            'live_attempt' => ['p1'],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [],
                    'waiting_room' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'energy_zone' => [],
                    'main_deck' => [],
                    'live_zone' => [$hb],
                    'success_lives' => [
                        ['instance_id' => 's1', 'card_type' => 'ライブ', 'score' => 6, 'name_en' => 'Prior'],
                        ['instance_id' => 's2', 'card_type' => 'ライブ', 'score' => 3, 'name_en' => 'Prior2'],
                    ],
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

        $state = \resolveLiveStartAbilities($state, 'p1');
        $lc = $state['players']['p1']['live_zone'][0];
        $this->assertSame(7, $this->grayCount($lc['required_hearts'] ?? []), 'must leave 7 gray after −1');
        $this->assertSame(7, intval($lc['score'] ?? 0), 'score +1 when success total ≥ 9');

        $req = \liveHeartRequirementsForCheck($state, 'p1', $lc);
        $this->assertSame(7, $this->grayCount($req));

        $owned = array_merge(
            array_fill(0, 4, 'pink'),
            array_fill(0, 4, 'yellow'),
            array_fill(0, 3, 'purple')
        );
        [$ok11] = \checkHearts($owned, $req);
        $this->assertFalse($ok11, '11 hearts must not clear 2+2+2+7');

        $owned13 = array_merge($owned, ['pink', 'yellow']);
        [$ok13] = \checkHearts($owned13, $req);
        $this->assertTrue($ok13, '13 hearts must clear after −1 gray only');
    }

    public function testElevenHeartsFailsWithoutDreamin(): void
    {
        $hb = $this->cardByNo('PL!-bp4-021-L', 'hb1');
        $hb['required_hearts'] = \reduceHeartRequirementsByColor(
            $hb['required_hearts'],
            'any',
            1
        );
        $req = \liveHeartRequirementsForCheck(
            ['players' => ['p1' => ['success_lives' => [], 'stage' => []]]],
            'p1',
            $hb
        );
        $owned = array_merge(
            array_fill(0, 4, 'pink'),
            array_fill(0, 4, 'yellow'),
            array_fill(0, 3, 'purple')
        );
        [$ok] = \checkHearts($owned, $req);
        $this->assertFalse($ok);
        $this->assertSame(7, $this->grayCount($req));
    }
}
