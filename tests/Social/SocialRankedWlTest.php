<?php

declare(strict_types=1);

namespace LLTCG\Tests\Social;

use PHPUnit\Framework\TestCase;

final class SocialRankedWlTest extends TestCase
{
    private string $discordId;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite extension required');
        }
        require_once dirname(__DIR__, 2) . '/social.php';
        $this->discordId = 'test_ranked_wl_' . bin2hex(random_bytes(4));
        tcgEnsureUser($this->discordId, ['username' => 'Ranked Wl Test']);
        $now = time();
        $db = tcgDb();
        $db->prepare(
            'INSERT INTO tcg_rank (discord_id, game_mode, rating, wins, losses, draws, games, updated_at)
             VALUES (?, ?, 1200, ?, ?, 0, ?, ?)
             ON CONFLICT(discord_id, game_mode) DO UPDATE SET
               wins = excluded.wins, losses = excluded.losses, games = excluded.games'
        )->execute([$this->discordId, 'standard', 265, 143, 408, $now]);
        $db->prepare(
            'INSERT INTO tcg_rank (discord_id, game_mode, rating, wins, losses, draws, games, updated_at)
             VALUES (?, ?, 1100, ?, ?, 0, ?, ?)'
        )->execute([$this->discordId, 'starters', 4, 6, 10, $now]);
    }

    public function testProfileRankedWlMatchesLeaderboardModeNotSum(): void
    {
        $standard = tcgSocialRankedWl($this->discordId, 'standard');
        $this->assertSame(265, $standard['wins']);
        $this->assertSame(143, $standard['losses']);
        $this->assertSame('standard', $standard['game_mode']);

        $starters = tcgSocialRankedWl($this->discordId, 'starters');
        $this->assertSame(4, $starters['wins']);
        $this->assertSame(6, $starters['losses']);

        $freeMapsToStandard = tcgSocialRankedWl($this->discordId, 'free');
        $this->assertSame(265, $freeMapsToStandard['wins']);
        $this->assertSame('standard', $freeMapsToStandard['game_mode']);
    }
}
