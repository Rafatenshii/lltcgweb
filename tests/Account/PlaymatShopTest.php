<?php

declare(strict_types=1);

namespace LLTCG\Tests\Account;

use PHPUnit\Framework\TestCase;

final class PlaymatShopTest extends TestCase
{
    private string $discordId;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite extension required');
        }
        require_once dirname(__DIR__, 2) . '/coins.php';
        require_once dirname(__DIR__, 2) . '/playmats.php';
        require_once dirname(__DIR__, 2) . '/playmat_shop.php';
        require_once dirname(__DIR__, 2) . '/booster.php';
        $this->discordId = 'test_playmat_' . bin2hex(random_bytes(4));
        tcgEnsureUser($this->discordId, ['username' => 'Playmat Tester']);
    }

    private function catalogPlaymatId(): string
    {
        $items = tcgLoadPlaymatCatalog();
        $this->assertNotEmpty($items, 'playmats_catalog.json must have items');
        return (string)$items[0]['id'];
    }

    public function testShopCatalogOmitsEmptyOtherAndMixed(): void
    {
        // Catalog has only the five franchise units — shop skips empty Mixed/Other tabs.
        $catalog = tcgLoadPlaymatCatalog();
        $units = [];
        foreach ($catalog as $mat) {
            $units[tcgSleeveShopUnitForGroup($mat['group'])] = true;
        }
        $this->assertArrayNotHasKey('Other', $units);
        $this->assertArrayNotHasKey('Mixed', $units);
        foreach (["µ's", 'Aqours', 'Nijigasaki', 'Liella!', 'Hasunosora'] as $u) {
            $this->assertArrayHasKey($u, $units, "expected unit $u in playmat catalog");
        }
    }

    public function testNormalizeBrightnessClamp(): void
    {
        $this->assertEqualsWithDelta(1.0, tcgNormalizePlaymatBrightness(null), 0.0001);
        $this->assertEqualsWithDelta(1.0, tcgNormalizePlaymatBrightness('nope'), 0.0001);
        $this->assertEqualsWithDelta(0.35, tcgNormalizePlaymatBrightness(0.1), 0.0001);
        $this->assertEqualsWithDelta(1.0, tcgNormalizePlaymatBrightness(1.5), 0.0001);
        $this->assertEqualsWithDelta(0.5, tcgNormalizePlaymatBrightness(50), 0.0001);
        $this->assertEqualsWithDelta(0.8, tcgNormalizePlaymatBrightness(0.8), 0.0001);
    }

    public function testBuyCosts3000AndGrantsOnce(): void
    {
        $id = $this->catalogPlaymatId();
        tcgAddCoins($this->discordId, 10000);
        $before = tcgGetCoins($this->discordId);
        tcgDeductCoins($this->discordId, TCG_PLAYMAT_SHOP_PRICE);
        tcgGrantOwnedPlaymat($this->discordId, $id, 'shop');
        $this->assertTrue(tcgOwnsPlaymat($this->discordId, $id));
        $this->assertSame($before - 3000, tcgGetCoins($this->discordId));
        $this->assertContains($id, tcgOwnedPlaymatIds($this->discordId));

        // Second grant is idempotent (OR IGNORE) — no duplicate row.
        tcgGrantOwnedPlaymat($this->discordId, $id, 'shop');
        $this->assertSame(1, count(array_filter(
            tcgOwnedPlaymatIds($this->discordId),
            static fn($x) => $x === $id
        )));
    }

    public function testPresetPlaymatPersistAndClamp(): void
    {
        $id = $this->catalogPlaymatId();
        tcgAddCoins($this->discordId, 5000);
        tcgDeductCoins($this->discordId, TCG_PLAYMAT_SHOP_PRICE);
        tcgGrantOwnedPlaymat($this->discordId, $id, 'shop');

        $cards = json_decode((string)file_get_contents(TCG_CARDS_FILE), true) ?: ['cards' => []];
        $key = tcgStarterDeckKeys()[0] ?? 'nijigasaki';
        tcgSaveStarterPreset($this->discordId, $key, $cards, 1, true);

        $db = tcgDb();
        $db->prepare('UPDATE tcg_deck_presets SET playmat_id = ?, playmat_brightness = ? WHERE discord_id = ? AND slot = 1')
            ->execute([$id, 0.2, $this->discordId]);
        $stmt = $db->prepare('SELECT playmat_id, playmat_brightness FROM tcg_deck_presets WHERE discord_id = ? AND slot = 1');
        $stmt->execute([$this->discordId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame($id, tcgNormalizePlaymatId($row['playmat_id'] ?? ''));
        $this->assertSame(0.35, tcgNormalizePlaymatBrightness($row['playmat_brightness'] ?? 0));
    }
}
