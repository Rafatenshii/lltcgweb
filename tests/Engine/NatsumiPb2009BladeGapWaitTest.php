<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * Natsumi Onitsuka PL!SP-pb2-009 — Wait chain uses printed Blade, not hearts.
 * Replay AD86EF compared heart counts, so a 7-heart / 5-blade Kanon could not
 * Wait a 6-heart / 4-blade Kanon. Official text is 元々持つブレード.
 */
final class NatsumiPb2009BladeGapWaitTest extends TestCase
{
    private function member(string $id, string $name, int $blade, int $hearts): array
    {
        return [
            'instance_id' => $id,
            'name_en' => $name,
            'name' => $name,
            'card_type' => 'メンバー',
            'group' => 'Superstar',
            'blade' => $blade,
            'hearts' => [['color' => 'red', 'count' => $hearts]],
            'active' => true,
        ];
    }

    private function state(array $p1Stage, array $p2Stage, array $prompt): array
    {
        return [
            'seq' => 10,
            'turn' => 5,
            'phase' => 'main_first',
            'active_player' => 'p1',
            'log' => [],
            'players' => [
                'p1' => [
                    'name' => 'liro',
                    'stage' => array_merge(['left' => null, 'center' => null, 'right' => null], $p1Stage),
                    'waiting_room' => [],
                    'hand' => [],
                    'live_zone' => [],
                    'success_lives' => [],
                ],
                'p2' => [
                    'name' => 'Kyra',
                    'stage' => array_merge(['left' => null, 'center' => null, 'right' => null], $p2Stage),
                    'waiting_room' => [],
                    'hand' => [],
                    'live_zone' => [],
                    'success_lives' => [],
                ],
            ],
            'pending_prompt' => $prompt,
        ];
    }

    public function testWaitingSevenPrintedBladeCanWaitFourBladeDespiteHeartCounts(): void
    {
        $self7 = $this->member('self7', 'Shiki', 7, 2);
        $opp4 = $this->member('opp4', 'Kanon4', 4, 6);
        $state = $this->state(
            ['right' => $self7],
            ['left' => $opp4],
            [
                'type' => 'spbp2_wait_self_opp_heart_gap',
                'step' => 'pick_self',
                'owner' => 'p1',
                'responder' => 'p1',
                'source_name' => 'Natsumi Onitsuka',
                'blade_gap' => 2,
                'self_candidates' => [['slot' => 'right']],
            ]
        );

        $out = \spBp2ResolvePrompt($state, 'p1', $state['pending_prompt'], 'right', ['slot' => 'right']);
        $this->assertIsArray($out);
        $this->assertSame('pick_opp', $out['pending_prompt']['step'] ?? null);
        $slots = array_column($out['pending_prompt']['candidates'] ?? [], 'slot');
        $this->assertContains('left', $slots);
        $this->assertTrue($out['players']['p1']['stage']['right']['in_wait'] ?? false);
    }

    public function testFivePrintedBladeCannotWaitFourBlade(): void
    {
        $self5 = $this->member('self5', 'Kanon5', 5, 7);
        $opp4 = $this->member('opp4', 'Kanon4', 4, 6);
        $state = $this->state(
            ['right' => $self5],
            ['left' => $opp4],
            [
                'type' => 'spbp2_wait_self_opp_heart_gap',
                'step' => 'pick_self',
                'owner' => 'p1',
                'responder' => 'p1',
                'source_name' => 'Natsumi Onitsuka',
                'blade_gap' => 2,
                'self_candidates' => [['slot' => 'right']],
            ]
        );

        $out = \spBp2ResolvePrompt($state, 'p1', $state['pending_prompt'], 'right', ['slot' => 'right']);
        $this->assertIsArray($out);
        $this->assertArrayNotHasKey('pending_prompt', $out);
        $this->assertTrue($out['players']['p1']['stage']['right']['in_wait'] ?? false);
        $this->assertFalse($out['players']['p2']['stage']['left']['in_wait'] ?? false);
    }

    public function testCatalogUsesPrintedBladeGap(): void
    {
        $data = json_decode((string) file_get_contents((string) constant('CARDS_FILE')), true);
        $this->assertIsArray($data);
        $found = 0;
        foreach ($data['cards'] ?? [] as $card) {
            if (!in_array($card['card_no'] ?? '', ['PL!SP-pb2-009-PP', 'PL!SP-pb2-009-R'], true)) {
                continue;
            }
            $found++;
            $ab = $card['abilities'][0] ?? [];
            $this->assertSame('optional_wait_self_opp_heart_gap', $ab['type'] ?? '');
            $this->assertSame(2, intval($ab['blade_gap'] ?? 0));
            $this->assertArrayNotHasKey('heart_gap', $ab);
        }
        $this->assertSame(2, $found);
    }
}
