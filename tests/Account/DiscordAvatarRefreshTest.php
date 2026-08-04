<?php

declare(strict_types=1);

namespace LLTCG\Tests\Account;

use PHPUnit\Framework\TestCase;

final class DiscordAvatarRefreshTest extends TestCase
{
    private string $discordId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->discordId = 'avatar_refresh_' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        $db = tcgDb();
        $db->prepare('DELETE FROM tcg_rank WHERE discord_id = ?')->execute([$this->discordId]);
        $db->prepare('DELETE FROM tcg_daily_state WHERE discord_id = ?')->execute([$this->discordId]);
        $db->prepare('DELETE FROM tcg_users WHERE discord_id = ?')->execute([$this->discordId]);
        parent::tearDown();
    }

    public function testAvatarUrlRefreshesWhenUsernameUnchanged(): void
    {
        tcgEnsureUser($this->discordId, [
            'username' => 'SameHandle',
            'avatar_url' => 'https://cdn.discordapp.com/avatars/' . $this->discordId . '/oldhash.png?size=128',
        ]);
        $row = tcgEnsureUser($this->discordId, [
            'username' => 'SameHandle',
            'avatar_url' => 'https://cdn.discordapp.com/avatars/' . $this->discordId . '/newhash.png?size=128',
        ]);
        $this->assertStringContainsString('newhash', (string)($row['avatar_url'] ?? ''));
    }

    public function testAnimatedAvatarUsesGif(): void
    {
        $url = tcgDiscordAvatarUrl('123456789012345678', 'a_abcdef0123456789');
        $this->assertStringContainsString('.gif?', $url);
        $this->assertStringContainsString('a_abcdef0123456789', $url);
    }
}
