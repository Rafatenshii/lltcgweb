<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * PL!HS-pb1-007-R Ceras: On Enter Energy cost must not be paid until the
 * player confirms the optional discard effect.
 */
final class CerasPb1007OptionalEnergyTest extends TestCase
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

    private function countActiveEnergy(array $p): int
    {
        return count(array_filter(
            $p['energy_zone'] ?? [],
            static fn($e) => !empty($e['active'])
        ));
    }

    private function baseState(): array
    {
        $ceras = $this->cardByNo('PL!HS-pb1-007-R', 'ceras');
        $handA = $this->cardByNo('PL!HS-sd1-015-SD', 'hand_a');
        $wr = $this->cardByNo('PL!HS-sd1-015-SD', 'wr_a');
        $energy = [];
        for ($i = 0; $i < 14; $i++) {
            $energy[] = ['instance_id' => "e$i", 'active' => true];
        }

        return [
            'room_id' => 'CERAS007',
            'status' => 'playing',
            'seq' => 1,
            'turn' => 1,
            'phase' => 'main_first',
            'first_player' => 'p1',
            'active_player' => 'p1',
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [$ceras, $handA],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'waiting_room' => [$wr],
                    'energy_zone' => $energy,
                    'main_deck' => [],
                    'energy_deck' => [],
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
                    'main_deck' => [],
                    'energy_deck' => [],
                    'live_zone' => [],
                    'success_lives' => [],
                ],
            ],
        ];
    }

    public function testDeclineDoesNotPayEnergy(): void
    {
        $state = $this->baseState();
        $beforePlay = $this->countActiveEnergy($state['players']['p1']);

        $state = applyAction($state, 'p1', 'play_member', [
            'card_id' => 'ceras',
            'slot' => 'center',
        ]);

        $this->assertSame('optional_discard_prompt', $state['pending_prompt']['type'] ?? null);
        // Play cost (11) only — On Enter Energy must still be active.
        $this->assertSame(
            $beforePlay - 11,
            $this->countActiveEnergy($state['players']['p1'])
        );

        $afterPrompt = $this->countActiveEnergy($state['players']['p1']);
        $state = applyAction($state, 'p1', 'resolve_prompt', ['choice' => 'no']);

        $this->assertEmpty($state['pending_prompt'] ?? null);
        $this->assertSame(
            $afterPrompt,
            $this->countActiveEnergy($state['players']['p1']),
            'Declining On Enter must not spend the 2 Energy cost'
        );
    }

    public function testConfirmPaysEnergyOnce(): void
    {
        $state = $this->baseState();
        $state = applyAction($state, 'p1', 'play_member', [
            'card_id' => 'ceras',
            'slot' => 'center',
        ]);
        $beforeYes = $this->countActiveEnergy($state['players']['p1']);

        $state = applyAction($state, 'p1', 'resolve_prompt', [
            'choice' => 'yes',
            'discard_ids' => ['hand_a'],
        ]);
        if (($state['pending_prompt']['type'] ?? '') === 'pick_wr_to_hand') {
            $state = applyAction($state, 'p1', 'resolve_prompt', [
                'card_id' => 'wr_a',
            ]);
        }

        $this->assertSame(
            $beforeYes - 2,
            $this->countActiveEnergy($state['players']['p1']),
            'Confirming On Enter must pay exactly 2 Energy'
        );
        $this->assertSame('wr_a', $state['players']['p1']['hand'][0]['instance_id'] ?? null);
    }
}
