<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/** A Success Live choice must consume its judge step instead of reopening itself. */
final class JudgeSuccessLivePickProgressTest extends TestCase
{
    private function live(string $id, int $score): array
    {
        return [
            'instance_id' => $id,
            'card_type' => 'ライブ',
            'card_type_en' => 'Live',
            'name_en' => 'Live ' . $id,
            'score' => $score,
            'required_hearts' => [],
        ];
    }

    private function state(): array
    {
        return [
            'room_id' => 'JUDGEPICK',
            'status' => 'playing',
            'seq' => 40,
            'turn' => 4,
            'phase' => 'live_judge',
            'first_player' => 'p1',
            'active_player' => 'p1',
            'log' => [],
            '_live_judge_ctx' => [
                'live_winners' => ['p1'],
                'block_tie_success' => false,
                'is_score_tie' => false,
                'success_placed_by' => [],
                'winner_index' => 0,
            ],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'waiting_room' => [],
                    'energy_zone' => [],
                    'energy_deck' => [],
                    'main_deck' => [
                        ['instance_id' => 'd1', 'card_type' => 'メンバー', 'name_en' => 'D1'],
                    ],
                    'live_zone' => [$this->live('live_a', 3), $this->live('live_b', 4)],
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
                        ['instance_id' => 'd2', 'card_type' => 'メンバー', 'name_en' => 'D2'],
                    ],
                    'live_zone' => [],
                    'success_lives' => [],
                ],
            ],
        ];
    }

    public function testSelectedSuccessLiveAdvancesJudgeRatherThanReopeningPick(): void
    {
        $state = $this->state();
        $prompt = [
            'type' => 'pick_judge_success_live',
            'owner' => 'p1',
            'responder' => 'p1',
            'candidates' => [
                ['instance_id' => 'live_a'],
                ['instance_id' => 'live_b'],
            ],
        ];
        $state['pending_prompt'] = $prompt;

        $after = \actionResolvePickJudgeSuccessLive($state, 'p1', $prompt, ['card_id' => 'live_b']);

        $this->assertNotSame('pick_judge_success_live', $after['pending_prompt']['type'] ?? null);
        $this->assertSame(['live_b'], array_column($after['players']['p1']['success_lives'], 'instance_id'));
        $this->assertSame([], $after['players']['p1']['live_zone']);
        $this->assertArrayNotHasKey('_live_judge_ctx', $after);
        $this->assertContains($after['phase'], ['main_first', 'active_first']);
    }
}
