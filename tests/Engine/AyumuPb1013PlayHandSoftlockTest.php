<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * Ayumu PL!N-pb1-013 On Enter: optional pay + play a named Ayumu ≤4 from hand.
 * Softlocked when hand had no matching Ayumu (empty / wrong-filter hand pick).
 */
final class AyumuPb1013PlayHandSoftlockTest extends TestCase
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

    private function baseState(): array
    {
        return [
            'status' => 'playing',
            'phase' => 'main_first',
            'seq' => 1,
            'turn' => 2,
            'active_player' => 'p1',
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [],
                    'waiting_room' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'energy_zone' => [
                        ['instance_id' => 'e1', 'card_type' => 'エネルギー', 'active' => true],
                        ['instance_id' => 'e2', 'card_type' => 'エネルギー', 'active' => true],
                    ],
                    'main_deck' => [],
                    'live_zone' => [],
                    'success_lives' => [],
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'P2',
                    'hand' => [],
                    'waiting_room' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'energy_zone' => [],
                    'main_deck' => [],
                    'live_zone' => [],
                    'success_lives' => [],
                ],
            ],
        ];
    }

    public function testNoPromptWhenHandHasNoMatchingAyumu(): void
    {
        $ayumu = $this->cardByNo('PL!N-pb1-013-R', 'ayumu_enter');
        $other = $this->cardByNo('PL!N-bp4-006-R', 'kanata_hand'); // Nijigasaki, not Ayumu
        $other['cost'] = 3;

        $state = $this->baseState();
        $state['players']['p1']['stage']['center'] = $ayumu;
        $state['players']['p1']['hand'] = [$other];

        $state = \resolveOnEnterAbilities($state, 'p1', $ayumu, 'center');
        $this->assertArrayNotHasKey('pending_prompt', $state);
    }

    public function testNoPromptWhenHandEmpty(): void
    {
        $ayumu = $this->cardByNo('PL!N-pb1-013-R', 'ayumu_empty');
        $state = $this->baseState();
        $state['players']['p1']['stage']['center'] = $ayumu;
        $state['players']['p1']['hand'] = [];

        $state = \resolveOnEnterAbilities($state, 'p1', $ayumu, 'center');
        $this->assertArrayNotHasKey('pending_prompt', $state);
    }

    public function testOpensWhenLowCostAyumuInHandAndPlaysHer(): void
    {
        $ayumu = $this->cardByNo('PL!N-pb1-013-R', 'ayumu_src');
        $handAyumu = $this->cardByNo('PL!N-pb1-013-R', 'ayumu_hand');
        $handAyumu['cost'] = 4;
        $handAyumu['name_en'] = 'Ayumu Uehara';

        $state = $this->baseState();
        $state['players']['p1']['stage']['center'] = $ayumu;
        $state['players']['p1']['hand'] = [$handAyumu];

        $state = \resolveOnEnterAbilities($state, 'p1', $ayumu, 'center');
        $this->assertSame('optional_pay_play_hand_member', $state['pending_prompt']['type'] ?? null);
        $this->assertSame(1, $state['pending_prompt']['ability']['cost'] ?? null);
        $candIds = array_column($state['pending_prompt']['candidates'] ?? [], 'instance_id');
        $this->assertSame(['ayumu_hand'], $candIds);

        $activeBefore = count(array_filter(
            $state['players']['p1']['energy_zone'],
            fn($e) => !empty($e['active'])
        ));
        $state = \actionResolvePrompt($state, 'p1', [
            'choice' => 'yes',
            'card_id' => 'ayumu_hand',
        ]);
        if (($state['pending_prompt']['step'] ?? '') === 'pick_slot') {
            $slot = $state['pending_prompt']['slots'][0] ?? 'left';
            $state = \actionResolvePrompt($state, 'p1', ['slot' => $slot]);
        }

        $this->assertArrayNotHasKey('pending_prompt', $state);
        $stageIds = array_values(array_filter(array_map(
            fn($m) => $m['instance_id'] ?? null,
            $state['players']['p1']['stage']
        )));
        $this->assertContains('ayumu_hand', $stageIds);
        $activeAfter = count(array_filter(
            $state['players']['p1']['energy_zone'],
            fn($e) => !empty($e['active'])
        ));
        $this->assertSame($activeBefore - 1, $activeAfter);
    }

    public function testEmptyCardIdOnYesClearsPromptWithoutSpendingEnergy(): void
    {
        $ayumu = $this->cardByNo('PL!N-pb1-013-R', 'ayumu_skip');
        $handAyumu = $this->cardByNo('PL!N-pb1-013-R', 'ayumu_keep');
        $handAyumu['cost'] = 3;

        $state = $this->baseState();
        $state['players']['p1']['stage']['center'] = $ayumu;
        $state['players']['p1']['hand'] = [$handAyumu];
        $state = \resolveOnEnterAbilities($state, 'p1', $ayumu, 'center');

        $state = \actionResolvePrompt($state, 'p1', ['choice' => 'yes', 'card_id' => '']);
        $this->assertArrayNotHasKey('pending_prompt', $state);
        $this->assertSame('ayumu_keep', $state['players']['p1']['hand'][0]['instance_id'] ?? null);
        $active = count(array_filter(
            $state['players']['p1']['energy_zone'],
            fn($e) => !empty($e['active'])
        ));
        $this->assertSame(2, $active);
    }

    public function testAbilityCostIsOneEnergy(): void
    {
        $ayumu = $this->cardByNo('PL!N-pb1-013-R', 'ayumu_cost');
        $this->assertSame(1, intval($ayumu['abilities'][0]['cost'] ?? 0));
    }
}
