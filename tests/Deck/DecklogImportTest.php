<?php

declare(strict_types=1);

namespace LLTCG\Tests\Deck;

use Exception;
use PHPUnit\Framework\TestCase;

final class DecklogImportTest extends TestCase
{
    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/decklog_import.php';
        require_once dirname(__DIR__, 2) . '/experiment_decks.php';
    }

    public function testNormalizeCodeFromUrlAndBare(): void
    {
        $this->assertSame(
            '2X7YN',
            tcgNormalizeDecklogCode('https://decklog.bushiroad.com/view/2X7YN')
        );
        $this->assertSame('2X7YN', tcgNormalizeDecklogCode(' 2x7yn '));
        $this->assertSame('2X7YN', tcgNormalizeDecklogCode('https://decklog.bushiroad.com/view/2x7yn?lang=en'));
    }

    public function testResolveFullwidthPlusCardNo(): void
    {
        $cardNos = ['PL!HS-bp1-001-R＋' => true];
        $this->assertSame(
            'PL!HS-bp1-001-R＋',
            tcgResolveDecklogCardNo('PL!HS-bp1-001-R+', $cardNos)
        );
        $this->assertSame(
            'PL!HS-bp1-001-R＋',
            tcgResolveDecklogCardNo('PL!HS-bp1-001-R＋', $cardNos)
        );
    }

    public function testMap2X7YNFixtureToLegalExperimentDeck(): void
    {
        $fixture = dirname(__DIR__) . '/fixtures/decklog_2X7YN.json';
        $this->assertFileExists($fixture);
        $payload = json_decode((string)file_get_contents($fixture), true);
        $this->assertIsArray($payload);
        $this->assertSame(11, intval($payload['game_title_id'] ?? 0));

        $cards = json_decode((string)file_get_contents(CARDS_FILE), true);
        $this->assertIsArray($cards);

        $mapped = tcgMapDecklogPayloadToExperimentLists($payload, $cards);
        $this->assertSame('2X7YN', $mapped['deck_id']);
        $this->assertSame(60, count($mapped['main_deck']));
        $this->assertSame(12, count($mapped['energy_deck']));

        $validated = validateExperimentDeckPayload($mapped['main_deck'], $mapped['energy_deck'], $cards);
        $this->assertCount(60, $validated['main']);
        $this->assertCount(12, $validated['energy']);
    }

    public function testRejectNonLoveLiveGameTitle(): void
    {
        $cards = json_decode((string)file_get_contents(CARDS_FILE), true);
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Love Live');
        tcgMapDecklogPayloadToExperimentLists([
            'game_title_id' => 2,
            'deck_id' => '23KKA',
            'title' => 'WS sample',
            'list' => [],
            'sub_list' => [],
        ], $cards);
    }
}
