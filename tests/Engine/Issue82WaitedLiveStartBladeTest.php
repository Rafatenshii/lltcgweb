<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * Regression for GitHub issue #82 —
 * Live Start blade grants from a Waited Member must not count toward Yell.
 * Natsumi PL!SP-bp2-009-P used player-wide blade_bonus, which bypassed Wait.
 */
final class Issue82WaitedLiveStartBladeTest extends TestCase
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

    private function handCards(int $count, string $prefix): array
    {
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $out[] = [
                'instance_id' => $prefix . $i,
                'card_type' => 'エネルギー',
            ];
        }
        return $out;
    }

    private function baseState(): array
    {
        return [
            'status' => 'playing',
            'phase' => 'live_start_effects',
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
                    'main_deck' => array_fill(0, 10, ['instance_id' => 'deck', 'card_type' => 'エネルギー']),
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

    public function testWaitedNatsumiLiveStartBladeExcludedFromYell(): void
    {
        $natsumi = $this->cardByNo('PL!SP-bp2-009-P', 'issue82_natsumi');
        $printed = intval($natsumi['blade'] ?? 0);

        $state = $this->baseState();
        $state['players']['p1']['stage']['center'] = $natsumi;
        $state['players']['p1']['hand'] = $this->handCards(12, 'issue82_hand');
        \waitMember($state['players']['p1']['stage']['center'], $state);

        $state = \resolveLiveStartAbilities($state, 'p1');

        // Skill still resolves (+6 from 12/2), but on the Waited Member.
        $this->assertSame(6, intval($state['players']['p1']['stage']['center']['live_blade_bonus'] ?? 0));
        $this->assertSame(0, \getStageBladeBonus($state, 'p1'));
        $this->assertSame(
            0,
            \computeYellBladeTotal($state, 'p1'),
            'Waited Natsumi printed + Live Start blades must not count toward Yell'
        );
        $this->assertSame($printed + 6, \getMemberBlade(
            $state['players']['p1']['stage']['center'],
            $state,
            'p1',
            'center'
        ));
    }

    public function testTwoWaitedNatsumisDoNotStackPlayerWideBladeBonus(): void
    {
        $n1 = $this->cardByNo('PL!SP-bp2-009-P', 'issue82_n1');
        $n2 = $this->cardByNo('PL!SP-bp2-009-P', 'issue82_n2');

        $state = $this->baseState();
        $state['players']['p1']['stage']['left'] = $n1;
        $state['players']['p1']['stage']['right'] = $n2;
        $state['players']['p1']['hand'] = $this->handCards(12, 'issue82_hand2');
        \waitMember($state['players']['p1']['stage']['left'], $state);
        \waitMember($state['players']['p1']['stage']['right'], $state);

        $state = \resolveLiveStartAbilities($state, 'p1');

        $this->assertSame(6, intval($state['players']['p1']['stage']['left']['live_blade_bonus'] ?? 0));
        $this->assertSame(6, intval($state['players']['p1']['stage']['right']['live_blade_bonus'] ?? 0));
        $this->assertSame(0, \getStageBladeBonus($state, 'p1'));
        $this->assertSame(0, \computeYellBladeTotal($state, 'p1'));
    }

    public function testActiveNatsumiLiveStartStillCountsTowardYell(): void
    {
        $natsumi = $this->cardByNo('PL!SP-bp2-009-P', 'issue82_active');
        $printed = intval($natsumi['blade'] ?? 0);

        $state = $this->baseState();
        $state['players']['p1']['stage']['center'] = $natsumi;
        $state['players']['p1']['hand'] = $this->handCards(4, 'issue82_ah');

        $state = \resolveLiveStartAbilities($state, 'p1');

        $this->assertSame(2, intval($state['players']['p1']['stage']['center']['live_blade_bonus'] ?? 0));
        $this->assertSame($printed + 2, \computeYellBladeTotal($state, 'p1'));
    }
}
