<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * GitHub #102 — Dia bp5-004 +Blade Stage pick; Kanan bp3-003 discard Live → draw 3.
 */
final class Issue102AqoursCardFixesTest extends TestCase
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

    private function aqoursStub(string $id, string $name): array
    {
        return [
            'instance_id' => $id,
            'card_no' => 'PL!S-sd1-001-SD',
            'name_en' => $name,
            'card_type' => 'メンバー',
            'group' => 'Sunshine',
            'subunit' => 'CYaRon!',
            'active' => true,
            'cost' => 3,
            'blade' => 1,
        ];
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

    private function baseState(array $p1, array $p2): array
    {
        return [
            'status' => 'playing',
            'phase' => 'main_first',
            'seq' => 4,
            'turn' => 3,
            'first_player' => 'p1',
            'active_player' => 'p1',
            'log' => [],
            'players' => ['p1' => $p1, 'p2' => $p2],
        ];
    }

    public function testDiaOnEnterBladePickAppliesToChosenAqours(): void
    {
        $dia = $this->cardByNo('PL!S-bp5-004-R', 'dia_enter');
        $chika = $this->aqoursStub('chika_l', 'Chika');
        $you = $this->aqoursStub('you_r', 'You');
        $p1 = $this->emptyPlayer('p1', 'P1');
        $p1['stage'] = ['left' => $chika, 'center' => $dia, 'right' => $you];

        $state = $this->baseState($p1, $this->emptyPlayer('p2', 'P2'));
        $state = \resolveAbilityEffect($state, 'p1', $dia, $dia['abilities'][0], [
            'phase' => 'on_enter',
            'slot' => 'center',
        ]);
        $this->assertSame('sbp5_aqours_blade_or_position', $state['pending_prompt']['type'] ?? null);

        $state = \actionResolvePrompt($state, 'p1', ['choice' => 'blade']);
        $this->assertSame('sbp5_pick_stage_member_blade', $state['pending_prompt']['type'] ?? null);
        $this->assertCount(2, $state['pending_prompt']['candidates'] ?? []);

        $state = \actionResolvePrompt($state, 'p1', ['card_id' => 'you_r']);
        $this->assertNull($state['pending_prompt'] ?? null);
        $this->assertSame(1, intval($state['players']['p1']['stage']['right']['live_blade_bonus'] ?? 0));
        $this->assertSame(0, intval($state['players']['p1']['stage']['left']['live_blade_bonus'] ?? 0));
        $this->assertSame(0, intval($state['players']['p1']['stage']['center']['live_blade_bonus'] ?? 0));
    }

    public function testKananOnEnterDiscardLiveDrawsThree(): void
    {
        $kanan = $this->cardByNo('PL!S-bp3-003-R＋', 'kanan_enter');
        $live = $this->cardByNo('PL!S-bp3-019-L', 'hand_live');
        $p1 = $this->emptyPlayer('p1', 'P1');
        $p1['stage']['center'] = $kanan;
        $p1['hand'] = [$live];
        $p1['main_deck'] = [
            ['instance_id' => 'd1', 'card_type' => 'メンバー', 'name_en' => 'D1'],
            ['instance_id' => 'd2', 'card_type' => 'メンバー', 'name_en' => 'D2'],
            ['instance_id' => 'd3', 'card_type' => 'メンバー', 'name_en' => 'D3'],
            ['instance_id' => 'd4', 'card_type' => 'メンバー', 'name_en' => 'D4'],
        ];

        $state = $this->baseState($p1, $this->emptyPlayer('p2', 'P2'));
        $state = \resolveAbilityEffect($state, 'p1', $kanan, $kanan['abilities'][0], [
            'phase' => 'on_enter',
            'slot' => 'center',
        ]);
        $this->assertSame('optional_discard_prompt', $state['pending_prompt']['type'] ?? null);
        $this->assertSame('live', $state['pending_prompt']['ability']['filter'] ?? null);

        $state = \actionResolvePrompt($state, 'p1', [
            'choice' => 'yes',
            'discard_ids' => ['hand_live'],
        ]);
        $this->assertNull($state['pending_prompt'] ?? null);
        $handIds = array_column($state['players']['p1']['hand'], 'instance_id');
        $this->assertCount(3, $handIds);
        $this->assertContains('d1', $handIds);
        $this->assertSame('hand_live', $state['players']['p1']['waiting_room'][0]['instance_id'] ?? null);
        $this->assertStringContainsString('drew 3', implode("\n", array_column($state['log'], 'msg')));
    }

    public function testKananOnEnterRejectsNonLiveDiscard(): void
    {
        $kanan = $this->cardByNo('PL!S-bp3-003-P', 'kanan_bad');
        $member = $this->aqoursStub('hand_mbr', 'Ruby');
        $p1 = $this->emptyPlayer('p1', 'P1');
        $p1['stage']['center'] = $kanan;
        $p1['hand'] = [$member, $this->cardByNo('PL!S-bp3-019-L', 'hand_live2')];
        $p1['main_deck'] = [
            ['instance_id' => 'd1', 'card_type' => 'メンバー', 'name_en' => 'D1'],
        ];

        $state = $this->baseState($p1, $this->emptyPlayer('p2', 'P2'));
        $state = \resolveAbilityEffect($state, 'p1', $kanan, $kanan['abilities'][0], [
            'phase' => 'on_enter',
            'slot' => 'center',
        ]);
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Must discard a Live card');
        \actionResolvePrompt($state, 'p1', [
            'choice' => 'yes',
            'discard_ids' => ['hand_mbr'],
        ]);
    }

    /** Stale Stage ability IR (pre-#102, no then) + ASCII P+ card_no must still draw 3. */
    public function testKananPPlusStaleAbilityStillDrawsThreeViaCatalog(): void
    {
        $kanan = $this->cardByNo('PL!S-bp3-003-P＋', 'kanan_pplus');
        // Simulate older deck / import that used ASCII + and omitted then.
        $kanan['card_no'] = 'PL!S-bp3-003-P+';
        $kanan['abilities'] = [[
            'trigger' => 'on_enter',
            'type' => 'optional_discard_prompt',
            'discard' => 1,
            'filter' => 'live',
            'prompt' => 'Put 1 Live card from your hand into the Waiting Room: draw 3 cards?',
            // intentionally no then
        ]];
        $live = $this->cardByNo('PL!S-bp3-019-L', 'hand_live_pp');
        $p1 = $this->emptyPlayer('p1', 'P1');
        $p1['stage']['center'] = $kanan;
        $p1['hand'] = [$live];
        $p1['main_deck'] = [
            ['instance_id' => 'd1', 'card_type' => 'メンバー', 'name_en' => 'D1'],
            ['instance_id' => 'd2', 'card_type' => 'メンバー', 'name_en' => 'D2'],
            ['instance_id' => 'd3', 'card_type' => 'メンバー', 'name_en' => 'D3'],
        ];

        $state = $this->baseState($p1, $this->emptyPlayer('p2', 'P2'));
        $state = \resolveOnEnterAbilities($state, 'p1', $kanan, 'center');
        $this->assertSame('optional_discard_prompt', $state['pending_prompt']['type'] ?? null);
        $this->assertSame('draw_cards', $state['pending_prompt']['ability']['then']['type'] ?? null);
        $this->assertSame(3, intval($state['pending_prompt']['ability']['then']['count'] ?? 0));

        $state = \actionResolvePrompt($state, 'p1', [
            'choice' => 'yes',
            'discard_ids' => ['hand_live_pp'],
        ]);
        $this->assertNull($state['pending_prompt'] ?? null);
        $this->assertCount(3, $state['players']['p1']['hand']);
        $this->assertStringContainsString('drew 3', implode("\n", array_column($state['log'], 'msg')));
    }

    /** Mid-prompt resolve must refill missing then even if Stage still has stale IR. */
    public function testKananStalePendingPromptAbilityDrawsOnResolve(): void
    {
        $kanan = $this->cardByNo('PL!S-bp3-003-P＋', 'kanan_stale_pr');
        $kanan['card_no'] = 'PL!S-bp3-003-P+';
        $live = $this->cardByNo('PL!S-bp3-019-L', 'hand_live_stale');
        $p1 = $this->emptyPlayer('p1', 'P1');
        $p1['stage']['center'] = $kanan;
        $p1['hand'] = [$live];
        $p1['main_deck'] = [
            ['instance_id' => 'd1', 'card_type' => 'メンバー', 'name_en' => 'D1'],
            ['instance_id' => 'd2', 'card_type' => 'メンバー', 'name_en' => 'D2'],
            ['instance_id' => 'd3', 'card_type' => 'メンバー', 'name_en' => 'D3'],
        ];
        $state = $this->baseState($p1, $this->emptyPlayer('p2', 'P2'));
        $state['pending_prompt'] = [
            'type' => 'optional_discard_prompt',
            'owner' => 'p1',
            'responder' => 'p1',
            'source_id' => 'kanan_stale_pr',
            'source_name' => 'Kanan Matsuura',
            'choices' => ['yes', 'no'],
            'ability' => [
                'type' => 'optional_discard_prompt',
                'trigger' => 'on_enter',
                'discard' => 1,
                'filter' => 'live',
                // no then — pre-#102 prompt snapshot
            ],
        ];

        $state = \actionResolvePrompt($state, 'p1', [
            'choice' => 'yes',
            'discard_ids' => ['hand_live_stale'],
        ]);
        $this->assertNull($state['pending_prompt'] ?? null);
        $this->assertCount(3, $state['players']['p1']['hand']);
        $this->assertStringContainsString('drew 3', implode("\n", array_column($state['log'], 'msg')));
    }
}
