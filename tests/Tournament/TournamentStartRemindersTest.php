<?php

declare(strict_types=1);

namespace LLTCG\Tests\Tournament;

use PHPUnit\Framework\TestCase;

final class TournamentStartRemindersTest extends TestCase
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
        putenv('TCG_FCM_SERVER_KEY=');
        require_once dirname(__DIR__, 2) . '/db.php';
        require_once dirname(__DIR__, 2) . '/push.php';
        require_once dirname(__DIR__, 2) . '/tournament.php';
        tcgPushEnsureSchema();
    }

    protected function tearDown(): void
    {
        $db = tcgDb();
        foreach ($this->tournamentIds as $tid) {
            $db->prepare('DELETE FROM tcg_tournament_start_reminders WHERE tournament_id = ?')->execute([$tid]);
            $db->prepare('DELETE FROM tcg_tournaments WHERE id = ?')->execute([$tid]);
        }
        foreach ($this->userIds as $uid) {
            $db->prepare('DELETE FROM tcg_push_tokens WHERE discord_id = ?')->execute([$uid]);
            $db->prepare('DELETE FROM tcg_tournament_start_reminders WHERE discord_id = ?')->execute([$uid]);
            $db->prepare('DELETE FROM tcg_users WHERE discord_id = ?')->execute([$uid]);
        }
    }

    private function ensureUser(string $uid): void
    {
        $this->userIds[] = $uid;
        $now = time();
        tcgDb()->prepare(
            'INSERT OR REPLACE INTO tcg_users (discord_id, username, avatar_url, starter_deck, created_at, updated_at)
             VALUES (?, ?, NULL, "muse", ?, ?)'
        )->execute([$uid, $uid, $now, $now]);
    }

    private function insertOpenTournament(string $tid, string $host, int $startAt): void
    {
        $this->tournamentIds[] = $tid;
        $now = time();
        tcgDb()->prepare(
            'INSERT INTO tcg_tournaments
             (id, host_discord_id, title, status, game_mode, start_at, checkin_mins,
              min_players, max_players, entry_fee_coins, prize_pool_coins, settings_json, created_at, updated_at)
             VALUES (?, ?, "Soon Cup", "open", "standard", ?, 10, 2, 8, 0, 0, "{}", ?, ?)'
        )->execute([$tid, $host, $startAt, $now, $now]);
    }

    public function testNormalizeOffsetsDropsUnknown(): void
    {
        $this->assertSame(
            [300, 3600],
            tcgPushNormalizeTournamentStartOffsets([300, 999, '3600', 300])
        );
    }

    public function testDispatchMarksSentWhenWindowOpenAndTokenExists(): void
    {
        $suffix = strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
        $host = 'tr_host_' . $suffix;
        $uid = 'tr_user_' . $suffix;
        $tid = 'TR' . $suffix;
        $this->ensureUser($host);
        $this->ensureUser($uid);
        $now = time();
        $this->insertOpenTournament($tid, $host, $now + 400);
        tcgPushRegisterToken($uid, 'token-' . $suffix, 'android');
        tcgDb()->prepare(
            'INSERT INTO tcg_tournament_start_reminders (discord_id, tournament_id, offset_sec, sent_at)
             VALUES (?, ?, 600, NULL)'
        )->execute([$uid, $tid]);

        $n = tcgPushDispatchTournamentStartReminders();
        $this->assertSame(1, $n);
        $stmt = tcgDb()->prepare(
            'SELECT sent_at FROM tcg_tournament_start_reminders WHERE discord_id = ? AND tournament_id = ? AND offset_sec = 600'
        );
        $stmt->execute([$uid, $tid]);
        $this->assertGreaterThan(0, (int)$stmt->fetchColumn());

        $this->assertSame(0, tcgPushDispatchTournamentStartReminders());
    }

    public function testDispatchSkipsWithoutTokenAndLeavesUnsent(): void
    {
        $suffix = strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
        $host = 'ts_host_' . $suffix;
        $uid = 'ts_user_' . $suffix;
        $tid = 'TS' . $suffix;
        $this->ensureUser($host);
        $this->ensureUser($uid);
        $now = time();
        $this->insertOpenTournament($tid, $host, $now + 200);
        tcgDb()->prepare(
            'INSERT INTO tcg_tournament_start_reminders (discord_id, tournament_id, offset_sec, sent_at)
             VALUES (?, ?, 300, NULL)'
        )->execute([$uid, $tid]);

        $this->assertSame(0, tcgPushDispatchTournamentStartReminders());
        $stmt = tcgDb()->prepare(
            'SELECT sent_at FROM tcg_tournament_start_reminders WHERE discord_id = ? AND tournament_id = ?'
        );
        $stmt->execute([$uid, $tid]);
        $this->assertNull($stmt->fetchColumn());
    }

    public function testDispatchSkipsRunningTournament(): void
    {
        $suffix = strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
        $host = 'tu_host_' . $suffix;
        $uid = 'tu_user_' . $suffix;
        $tid = 'TU' . $suffix;
        $this->ensureUser($host);
        $this->ensureUser($uid);
        $now = time();
        $this->insertOpenTournament($tid, $host, $now + 100);
        tcgDb()->prepare('UPDATE tcg_tournaments SET status = "running" WHERE id = ?')->execute([$tid]);
        tcgPushRegisterToken($uid, 'token-run-' . $suffix, 'android');
        tcgDb()->prepare(
            'INSERT INTO tcg_tournament_start_reminders (discord_id, tournament_id, offset_sec, sent_at)
             VALUES (?, ?, 300, NULL)'
        )->execute([$uid, $tid]);

        $this->assertSame(0, tcgPushDispatchTournamentStartReminders());
    }

    public function testLateEnableFiresInWindow(): void
    {
        $suffix = strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
        $host = 'tv_host_' . $suffix;
        $uid = 'tv_user_' . $suffix;
        $tid = 'TV' . $suffix;
        $this->ensureUser($host);
        $this->ensureUser($uid);
        $now = time();
        // 30 min left; user enables 1h offset — window already open.
        $this->insertOpenTournament($tid, $host, $now + 1800);
        tcgPushRegisterToken($uid, 'token-late-' . $suffix, 'android');
        tcgDb()->prepare(
            'INSERT INTO tcg_tournament_start_reminders (discord_id, tournament_id, offset_sec, sent_at)
             VALUES (?, ?, 3600, NULL)'
        )->execute([$uid, $tid]);

        $this->assertSame(1, tcgPushDispatchTournamentStartReminders());
    }

    public function testOffsetsForUserMap(): void
    {
        $suffix = strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
        $host = 'tw_host_' . $suffix;
        $uid = 'tw_user_' . $suffix;
        $tid = 'TW' . $suffix;
        $this->ensureUser($host);
        $this->ensureUser($uid);
        $this->insertOpenTournament($tid, $host, time() + 7200);
        tcgDb()->prepare(
            'INSERT INTO tcg_tournament_start_reminders (discord_id, tournament_id, offset_sec, sent_at)
             VALUES (?, ?, 300, NULL), (?, ?, 1800, NULL)'
        )->execute([$uid, $tid, $uid, $tid]);
        $map = tcgPushTournamentStartOffsetsForUser($uid, [$tid]);
        $this->assertSame([300, 1800], $map[$tid] ?? []);
    }
}
