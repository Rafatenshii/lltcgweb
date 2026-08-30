<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * Rin PL!-bp6-005 On Enter: discard 2 to add up to 1 Member with a Yellow
 * printed heart and up to 1 Live requiring a Yellow heart from the Waiting Room.
 * JP: 黄ハートを持つ — not Blade heart.
 */
final class RinBp6005DiscardAddWrTest extends TestCase
{
    private function rin(): array
    {
        return [
            'instance_id' => 'rin',
            'card_no' => 'PL!-bp6-005-R',
            'name_en' => 'Rin Hoshizora',
            'card_type' => 'メンバー',
            'group' => "μ's",
            'cost' => 11,
            'abilities' => [[
                'trigger' => 'on_enter',
                'type' => 'optional_discard2_add_wr_heart_member_and_heart_live',
                'discard' => 2,
                'heart_color' => 'yellow',
            ]],
        ];
    }

    private function baseState(array $waitingRoom, int $handSize = 3): array
    {
        $hand = [];
        for ($i = 1; $i <= $handSize; $i++) {
            $hand[] = [
                'instance_id' => "hand$i",
                'name_en' => "Hand $i",
                'card_type' => 'メンバー',
                'group' => "μ's",
                'cost' => 2,
            ];
        }
        $empty = [
            'id' => 'p2',
            'name' => 'P2',
            'hand' => [],
            'waiting_room' => [],
            'stage' => ['left' => null, 'center' => null, 'right' => null],
            'energy_zone' => [],
            'main_deck' => [],
            'live_zone' => [],
            'success_lives' => [],
        ];
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
                    'hand' => $hand,
                    'waiting_room' => $waitingRoom,
                    'stage' => ['left' => null, 'center' => $this->rin(), 'right' => null],
                    'energy_zone' => [],
                    'main_deck' => [],
                    'live_zone' => [],
                    'success_lives' => [],
                ],
                'p2' => $empty,
            ],
        ];
    }

    private function wrMember(string $id, array $hearts, array $bladeHearts = []): array
    {
        return [
            'instance_id' => $id,
            'name_en' => 'WR ' . $id,
            'card_type' => 'メンバー',
            'group' => "μ's",
            'cost' => 5,
            'blade' => 1,
            'hearts' => $hearts,
            'blade_hearts' => $bladeHearts,
        ];
    }

    private function wrLive(string $id, string $color): array
    {
        return [
            'instance_id' => $id,
            'name_en' => 'WR ' . $id,
            'card_type' => 'ライブ',
            'group' => "μ's",
            'score' => 3,
            'required_hearts' => [['color' => $color, 'count' => 2]],
        ];
    }

    private function enterRin(array $state): array
    {
        $rin = $state['players']['p1']['stage']['center'];
        return \resolveAbilityEffect($state, 'p1', $rin, $rin['abilities'][0], [
            'slot' => 'center',
            'phase' => 'on_enter',
        ]);
    }

    public function testCatalogUsesPrintedYellowHeartNotBlade(): void
    {
        $data = json_decode((string) file_get_contents((string) constant('CARDS_FILE')), true);
        foreach (['PL!-bp6-005-R', 'PL!-bp6-005-P'] as $no) {
            $card = null;
            foreach ($data['cards'] ?? [] as $c) {
                if (($c['card_no'] ?? '') === $no) {
                    $card = $c;
                    break;
                }
            }
            $this->assertNotNull($card, $no);
            $ab = ($card['abilities'] ?? [])[0] ?? [];
            $this->assertSame(
                'optional_discard2_add_wr_heart_member_and_heart_live',
                $ab['type'] ?? null,
                $no
            );
            $this->assertSame('yellow', $ab['heart_color'] ?? null, $no);
            $this->assertStringContainsString('Yellow heart', (string)($card['text'] ?? ''), $no);
            $this->assertStringNotContainsString('Blade heart', (string)($card['text'] ?? ''), $no);
        }
    }

    public function testDiscardTwoThenPicksYellowHeartMemberAndYellowHeartLive(): void
    {
        $state = $this->baseState([
            // Printed yellow heart only — Blade yellow must NOT qualify alone.
            $this->wrMember('mem_yellow', [['color' => 'yellow', 'count' => 1]]),
            $this->wrMember('mem_blade_only', [], ['yellow']),
            $this->wrMember('mem_pink', [['color' => 'pink', 'count' => 1]]),
            $this->wrLive('live_yellow', 'yellow'),
            $this->wrLive('live_red', 'red'),
        ]);

        $state = $this->enterRin($state);
        $this->assertSame('optional_discard_prompt', $state['pending_prompt']['type'] ?? null);
        $this->assertSame(2, $state['pending_prompt']['ability']['discard'] ?? null);

        $state = \actionResolvePrompt($state, 'p1', [
            'choice' => 'yes',
            'discard_ids' => ['hand1', 'hand2'],
        ]);

        $this->assertSame('pl_muse_wr_pick_sequence', $state['pending_prompt']['type'] ?? null);
        $this->assertSame('pick_member', $state['pending_prompt']['step'] ?? null);
        $this->assertSame(
            ['mem_yellow'],
            array_column($state['pending_prompt']['candidates'] ?? [], 'instance_id')
        );

        $state = \actionResolvePrompt($state, 'p1', ['card_id' => 'mem_yellow']);

        $this->assertSame('pl_muse_wr_pick_sequence', $state['pending_prompt']['type'] ?? null);
        $this->assertSame('pick_live', $state['pending_prompt']['step'] ?? null);
        $this->assertSame(
            ['live_yellow'],
            array_column($state['pending_prompt']['candidates'] ?? [], 'instance_id')
        );

        $state = \actionResolvePrompt($state, 'p1', ['card_id' => 'live_yellow']);

        $this->assertArrayNotHasKey('pending_prompt', $state);
        $handIds = array_column($state['players']['p1']['hand'], 'instance_id');
        $this->assertContains('mem_yellow', $handIds);
        $this->assertContains('live_yellow', $handIds);
        $this->assertNotContains('hand1', $handIds);
        $this->assertNotContains('hand2', $handIds);
        $wrIds = array_column($state['players']['p1']['waiting_room'], 'instance_id');
        $this->assertContains('hand1', $wrIds);
        $this->assertNotContains('mem_yellow', $wrIds);
    }

    public function testSkippingLiveStepStillAddsMemberAndClearsPrompt(): void
    {
        $state = $this->baseState([
            $this->wrMember('mem_yellow', [['color' => 'yellow', 'count' => 1]]),
            $this->wrLive('live_yellow', 'yellow'),
        ]);
        $state = $this->enterRin($state);
        $state = \actionResolvePrompt($state, 'p1', [
            'choice' => 'yes',
            'discard_ids' => ['hand1', 'hand2'],
        ]);
        $state = \actionResolvePrompt($state, 'p1', ['card_id' => 'mem_yellow']);
        $state = \actionResolvePrompt($state, 'p1', ['choice' => 'skip']);

        $this->assertArrayNotHasKey('pending_prompt', $state);
        $handIds = array_column($state['players']['p1']['hand'], 'instance_id');
        $this->assertContains('mem_yellow', $handIds);
        $this->assertNotContains('live_yellow', $handIds);
    }

    public function testStepWithoutCandidatesIsSkipped(): void
    {
        $state = $this->baseState([
            $this->wrMember('mem_pink', [['color' => 'pink', 'count' => 1]]),
            $this->wrLive('live_yellow', 'yellow'),
        ]);
        $state = $this->enterRin($state);
        $state = \actionResolvePrompt($state, 'p1', [
            'choice' => 'yes',
            'discard_ids' => ['hand1', 'hand2'],
        ]);

        $this->assertSame('pick_live', $state['pending_prompt']['step'] ?? null);
    }

    public function testNoPromptWhenWaitingRoomHasNoMatch(): void
    {
        $state = $this->baseState([
            $this->wrMember('mem_pink', [['color' => 'pink', 'count' => 1]]),
            $this->wrLive('live_red', 'red'),
        ]);
        $state = $this->enterRin($state);
        $this->assertArrayNotHasKey('pending_prompt', $state);
    }

    public function testNoPromptWhenHandTooSmallToPayCost(): void
    {
        $state = $this->baseState([
            $this->wrMember('mem_yellow', [['color' => 'yellow', 'count' => 1]]),
        ], 1);
        $state = $this->enterRin($state);
        $this->assertArrayNotHasKey('pending_prompt', $state);
    }

    public function testDecliningTheOptionalCostKeepsHand(): void
    {
        $state = $this->baseState([
            $this->wrMember('mem_yellow', [['color' => 'yellow', 'count' => 1]]),
        ]);
        $state = $this->enterRin($state);
        $state = \actionResolvePrompt($state, 'p1', ['choice' => 'no']);

        $this->assertArrayNotHasKey('pending_prompt', $state);
        $this->assertCount(3, $state['players']['p1']['hand']);
    }
}
