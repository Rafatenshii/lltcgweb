<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/** Player-chosen activation order when multiple Live Success skills fire. */
final class LiveSuccessOrderPickTest extends TestCase
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

    private function handFill(int $n, string $prefix = 'h'): array
    {
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = [
                'instance_id' => $prefix . $i,
                'card_type' => 'メンバー',
                'name_en' => 'Hand ' . $i,
                'cost' => 1,
            ];
        }
        return $out;
    }

    private function deckFill(int $n, string $prefix = 'd'): array
    {
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = [
                'instance_id' => $prefix . $i,
                'card_type' => 'メンバー',
                'name_en' => 'Deck ' . $i,
                'cost' => 1,
            ];
        }
        return $out;
    }

    private function baseState(array $successLives, ?array $natsumi = null): array
    {
        return [
            'status' => 'playing',
            'phase' => 'live_success_effects',
            'seq' => 1,
            'turn' => 3,
            'first_player' => 'p1',
            'active_player' => 'p1',
            '_performance_continue' => 'p1',
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => $this->handFill(2),
                    'waiting_room' => [],
                    'stage' => [
                        'left' => null,
                        'center' => $natsumi,
                        'right' => null,
                    ],
                    'energy_zone' => [],
                    'energy_deck' => [
                        ['instance_id' => 'en0', 'card_type' => 'エネルギー', 'active' => true],
                    ],
                    'main_deck' => $this->deckFill(10),
                    'success_lives' => [],
                    'live_zone' => $successLives,
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

    public function testOrderPromptOpensWhenMultipleSources(): void
    {
        $kimi = $this->cardByNo('PL!S-bp2-024-L', 'kimi1');
        $natsumi = $this->cardByNo('PL!SP-bp2-009-P', 'natsumi');
        $state = $this->baseState([$kimi], $natsumi);

        $state = \resolveLiveSuccessAbilities($state, 'p1', [$kimi], 0, [], []);
        $this->assertSame('live_success_order_sources', $state['pending_prompt']['type'] ?? null);
        $ids = array_column($state['pending_prompt']['candidates'] ?? [], 'instance_id');
        $this->assertSame(['kimi1', 'natsumi'], $ids);
    }

    public function testSingleSourceSkipsOrderPrompt(): void
    {
        $kimi = $this->cardByNo('PL!S-bp2-024-L', 'kimi_solo');
        $state = $this->baseState([$kimi], null);

        $state = \resolveLiveSuccessAbilities($state, 'p1', [$kimi], 0, [], []);
        $this->assertSame('effect_discard_hand', $state['pending_prompt']['type'] ?? null);
        $this->assertStringContainsString('Kokoro', (string)($state['pending_prompt']['source_name'] ?? 'Kokoro'));
    }

    public function testPlayerCanActivateMemberBeforeLive(): void
    {
        $kimi = $this->cardByNo('PL!S-bp2-024-L', 'kimi_late');
        $natsumi = $this->cardByNo('PL!SP-bp2-009-P', 'natsumi_first');
        $state = $this->baseState([$kimi], $natsumi);
        $state['players']['p1']['main_deck'] = $this->deckFill(20);

        $state = \resolveLiveSuccessAbilities($state, 'p1', [$kimi], 0, [], []);
        $this->assertSame('live_success_order_sources', $state['pending_prompt']['type'] ?? null);

        $state = \actionResolvePrompt($state, 'p1', [
            'card_ids' => ['natsumi_first', 'kimi_late'],
        ]);
        $this->assertSame('effect_discard_hand', $state['pending_prompt']['type'] ?? null);
        $this->assertStringContainsString('Natsumi', (string)($state['pending_prompt']['source_name'] ?? ''));

        $handId = $state['players']['p1']['hand'][0]['instance_id'] ?? '';
        $state = \actionResolvePrompt($state, 'p1', ['discard_ids' => [$handId]]);

        $this->assertSame('effect_discard_hand', $state['pending_prompt']['type'] ?? null);
        $this->assertStringContainsString('Kokoro', (string)($state['pending_prompt']['source_name'] ?? 'Kokoro'));
    }
}
