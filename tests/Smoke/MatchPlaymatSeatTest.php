<?php

declare(strict_types=1);

namespace LLTCG\Tests\Smoke;

use PHPUnit\Framework\TestCase;

final class MatchPlaymatSeatTest extends TestCase
{
    public function testCreateJoinKeepsDistinctPlaymatsAndBrightness(): void
    {
        $created = createRoom([
            'name' => 'MatHost',
            'deck' => 'nijigasaki',
            'playmat_id' => 'br-rm-9',
            'playmat_brightness' => 0.7,
        ]);
        $roomId = (string)$created['room_id'];
        $p1Token = (string)$created['player_token'];

        $joined = joinRoom([
            'room_id' => $roomId,
            'name' => 'MatJoin',
            'deck' => 'liella',
            'playmat_id' => 'br-rmv2-1',
            'playmat_brightness' => 0.45,
        ]);
        $p2Token = (string)$joined['player_token'];

        $state = loadGame($roomId);
        $this->assertNotNull($state);
        $this->assertSame('br-rm-9', (string)($state['players']['p1']['playmat_id'] ?? ''));
        $this->assertSame('br-rmv2-1', (string)($state['players']['p2']['playmat_id'] ?? ''));
        $this->assertSame(0.7, (float)($state['players']['p1']['playmat_brightness'] ?? 0));
        $this->assertSame(0.45, (float)($state['players']['p2']['playmat_brightness'] ?? 0));

        $asP1 = filterStateForPlayer($state, $p1Token);
        $this->assertSame('br-rm-9', (string)($asP1['players']['p1']['playmat_id'] ?? ''));
        $this->assertSame('br-rmv2-1', (string)($asP1['players']['p2']['playmat_id'] ?? ''));

        $asP2 = filterStateForPlayer($state, $p2Token);
        $this->assertSame('br-rm-9', (string)($asP2['players']['p1']['playmat_id'] ?? ''));
        $this->assertSame('br-rmv2-1', (string)($asP2['players']['p2']['playmat_id'] ?? ''));
    }

    public function testRematchPreservesPlaymats(): void
    {
        $created = createRoom([
            'name' => 'RematchMatHost',
            'deck' => 'nijigasaki',
            'playmat_id' => 'mat-a',
            'playmat_brightness' => 0.9,
        ]);
        $roomId = (string)$created['room_id'];
        joinRoom([
            'room_id' => $roomId,
            'name' => 'RematchMatJoin',
            'deck' => 'liella',
            'playmat_id' => 'mat-b',
            'playmat_brightness' => 0.55,
        ]);

        $state = loadGame($roomId);
        $state['status'] = 'finished';
        $state['winner'] = 'p1';
        $state['rematch'] = ['p1' => true, 'p2' => true];
        $next = startRematchGame($state);

        $this->assertSame('mat-a', (string)($next['players']['p1']['playmat_id'] ?? ''));
        $this->assertSame('mat-b', (string)($next['players']['p2']['playmat_id'] ?? ''));
        $this->assertSame(0.9, (float)($next['players']['p1']['playmat_brightness'] ?? 0));
        $this->assertSame(0.55, (float)($next['players']['p2']['playmat_brightness'] ?? 0));
    }
}
