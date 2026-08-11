<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

final class SequentialLiveShowTest extends TestCase
{
    private function player(string $id): array
    {
        return [
            'id' => $id,
            'token' => $id . '-token',
            'name' => strtoupper($id),
            'hand' => [],
            'main_deck' => [],
            'energy_deck' => [],
            'energy_zone' => [],
            'waiting_room' => [],
            'success_lives' => [],
            'stage' => ['left' => null, 'center' => null, 'right' => null],
            'live_zone' => [[
                'instance_id' => $id . '-live',
                'card_no' => $id . '-live',
                'card_type' => 'ライブ',
                'card_type_en' => 'Live',
                'name_en' => strtoupper($id) . ' Live',
                'score' => 1,
                'hearts' => [],
                'abilities' => [],
                'revealed' => false,
            ]],
        ];
    }

    private function state(): array
    {
        return [
            'status' => 'playing',
            'phase' => 'live_set',
            'seq' => 10,
            'turn' => 2,
            'first_player' => 'p1',
            'active_player' => 'p1',
            'log' => [],
            'players' => [
                'p1' => $this->player('p1'),
                'p2' => $this->player('p2'),
            ],
        ];
    }

    private function ackBoth(array $state): array
    {
        $seq = intval($state['live_show']['stage_seq'] ?? 0);
        $state = \actionLiveShowAck($state, 'p1', ['stage_seq' => $seq]);
        return \actionLiveShowAck($state, 'p2', ['stage_seq' => $seq]);
    }

    public function testPerformanceStartsAtPersistedRevealStage(): void
    {
        $state = \beginPerformancePhase($this->state());

        $this->assertSame('reveal', $state['live_show']['stage'] ?? null);
        $this->assertSame('p1', $state['live_show']['performer'] ?? null);
        $this->assertSame('live_start_effects', $state['phase'] ?? null);
        $this->assertTrue(!empty($state['players']['p1']['live_zone'][0]['revealed']));
        $this->assertFalse(!empty($state['players']['p2']['live_zone'][0]['revealed']));
        $this->assertStringNotContainsString(
            'Live Scores:',
            implode("\n", array_column($state['log'], 'msg'))
        );
        $this->assertStringNotContainsString(
            'simultaneously',
            implode("\n", array_column($state['log'], 'msg'))
        );
        // Live Start skills must not run until the reveal beat is acknowledged.
        $this->assertNull($state['pending_prompt'] ?? null);
    }

    public function testSecondPerformerStaysFaceDownUntilAfterFirstYell(): void
    {
        $state = \beginPerformancePhase($this->state());
        $this->assertFalse(!empty($state['players']['p2']['live_zone'][0]['revealed']));

        $state = $this->ackBoth($state); // reveal → live_start
        $this->assertSame('live_start', $state['live_show']['stage'] ?? null);
        $this->assertFalse(!empty($state['players']['p2']['live_zone'][0]['revealed']));

        $state = $this->ackBoth($state); // live_start → performance (p1 Yell) → may park on p2 reveal
        // After p1 Yell, second reveal beat flips p2 before their Live Start.
        if (($state['live_show']['stage'] ?? '') === 'performance'
            && empty($state['_perf_yell_both_done'])) {
            $state = \continuePerformanceYellPhase($state, 'p1');
        }
        $this->assertSame('reveal', $state['live_show']['stage'] ?? null);
        $this->assertSame('p2', $state['live_show']['performer'] ?? null);
        $this->assertTrue(!empty($state['players']['p2']['live_zone'][0]['revealed']));
        $this->assertArrayHasKey('p1', $state['yell_reveal'] ?? []);
    }

    public function testBothPlayersAdvanceEachPresentationBeat(): void
    {
        $state = \beginPerformancePhase($this->state());
        $this->assertSame('reveal', $state['live_show']['stage']);
        $this->assertSame('p1', $state['live_show']['performer'] ?? null);

        $state = $this->ackBoth($state);
        $this->assertSame('live_start', $state['live_show']['stage']);

        // First Yell resolves immediately, then parks on the second performer's reveal.
        $state = $this->ackBoth($state);
        $this->assertSame('reveal', $state['live_show']['stage']);
        $this->assertSame('p2', $state['live_show']['performer'] ?? null);
        $this->assertArrayHasKey('p1', $state['yell_reveal'] ?? []);
        $this->assertStringNotContainsString(
            'performed Live! Blades:',
            implode("\n", array_column($state['log'], 'msg'))
        );
        $this->assertStringNotContainsString(
            'Live Scores:',
            implode("\n", array_column($state['log'], 'msg'))
        );

        $state = $this->ackBoth($state);
        $this->assertSame('live_start', $state['live_show']['stage']);

        $state = $this->ackBoth($state);
        $this->assertSame('performance', $state['live_show']['stage']);
        $this->assertTrue(!empty($state['_perf_yell_both_done']));

        $state = $this->ackBoth($state);
        $this->assertSame('outcomes', $state['live_show']['stage']);
        $this->assertStringContainsString(
            'performed Live! Blades:',
            implode("\n", array_column($state['log'], 'msg'))
        );
        $this->assertStringNotContainsString(
            'Live Scores:',
            implode("\n", array_column($state['log'], 'msg'))
        );

        $state = $this->ackBoth($state);
        $this->assertSame('judge', $state['live_show']['stage']);
        $this->assertStringContainsString(
            'Live Scores:',
            implode("\n", array_column($state['log'], 'msg'))
        );
    }

    public function testPreJudgeFilteredStateRedactsScoreLog(): void
    {
        $source = $this->state();
        $source['live_show'] = ['stage' => 'outcomes'];
        $filtered = $source;
        $filtered['log'] = [
            ['msg' => 'Live Scores: P1 = 3 | P2 = 2'],
            ['msg' => 'P1 wins the Live — P2 failed.'],
            ['msg' => 'Visible performance line'],
        ];
        $filtered['players']['p1']['live_zone'][0]['score'] = 9;
        $filtered['players']['p1']['live_zone'][0]['live_score_bonus'] = 2;

        \hideLiveJudgeSpoilersFromFilteredState($filtered, $source);

        $this->assertTrue($filtered['live_scores_hidden']);
        $this->assertSame(['Visible performance line'], array_column($filtered['log'], 'msg'));
        $this->assertNull($filtered['players']['p1']['live_zone'][0]['score']);
        $this->assertSame(0, $filtered['players']['p1']['live_zone'][0]['live_score_bonus']);
        $this->assertSame('outcomes', $filtered['live_show']['stage']);
    }

    public function testJudgeStageStopsRedactingScores(): void
    {
        $source = $this->state();
        $source['live_show'] = ['stage' => 'judge'];
        $filtered = $source;
        $filtered['log'] = [
            ['msg' => 'Live Scores: P1 = 3 | P2 = 2'],
        ];

        \hideLiveJudgeSpoilersFromFilteredState($filtered, $source);

        $this->assertArrayNotHasKey('live_scores_hidden', $filtered);
        $this->assertSame(['Live Scores: P1 = 3 | P2 = 2'], array_column($filtered['log'], 'msg'));
    }

    public function testPerformanceAckWaitsUntilBothYellsDone(): void
    {
        // Simulate 1st Yell complete + 2nd Live Start in progress (yell_reveal partial).
        $state = $this->state();
        $state['phase'] = 'live_start_effects';
        $state['live_attempt'] = ['p1', 'p2'];
        $state['live_show'] = [
            'turn' => 2,
            'stage' => 'performance',
            'started_at' => time(),
            'stage_seq' => 3,
            'acks' => [],
        ];
        $state['yell_reveal'] = [
            'p1' => [[
                'instance_id' => 'yell1',
                'card_no' => 'y1',
                'card_type' => 'メンバー',
                'card_type_en' => 'Member',
                'name_en' => 'Yell Chip',
            ]],
            'p2' => [],
        ];
        unset($state['_perf_yell_both_done']);

        $after = $this->ackBoth($state);
        $this->assertSame('performance', $after['live_show']['stage'] ?? null);
        $this->assertStringNotContainsString(
            'performed Live! Blades:',
            implode("\n", array_column($after['log'] ?? [], 'msg'))
        );

        $state['_perf_yell_both_done'] = true;
        $state['live_show']['acks'] = [];
        $afterBoth = $this->ackBoth($state);
        $this->assertSame('outcomes', $afterBoth['live_show']['stage'] ?? null);
    }

    public function testYellRevealExposedDuringSecondLiveStart(): void
    {
        $state = $this->state();
        $state['phase'] = 'live_start_effects';
        $state['players']['p1']['token'] = 'p1-token';
        $state['yell_reveal'] = [
            'p1' => [[
                'instance_id' => 'yell1',
                'card_no' => 'y1',
                'card_type' => 'メンバー',
                'card_type_en' => 'Member',
                'name_en' => 'Yell Chip',
            ]],
            'p2' => [],
        ];
        $state['live_show'] = [
            'turn' => 2,
            'stage' => 'performance',
            'started_at' => time(),
            'stage_seq' => 3,
            'acks' => [],
        ];

        $filtered = \filterStateForPlayer($state, 'p1-token');
        $this->assertNotEmpty($filtered['yell_reveal']['p1'] ?? []);
        $this->assertSame('yell1', $filtered['yell_reveal']['p1'][0]['instance_id'] ?? null);
    }
}
