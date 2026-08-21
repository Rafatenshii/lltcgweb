<?php

declare(strict_types=1);

namespace LLTCG\Tests\Tournament;

use PHPUnit\Framework\TestCase;

final class TournamentStatsTest extends TestCase
{
    private string $a = '';
    private string $b = '';

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite extension required');
        }
        putenv('TCG_TOURNAMENTS_ENABLED=1');
        require_once dirname(__DIR__, 2) . '/db.php';
        require_once dirname(__DIR__, 2) . '/tournament_stats.php';

        tcgTournamentStatsEnsureSchema();
        $this->a = 'statA' . bin2hex(random_bytes(4));
        $this->b = 'statB' . bin2hex(random_bytes(4));
        $db = tcgDb();
        foreach ([$this->a, $this->b] as $id) {
            $db->prepare(
                'INSERT OR IGNORE INTO tcg_users (discord_id, username, avatar_url, created_at, updated_at)
                 VALUES (?, ?, NULL, ?, ?)'
            )->execute([$id, 'U' . $id, time(), time()]);
        }
    }

    protected function tearDown(): void
    {
        $db = tcgDb();
        foreach ([$this->a, $this->b] as $id) {
            $db->prepare('DELETE FROM tcg_tournament_h2h WHERE discord_id = ? OR opponent_discord_id = ?')
                ->execute([$id, $id]);
            $db->prepare('DELETE FROM tcg_tournament_user_stats WHERE discord_id = ?')->execute([$id]);
            $db->prepare('DELETE FROM tcg_users WHERE discord_id = ?')->execute([$id]);
        }
    }

    public function testMatchAndH2hAndCoins(): void
    {
        $meta = [];
        tcgTournamentStatsRecordSeriesResult($this->a, $this->b, $meta);
        $this->assertTrue(!empty($meta['stats_recorded']));

        // Idempotent
        tcgTournamentStatsRecordSeriesResult($this->a, $this->b, $meta);

        tcgTournamentStatsOnLedger('entry_escrow', $this->a, 100);
        tcgTournamentStatsOnLedger('host_deposit', $this->a, 500);
        tcgTournamentStatsOnLedger('payout', $this->a, 700);
        tcgTournamentStatsOnLedger('refund', $this->a, 100);

        $sum = tcgTournamentStatsSummaryForUser($this->a, 5);
        $this->assertSame(1, $sum['match_wins']);
        $this->assertSame(0, $sum['match_losses']);
        $this->assertSame(700, $sum['coins_earned']);
        $this->assertSame(500, $sum['coins_contributed']); // 100+500-100
        $this->assertCount(1, $sum['h2h']);
        $this->assertSame($this->b, $sum['h2h'][0]['opponent_discord_id']);
        $this->assertSame(1, $sum['h2h'][0]['wins']);
        $this->assertSame(0, $sum['h2h'][0]['losses']);

        $sumB = tcgTournamentStatsSummaryForUser($this->b, 5);
        $this->assertSame(0, $sumB['match_wins']);
        $this->assertSame(1, $sumB['match_losses']);
        $this->assertSame(1, $sumB['h2h'][0]['losses']);
    }

    public function testByeCountsAsWinWithoutH2h(): void
    {
        $meta = [];
        tcgTournamentStatsRecordSeriesResult($this->a, '', $meta);
        $sum = tcgTournamentStatsSummaryForUser($this->a, 5);
        $this->assertSame(1, $sum['match_wins']);
        $this->assertSame([], $sum['h2h']);
    }
}
