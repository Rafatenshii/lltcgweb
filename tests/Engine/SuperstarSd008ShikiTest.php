<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/** Regression: PL!SP-sd1-008-SD (Shiki Wakana) optional pay + deck surveil on enter. */
final class SuperstarSd008ShikiTest extends TestCase
{
    private function cardByNo(string $cardNo, string $instanceId): array
    {
        $data = json_decode((string) file_get_contents((string) constant('CARDS_FILE')), true);
        foreach ($data['cards'] ?? [] as $card) {
            if (($card['card_no'] ?? '') === $cardNo) {
                $card['instance_id'] = $instanceId;
                $card['active'] = true;
                $card['entered_turn'] = 1;
                return $card;
            }
        }
        $this->fail('Missing test card ' . $cardNo);
    }

    private function baseState(string $phase = 'main_first'): array
    {
        return [
            'status' => 'playing',
            'phase' => $phase,
            'seq' => 1,
            'turn' => 2,
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

    /** @return list<array<string, mixed>> */
    private function activeEnergy(int $count, string $prefix = 'sp008_en'): array
    {
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $out[] = [
                'instance_id' => $prefix . $i,
                'card_type' => 'エネルギー',
                'active' => true,
            ];
        }
        return $out;
    }

    public function testOnEnterOptionalPayOpensLookRevealPick(): void
    {
        $shiki = $this->cardByNo('PL!SP-sd1-008-SD', 'sp008_shiki');
        $deckTop = $this->cardByNo('PL!SP-sd1-002-SD', 'sp008_deck_pick');
        $deckTop['instance_id'] = 'sp008_deck_pick';
        $filler = ['instance_id' => 'sp008_f1', 'card_type' => 'メンバー', 'group' => 'Superstar'];

        $state = $this->baseState();
        $state['players']['p1']['hand'] = [$shiki];
        $state['players']['p1']['main_deck'] = [$deckTop, $filler, $filler];
        $state['players']['p1']['energy_zone'] = $this->activeEnergy(5);

        $state = \actionPlayMember($state, 'p1', [
            'card_id' => 'sp008_shiki',
            'slot' => 'center',
        ]);
        $this->assertSame('optional_pay_energy_on_enter', $state['pending_prompt']['type'] ?? null);

        $activeAfterPlay = count(array_filter(
            $state['players']['p1']['energy_zone'] ?? [],
            static fn(array $e): bool => !empty($e['active'])
        ));
        $this->assertSame(1, $activeAfterPlay);

        $state = \actionResolvePrompt($state, 'p1', ['choice' => 'yes']);
        $this->assertSame('pick_looked_deck_hand', $state['pending_prompt']['type'] ?? null);
        $this->assertFalse(!empty($state['pending_prompt']['optional']));

        $state = \actionResolvePrompt($state, 'p1', ['card_id' => 'sp008_deck_pick']);
        $handIds = array_column($state['players']['p1']['hand'], 'instance_id');
        $this->assertContains('sp008_deck_pick', $handIds);
        $this->assertNull($state['pending_prompt'] ?? null);
    }

    public function testOptionalPaySkipsWhenNotEnoughEnergyAfterPlayCost(): void
    {
        $shiki = $this->cardByNo('PL!SP-sd1-008-SD', 'sp008_shiki_low');
        $state = $this->baseState();
        $state['players']['p1']['hand'] = [$shiki];
        $state['players']['p1']['main_deck'] = [['instance_id' => 'sp008_d1', 'card_type' => 'メンバー']];
        $state['players']['p1']['energy_zone'] = $this->activeEnergy(4);

        $state = \actionPlayMember($state, 'p1', [
            'card_id' => 'sp008_shiki_low',
            'slot' => 'center',
        ]);
        $this->assertNull($state['pending_prompt'] ?? null);
        $this->assertSame(0, \countActiveEnergyInZone($state['players']['p1']));
    }

    public function testBatonPlayCostsLessThanEmptySlotPlay(): void
    {
        $shiki = $this->cardByNo('PL!SP-sd1-008-SD', 'sp008_shiki_baton');
        $existing = $this->cardByNo('PL!SP-sd1-019-SD', 'sp008_existing');
        $existing['instance_id'] = 'sp008_existing';
        $existing['entered_turn'] = 1;

        $state = $this->baseState();
        $state['players']['p1']['hand'] = [$shiki];
        $state['players']['p1']['stage']['center'] = $existing;
        $state['players']['p1']['energy_zone'] = $this->activeEnergy(3);

        $state = \actionPlayMember($state, 'p1', [
            'card_id' => 'sp008_shiki_baton',
            'slot' => 'center',
            'baton_id' => 'sp008_existing',
        ]);

        $this->assertSame('sp008_shiki_baton', $state['players']['p1']['stage']['center']['instance_id'] ?? null);
        $activeAfter = count(array_filter(
            $state['players']['p1']['energy_zone'] ?? [],
            static fn(array $e): bool => !empty($e['active'])
        ));
        $this->assertSame(1, $activeAfter);
    }
}
