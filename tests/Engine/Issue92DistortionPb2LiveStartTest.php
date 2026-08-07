<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * Issue #92 — Distortion (PL!SP-pb2-048-L) Live Start:
 * per distinct CatChu! on Stage: −2 gray / +1 red; score +1 if red ≥ 9.
 */
final class Issue92DistortionPb2LiveStartTest extends TestCase
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

    private function member(string $nameEn, string $subunit, string $iid): array
    {
        return [
            'instance_id' => $iid,
            'card_no' => 'TEST-' . $iid,
            'name' => $nameEn,
            'name_en' => $nameEn,
            'card_type' => 'メンバー',
            'card_type_en' => 'Member',
            'group' => 'Superstar',
            'subunit' => $subunit,
            'cost' => 3,
            'hearts' => [['color' => 'red', 'count' => 1]],
            'abilities' => [],
        ];
    }

    private function colorCount(array $req, string $color): int
    {
        $n = 0;
        foreach ($req as $h) {
            if (\heartRequirementColorsMatch((string)($h['color'] ?? ''), $color)) {
                $n += intval($h['count'] ?? 0);
            }
        }
        return $n;
    }

    private function baseState(array $live, array $stage): array
    {
        return [
            'room_id' => 'D92',
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
                    'stage' => $stage,
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
    }

    public function testCatalogAbilityMatchesJpEffect(): void
    {
        $card = $this->cardByNo('PL!SP-pb2-048-L', 'd0');
        $ab = $card['abilities'][0] ?? [];
        $this->assertSame('live_start', $ab['trigger'] ?? null);
        $this->assertSame('convert_hearts_per_distinct_subunit', $ab['type'] ?? null);
        $this->assertSame('CatChu!', $ab['subunit'] ?? null);
        $this->assertSame(2, intval($ab['reduce_per'] ?? 0));
        $this->assertSame(1, intval($ab['increase_per'] ?? 0));
        $this->assertSame(9, intval($ab['score_if_color_min'] ?? 0));
    }

    public function testZeroCatChuLeavesPrintedHearts(): void
    {
        $live = $this->cardByNo('PL!SP-pb2-048-L', 'd1');
        $printedScore = intval($live['score'] ?? 0);
        $state = $this->baseState($live, [
            'left' => $this->member('Someone', '5yncri5e!', 'm1'),
            'center' => null,
            'right' => null,
        ]);
        $state = \resolveLiveStartAbilities($state, 'p1');
        $lc = $state['players']['p1']['live_zone'][0];
        $req = \liveHeartRequirementsForCheck($state, 'p1', $lc);
        $this->assertSame(6, $this->colorCount($req, 'red'));
        $this->assertSame(9, $this->colorCount($req, 'any'));
        $this->assertSame($printedScore, intval($lc['score'] ?? 0));
    }

    public function testOneCatChuConvertsHeartsNoScore(): void
    {
        $live = $this->cardByNo('PL!SP-pb2-048-L', 'd2');
        $printedScore = intval($live['score'] ?? 0);
        $state = $this->baseState($live, [
            'left' => $this->member('Kanon Shibuya', 'CatChu!', 'm1'),
            'center' => null,
            'right' => null,
        ]);
        $state = \resolveLiveStartAbilities($state, 'p1');
        $lc = $state['players']['p1']['live_zone'][0];
        $req = \liveHeartRequirementsForCheck($state, 'p1', $lc);
        $this->assertSame(7, $this->colorCount($req, 'red'));
        $this->assertSame(7, $this->colorCount($req, 'any'));
        $this->assertSame($printedScore, intval($lc['score'] ?? 0));
        $this->assertSame(2, intval($lc['hearts_color_reduction']['any'] ?? 0));
        $this->assertSame(1, intval($lc['hearts_color_increase']['red'] ?? 0));
    }

    public function testThreeCatChuConvertsAndScores(): void
    {
        $live = $this->cardByNo('PL!SP-pb2-048-L', 'd3');
        $printedScore = intval($live['score'] ?? 0);
        $state = $this->baseState($live, [
            'left' => $this->member('Kanon Shibuya', 'CatChu!', 'm1'),
            'center' => $this->member('Sumire Heanna', 'CatChu!', 'm2'),
            'right' => $this->member('Mei Yoneme', 'CatChu!', 'm3'),
        ]);
        $state = \resolveLiveStartAbilities($state, 'p1');
        $lc = $state['players']['p1']['live_zone'][0];
        $req = \liveHeartRequirementsForCheck($state, 'p1', $lc);
        $this->assertSame(9, $this->colorCount($req, 'red'));
        $this->assertSame(3, $this->colorCount($req, 'any'));
        $this->assertSame($printedScore + 1, intval($lc['score'] ?? 0));
    }

    public function testDuplicateNamesCountOnce(): void
    {
        $live = $this->cardByNo('PL!SP-pb2-048-L', 'd4');
        $state = $this->baseState($live, [
            'left' => $this->member('Kanon Shibuya', 'CatChu!', 'm1'),
            'center' => $this->member('Kanon Shibuya', 'CatChu!', 'm2'),
            'right' => null,
        ]);
        $state = \resolveLiveStartAbilities($state, 'p1');
        $lc = $state['players']['p1']['live_zone'][0];
        $req = \liveHeartRequirementsForCheck($state, 'p1', $lc);
        $this->assertSame(7, $this->colorCount($req, 'red'));
        $this->assertSame(7, $this->colorCount($req, 'any'));
    }
}
