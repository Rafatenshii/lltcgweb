<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/** Player-chosen activation order when multiple Live Start skills fire. */
final class LiveStartOrderPickTest extends TestCase
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

    private function miraStub(string $id): array
    {
        return [
            'instance_id' => $id,
            'card_no' => 'PL!HS-sd1-003-SD',
            'name_en' => 'Mira stub',
            'card_type' => 'メンバー',
            'group' => 'Hasunosora',
            'subunit' => 'みらくらぱーく！',
            'active' => true,
            'cost' => 3,
            'blade' => 1,
            'abilities' => [],
        ];
    }

    private function baseState(?array $left, ?array $center, ?array $right = null): array
    {
        return [
            'status' => 'playing',
            'phase' => 'live_start_effects',
            'seq' => 1,
            'turn' => 3,
            'first_player' => 'p1',
            'active_player' => 'p1',
            'live_attempt' => ['p1'],
            '_live_start_perf_pid' => 'p1',
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => $this->handFill(4),
                    'waiting_room' => [],
                    'stage' => [
                        'left' => $left,
                        'center' => $center,
                        'right' => $right,
                    ],
                    'energy_zone' => [],
                    'energy_deck' => [],
                    'main_deck' => $this->handFill(8, 'd'),
                    'success_lives' => [],
                    'live_zone' => [[
                        'instance_id' => 'dummy_live',
                        'card_type' => 'ライブ',
                        'name_en' => 'Dummy',
                        'score' => 1,
                        'abilities' => [],
                    ]],
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
        $maki = $this->cardByNo('PL!-bp3-006-P', 'maki_ls');
        $ruri = $this->cardByNo('PL!HS-bp6-003-P', 'ruri_ls');
        // Rurino then needs a Mira-Cra on Stage for heart pick eligibility after Yes;
        // use a no-catalog stub so it does not contribute its own Live Start.
        $mira = $this->miraStub('mira_stage');
        unset($mira['card_no']);
        $state = $this->baseState($maki, $ruri, $mira);

        $state = \resolveLiveStartAbilities($state, 'p1');
        $this->assertSame('live_start_order_sources', $state['pending_prompt']['type'] ?? null);
        $ids = array_column($state['pending_prompt']['candidates'] ?? [], 'instance_id');
        $this->assertSame(['maki_ls', 'ruri_ls'], $ids);
    }

    public function testSingleSourceSkipsOrderPrompt(): void
    {
        $maki = $this->cardByNo('PL!-bp3-006-P', 'maki_solo');
        $state = $this->baseState($maki, null);

        $state = \resolveLiveStartAbilities($state, 'p1');
        $this->assertNotSame('live_start_order_sources', $state['pending_prompt']['type'] ?? null);
        $this->assertNotEmpty($state['pending_prompt'] ?? null);
        $this->assertStringContainsString('Maki', (string)($state['pending_prompt']['source_name'] ?? ''));
    }

    public function testPlayerCanActivateCenterBeforeLeft(): void
    {
        $maki = $this->cardByNo('PL!-bp3-006-P', 'maki_late');
        $ruri = $this->cardByNo('PL!HS-bp6-003-P', 'ruri_first');
        $mira = $this->miraStub('mira_stage');
        unset($mira['card_no']);
        $state = $this->baseState($maki, $ruri, $mira);

        $state = \resolveLiveStartAbilities($state, 'p1');
        $this->assertSame('live_start_order_sources', $state['pending_prompt']['type'] ?? null);

        $state = \actionResolvePrompt($state, 'p1', [
            'card_ids' => ['ruri_first', 'maki_late'],
        ]);
        $this->assertNotSame('live_start_order_sources', $state['pending_prompt']['type'] ?? null);
        $this->assertStringContainsString('Rurino', (string)($state['pending_prompt']['source_name'] ?? ''));

        $state = \actionResolvePrompt($state, 'p1', ['choice' => 'no']);
        $this->assertStringContainsString('Maki', (string)($state['pending_prompt']['source_name'] ?? ''));
    }

    public function testSecondSourceStillPromptsAfterFirstYesChain(): void
    {
        $maki = $this->cardByNo('PL!-bp3-006-P', 'maki_second');
        $ruri = $this->cardByNo('PL!HS-bp6-003-P', 'ruri_first');
        $mira = $this->miraStub('mira_stage');
        unset($mira['card_no']);
        $state = $this->baseState($maki, $ruri, $mira);
        $GLOBALS['TUT_PERF_MANUAL_PHASES'] = true;
        try {
            $state = \resolveLiveStartAbilities($state, 'p1');
            $state = \actionResolvePrompt($state, 'p1', [
                'card_ids' => ['ruri_first', 'maki_second'],
            ]);
            $this->assertSame('optional_live_start', $state['pending_prompt']['type'] ?? null);
            $this->assertStringContainsString('Rurino', (string)($state['pending_prompt']['source_name'] ?? ''));

            $discardId = (string)($state['players']['p1']['hand'][0]['instance_id'] ?? '');
            $state = \actionResolvePrompt($state, 'p1', [
                'choice' => 'yes',
                'discard_ids' => [$discardId],
            ]);
            // Rurino then → pick Mira-Cra for pink heart
            if (($state['pending_prompt']['type'] ?? '') === 'pick_member_grant_hearts') {
                $state = \actionResolvePrompt($state, 'p1', [
                    'card_id' => 'mira_stage',
                    'choice' => 'mira_stage',
                ]);
            }
            $this->assertSame('optional_live_start', $state['pending_prompt']['type'] ?? null);
            $this->assertStringContainsString(
                'Maki',
                (string)($state['pending_prompt']['source_name'] ?? ''),
                'Second Live Start source must still open after first Yes chain'
            );
        } finally {
            unset($GLOBALS['TUT_PERF_MANUAL_PHASES']);
        }
    }
}
