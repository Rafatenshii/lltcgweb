<?php

declare(strict_types=1);

namespace LLTCG\Tests\Tournament;

use PDO;
use PHPUnit\Framework\TestCase;

final class TournamentBracketTest extends TestCase
{
    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite extension required');
        }
        putenv('TCG_TOURNAMENTS_ENABLED=1');
        require_once dirname(__DIR__, 2) . '/tournament_lib.php';
    }

    public function testBracketSizeRoundsUpToPowerOfTwo(): void
    {
        $this->assertSame(2, tcgTournamentBracketSize(1));
        $this->assertSame(2, tcgTournamentBracketSize(2));
        $this->assertSame(4, tcgTournamentBracketSize(3));
        $this->assertSame(8, tcgTournamentBracketSize(5));
        $this->assertSame(8, tcgTournamentBracketSize(8));
    }

    public function testRound1PairingsIncludeByeForOddCount(): void
    {
        $pairings = tcgTournamentBuildRound1Pairings(['A', 'B', 'C']);
        $this->assertCount(2, $pairings);
        $byes = array_values(array_filter($pairings, static fn($p) => ($p['bye'] ?? null) !== null));
        $this->assertCount(1, $byes);
        $played = array_values(array_filter($pairings, static fn($p) => ($p['bye'] ?? null) === null));
        $this->assertCount(1, $played);
        $this->assertNotNull($played[0]['p1']);
        $this->assertNotNull($played[0]['p2']);
    }

    public function testFourPlayerPairingsHaveNoByes(): void
    {
        $pairings = tcgTournamentBuildRound1Pairings(['A', 'B', 'C', 'D']);
        $this->assertCount(2, $pairings);
        foreach ($pairings as $p) {
            $this->assertNull($p['bye']);
            $this->assertNotNull($p['p1']);
            $this->assertNotNull($p['p2']);
        }
    }

    public function testPrizePercents(): void
    {
        $this->assertSame([100], tcgTournamentPrizePercents(1));
        $this->assertSame([70, 30], tcgTournamentPrizePercents(2));
        $this->assertSame([50, 30, 20], tcgTournamentPrizePercents(3));
    }

    public function testTournamentsEnabledFlag(): void
    {
        putenv('TCG_TOURNAMENT_ALLOWLIST=*');
        putenv('TCG_TOURNAMENTS_ENABLED=1');
        $this->assertTrue(tcgTournamentsEnabled());
        putenv('TCG_TOURNAMENTS_ENABLED=0');
        $this->assertFalse(tcgTournamentsEnabled());
        putenv('TCG_TOURNAMENTS_ENABLED');
        putenv('TCG_TOURNAMENT_ALLOWLIST=*');
        $this->assertTrue(tcgTournamentsEnabled());
        $this->assertTrue(tcgUserMayUseTournaments('anyone'));
        putenv('TCG_TOURNAMENT_ALLOWLIST');
    }

    public function testTournamentAllowlistGatesUsers(): void
    {
        putenv('TCG_TOURNAMENTS_ENABLED=0');
        putenv('TCG_TOURNAMENT_ALLOWLIST=213038604975472640');
        $this->assertTrue(tcgTournamentsEnabled());
        $this->assertTrue(tcgUserMayUseTournaments('213038604975472640'));
        $this->assertFalse(tcgUserMayUseTournaments('999999999999999999'));
        $this->assertFalse(tcgUserMayUseTournaments(null));
        putenv('TCG_TOURNAMENT_ALLOWLIST=*');
        putenv('TCG_TOURNAMENTS_ENABLED=1');
        $this->assertTrue(tcgUserMayUseTournaments('anyone'));
        putenv('TCG_TOURNAMENT_ALLOWLIST');
        putenv('TCG_TOURNAMENTS_ENABLED');
    }
}
