<?php

declare(strict_types=1);

namespace LLTCG\Tests\Tournament;

use PHPUnit\Framework\TestCase;

final class TournamentPhase3Test extends TestCase
{
    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/tournament_lib.php';
        require_once dirname(__DIR__, 2) . '/tournament_formats.php';
    }

    public function testSwissPairingsAvoidRematchWhenPossible(): void
    {
        $records = [
            'A' => ['wins' => 1, 'losses' => 0],
            'B' => ['wins' => 1, 'losses' => 0],
            'C' => ['wins' => 0, 'losses' => 1],
            'D' => ['wins' => 0, 'losses' => 1],
        ];
        $pairings = tcgTournamentBuildSwissPairings(
            ['A', 'B', 'C', 'D'],
            $records,
            [['A', 'B']]
        );
        $this->assertCount(2, $pairings);
        foreach ($pairings as $p) {
            $this->assertNull($p['bye']);
            $pair = [$p['p1'], $p['p2']];
            sort($pair);
            $this->assertNotSame(['A', 'B'], $pair);
        }
    }

    public function testSwissRoundCountBounds(): void
    {
        $this->assertSame(3, tcgTournamentSwissRoundCount(2));
        $this->assertSame(3, tcgTournamentSwissRoundCount(4));
        $this->assertSame(4, tcgTournamentSwissRoundCount(10));
        $this->assertSame(5, tcgTournamentSwissRoundCount(40));
    }

    public function testBracketPreviewScalesWithPlayerCap(): void
    {
        $p4 = tcgTournamentBracketPreview(4, 'single_elim');
        $winners = array_values(array_filter($p4, static fn($x) => ($x['bracket_side'] ?? '') === 'winners'));
        $this->assertSame(3, count($winners)); // 2 semis + final slots = 2+1

        $p8 = tcgTournamentBracketPreview(8, 'single_elim');
        $w8 = array_values(array_filter($p8, static fn($x) => ($x['bracket_side'] ?? '') === 'winners'));
        $this->assertSame(7, count($w8)); // 4+2+1

        $swiss = tcgTournamentBracketPreview(4, 'swiss');
        $this->assertNotEmpty($swiss);
        $this->assertSame('swiss', $swiss[0]['bracket_side']);
        $swissPlayoff = array_values(array_filter($swiss, static fn($x) => ($x['bracket_side'] ?? '') === 'winners'));
        $this->assertCount(1, $swissPlayoff); // ≤8 → final only

        $swiss9 = tcgTournamentBracketPreview(9, 'swiss');
        $cut9 = array_values(array_filter($swiss9, static fn($x) => ($x['bracket_side'] ?? '') === 'winners'));
        $this->assertCount(3, $cut9); // 2 semis + final
    }

    public function testSwissPlayoffSizeByShowedUp(): void
    {
        $this->assertSame(2, tcgTournamentSwissPlayoffSize(2));
        $this->assertSame(2, tcgTournamentSwissPlayoffSize(8));
        $this->assertSame(4, tcgTournamentSwissPlayoffSize(9));
        $this->assertSame(4, tcgTournamentSwissPlayoffSize(16));
    }

    public function testNormalizeKeepsSwissRuntimeFields(): void
    {
        $n = tcgTournamentNormalizeSettings([
            'format' => 'swiss',
            'swiss_rounds' => 3,
            'showed_up' => 8,
            'playoff_size' => 2,
            'swiss_phase' => 'playoff',
        ]);
        $this->assertSame(3, $n['swiss_rounds']);
        $this->assertSame(8, $n['showed_up']);
        $this->assertSame(2, $n['playoff_size']);
        $this->assertSame('playoff', $n['swiss_phase']);
        $encoded = tcgTournamentEncodeSettings($n);
        $decoded = tcgTournamentDecodeSettings($encoded);
        $this->assertSame(3, $decoded['swiss_rounds']);
        $this->assertSame(2, $decoded['playoff_size']);
    }

    public function testRecordsFromMatches(): void
    {
        $recs = tcgTournamentRecordsFromMatches([
            [
                'status' => 'done',
                'winner_discord_id' => 'A',
                'p1_discord_id' => 'A',
                'p2_discord_id' => 'B',
                'bracket_side' => 'swiss',
            ],
            [
                'status' => 'done',
                'winner_discord_id' => 'C',
                'p1_discord_id' => 'C',
                'p2_discord_id' => 'A',
                'bracket_side' => 'swiss',
            ],
            [
                'status' => 'done',
                'winner_discord_id' => 'A',
                'p1_discord_id' => 'A',
                'p2_discord_id' => 'C',
                'bracket_side' => 'winners',
            ],
        ]);
        $this->assertSame(2, $recs['A']['wins']);
        $swissOnly = tcgTournamentRecordsFromMatches([
            [
                'status' => 'done',
                'winner_discord_id' => 'A',
                'p1_discord_id' => 'A',
                'p2_discord_id' => 'B',
                'bracket_side' => 'swiss',
            ],
            [
                'status' => 'done',
                'winner_discord_id' => 'A',
                'p1_discord_id' => 'A',
                'p2_discord_id' => 'C',
                'bracket_side' => 'winners',
            ],
        ], 'swiss');
        $this->assertSame(1, $swissOnly['A']['wins']);
        $this->assertArrayNotHasKey('C', $swissOnly);
    }
}
