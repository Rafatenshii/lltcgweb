<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * CPU seats have no phase timer. A client that dies mid-skill used to freeze
 * the room forever (Wait overlay, resign looking broken). Server polls must
 * auto-resolve then dismiss that prompt.
 */
final class CpuStuckPromptTimeoutTest extends TestCase
{
    private function live(string $id): array
    {
        return [
            'instance_id' => $id,
            'card_no' => 'TEST-' . $id,
            'name_en' => 'Niji Live',
            'card_type' => 'ライブ',
            'card_type_en' => 'Live',
            'group' => 'Nijigasaki',
            'score' => 1,
        ];
    }

    private function cpuState(): array
    {
        return [
            'room_id' => 'CPUSTUCK',
            'status' => 'playing',
            'cpu_solo' => true,
            'cpu_difficulty' => 'normal',
            'phase' => 'main_first',
            'seq' => 10,
            'turn' => 5,
            'active_player' => 'p2',
            'first_player' => 'p1',
            'phase_timer_cfg' => ['enabled' => false, 'duration' => 60],
            'log' => [],
            'action_log' => [
                ['player' => 'p2', 'type' => 'resolve_prompt', 'ts' => time() - 90, 'data' => ['choice' => 'yes']],
            ],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'Human',
                    'token' => 'human-token',
                    'hand' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'waiting_room' => [],
                    'energy_zone' => [],
                    'main_deck' => [],
                    'success_lives' => [],
                    'live_zone' => [],
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'CPU (Normal)',
                    'token' => 'cpu-token',
                    'is_cpu' => true,
                    'deck_choice' => 'cpu',
                    'hand' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'waiting_room' => [$this->live('wr_niji')],
                    'energy_zone' => [],
                    'main_deck' => [],
                    'success_lives' => [$this->live('succ_niji')],
                    'live_zone' => [],
                ],
            ],
            'pending_prompt' => [
                'type' => 'optional_success_wr_live_swap',
                'owner' => 'p2',
                'responder' => 'p2',
                'source_name' => 'Shioriko Mifune',
                'step' => 'pick_success_live',
                'group' => 'Nijigasaki',
                'filter' => 'live',
                'candidates' => [
                    [
                        'instance_id' => 'succ_niji',
                        'name_en' => 'Niji Live',
                        'card_type' => 'ライブ',
                        'group' => 'Nijigasaki',
                        'score' => 1,
                    ],
                ],
            ],
        ];
    }

    public function testCpuPromptOlderThanThresholdIsClearedWithoutPhaseTimer(): void
    {
        $state = $this->cpuState();
        $changed = \applyPhaseTimeouts($state);
        $this->assertTrue($changed);
        $this->assertGreaterThan(10, intval($state['seq'] ?? 0));
        $after = $state['pending_prompt'] ?? null;
        if (is_array($after)) {
            $this->assertNotSame('pick_success_live', $after['step'] ?? null);
        }
    }

    public function testFreshCpuPromptIsOnlyStamped(): void
    {
        $state = $this->cpuState();
        $state['action_log'] = [
            ['player' => 'p2', 'type' => 'resolve_prompt', 'ts' => time() - 2, 'data' => ['choice' => 'yes']],
        ];
        $changed = \applyPhaseTimeouts($state);
        $this->assertNotNull($state['pending_prompt'] ?? null);
        $this->assertSame('pick_success_live', $state['pending_prompt']['step'] ?? null);
        $this->assertGreaterThan(0, intval($state['pending_prompt']['opened_at'] ?? 0));
        $this->assertTrue($changed);
    }
}
