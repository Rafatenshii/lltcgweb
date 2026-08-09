<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * PL!S-bp3-003 Kanan Live Start: optional_discard_blade_per_card.
 * Client must open hand pick on Yes (promptDiscardCount); server accepts up to N discards.
 */
final class KananBp3003LiveStartDiscardBladeTest extends TestCase
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

    private function baseState(array $kanan, array $hand): array
    {
        return [
            'status' => 'playing',
            'phase' => 'live_start_effects',
            'seq' => 1,
            'turn' => 3,
            'first_player' => 'p1',
            'active_player' => 'p1',
            'live_attempt' => ['p1'],
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => $hand,
                    'waiting_room' => [],
                    'stage' => [
                        'left' => null,
                        'center' => $kanan,
                        'right' => null,
                    ],
                    'energy_zone' => [],
                    'main_deck' => [],
                    'success_lives' => [],
                    'live_zone' => [[
                        'instance_id' => 'live_dummy',
                        'card_type' => 'ライブ',
                        'card_type_en' => 'Live',
                        'name_en' => 'Dummy Live',
                        'face_up' => true,
                    ]],
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

    public function testLiveStartOpensDiscardBladePromptAndAppliesBonus(): void
    {
        $GLOBALS['TUT_PERF_MANUAL_PHASES'] = true;
        try {
            $kanan = $this->cardByNo('PL!S-bp3-003-P', 'kanan_1');
            $h1 = $this->cardByNo('PL!S-sd1-009-SD', 'hand_1');
            $h2 = $this->cardByNo('PL!S-sd1-010-SD', 'hand_2');
            $state = $this->baseState($kanan, [$h1, $h2]);

            $state = \resolveLiveStartAbilities($state, 'p1');
            $this->assertSame(
                'optional_discard_blade_per_card',
                $state['pending_prompt']['type'] ?? null
            );
            $this->assertSame(2, intval($state['pending_prompt']['max_discard'] ?? 0));

            $state = \actionResolvePrompt($state, 'p1', [
                'choice' => 'yes',
                'discard_ids' => ['hand_1', 'hand_2'],
            ]);
            $this->assertNull($state['pending_prompt'] ?? null);
            $this->assertCount(0, $state['players']['p1']['hand']);
            $this->assertCount(2, $state['players']['p1']['waiting_room']);
            $bonus = intval($state['players']['p1']['stage']['center']['live_blade_bonus'] ?? 0);
            $this->assertSame(4, $bonus, '2 cards × +2 Blade');
            $this->assertSame(0, intval($state['live_modifiers']['p1']['blade_bonus'] ?? 0));
        } finally {
            unset($GLOBALS['TUT_PERF_MANUAL_PHASES']);
        }
    }

    public function testYesWithZeroDiscardsIsNoOp(): void
    {
        $GLOBALS['TUT_PERF_MANUAL_PHASES'] = true;
        try {
            $kanan = $this->cardByNo('PL!S-bp3-003-P', 'kanan_1');
            $h1 = $this->cardByNo('PL!S-sd1-009-SD', 'hand_1');
            $state = $this->baseState($kanan, [$h1]);
            $state = \resolveLiveStartAbilities($state, 'p1');
            $state = \actionResolvePrompt($state, 'p1', [
                'choice' => 'yes',
                'discard_ids' => [],
            ]);
            $this->assertCount(1, $state['players']['p1']['hand']);
            $this->assertSame(0, intval($state['live_modifiers']['p1']['blade_bonus'] ?? 0));
        } finally {
            unset($GLOBALS['TUT_PERF_MANUAL_PHASES']);
        }
    }
}
