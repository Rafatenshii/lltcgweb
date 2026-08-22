<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * SPSD02 Superstar!! cheer — skill correctness + softlock safety.
 */
final class Spsd02CheerSkillsTest extends TestCase
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
        $this->fail('Missing card ' . $cardNo);
    }

    private function energyStub(string $id): array
    {
        return [
            'instance_id' => $id,
            'card_no' => 'LL-E-005-SD',
            'card_type' => 'エネルギー',
            'card_type_en' => 'Energy',
            'active' => true,
        ];
    }

    private function baseState(): array
    {
        return [
            'status' => 'playing',
            'phase' => 'live_start_effects',
            'seq' => 1,
            'turn' => 3,
            'first_player' => 'p1',
            'active_player' => 'p1',
            'live_attempt' => ['p1'],
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

    /** @return list<array> */
    private function sevenEnergy(): array
    {
        $out = [];
        for ($i = 0; $i < 7; $i++) {
            $out[] = $this->energyStub('e_' . $i);
        }
        return $out;
    }

    public function testNatsumiPickResumesLiveStart(): void
    {
        $GLOBALS['TUT_PERF_MANUAL_PHASES'] = true;
        try {
            $natsumi = $this->cardByNo('PL!SP-sd2-020-SD2', 'natsumi_1');
            $ren = $this->cardByNo('PL!SP-sd2-005-SD2', 'ren_1');
            $state = $this->baseState();
            $state['players']['p1']['stage']['center'] = $natsumi;
            $state['players']['p1']['stage']['right'] = $ren;
            $state['players']['p1']['energy_zone'] = $this->sevenEnergy();
            $state['players']['p1']['live_zone'] = [[
                'instance_id' => 'live_filler',
                'card_no' => 'PL!SP-sd2-024-SD2',
                'name_en' => 'Aikotoba!',
                'card_type' => 'ライブ',
                'group' => 'Superstar',
                'score' => 1,
                'face_up' => true,
            ]];

            $state = \resolveLiveStartAbilities($state, 'p1');
            $this->assertSame('on_enter_blade_self_and_pick_group', $state['pending_prompt']['type'] ?? null);
            $this->assertSame(1, intval($state['players']['p1']['stage']['center']['live_blade_bonus'] ?? 0));

            $state = \actionResolvePrompt($state, 'p1', ['slot' => 'right']);
            $this->assertNull($state['pending_prompt'] ?? null);
            $this->assertSame(1, intval($state['players']['p1']['stage']['right']['live_blade_bonus'] ?? 0));
            $this->assertSame(1, intval($state['players']['p1']['stage']['center']['live_blade_bonus'] ?? 0));
            $this->assertArrayNotHasKey('live_start_optional_queue', $state);
            // Softlock guard: finishPromptEffects must run (queue cleared / phase may stay for manual).
            $this->assertTrue(
                ($state['phase'] ?? '') !== 'live_start_effects'
                || !isset($state['live_start_optional_queue']),
                'Natsumi pick must resume via finishPromptEffects'
            );
        } finally {
            unset($GLOBALS['TUT_PERF_MANUAL_PHASES']);
        }
    }

    public function testNatsumiInvalidSlotSkipsWithoutSoftlock(): void
    {
        $GLOBALS['TUT_PERF_MANUAL_PHASES'] = true;
        try {
            $natsumi = $this->cardByNo('PL!SP-sd2-020-SD2', 'natsumi_2');
            $ren = $this->cardByNo('PL!SP-sd2-005-SD2', 'ren_2');
            $state = $this->baseState();
            $state['players']['p1']['stage']['center'] = $natsumi;
            $state['players']['p1']['stage']['right'] = $ren;
            $state['players']['p1']['energy_zone'] = $this->sevenEnergy();
            $state['players']['p1']['live_zone'] = [[
                'instance_id' => 'live_filler2',
                'card_no' => 'PL!SP-sd2-024-SD2',
                'card_type' => 'ライブ',
                'group' => 'Superstar',
                'score' => 1,
                'face_up' => true,
            ]];

            $state = \resolveLiveStartAbilities($state, 'p1');
            $this->assertSame('on_enter_blade_self_and_pick_group', $state['pending_prompt']['type'] ?? null);

            $state = \actionResolvePrompt($state, 'p1', ['slot' => 'left']); // empty / not a candidate
            $this->assertNull($state['pending_prompt'] ?? null);
            $this->assertSame(0, intval($state['players']['p1']['stage']['right']['live_blade_bonus'] ?? 0));
            $this->assertSame(1, intval($state['players']['p1']['stage']['center']['live_blade_bonus'] ?? 0));
            $this->assertArrayNotHasKey('live_start_optional_queue', $state);
        } finally {
            unset($GLOBALS['TUT_PERF_MANUAL_PHASES']);
        }
    }

    public function testNatsumiPickDoesNotSoftlockWithoutManualPerfFlag(): void
    {
        $natsumi = $this->cardByNo('PL!SP-sd2-020-SD2', 'natsumi_soft');
        $ren = $this->cardByNo('PL!SP-sd2-005-SD2', 'ren_soft');
        $state = $this->baseState();
        $state['players']['p1']['stage']['center'] = $natsumi;
        $state['players']['p1']['stage']['right'] = $ren;
        $state['players']['p1']['energy_zone'] = $this->sevenEnergy();
        $state['players']['p1']['live_zone'] = [[
            'instance_id' => 'live_soft',
            'card_no' => 'PL!SP-sd2-024-SD2',
            'card_type' => 'ライブ',
            'group' => 'Superstar',
            'score' => 1,
            'face_up' => true,
        ]];

        $state = \resolveLiveStartAbilities($state, 'p1');
        $this->assertSame('on_enter_blade_self_and_pick_group', $state['pending_prompt']['type'] ?? null);
        $state = \actionResolvePrompt($state, 'p1', ['slot' => 'right']);
        $this->assertNull($state['pending_prompt'] ?? null);
        $this->assertNotSame(
            'live_start_effects',
            $state['phase'] ?? null,
            'Bare return used to softlock here; finishPromptEffects must advance the phase'
        );
    }

    public function testNatsumiSoloSelfBladeNoPromptAdvances(): void
    {
        $GLOBALS['TUT_PERF_MANUAL_PHASES'] = true;
        try {
            $natsumi = $this->cardByNo('PL!SP-sd2-020-SD2', 'natsumi_solo');
            $state = $this->baseState();
            $state['players']['p1']['stage']['center'] = $natsumi;
            $state['players']['p1']['energy_zone'] = $this->sevenEnergy();
            $state['players']['p1']['live_zone'] = [[
                'instance_id' => 'live_solo',
                'card_no' => 'PL!SP-sd2-024-SD2',
                'card_type' => 'ライブ',
                'group' => 'Superstar',
                'score' => 1,
                'face_up' => true,
            ]];

            $state = \resolveLiveStartAbilities($state, 'p1');
            $this->assertNull($state['pending_prompt'] ?? null);
            $this->assertSame(1, intval($state['players']['p1']['stage']['center']['live_blade_bonus'] ?? 0));
            $state = \finishLiveStartEffects($state);
            $this->assertArrayNotHasKey('live_start_optional_queue', $state);
        } finally {
            unset($GLOBALS['TUT_PERF_MANUAL_PHASES']);
        }
    }

    public function testShikiHeartOnlyWhenOwnStageHasCost13(): void
    {
        $shiki = $this->cardByNo('PL!SP-sd2-008-SD2', 'shiki_1');
        $this->assertTrue(!empty($shiki['abilities'][0]['self_only']));

        $keke = $this->cardByNo('PL!SP-sd2-002-SD2', 'keke_cost13');
        $this->assertGreaterThanOrEqual(13, intval($keke['cost'] ?? 0));

        $state = $this->baseState();
        $state['phase'] = 'main';
        $state['players']['p1']['stage']['center'] = $shiki;
        $state['players']['p1']['stage']['right'] = $keke;

        $grants = \collectContinuousPerformanceHeartGrants($state, 'p1');
        $shikiHearts = [];
        foreach ($grants as $g) {
            if (($g['instance_id'] ?? '') === 'shiki_1') {
                $shikiHearts = $g['hearts'] ?? [];
                break;
            }
        }
        $this->assertContains('yellow', $shikiHearts, 'Own cost-13+ member must grant Shiki yellow heart');

        $stateOppOnly = $this->baseState();
        $stateOppOnly['phase'] = 'main';
        $stateOppOnly['players']['p1']['stage']['center'] = $this->cardByNo('PL!SP-sd2-008-SD2', 'shiki_2');
        $stateOppOnly['players']['p2']['stage']['center'] = $this->cardByNo('PL!SP-sd2-002-SD2', 'opp_keke');

        $grantsOpp = \collectContinuousPerformanceHeartGrants($stateOppOnly, 'p1');
        $shikiOppHearts = [];
        foreach ($grantsOpp as $g) {
            if (($g['instance_id'] ?? '') === 'shiki_2') {
                $shikiOppHearts = $g['hearts'] ?? [];
                break;
            }
        }
        $this->assertSame(
            [],
            $shikiOppHearts,
            'Opponent-only cost-13+ must NOT grant Shiki heart (self_only)'
        );
    }

    public function testOptionalSwapSkipClearsPrompt(): void
    {
        $ren = $this->cardByNo('PL!SP-sd2-005-SD2', 'ren_swap');
        $state = $this->baseState();
        $state['phase'] = 'main';
        $state['players']['p1']['stage']['center'] = $ren;

        $state = \resolveOnEnterAbilities($state, 'p1', $ren, 'center');
        $this->assertSame('optional_swap_area_on_enter', $state['pending_prompt']['type'] ?? null);

        $state = \actionResolvePrompt($state, 'p1', ['choice' => 'skip']);
        $this->assertNull($state['pending_prompt'] ?? null);
        $this->assertSame('PL!SP-sd2-005-SD2', $state['players']['p1']['stage']['center']['card_no'] ?? null);
    }

    public function testOptionalSwapMoveSetsMovedFlag(): void
    {
        $ren = $this->cardByNo('PL!SP-sd2-005-SD2', 'ren_move');
        $mei = $this->cardByNo('PL!SP-sd2-007-SD2', 'mei_1');
        $state = $this->baseState();
        $state['phase'] = 'main';
        $state['players']['p1']['stage']['center'] = $ren;
        $state['players']['p1']['stage']['left'] = $mei;

        $state = \resolveOnEnterAbilities($state, 'p1', $ren, 'center');
        $state = \actionResolvePrompt($state, 'p1', ['choice' => 'left']);
        $this->assertNull($state['pending_prompt'] ?? null);
        $this->assertSame('PL!SP-sd2-005-SD2', $state['players']['p1']['stage']['left']['card_no'] ?? null);
        $this->assertTrue(!empty($state['players']['p1']['stage']['left']['moved_this_turn']));
        $this->assertSame('Superstar', $state['players']['p1']['stage']['left']['moved_by_group_effect'] ?? '');
        $this->assertSame('PL!SP-sd2-007-SD2', $state['players']['p1']['stage']['center']['card_no'] ?? null);
        $this->assertTrue(!empty($state['players']['p1']['stage']['center']['moved_this_turn']));
        $this->assertSame('Superstar', $state['players']['p1']['stage']['center']['moved_by_group_effect'] ?? '');
    }

    public function testAspireBladesMovedSuperstarOnly(): void
    {
        $aspire = $this->cardByNo('PL!SP-sd2-025-SD2', 'aspire_1');
        $moved = $this->cardByNo('PL!SP-sd2-005-SD2', 'moved_ren');
        $moved['moved_this_turn'] = true;
        $still = $this->cardByNo('PL!SP-sd2-007-SD2', 'still_mei');

        $state = $this->baseState();
        $state['players']['p1']['stage']['left'] = $moved;
        $state['players']['p1']['stage']['right'] = $still;
        $state['players']['p1']['live_zone'] = [$aspire];

        $GLOBALS['TUT_PERF_MANUAL_PHASES'] = true;
        try {
            $state = \resolveLiveStartAbilities($state, 'p1');
            $this->assertNull($state['pending_prompt'] ?? null);
            $this->assertSame(1, intval($state['players']['p1']['stage']['left']['live_blade_bonus'] ?? 0));
            $this->assertSame(0, intval($state['players']['p1']['stage']['right']['live_blade_bonus'] ?? 0));
        } finally {
            unset($GLOBALS['TUT_PERF_MANUAL_PHASES']);
        }
    }

    public function testHajimariScoreAndHeartsWithTwoSuccess(): void
    {
        $hajimari = $this->cardByNo('PL!SP-sd2-023-SD2', 'hajimari_1');
        $state = $this->baseState();
        $state['players']['p1']['live_zone'] = [$hajimari];
        $state['players']['p1']['success_lives'] = [
            ['instance_id' => 'succ_a', 'card_type' => 'ライブ', 'score' => 1],
            ['instance_id' => 'succ_b', 'card_type' => 'ライブ', 'score' => 1],
        ];

        $GLOBALS['TUT_PERF_MANUAL_PHASES'] = true;
        try {
            $state = \resolveLiveStartAbilities($state, 'p1');
            $live = $state['players']['p1']['live_zone'][0] ?? [];
            $this->assertSame(6, intval($live['score'] ?? 0), 'Base score 1 + bonus 5');
            $req = $live['required_hearts'] ?? [];
            $byColor = [];
            foreach ($req as $h) {
                $byColor[$h['color'] ?? ''] = intval($h['count'] ?? 0);
            }
            $this->assertSame(3, $byColor['red'] ?? 0);
            $this->assertSame(3, $byColor['yellow'] ?? 0);
            $this->assertSame(3, $byColor['purple'] ?? 0);
            $this->assertSame(3, $byColor['gray'] ?? 0);
        } finally {
            unset($GLOBALS['TUT_PERF_MANUAL_PHASES']);
        }
    }

    public function testRegistryKnowsSpsd02AbilityTypes(): void
    {
        $known = \LLTCG\Game\EffectRegistry::knownAbilityTypes();
        foreach ([
            'draw_extra_if_moved_on_enter',
            'continuous_blade_bonus',
            'optional_swap_area_on_enter',
            'blade_if_either_stage_cost_min',
            'leave_stage_add_from_wr',
            'on_enter_blade_self_and_pick_group',
            'live_start_score_wild_if_success',
            'live_start_blade_moved_members',
        ] as $type) {
            $this->assertContains($type, $known, "EffectRegistry must know $type");
        }
    }
}
