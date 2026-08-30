<?php

declare(strict_types=1);

namespace LLTCG\Tests\Tournament;

use PHPUnit\Framework\TestCase;

/**
 * Lifecycle smoke against the shared local SQLite DB (unique IDs; cleaned up).
 */
final class TournamentLifecycleTest extends TestCase
{
    /** @var list<string> */
    private array $tournamentIds = [];

    /** @var list<string> */
    private array $userIds = [];

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite extension required');
        }
        putenv('TCG_TOURNAMENTS_ENABLED=1');
        require_once dirname(__DIR__, 2) . '/db.php';
        require_once dirname(__DIR__, 2) . '/coins.php';
        require_once dirname(__DIR__, 2) . '/tournament.php';

        // Ensure migration applied.
        $sql = (string)file_get_contents(dirname(__DIR__, 2) . '/migrations/017_tournaments.sql');
        tcgDb()->exec($sql);
    }

    protected function tearDown(): void
    {
        $db = tcgDb();
        foreach ($this->tournamentIds as $tid) {
            $db->prepare('DELETE FROM tcg_tournament_ledger WHERE tournament_id = ?')->execute([$tid]);
            $db->prepare('DELETE FROM tcg_tournament_matches WHERE tournament_id = ?')->execute([$tid]);
            $db->prepare('DELETE FROM tcg_tournament_entrants WHERE tournament_id = ?')->execute([$tid]);
            $db->prepare('DELETE FROM tcg_tournaments WHERE id = ?')->execute([$tid]);
        }
        foreach ($this->userIds as $uid) {
            $db->prepare('DELETE FROM tcg_tournament_h2h WHERE discord_id = ? OR opponent_discord_id = ?')
                ->execute([$uid, $uid]);
            $db->prepare('DELETE FROM tcg_tournament_user_stats WHERE discord_id = ?')->execute([$uid]);
            $db->prepare('DELETE FROM tcg_users WHERE discord_id = ?')->execute([$uid]);
        }
    }

    private function ensureUser(string $uid, int $coins = 5000): void
    {
        $this->userIds[] = $uid;
        $now = time();
        $db = tcgDb();
        $db->prepare(
            'INSERT OR REPLACE INTO tcg_users (discord_id, username, avatar_url, starter_deck, created_at, updated_at)
             VALUES (?, ?, NULL, "muse", ?, ?)'
        )->execute([$uid, $uid, $now, $now]);
        // coins column may be added by migration — best-effort
        try {
            $db->prepare('UPDATE tcg_users SET coins = ? WHERE discord_id = ?')->execute([$coins, $uid]);
        } catch (\Throwable $e) {
            // ignore if column missing in exotic fixtures
        }
    }

    public function testCancelRefundsEntryFeesAndHostDeposit(): void
    {
        $suffix = strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
        $host = 'tt_host_' . $suffix;
        $p1 = 'tt_p1_' . $suffix;
        $tid = 'TT' . $suffix;
        $this->tournamentIds[] = $tid;
        $this->ensureUser($host, 5000);
        $this->ensureUser($p1, 5000);

        $db = tcgDb();
        $now = time();
        $db->prepare(
            'INSERT INTO tcg_tournaments
             (id, host_discord_id, title, status, game_mode, start_at, checkin_mins,
              min_players, max_players, entry_fee_coins, prize_pool_coins, settings_json, created_at, updated_at)
             VALUES (?, ?, "Test", "open", "standard", ?, 10, 2, 8, 100, 0, "{}", ?, ?)'
        )->execute([$tid, $host, $now + 3600, $now, $now]);

        $db->prepare(
            'INSERT INTO tcg_tournament_entrants
             (tournament_id, discord_id, status, seed, deck_snapshot, paid_coins, registered_at, checked_in_at)
             VALUES (?, ?, "registered", NULL, "{}", 100, ?, NULL)'
        )->execute([$tid, $p1, $now]);
        $db->prepare('UPDATE tcg_tournaments SET prize_pool_coins = 1100 WHERE id = ?')->execute([$tid]);
        tcgTournamentLedgerWrite($tid, $p1, 'entry_escrow', 100, 'entry:' . $tid . ':' . $p1, []);
        tcgTournamentLedgerWrite($tid, $host, 'host_deposit', 1000, 'deposit:seed:' . $tid, []);

        $coinsBeforeP1 = tcgGetCoins($p1);
        $coinsBeforeHost = tcgGetCoins($host);

        tcgTournamentCancelAndRefund($tid, 'test');

        $row = tcgTournamentFetch($tid);
        $this->assertSame('cancelled', (string)($row['status'] ?? ''));
        $this->assertSame($coinsBeforeP1 + 100, tcgGetCoins($p1));
        $this->assertSame($coinsBeforeHost + 1000, tcgGetCoins($host));
    }

    public function testTickOpensCheckinWindow(): void
    {
        $suffix = strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
        $host = 'tt_host2_' . $suffix;
        $tid = 'TU' . $suffix;
        $this->tournamentIds[] = $tid;
        $this->ensureUser($host, 1000);

        $db = tcgDb();
        $now = time();
        $start = $now + 60;
        $db->prepare(
            'INSERT INTO tcg_tournaments
             (id, host_discord_id, title, status, game_mode, start_at, checkin_mins,
              min_players, max_players, entry_fee_coins, prize_pool_coins, settings_json, created_at, updated_at)
             VALUES (?, ?, "Soon", "open", "standard", ?, 10, 2, 8, 0, 0, "{}", ?, ?)'
        )->execute([$tid, $host, $start, $now, $now]);

        $out = tcgTournamentTickOne($tid);
        $this->assertTrue(!empty($out['success']));
        $row = tcgTournamentFetch($tid);
        $this->assertSame('checkin', (string)($row['status'] ?? ''));
        $this->assertContains('entered_checkin', $out['events'] ?? []);
    }

    public function testPayoutPersistsResultsAndProfileSummary(): void
    {
        $suffix = strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
        $host = 'tt_host3_' . $suffix;
        $p1 = 'tt_win_' . $suffix;
        $p2 = 'tt_sec_' . $suffix;
        $tid = 'TR' . $suffix;
        $this->tournamentIds[] = $tid;
        $this->ensureUser($host, 5000);
        $this->ensureUser($p1, 1000);
        $this->ensureUser($p2, 1000);

        $db = tcgDb();
        $now = time();
        tcgTournamentEnsureResultsColumn();
        $db->prepare(
            'INSERT INTO tcg_tournaments
             (id, host_discord_id, title, status, game_mode, start_at, checkin_mins,
              min_players, max_players, entry_fee_coins, prize_pool_coins, settings_json, created_at, updated_at)
             VALUES (?, ?, "Finals Night", "running", "standard", ?, 10, 2, 8, 0, 1000, "{}", ?, ?)'
        )->execute([$tid, $host, $now - 60, $now, $now]);

        foreach ([$p1, $p2] as $pid) {
            $db->prepare(
                'INSERT INTO tcg_tournament_entrants
                 (tournament_id, discord_id, status, seed, deck_snapshot, paid_coins, registered_at, checked_in_at)
                 VALUES (?, ?, "playing", NULL, "{}", 0, ?, ?)'
            )->execute([$tid, $pid, $now, $now]);
        }

        $row = tcgTournamentFetch($tid);
        $this->assertNotNull($row);
        $ok = tcgTournamentPayoutAndFinish($tid, $row, [$p1, $p2]);
        $this->assertTrue($ok);

        $finished = tcgTournamentFetch($tid);
        $this->assertSame('finished', (string)($finished['status'] ?? ''));
        $this->assertSame(0, (int)($finished['prize_pool_coins'] ?? -1));
        $results = tcgTournamentResultsForRow($finished);
        $this->assertNotNull($results);
        $this->assertSame(1000, (int)$results['prize_pool_total']);
        $this->assertSame($p1, (string)($results['winner']['discord_id'] ?? ''));
        $this->assertSame(700, (int)($results['winner']['coins'] ?? 0));
        $this->assertCount(2, $results['places']);
        $this->assertSame(300, (int)$results['places'][1]['coins']);

        $pub = tcgTournamentPublicRow($finished, ['total' => 2, 'checked_in' => 2]);
        $this->assertSame(1000, (int)($pub['prize_pool_total'] ?? 0));
        $this->assertSame($p1, (string)($pub['results']['winner']['discord_id'] ?? ''));

        $profile = tcgTournamentProfileSummary($p1, 5);
        $this->assertSame(1, (int)$profile['events_played']);
        $this->assertNotEmpty($profile['placements']);
        $this->assertSame(1, (int)$profile['placements'][0]['place']);
        $this->assertSame(700, (int)$profile['placements'][0]['coins']);
        $this->assertSame($tid, (string)$profile['placements'][0]['tournament_id']);
    }
}
