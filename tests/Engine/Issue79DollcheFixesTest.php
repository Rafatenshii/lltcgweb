<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/** Regression tests for GitHub issue #79 (Dollche). */
final class Issue79DollcheFixesTest extends TestCase
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
            'energy_deck' => [],
            'live_zone' => [],
            'success_lives' => [],
        ];
    }

    public function testSummerSayakaOptionalShowsYesNoThenDraws(): void
    {
        $sayaka = $this->cardByNo('PL!HS-bp6-010-R', 'summer');
        $handDoll = $this->cardByNo('PL!HS-bp5-008-R', 'hand_doll');
        $handDoll['subunit'] = 'DOLLCHESTRA';

        $p1 = $this->emptyPlayer('p1', 'P1');
        $p1['stage']['center'] = $sayaka;
        $p1['hand'] = [$handDoll];
        $p1['main_deck'] = [
            ['instance_id' => 'draw1', 'card_type' => 'メンバー', 'name_en' => 'Drawn'],
        ];

        $state = [
            'status' => 'playing',
            'phase' => 'live_start_effects',
            'seq' => 1,
            'turn' => 2,
            'first_player' => 'p1',
            'live_attempt' => ['p1'],
            'log' => [],
            'players' => ['p1' => $p1, 'p2' => $this->emptyPlayer('p2', 'P2')],
        ];

        $GLOBALS['TUT_PERF_MANUAL_PHASES'] = true;
        try {
            $state = \resolveLiveStartAbilities($state, 'p1');
            $this->assertSame('optional_discard_subunit_draw_buff_cost', $state['pending_prompt']['type'] ?? null);
            $this->assertSame(['yes', 'no'], $state['pending_prompt']['choices'] ?? null);

            $state = \actionResolvePrompt($state, 'p1', [
                'choice' => 'yes',
                'discard_ids' => ['hand_doll'],
            ]);
            $this->assertSame('pick_member_cost_bonus', $state['pending_prompt']['type'] ?? null);
            $this->assertSame('draw1', $state['players']['p1']['hand'][0]['instance_id'] ?? null);

            $state = \actionResolvePrompt($state, 'p1', ['slot' => 'center']);
            $this->assertNull($state['pending_prompt'] ?? null);
            $this->assertSame(5, intval($state['players']['p1']['stage']['center']['live_cost_bonus'] ?? 0));
            $this->assertSame(
                intval($sayaka['cost'] ?? 0) + 5,
                \getEffectiveStageMemberCost($state, 'p1', $state['players']['p1']['stage']['center'])
            );

            $state = \clearLiveModifiers($state);
            $this->assertSame(0, intval($state['players']['p1']['stage']['center']['live_cost_bonus'] ?? 0));
            $this->assertArrayNotHasKey('live_cost_bonus', $state['players']['p1']['stage']['center']);
            $this->assertSame(
                intval($sayaka['cost'] ?? 0),
                \getEffectiveStageMemberCost($state, 'p1', $state['players']['p1']['stage']['center'])
            );
        } finally {
            unset($GLOBALS['TUT_PERF_MANUAL_PHASES']);
        }
    }

    public function testProofKosuzuCanSkipOptionalDiscard(): void
    {
        $kosuzu = $this->cardByNo('PL!HS-bp6-005-P＋', 'proof');
        $hand = $this->cardByNo('PL!HS-sd1-015-SD', 'hand1');

        $p1 = $this->emptyPlayer('p1', 'P1');
        $p1['stage']['center'] = $kosuzu;
        $p1['hand'] = [$hand];

        $state = [
            'status' => 'playing',
            'phase' => 'live_start_effects',
            'seq' => 1,
            'turn' => 2,
            'first_player' => 'p1',
            'live_attempt' => ['p1'],
            'log' => [],
            'players' => ['p1' => $p1, 'p2' => $this->emptyPlayer('p2', 'P2')],
        ];

        $GLOBALS['TUT_PERF_MANUAL_PHASES'] = true;
        try {
            $state = \resolveLiveStartAbilities($state, 'p1');
            $this->assertSame('optional_discard_prompt', $state['pending_prompt']['type'] ?? null);
            $this->assertContains('no', $state['pending_prompt']['choices'] ?? []);

            $state = \actionResolvePrompt($state, 'p1', ['choice' => 'no']);
            $this->assertNull($state['pending_prompt'] ?? null);
            $this->assertSame(0, intval($state['players']['p1']['stage']['center']['live_cost_bonus'] ?? 0));
            $this->assertCount(1, $state['players']['p1']['hand']);
        } finally {
            unset($GLOBALS['TUT_PERF_MANUAL_PHASES']);
        }
    }

    public function testFantasyKosuzuEqualCostGetsHandAndBlade(): void
    {
        $kosuzu = $this->cardByNo('PL!HS-pb1-005-R', 'fantasy');
        $top = $this->cardByNo('PL!HS-sd1-015-SD', 'top');
        $top['cost'] = 10;

        $p1 = $this->emptyPlayer('p1', 'P1');
        $p1['stage']['center'] = $kosuzu;
        $p1['main_deck'] = [$top];

        $state = [
            'status' => 'playing',
            'phase' => 'live_start_effects',
            'seq' => 1,
            'turn' => 2,
            'first_player' => 'p1',
            'live_attempt' => ['p1'],
            'log' => [],
            'players' => ['p1' => $p1, 'p2' => $this->emptyPlayer('p2', 'P2')],
        ];

        $GLOBALS['TUT_PERF_MANUAL_PHASES'] = true;
        try {
            $state = \resolveLiveStartAbilities($state, 'p1');
            $this->assertSame('pick_number', $state['pending_prompt']['step'] ?? null);
            $this->assertContains(25, $state['pending_prompt']['numbers'] ?? []);

            $state = \actionResolvePrompt($state, 'p1', ['choice' => '10', 'number' => 10]);
            $this->assertSame('resolve_reveal', $state['pending_prompt']['step'] ?? null);
            $this->assertSame('top', $state['pending_prompt']['revealed']['instance_id'] ?? null);
            // Still on deck until confirm.
            $this->assertSame('top', $state['players']['p1']['main_deck'][0]['instance_id'] ?? null);

            $state = \actionResolvePrompt($state, 'p1', ['choice' => 'confirm']);
            $this->assertNull($state['pending_prompt'] ?? null);
            $this->assertSame('top', $state['players']['p1']['hand'][0]['instance_id'] ?? null);
            $this->assertSame(2, intval($state['live_modifiers']['p1']['blade_bonus'] ?? 0));
        } finally {
            unset($GLOBALS['TUT_PERF_MANUAL_PHASES']);
        }
    }

    public function testPb1002LiveStartUsesStacks(): void
    {
        $sayaka = $this->cardByNo('PL!HS-pb1-002-R', 'sayaka');
        $this->assertSame('live_start', $sayaka['abilities'][1]['trigger'] ?? null);
        $sayaka['stacked_members'] = [
            ['instance_id' => 's1', 'card_type' => 'メンバー'],
            ['instance_id' => 's2', 'card_type' => 'メンバー'],
            ['instance_id' => 's3', 'card_type' => 'メンバー'],
        ];

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
            'players' => ['p1' => $p1, 'p2' => $this->emptyPlayer('p2', 'P2')],
        ];

        $GLOBALS['TUT_PERF_MANUAL_PHASES'] = true;
        try {
            $state = \resolveLiveStartAbilities($state, 'p1');
            $this->assertSame(
                14,
                \getEffectiveStageMemberCost($state, 'p1', $state['players']['p1']['stage']['center']),
                '2 + 4*3 stacks = 14'
            );
        } finally {
            unset($GLOBALS['TUT_PERF_MANUAL_PHASES']);
        }
    }
}
