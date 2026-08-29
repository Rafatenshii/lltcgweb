<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * Issue #73: Rurino (PL!HS-bp5-003) Live Start pink ♡ must apply for Performance,
 * and Zenhoui Kyun♡ (PL!HS-pb1-029-L) must see that buff (optional Live Starts run
 * after mandatory Live-card Live Starts). Spectacle must keep stage hearts after
 * clearLiveModifiers.
 */
final class Issue73RurinoZenhouiTest extends TestCase
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

    private function basePlayers(): array
    {
        return [
            'p1' => [
                'id' => 'p1',
                'name' => 'P1',
                'hand' => [],
                'waiting_room' => [],
                'main_deck' => [
                    $this->cardByNo('PL!HS-bp1-023-L', 'deck1'),
                    $this->cardByNo('PL!HS-bp1-023-L', 'deck2'),
                    $this->cardByNo('PL!HS-bp1-023-L', 'deck3'),
                ],
                'energy_zone' => [],
                'energy_deck' => [],
                'live_zone' => [],
                'success_lives' => [],
                'stage' => ['left' => null, 'center' => null, 'right' => null],
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
        ];
    }

    /** Drive Rurino optional Live Start through discard + Stage pick. */
    private function resolveRurinoPinkBuff(array $state): array
    {
        $GLOBALS['TUT_PERF_MANUAL_PHASES'] = true;
        try {
            $state = \resolveLiveStartAbilities($state, 'p1');
            $state = $this->driveLiveStartPrompts($state);
            if (empty($state['pending_prompt'])) {
                $state = \finishLiveStartEffects($state, false);
            }
            return $state;
        } finally {
            unset($GLOBALS['TUT_PERF_MANUAL_PHASES']);
        }
    }

    /** Resolve order pick / optional Live Starts until idle or Performance. */
    private function driveLiveStartPrompts(array $state, int $maxSteps = 12): array
    {
        $handDiscard = ['hand_hs', 'hand_hs2'];
        $discardIdx = 0;
        for ($i = 0; $i < $maxSteps; $i++) {
            $ptype = $state['pending_prompt']['type'] ?? '';
            if ($ptype === '') {
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
                $hid = $handDiscard[$discardIdx++] ?? 'hand_hs';
                $state = \actionResolvePrompt($state, 'p1', [
                    'choice' => 'yes',
                    'discard_ids' => [$hid],
                ]);
                continue;
            }
            if ($ptype === 'buff_member_matching_discarded_group') {
                $slot = ($discardIdx <= 1) ? 'center' : 'left';
                $state = \actionResolvePrompt($state, 'p1', ['slot' => $slot]);
                continue;
            }
            break;
        }
        return $state;
    }

    public function testRurinoLiveStartGrantsPinkHeartOnStageMember(): void
    {
        $rurino = $this->cardByNo('PL!HS-bp5-003-R＋', 'rurino');
        $mate = $this->cardByNo('PL!HS-bp5-003-P', 'mate');
        $state = [
            'status' => 'playing',
            'phase' => 'live_start_effects',
            'seq' => 1,
            'turn' => 2,
            'first_player' => 'p1',
            'active_player' => 'p1',
            'live_attempt' => ['p1'],
            'log' => [],
            'players' => $this->basePlayers(),
        ];
        $state['players']['p1']['stage']['center'] = $rurino;
        $state['players']['p1']['stage']['left'] = $mate;
        $state['players']['p1']['hand'] = [
            [
                'instance_id' => 'hand_hs',
                'card_type' => 'メンバー',
                'group' => 'Hasunosora',
                'name_en' => 'Hand HS',
            ],
            [
                'instance_id' => 'hand_hs2',
                'card_type' => 'メンバー',
                'group' => 'Hasunosora',
                'name_en' => 'Hand HS 2',
            ],
        ];
        $state['players']['p1']['live_zone'] = [$this->cardByNo('PL!HS-bp1-023-L', 'live1')];

        $state = $this->resolveRurinoPinkBuff($state);
        $flat = \memberPerformanceHeartsFlat($state['players']['p1']['stage']['center']);
        $this->assertContains('pink', $flat);
        $this->assertGreaterThanOrEqual(2, count($flat), 'Printed pink + bonus pink');
        $agg = \aggregateStageHeartsByColor($state['players']['p1']['stage']);
        $pink = 0;
        foreach ($agg as $row) {
            if (($row['color'] ?? '') === 'pink') {
                $pink = intval($row['count'] ?? 0);
            }
        }
        $this->assertGreaterThanOrEqual(3, $pink, 'Rurino+mate printed + 1 bonus');
    }

    public function testZenhouiSeesRurinoBuffAndReducesAnyHearts(): void
    {
        $rurino = $this->cardByNo('PL!HS-bp5-003-R＋', 'rurino');
        $mate = $this->cardByNo('PL!HS-bp5-003-P', 'mate');
        $zen = $this->cardByNo('PL!HS-pb1-029-L', 'zen');

        $state = [
            'status' => 'playing',
            'phase' => 'live_start_effects',
            'seq' => 1,
            'turn' => 2,
            'first_player' => 'p1',
            'active_player' => 'p1',
            'live_attempt' => ['p1'],
            'log' => [],
            'players' => $this->basePlayers(),
        ];
        $state['players']['p1']['stage']['center'] = $rurino;
        $state['players']['p1']['stage']['left'] = $mate;
        $state['players']['p1']['hand'] = [
            [
                'instance_id' => 'hand_hs',
                'card_type' => 'メンバー',
                'group' => 'Hasunosora',
                'name_en' => 'Hand HS',
            ],
            [
                'instance_id' => 'hand_hs2',
                'card_type' => 'メンバー',
                'group' => 'Hasunosora',
                'name_en' => 'Hand HS 2',
            ],
        ];
        $state['players']['p1']['live_zone'] = [$zen];
        $deckBefore = count($state['players']['p1']['main_deck']);

        $GLOBALS['TUT_PERF_MANUAL_PHASES'] = true;
        try {
            $state = \resolveLiveStartAbilities($state, 'p1');
            $this->assertSame('live_start_order_sources', $state['pending_prompt']['type'] ?? null);
            $this->assertEmpty($state['_deferred_mp_extra_hearts'] ?? null);
            $this->assertSame($deckBefore, count($state['players']['p1']['main_deck']));

            $state = $this->driveLiveStartPrompts($state);
            if (empty($state['pending_prompt'])) {
                $state = \finishLiveStartEffects($state, false);
            }

            $this->assertSame($deckBefore - 1, count($state['players']['p1']['main_deck']));

            $zenAfter = null;
            foreach ($state['players']['p1']['live_zone'] as $lc) {
                if (($lc['instance_id'] ?? '') === 'zen') {
                    $zenAfter = $lc;
                    break;
                }
            }
            $this->assertNotNull($zenAfter);
            $this->assertSame(
                2,
                intval($zenAfter['hearts_color_reduction']['any'] ?? 0),
                'Zenhoui must reduce any-color hearts on the Live card'
            );
            $req = \applyLiveHeartReductions(
                $zenAfter['required_hearts'] ?? $zenAfter['hearts'] ?? [],
                $zenAfter
            );
            $any = 0;
            foreach ($req as $row) {
                if (\normalizeRequiredHeartColor((string)($row['color'] ?? '')) === 'any') {
                    $any += intval($row['count'] ?? 0);
                }
            }
            $this->assertSame(6, $any, 'Original 8 any − 2 = 6');
        } finally {
            unset($GLOBALS['TUT_PERF_MANUAL_PHASES']);
        }
    }

    public function testStageHeartsSnapshotSurvivesClearLiveModifiers(): void
    {
        $state = [
            'status' => 'playing',
            'phase' => 'live_judge',
            'seq' => 10,
            'turn' => 3,
            'first_player' => 'p1',
            'active_player' => 'p1',
            'live_attempt' => ['p1'],
            'live_perf_success' => ['p1' => ['live1'], 'p2' => []],
            'live_round_success' => ['p1' => true, 'p2' => false],
            'yell_reveal' => ['p1' => [], 'p2' => []],
            'log' => [],
            'players' => $this->basePlayers(),
            'live_modifiers' => [
                'p1' => ['bonus_hearts' => ['pink'], 'score_bonus' => 0, 'blade_bonus' => 0],
                'p2' => ['bonus_hearts' => [], 'score_bonus' => 0, 'blade_bonus' => 0],
            ],
        ];
        $m = $this->cardByNo('PL!HS-bp5-003-R＋', 'rurino');
        \addBonusHeartsToMember($m, [['color' => 'pink', 'count' => 1]]);
        $state['players']['p1']['stage']['center'] = $m;
        $state['players']['p1']['token'] = 'tok1';
        $state['players']['p2']['token'] = 'tok2';

        $before = \aggregateStageHeartsByColor($state['players']['p1']['stage']);
        $before = \mergeHeartColorCounts(
            $before,
            \aggregateFlatHeartColors(\getBonusHeartsFlat($state, 'p1'))
        );

        $state = \completeLiveRoundTurnAdvance($state, [
            'kind' => 'judge',
            'attempting' => ['p1'],
            'success_placed_by' => ['p1'],
        ]);

        $this->assertNotEmpty($state['_stage_hearts_snapshot']['p1'] ?? null);
        $this->assertSame($before, $state['_stage_hearts_snapshot']['p1']);
        // Member bonus cleared after Live, but snapshot kept for spectacle.
        $this->assertArrayNotHasKey('bonus_hearts', $state['players']['p1']['stage']['center'] ?? []);

        $filtered = \filterStateForPlayer($state, 'tok1');
        // Live HUD must use current stage (bonus cleared); snapshot stays for spectacle.
        $liveHearts = \aggregateStageHeartsByColor($state['players']['p1']['stage']);
        $this->assertSame(
            $liveHearts,
            $filtered['stage_board']['mine']['stage_hearts'] ?? null
        );
        $this->assertSame(
            $before,
            $filtered['stage_board']['mine']['perf_stage_hearts'] ?? null
        );
    }
}
