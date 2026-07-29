<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * Umi PL!-bp3-004 Live Start: discard ANY hand card, then add μ's Live from WR.
 * Client previously filtered the discard hand by ability group/filter (WR targets).
 */
final class UmiBp3004LiveStartDiscardHandTest extends TestCase
{
    private function cardByNo(string $cardNo, string $instanceId): array {
        $data = json_decode((string) file_get_contents(CARDS_FILE), true);
        $this->assertIsArray($data);
        foreach ($data['cards'] ?? [] as $card) {
            if (($card['card_no'] ?? '') === $cardNo) {
                $card['instance_id'] = $instanceId;
                return $card;
            }
        }
        $this->fail('Missing test card ' . $cardNo);
    }

    private function firstMusLive(string $instanceId): array {
        $data = json_decode((string) file_get_contents(CARDS_FILE), true);
        foreach ($data['cards'] ?? [] as $card) {
            $isLive = ($card['card_type_en'] ?? '') === 'Live' || ($card['card_type'] ?? '') === 'ライブ';
            if (($card['group'] ?? '') === "μ's" && $isLive) {
                $card['instance_id'] = $instanceId;
                return $card;
            }
        }
        $this->fail('Need a μ\'s Live in catalog');
    }

    public function testUmiLiveStartAcceptsAnyHandCardDiscardThenAddsMusLive(): void {
        $umi = $this->cardByNo('PL!-bp3-004-R＋', 'umi');
        $handMember = $this->cardByNo('PL!HS-sd1-015-SD', 'hand_any'); // Hasunosora — not μ's Live
        $wrLive = $this->firstMusLive('wr_mus_live');
        $successLive = $this->firstMusLive('success_live');
        $liveInZone = $this->firstMusLive('live_zone');

        $state = [
            'room_id' => 'UMI_LS',
            'status' => 'playing',
            'seq' => 1,
            'turn' => 2,
            'phase' => 'live_start_effects',
            'first_player' => 'p1',
            'active_player' => 'p1',
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [$handMember],
                    'stage' => ['left' => null, 'center' => $umi, 'right' => null],
                    'waiting_room' => [$wrLive],
                    'energy_zone' => [],
                    'main_deck' => [['instance_id' => 'd1', 'card_type' => 'メンバー', 'card_type_en' => 'Member']],
                    'energy_deck' => [],
                    'live_zone' => [$liveInZone],
                    'success_lives' => [$successLive],
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

        $ab = null;
        foreach ($umi['abilities'] as $a) {
            if (($a['type'] ?? '') === 'optional_discard_add_from_wr') {
                $ab = $a;
                break;
            }
        }
        $this->assertNotNull($ab);

        $queueItem = [
            'owner' => 'p1',
            'source_id' => 'umi',
            'source_name' => 'Umi Sonoda',
            'ability_index' => 1,
            'ability' => $ab,
        ];
        $state['pending_prompt'] = buildOptionalLiveStartPrompt($state, $queueItem);
        $this->assertSame('optional_live_start', $state['pending_prompt']['type'] ?? null);
        $this->assertSame(1, intval($state['pending_prompt']['discard_count'] ?? 0));

        $state = applyAction($state, 'p1', 'resolve_prompt', [
            'choice' => 'yes',
            'discard_ids' => ['hand_any'],
        ]);

        $handIds = array_column($state['players']['p1']['hand'], 'instance_id');
        $this->assertNotContains('hand_any', $handIds, 'Discard cost must accept a non-μ\'s hand card');
        $pr = $state['pending_prompt'] ?? null;
        if ($pr) {
            $this->assertTrue(
                str_contains((string) ($pr['type'] ?? ''), 'wr')
                || ($pr['type'] ?? '') === 'pick_wr_to_hand'
                || !empty($pr['candidates']),
                'Expected Waiting Room Live pick after discard, got ' . json_encode($pr['type'] ?? null)
            );
        } else {
            $this->assertContains('wr_mus_live', $handIds);
        }
    }
}
