<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * PL!SP-pb2-003 Chisato: Live Success +1 total if this Member moved via a Liella effect.
 * Special Color (bp4-025) +1 card score if current Center Liella moved this turn.
 */
final class ChisatoPb2003MovedByLiellaTest extends TestCase
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

    private function baseState(): array
    {
        return [
            'status' => 'playing',
            'phase' => 'main_first',
            'seq' => 1,
            'turn' => 2,
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

    public function testLiellaOnEnterSwapMarksMovedByGroupEffect(): void
    {
        $ren = $this->cardByNo('PL!SP-sd2-005-SD2', 'ren_move');
        $chisato = $this->cardByNo('PL!SP-pb2-003-PP', 'chi_1');
        $state = $this->baseState();
        $state['players']['p1']['stage']['center'] = $ren;
        $state['players']['p1']['stage']['left'] = $chisato;

        $state = \resolveOnEnterAbilities($state, 'p1', $ren, 'center');
        $state = \actionResolvePrompt($state, 'p1', ['choice' => 'left']);

        $center = $state['players']['p1']['stage']['center'];
        $left = $state['players']['p1']['stage']['left'];
        $this->assertSame('PL!SP-pb2-003-PP', $center['card_no'] ?? null);
        $this->assertSame('Superstar', $center['moved_by_group_effect'] ?? '');
        $this->assertSame('Superstar', $left['moved_by_group_effect'] ?? '');
    }

    public function testBothLiveSuccessBonusesAfterLiellaSwap(): void
    {
        $ren = $this->cardByNo('PL!SP-sd2-005-SD2', 'ren_move');
        $chisato = $this->cardByNo('PL!SP-pb2-003-PP', 'chi_1');
        $live = $this->cardByNo('PL!SP-bp4-025-SRL', 'sc_1');
        $state = $this->baseState();
        $state['players']['p1']['stage']['center'] = $ren;
        $state['players']['p1']['stage']['left'] = $chisato;
        $state['players']['p1']['live_zone'] = [$live];

        $state = \resolveOnEnterAbilities($state, 'p1', $ren, 'center');
        $state = \actionResolvePrompt($state, 'p1', ['choice' => 'left']);

        $this->assertSame('PL!SP-pb2-003-PP', $state['players']['p1']['stage']['center']['card_no'] ?? null);
        $liveNow = $state['players']['p1']['live_zone'][0];
        $state = \resolveLiveSuccessAbilities($state, 'p1', [$liveNow], 0, [], []);
        $this->assertSame('live_success_order_sources', $state['pending_prompt']['type'] ?? null);
        $state = \actionResolvePrompt($state, 'p1', [
            'card_ids' => ['sc_1', 'chi_1'],
        ]);

        $this->assertSame(3, intval($state['players']['p1']['live_zone'][0]['score'] ?? 0));
        $this->assertSame(1, intval($state['live_modifiers']['p1']['score_bonus'] ?? 0));
        $this->assertSame(4, \getLiveTotalScore($state, 'p1'));
    }
}
