<?php

declare(strict_types=1);

namespace LLTCG\Tests\Account;

use PHPUnit\Framework\TestCase;

/**
 * Opaque Discord Rich Presence action tokens (mint / redeem / expiry).
 */
final class PresenceActionsTest extends TestCase
{
    private string $uidOwner = '900000000000000801';
    private string $uidJoiner = '900000000000000802';

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite extension required');
        }
        $offlineAuth = dirname(__DIR__, 2) . '/llr_auth_offline.php';
        putenv('TCG_LLR_AUTH_FILE=' . $offlineAuth);
        if (!defined('TCG_ACCOUNT_LIB_ONLY')) {
            define('TCG_ACCOUNT_LIB_ONLY', true);
        }
        require_once dirname(__DIR__, 2) . '/account.php';
        tcgEnsureUser($this->uidOwner, ['username' => 'PresenceOwner']);
        tcgEnsureUser($this->uidJoiner, ['username' => 'PresenceJoiner']);
    }

    public function testMintAndPeekRankedQueueToken(): void
    {
        require_once dirname(__DIR__, 2) . '/matchmaking.php';
        require_once dirname(__DIR__, 2) . '/game_mode.php';
        tcgQueueJoin($this->uidOwner, TCG_GAME_MODE_STANDARD);

        $minted = tcgPresenceActionMint($this->uidOwner, TCG_PRESENCE_ACTION_RANKED_QUEUE, [
            'game_mode' => TCG_GAME_MODE_STANDARD,
        ]);
        $this->assertNotSame('', $minted['token'] ?? '');
        $this->assertStringContainsString('presence_action=', $minted['deep_link'] ?? '');

        $peek = tcgPresenceActionRedeem($minted['token'], false);
        $this->assertSame(TCG_PRESENCE_ACTION_RANKED_QUEUE, $peek['action_type']);
        $this->assertSame($this->uidOwner, $peek['owner_discord_id']);

        $validated = tcgPresenceActionValidateRankedQueue($this->uidOwner, $peek['payload']);
        $this->assertSame($this->uidOwner, $validated['challenge_discord_id']);
        $this->assertSame(TCG_GAME_MODE_STANDARD, $validated['game_mode']);

        tcgQueueLeave($this->uidOwner);
    }

    public function testRedeemConsumesTokenOnce(): void
    {
        require_once dirname(__DIR__, 2) . '/matchmaking.php';
        require_once dirname(__DIR__, 2) . '/game_mode.php';
        tcgQueueJoin($this->uidOwner, TCG_GAME_MODE_STANDARD);
        $minted = tcgPresenceActionMint($this->uidOwner, TCG_PRESENCE_ACTION_RANKED_QUEUE, [
            'game_mode' => TCG_GAME_MODE_STANDARD,
        ]);
        $first = tcgPresenceActionRedeem($minted['token'], true);
        $this->assertSame(TCG_PRESENCE_ACTION_RANKED_QUEUE, $first['action_type']);

        $this->expectException(\Exception::class);
        tcgPresenceActionRedeem($minted['token'], true);
    }

    public function testExpiredTokenRejected(): void
    {
        $db = tcgDb();
        tcgPresenceActionsEnsureTable($db);
        $token = tcgPresenceActionToken();
        $now = time();
        $db->prepare(
            'INSERT INTO tcg_presence_actions
                (token, discord_id, action_type, payload_json, created_at, expires_at, redeemed_at)
             VALUES (?, ?, ?, ?, ?, ?, NULL)'
        )->execute([
            $token,
            $this->uidOwner,
            TCG_PRESENCE_ACTION_RANKED_QUEUE,
            json_encode(['game_mode' => 'standard']),
            $now - 100,
            $now - 10,
        ]);

        $this->expectException(\Exception::class);
        tcgPresenceActionRedeem($token, true);
    }

    public function testValidateRankedQueueFailsWhenNotWaiting(): void
    {
        require_once dirname(__DIR__, 2) . '/matchmaking.php';
        tcgQueueLeave($this->uidOwner);
        $this->expectException(\Exception::class);
        tcgPresenceActionValidateRankedQueue($this->uidOwner, ['game_mode' => 'standard']);
    }

    protected function tearDown(): void
    {
        if (function_exists('tcgQueueLeave')) {
            try { tcgQueueLeave($this->uidOwner); } catch (\Throwable $e) { /* ignore */ }
        }
        if (function_exists('tcgDb')) {
            try {
                $db = tcgDb();
                $db->prepare('DELETE FROM tcg_presence_actions WHERE discord_id IN (?, ?)')
                    ->execute([$this->uidOwner, $this->uidJoiner]);
            } catch (\Throwable $e) { /* ignore */ }
        }
    }
}
