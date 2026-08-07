<?php

declare(strict_types=1);

namespace LLTCG\Tests\Ranked;

use PHPUnit\Framework\TestCase;

final class GameModeMatchmakingTest extends TestCase
{
    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite extension required');
        }
        require_once dirname(__DIR__, 2) . '/matchmaking.php';
        require_once dirname(__DIR__, 2) . '/casual_matchmaking.php';
        require_once dirname(__DIR__, 2) . '/game_mode.php';
        if (!defined('TCG_ACCOUNT_LIB_ONLY')) {
            define('TCG_ACCOUNT_LIB_ONLY', true);
        }
        require_once dirname(__DIR__, 2) . '/account.php';
    }

    public function testNormalizeGameModeAliases(): void
    {
        $this->assertSame(TCG_GAME_MODE_STANDARD, tcgNormalizeGameMode(''));
        $this->assertSame(TCG_GAME_MODE_STANDARD, tcgNormalizeGameMode('standard'));
        $this->assertSame(TCG_GAME_MODE_STARTERS, tcgNormalizeGameMode('starters'));
        $this->assertSame(TCG_GAME_MODE_STARTERS, tcgNormalizeGameMode('starter'));
        $this->assertSame(TCG_GAME_MODE_STARTERS, tcgNormalizeGameMode('starter_decks'));
    }

    public function testRankedStatusStatsGameModeHonorsGetWhenIdle(): void
    {
        $idle = ['status' => 'idle'];
        // GET-only client poll (accountGet) — body empty, mode in query.
        $this->assertSame(
            TCG_GAME_MODE_STARTERS,
            tcgRankedStatusStatsGameMode($idle, [], ['game_mode' => 'starters'])
        );
        $this->assertSame(
            TCG_GAME_MODE_STANDARD,
            tcgRankedStatusStatsGameMode($idle, [], ['game_mode' => 'standard'])
        );
        // Missing request → standard (previous buggy idle default).
        $this->assertSame(
            TCG_GAME_MODE_STANDARD,
            tcgRankedStatusStatsGameMode($idle, [], [])
        );
        // Searching uses queue row mode, not a stale UI select.
        $searching = ['status' => 'searching', 'game_mode' => TCG_GAME_MODE_STARTERS];
        $this->assertSame(
            TCG_GAME_MODE_STARTERS,
            tcgRankedStatusStatsGameMode($searching, [], ['game_mode' => 'standard'])
        );
    }

    public function testRankedQueueOpponentRequiresSameMode(): void
    {
        $a = 'gm_rank_a_' . bin2hex(random_bytes(3));
        $b = 'gm_rank_b_' . bin2hex(random_bytes(3));
        $c = 'gm_rank_c_' . bin2hex(random_bytes(3));
        tcgEnsureUser($a, ['username' => 'A']);
        tcgEnsureUser($b, ['username' => 'B']);
        tcgEnsureUser($c, ['username' => 'C']);

        tcgQueueJoin($a, TCG_GAME_MODE_STANDARD);
        tcgQueueJoin($b, TCG_GAME_MODE_STARTERS);
        $this->assertNull(tcgFindQueueOpponent($a, 1000, TCG_GAME_MODE_STANDARD));

        tcgQueueJoin($c, TCG_GAME_MODE_STANDARD);
        $opp = tcgFindQueueOpponent($a, 1000, TCG_GAME_MODE_STANDARD);
        $this->assertNotNull($opp);
        $this->assertSame($c, $opp['discord_id']);
        $this->assertSame(TCG_GAME_MODE_STANDARD, tcgNormalizeGameMode($opp['game_mode'] ?? ''));
    }

    public function testApplyRankResultUpdatesOnlyThatMode(): void
    {
        $winner = 'gm_elo_w_' . bin2hex(random_bytes(3));
        $loser = 'gm_elo_l_' . bin2hex(random_bytes(3));
        tcgEnsureUser($winner, ['username' => 'W']);
        tcgEnsureUser($loser, ['username' => 'L']);

        $stdBefore = tcgRankRow($winner, TCG_GAME_MODE_STANDARD);
        tcgApplyRankResult($winner, $loser, false, TCG_GAME_MODE_STARTERS);

        $stdAfter = tcgRankRow($winner, TCG_GAME_MODE_STANDARD);
        $starters = tcgRankRow($winner, TCG_GAME_MODE_STARTERS);
        $this->assertSame(intval($stdBefore['rating']), intval($stdAfter['rating']));
        $this->assertSame(0, intval($stdAfter['games']));
        $this->assertSame(1, intval($starters['wins']));
        $this->assertSame(1, intval($starters['games']));
        $this->assertGreaterThan(1000, intval($starters['rating']));
    }

    public function testPublicLeaderboardFiltersByGameMode(): void
    {
        $stdA = 'gm_lb_sa_' . bin2hex(random_bytes(3));
        $stdB = 'gm_lb_sb_' . bin2hex(random_bytes(3));
        $stA = 'gm_lb_ta_' . bin2hex(random_bytes(3));
        $stB = 'gm_lb_tb_' . bin2hex(random_bytes(3));
        tcgEnsureUser($stdA, ['username' => 'StdA']);
        tcgEnsureUser($stdB, ['username' => 'StdB']);
        tcgEnsureUser($stA, ['username' => 'StA']);
        tcgEnsureUser($stB, ['username' => 'StB']);

        tcgApplyRankResult($stdA, $stdB, false, TCG_GAME_MODE_STANDARD);
        tcgApplyRankResult($stA, $stB, false, TCG_GAME_MODE_STARTERS);

        $stdBoard = tcgApiPublicLeaderboard(['game_mode' => 'standard']);
        $stBoard = tcgApiPublicLeaderboard(['game_mode' => 'starters']);

        $stdIds = array_column($stdBoard['leaderboard'], 'user_id');
        $stIds = array_column($stBoard['leaderboard'], 'user_id');
        $this->assertContains($stdA, $stdIds);
        $this->assertNotContains($stA, $stdIds);
        $this->assertContains($stA, $stIds);
        $this->assertNotContains($stdA, $stIds);
        $this->assertSame(TCG_GAME_MODE_STANDARD, $stdBoard['game_mode']);
        $this->assertSame(TCG_GAME_MODE_STARTERS, $stBoard['game_mode']);
    }

    public function testCasualOpponentRequiresSameMode(): void
    {
        $db = tcgDb();
        $now = time();
        $k1 = 'cq_' . bin2hex(random_bytes(4));
        $k2 = 'cq_' . bin2hex(random_bytes(4));
        $k3 = 'cq_' . bin2hex(random_bytes(4));
        $body = json_encode(['name' => 'P', 'deck' => 'nijigasaki', 'game_mode' => 'standard'], JSON_UNESCAPED_UNICODE);

        $db->prepare('INSERT INTO tcg_casual_queue (queue_key, discord_id, player_name, join_body, joined_at, game_mode)
            VALUES (?, NULL, ?, ?, ?, ?)')
            ->execute([$k1, 'P1', $body, $now, TCG_GAME_MODE_STANDARD]);
        $db->prepare('INSERT INTO tcg_casual_queue (queue_key, discord_id, player_name, join_body, joined_at, game_mode)
            VALUES (?, NULL, ?, ?, ?, ?)')
            ->execute([$k2, 'P2', $body, $now + 1, TCG_GAME_MODE_STARTERS]);

        $this->assertNull(tcgFindCasualOpponent($k1, TCG_GAME_MODE_STANDARD));

        $db->prepare('INSERT INTO tcg_casual_queue (queue_key, discord_id, player_name, join_body, joined_at, game_mode)
            VALUES (?, NULL, ?, ?, ?, ?)')
            ->execute([$k3, 'P3', $body, $now + 2, TCG_GAME_MODE_STANDARD]);
        $opp = tcgFindCasualOpponent($k1, TCG_GAME_MODE_STANDARD);
        $this->assertNotNull($opp);
        $this->assertSame($k3, $opp['queue_key']);
    }

    public function testCasualStartersModeRejectsPresetDeck(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Starter decks only mode requires a starter deck');
        tcgValidateCasualStartersModeDeck(
            ['deck' => 'preset', 'deck_slot' => 1],
            null,
            ['cards' => []]
        );
    }

    public function testCasualQueueStatsArePerMode(): void
    {
        $db = tcgDb();
        $now = time();
        $kStd = 'cq_stats_std_' . bin2hex(random_bytes(3));
        $kSt = 'cq_stats_st_' . bin2hex(random_bytes(3));
        $body = json_encode(['name' => 'P', 'deck' => 'nijigasaki'], JSON_UNESCAPED_UNICODE);
        $db->prepare('INSERT INTO tcg_casual_queue (queue_key, discord_id, player_name, join_body, joined_at, game_mode)
            VALUES (?, NULL, ?, ?, ?, ?)')
            ->execute([$kStd, 'StdWait', $body, $now, TCG_GAME_MODE_STANDARD]);
        $db->prepare('INSERT INTO tcg_casual_queue (queue_key, discord_id, player_name, join_body, joined_at, game_mode)
            VALUES (?, NULL, ?, ?, ?, ?)')
            ->execute([$kSt, 'StWait', $body, $now, TCG_GAME_MODE_STARTERS]);

        // Bypass short cache by using unique mode filter counts directly.
        $stmt = $db->prepare('SELECT COUNT(*) FROM tcg_casual_queue WHERE game_mode = ?');
        $stmt->execute([TCG_GAME_MODE_STANDARD]);
        $stdWaiting = (int)$stmt->fetchColumn();
        $stmt->execute([TCG_GAME_MODE_STARTERS]);
        $stWaiting = (int)$stmt->fetchColumn();
        $this->assertGreaterThanOrEqual(1, $stdWaiting);
        $this->assertGreaterThanOrEqual(1, $stWaiting);

        $std = tcgCasualQueuePublicStats(TCG_GAME_MODE_STANDARD);
        $st = tcgCasualQueuePublicStats(TCG_GAME_MODE_STARTERS);
        $this->assertSame(TCG_GAME_MODE_STANDARD, $std['game_mode']);
        $this->assertSame(TCG_GAME_MODE_STARTERS, $st['game_mode']);
        $this->assertSame($stdWaiting, $std['waiting']);
        $this->assertSame($stWaiting, $st['waiting']);
    }
}
