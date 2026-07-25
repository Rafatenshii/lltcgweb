<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * PL!N-bp4-011 Mia Taylor — Live Start discard must be a Live card (filter=live).
 * Replay BAA67571 softlock: CPU could discard a Member, then client poll-hold
 * prevented chained choose_heart_modifier from surfacing.
 */
final class MiaTaylorBp4011LiveStartFilterTest extends TestCase
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

    private function baseLiveStartState(array $mia, array $hand): array
    {
        return [
            'status' => 'playing',
            'phase' => 'live_start_effects',
            'seq' => 1,
            'turn' => 3,
            'first_player' => 'p1',
            'active_player' => 'p1',
            'live_attempt' => ['p2'],
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
                    'name' => 'CPU (Expert)',
                    'hand' => $hand,
                    'waiting_room' => [],
                    'stage' => ['left' => null, 'center' => $mia, 'right' => null],
                    'energy_zone' => [],
                    'main_deck' => [['instance_id' => 'deck1', 'card_type' => 'メンバー']],
                    'success_lives' => [],
                    'live_zone' => [[
                        'instance_id' => 'live_zone_1',
                        'card_type' => 'ライブ',
                        'card_type_en' => 'Live',
                        'name_en' => 'Blue!',
                        'group' => 'Nijigasaki',
                        'face_up' => true,
                    ]],
                ],
            ],
        ];
    }

    public function testRejectsMemberDiscardForLiveFilter(): void
    {
        $mia = $this->cardByNo('PL!N-bp4-011-P', 'mia_src');
        $member = [
            'instance_id' => 'hand_member',
            'card_type' => 'メンバー',
            'card_type_en' => 'Member',
            'name_en' => 'Karin Asaka',
            'group' => 'Nijigasaki',
        ];
        $live = [
            'instance_id' => 'hand_live',
            'card_type' => 'ライブ',
            'card_type_en' => 'Live',
            'name_en' => 'MONSTER GIRLS',
            'group' => 'Nijigasaki',
        ];
        $state = $this->baseLiveStartState($mia, [$member, $live]);
        $state = \beginLiveStartEffectPhase($state, false, true);

        $this->assertSame('optional_live_start', $state['pending_prompt']['type'] ?? null);
        $this->assertSame('live', $state['pending_prompt']['ability']['filter'] ?? null);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Must discard a Live card');
        \actionResolvePrompt($state, 'p2', [
            'choice' => 'yes',
            'discard_ids' => ['hand_member'],
        ]);
    }

    public function testAcceptsLiveDiscardThenHeartChoice(): void
    {
        $mia = $this->cardByNo('PL!N-bp4-011-P', 'mia_src2');
        $member = [
            'instance_id' => 'hand_member2',
            'card_type' => 'メンバー',
            'name_en' => 'Karin Asaka',
            'group' => 'Nijigasaki',
        ];
        $live = [
            'instance_id' => 'hand_live2',
            'card_type' => 'ライブ',
            'card_type_en' => 'Live',
            'name_en' => 'MONSTER GIRLS',
            'group' => 'Nijigasaki',
        ];
        $state = $this->baseLiveStartState($mia, [$member, $live]);
        $state = \beginLiveStartEffectPhase($state, false, true);
        $state = \actionResolvePrompt($state, 'p2', [
            'choice' => 'yes',
            'discard_ids' => ['hand_live2'],
        ]);

        $this->assertSame('choose_heart_modifier', $state['pending_prompt']['type'] ?? null);
        $this->assertSame('p2', $state['pending_prompt']['responder'] ?? null);
        $this->assertContains('hand_live2', array_column($state['players']['p2']['waiting_room'], 'instance_id'));

        $state = \actionResolvePrompt($state, 'p2', ['choice' => 'pink']);
        $this->assertNull($state['pending_prompt'] ?? null);
    }

    public function testSkipsPromptWhenNoLiveInHand(): void
    {
        $mia = $this->cardByNo('PL!N-bp4-011-P', 'mia_src3');
        $member = [
            'instance_id' => 'hand_only_member',
            'card_type' => 'メンバー',
            'name_en' => 'Karin Asaka',
            'group' => 'Nijigasaki',
        ];
        $state = $this->baseLiveStartState($mia, [$member]);
        $state = \beginLiveStartEffectPhase($state, false, true);
        $this->assertNull($state['pending_prompt'] ?? null);
    }
}
