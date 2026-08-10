<?php

declare(strict_types=1);

namespace LLTCG\Tests\Booster;

use PHPUnit\Framework\TestCase;

final class BoosterSmokeTest extends TestCase
{
    private function loadBooster(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite extension required');
        }
        require_once dirname(__DIR__, 2) . '/booster.php';
    }

    public function testCatalogMatchesOfficialPackAndBoxSizes(): void
    {
        $this->loadBooster();
        $byId = [];
        foreach (tcgBoosterBoxes() as $box) {
            $byId[$box['id']] = tcgEnrichBoosterBoxPublic($box);
        }

        $this->assertSame(5, $byId['bp_vol1']['pack_size']);
        $this->assertSame(10, $byId['bp_vol1']['packs_per_box']);
        $this->assertSame(100, $byId['bp_vol1']['star_gems_pack_cost']);
        $this->assertSame(1000, $byId['bp_vol1']['star_gems_box_cost']);

        $this->assertSame(5, $byId['bp_mellow']['pack_size']);
        $this->assertSame(10, $byId['bp_mellow']['packs_per_box']);
        $this->assertSame(1000, $byId['bp_mellow']['star_gems_box_cost']);

        $this->assertSame(3, $byId['pb_niji']['pack_size']);
        $this->assertSame(20, $byId['pb_niji']['packs_per_box']);
        $this->assertSame(60, $byId['pb_niji']['star_gems_pack_cost']);
        $this->assertSame(1200, $byId['pb_niji']['star_gems_box_cost']);

        $this->assertSame(3, $byId['pb_superstar_duo']['pack_size']);
        $this->assertSame(20, $byId['pb_superstar_duo']['packs_per_box']);
        $this->assertSame(60, $byId['pb_superstar_duo']['star_gems_pack_cost']);
        $this->assertSame(1200, $byId['pb_superstar_duo']['star_gems_box_cost']);
    }

    public function testPbSuperstarDuoPackHasThreeCards(): void
    {
        $this->loadBooster();
        $cardsData = json_decode((string)file_get_contents(CARDS_FILE), true);
        $discordId = 'test_booster_' . bin2hex(random_bytes(4));
        tcgEnsureUser($discordId, ['username' => 'Booster Test']);
        $db = tcgDb();
        $db->prepare('UPDATE tcg_users SET star_gems = 5000, updated_at = ? WHERE discord_id = ?')
            ->execute([time(), $discordId]);

        $out = tcgOpenBoosterPack($discordId, 'pb_superstar_duo', $cardsData, 'gems');
        $this->assertSame('pack', $out['mode'] ?? '');
        $this->assertSame('pb_superstar_duo', $out['box']['id'] ?? '');
        $this->assertCount(3, $out['card_nos'] ?? []);
        $this->assertSame(60, $out['star_gems_spent'] ?? 0);
    }

    public function testPremiumBoosterNijiPackHasThreeCardsAndCostsSixty(): void
    {
        $this->loadBooster();
        $cardsData = json_decode((string)file_get_contents(CARDS_FILE), true);
        $discordId = 'test_booster_niji_' . bin2hex(random_bytes(4));
        tcgEnsureUser($discordId, ['username' => 'Booster Niji']);
        $db = tcgDb();
        $db->prepare('UPDATE tcg_users SET star_gems = 5000, updated_at = ? WHERE discord_id = ?')
            ->execute([time(), $discordId]);

        $out = tcgOpenBoosterPack($discordId, 'pb_niji', $cardsData, 'gems');
        $this->assertSame('pack', $out['mode'] ?? '');
        $this->assertSame('pb_niji', $out['box']['id'] ?? '');
        $this->assertCount(3, $out['card_nos'] ?? []);
        $this->assertSame(60, $out['star_gems_spent'] ?? 0);
    }

    public function testStandardBoosterPackHasFiveCardsAndCostsOneHundred(): void
    {
        $this->loadBooster();
        $cardsData = json_decode((string)file_get_contents(CARDS_FILE), true);
        $discordId = 'test_booster_bp_' . bin2hex(random_bytes(4));
        tcgEnsureUser($discordId, ['username' => 'Booster BP']);
        $db = tcgDb();
        $db->prepare('UPDATE tcg_users SET star_gems = 5000, updated_at = ? WHERE discord_id = ?')
            ->execute([time(), $discordId]);

        $out = tcgOpenBoosterPack($discordId, 'bp_vol1', $cardsData, 'gems');
        $this->assertSame('pack', $out['mode'] ?? '');
        $this->assertCount(5, $out['card_nos'] ?? []);
        $this->assertSame(100, $out['star_gems_spent'] ?? 0);
    }
}
