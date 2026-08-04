<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/** Hand draws must not leak card type to the opponent via the game log. */
final class EffectDrawLogPrivacyTest extends TestCase
{
    public function testPublicDrawLogHidesCardType(): void
    {
        [$private, $public] = effectLogDrewDetail([
            'name_en' => 'Umi Sonoda',
            'card_type' => 'ライブ',
        ]);
        $this->assertSame('drew Umi Sonoda.', $private);
        $this->assertSame('drew a card.', $public);
        $this->assertStringNotContainsString('Live', $public);
        $this->assertStringNotContainsString('Member', $public);
    }

    public function testPublicWrDiscardStillShowsType(): void
    {
        [$private, $public] = effectLogPutWrDetail([
            'name_en' => 'Umi Sonoda',
            'card_type' => 'メンバー',
        ]);
        $this->assertSame('put Umi Sonoda into the Waiting Room.', $private);
        $this->assertSame('put a Member card into the Waiting Room.', $public);
    }

    public function testFilterLogEntrySwapsMsgPublicForOpponent(): void
    {
        $state = [
            'players' => [
                'p1' => ['name' => 'Alice'],
                'p2' => ['name' => 'Bob'],
            ],
        ];
        $entry = [
            'msg' => 'Alice — [Umi Sonoda] drew Umi Sonoda.',
            'owner' => 'p1',
            'msg_public' => 'Alice — [Umi Sonoda] drew a card.',
            'kind' => 'effect',
        ];
        $forOpp = filterLogEntryForViewer($entry, 'p2', $state);
        $this->assertSame('Alice — [Umi Sonoda] drew a card.', $forOpp['msg']);
        $this->assertArrayNotHasKey('msg_public', $forOpp);

        $forOwner = filterLogEntryForViewer($entry, 'p1', $state);
        $this->assertSame('Alice — [Umi Sonoda] drew Umi Sonoda.', $forOwner['msg']);
    }

    public function testLogEffectDrawStoresRedactedPublicMessage(): void
    {
        $state = [
            'log' => [],
            'players' => [
                'p1' => ['name' => 'Alice'],
                'p2' => ['name' => 'Bob'],
            ],
        ];
        $card = [
            'instance_id' => 'c1',
            'name_en' => 'Test Live',
            'card_type' => 'ライブ',
        ];
        $state = logEffectDraw($state, 'p1', 'Umi Sonoda', $card);
        $entry = $state['log'][0] ?? [];
        $this->assertSame('Alice — [Umi Sonoda] drew Test Live.', $entry['msg'] ?? null);
        $this->assertSame('Alice — [Umi Sonoda] drew a card.', $entry['msg_public'] ?? null);
        $this->assertSame('p1', $entry['owner'] ?? null);
    }
}
