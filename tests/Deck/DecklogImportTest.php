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
        require_once dirname(__DIR__, 2) . '/deck_validate.php';
    }

    public function testNormalizeCodeFromUrlAndBare(): void
    {
        $this->assertSame(
            '2X7YN',
            tcgNormalizeDecklogCode('https://decklog.example.com/view/2X7YN')
        );
        $this->assertSame('2X7YN', tcgNormalizeDecklogCode(' 2x7yn '));
        $this->assertSame('2X7YN', tcgNormalizeDecklogCode('https://decklog.example.com/view/2x7yn?lang=en'));
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

    public function testMissingFromOwnedReportsShortfallAndObtain(): void
    {
        $cards = json_decode((string)file_get_contents(CARDS_FILE), true);
        $cardMap = tcgBuildCardMap($cards);
        $fixture = dirname(__DIR__) . '/fixtures/decklog_2X7YN.json';
        $payload = json_decode((string)file_get_contents($fixture), true);
        $mapped = tcgMapDecklogPayloadToExperimentLists($payload, $cards);

        $owned = [];
        foreach (array_merge($mapped['main_deck'], $mapped['energy_deck']) as $no) {
            $owned[$no] = ($owned[$no] ?? 0) + 1;
        }
        $victim = $mapped['main_deck'][0];
        $owned[$victim] = max(0, ($owned[$victim] ?? 0) - 2);

        $missing = tcgDecklogMissingFromOwned(
            $mapped['main_deck'],
            $mapped['energy_deck'],
            $owned,
            $cardMap
        );
        $this->assertNotEmpty($missing);
        $row = null;
        foreach ($missing as $m) {
            if ($m['card_no'] === $victim) {
                $row = $m;
                break;
            }
        }
        $this->assertNotNull($row);
        $this->assertSame(2, $row['shortfall']);
        $this->assertArrayHasKey('booster_pack', $row['obtain']);
        $this->assertArrayHasKey('product_kind', $row['obtain']);
        $this->assertNotSame('', $row['obtain']['product_kind']);
    }

    public function testApplySubstitutionsFillsShortfall(): void
    {
        $cards = json_decode((string)file_get_contents(CARDS_FILE), true);
        $cardMap = tcgBuildCardMap($cards);
        $main = ['PL!HS-bp1-002-R', 'PL!HS-bp1-002-R', 'PL!HS-bp1-002-R', 'PL!HS-bp1-002-R'];
        $energy = [];
        // Own 1 of target + 3 of a same-type substitute family member if possible.
        $owned = ['PL!HS-bp1-002-R' => 1];
        $sub = null;
        foreach ($cardMap as $no => $card) {
            if ($no === 'PL!HS-bp1-002-R') {
                continue;
            }
            if (($card['card_type'] ?? '') !== 'メンバー') {
                continue;
            }
            if (tcgDecklogSubstituteScore($cardMap['PL!HS-bp1-002-R'], $card) >= 40) {
                $sub = $no;
                break;
            }
        }
        $this->assertNotNull($sub);
        $owned[$sub] = 3;

        $missing = tcgDecklogMissingFromOwned($main, $energy, $owned, $cardMap);
        $this->assertCount(1, $missing);
        $this->assertSame(3, $missing[0]['shortfall']);

        $ownedLeft = $owned;
        [$filled, $unresolved] = tcgDecklogApplySubstitutionsToList(
            $main,
            $ownedLeft,
            ['PL!HS-bp1-002-R' => $sub]
        );
        $this->assertSame([], $unresolved);
        $this->assertCount(4, $filled);
        $this->assertSame(1, count(array_filter($filled, static fn($n) => $n === 'PL!HS-bp1-002-R')));
        $this->assertSame(3, count(array_filter($filled, static fn($n) => $n === $sub)));

        $after = tcgDecklogMissingFromOwned($filled, $energy, $owned, $cardMap);
        $this->assertSame([], $after);
    }

    public function testProductKindForBoosterAndPr(): void
    {
        $this->assertSame('bp', tcgDecklogProductKind('ブースターパック vol.1'));
        $this->assertSame('pr', tcgDecklogProductKind('PRカード'));
        $this->assertSame('sd', tcgDecklogProductKind('スタートデッキ ラブライブ！'));
    }
}
