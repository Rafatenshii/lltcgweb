<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/** Turn-prep Draw Phase must emit main_deck→hand anim like Energy. */
final class TurnPrepDrawAnimTest extends TestCase
{
    private function baseState(): array
    {
        return [
            'room_id' => 'TURNPREP',
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
                        ['instance_id' => 'h1', 'card_type' => 'メンバー', 'group' => "μ's", 'name_en' => 'H1'],
                    ],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'waiting_room' => [],
                    'energy_zone' => [
                        ['instance_id' => 'e1', 'card_type' => 'エネルギー', 'active' => true],
                    ],
                    'energy_deck' => [
                        ['instance_id' => 'ed1', 'card_type' => 'エネルギー', 'active' => false],
                    ],
                    'main_deck' => [
                        ['instance_id' => 'd1', 'card_type' => 'メンバー', 'group' => "μ's", 'name_en' => 'D1'],
                        ['instance_id' => 'd2', 'card_type' => 'メンバー', 'group' => "μ's", 'name_en' => 'D2'],
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
                    'main_deck' => [
                        ['instance_id' => 'od1', 'card_type' => 'メンバー', 'group' => "μ's", 'name_en' => 'OD1'],
                    ],
                    'live_zone' => [],
                    'success_lives' => [],
                ],
            ],
        ];
    }

    public function testDoDrawPhaseAttachesMainDeckToHandAnim(): void
    {
        $state = $this->baseState();
        $beforeHand = count($state['players']['p1']['hand']);
        $state = \doDrawPhase($state, 'p1');
        $this->assertCount($beforeHand + 1, $state['players']['p1']['hand']);

        $drawEntry = null;
        foreach (array_reverse($state['log'] ?? []) as $entry) {
            if (str_contains((string) ($entry['msg'] ?? ''), '— Draw Phase.')) {
                $drawEntry = $entry;
                break;
            }
        }
        $this->assertNotNull($drawEntry);
        $anims = $drawEntry['anim'] ?? [];
        $this->assertNotEmpty($anims);
        $this->assertSame('main_deck', $anims[0]['from'] ?? null);
        $this->assertSame('hand', $anims[0]['to'] ?? null);
        $this->assertSame('p1', $anims[0]['pid'] ?? null);
        $this->assertSame('d1', $anims[0]['iid'] ?? null);
    }

    public function testRunPlayerTurnPrepKeepsEnergyAndDrawAnims(): void
    {
        $state = $this->baseState();
        $state = \runPlayerTurnPrep($state, 'p1');
        $energyAnim = null;
        $drawAnim = null;
        foreach ($state['log'] ?? [] as $entry) {
            $msg = (string) ($entry['msg'] ?? '');
            if (str_contains($msg, '— Energy Phase: placed') && !empty($entry['anim'])) {
                $energyAnim = $entry['anim'][0];
            }
            if (str_contains($msg, '— Draw Phase.') && !empty($entry['anim'])) {
                $drawAnim = $entry['anim'][0];
            }
        }
        $this->assertNotNull($energyAnim);
        $this->assertSame('energy_deck', $energyAnim['from'] ?? null);
        $this->assertSame('energy', $energyAnim['to'] ?? null);
        $this->assertNotNull($drawAnim);
        $this->assertSame('main_deck', $drawAnim['from'] ?? null);
        $this->assertSame('hand', $drawAnim['to'] ?? null);
    }
}
