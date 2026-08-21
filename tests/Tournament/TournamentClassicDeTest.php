<?php

declare(strict_types=1);

namespace LLTCG\Tests\Tournament;

use PHPUnit\Framework\TestCase;

final class TournamentClassicDeTest extends TestCase
{
    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/tournament_lib.php';
        require_once dirname(__DIR__, 2) . '/tournament_formats.php';
    }

    public function testLosersRoundCounts(): void
    {
        $this->assertSame([1, 1], tcgTournamentClassicDeLosersRoundCounts(4));
        $this->assertSame([2, 2, 1, 1], tcgTournamentClassicDeLosersRoundCounts(8));
        $this->assertSame([4, 4, 2, 2, 1, 1], tcgTournamentClassicDeLosersRoundCounts(16));
    }

    public function testWinnerDestWinnersTree(): void
    {
        $d = tcgTournamentClassicDeWinnerDest(8, 'winners', 1, 3);
        $this->assertSame(['side' => 'winners', 'round' => 2, 'slot' => 1, 'seat' => 1], $d);

        $wf = tcgTournamentClassicDeWinnerDest(8, 'winners', 3, 0);
        $this->assertSame(['side' => 'grand_final', 'round' => 1, 'slot' => 0, 'seat' => 0], $wf);
    }

    public function testLoserDropAndLosersAdvance(): void
    {
        $drop = tcgTournamentClassicDeLoserDrop(8, 1, 2);
        $this->assertSame(['side' => 'losers', 'round' => 1, 'slot' => 1, 'seat' => 0], $drop);

        $w2 = tcgTournamentClassicDeLoserDrop(8, 2, 0);
        $this->assertSame(['side' => 'losers', 'round' => 2, 'slot' => 0, 'seat' => 1], $w2);

        $l2w = tcgTournamentClassicDeWinnerDest(8, 'losers', 2, 1);
        $this->assertSame(['side' => 'losers', 'round' => 3, 'slot' => 0, 'seat' => 1], $l2w);

        $lf = tcgTournamentClassicDeWinnerDest(8, 'losers', 4, 0);
        $this->assertSame(['side' => 'grand_final', 'round' => 1, 'slot' => 0, 'seat' => 1], $lf);
    }

    public function testFourPlayerFeedMap(): void
    {
        // W1-0 / W1-1 → W2; losers → L1; W2 loser → L2; champs → GF
        $this->assertSame(
            ['side' => 'winners', 'round' => 2, 'slot' => 0, 'seat' => 0],
            tcgTournamentClassicDeWinnerDest(4, 'winners', 1, 0)
        );
        $this->assertSame(
            ['side' => 'losers', 'round' => 1, 'slot' => 0, 'seat' => 0],
            tcgTournamentClassicDeLoserDrop(4, 1, 0)
        );
        $this->assertSame(
            ['side' => 'losers', 'round' => 1, 'slot' => 0, 'seat' => 1],
            tcgTournamentClassicDeLoserDrop(4, 1, 1)
        );
        $this->assertSame(
            ['side' => 'losers', 'round' => 2, 'slot' => 0, 'seat' => 1],
            tcgTournamentClassicDeLoserDrop(4, 2, 0)
        );
        $this->assertSame(
            ['side' => 'grand_final', 'round' => 1, 'slot' => 0, 'seat' => 0],
            tcgTournamentClassicDeWinnerDest(4, 'winners', 2, 0)
        );
        $this->assertSame(
            ['side' => 'grand_final', 'round' => 1, 'slot' => 0, 'seat' => 1],
            tcgTournamentClassicDeWinnerDest(4, 'losers', 2, 0)
        );
    }

    public function testBracketPreviewClassicHasSides(): void
    {
        $prev = tcgTournamentBracketPreview(4, 'double_elim_bracket');
        $sides = [];
        foreach ($prev as $row) {
            $sides[$row['bracket_side']] = ($sides[$row['bracket_side']] ?? 0) + 1;
        }
        $this->assertSame(3, $sides['winners']); // 2 + 1
        $this->assertSame(2, $sides['losers']); // L1 + L2
        $this->assertSame(2, $sides['grand_final']); // GF + reset skeleton

        $lives = tcgTournamentBracketPreview(4, 'double_elim');
        foreach ($lives as $row) {
            $this->assertNotSame('losers', $row['bracket_side']);
            $this->assertNotSame('grand_final', $row['bracket_side']);
        }
    }

    public function testNormalizeAllowsClassicFormat(): void
    {
        $n = tcgTournamentNormalizeSettings(['format' => 'double_elim_bracket']);
        $this->assertSame('double_elim_bracket', $n['format']);
        $this->assertTrue(tcgTournamentIsClassicDoubleElim('double_elim_bracket'));
        $this->assertFalse(tcgTournamentIsClassicDoubleElim('double_elim'));
    }
}
