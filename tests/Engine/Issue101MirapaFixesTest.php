<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * GitHub #101 — Mira-Cra Park! cards: Wait activate picker, cannot-live dump,
 * Position Change swap, Hime hand-cost −2 per stage subunit.
 */
final class Issue101MirapaFixesTest extends TestCase
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

    private function emptyPlayer(string $id, string $name): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'hand' => [],
            'stage' => ['left' => null, 'center' => null, 'right' => null],
            'waiting_room' => [],
            'energy_zone' => [],
            'main_deck' => [
                ['instance_id' => $id . '_d0', 'card_type' => 'メンバー', 'name_en' => 'Draw'],
                ['instance_id' => $id . '_d1', 'card_type' => 'メンバー', 'name_en' => 'Draw2'],
            ],
            'energy_deck' => [],
            'live_zone' => [],
            'success_lives' => [],
        ];
    }

    private function baseState(array $p1, array $p2, string $phase = 'main_first'): array
    {
        return [
            'status' => 'playing',
            'phase' => $phase,
            'seq' => 4,
            'turn' => 3,
            'first_player' => 'p1',
            'active_player' => 'p1',
            'log' => [],
            'players' => ['p1' => $p1, 'p2' => $p2],
        ];
    }

    private function miraCraStub(string $id): array
    {
        return [
            'instance_id' => $id,
            'card_no' => 'PL!HS-sd1-003-SD',
            'name_en' => 'Mira stub',
            'card_type' => 'メンバー',
            'group' => 'Hasunosora',
            'subunit' => 'みらくらぱーく！',
            'active' => true,
            'cost' => 3,
            'blade' => 1,
        ];
    }

    public function testBp6003OnEnterActivatesWaitAndAddsSubunitLive(): void
    {
        $ruri = $this->cardByNo('PL!HS-bp6-003-P', 'ruri_enter');
        $wait = $this->miraCraStub('wait_mira');
        $live = $this->cardByNo('PL!HS-bp6-030-L', 'mira_live');
        $p1 = $this->emptyPlayer('p1', 'P1');
        $p1['stage']['left'] = $wait;
        \waitMember($p1['stage']['left'], ['phase' => 'main_first', 'turn' => 3, 'active_player' => 'p1']);
        $p1['stage']['center'] = $ruri;
        $p1['waiting_room'] = [$live];

        $state = $this->baseState($p1, $this->emptyPlayer('p2', 'P2'));
        $state = \resolveAbilityEffect($state, 'p1', $ruri, $ruri['abilities'][0], [
            'phase' => 'on_enter',
            'slot' => 'center',
        ]);
        $this->assertSame('optional_activate_wait_subunit_add_live_wr', $state['pending_prompt']['type'] ?? null);

        $state = \actionResolvePrompt($state, 'p1', ['choice' => 'yes']);
        $this->assertSame('pick_wr_to_hand', $state['pending_prompt']['type'] ?? null);
        $this->assertFalse(\memberIsInWait($state['players']['p1']['stage']['left']));
        $this->assertTrue($state['players']['p1']['stage']['left']['active'] ?? false);

        $state = \actionResolvePrompt($state, 'p1', ['card_id' => 'mira_live']);
        $this->assertNull($state['pending_prompt'] ?? null);
        $this->assertSame('mira_live', $state['players']['p1']['hand'][0]['instance_id'] ?? null);
        $this->assertCount(0, $state['players']['p1']['waiting_room']);
    }

    public function testBp6003OnEnterLetsPlayerChooseAmongWrLives(): void
    {
        $ruri = $this->cardByNo('PL!HS-bp6-003-P', 'ruri_enter2');
        $wait = $this->miraCraStub('wait_mira2');
        $liveA = $this->cardByNo('PL!HS-bp6-030-L', 'mira_live_a');
        $liveB = $this->cardByNo('PL!HS-bp6-030-L', 'mira_live_b');
        $p1 = $this->emptyPlayer('p1', 'P1');
        $p1['stage']['left'] = $wait;
        \waitMember($p1['stage']['left'], ['phase' => 'main_first', 'turn' => 3, 'active_player' => 'p1']);
        $p1['stage']['center'] = $ruri;
        $p1['waiting_room'] = [$liveA, $liveB];

        $state = $this->baseState($p1, $this->emptyPlayer('p2', 'P2'));
        $state = \resolveAbilityEffect($state, 'p1', $ruri, $ruri['abilities'][0], [
            'phase' => 'on_enter',
            'slot' => 'center',
        ]);
        $state = \actionResolvePrompt($state, 'p1', ['choice' => 'yes']);
        $this->assertSame('pick_wr_to_hand', $state['pending_prompt']['type'] ?? null);
        $candIds = array_column($state['pending_prompt']['candidates'] ?? [], 'instance_id');
        $this->assertContains('mira_live_a', $candIds);
        $this->assertContains('mira_live_b', $candIds);

        $state = \actionResolvePrompt($state, 'p1', ['card_id' => 'mira_live_b']);
        $this->assertNull($state['pending_prompt'] ?? null);
        $this->assertSame('mira_live_b', $state['players']['p1']['hand'][0]['instance_id'] ?? null);
        $this->assertSame('mira_live_a', $state['players']['p1']['waiting_room'][0]['instance_id'] ?? null);
    }

    public function testBp6003IgnoresInactiveNonWaitMembers(): void
    {
        $ruri = $this->cardByNo('PL!HS-bp6-003-P', 'ruri_skip');
        $rested = $this->miraCraStub('rested_not_wait');
        $rested['active'] = false;
        $p1 = $this->emptyPlayer('p1', 'P1');
        $p1['stage']['left'] = $rested;
        $p1['stage']['center'] = $ruri;
        $p1['waiting_room'] = [$this->cardByNo('PL!HS-bp6-030-L', 'mira_live2')];

        $state = $this->baseState($p1, $this->emptyPlayer('p2', 'P2'));
        $state = \resolveAbilityEffect($state, 'p1', $ruri, $ruri['abilities'][0], [
            'phase' => 'on_enter',
            'slot' => 'center',
        ]);
        $this->assertNull($state['pending_prompt'] ?? null);
        $this->assertCount(0, $state['players']['p1']['hand']);
    }

    public function testBp2014CannotLiveDumpsSetLivesBeforePerformance(): void
    {
        $ruri = $this->cardByNo('PL!HS-bp2-014-N', 'ruri_ban');
        $live = $this->cardByNo('PL!HS-bp2-019-L', 'set_live');
        $p1 = $this->emptyPlayer('p1', 'P1');
        $p1['stage']['center'] = $ruri;
        $p1['live_zone'] = [$live];
        $p2 = $this->emptyPlayer('p2', 'P2');
        $p2['live_zone'] = [$this->cardByNo('PL!HS-bp2-019-L', 'opp_live')];

        $state = $this->baseState($p1, $p2, 'live_set');
        $state = \resolveAbilityEffect($state, 'p1', $ruri, $ruri['abilities'][0], [
            'phase' => 'on_enter',
            'slot' => 'center',
        ]);
        $this->assertTrue(!empty($state['live_modifiers']['p1']['cannot_live']));
        $this->assertFalse(\playerAttemptingLivePerformance($state, 'p1'));
        $this->assertTrue(\playerAttemptingLivePerformance($state, 'p2'));

        $state = \beginPerformancePhase($state);
        $wrIds = array_column($state['players']['p1']['waiting_room'], 'instance_id');
        $this->assertContains('set_live', $wrIds);
        $this->assertSame(['p2'], $state['live_attempt'] ?? null);
        foreach ($state['players']['p1']['live_zone'] as $c) {
            $this->assertFalse(\isLiveTypeCard($c));
        }
    }

    public function testBp5003PositionChangeSwapsChosenMember(): void
    {
        $ruri = $this->cardByNo('PL!HS-bp5-003-P', 'ruri_leave');
        $left = $this->miraCraStub('stay_left');
        $center = $this->miraCraStub('stay_center');
        $p1 = $this->emptyPlayer('p1', 'P1');
        $p1['stage'] = ['left' => $left, 'center' => $center, 'right' => null];
        $p1['waiting_room'] = [$ruri];

        $state = $this->baseState($p1, $this->emptyPlayer('p2', 'P2'));
        $state = \resolveAbilityEffect($state, 'p1', $ruri, $ruri['abilities'][0], [
            'phase' => 'on_leave_stage',
        ]);
        $this->assertSame('optional_stage_reposition', $state['pending_prompt']['type'] ?? null);

        $state = \actionResolvePrompt($state, 'p1', ['choice' => 'yes']);
        $this->assertSame('pick_member', $state['pending_prompt']['step'] ?? null);
        $state = \actionResolvePrompt($state, 'p1', ['card_id' => 'stay_left', 'slot' => 'left']);
        $this->assertSame('pick_dest', $state['pending_prompt']['step'] ?? null);
        $state = \actionResolvePrompt($state, 'p1', ['slot' => 'right']);

        $this->assertNull($state['pending_prompt'] ?? null);
        $this->assertSame('stay_left', $state['players']['p1']['stage']['right']['instance_id'] ?? null);
        $this->assertNull($state['players']['p1']['stage']['left']);
        $this->assertSame('stay_center', $state['players']['p1']['stage']['center']['instance_id'] ?? null);
        $this->assertTrue($state['players']['p1']['stage']['right']['active'] ?? false);
    }

    public function testBp6006HandCostMinusTwoPerMiraCraOnStage(): void
    {
        $hime = $this->cardByNo('PL!HS-bp6-006-P', 'hime_hand');
        $p1 = $this->emptyPlayer('p1', 'P1');
        $p1['hand'] = [$hime];
        $p1['stage']['left'] = $this->miraCraStub('m1');
        $p1['stage']['center'] = $this->miraCraStub('m2');
        $p1['stage']['right'] = $this->miraCraStub('m3');
        $state = $this->baseState($p1, $this->emptyPlayer('p2', 'P2'));

        $this->assertSame(14, \getEffectiveHandCost($state, 'p1', $hime));
    }

    /** PL!HS-bp2-006-R Always: +1 Blade per other Mira-Cra — bang variants must match. */
    public function testBp2006MegumiBladePerOtherMiraCraDespiteBangVariant(): void
    {
        $megumi = $this->cardByNo('PL!HS-bp2-006-R', 'megumi_r');
        $this->assertSame('blade_per_other_subunit', $megumi['abilities'][1]['type'] ?? null);
        // Catalog ability uses half-width !; many stage copies use full-width ！.
        $this->assertSame('みらくらぱーく!', $megumi['abilities'][1]['subunit'] ?? null);

        $other = $this->miraCraStub('mira_fw');
        $this->assertSame('みらくらぱーく！', $other['subunit']);

        $p1 = $this->emptyPlayer('p1', 'P1');
        $p1['stage']['center'] = $megumi;
        $p1['stage']['left'] = $other;
        $state = $this->baseState($p1, $this->emptyPlayer('p2', 'P2'));

        $printed = intval($megumi['blade'] ?? 0);
        $blade = \getMemberBlade($megumi, $state, 'p1', 'center');
        $this->assertSame(
            $printed + 1,
            $blade,
            'Megumi Always must count full-width Mira-Cra Park! stage mates'
        );
    }
}
