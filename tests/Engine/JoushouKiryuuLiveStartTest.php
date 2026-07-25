<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * Regression: Joushou Kiryuu (PL!HS-bp5-021-L) Live Start softlock —
 * treat_pick_group_member_hearts_as must accept {slot} from openStageSlotPick.
 */
final class JoushouKiryuuLiveStartTest extends TestCase
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
        $this->fail('Missing test card ' . $cardNo);
    }

    private function basePlayers(): array
    {
        return [
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
        ];
    }

    public function testTreatPickAcceptsSlotPayloadWithoutHanging(): void
    {
        $live = $this->cardByNo('PL!HS-bp5-021-L', 'joushou');
        $a = $this->cardByNo('PL!HS-bp5-003-R＋', 'hs_a');
        $b = $this->cardByNo('PL!HS-pb1-003-P＋', 'hs_b');

        $state = [
            'status' => 'playing',
            'phase' => 'live_start_effects',
            'seq' => 1,
            'turn' => 2,
            'first_player' => 'p1',
            'active_player' => 'p1',
            'live_attempt' => ['p1'],
            'log' => [],
            'players' => $this->basePlayers(),
        ];
        $state['players']['p1']['live_zone'] = [$live];
        $state['players']['p1']['stage']['center'] = $a;
        $state['players']['p1']['stage']['left'] = $b;

        $state = \resolveLiveStartAbilities($state, 'p1');
        $this->assertSame('treat_pick_group_member_hearts_as', $state['pending_prompt']['type'] ?? null);
        $this->assertSame('p1', $state['_live_start_resume_from'] ?? null);

        // Client openStageSlotPick sends {slot}, not {choice} — previously threw and softlocked.
        $state = \actionResolvePrompt($state, 'p1', ['slot' => 'center']);
        $this->assertNotSame(
            'treat_pick_group_member_hearts_as',
            $state['pending_prompt']['type'] ?? null,
            'Prompt must clear after Stage pick'
        );
        $this->assertSame('pink', $state['players']['p1']['stage']['center']['hearts_treat_as'] ?? null);
    }

    public function testChoiceSlotFailsPreviouslyWouldThrow(): void
    {
        $live = $this->cardByNo('PL!HS-bp5-021-L', 'joushou2');
        $a = $this->cardByNo('PL!HS-bp5-003-R＋', 'hs_a2');
        $b = $this->cardByNo('PL!HS-pb1-003-P＋', 'hs_b2');

        $state = [
            'status' => 'playing',
            'phase' => 'live_start_effects',
            'seq' => 1,
            'turn' => 2,
            'first_player' => 'p1',
            'active_player' => 'p1',
            'live_attempt' => ['p1'],
            'log' => [],
            'players' => $this->basePlayers(),
            'pending_prompt' => [
                'type' => 'treat_pick_group_member_hearts_as',
                'owner' => 'p1',
                'responder' => 'p1',
                'source_name' => 'Joushou Kiryuu',
                'color' => 'pink',
                'candidates' => [
                    ['slot' => 'center', 'instance_id' => 'hs_a2'],
                    ['slot' => 'left', 'instance_id' => 'hs_b2'],
                ],
            ],
            '_live_start_resume_from' => 'p1',
        ];
        $state['players']['p1']['live_zone'] = [$live];
        $state['players']['p1']['stage']['center'] = $a;
        $state['players']['p1']['stage']['left'] = $b;

        // Empty choice with slot set must succeed (the hang reproduction).
        $state = \actionResolvePrompt($state, 'p1', ['slot' => 'left']);
        $this->assertSame('pink', $state['players']['p1']['stage']['left']['hearts_treat_as'] ?? null);
    }

    public function testMiraCraParkScoreUsesMemberCountNotDistinctOnly(): void
    {
        $live = $this->cardByNo('PL!HS-bp5-021-L', 'joushou3');
        // Three Mira-Cra Park! Members (full stage) — score +1.
        $m1 = $this->cardByNo('PL!HS-pb1-003-P＋', 'mcp1');
        $m2 = $this->cardByNo('PL!HS-bp5-003-R＋', 'mcp2');
        $m3 = $this->cardByNo('PL!HS-bp5-003-SEC', 'mcp3');

        $state = [
            'status' => 'playing',
            'phase' => 'live_start_effects',
            'seq' => 1,
            'turn' => 2,
            'first_player' => 'p1',
            'active_player' => 'p1',
            'live_attempt' => ['p1'],
            'log' => [],
            'players' => $this->basePlayers(),
        ];
        $state['players']['p1']['live_zone'] = [$live];
        $state['players']['p1']['stage']['center'] = $m1;
        $state['players']['p1']['stage']['left'] = $m2;
        $state['players']['p1']['stage']['right'] = $m3;

        // One Member → auto-apply treat; then score_if should bump.
        $state = \resolveLiveStartAbilities($state, 'p1');
        // Three Hasunosora → treat pick opens first.
        if (($state['pending_prompt']['type'] ?? '') === 'treat_pick_group_member_hearts_as') {
            $state = \actionResolvePrompt($state, 'p1', ['slot' => 'center']);
        }
        $scored = intval($state['players']['p1']['live_zone'][0]['score'] ?? 0);
        // Printed 4 + Mira-Cra Park! ×3 bonus +1.
        $this->assertSame(5, $scored);
    }
}
