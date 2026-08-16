<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * PL!SP-bp5-007 Mei Yoneme — optional discard then look 5 / pick up to 3 distinct groups.
 */
final class MeiBp5007DistinctGroupsLookTest extends TestCase
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

    private function memberStub(string $id, string $group): array
    {
        return [
            'instance_id' => $id,
            'card_type' => 'メンバー',
            'card_type_en' => 'Member',
            'name' => $id,
            'name_en' => $id,
            'group' => $group,
            'cost' => 3,
            'blade' => 1,
            'hearts' => [],
        ];
    }

    private function energyStub(string $id): array
    {
        return [
            'instance_id' => $id,
            'card_type' => 'エネルギー',
            'card_type_en' => 'Energy',
            'name' => 'Energy',
            'name_en' => 'Energy',
            'group' => '',
        ];
    }

    private function baseState(): array
    {
        $mei = $this->cardByNo('PL!SP-bp5-007-R', 'mei_center');
        return [
            'status' => 'playing',
            'phase' => 'main_first',
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
                    'hand' => [$this->memberStub('hand_disc', 'Superstar')],
                    'waiting_room' => [],
                    'stage' => [
                        'left' => null,
                        'center' => $mei,
                        'right' => null,
                    ],
                    'energy_zone' => [],
                    'main_deck' => [
                        $this->memberStub('d1', 'Superstar'),
                        $this->memberStub('d2', 'Sunshine'),
                        $this->memberStub('d3', 'Superstar'),
                        $this->energyStub('d4'),
                        $this->memberStub('d5', 'Nijigasaki'),
                        $this->memberStub('d6', 'Hasunosora'),
                    ],
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

    public function testDiscardYesOpensDistinctGroupDeckLook(): void
    {
        $state = $this->baseState();
        $mei = $state['players']['p1']['stage']['center'];
        $state = \resolveOnEnterAbilities($state, 'p1', $mei, 'center');
        $this->assertSame('optional_discard_prompt', $state['pending_prompt']['type'] ?? null);

        $state = \resolveOptionalDiscardPromptChoice(
            $state,
            'p1',
            $state['pending_prompt'],
            'yes',
            ['discard_ids' => ['hand_disc']]
        );

        $this->assertSame('pick_looked_deck_hand', $state['pending_prompt']['type'] ?? null);
        $this->assertTrue(!empty($state['pending_prompt']['ability']['distinct_groups']));
        $this->assertSame(3, intval($state['pending_prompt']['pick_count'] ?? 0));
        $this->assertCount(5, $state['pending_prompt']['candidates'] ?? []);
        $eligible = $state['pending_prompt']['eligible_ids'] ?? [];
        $this->assertContains('d1', $eligible);
        $this->assertContains('d2', $eligible);
        $this->assertContains('d5', $eligible);
        $this->assertNotContains('d4', $eligible, 'Energy without group is not eligible');
        $this->assertCount(0, $state['players']['p1']['hand']);
        $wrIds = array_map(fn($c) => $c['instance_id'] ?? '', $state['players']['p1']['waiting_room']);
        $this->assertContains('hand_disc', $wrIds);
    }

    public function testDistinctGroupPicksAddToHandAndRestToWr(): void
    {
        $state = $this->baseState();
        $mei = $state['players']['p1']['stage']['center'];
        $state = \resolveOnEnterAbilities($state, 'p1', $mei, 'center');
        $state = \resolveOptionalDiscardPromptChoice(
            $state,
            'p1',
            $state['pending_prompt'],
            'yes',
            ['discard_ids' => ['hand_disc']]
        );

        $state = \actionResolvePrompt($state, 'p1', [
            'choice' => 'yes',
            'card_ids' => ['d1', 'd2', 'd5'],
        ]);

        $handIds = array_map(fn($c) => $c['instance_id'] ?? '', $state['players']['p1']['hand']);
        sort($handIds);
        $this->assertSame(['d1', 'd2', 'd5'], $handIds);
        $wrIds = array_map(fn($c) => $c['instance_id'] ?? '', $state['players']['p1']['waiting_room']);
        $this->assertContains('d3', $wrIds);
        $this->assertContains('d4', $wrIds);
        $this->assertNull($state['pending_prompt'] ?? null);
    }

    public function testRejectsTwoCardsFromSameGroup(): void
    {
        $state = $this->baseState();
        $mei = $state['players']['p1']['stage']['center'];
        $state = \resolveOnEnterAbilities($state, 'p1', $mei, 'center');
        $state = \resolveOptionalDiscardPromptChoice(
            $state,
            'p1',
            $state['pending_prompt'],
            'yes',
            ['discard_ids' => ['hand_disc']]
        );

        $this->expectException(\Exception::class);
        \actionResolvePrompt($state, 'p1', [
            'choice' => 'yes',
            'card_ids' => ['d1', 'd3'],
        ]);
    }
}
