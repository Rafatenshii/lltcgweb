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
        $this->assertSame(
            '2X7YN',
            tcgNormalizeDecklogCode('https://decklog-en.bushiroad.com/view/2X7YN')
        );
        $this->assertSame(
            'ABC12',
            tcgNormalizeDecklogCode('https://decklog-en.example.com/view/abc12?foo=1')
        );
        $this->assertSame(
            '755SH',
            tcgNormalizeDecklogCode('https://decklog-en.bushiroad.com/ja/view/755SH')
        );
    }

    public function testParseDecklogInputPrefersEnHostFromUrl(): void
    {
        $hosts = tcgDecklogHosts();
        $en = tcgParseDecklogInput('https://decklog-en.bushiroad.com/view/2X7YN');
        $this->assertSame('2X7YN', $en['code']);
        $this->assertSame($hosts['en'], $en['preferred_host']);
        $this->assertNull($en['preferred_api_prefix']);

        $enJa = tcgParseDecklogInput('https://decklog-en.bushiroad.com/ja/view/755SH');
        $this->assertSame('755SH', $enJa['code']);
        $this->assertSame($hosts['en'], $enJa['preferred_host']);
        $this->assertSame('app-ja', $enJa['preferred_api_prefix']);

        $jp = tcgParseDecklogInput('https://decklog.bushiroad.com/view/2X7YN');
        $this->assertSame('2X7YN', $jp['code']);
        $this->assertSame($hosts['jp'], $jp['preferred_host']);

        $bare = tcgParseDecklogInput('2X7YN');
        $this->assertSame('2X7YN', $bare['code']);
        $this->assertNull($bare['preferred_host']);
    }

    public function testEnHostPrefersAppJaApiPrefix(): void
    {
        $hosts = tcgDecklogHosts();
        $this->assertSame(
            ['app-ja', 'app'],
            tcgDecklogApiAppPrefixesForHost($hosts['en'], 'app-ja')
        );
        $this->assertSame(
            ['app-ja', 'app'],
            tcgDecklogApiAppPrefixesForHost($hosts['en'], null)
        );
        $this->assertSame(
            ['app'],
            tcgDecklogApiAppPrefixesForHost($hosts['jp'], null)
        );
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

    public function testMapEn755SHFixtureAcceptsGameTitleId109(): void
    {
        $fixture = dirname(__DIR__) . '/fixtures/decklog_755SH_en.json';
        $this->assertFileExists($fixture);
        $payload = json_decode((string)file_get_contents($fixture), true);
        $this->assertIsArray($payload);
        $this->assertSame(109, intval($payload['game_title_id'] ?? 0));

        $cards = json_decode((string)file_get_contents(CARDS_FILE), true);
        $this->assertIsArray($cards);

        $mapped = tcgMapDecklogPayloadToExperimentLists($payload, $cards);
        $this->assertSame('755SH', $mapped['deck_id']);
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
            ['PL!HS-bp1-002-R' => $sub] // legacy string = reuse for every shortfall
        );
        $this->assertSame([], $unresolved);
        $this->assertCount(4, $filled);
        $this->assertSame(1, count(array_filter($filled, static fn($n) => $n === 'PL!HS-bp1-002-R')));
        $this->assertSame(3, count(array_filter($filled, static fn($n) => $n === $sub)));

        $after = tcgDecklogMissingFromOwned($filled, $energy, $owned, $cardMap);
        $this->assertSame([], $after);

        $ownedLeft2 = $owned;
        [$filledList, $unresolvedList] = tcgDecklogApplySubstitutionsToList(
            $main,
            $ownedLeft2,
            ['PL!HS-bp1-002-R' => [$sub, $sub, $sub]]
        );
        $this->assertSame([], $unresolvedList);
        $this->assertSame(3, count(array_filter($filledList, static fn($n) => $n === $sub)));
    }

    public function testProductKindForBoosterAndPr(): void
    {
        $this->assertSame('bp', tcgDecklogProductKind('ブースターパック vol.1'));
        $this->assertSame('pr', tcgDecklogProductKind('PRカード'));
        $this->assertSame('sd', tcgDecklogProductKind('スタートデッキ ラブライブ！'));
    }

    public function testAutoEnergyPrefersNonStarterAndKeepsMissingVisible(): void
    {
        $cards = json_decode((string)file_get_contents(CARDS_FILE), true);
        $cardMap = tcgBuildCardMap($cards);

        $targetEnergy = null;
        $sdEnergy = null;
        $bpEnergy = null;
        foreach ($cardMap as $no => $card) {
            if (($card['card_type'] ?? '') !== 'エネルギー') {
                continue;
            }
            $kind = tcgDecklogProductKind(trim((string)($card['booster_pack'] ?? '')));
            if ($targetEnergy === null) {
                $targetEnergy = $no;
            }
            if ($sdEnergy === null && $kind === 'sd' && $no !== $targetEnergy) {
                $sdEnergy = $no;
            }
            if ($bpEnergy === null && $kind === 'bp' && $no !== $targetEnergy) {
                $bpEnergy = $no;
            }
            if ($targetEnergy && $sdEnergy && $bpEnergy) {
                break;
            }
        }
        $this->assertNotNull($targetEnergy);
        $this->assertNotNull($sdEnergy);
        $this->assertNotNull($bpEnergy);

        $energy = [$targetEnergy, $targetEnergy, $targetEnergy, $targetEnergy];
        $owned = [
            $targetEnergy => 0,
            $sdEnergy => 4,
            $bpEnergy => 4,
        ];

        $subs = tcgDecklogBuildAutoEnergySubstitutions([], $energy, $owned, $cardMap, []);
        $this->assertArrayHasKey($targetEnergy, $subs);
        $picked = $subs[$targetEnergy];
        $this->assertCount(4, $picked);
        foreach ($picked as $no) {
            $this->assertSame($bpEnergy, $no, 'Auto Energy should prefer booster over starter');
        }

        // Incomplete UI should still list the original Energy shortfall (not hide it after apply).
        $missingShow = tcgDecklogMissingFromOwned([], $energy, $owned, $cardMap);
        $energyRow = null;
        foreach ($missingShow as $row) {
            if ($row['card_no'] === $targetEnergy) {
                $energyRow = $row;
                break;
            }
        }
        $this->assertNotNull($energyRow);
        $this->assertSame(4, $energyRow['shortfall']);
        $this->assertSame('エネルギー', $energyRow['card_type']);
    }

    public function testEnergyMissingSortedAfterMembers(): void
    {
        $cards = json_decode((string)file_get_contents(CARDS_FILE), true);
        $cardMap = tcgBuildCardMap($cards);
        $member = null;
        $energy = null;
        foreach ($cardMap as $no => $card) {
            if ($member === null && ($card['card_type'] ?? '') === 'メンバー') {
                $member = $no;
            }
            if ($energy === null && ($card['card_type'] ?? '') === 'エネルギー') {
                $energy = $no;
            }
            if ($member && $energy) {
                break;
            }
        }
        $this->assertNotNull($member);
        $this->assertNotNull($energy);
        $missing = tcgDecklogMissingFromOwned([$member], [$energy], [], $cardMap);
        $this->assertCount(2, $missing);
        $this->assertSame('メンバー', $missing[0]['card_type']);
        $this->assertSame('エネルギー', $missing[1]['card_type']);
    }
}
