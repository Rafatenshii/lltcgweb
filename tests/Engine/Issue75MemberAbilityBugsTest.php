<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/** GitHub #75 — Mei/Shiki blade Live Start, Margarete LS/LS, Kotori activated. */
final class Issue75MemberAbilityBugsTest extends TestCase
{
    private function cardByNo(string $cardNo, string $instanceId = 'x'): array
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

    private function baseState(): array
    {
        return [
            'room_id' => 'ISSUE75',
            'status' => 'playing',
            'seq' => 10,
            'turn' => 2,
            'phase' => 'main_first',
            'first_player' => 'p1',
            'active_player' => 'p1',
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [
                        ['instance_id' => 'h1', 'card_type' => 'メンバー', 'group' => "μ's", 'name_en' => 'Mus'],
                        ['instance_id' => 'h2', 'card_type' => 'メンバー', 'group' => 'Nijigasaki', 'name_en' => 'Niji'],
                    ],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'waiting_room' => [
                        [
                            'instance_id' => 'live_wr',
                            'card_type' => 'ライブ',
                            'card_type_en' => 'Live',
                            'group' => "μ's",
                            'name_en' => 'WR Live',
                            'score' => 3,
                        ],
                    ],
                    'energy_zone' => [
                        ['instance_id' => 'e1', 'card_type' => 'エネルギー', 'active' => true],
                        ['instance_id' => 'e2', 'card_type' => 'エネルギー', 'active' => true],
                        ['instance_id' => 'e3', 'card_type' => 'エネルギー', 'active' => true],
                    ],
                    'energy_deck' => [
                        ['instance_id' => 'ed1', 'card_type' => 'エネルギー', 'active' => false],
                    ],
                    'main_deck' => [
                        ['instance_id' => 'd1', 'card_type' => 'メンバー', 'group' => "μ's", 'name_en' => 'D1'],
                        ['instance_id' => 'd2', 'card_type' => 'メンバー', 'group' => "μ's", 'name_en' => 'D2'],
                        ['instance_id' => 'd3', 'card_type' => 'メンバー', 'group' => "μ's", 'name_en' => 'D3'],
                        ['instance_id' => 'd4', 'card_type' => 'メンバー', 'group' => "μ's", 'name_en' => 'D4'],
                    ],
                    'live_zone' => [],
                    'success_lives' => [],
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'P2',
                    'hand' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'waiting_room' => [],
                    'energy_zone' => [],
                    'energy_deck' => [],
                    'main_deck' => [],
                    'live_zone' => [],
                    'success_lives' => [],
                ],
            ],
        ];
    }

    public function testMeiAndShikiHavePayEnergyBladeAbility(): void
    {
        $mei = $this->cardByNo('PL!SP-pb2-040-N', 'mei');
        $shiki = $this->cardByNo('PL!SP-bp2-019-N', 'shiki');
        foreach ([$mei, $shiki] as $card) {
            $ab = $card['abilities'][0] ?? [];
            $this->assertSame('live_start', $ab['trigger'] ?? null);
            $this->assertSame('optional_pay_energy', $ab['type'] ?? null);
            $this->assertSame(1, intval($ab['cost'] ?? 0));
            $this->assertSame('blade_bonus', $ab['then']['type'] ?? null);
            $this->assertSame(2, intval($ab['then']['amount'] ?? 0));
            $this->assertStringContainsString('+2 Blade', $card['text'] ?? '');
        }
    }

    public function testMargareteLiveStartOffersDiscardOrReturnEnergy(): void
    {
        $marg = $this->cardByNo('PL!SP-pb2-010-R', 'marg');
        $state = $this->baseState();
        $state['phase'] = 'live_start_effects';
        $state['live_attempt'] = ['p1'];
        $state['players']['p1']['stage']['center'] = $marg;
        $state = \resolveAbilityEffect($state, 'p1', $state['players']['p1']['stage']['center'], $marg['abilities'][0], [
            'phase' => 'live_start',
        ]);
        $this->assertSame('live_start_unless_discard_return_energy', $state['pending_prompt']['type'] ?? null);

        $state = \actionResolvePrompt($state, 'p1', ['choice' => 'return_energy']);
        $this->assertNull($state['pending_prompt'] ?? null);
        // Resolving Live Start may advance into Energy Phase (which replaces Energy),
        // so assert the ability log rather than final zone counts.
        $log = implode("\n", array_map(static fn($e) => (string) ($e['msg'] ?? ''), $state['log'] ?? []));
        $this->assertStringContainsString('put 1 Energy into Energy deck (Live Start)', $log);
    }

    public function testMargareteLiveSuccessChooseDraw(): void
    {
        $marg = $this->cardByNo('PL!SP-pb2-010-R', 'marg');
        $state = $this->baseState();
        $state['phase'] = 'live_success_effects';
        $state['players']['p1']['stage']['center'] = $marg;
        $ab = $marg['abilities'][1];
        $this->assertSame('live_success_choose_draw_or_energy_wait', $ab['type'] ?? null);
        $state = \resolveAbilityEffect($state, 'p1', $state['players']['p1']['stage']['center'], $ab, [
            'phase' => 'live_success',
        ]);
        $this->assertSame('live_success_choose_draw_or_energy_wait', $state['pending_prompt']['type'] ?? null);
        $state = \actionResolvePrompt($state, 'p1', ['choice' => 'draw']);
        // Live Success resolution can advance into Draw Phase (+1), so assert ability log.
        $log = implode("\n", array_map(static fn($e) => (string) ($e['msg'] ?? ''), $state['log'] ?? []));
        $this->assertStringContainsString('drew 2', $log);
    }

    public function testKotoriActivatedPaysEnergyAndRequiresChosenDiscard(): void
    {
        $kotori = $this->cardByNo('PL!-bp5-003-R＋', 'kotori');
        $ab = null;
        $abIdx = null;
        foreach ($kotori['abilities'] as $i => $row) {
            if (($row['type'] ?? '') === 'mandatory_discard_group_branch') {
                $ab = $row;
                $abIdx = $i;
                break;
            }
        }
        $this->assertNotNull($ab);
        $this->assertSame(2, intval($ab['cost'] ?? 0));

        $state = $this->baseState();
        $state['players']['p1']['stage']['center'] = $kotori;

        $energyBefore = count(array_filter(
            $state['players']['p1']['energy_zone'],
            fn($e) => !empty($e['active'])
        ));
        $state = \actionActivateAbility($state, 'p1', [
            'card_id' => 'kotori',
            'ability_index' => $abIdx,
        ]);
        $this->assertSame('mandatory_discard_group_branch', $state['pending_prompt']['type'] ?? null);
        $energyAfter = count(array_filter(
            $state['players']['p1']['energy_zone'],
            fn($e) => !empty($e['active'])
        ));
        $this->assertSame($energyBefore - 2, $energyAfter);

        // Must pick — leftmost auto-discard no longer happens.
        $this->assertContains('h1', array_column($state['players']['p1']['hand'], 'instance_id'));
        $this->assertContains('h2', array_column($state['players']['p1']['hand'], 'instance_id'));

        $state = \actionResolvePrompt($state, 'p1', ['discard_ids' => ['h2']]);
        $this->assertNotContains('h2', array_column($state['players']['p1']['hand'], 'instance_id'));
        $this->assertContains('h2', array_column($state['players']['p1']['waiting_room'], 'instance_id'));

        // Once per turn: second activate must fail.
        $this->expectException(\Exception::class);
        \actionActivateAbility($state, 'p1', [
            'card_id' => 'kotori',
            'ability_index' => $abIdx,
        ]);
    }
}
