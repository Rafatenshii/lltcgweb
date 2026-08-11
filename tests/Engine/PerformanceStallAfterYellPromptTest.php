<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * A prompt raised during the Yell step parks the Performance chain on
 * `_performance_continue`. Generic resolvers end in finishPromptEffects(), which
 * used to ignore the live_performance_* phases: the room stayed on live_show
 * stage `performance` forever (no heart check, no judge) and kept showing up in
 * the spectate list, where observers froze on the Yell columns.
 */
final class PerformanceStallAfterYellPromptTest extends TestCase
{
    private function live(string $id): array
    {
        return [
            'instance_id' => $id,
            'card_type' => 'ライブ',
            'card_type_en' => 'Live',
            'group' => 'Hasunosora',
            'name' => 'Test Live',
            'name_en' => 'Test Live',
            'score' => 3,
            'required_hearts' => [['color' => 'green', 'count' => 1]],
            'abilities' => [],
        ];
    }

    private function member(string $id): array
    {
        return [
            'instance_id' => $id,
            'card_type' => 'メンバー',
            'card_type_en' => 'Member',
            'group' => 'Hasunosora',
            'name_en' => 'M',
            'blade' => 1,
            'active' => true,
            'hearts' => [['color' => 'green', 'count' => 3]],
        ];
    }

    /** Yell done for both performers, chain parked mid-prompt on p1. */
    private function parkedAfterYellState(): array
    {
        return [
            'room_id' => 'STALL',
            'mode' => 'pvp',
            'status' => 'playing',
            'seq' => 40,
            'turn' => 3,
            'phase' => 'live_performance_second',
            'first_player' => 'p1',
            'active_player' => 'p1',
            'live_attempt' => ['p1', 'p2'],
            'live_show' => [
                'turn' => 3,
                'stage' => 'performance',
                'stage_seq' => 3,
                'started_at' => time() - 120,
                'acks' => [],
            ],
            '_perf_yell_both_done' => true,
            '_performance_continue' => 'p1',
            '_live_start_done' => ['p1' => true, 'p2' => true],
            'yell_reveal' => ['p1' => [], 'p2' => []],
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [],
                    'stage' => ['left' => null, 'center' => $this->member('m1'), 'right' => null],
                    'waiting_room' => [],
                    'energy_zone' => [],
                    'main_deck' => [],
                    'energy_deck' => [],
                    'live_zone' => [$this->live('live1')],
                    'yell_cards' => [],
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
                    'yell_cards' => [],
                    'success_lives' => [],
                ],
            ],
        ];
    }

    /** First performer's Yell raised a prompt; the second has not started yet. */
    private function parkedMidYellState(): array
    {
        $state = $this->parkedAfterYellState();
        unset($state['_perf_yell_both_done']);
        $state['phase'] = 'live_performance_first';
        $state['_live_start_done'] = ['p1' => true];
        $state['_live_start_perf_pid'] = 'p1';
        $state['players']['p2']['live_zone'] = [$this->live('live2')];
        $state['players']['p2']['stage']['center'] = $this->member('m2');
        $state['players']['p2']['main_deck'] = [
            $this->member('y1'),
            $this->member('y2'),
            $this->member('y3'),
        ];
        return $state;
    }

    public function testFinishPromptEffectsResumesPerformanceAfterYellPrompt(): void
    {
        $state = $this->parkedMidYellState();

        $after = \finishPromptEffects($state);

        $this->assertArrayNotHasKey(
            '_performance_continue',
            $after,
            'Resumed chain must consume the continue marker'
        );
        // Second performer must reveal before Live Start / Yell.
        $this->assertSame('live_start_effects', $after['phase'] ?? null);
        $this->assertSame('reveal', $after['live_show']['stage'] ?? null);
        $this->assertSame('p2', $after['live_show']['performer'] ?? null);

        $seq = intval($after['live_show']['stage_seq'] ?? 0);
        $after = \actionLiveShowAck($after, 'p1', ['stage_seq' => $seq]);
        $after = \actionLiveShowAck($after, 'p2', ['stage_seq' => $seq]);
        $this->assertSame('live_start', $after['live_show']['stage'] ?? null);

        $seq = intval($after['live_show']['stage_seq'] ?? 0);
        $after = \actionLiveShowAck($after, 'p1', ['stage_seq' => $seq]);
        $after = \actionLiveShowAck($after, 'p2', ['stage_seq' => $seq]);
        $this->assertSame('performance', $after['live_show']['stage'] ?? null);
        $this->assertTrue(
            !empty($after['_perf_yell_both_done']),
            'Second performer must Yell so the performance beat can be acked'
        );

        // The beat is now ackable: hearts resolve instead of the room wedging.
        $advanced = \advanceLiveShowStage($after);
        $this->assertNotSame('performance', $advanced['live_show']['stage'] ?? null);
    }

    public function testTimeoutHealsPerformanceStageThatCannotAdvance(): void
    {
        $state = $this->parkedAfterYellState();
        // Chain never reported back: no continue marker and Yell not marked done.
        unset($state['_performance_continue'], $state['_perf_yell_both_done']);
        $state['phase'] = 'live_performance_second';
        $state['_live_start_perf_pid'] = 'p2';
        $seqBefore = $state['seq'];

        $changed = \applyPhaseTimeouts($state);

        $this->assertTrue($changed);
        $this->assertGreaterThan($seqBefore, $state['seq']);
        $this->assertTrue(
            !empty($state['_perf_yell_both_done'])
                || ($state['live_show']['stage'] ?? '') !== 'performance'
                || !empty($state['pending_prompt']),
            'Timeout must unstick the Performance chain, not just bump seq'
        );
    }

    public function testTimeoutDoesNotSeqSpamWhenNothingCanAdvance(): void
    {
        $state = $this->parkedAfterYellState();
        unset($state['_performance_continue'], $state['_perf_yell_both_done']);
        $GLOBALS['TUT_PERF_MANUAL_PHASES'] = true;
        try {
            \applyPhaseTimeouts($state);
            $seqAfterFirst = $state['seq'];
            $state['live_show']['started_at'] = time() - 120;
            \applyPhaseTimeouts($state);
            $this->assertSame(
                $seqAfterFirst,
                $state['seq'],
                'A no-op live_show advance must not bump seq on every poll'
            );
        } finally {
            unset($GLOBALS['TUT_PERF_MANUAL_PHASES']);
        }
    }
}
