<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/** PL!S-pb1-015-N: look top 4 even when none meet Blue heart threshold. */
final class YoshikoLookRevealEmptyTest extends TestCase
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

    private function energyStub(string $id): array
    {
        return [
            'instance_id' => $id,
            'card_type' => 'エネルギー',
            'card_type_en' => 'Energy',
            'name_en' => 'Energy Card',
            'name' => 'エネルギーカード',
        ];
    }

    public function testEmptyEligibleStillOpensLookPrompt(): void
    {
        $yoshiko = $this->cardByNo('PL!S-pb1-015-N', 'yoshiko_stage');
        $discard = $this->energyStub('yoshiko_discard');
        // Top 4: Energy / low-heart Members — none meet 2+ Blue hearts.
        $deck = [
            $this->energyStub('deck_e1'),
            $this->energyStub('deck_e2'),
            $this->energyStub('deck_e3'),
            $this->energyStub('deck_e4'),
        ];

        $state = [
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
                    'hand' => [$discard],
                    'waiting_room' => [],
                    'stage' => ['left' => null, 'center' => $yoshiko, 'right' => null],
                    'energy_zone' => [],
                    'main_deck' => $deck,
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

        $state = resolveAbilityEffect($state, 'p1', $yoshiko, $yoshiko['abilities'][0], [
            'phase' => 'on_enter',
            'slot' => 'center',
        ]);
        $this->assertSame('optional_discard_prompt', $state['pending_prompt']['type'] ?? null);

        $state = actionResolvePrompt($state, 'p1', [
            'choice' => 'yes',
            'discard_ids' => ['yoshiko_discard'],
        ]);

        $pr = $state['pending_prompt'] ?? [];
        $this->assertSame('pick_looked_deck_hand', $pr['type'] ?? null);
        $this->assertTrue(!empty($pr['optional']));
        $this->assertSame([], $pr['eligible_ids'] ?? null);
        $this->assertCount(4, $pr['candidates'] ?? []);
        $this->assertCount(4, $state['surveil_stash'] ?? []);
        $this->assertStringContainsString('No matching', (string)($pr['prompt'] ?? ''));
        // Looked cards stay in stash — not auto-milled to WR yet.
        $lookedIds = array_map(fn($c) => $c['instance_id'] ?? '', $state['surveil_stash'] ?? []);
        foreach ($state['players']['p1']['waiting_room'] as $wrCard) {
            $this->assertNotContains($wrCard['instance_id'] ?? '', $lookedIds);
        }

        $state = actionResolvePrompt($state, 'p1', ['choice' => 'skip']);
        $this->assertEmpty($state['pending_prompt'] ?? null);
        $wrIds = array_map(fn($c) => $c['instance_id'] ?? '', $state['players']['p1']['waiting_room']);
        foreach ($lookedIds as $lid) {
            $this->assertContains($lid, $wrIds);
        }
    }
}
