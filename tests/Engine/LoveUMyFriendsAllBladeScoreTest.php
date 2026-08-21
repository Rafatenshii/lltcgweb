<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/** PL!N-bp3-030-L Love U my friends — ALL blade in Yell → +1 score once. */
final class LoveUMyFriendsAllBladeScoreTest extends TestCase
{
    public function testPrintedHeartsAloneDoNotGrantBonus(): void
    {
        $live = [
            'instance_id' => 'love_u',
            'card_no' => 'PL!N-bp3-030-L',
            'card_type' => 'ライブ',
            'name_en' => 'Love U my friends',
            'score' => 3,
            'abilities' => [[
                'trigger' => 'live_success',
                'type' => 'live_score_if_yell_has_all_blade',
                'amount' => 1,
            ]],
        ];
        $state = [
            'players' => [
                'p1' => [
                    'name' => 'P1',
                    'live_zone' => [$live],
                    '_pending_yell_wr' => [[
                        'instance_id' => 'y1',
                        'card_type' => 'メンバー',
                        'hearts' => [['color' => 'red', 'count' => 2]],
                        'blade_hearts' => [],
                    ]],
                ],
            ],
        ];
        $state = resolveAbilityEffect($state, 'p1', $live, $live['abilities'][0], [
            'phase' => 'live_success',
            'yell_cards' => $state['players']['p1']['_pending_yell_wr'],
        ]);
        $this->assertSame(3, intval($state['players']['p1']['live_zone'][0]['score'] ?? 0));
    }

    public function testAllBladeGrantsPlusOneOnceEvenWithMultiple(): void
    {
        $live = [
            'instance_id' => 'love_u',
            'card_no' => 'PL!N-bp3-030-L',
            'card_type' => 'ライブ',
            'name_en' => 'Love U my friends',
            'score' => 3,
            'abilities' => [[
                'trigger' => 'live_success',
                'type' => 'live_score_if_yell_has_all_blade',
                'amount' => 1,
            ]],
        ];
        $yell = [
            [
                'instance_id' => 'y1',
                'card_type' => 'メンバー',
                'blade_hearts' => ['all'],
            ],
            [
                'instance_id' => 'y2',
                'card_type' => 'メンバー',
                'blade_hearts' => ['all'],
            ],
            [
                'instance_id' => 'y3',
                'card_type' => 'メンバー',
                'blade_hearts' => ['pink'],
            ],
        ];
        $state = [
            'players' => [
                'p1' => [
                    'name' => 'P1',
                    'live_zone' => [$live],
                    '_pending_yell_wr' => $yell,
                ],
            ],
        ];
        $state = resolveAbilityEffect($state, 'p1', $live, $live['abilities'][0], [
            'phase' => 'live_success',
            'yell_cards' => $yell,
        ]);
        $this->assertSame(4, intval($state['players']['p1']['live_zone'][0]['score'] ?? 0));
        $this->assertSame(1, intval($state['players']['p1']['live_zone'][0]['_effect_score_bonus'] ?? 0));
    }

    public function testCardCatalogAbilityMatchesOfficial(): void
    {
        $map = tcgBuildCardMap(json_decode((string)file_get_contents(CARDS_FILE), true) ?: []);
        $card = $map['PL!N-bp3-030-L'] ?? null;
        $this->assertIsArray($card);
        $abs = $card['abilities'] ?? [];
        $this->assertCount(1, $abs);
        $this->assertSame('live_success', $abs[0]['trigger'] ?? null);
        $this->assertSame('live_score_if_yell_has_all_blade', $abs[0]['type'] ?? null);
        $this->assertSame(1, intval($abs[0]['amount'] ?? 0));
        $this->assertStringContainsString('ALL blade', (string)($card['text'] ?? ''));
        $this->assertStringContainsString('ALLブレード', (string)($card['text_jp'] ?? ''));
        $this->assertStringContainsString('[Live Success]', (string)($card['text'] ?? ''));
    }
}