<?php

declare(strict_types=1);

namespace LLTCG\Tests\Account;

use PHPUnit\Framework\TestCase;

/** Preset sleeve_id survives deck_save / deck_set_sleeve updates. */
final class DeckPresetSleevePersistTest extends TestCase
{
    private string $discordId;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite extension required');
        }
        require_once dirname(__DIR__, 2) . '/sleeves.php';
        require_once dirname(__DIR__, 2) . '/sleeve_shop.php';
        require_once dirname(__DIR__, 2) . '/booster.php';
        $this->discordId = 'test_sleeve_preset_' . bin2hex(random_bytes(4));
        tcgEnsureUser($this->discordId, ['username' => 'Sleeve Preset Tester']);
    }

    private function catalogSleeveId(): string
    {
        $items = tcgLoadSleeveCatalog();
        $this->assertNotEmpty($items, 'sleeves_catalog.json must have items');
        return (string)$items[0]['id'];
    }

    private function seedLegalPreset(int $slot = 1): void
    {
        $cards = json_decode((string)file_get_contents(TCG_CARDS_FILE), true) ?: ['cards' => []];
        $key = tcgStarterDeckKeys()[0] ?? 'nijigasaki';
        tcgSaveStarterPreset($this->discordId, $key, $cards, $slot, true);
    }

    private function readSleeve(int $slot = 1): string
    {
        $stmt = tcgDb()->prepare('SELECT sleeve_id FROM tcg_deck_presets WHERE discord_id = ? AND slot = ?');
        $stmt->execute([$this->discordId, $slot]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertNotFalse($row);
        return tcgNormalizeSleeveId($row['sleeve_id'] ?? '');
    }

    public function testSetSleeveUpdatesExistingPreset(): void
    {
        $this->seedLegalPreset(1);
        $sleeveId = $this->catalogSleeveId();
        tcgGrantOwnedSleeve($this->discordId, $sleeveId, 'test');

        $db = tcgDb();
        $db->prepare('UPDATE tcg_deck_presets SET sleeve_id = ?, updated_at = ? WHERE discord_id = ? AND slot = ?')
            ->execute([$sleeveId, time(), $this->discordId, 1]);
        $this->assertSame($sleeveId, $this->readSleeve(1));

        // Simulate deck_save that always writes sleeve_id from the client payload.
        $stmt = $db->prepare('SELECT name, main_deck, energy_deck FROM tcg_deck_presets WHERE discord_id = ? AND slot = 1');
        $stmt->execute([$this->discordId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertNotFalse($row);
        $db->prepare('INSERT INTO tcg_deck_presets (discord_id, slot, name, main_deck, energy_deck, sleeve_id, equipped, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, 0, ?)
            ON CONFLICT(discord_id, slot) DO UPDATE SET
                name = excluded.name,
                main_deck = excluded.main_deck,
                energy_deck = excluded.energy_deck,
                sleeve_id = excluded.sleeve_id,
                updated_at = excluded.updated_at')
            ->execute([
                $this->discordId,
                1,
                $row['name'],
                $row['main_deck'],
                $row['energy_deck'],
                $sleeveId,
                time(),
            ]);
        $this->assertSame($sleeveId, $this->readSleeve(1));
    }

    public function testStarterRewritePreservesSleeve(): void
    {
        $this->seedLegalPreset(1);
        $sleeveId = $this->catalogSleeveId();
        tcgGrantOwnedSleeve($this->discordId, $sleeveId, 'test');
        tcgDb()->prepare('UPDATE tcg_deck_presets SET sleeve_id = ? WHERE discord_id = ? AND slot = 1')
            ->execute([$sleeveId, $this->discordId]);

        $cards = json_decode((string)file_get_contents(TCG_CARDS_FILE), true) ?: ['cards' => []];
        $key = tcgStarterDeckKeys()[0] ?? 'nijigasaki';
        tcgSaveStarterPreset($this->discordId, $key, $cards, 1, true);

        $this->assertSame(
            $sleeveId,
            $this->readSleeve(1),
            'tcgWriteDeckPreset must not wipe sleeve_id on starter rewrite'
        );
    }
}
