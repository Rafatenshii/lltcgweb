<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * PL!S-pb1-006-R Yoshiko — Reveal Live from hand: opp may discard or grant +4 Blade.
 * Regression: client used to overwrite card_id with the revealed Live, causing Invalid ability.
 */
final class YoshikoPb1006RevealLiveActivateTest extends TestCase
{
    private function cardByNo(string $cardNo, string $instanceId): array
    {
        $data = json_decode((string) file_get_contents((string) constant('CARDS_FILE')), true);
        foreach ($data['cards'] ?? [] as $card) {
            if (($card['card_no'] ?? '') === $cardNo) {
                $card['instance_id'] = $instanceId;
                $card['active'] = true;
                $card['entered_turn'] = 1;
                return $card;
            }
        }
        $this->fail('Missing card ' . $cardNo);
    }

    private function liveStub(string $id): array
    {
        return [
            'instance_id' => $id,
            'card_no' => 'LIVE-' . $id,
            'card_type' => 'ライブ',
            'card_type_en' => 'Live',
            'name_en' => 'Test Live',
            'name' => 'Test Live',
            'score' => 1,
            'required_hearts' => [],
        ];
    }

    private function baseState(): array
    {
        $yoshiko = $this->cardByNo('PL!S-pb1-006-R', 'yoshiko');
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
                    'hand' => [$this->liveStub('live1')],
                    'waiting_room' => [],
                    'main_deck' => [],
                    'energy_zone' => [],
                    'energy_deck' => [],
                    'stage' => ['left' => null, 'center' => $yoshiko, 'right' => null],
                    'live_zone' => [],
                    'success_lives' => [],
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'P2',
                    'hand' => [
                        [
                            'instance_id' => 'p2h1',
                            'card_type' => 'エネルギー',
                            'card_type_en' => 'Energy',
                            'name_en' => 'Energy',
                        ],
                    ],
                    'waiting_room' => [],
                    'main_deck' => [],
                    'energy_zone' => [],
                    'energy_deck' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'live_zone' => [],
                    'success_lives' => [],
                ],
            ],
        ];
    }

    public function testActivateWithRevealCardIdOpensOppPrompt(): void
    {
        $state = $this->baseState();
        $state = \actionActivateAbility($state, 'p1', [
            'card_id' => 'yoshiko',
            'ability_index' => 0,
            'reveal_card_id' => 'live1',
        ]);
        $pr = $state['pending_prompt'] ?? null;
        $this->assertIsArray($pr);
        $this->assertSame('reveal_live_opp_discard_or_blade', $pr['type']);
        $this->assertSame('p2', $pr['responder']);
        $this->assertSame('yoshiko', $pr['source_id']);
        $this->assertSame('live1', $pr['revealed']['instance_id'] ?? null);
    }

    public function testLegacyOverwriteCardIdWithLiveThrowsInvalidAbility(): void
    {
        $state = $this->baseState();
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid ability');
        \actionActivateAbility($state, 'p1', [
            // Old client bug: card_id became the revealed Live instance.
            'card_id' => 'live1',
            'ability_index' => 0,
        ]);
    }

    public function testOppDeclineGrantsBlade(): void
    {
        $state = $this->baseState();
        $state = \actionActivateAbility($state, 'p1', [
            'card_id' => 'yoshiko',
            'ability_index' => 0,
            'reveal_card_id' => 'live1',
        ]);
        $state = \actionResolvePrompt($state, 'p2', ['choice' => 'no']);
        $blade = intval($state['players']['p1']['stage']['center']['live_blade_bonus'] ?? 0);
        $this->assertSame(4, $blade);
        $this->assertNull($state['pending_prompt'] ?? null);
    }
}
