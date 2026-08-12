<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * Issue #108 — Chance Day, Chance Way! formation-change must let the player
 * choose each Member's destination (not auto Left ↔ Right).
 */
final class Issue108ChanceDayFormationChooseTest extends TestCase
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

    private function stubLiella(string $instanceId, string $name): array
    {
        return [
            'instance_id' => $instanceId,
            'card_no' => 'PL!SP-sd1-001-SD',
            'name' => $name,
            'name_en' => $name,
            'card_type' => 'メンバー',
            'group' => 'Superstar',
            'subunit' => 'CatChu!',
            'active' => true,
            'cost' => 3,
            'blade' => 1,
            'hearts' => [['color' => 'red', 'count' => 1]],
        ];
    }

    private function liveSuccessState(array $stage): array
    {
        $live = $this->cardByNo('PL!SP-bp4-027-L', 'chance_day');
        return [
            'status' => 'playing',
            'phase' => 'live_success_effects',
            'seq' => 8,
            'turn' => 3,
            'first_player' => 'p1',
            'active_player' => 'p1',
            'log' => [],
            'live_modifiers' => [
                'p1' => ['blade_bonus' => 0, 'bonus_hearts' => []],
                'p2' => ['blade_bonus' => 0, 'bonus_hearts' => []],
            ],
            'pending_prompt' => [
                'type' => 'optional_formation_change_group',
                'owner' => 'p1',
                'responder' => 'p1',
                'source_id' => 'chance_day',
                'source_name' => $live['name_en'],
                'prompt' => 'Formation-change your Stage Members (one per area)?',
                'choices' => ['yes', 'no'],
                'ability' => $live['abilities'][0],
            ],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [],
                    'waiting_room' => [],
                    'stage' => $stage,
                    'energy_zone' => [],
                    'main_deck' => [],
                    'success_lives' => [$live],
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

    public function testYesOpensAssignStepInsteadOfAutoSwap(): void
    {
        $state = $this->liveSuccessState([
            'left' => $this->stubLiella('m_left', 'Left'),
            'center' => $this->stubLiella('m_center', 'Center'),
            'right' => $this->stubLiella('m_right', 'Right'),
        ]);

        $state = \actionResolvePrompt($state, 'p1', ['choice' => 'yes']);
        $this->assertSame('assign', $state['pending_prompt']['step'] ?? null);
        $this->assertSame('m_left', $state['pending_prompt']['current_member_id'] ?? null);
        $this->assertSame(
            ['left', 'center', 'right'],
            $state['pending_prompt']['target_slots'] ?? null
        );
        // Stage unchanged until assignment finishes.
        $this->assertSame('m_left', $state['players']['p1']['stage']['left']['instance_id'] ?? null);
        $this->assertSame('m_center', $state['players']['p1']['stage']['center']['instance_id'] ?? null);
        $this->assertSame('m_right', $state['players']['p1']['stage']['right']['instance_id'] ?? null);
    }

    public function testPlayerCanRotateFormationViaAssignPicks(): void
    {
        $state = $this->liveSuccessState([
            'left' => $this->stubLiella('m_left', 'Left'),
            'center' => $this->stubLiella('m_center', 'Center'),
            'right' => $this->stubLiella('m_right', 'Right'),
        ]);

        $state = \actionResolvePrompt($state, 'p1', ['choice' => 'yes']);
        // Place original left → center
        $state = \actionResolvePrompt($state, 'p1', ['slot' => 'center']);
        $this->assertSame('assign', $state['pending_prompt']['step'] ?? null);
        $this->assertSame('m_center', $state['pending_prompt']['current_member_id'] ?? null);
        $this->assertSame(['left', 'right'], $state['pending_prompt']['target_slots'] ?? null);

        // Place original center → right; original right auto-fills left.
        $state = \actionResolvePrompt($state, 'p1', ['slot' => 'right']);
        $this->assertEmpty($state['pending_prompt'] ?? null);
        $this->assertSame('m_right', $state['players']['p1']['stage']['left']['instance_id'] ?? null);
        $this->assertSame('m_left', $state['players']['p1']['stage']['center']['instance_id'] ?? null);
        $this->assertSame('m_center', $state['players']['p1']['stage']['right']['instance_id'] ?? null);
    }

    public function testLiveSuccessOpensFormationPromptForChanceDay(): void
    {
        $live = $this->cardByNo('PL!SP-bp4-027-L', 'chance_day');
        $state = [
            'status' => 'playing',
            'phase' => 'live_success_effects',
            'seq' => 1,
            'turn' => 3,
            'first_player' => 'p1',
            'active_player' => 'p1',
            'log' => [],
            'live_attempt' => ['p1'],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [],
                    'waiting_room' => [],
                    'stage' => [
                        'left' => $this->stubLiella('a', 'A'),
                        'center' => $this->stubLiella('b', 'B'),
                        'right' => $this->stubLiella('c', 'C'),
                    ],
                    'energy_zone' => [],
                    'main_deck' => [],
                    'live_zone' => [$live],
                    'success_lives' => [],
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'P2',
                    'hand' => [],
                    'waiting_room' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'energy_zone' => [],
                    'main_deck' => [],
                    'live_zone' => [],
                    'success_lives' => [],
                ],
            ],
        ];

        $state = \resolveLiveSuccessAbilities($state, 'p1', [$live], 0, [], []);
        $this->assertSame('optional_formation_change_group', $state['pending_prompt']['type'] ?? null);
    }
}
