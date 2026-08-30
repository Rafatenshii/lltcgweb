<?php

declare(strict_types=1);

namespace LLTCG\Tests\Tournament;

use PHPUnit\Framework\TestCase;

final class TournamentBulletinFeaturedTest extends TestCase
{
    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/tournament_lib.php';
    }

    public function testPickPrefersRunningWithMostPlayers(): void
    {
        $pick = \tcgTournamentPickBulletinFeatured([
            ['id' => 'A', 'status' => 'open', 'entrant_count' => 40, 'start_at' => 100],
            ['id' => 'B', 'status' => 'running', 'entrant_count' => 8, 'start_at' => 200],
            ['id' => 'C', 'status' => 'running', 'entrant_count' => 12, 'start_at' => 300],
            ['id' => 'D', 'status' => 'checkin', 'entrant_count' => 30, 'start_at' => 50],
        ]);
        $this->assertSame('C', $pick['id'] ?? null);
    }

    public function testPickFallsBackToCheckinThenOpen(): void
    {
        $pick = \tcgTournamentPickBulletinFeatured([
            ['id' => 'A', 'status' => 'open', 'entrant_count' => 5, 'start_at' => 100],
            ['id' => 'B', 'status' => 'checkin', 'entrant_count' => 3, 'start_at' => 50],
        ]);
        $this->assertSame('B', $pick['id'] ?? null);
    }

    public function testProgressSummarizesLiveRound(): void
    {
        $progress = \tcgTournamentBulletinProgress('running', ['format' => 'single_elim'], [
            ['status' => 'done', 'round' => 1, 'bracket_side' => 'winners'],
            ['status' => 'live', 'round' => 2, 'bracket_side' => 'winners'],
            ['status' => 'ready', 'round' => 2, 'bracket_side' => 'winners'],
        ]);
        $this->assertSame('running', $progress['phase']);
        $this->assertSame(2, $progress['round']);
        $this->assertStringContainsString('Semifinals', $progress['summary']);
        $this->assertStringContainsString('1 live', $progress['summary']);
    }

    public function testProgressOpenAndCheckin(): void
    {
        $open = \tcgTournamentBulletinProgress('open', [], []);
        $this->assertSame('open', $open['phase']);
        $checkin = \tcgTournamentBulletinProgress('checkin', [], []);
        $this->assertSame('checkin', $checkin['phase']);
    }
}
