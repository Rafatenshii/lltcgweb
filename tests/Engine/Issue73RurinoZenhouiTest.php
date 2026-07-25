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
        $state = \resolveLiveStartAbilities($state, 'p1');
        $state = \finishLiveStartEffects($state, false);
        $this->assertSame('optional_live_start', $state['pending_prompt']['type'] ?? null);

        $handId = $state['players']['p1']['hand'][0]['instance_id'] ?? '';
        $state = \actionResolvePrompt($state, 'p1', [
            'choice' => 'yes',
            'discard_ids' => [$handId],
        ]);
        if (($state['pending_prompt']['type'] ?? '') === 'buff_member_matching_discarded_group') {
            $state = \actionResolvePrompt($state, 'p1', ['slot' => 'center']);
        }
        // Finish remaining optionals / deferred Zenhoui without entering Performance.
        $guard = 0;
        while (!empty($state['pending_prompt']) && $guard++ < 8) {
            $ptype = $state['pending_prompt']['type'] ?? '';
            if ($ptype === 'optional_live_start') {
                $state = \actionResolvePrompt($state, 'p1', ['choice' => 'no']);
                continue;
            }
            if ($ptype === 'buff_member_matching_discarded_group') {
                $state = \actionResolvePrompt($state, 'p1', ['slot' => 'left']);
                continue;
            }
            break;
        }
        if (empty($state['pending_prompt'])) {
            $state = \finishLiveStartEffects($state, false);
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
        $state['players']['p1']['hand'] = [[
            'instance_id' => 'hand_hs',
            'card_type' => 'メンバー',
            'group' => 'Hasunosora',
            'name_en' => 'Hand HS',
        ]];
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
        $state['players']['p1']['hand'] = [[
            'instance_id' => 'hand_hs',
            'card_type' => 'メンバー',
            'group' => 'Hasunosora',
            'name_en' => 'Hand HS',
        ]];
        $state['players']['p1']['live_zone'] = [$zen];
        $deckBefore = count($state['players']['p1']['main_deck']);

        $GLOBALS['TUT_PERF_MANUAL_PHASES'] = true;
        $state = \resolveLiveStartAbilities($state, 'p1');
        // Deferred only — Zenhoui must not draw yet.
        $this->assertNotEmpty($state['_deferred_mp_extra_hearts'] ?? null);
        $this->assertSame($deckBefore, count($state['players']['p1']['main_deck']));

        $state = \finishLiveStartEffects($state, false);
        $handId = $state['players']['p1']['hand'][0]['instance_id'] ?? '';
        $state = \actionResolvePrompt($state, 'p1', [
            'choice' => 'yes',
            'discard_ids' => [$handId],
        ]);
        if (($state['pending_prompt']['type'] ?? '') === 'buff_member_matching_discarded_group') {
            $state = \actionResolvePrompt($state, 'p1', ['slot' => 'center']);
        }
        // Skip the second Rurino optional if any.
        while (($state['pending_prompt']['type'] ?? '') === 'optional_live_start') {
            $state = \actionResolvePrompt($state, 'p1', ['choice' => 'no']);
        }
        if (empty($state['pending_prompt'])) {
            $state = \finishLiveStartEffects($state, false);
        }

        // One Mira-Cra with extra hearts → draw 1; need two for reduce.
        $this->assertSame($deckBefore - 1, count($state['players']['p1']['main_deck']));

        // Grant a second extra-heart Mira-Cra and re-apply reduce branch.
        \addBonusHeartsToMember($state['players']['p1']['stage']['left'], [['color' => 'pink', 'count' => 1]]);
        $state['_deferred_mp_extra_hearts'] = [[
            'pid' => 'p1',
            'source_id' => 'zen',
            'name' => 'Zenhoui Kyun♡',
            'ability' => $zen['abilities'][0],
        ]];
        $deckMid = count($state['players']['p1']['main_deck']);
        $state = \flushDeferredMpExtraHeartsLiveStart($state);
        $this->assertSame($deckMid - 1, count($state['players']['p1']['main_deck']));

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
            intval($zenAfter['hearts_reduction'] ?? 0),
            'Zenhoui must use hearts_reduction (not heart_reduction typo)'
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
