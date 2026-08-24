<?php

declare(strict_types=1);

namespace LLTCG\Tests\Deck;

use PHPUnit\Framework\TestCase;

final class DeckgenSubunitPrefTest extends TestCase
{
    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/deckgen.php';
    }

    public function testSubunitBonusPrefersMatchingMembers(): void
    {
        $bibi = ['subunit' => 'BiBi', 'hearts' => ['red', 'red'], 'cost' => 4];
        $lily = ['subunit' => 'lily white', 'hearts' => ['red', 'red', 'red', 'red'], 'cost' => 4];
        $this->assertSame(10000, deckgenSubunitScoreBonus($bibi, 'BiBi'));
        $this->assertSame(0, deckgenSubunitScoreBonus($lily, 'BiBi'));
        $this->assertGreaterThan(
            deckgenMemberBuildScore($lily) + deckgenSubunitScoreBonus($lily, 'BiBi'),
            deckgenMemberBuildScore($bibi) + deckgenSubunitScoreBonus($bibi, 'BiBi')
        );
    }

    public function testSubunitMatchUsesDisplayAliases(): void
    {
        $card = ['subunit' => 'BiBi'];
        $this->assertTrue(deckgenCardMatchesSubunit($card, 'BiBi'));
        $this->assertFalse(deckgenCardMatchesSubunit($card, 'AZALEA'));
        $this->assertTrue(deckgenCardMatchesSubunit(['subunit' => 'バイバイ'], 'BiBi'));
    }

    public function testSunnyPassionFilterMatchesEmptyGroupSubunit(): void
    {
        $card = [
            'group' => '',
            'subunit' => 'Sunny Passion',
            'card_type' => 'メンバー',
        ];
        $this->assertTrue(deckgenCardMatchesGroupFilter($card, 'Sunny Passion'));
        $this->assertTrue(deckgenIsSubunitStyleGroup('Sunny Passion'));
        $this->assertTrue(deckgenIsSubunitStyleGroup('A-RISE'));
        $this->assertTrue(deckgenIsSubunitStyleGroup('Saint Snow'));
    }

    public function testRivalGroupAutobuildDoesNotHardFail(): void
    {
        [$cards, $owned] = $this->makeCollection(
            $this->spreadMembers('SP', 'Sunny Passion', '', 3),
            $this->spreadMembers('NJ', 'R3BIRTH', 'Nijigasaki', 12),
            $this->lives('L', 'R3BIRTH', 'Nijigasaki', 4),
            $this->energies('E', 'R3BIRTH', 'Nijigasaki', 3)
        );
        $gen = generateCollectionDeckLists($cards, $owned, 'Sunny Passion', null, null);
        $this->assertSame('Sunny Passion', $gen['group']);
        $this->assertCount(DECKGEN_MEMBER_SLOTS, array_slice($gen['main_deck'], 0, DECKGEN_MEMBER_SLOTS));
        $sunny = 0;
        foreach (array_slice($gen['main_deck'], 0, DECKGEN_MEMBER_SLOTS) as $no) {
            if (str_starts_with((string)$no, 'SP-')) {
                $sunny++;
            }
        }
        $this->assertSame(12, $sunny, 'all owned Sunny Passion copies should be used before fillers');
    }

    public function testInArchetypeSameCostBeatsOffArchetypeFillers(): void
    {
        $r3 = $this->spreadMembers('R3', 'R3BIRTH', 'Nijigasaki', 12);
        $setsuna = [];
        for ($i = 0; $i < 4; $i++) {
            $setsuna[] = $this->member("SE-{$i}", 'QU4RTZ', 'Nijigasaki', 8, 8);
        }
        [$cards, $owned] = $this->makeCollection(
            $r3,
            $setsuna,
            $this->lives('L', 'R3BIRTH', 'Nijigasaki', 4),
            $this->energies('E', 'R3BIRTH', 'Nijigasaki', 3)
        );
        $gen = generateCollectionDeckLists($cards, $owned, 'mixed', null, 'R3BIRTH');
        foreach (array_slice($gen['main_deck'], 0, DECKGEN_MEMBER_SLOTS) as $no) {
            $this->assertStringStartsWith('R3-', (string)$no, 'R3BIRTH copies must fill before off-archetype same-cost members');
        }
    }

    public function testEnergyUsesMatchingSubunitCopiesFirst(): void
    {
        $match = $this->energies('DE', 'DOLLCHESTRA', 'Hasunosora', 2);
        $other = $this->energies('XX', 'Cerise Bouquet', 'Hasunosora', 4);
        foreach ($other as &$c) {
            $c['rarity'] = 'SEC';
            $c['name_en'] = 'Fancy Off Archetype';
        }
        unset($c);
        [$cards, $owned] = $this->makeCollection(
            $this->spreadMembers('M', 'DOLLCHESTRA', 'Hasunosora', 12),
            $this->lives('L', 'DOLLCHESTRA', 'Hasunosora', 4),
            $match,
            $other
        );
        $gen = generateCollectionDeckLists($cards, $owned, 'mixed', null, 'DOLLCHESTRA');
        $energy = $gen['energy_deck'];
        $this->assertCount(DECKGEN_ENERGY_SLOTS, $energy);
        $matchCopies = 0;
        foreach ($energy as $no) {
            if (str_starts_with((string)$no, 'DE-')) {
                $matchCopies++;
            }
        }
        $this->assertSame(8, $matchCopies, 'all owned DOLLCHESTRA energy copies must be included');
        $this->assertTrue(
            str_starts_with((string)$energy[0], 'DE-'),
            'matching energy should occupy the first energy slots'
        );
    }

    /** @return list<array<string,mixed>> */
    private function spreadMembers(string $prefix, string $sub, string $group, int $count): array
    {
        $costs = [4, 2, 6, 8, 9, 11, 13, 15, 4, 7, 10, 14];
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $out[] = $this->member("{$prefix}-{$i}", $sub, $group, $costs[$i % count($costs)]);
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    private function lives(string $prefix, string $sub, string $group, int $count): array
    {
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $score = [3, 5, 8, 4][$i % 4];
            $out[] = [
                'card_no' => "{$prefix}-{$i}",
                'card_type' => 'ライブ',
                'group' => $group,
                'subunit' => $sub,
                'score' => $score,
                'required_hearts' => [['color' => 'red', 'count' => 1]],
                'abilities' => [],
            ];
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    private function energies(string $prefix, string $sub, string $group, int $count): array
    {
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $out[] = [
                'card_no' => "{$prefix}-{$i}",
                'card_type' => 'エネルギー',
                'group' => $group,
                'subunit' => $sub,
                'name_en' => $sub . ' Energy',
                'rarity' => 'PR',
            ];
        }
        return $out;
    }

    /** @return array<string,mixed> */
    private function member(string $no, string $sub, string $group, int $cost, int $abilityCount = 0): array
    {
        $abs = [];
        for ($i = 0; $i < $abilityCount; $i++) {
            $abs[] = ['type' => 'draw'];
        }
        return [
            'card_no' => $no,
            'card_type' => 'メンバー',
            'group' => $group,
            'subunit' => $sub,
            'cost' => $cost,
            'blade' => 1,
            'hearts' => [['color' => 'red', 'count' => 1]],
            'abilities' => $abs,
        ];
    }

    /**
     * @param list<array<string,mixed>> ...$groups
     * @return array{0: list<array<string,mixed>>, 1: array<string,int>}
     */
    private function makeCollection(array ...$groups): array
    {
        $cards = [];
        $owned = [];
        foreach ($groups as $list) {
            foreach ($list as $c) {
                $cards[] = $c;
                $owned[$c['card_no']] = 4;
            }
        }
        return [$cards, $owned];
    }
}
