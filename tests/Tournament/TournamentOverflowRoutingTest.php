<?php

declare(strict_types=1);

namespace LLTCG\Tests\Tournament;

use PHPUnit\Framework\TestCase;

final class TournamentOverflowRoutingTest extends TestCase
{
    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/config/paths.php';
        if (!defined('TCG_INTERNAL_MATCH_SECRET')) {
            define('TCG_INTERNAL_MATCH_SECRET', 'phpunit-internal-match-secret');
        }
        if (!defined('TCG_API_LIB_ONLY')) {
            define('TCG_API_LIB_ONLY', true);
        }
        require_once dirname(__DIR__, 2) . '/api.php';
    }

    public function testSeedRankedRoomAcceptsTournamentMode(): void
    {
        $_SERVER['HTTP_X_TCG_INTERNAL_SECRET'] = TCG_INTERNAL_MATCH_SECRET;

        $roomId = 'T' . strtoupper(bin2hex(random_bytes(2)));
        $state = [
            'room_id' => $roomId,
            'mode' => 'tournament',
            'status' => 'waiting',
            'seq' => 1,
            'players' => [
                'p1' => ['token' => 'tok1', 'name' => 'A', 'discord_id' => '111'],
                'p2' => ['token' => 'tok2', 'name' => 'B', 'discord_id' => '222'],
            ],
            'tournament' => [
                'id' => 'ABC123',
                'match_id' => 'MATCH1',
            ],
        ];

        $res = apiSeedRankedRoom(['state' => $state]);
        $this->assertTrue($res['ok'] ?? false);
        $this->assertSame($roomId, $res['room_id'] ?? null);

        $loaded = loadGame($roomId);
        $this->assertSame('tournament', $loaded['mode'] ?? null);
        $this->assertSame('overflow', $loaded['tournament']['match_api'] ?? null);
    }

    public function testSeedRankedRoomSkipsWhenExistingSeqAhead(): void
    {
        $_SERVER['HTTP_X_TCG_INTERNAL_SECRET'] = TCG_INTERNAL_MATCH_SECRET;

        $roomId = 'T' . strtoupper(bin2hex(random_bytes(2)));
        $live = [
            'room_id' => $roomId,
            'mode' => 'tournament',
            'status' => 'active',
            'seq' => 42,
            'phase' => 'main_first',
            'players' => [
                'p1' => ['token' => 'tok1', 'name' => 'A', 'discord_id' => '111'],
                'p2' => ['token' => 'tok2', 'name' => 'B', 'discord_id' => '222'],
            ],
            'tournament' => [
                'id' => 'ABC123',
                'match_id' => 'MATCH1',
                'match_api' => 'overflow',
            ],
        ];
        saveGame($roomId, $live);

        $staleSeed = $live;
        $staleSeed['seq'] = 1;
        $staleSeed['status'] = 'waiting';
        $staleSeed['phase'] = null;

        $res = apiSeedRankedRoom(['state' => $staleSeed]);
        $this->assertTrue($res['ok'] ?? false);
        $this->assertSame('existing_ahead', $res['skipped'] ?? null);
        $this->assertSame(42, $res['seq'] ?? null);

        $loaded = loadGame($roomId);
        $this->assertSame(42, intval($loaded['seq'] ?? 0));
        $this->assertSame('active', $loaded['status'] ?? null);
        $this->assertSame('main_first', $loaded['phase'] ?? null);
    }
}
