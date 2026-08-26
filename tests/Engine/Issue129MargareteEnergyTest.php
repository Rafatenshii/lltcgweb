<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/** GitHub #129 — PL!SP-bp7-010 activated must put Energy zone cards into the Energy deck. */
final class Issue129MargareteEnergyTest extends TestCase
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

    private function energy(string $id, bool $active = true): array
    {
        return [
            'instance_id' => $id,
            'card_type' => 'エネルギー',
            'card_type_en' => 'Energy',
            'name_en' => 'Energy',
            'active' => $active,
        ];
    }

    private function baseState(): array
    {
        return [
            'status' => 'playing',
            'phase' => 'main_first',
            'seq' => 1,
            'turn' => 2,
            'first_player' => 'p1',
            'active_player' => 'p1',
            'live_attempt' => ['p1', 'p2'],
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [],
                    'waiting_room' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'energy_zone' => [],
                    'energy_deck' => [],
                    'main_deck' => [['instance_id' => 'p1_deck_pad', 'card_type' => 'メンバー', 'name_en' => 'Pad']],
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
                    'energy_deck' => [],
                    'main_deck' => [],
                    'success_lives' => [],
                    'live_zone' => [],
                ],
            ],
        ];
    }

    public function testActivatedPutsOneEnergyFromZoneIntoDeck(): void
    {
        $marga = $this->cardByNo('PL!SP-bp7-010-R', 'issue129_marga');
        $wrCard = $this->cardByNo('PL!SP-bp7-001-P', 'issue129_wr');

        $state = $this->baseState();
        $state['players']['p1']['stage']['center'] = $marga;
        $state['players']['p1']['waiting_room'] = [$wrCard];
        $state['players']['p1']['energy_zone'] = [
            $this->energy('ez_active'),
            $this->energy('ez_rest', false),
        ];
        $state['players']['p1']['energy_deck'] = [$this->energy('ed0', false)];

        $state = \actionActivateAbility($state, 'p1', [
            'card_id' => 'issue129_marga',
            'ability_index' => 0,
        ]);

        $zoneIds = array_column($state['players']['p1']['energy_zone'] ?? [], 'instance_id');
        $deckIds = array_column($state['players']['p1']['energy_deck'] ?? [], 'instance_id');

        $this->assertCount(1, $state['players']['p1']['energy_zone'] ?? [], json_encode([
            'zone' => $zoneIds,
            'deck' => $deckIds,
            'prompt' => $state['pending_prompt']['type'] ?? null,
            'log' => array_slice($state['log'] ?? [], -6),
        ], JSON_UNESCAPED_UNICODE));
        $this->assertCount(2, $state['players']['p1']['energy_deck'] ?? []);
        $this->assertContains('ez_active', $deckIds);
        $this->assertNotContains('ez_active', $zoneIds);
        $this->assertNull($state['players']['p1']['stage']['center'] ?? null);
        $this->assertNotEmpty($state['pending_prompt']);
    }

    public function testActivatedPrefersActiveEnergyOverWaiting(): void
    {
        $marga = $this->cardByNo('PL!SP-bp7-010-R', 'issue129_marga_mix');

        $state = $this->baseState();
        $state['players']['p1']['stage']['left'] = $marga;
        $state['players']['p1']['energy_zone'] = [
            $this->energy('ez_wait_first', false),
            $this->energy('ez_active_second'),
        ];
        $state['players']['p1']['energy_deck'] = [];

        $state = \actionActivateAbility($state, 'p1', [
            'card_id' => 'issue129_marga_mix',
            'ability_index' => 0,
        ]);

        $zoneIds = array_column($state['players']['p1']['energy_zone'] ?? [], 'instance_id');
        $deckIds = array_column($state['players']['p1']['energy_deck'] ?? [], 'instance_id');
        $this->assertSame(['ez_wait_first'], $zoneIds);
        $this->assertSame(['ez_active_second'], $deckIds);
        $activeLeft = count(array_filter(
            $state['players']['p1']['energy_zone'] ?? [],
            fn($e) => !empty($e['active'])
        ));
        $this->assertSame(0, $activeLeft);
    }

    public function testActivatedReturnsRestingEnergyWhenNoActiveEnergy(): void
    {
        $marga = $this->cardByNo('PL!SP-bp7-010-R', 'issue129_marga_rest');

        $state = $this->baseState();
        $state['players']['p1']['stage']['right'] = $marga;
        $state['players']['p1']['energy_zone'] = [$this->energy('ez_only_rest', false)];
        $state['players']['p1']['energy_deck'] = [];

        $state = \actionActivateAbility($state, 'p1', [
            'card_id' => 'issue129_marga_rest',
            'ability_index' => 0,
        ]);

        $this->assertCount(0, $state['players']['p1']['energy_zone'] ?? []);
        $this->assertSame(['ez_only_rest'], array_column($state['players']['p1']['energy_deck'] ?? [], 'instance_id'));
    }
}
