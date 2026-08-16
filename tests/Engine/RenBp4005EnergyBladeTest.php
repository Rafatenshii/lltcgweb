<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * PL!SP-bp4-005 Ren Hazuki — [Always] While you have 10+ Energy, gain +3 Blade.
 */
final class RenBp4005EnergyBladeTest extends TestCase
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

    /** @return list<array<string, mixed>> */
    private function energy(int $count): array
    {
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $out[] = [
                'instance_id' => 'en_' . $i,
                'card_type' => 'エネルギー',
                'active' => true,
            ];
        }
        return $out;
    }

    private function stateWithRen(int $energyCount): array
    {
        $ren = $this->cardByNo('PL!SP-bp4-005-P', 'ren_center');
        return [
            'status' => 'playing',
            'phase' => 'main_first',
            'seq' => 1,
            'turn' => 1,
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
                    'stage' => [
                        'left' => null,
                        'center' => $ren,
                        'right' => null,
                    ],
                    'energy_zone' => $this->energy($energyCount),
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

    public function testAbilityIrIsBladeBonusIfMinEnergy(): void
    {
        $ren = $this->cardByNo('PL!SP-bp4-005-P', 'ren_ir');
        $ab = $ren['abilities'][1] ?? null;
        $this->assertIsArray($ab);
        $this->assertSame('continuous', $ab['trigger'] ?? null);
        $this->assertSame('blade_bonus_if_min_energy', $ab['type'] ?? null);
        $this->assertSame(10, intval($ab['min_energy'] ?? 0));
        $this->assertSame(3, intval($ab['amount'] ?? 0));
    }

    public function testTenEnergyGrantsPlusThreeBlade(): void
    {
        $state = $this->stateWithRen(10);
        $ren = $state['players']['p1']['stage']['center'];
        $printed = intval($ren['blade'] ?? 0);
        $this->assertSame($printed + 3, \getMemberBlade($ren, $state, 'p1', 'center'));
    }

    public function testNineEnergyDoesNotGrantBlade(): void
    {
        $state = $this->stateWithRen(9);
        $ren = $state['players']['p1']['stage']['center'];
        $printed = intval($ren['blade'] ?? 0);
        $this->assertSame($printed, \getMemberBlade($ren, $state, 'p1', 'center'));
    }
}
