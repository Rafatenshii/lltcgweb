<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/** PL!HS-sd1-001-SD Kaho: activate 2 Energy after Baton to WR from cost 10+ Hasunosora Member. */
final class KahoBatonEnergyTest extends TestCase
{
    private function cardByNo(string $cardNo, string $instanceId): array {
        $data = json_decode((string)file_get_contents(CARDS_FILE), true);
        $this->assertIsArray($data);
        foreach ($data['cards'] ?? [] as $card) {
            if (($card['card_no'] ?? '') === $cardNo) {
                $card['instance_id'] = $instanceId;
                return $card;
            }
        }
        $this->fail('Missing test card ' . $cardNo);
    }

    private function countActiveEnergy(array $player): int {
        return count(array_filter(
            $player['energy_zone'] ?? [],
            static fn(array $e): bool => !empty($e['active'])
        ));
    }

    private function baseState(array $kaho, array $incoming, array $energy, array $mainDeck = []): array {
        return [
            'room_id' => 'KAHO_BATON',
            'status' => 'playing',
            'seq' => 5,
            'turn' => 1,
            'phase' => 'main_first',
            'first_player' => 'p1',
            'active_player' => 'p1',
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [$incoming],
                    'stage' => ['left' => null, 'center' => $kaho, 'right' => null],
                    'waiting_room' => [],
                    'energy_zone' => $energy,
                    'main_deck' => $mainDeck,
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

    private function energyZone(int $active, int $inactive): array {
        $energy = [];
        for ($i = 0; $i < $active; $i++) {
            $energy[] = ['instance_id' => 'e_active_' . $i, 'active' => true];
        }
        for ($i = 0; $i < $inactive; $i++) {
            $energy[] = ['instance_id' => 'e_inactive_' . $i, 'active' => false];
        }
        return $energy;
    }

    public function testKahoBatonWrActivatesEnergyAfterBatonCostPaid(): void {
        $kaho = $this->cardByNo('PL!HS-sd1-001-SD', 'test_kaho');
        $hime = $this->cardByNo('PL!HS-sd1-006-SD', 'test_incoming');
        // Non-empty deck: leaving Kaho stays in WR for the normal wr_idx path.
        $filler = $this->cardByNo('PL!HS-sd1-002-SD', 'deck_filler');

        $state = $this->baseState($kaho, $hime, $this->energyZone(6, 4), [$filler]);
        $state = applyAction($state, 'p1', 'play_member', [
            'card_id' => 'test_incoming',
            'slot' => 'center',
            'baton_id' => 'test_kaho',
        ]);

        $this->assertSame('test_incoming', $state['players']['p1']['stage']['center']['instance_id'] ?? null);
        $wrIds = array_column($state['players']['p1']['waiting_room'] ?? [], 'instance_id');
        $this->assertContains('test_kaho', $wrIds);

        // Baton cost = 15-9 = 6 paid → 0 active; Kaho activates 2 → 2 active.
        $this->assertSame(2, $this->countActiveEnergy($state['players']['p1']));

        $found = false;
        foreach ($state['log'] ?? [] as $entry) {
            $msg = is_array($entry) ? ($entry['msg'] ?? '') : (string)$entry;
            if (str_contains($msg, 'activated 2 Energy (Baton Touch to Waiting Room)')) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Expected Kaho baton WR activation log line');
    }

    public function testKahoBatonWrActivatesEnergyEvenWhenEmptyDeckRefreshesLeavingMember(): void {
        $kaho = $this->cardByNo('PL!HS-sd1-001-SD', 'test_kaho');
        $ceras = $this->cardByNo('PL!HS-pb1-007-R', 'test_incoming');
        // Empty deck: appendCardsToWaitingRoom immediately shuffles Kaho into main_deck.
        $state = $this->baseState($kaho, $ceras, $this->energyZone(6, 0), []);

        $state = applyAction($state, 'p1', 'play_member', [
            'card_id' => 'test_incoming',
            'slot' => 'center',
            'baton_id' => 'test_kaho',
        ]);

        $this->assertSame('test_incoming', $state['players']['p1']['stage']['center']['instance_id'] ?? null);

        $found = false;
        foreach ($state['log'] ?? [] as $entry) {
            $msg = is_array($entry) ? ($entry['msg'] ?? '') : (string)$entry;
            if (str_contains($msg, 'activated 2 Energy (Baton Touch to Waiting Room)')) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Kaho leave ability must fire even after empty-deck WR refresh');
    }

    public function testKahoBatonFromCeras11ActivatesTwoEnergy(): void {
        $kaho = $this->cardByNo('PL!HS-sd1-001-SD', 'test_kaho');
        $ceras = $this->cardByNo('PL!HS-pb1-007-R', 'test_incoming');
        $filler = $this->cardByNo('PL!HS-sd1-002-SD', 'deck_filler');

        $state = $this->baseState($kaho, $ceras, $this->energyZone(6, 0), [$filler]);
        $state = applyAction($state, 'p1', 'play_member', [
            'card_id' => 'test_incoming',
            'slot' => 'center',
            'baton_id' => 'test_kaho',
        ]);

        // Pay 2 for baton (11-9), Kaho +2, then Ceras on_enter pays 2 up front for its prompt → 4 active.
        // Without Kaho, energy would be 2 after those same pays.
        $this->assertSame(4, $this->countActiveEnergy($state['players']['p1']));

        $found = false;
        foreach ($state['log'] ?? [] as $entry) {
            $msg = is_array($entry) ? ($entry['msg'] ?? '') : (string)$entry;
            if (str_contains($msg, 'activated 2 Energy (Baton Touch to Waiting Room)')) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found);
    }
}
