<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * Issue #100: Chance Day, Chance Way! formation-change must trigger
 * PL!SP-bp5-004 Sumire's [Auto] area-move draw + red heart.
 */
final class Issue100SumireFormationDrawTest extends TestCase
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

    private function stubLiella(string $instanceId, string $slotName): array
    {
        return [
            'instance_id' => $instanceId,
            'card_no' => 'PL!SP-sd1-001-SD',
            'name' => $slotName,
            'name_en' => $slotName,
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
                    'main_deck' => [
                        ['instance_id' => 'draw_a', 'card_type' => 'メンバー', 'name_en' => 'Draw A'],
                        ['instance_id' => 'draw_b', 'card_type' => 'メンバー', 'name_en' => 'Draw B'],
                    ],
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

    public function testAssignmentMoveDrawsAndGrantsRedHeart(): void
    {
        $sumire = $this->cardByNo('PL!SP-bp5-004-R＋', 'sumire_bp5');
        $k = $this->stubLiella('kanan_l', 'Kanon');
        $c = $this->stubLiella('chisato_c', 'Chisato');
        $state = $this->liveSuccessState([
            'left' => $sumire,
            'center' => $c,
            'right' => $k,
        ]);

        $state = \actionResolvePrompt($state, 'p1', [
            'choice' => 'yes',
            'assignments' => [
                'left' => 'chisato_c',
                'center' => 'sumire_bp5',
                'right' => 'kanan_l',
            ],
        ]);

        $this->assertSame('sumire_bp5', $state['players']['p1']['stage']['center']['instance_id'] ?? null);
        $log = $this->logText($state);
        $this->assertStringContainsString('[Sumire Heanna] drew 1 and gained 1 red heart(s) (area move).', $log);
    }

    public function testInteractiveAssignAlsoTriggersSumire(): void
    {
        $sumire = $this->cardByNo('PL!SP-bp5-004-P', 'sumire_left');
        $k = $this->stubLiella('kanan_r', 'Kanon');
        $state = $this->liveSuccessState([
            'left' => $sumire,
            'center' => null,
            'right' => $k,
        ]);

        // Issue #108: Yes opens per-Member area picks (no auto Left ↔ Right).
        $state = \actionResolvePrompt($state, 'p1', ['choice' => 'yes']);
        $this->assertSame('assign', $state['pending_prompt']['step'] ?? null);
        $state = \actionResolvePrompt($state, 'p1', ['slot' => 'right']); // Sumire → Right
        $this->assertSame('assign', $state['pending_prompt']['step'] ?? null);
        $state = \actionResolvePrompt($state, 'p1', ['slot' => 'left']); // Kanon → Left

        $this->assertSame('sumire_left', $state['players']['p1']['stage']['right']['instance_id'] ?? null);
        $this->assertSame('kanan_r', $state['players']['p1']['stage']['left']['instance_id'] ?? null);
        $this->assertNull($state['players']['p1']['stage']['center'] ?? null);
        $this->assertStringContainsString('[Sumire Heanna] drew 1 and gained 1 red heart(s) (area move).', $this->logText($state));
    }

    public function testSkipDoesNotDraw(): void
    {
        $sumire = $this->cardByNo('PL!SP-bp5-004-SEC', 'sumire_skip');
        $state = $this->liveSuccessState([
            'left' => $sumire,
            'center' => $this->stubLiella('m2', 'M2'),
            'right' => $this->stubLiella('m3', 'M3'),
        ]);

        $state = \actionResolvePrompt($state, 'p1', ['choice' => 'no']);
        $log = $this->logText($state);
        $this->assertStringContainsString('skipped formation change', $log);
        $this->assertStringNotContainsString('(area move)', $log);
    }

    private function logText(array $state): string
    {
        return implode("\n", array_map(
            static fn($e) => is_array($e) ? (string)($e['msg'] ?? '') : (string)$e,
            $state['log'] ?? []
        ));
    }
}
