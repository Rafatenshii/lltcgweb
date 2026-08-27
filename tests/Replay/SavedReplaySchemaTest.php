<?php

declare(strict_types=1);

namespace LLTCG\Tests\Replay;

use PHPUnit\Framework\TestCase;

final class SavedReplaySchemaTest extends TestCase
{
    public function testSavedReplayTableStoresAccountPayload(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite extension required');
        }

        $uid = 'replay_schema_' . bin2hex(random_bytes(4));
        \tcgEnsureUser($uid, ['username' => 'Replay Tester']);
        $db = \tcgDb();
        $payload = json_encode([
                'schema_version' => \REPLAY_SCHEMA_VERSION,
            'meta' => [
                'saved_at' => gmdate('c'),
                'saver_player_id' => 'p1',
                'saver_name' => 'Replay Tester',
            ],
            'baseline' => ['players' => ['p1' => [], 'p2' => []]],
            'actions' => [],
            'frames' => [['players' => ['p1' => [], 'p2' => []]]],
        ]);

        $db->prepare('INSERT INTO tcg_replays (
                discord_id, room_id, saver_player_id, saver_name, opponent_name, winner, end_reason,
                turn, phase, action_count, duration_seconds, payload_json, saved_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([
                $uid, 'RPL001', 'p1', 'Replay Tester', 'CPU', 'p1', 'three_lives',
                3, 'main', 0, 0, $payload, time(),
            ]);

        $stmt = $db->prepare('SELECT room_id, saver_player_id, payload_json FROM tcg_replays WHERE discord_id = ?');
        $stmt->execute([$uid]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assertSame('RPL001', $row['room_id'] ?? null);
        $this->assertSame('p1', $row['saver_player_id'] ?? null);
        $this->assertSame(\REPLAY_SCHEMA_VERSION, json_decode((string)$row['payload_json'], true)['schema_version'] ?? null);
    }
}
