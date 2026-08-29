<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/** PL!HS-pb1-029-L — Zenhoui Kyun♡ Live Start draw / any-heart reduction (#136). */
final class ZenhouiLiveStartTest extends TestCase
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

    private function baseState(array $stage, array $liveZone, array $hand = []): array
    {
        return [
            'status' => 'playing',
            'phase' => 'live_start_effects',
            'seq' => 1,
            'turn' => 2,
            'first_player' => 'p1',
            'active_player' => 'p1',
            'live_attempt' => ['p1'],
            '_live_start_perf_pid' => 'p1',
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => $hand,
                    'waiting_room' => [],
                    'main_deck' => [
                        ['instance_id' => 'd1', 'card_type' => 'メンバー', 'group' => 'Hasunosora'],
                        ['instance_id' => 'd2', 'card_type' => 'メンバー', 'group' => 'Hasunosora'],
                        ['instance_id' => 'd3', 'card_type' => 'メンバー', 'group' => 'Hasunosora'],
                    ],
                    'energy_zone' => [],
                    'energy_deck' => [],
                    'live_zone' => $liveZone,
                    'success_lives' => [],
                    'stage' => $stage,
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
    }

    private function findLive(array $state, string $instanceId): ?array
    {
        foreach ($state['players']['p1']['live_zone'] ?? [] as $lc) {
            if (($lc['instance_id'] ?? '') === $instanceId) {
                return $lc;
            }
        }
        return null;
    }

    private function anyRequired(array $live): int
    {
        $req = \applyLiveHeartReductions(
            $live['required_hearts'] ?? $live['hearts'] ?? [],
            $live
        );
        $n = 0;
        foreach ($req as $row) {
            if (\normalizeRequiredHeartColor((string) ($row['color'] ?? '')) === 'any') {
                $n += intval($row['count'] ?? 0);
            }
        }
        return $n;
    }

    private function driveLiveStarts(array $state): array
    {
        $handDiscard = ['h1', 'h2'];
        $discardIdx = 0;
        for ($i = 0; $i < 24; $i++) {
            $ptype = $state['pending_prompt']['type'] ?? '';
            if ($ptype === '') {
                if (($state['phase'] ?? '') === 'live_start_effects') {
                    $state = \finishLiveStartEffects($state, false);
                }
                break;
            }
            if ($ptype === 'live_start_order_sources') {
                $ids = array_map(
                    static fn(array $c): string => (string) ($c['instance_id'] ?? ''),
                    $state['pending_prompt']['candidates'] ?? []
                );
                $state = \actionResolvePrompt($state, 'p1', ['card_ids' => $ids]);
                continue;
            }
            if ($ptype === 'optional_live_start') {
                $hid = $handDiscard[$discardIdx++] ?? 'h1';
                $state = \actionResolvePrompt($state, 'p1', [
                    'choice' => 'yes',
                    'discard_ids' => [$hid],
                ]);
                continue;
            }
            if ($ptype === 'buff_member_matching_discarded_group') {
                $slot = ($discardIdx === 1) ? 'left' : 'center';
                $state = \actionResolvePrompt($state, 'p1', ['slot' => $slot]);
                continue;
            }
            $this->fail('Unexpected prompt: ' . $ptype);
        }
        return $state;
    }

    public function testTwoBuffedMirapaMembersReduceAnyHearts(): void
    {
        $r1 = $this->cardByNo('PL!HS-bp5-003-R＋', 'r1');
        $r2 = $this->cardByNo('PL!HS-bp5-003-P', 'r2');
        $zen = $this->cardByNo('PL!HS-pb1-029-L', 'zen');
        $state = $this->baseState(
            ['left' => $r1, 'center' => $r2, 'right' => null],
            [$zen],
            [
                ['instance_id' => 'h1', 'card_type' => 'メンバー', 'group' => 'Hasunosora', 'name_en' => 'H1'],
                ['instance_id' => 'h2', 'card_type' => 'メンバー', 'group' => 'Hasunosora', 'name_en' => 'H2'],
            ]
        );
        $deckBefore = count($state['players']['p1']['main_deck']);

        $GLOBALS['TUT_PERF_MANUAL_PHASES'] = true;
        try {
            $state = \resolveLiveStartAbilities($state, 'p1');
            $state = $this->driveLiveStarts($state);
        } finally {
            unset($GLOBALS['TUT_PERF_MANUAL_PHASES']);
        }

        $this->assertSame($deckBefore - 1, count($state['players']['p1']['main_deck']));
        $zenLive = $this->findLive($state, 'zen');
        $this->assertNotNull($zenLive);
        $this->assertSame(2, intval($zenLive['hearts_color_reduction']['any'] ?? 0));
        $this->assertSame(6, $this->anyRequired($zenLive));
    }

    public function testZenhouiFirstOrderStillReducesAfterTwoBuffs(): void
    {
        $r1 = $this->cardByNo('PL!HS-bp5-003-R＋', 'r1');
        $r2 = $this->cardByNo('PL!HS-bp5-003-P', 'r2');
        $zen = $this->cardByNo('PL!HS-pb1-029-L', 'zen');
        $state = $this->baseState(
            ['left' => $r1, 'center' => $r2, 'right' => null],
            [$zen],
            [
                ['instance_id' => 'h1', 'card_type' => 'メンバー', 'group' => 'Hasunosora', 'name_en' => 'H1'],
                ['instance_id' => 'h2', 'card_type' => 'メンバー', 'group' => 'Hasunosora', 'name_en' => 'H2'],
            ]
        );

        $GLOBALS['TUT_PERF_MANUAL_PHASES'] = true;
        try {
            $state = \resolveLiveStartAbilities($state, 'p1');
            $state = \actionResolvePrompt($state, 'p1', ['card_ids' => ['zen', 'r1', 'r2']]);
            $state = $this->driveLiveStarts($state);
        } finally {
            unset($GLOBALS['TUT_PERF_MANUAL_PHASES']);
        }

        $zenLive = $this->findLive($state, 'zen');
        $this->assertNotNull($zenLive);
        $this->assertSame(2, intval($zenLive['hearts_color_reduction']['any'] ?? 0));
        $this->assertSame(6, $this->anyRequired($zenLive));
    }

    public function testOneBuffedMemberDrawsWithoutReduce(): void
    {
        $r1 = $this->cardByNo('PL!HS-bp5-003-R＋', 'r1');
        $zen = $this->cardByNo('PL!HS-pb1-029-L', 'zen');
        $state = $this->baseState(
            ['left' => $r1, 'center' => null, 'right' => null],
            [$zen],
            [
                ['instance_id' => 'h1', 'card_type' => 'メンバー', 'group' => 'Hasunosora', 'name_en' => 'H1'],
            ]
        );
        $deckBefore = count($state['players']['p1']['main_deck']);

        $GLOBALS['TUT_PERF_MANUAL_PHASES'] = true;
        try {
            $state = \resolveLiveStartAbilities($state, 'p1');
            $state = $this->driveLiveStarts($state);
        } finally {
            unset($GLOBALS['TUT_PERF_MANUAL_PHASES']);
        }

        $this->assertSame($deckBefore - 1, count($state['players']['p1']['main_deck']));
        $zenLive = $this->findLive($state, 'zen');
        $this->assertNotNull($zenLive);
        $this->assertSame(0, intval($zenLive['hearts_color_reduction']['any'] ?? 0));
        $this->assertSame(8, $this->anyRequired($zenLive));
    }

    /** Hime live start is player-pool hearts; Rurino buff on Hime + one Rurino = 2 members → −2 any. */
    public function testHimeBuffedByRurinoPlusAnotherRurinoReduces(): void
    {
        $rLeft = $this->cardByNo('PL!HS-bp5-003-R＋', 'rLeft');
        $hime = $this->cardByNo('PL!HS-bp5-006-P', 'hime');
        $rRight = $this->cardByNo('PL!HS-bp5-003-P', 'rRight');
        $zen = $this->cardByNo('PL!HS-pb1-029-L', 'zen');
        $state = $this->baseState(
            ['left' => $rLeft, 'center' => $hime, 'right' => $rRight],
            [$zen],
            [
                ['instance_id' => 'h1', 'card_type' => 'メンバー', 'group' => 'Hasunosora', 'name_en' => 'H1'],
                ['instance_id' => 'h2', 'card_type' => 'メンバー', 'group' => 'Hasunosora', 'name_en' => 'H2'],
                ['instance_id' => 'h3', 'card_type' => 'メンバー', 'group' => 'Hasunosora', 'name_en' => 'H3'],
                ['instance_id' => 'h4', 'card_type' => 'メンバー', 'group' => 'Hasunosora', 'name_en' => 'H4'],
                ['instance_id' => 'h5', 'card_type' => 'メンバー', 'group' => 'Hasunosora', 'name_en' => 'H5'],
            ]
        );
        $handDiscard = ['h1', 'h2', 'h3', 'h4', 'h5'];
        $discardIdx = 0;
        $rurinoBuffPick = 0;
        $rurinoSlots = ['center', 'left'];

        $GLOBALS['TUT_PERF_MANUAL_PHASES'] = true;
        try {
            $state = \resolveLiveStartAbilities($state, 'p1');
            for ($i = 0; $i < 30; $i++) {
                $ptype = $state['pending_prompt']['type'] ?? '';
                if ($ptype === '') {
                    if (($state['phase'] ?? '') === 'live_start_effects') {
                        $state = \finishLiveStartEffects($state, false);
                    }
                    break;
                }
                if ($ptype === 'live_start_order_sources') {
                    $ids = array_map(
                        static fn(array $c): string => (string) ($c['instance_id'] ?? ''),
                        $state['pending_prompt']['candidates'] ?? []
                    );
                    $state = \actionResolvePrompt($state, 'p1', ['card_ids' => $ids]);
                    continue;
                }
                if ($ptype === 'optional_live_start') {
                    $need = intval($state['pending_prompt']['discard_count'] ?? 1);
                    $ids = array_slice($handDiscard, $discardIdx, $need);
                    $discardIdx += $need;
                    $state = \actionResolvePrompt($state, 'p1', [
                        'choice' => 'yes',
                        'discard_ids' => $ids,
                    ]);
                    continue;
                }
                if ($ptype === 'buff_member_matching_discarded_group') {
                    $slot = $rurinoSlots[$rurinoBuffPick++] ?? 'left';
                    $state = \actionResolvePrompt($state, 'p1', ['slot' => $slot]);
                    continue;
                }
                $this->fail('Unexpected prompt: ' . $ptype);
            }
        } finally {
            unset($GLOBALS['TUT_PERF_MANUAL_PHASES']);
        }

        $this->assertNotEmpty($state['players']['p1']['stage']['center']['bonus_hearts'] ?? null);
        $this->assertNotEmpty($state['players']['p1']['stage']['left']['bonus_hearts'] ?? null);
        $zenLive = $this->findLive($state, 'zen');
        $this->assertSame(2, intval($zenLive['hearts_color_reduction']['any'] ?? 0));
        $this->assertSame(6, $this->anyRequired($zenLive));
    }

    /** Hime Live Start (+2 pink to player pool) + one Rurino member buff = only 1 qualifies → draw, no −2. */
    public function testHimeLiveStartPlusOneRurinoBuffDrawsOnly(): void
    {
        $rLeft = $this->cardByNo('PL!HS-bp5-003-R＋', 'rLeft');
        $hime = $this->cardByNo('PL!HS-bp5-006-P', 'hime');
        $zen = $this->cardByNo('PL!HS-pb1-029-L', 'zen');
        $state = $this->baseState(
            ['left' => $rLeft, 'center' => $hime, 'right' => null],
            [$zen],
            [
                ['instance_id' => 'h1', 'card_type' => 'メンバー', 'group' => 'Hasunosora', 'name_en' => 'H1'],
                ['instance_id' => 'h2', 'card_type' => 'メンバー', 'group' => 'Hasunosora', 'name_en' => 'H2'],
                ['instance_id' => 'h3', 'card_type' => 'メンバー', 'group' => 'Hasunosora', 'name_en' => 'H3'],
            ]
        );
        $handDiscard = ['h1', 'h2', 'h3'];
        $discardIdx = 0;

        $GLOBALS['TUT_PERF_MANUAL_PHASES'] = true;
        try {
            $state = \resolveLiveStartAbilities($state, 'p1');
            for ($i = 0; $i < 20; $i++) {
                $ptype = $state['pending_prompt']['type'] ?? '';
                if ($ptype === '') {
                    if (($state['phase'] ?? '') === 'live_start_effects') {
                        $state = \finishLiveStartEffects($state, false);
                    }
                    break;
                }
                if ($ptype === 'live_start_order_sources') {
                    $ids = array_map(
                        static fn(array $c): string => (string) ($c['instance_id'] ?? ''),
                        $state['pending_prompt']['candidates'] ?? []
                    );
                    $state = \actionResolvePrompt($state, 'p1', ['card_ids' => $ids]);
                    continue;
                }
                if ($ptype === 'optional_live_start') {
                    $need = intval($state['pending_prompt']['discard_count'] ?? 1);
                    $ids = array_slice($handDiscard, $discardIdx, $need);
                    $discardIdx += $need;
                    $state = \actionResolvePrompt($state, 'p1', [
                        'choice' => 'yes',
                        'discard_ids' => $ids,
                    ]);
                    continue;
                }
                if ($ptype === 'buff_member_matching_discarded_group') {
                    $state = \actionResolvePrompt($state, 'p1', ['slot' => 'left']);
                    continue;
                }
                $this->fail('Unexpected prompt: ' . $ptype);
            }
        } finally {
            unset($GLOBALS['TUT_PERF_MANUAL_PHASES']);
        }

        $this->assertNotEmpty($state['players']['p1']['stage']['left']['bonus_hearts'] ?? null);
        $this->assertEmpty($state['players']['p1']['stage']['center']['bonus_hearts'] ?? null);
        $zenLive = $this->findLive($state, 'zen');
        $this->assertSame(0, intval($zenLive['hearts_color_reduction']['any'] ?? 0));
        $this->assertSame(8, $this->anyRequired($zenLive));
    }
}
