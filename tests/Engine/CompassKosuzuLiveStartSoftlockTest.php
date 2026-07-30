<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * Room 366B1909 softlock: Kosuzu pb1-005 pick_number cleared pending_prompt
 * without finishPromptEffects, so COMPASS never opened and phase stayed
 * live_start_effects with prompt=null.
 */
final class CompassKosuzuLiveStartSoftlockTest extends TestCase
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

    private function baseState(): array
    {
        $kosuzu = $this->cardByNo('PL!HS-pb1-005-R', 'kosuzu_1');
        $compass = $this->cardByNo('PL!HS-pb1-028-L', 'compass_1');
        $compass['face_up'] = true;
        $lowCostMember = $this->cardByNo('PL!HS-sd1-009-SD', 'deck_top_1');
        // Force revealed cost below chosen number so +2 Blade path is taken.
        $lowCostMember['cost'] = 4;

        return [
            'status' => 'playing',
            'phase' => 'live_start_effects',
            'seq' => 1,
            'turn' => 5,
            'first_player' => 'p1',
            'active_player' => 'p1',
            'live_attempt' => ['p1'],
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [],
                    'waiting_room' => [],
                    'stage' => [
                        'left' => null,
                        'center' => null,
                        'right' => $kosuzu,
                    ],
                    'energy_zone' => [],
                    'main_deck' => [$lowCostMember],
                    'success_lives' => [],
                    'live_zone' => [$compass],
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

    public function testKosuzuNumberPickResumesToCompassPrompt(): void
    {
        $GLOBALS['TUT_PERF_MANUAL_PHASES'] = true;
        try {
            $state = $this->baseState();
            $state = \resolveLiveStartAbilities($state, 'p1');
            $this->assertSame('pick_number_reveal_deck_top', $state['pending_prompt']['type'] ?? null);
            $this->assertSame('live_start_effects', $state['phase'] ?? null);

            $state = \actionResolvePrompt($state, 'p1', ['choice' => '15']);
            $this->assertSame('pick_number_reveal_deck_top', $state['pending_prompt']['type'] ?? null);
            $this->assertSame('resolve_reveal', $state['pending_prompt']['step'] ?? null);
            $state = \actionResolvePrompt($state, 'p1', ['choice' => 'confirm']);
            $this->assertSame(
                'live_start_activate_stage_live_start_ability',
                $state['pending_prompt']['type'] ?? null,
                'After Kosuzu number pick, COMPASS must open (not softlock with null prompt)'
            );
            $this->assertSame('live_start_effects', $state['phase'] ?? null);
        } finally {
            unset($GLOBALS['TUT_PERF_MANUAL_PHASES']);
        }
    }

    public function testCompassActivatesKosuzuNestedNumberPickThenFinishes(): void
    {
        $GLOBALS['TUT_PERF_MANUAL_PHASES'] = true;
        try {
            $state = $this->baseState();
            // Second reveal for COMPASS-triggered Kosuzu ability.
            $state['players']['p1']['main_deck'][] = $this->cardByNo('PL!HS-sd1-010-SD', 'deck_top_2');
            $state['players']['p1']['main_deck'][1]['cost'] = 3;

            $state = \resolveLiveStartAbilities($state, 'p1');
            $state = \actionResolvePrompt($state, 'p1', ['choice' => '15']);
            $this->assertSame('resolve_reveal', $state['pending_prompt']['step'] ?? null);
            $state = \actionResolvePrompt($state, 'p1', ['choice' => 'confirm']);
            $this->assertSame('live_start_activate_stage_live_start_ability', $state['pending_prompt']['type'] ?? null);

            $state = \actionResolvePrompt($state, 'p1', ['slot' => 'right']);
            $this->assertSame(
                'pick_number_reveal_deck_top',
                $state['pending_prompt']['type'] ?? null,
                'COMPASS must open nested Kosuzu pick_number (not wipe it)'
            );

            $state = \actionResolvePrompt($state, 'p1', ['choice' => '6']);
            $this->assertSame('resolve_reveal', $state['pending_prompt']['step'] ?? null);
            $state = \actionResolvePrompt($state, 'p1', ['choice' => 'confirm']);
            $this->assertNull($state['pending_prompt'] ?? null);
            $this->assertArrayNotHasKey('live_start_optional_queue', $state);
            $this->assertArrayNotHasKey('_live_start_resume_from', $state);
            // With TUT_PERF_MANUAL_PHASES, finishLiveStartEffects keeps phase but clears resume/queue.
            $this->assertSame('live_start_effects', $state['phase'] ?? null);
        } finally {
            unset($GLOBALS['TUT_PERF_MANUAL_PHASES']);
        }
    }

    public function testCompassSkipFinishesLiveStart(): void
    {
        $GLOBALS['TUT_PERF_MANUAL_PHASES'] = true;
        try {
            $state = $this->baseState();
            $state = \resolveLiveStartAbilities($state, 'p1');
            $state = \actionResolvePrompt($state, 'p1', ['choice' => '15']);
            $state = \actionResolvePrompt($state, 'p1', ['choice' => 'confirm']);
            $state = \actionResolvePrompt($state, 'p1', ['choice' => 'skip']);
            $this->assertNull($state['pending_prompt'] ?? null);
            $this->assertArrayNotHasKey('live_start_optional_queue', $state);
        } finally {
            unset($GLOBALS['TUT_PERF_MANUAL_PHASES']);
        }
    }
}
