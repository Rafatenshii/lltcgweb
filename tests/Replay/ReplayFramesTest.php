<?php

declare(strict_types=1);

namespace LLTCG\Tests\Replay;

use PHPUnit\Framework\TestCase;

final class ReplayFramesTest extends TestCase
{
    private function fixtureV1(string $name = 'mulligan_keep.json'): array
    {
        $path = dirname(__DIR__) . '/fixtures/replays/' . $name;
        $replay = json_decode((string)file_get_contents($path), true);
        $this->assertIsArray($replay);
        $this->assertSame(1, intval($replay['schema_version'] ?? 0));
        unset($replay['expected']);
        return $replay;
    }

    public function testConvertV1ProducesFramesActionsPlusOne(): void
    {
        $v1 = $this->fixtureV1();
        $v2 = convertReplayPayloadToV2($v1);
        $this->assertSame(REPLAY_SCHEMA_VERSION, $v2['schema_version']);
        $this->assertCount(count($v1['actions']) + 1, $v2['frames']);
        $this->assertSame(count($v2['frames']), intval($v2['meta']['frame_count'] ?? 0));
        $this->assertArrayNotHasKey('token', $v2['frames'][0]['players']['p1'] ?? []);
    }

    public function testExportIsAlwaysV2(): void
    {
        $created = createRoom(['name' => 'Export P1', 'deck' => 'nijigasaki']);
        joinRoom([
            'room_id' => $created['room_id'],
            'name' => 'Export P2',
            'deck' => 'cpu',
            'cpu_difficulty' => 'easy',
            'first_player' => 'p1',
        ]);
        $state = loadGame($created['room_id']);
        $this->assertIsArray($state);
        $state = captureReplayBaselineIfNeeded($state);
        $state = applyAction($state, 'p1', 'mulligan', ['card_ids' => []]);
        $state = appendReplayAction($state, 'p1', 'mulligan', ['card_ids' => []]);
        $state = applyAction($state, 'p2', 'mulligan', ['card_ids' => []]);
        $state = appendReplayAction($state, 'p2', 'mulligan', ['card_ids' => []]);
        $payload = buildReplayExportPayload($state, 'p1');
        $this->assertSame(REPLAY_SCHEMA_VERSION, $payload['schema_version']);
        $this->assertArrayHasKey('frames', $payload);
        $this->assertCount(count($payload['actions']) + 1, $payload['frames']);
        $this->assertGreaterThan(0, count($payload['actions']));
    }

    public function testReplayGotoLoadsFramesWithoutResimAbort(): void
    {
        $v1 = $this->fixtureV1();
        $started = apiReplayStart(['replay' => $v1]);
        $this->assertTrue($started['ok'] ?? false);
        $roomId = $started['room_id'];
        $token = $started['player_token'];
        $total = intval($started['total_steps'] ?? 0);
        $this->assertGreaterThan(0, $total);

        $goto0 = apiReplayGoto([
            'room_id' => $roomId,
            'token' => $token,
            'step' => 0,
        ]);
        $this->assertSame(0, $goto0['step'] ?? -1);

        $gotoLast = apiReplayGoto([
            'room_id' => $roomId,
            'token' => $token,
            'step' => $total,
        ]);
        $this->assertSame($total, $gotoLast['step'] ?? -1);

        $mid = (int)floor($total / 2);
        $gotoMid = apiReplayGoto([
            'room_id' => $roomId,
            'token' => $token,
            'step' => $mid,
        ]);
        $this->assertSame($mid, $gotoMid['step'] ?? -1);

        $room = loadGame($roomId);
        $this->assertSame('replay_view', $room['mode'] ?? null);
        $this->assertArrayHasKey('frames', $room['replay'] ?? []);
        $this->assertSame($mid, intval($room['replay']['step'] ?? -1));
        $this->assertCount($total + 1, $room['replay']['frames']);
    }

    public function testGzipStorageRoundTrip(): void
    {
        $payload = [
            'schema_version' => REPLAY_SCHEMA_VERSION,
            'meta' => ['saver_player_id' => 'p1', 'saver_name' => 'G'],
            'baseline' => ['players' => ['p1' => [], 'p2' => []]],
            'actions' => [],
            'frames' => [['players' => ['p1' => [], 'p2' => []]]],
        ];
        // Force gzip path with a large string pad under meta.
        $payload['meta']['pad'] = str_repeat('x', REPLAY_STORAGE_GZIP_BYTES + 100);
        $encoded = replayPayloadEncodeForStorage($payload);
        $this->assertStringStartsWith('LLTCG_GZ1:', $encoded);
        $decoded = replayPayloadDecodeFromStorage($encoded);
        $this->assertSame(REPLAY_SCHEMA_VERSION, $decoded['schema_version'] ?? null);
        $this->assertSame(strlen($payload['meta']['pad']), strlen($decoded['meta']['pad'] ?? ''));
    }

    public function testGoldenFixturesStillValidateAsV1(): void
    {
        $replay = $this->fixtureV1();
        validateReplayFile($replay);
        $this->assertSame(1, intval($replay['schema_version'] ?? 0));
        $v2 = ensureReplayPayloadV2($replay);
        $this->assertSame(REPLAY_SCHEMA_VERSION, $v2['schema_version']);
        $this->assertCount(count($replay['actions']) + 1, $v2['frames']);
    }
}
