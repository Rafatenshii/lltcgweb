<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/** Maki Nishikino PL!-bp6-006 activated: discard → color → reveal 5 threshold. */
final class MakiBp6006Reveal5Test extends TestCase
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

    private function memberWithHeart(string $id, string $color, string $group = "μ's"): array
    {
        return [
            'instance_id' => $id,
            'card_no' => 'stub-' . $id,
            'name_en' => 'Stub ' . $id,
            'card_type' => 'メンバー',
            'group' => $group,
            'cost' => 3,
            'blade' => 1,
            'hearts' => [['color' => $color, 'count' => 1]],
            'abilities' => [],
        ];
    }

    private function liveRequiring(string $id, string $color, string $group = "μ's"): array
    {
        return [
            'instance_id' => $id,
            'card_no' => 'live-' . $id,
            'name_en' => 'Live ' . $id,
            'card_type' => 'ライブ',
            'group' => $group,
            'score' => 1,
            'required_hearts' => [['color' => $color, 'count' => 1]],
            'abilities' => [],
        ];
    }

    private function baseState(array $maki, array $deck, array $hand): array
    {
        return [
            'status' => 'playing',
            'phase' => 'main_first',
            'seq' => 1,
            'turn' => 2,
            'first_player' => 'p1',
            'active_player' => 'p1',
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => $hand,
                    'waiting_room' => [],
                    'stage' => [
                        'left' => null,
                        'center' => $maki,
                        'right' => null,
                    ],
                    'energy_zone' => [],
                    'energy_deck' => [],
                    'main_deck' => $deck,
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

    public function testAbilityTypeIsRegistered(): void
    {
        $maki = $this->cardByNo('PL!-bp6-006-R＋', 'maki');
        $this->assertSame('mandatory_discard_color_threshold_reveal5', $maki['abilities'][0]['type'] ?? null);
        $this->assertTrue(\plMuseGapIsEffectType('mandatory_discard_color_threshold_reveal5'));
    }

    public function testActivateOpensDiscardThenColorThenGrantsOnThreshold(): void
    {
        $maki = $this->cardByNo('PL!-bp6-006-R＋', 'maki_r');
        $hand = [
            $this->memberWithHeart('h0', 'red'),
            $this->memberWithHeart('h1', 'blue'),
        ];
        $deck = [
            $this->memberWithHeart('d0', 'pink'),
            $this->memberWithHeart('d1', 'pink'),
            $this->liveRequiring('d2', 'pink'),
            $this->memberWithHeart('d3', 'pink', 'Sunshine'),
            $this->liveRequiring('d4', 'pink'),
            $this->memberWithHeart('d5', 'blue'),
        ];
        $state = $this->baseState($maki, $deck, $hand);

        $state = \actionActivateAbility($state, 'p1', [
            'card_id' => 'maki_r',
            'ability_index' => 0,
        ]);
        $this->assertSame('mandatory_discard_color_threshold_reveal5', $state['pending_prompt']['type'] ?? null);

        $state = \actionResolvePrompt($state, 'p1', ['discard_ids' => ['h0']]);
        $this->assertSame('maki_reveal5_choose_color', $state['pending_prompt']['type'] ?? null);
        $this->assertCount(1, $state['players']['p1']['hand']);

        $state = \actionResolvePrompt($state, 'p1', ['choice' => 'pink']);
        // Auto-picks sole μ's among revealed when only one μ's? We have d0,d1 (μ's members) + d2,d4 (μ's lives) + d3 Sunshine = multiple μ's
        $this->assertSame('maki_reveal5_pick_mus', $state['pending_prompt']['type'] ?? null);

        $state = \actionResolvePrompt($state, 'p1', ['card_id' => 'd0']);
        $this->assertEmpty($state['pending_prompt'] ?? null);
        $handIds = array_column($state['players']['p1']['hand'], 'instance_id');
        $this->assertContains('d0', $handIds);
        $this->assertSame(3, intval($state['players']['p1']['stage']['center']['live_blade_bonus'] ?? 0));
        $this->assertCount(5, $state['players']['p1']['waiting_room']); // h0 + 4 non-picked revealed
    }

    public function testThresholdFailMillsAll(): void
    {
        $maki = $this->cardByNo('PL!-bp6-006-R＋', 'maki_fail');
        $hand = [$this->memberWithHeart('h0', 'red')];
        $deck = [
            $this->memberWithHeart('d0', 'pink'),
            $this->memberWithHeart('d1', 'blue'), // breaks pink threshold
            $this->memberWithHeart('d2', 'pink'),
            $this->memberWithHeart('d3', 'pink'),
            $this->memberWithHeart('d4', 'pink'),
        ];
        $state = $this->baseState($maki, $deck, $hand);
        $state = \actionActivateAbility($state, 'p1', [
            'card_id' => 'maki_fail',
            'ability_index' => 0,
            'discard_ids' => ['h0'],
        ]);
        $this->assertSame('maki_reveal5_choose_color', $state['pending_prompt']['type'] ?? null);
        $state = \actionResolvePrompt($state, 'p1', ['choice' => 'pink']);
        $this->assertEmpty($state['pending_prompt'] ?? null);
        $this->assertSame(0, intval($state['players']['p1']['stage']['center']['live_blade_bonus'] ?? 0));
        $this->assertCount(6, $state['players']['p1']['waiting_room']); // discard + 5 revealed
        $this->assertCount(0, $state['players']['p1']['hand']);
    }
}
