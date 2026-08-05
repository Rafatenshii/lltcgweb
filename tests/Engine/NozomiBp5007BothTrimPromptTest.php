<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/** PL!-bp5-007 — both players choose their own discards down to 3, then both draw 3. */
final class NozomiBp5007BothTrimPromptTest extends TestCase
{
    public function testOwnerIsPromptedBeforeAnythingIsDiscarded(): void
    {
        $state = $this->baseState(5, 5);
        $state = plMuseGapResolveEffect($state, 'p1', $this->nozomi(), $this->ability());

        $pr = $state['pending_prompt'] ?? null;
        $this->assertSame('effect_discard_hand', $pr['type'] ?? null);
        $this->assertSame('p1', $pr['responder'] ?? null);
        $this->assertSame(2, $pr['count'] ?? null);
        $this->assertCount(5, $state['players']['p1']['hand']);
        $this->assertCount(5, $state['players']['p2']['hand']);
        $this->assertSame([], $state['players']['p1']['waiting_room']);
    }

    public function testOpponentIsPromptedAfterOwnerAndDrawsHappenLast(): void
    {
        $state = $this->baseState(5, 6);
        $state = plMuseGapResolveEffect($state, 'p1', $this->nozomi(), $this->ability());
        $state = actionResolvePrompt($state, 'p1', ['discard_ids' => ['p1h1', 'p1h2']]);

        $pr = $state['pending_prompt'] ?? null;
        $this->assertSame('effect_discard_hand', $pr['type'] ?? null);
        $this->assertSame('p2', $pr['responder'] ?? null);
        $this->assertSame(3, $pr['count'] ?? null);
        $this->assertCount(3, $state['players']['p1']['hand'], 'owner keeps 3 until both trims finish');

        $state = actionResolvePrompt($state, 'p2', ['discard_ids' => ['p2h1', 'p2h2', 'p2h3']]);

        $this->assertNull($state['pending_prompt'] ?? null);
        $this->assertCount(6, $state['players']['p1']['hand']);
        $this->assertCount(6, $state['players']['p2']['hand']);
        $this->assertCount(2, $state['players']['p1']['waiting_room']);
        $this->assertCount(3, $state['players']['p2']['waiting_room']);
    }

    public function testPlayerAtOrUnderTargetIsNotPrompted(): void
    {
        $state = $this->baseState(3, 4);
        $state = plMuseGapResolveEffect($state, 'p1', $this->nozomi(), $this->ability());

        $pr = $state['pending_prompt'] ?? null;
        $this->assertSame('p2', $pr['responder'] ?? null);
        $this->assertSame(1, $pr['count'] ?? null);
    }

    public function testNoPromptWhenBothHandsAreSmallEnough(): void
    {
        $state = $this->baseState(2, 3);
        $state = plMuseGapResolveEffect($state, 'p1', $this->nozomi(), $this->ability());

        $this->assertNull($state['pending_prompt'] ?? null);
        $this->assertCount(5, $state['players']['p1']['hand']);
        $this->assertCount(6, $state['players']['p2']['hand']);
    }

    private function ability(): array
    {
        return ['type' => 'both_players_trim_then_draw', 'target_hand' => 3, 'draw' => 3];
    }

    private function nozomi(): array
    {
        return [
            'instance_id' => 'nozomi',
            'card_type' => 'メンバー',
            'name_en' => 'Nozomi Tojo',
            'group' => "μ's",
            'cost' => 13,
            'active' => true,
        ];
    }

    private function baseState(int $p1Hand, int $p2Hand): array
    {
        return [
            'room_id' => 'BP5007',
            'status' => 'playing',
            'seq' => 1,
            'turn' => 3,
            'phase' => 'main_first',
            'active_player' => 'p1',
            'log' => [],
            'players' => [
                'p1' => $this->player('p1', $p1Hand),
                'p2' => $this->player('p2', $p2Hand),
            ],
        ];
    }

    private function player(string $pid, int $handCount): array
    {
        $cards = fn(string $prefix, int $n) => array_map(fn(int $i) => [
            'instance_id' => $pid . $prefix . $i,
            'card_type' => 'メンバー',
            'name_en' => 'Filler',
            'group' => "μ's",
        ], range(1, max(1, $n)));

        return [
            'id' => $pid,
            'name' => strtoupper($pid),
            'hand' => $handCount > 0 ? $cards('h', $handCount) : [],
            'main_deck' => $cards('d', 10),
            'waiting_room' => [],
            'stage' => ['left' => null, 'center' => null, 'right' => null],
            'live_zone' => [],
            'energy_zone' => [],
        ];
    }
}
