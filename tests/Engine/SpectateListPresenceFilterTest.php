<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * spectate_list used to loadGame() every games/*.json, so a host with thousands of
 * dead rooms answered in ~30s. Hostinger's ranked hub calls it for the "in ranked
 * games" number, which pushed ranked_status past the client's 12s budget
 * ("Request timed out"). Rooms without fresh presence can never be spectatable,
 * so they must be skipped before the state load, and listed rooms must report how
 * many seats are actually polling.
 */
final class SpectateListPresenceFilterTest extends TestCase
{
    /** @var list<string> */
    private array $rooms = [];

    protected function tearDown(): void
    {
        foreach ($this->rooms as $roomId) {
            @unlink(GAMES_DIR . $roomId . '.json');
            @unlink(GAMES_DIR . 'presence_' . $roomId . '.json');
        }
        $this->rooms = [];
        @unlink(tcgSpectateListCacheFile('ranked'));
    }

    /** @param array<string,int> $presence token => last poll timestamp */
    private function writeRoom(string $roomId, array $presence): void
    {
        $this->rooms[] = $roomId;
        $state = [
            'room_id' => $roomId,
            'mode' => 'ranked',
            'status' => 'playing',
            'turn' => 3,
            'seq' => 12,
            'phase' => 'main',
            'game_mode' => 'standard',
            'players' => [
                'p1' => ['name' => 'Alice', 'token' => 'tok_p1_' . $roomId, 'discord_id' => 'd1_' . $roomId],
                'p2' => ['name' => 'Bob', 'token' => 'tok_p2_' . $roomId, 'discord_id' => 'd2_' . $roomId],
            ],
        ];
        file_put_contents(GAMES_DIR . $roomId . '.json', json_encode($state));
        if ($presence !== []) {
            file_put_contents(GAMES_DIR . 'presence_' . $roomId . '.json', json_encode($presence));
        }
    }

    private function rankedList(): array
    {
        @unlink(tcgSpectateListCacheFile('ranked'));
        return tcgListSpectatableMatches('ranked');
    }

    public function testRoomWithoutPresenceFileIsSkippedBeforeStateLoad(): void
    {
        $this->writeRoom('DEADROOM', []);

        $this->assertFalse(tcgSpectateRoomHasFreshPresence('DEADROOM'));
        $this->assertSame([], $this->rankedList());
    }

    public function testStalePresenceIsSkipped(): void
    {
        $old = time() - 3600;
        $this->writeRoom('STALEROOM', ['tok_p1_STALEROOM' => $old, 'tok_p2_STALEROOM' => $old]);
        touch(GAMES_DIR . 'presence_STALEROOM.json', $old);

        $this->assertFalse(tcgSpectateRoomHasFreshPresence('STALEROOM'));
        $this->assertSame([], $this->rankedList());
    }

    public function testLiveRoomIsListedWithBothSeatsCounted(): void
    {
        $now = time();
        $this->writeRoom('LIVEROOM', ['tok_p1_LIVEROOM' => $now, 'tok_p2_LIVEROOM' => $now]);

        $matches = $this->rankedList();
        $this->assertCount(1, $matches);
        $this->assertSame('LIVEROOM', $matches[0]['room_id']);
        $this->assertSame(2, $matches[0]['live_players']);
    }

    public function testDisconnectedSeatIsNotCountedAsInGame(): void
    {
        $now = time();
        $this->writeRoom('HALFROOM', [
            'tok_p1_HALFROOM' => $now,
            'tok_p2_HALFROOM' => $now - 3600,
        ]);

        $matches = $this->rankedList();
        $this->assertCount(1, $matches, 'one live seat still makes the match spectatable');
        $this->assertSame(1, $matches[0]['live_players']);
    }

    public function testListIsCachedBrieflyForHubPolls(): void
    {
        $now = time();
        $this->writeRoom('CACHEROOM', ['tok_p1_CACHEROOM' => $now, 'tok_p2_CACHEROOM' => $now]);

        $this->assertCount(1, $this->rankedList());
        // Room disappears, but the cache window still serves the previous scan.
        @unlink(GAMES_DIR . 'presence_CACHEROOM.json');
        $this->assertCount(1, tcgListSpectatableMatches('ranked'));
    }
}
