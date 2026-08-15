<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * PL!-bp5-001-P Honoka — Live Success: optional discard 1, then look at
 * Live total score + 2, add 1 to hand, rest to WR.
 */
final class HonokaBp5001LiveSuccessLookTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['TUT_PERF_MANUAL_PHASES'] = true;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TUT_PERF_MANUAL_PHASES']);
    }

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
        $this->fail('Missing test card ' . $cardNo);
    }

    private function stubCard(string $id, array $extra = []): array
    {
        return array_merge([
            'instance_id' => $id,
            'card_no' => 'LL-E-001-SD',
            'name_en' => $id,
            'card_type' => 'エネルギー',
        ], $extra);
    }

    private function baseState(): array
    {
        return [
            'status' => 'playing',
            'phase' => 'live_success_effects',
            'seq' => 1,
            'turn' => 1,
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
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'energy_zone' => [],
                    'main_deck' => [],
                    'success_lives' => [],
                    'live_zone' => [],
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

    public function testOptionalDiscardThenOpensSurveilPickForScorePlusTwo(): void
    {
        $honoka = $this->cardByNo('PL!-bp5-001-P', 'bp5_honoka');
        $this->assertSame('optional_discard_prompt', $honoka['abilities'][0]['type'] ?? null);
        $this->assertSame('look_reveal_live_score_plus', $honoka['abilities'][0]['then']['type'] ?? null);

        $disc = $this->stubCard('bp5_hon_disc');
        $a = $this->stubCard('bp5_hon_a');
        $b = $this->stubCard('bp5_hon_b');
        $c = $this->stubCard('bp5_hon_c');
        $rest = $this->stubCard('bp5_hon_rest');
        $live = $this->stubCard('bp5_hon_live', [
            'card_type' => 'ライブ',
            'name_en' => 'Test Live',
            'score' => 1,
        ]);

        $state = $this->baseState();
        $state['players']['p1']['stage']['center'] = $honoka;
        $state['players']['p1']['hand'] = [$disc];
        $state['players']['p1']['main_deck'] = [$a, $b, $c, $rest];
        $state['players']['p1']['live_zone'] = [$live];

        $state = \resolveLiveSuccessAbilities($state, 'p1', [$live], 0, [], []);

        $this->assertSame('optional_discard_prompt', $state['pending_prompt']['type'] ?? null);

        $state = \actionResolvePrompt($state, 'p1', [
            'choice' => 'yes',
            'discard_ids' => ['bp5_hon_disc'],
        ]);

        // Live score 1 + bonus 2 = look 3 → surveil pick (not silent no-op).
        $this->assertSame('surveil_pick_one', $state['pending_prompt']['type'] ?? null);
        $this->assertCount(3, $state['pending_prompt']['look_cards'] ?? []);
        $this->assertSame(['bp5_hon_disc'], array_column($state['players']['p1']['waiting_room'], 'instance_id'));
        $this->assertSame(['bp5_hon_rest'], array_column($state['players']['p1']['main_deck'], 'instance_id'));
        $this->assertSame([], array_column($state['players']['p1']['hand'], 'instance_id'));

        $state = \actionResolvePrompt($state, 'p1', [
            'choice' => 'bp5_hon_b',
            'card_id' => 'bp5_hon_b',
        ]);

        $this->assertNull($state['pending_prompt'] ?? null);
        $this->assertSame(['bp5_hon_b'], array_column($state['players']['p1']['hand'], 'instance_id'));
        $wrIds = array_column($state['players']['p1']['waiting_room'], 'instance_id');
        $this->assertContains('bp5_hon_disc', $wrIds);
        $this->assertContains('bp5_hon_a', $wrIds);
        $this->assertContains('bp5_hon_c', $wrIds);
        $this->assertNotContains('bp5_hon_b', $wrIds);
    }

    public function testSkipOptionalDoesNotLook(): void
    {
        $honoka = $this->cardByNo('PL!-bp5-001-P', 'bp5_honoka_skip');
        $disc = $this->stubCard('bp5_hon_skip_disc');
        $a = $this->stubCard('bp5_hon_skip_a');
        $live = $this->stubCard('bp5_hon_skip_live', [
            'card_type' => 'ライブ',
            'name_en' => 'Test Live',
            'score' => 1,
        ]);

        $state = $this->baseState();
        $state['players']['p1']['stage']['center'] = $honoka;
        $state['players']['p1']['hand'] = [$disc];
        $state['players']['p1']['main_deck'] = [$a];
        $state['players']['p1']['live_zone'] = [$live];

        $state = \resolveLiveSuccessAbilities($state, 'p1', [$live], 0, [], []);
        $state = \actionResolvePrompt($state, 'p1', ['choice' => 'no']);

        $this->assertNull($state['pending_prompt'] ?? null);
        $this->assertSame(['bp5_hon_skip_disc'], array_column($state['players']['p1']['hand'], 'instance_id'));
        $this->assertSame(['bp5_hon_skip_a'], array_column($state['players']['p1']['main_deck'], 'instance_id'));
        $this->assertSame([], $state['players']['p1']['waiting_room']);
    }
}
