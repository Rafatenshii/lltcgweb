<?php

declare(strict_types=1);

namespace LLTCG\Tests\Casual;

use PHPUnit\Framework\TestCase;

/**
 * Match-primary VPS has no Hostinger tcg_deck_presets rows.
 * Unranked create/join must accept inline preset lists from the client.
 */
final class AccountPresetInlineDeckTest extends TestCase
{
    private function starterLists(): array
    {
        $data = json_decode((string) file_get_contents((string) constant('CARDS_FILE')), true);
        $this->assertIsArray($data);
        $starter = $data['starter_decks']['nijigasaki'] ?? null;
        $this->assertIsArray($starter);
        $main = $starter['main_deck'] ?? [];
        $energy = $starter['energy_deck'] ?? [];
        $this->assertCount(60, $main);
        $this->assertCount(12, $energy);
        return [$main, $energy, $data];
    }

    public function testInlinePresetRejectsIllegalListsBeforeSqlite(): void
    {
        [, , $cards] = $this->starterLists();
        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/Preset deck is invalid/i');
        resolveAccountPresetDeckLists([
            'deck' => 'preset',
            'deck_slot' => 1,
            'main_deck' => ['LL-E-001-SD'],
            'energy_deck' => ['LL-E-001-SD'],
        ], $cards, 1);
    }

    public function testInlinePresetAcceptsLegalListsWithoutSqliteRow(): void
    {
        [$main, $energy, $cards] = $this->starterLists();
        try {
            $resolved = resolveAccountPresetDeckLists([
                'token' => 'offline-tests-have-no-auth',
                'deck' => 'preset',
                'deck_slot' => 1,
                'deck_label' => 'My Preset',
                'main_deck' => $main,
                'energy_deck' => $energy,
            ], $cards, 1);
            // Production auth may accept; offline auth throws 401 after validation.
            $this->assertSame('preset:1', $resolved['deck_choice']);
            $this->assertSame($main, $resolved['main_nos']);
            $this->assertSame($energy, $resolved['energy_nos']);
        } catch (\Exception $e) {
            $this->assertStringContainsStringIgnoringCase('Authentication', $e->getMessage());
            $this->assertStringNotContainsStringIgnoringCase('not found', $e->getMessage());
        }
    }
}
