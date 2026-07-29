<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/** Shift+T force_own_timeout — voluntary timer expiry without ranked inactivity. */
final class ForceOwnTimeoutTest extends TestCase
{
    private function baseState(): array {
        return [
            'room_id' => 'FORCE_TO',
            'status' => 'playing',
            'seq' => 3,
            'turn' => 1,
            'phase' => 'main_first',
            'first_player' => 'p1',
            'active_player' => 'p1',
            'mode' => 'ranked',
            'log' => [],
            'ranked' => [
                'inactivity_timeouts' => ['p1' => 1, 'p2' => 0],
            ],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'waiting_room' => [],
                    'energy_zone' => [],
                    'main_deck' => [['instance_id' => 'd1', 'card_type' => 'メンバー']],
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
                    'main_deck' => [['instance_id' => 'd2', 'card_type' => 'メンバー']],
                    'energy_deck' => [],
                    'live_zone' => [],
                    'success_lives' => [],
                ],
            ],
        ];
    }

    public function testForceOwnTimeoutResolvesOwnPromptWithoutInactivityStrike(): void {
        $state = $this->baseState();
        $state['pending_prompt'] = [
            'type' => 'optional_discard_prompt',
            'owner' => 'p1',
            'responder' => 'p1',
            'source_name' => 'Stuck Card',
            'choices' => ['yes', 'no'],
            'choice_labels' => ['Yes', 'No — Skip'],
            'ability' => ['discard' => 1],
        ];

        $after = applyAction($state, 'p1', 'force_own_timeout', []);
        $this->assertNull($after['pending_prompt'] ?? null);
        $this->assertSame(1, intval($after['ranked']['inactivity_timeouts']['p1'] ?? 0),
            'Voluntary force-timeout must not add an inactivity strike');
        $this->assertSame('main_first', $after['phase']);
    }

    public function testForceOwnTimeoutEndsMainWhenNoPrompt(): void {
        $state = $this->baseState();
        $after = applyAction($state, 'p1', 'force_own_timeout', []);
        $this->assertNotSame('main_first', $after['phase']);
        $found = false;
        foreach ($after['log'] ?? [] as $entry) {
            $msg = is_array($entry) ? ($entry['msg'] ?? '') : (string) $entry;
            if (str_contains($msg, 'forced Main Phase timeout')) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found);
    }

    public function testForceOwnTimeoutRejectsWhenNotYourClock(): void {
        $state = $this->baseState();
        $state['active_player'] = 'p2';
        $this->expectException(\Exception::class);
        applyAction($state, 'p1', 'force_own_timeout', []);
    }
}
