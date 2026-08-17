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
        require_once dirname(__DIR__, 2) . '/experiment_decks.php';
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
        $this->assertSame(TCG_GAME_MODE_RANDOMIZED, tcgNormalizeGameMode('randomized'));
        $this->assertSame(TCG_GAME_MODE_RANDOMIZED, tcgNormalizeGameMode('random_decks'));
        $this->assertSame(TCG_GAME_MODE_RANDOMIZED, tcgNormalizeGameMode('randomized_decks'));
        $this->assertSame(TCG_GAME_MODE_RANDOMIZED, tcgNormalizeRankedGameMode('randomized'));
        $this->assertTrue(tcgIsRandomizedGameMode('random-decks'));
        $this->assertContains(TCG_GAME_MODE_RANDOMIZED, tcgRankedGameModeIds());
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
        $this->assertSame(
            TCG_GAME_MODE_RANDOMIZED,
            tcgRankedStatusStatsGameMode($idle, [], ['game_mode' => 'randomized'])
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
        $d = 'gm_rank_d_' . bin2hex(random_bytes(3));
        tcgEnsureUser($a, ['username' => 'A']);
        tcgEnsureUser($b, ['username' => 'B']);
        tcgEnsureUser($c, ['username' => 'C']);
        tcgEnsureUser($d, ['username' => 'D']);

        tcgQueueJoin($a, TCG_GAME_MODE_STANDARD);
        tcgQueueJoin($b, TCG_GAME_MODE_STARTERS);
        $this->assertNull(tcgFindQueueOpponent($a, 1000, TCG_GAME_MODE_STANDARD));

        tcgQueueJoin($c, TCG_GAME_MODE_STANDARD);
        $opp = tcgFindQueueOpponent($a, 1000, TCG_GAME_MODE_STANDARD);
        $this->assertNotNull($opp);
        $this->assertSame($c, $opp['discord_id']);
        $this->assertSame(TCG_GAME_MODE_STANDARD, tcgNormalizeGameMode($opp['game_mode'] ?? ''));

        tcgQueueJoin($d, TCG_GAME_MODE_RANDOMIZED);
        // Alone in randomized — no partner yet (standard queue must not match).
        $this->assertNull(tcgFindQueueOpponent($d, 1000, TCG_GAME_MODE_RANDOMIZED));
        tcgQueueJoin($b, TCG_GAME_MODE_RANDOMIZED);
        $randOpp = tcgFindQueueOpponent($d, 1000, TCG_GAME_MODE_RANDOMIZED);
        $this->assertNotNull($randOpp);
        $this->assertSame($b, $randOpp['discord_id']);
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

        tcgApplyRankResult($winner, $loser, false, TCG_GAME_MODE_RANDOMIZED);
        $rand = tcgRankRow($winner, TCG_GAME_MODE_RANDOMIZED);
        $stdAfterRand = tcgRankRow($winner, TCG_GAME_MODE_STANDARD);
        $this->assertSame(1, intval($rand['wins']));
        $this->assertSame(intval($stdAfter['rating']), intval($stdAfterRand['rating']));
    }

    public function testPublicLeaderboardFiltersByGameMode(): void
    {
        $stdA = 'gm_lb_sa_' . bin2hex(random_bytes(3));
        $stdB = 'gm_lb_sb_' . bin2hex(random_bytes(3));
        $stA = 'gm_lb_ta_' . bin2hex(random_bytes(3));
        $stB = 'gm_lb_tb_' . bin2hex(random_bytes(3));
        $randA = 'gm_lb_ra_' . bin2hex(random_bytes(3));
        $randB = 'gm_lb_rb_' . bin2hex(random_bytes(3));
        tcgEnsureUser($stdA, ['username' => 'StdA']);
        tcgEnsureUser($stdB, ['username' => 'StdB']);
        tcgEnsureUser($stA, ['username' => 'StA']);
        tcgEnsureUser($stB, ['username' => 'StB']);
        tcgEnsureUser($randA, ['username' => 'RandA']);
        tcgEnsureUser($randB, ['username' => 'RandB']);

        tcgApplyRankResult($stdA, $stdB, false, TCG_GAME_MODE_STANDARD);
        tcgApplyRankResult($stA, $stB, false, TCG_GAME_MODE_STARTERS);
        tcgApplyRankResult($randA, $randB, false, TCG_GAME_MODE_RANDOMIZED);

        $stdBoard = tcgApiPublicLeaderboard(['game_mode' => 'standard']);
        $stBoard = tcgApiPublicLeaderboard(['game_mode' => 'starters']);
        $randBoard = tcgApiPublicLeaderboard(['game_mode' => 'randomized']);

        $stdIds = array_column($stdBoard['leaderboard'], 'user_id');
        $stIds = array_column($stBoard['leaderboard'], 'user_id');
        $randIds = array_column($randBoard['leaderboard'], 'user_id');
        $this->assertContains($stdA, $stdIds);
        $this->assertNotContains($stA, $stdIds);
        $this->assertNotContains($randA, $stdIds);
        $this->assertContains($stA, $stIds);
        $this->assertNotContains($stdA, $stIds);
        $this->assertContains($randA, $randIds);
        $this->assertNotContains($stdA, $randIds);
        $this->assertSame(TCG_GAME_MODE_STANDARD, $stdBoard['game_mode']);
        $this->assertSame(TCG_GAME_MODE_STARTERS, $stBoard['game_mode']);
        $this->assertSame(TCG_GAME_MODE_RANDOMIZED, $randBoard['game_mode']);
    }

    public function testCasualOpponentRequiresSameMode(): void
    {
        $db = tcgDb();
        $now = time();
        $k1 = 'cq_' . bin2hex(random_bytes(4));
        $k2 = 'cq_' . bin2hex(random_bytes(4));
        $k3 = 'cq_' . bin2hex(random_bytes(4));
        $k4 = 'cq_' . bin2hex(random_bytes(4));
        $k5 = 'cq_' . bin2hex(random_bytes(4));
        $body = json_encode(['name' => 'P', 'deck' => 'nijigasaki', 'game_mode' => 'standard'], JSON_UNESCAPED_UNICODE);
        $randBody = json_encode(['name' => 'P', 'deck' => 'random', 'game_mode' => 'randomized'], JSON_UNESCAPED_UNICODE);

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

        $db->prepare('INSERT INTO tcg_casual_queue (queue_key, discord_id, player_name, join_body, joined_at, game_mode)
            VALUES (?, NULL, ?, ?, ?, ?)')
            ->execute([$k4, 'P4', $randBody, $now + 3, TCG_GAME_MODE_RANDOMIZED]);
        $this->assertNull(tcgFindCasualOpponent($k4, TCG_GAME_MODE_RANDOMIZED));
        $db->prepare('INSERT INTO tcg_casual_queue (queue_key, discord_id, player_name, join_body, joined_at, game_mode)
            VALUES (?, NULL, ?, ?, ?, ?)')
            ->execute([$k5, 'P5', $randBody, $now + 4, TCG_GAME_MODE_RANDOMIZED]);
        $randOpp = tcgFindCasualOpponent($k4, TCG_GAME_MODE_RANDOMIZED);
        $this->assertNotNull($randOpp);
        $this->assertSame($k5, $randOpp['queue_key']);
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

    public function testCasualFreeModeAllowsAccountPreset(): void
    {
        tcgValidateCasualFreeModeDeck(['deck' => 'preset', 'deck_slot' => 2]);
        tcgValidateCasualFreeModeDeck(['deck' => 'preset:3']);
        $this->assertTrue(true);
    }

    public function testCasualFreeModeRejectsStarterDeck(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Free requires a Deck Experiment deck or a saved account deck');
        tcgValidateCasualFreeModeDeck(['deck' => 'nijigasaki']);
    }

    public function testUnrankedFreeModeAllowsPresetOrExperiment(): void
    {
        tcgAssertUnrankedDeckForGameMode([
            'game_mode' => TCG_GAME_MODE_FREE,
            'deck' => 'preset',
            'deck_slot' => 1,
        ]);
        tcgAssertUnrankedDeckForGameMode([
            'game_mode' => TCG_GAME_MODE_FREE,
            'deck' => 'experiment:ABCDEFGH',
        ]);
        $this->assertTrue(true);
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
        $this->assertContains(TCG_GAME_MODE_STARTERS, $std['other_modes_waiting'] ?? []);
        $this->assertContains(TCG_GAME_MODE_STANDARD, $st['other_modes_waiting'] ?? []);
        $this->assertNotContains(TCG_GAME_MODE_STANDARD, $std['other_modes_waiting'] ?? []);
    }

    public function testFindQueueOpponentSkipsPlayersWithPendingMatch(): void
    {
        $a = 'gm_pend_a_' . bin2hex(random_bytes(3));
        $b = 'gm_pend_b_' . bin2hex(random_bytes(3));
        $c = 'gm_pend_c_' . bin2hex(random_bytes(3));
        tcgEnsureUser($a, ['username' => 'A']);
        tcgEnsureUser($b, ['username' => 'B']);
        tcgEnsureUser($c, ['username' => 'C']);

        tcgQueueJoin($a, TCG_GAME_MODE_STANDARD);
        tcgQueueJoin($b, TCG_GAME_MODE_STANDARD);
        tcgQueueJoin($c, TCG_GAME_MODE_STANDARD);

        $db = tcgDb();
        $db->prepare(
            'INSERT INTO tcg_ranked_matches
            (match_id, room_id, p1_id, p2_id, p1_token, p2_token, status, created_at, game_mode)
            VALUES (?, ?, ?, ?, ?, ?, "pending", ?, ?)'
        )->execute([
            'M' . bin2hex(random_bytes(4)),
            'R' . bin2hex(random_bytes(2)),
            $b,
            'other_' . bin2hex(random_bytes(2)),
            't1',
            't2',
            time(),
            TCG_GAME_MODE_STANDARD,
        ]);

        $this->assertTrue(tcgDiscordIdHasPendingRankedMatch($b));
        $opp = tcgFindQueueOpponent($a, 1000, TCG_GAME_MODE_STANDARD);
        $this->assertNotNull($opp);
        $this->assertNotSame($b, $opp['discord_id'], 'Must not pair a player who already has a pending match');
        // Dirty seat kicked from queue so hub waiting count stays honest.
        $stmt = $db->prepare('SELECT 1 FROM tcg_match_queue WHERE discord_id = ?');
        $stmt->execute([$b]);
        $this->assertFalse((bool)$stmt->fetchColumn());
    }

    public function testClaimRankedQueuePairRejectsPendingPlayer(): void
    {
        $a = 'gm_claim_a_' . bin2hex(random_bytes(3));
        $b = 'gm_claim_b_' . bin2hex(random_bytes(3));
        tcgEnsureUser($a, ['username' => 'A']);
        tcgEnsureUser($b, ['username' => 'B']);
        tcgQueueJoin($a, TCG_GAME_MODE_STANDARD);
        tcgQueueJoin($b, TCG_GAME_MODE_STANDARD);
        $db = tcgDb();
        $db->prepare(
            'INSERT INTO tcg_ranked_matches
            (match_id, room_id, p1_id, p2_id, p1_token, p2_token, status, created_at, game_mode)
            VALUES (?, ?, ?, ?, ?, ?, "pending", ?, ?)'
        )->execute([
            'M' . bin2hex(random_bytes(4)),
            'R' . bin2hex(random_bytes(2)),
            $a,
            'other_' . bin2hex(random_bytes(2)),
            't1',
            't2',
            time(),
            TCG_GAME_MODE_STANDARD,
        ]);
        $this->assertFalse(tcgClaimRankedQueuePair($a, $b, TCG_GAME_MODE_STANDARD));
        // Successful claim when neither has a pending seat.
        $db->prepare('DELETE FROM tcg_ranked_matches WHERE p1_id = ? OR p2_id = ?')->execute([$a, $a]);
        $this->assertTrue(tcgClaimRankedQueuePair($a, $b, TCG_GAME_MODE_STANDARD));
        $stmt = $db->prepare('SELECT COUNT(*) FROM tcg_match_queue WHERE discord_id IN (?, ?)');
        $stmt->execute([$a, $b]);
        $this->assertSame(0, (int)$stmt->fetchColumn());
    }
}
