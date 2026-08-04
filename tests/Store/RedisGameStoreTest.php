<?php

declare(strict_types=1);

namespace LLTCG\Tests\Store;

use LLTCG\Game\Store\RedisClient;
use LLTCG\Game\Store\RedisGameStore;
use PHPUnit\Framework\TestCase;

final class RedisGameStoreTest extends TestCase
{
    private ?RedisGameStore $store = null;
    private string $roomId = '';

    protected function setUp(): void
    {
        $url = getenv('TCG_REDIS_URL');
        if ($url === false || trim((string)$url) === '') {
            $this->markTestSkipped('TCG_REDIS_URL not set');
        }
        try {
            $client = RedisClient::fromUrl((string)$url);
            $client->set('lltcg:phpunit:ping', '1', 10);
            if ($client->get('lltcg:phpunit:ping') !== '1') {
                $this->markTestSkipped('Redis not reachable at TCG_REDIS_URL');
            }
            $this->roomId = 'T' . strtoupper(bin2hex(random_bytes(3)));
            $this->store = new RedisGameStore(
                $client,
                'lltcg:phpunit:room:',
                120,
                null
            );
        } catch (\Throwable $e) {
            $this->markTestSkipped('Redis unavailable: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if ($this->store && $this->roomId !== '') {
            try {
                $this->store->delete($this->roomId);
            } catch (\Throwable $e) {
                // ignore cleanup failures
            }
        }
    }

    public function testCreateActionReloadFromRedis(): void
    {
        $store = $this->store;
        $this->assertNotNull($store);
        $room = $this->roomId;

        $store->save($room, [
            'room_id' => $room,
            'seq' => 1,
            'phase' => 'main_first',
            'players' => ['p1' => ['name' => 'A'], 'p2' => ['name' => 'B']],
        ]);

        $store->withLock($room, function () use ($store, $room) {
            $s = $store->load($room);
            $this->assertNotNull($s);
            $s['seq'] = intval($s['seq'] ?? 0) + 1;
            $s['phase'] = 'live_set';
            $store->save($room, $s);
            return true;
        });

        $loaded = $store->load($room);
        $this->assertNotNull($loaded);
        $this->assertSame(2, $loaded['seq'] ?? null);
        $this->assertSame('live_set', $loaded['phase'] ?? null);
    }

    public function testListRoomIdsIncludesSavedRoomExcludesLocks(): void
    {
        $store = $this->store;
        $this->assertNotNull($store);
        $room = $this->roomId;
        $store->save($room, ['room_id' => $room, 'seq' => 1]);
        $ids = $store->listRoomIds();
        $this->assertContains($room, $ids);
        foreach ($ids as $id) {
            $this->assertStringNotContainsString('lock:', $id);
        }
    }
}
