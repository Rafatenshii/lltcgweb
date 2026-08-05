<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/api.php';
require_once dirname(__DIR__, 2) . '/effects.php';

final class Bp7All2BladeHeartTest extends TestCase
{
    public function testAll2ResolvesToTwoWildHearts(): void
    {
        $live = [
            'card_no' => 'PL!N-bp7-030-L',
            'required_hearts' => [
                ['color' => 'green', 'count' => 1],
                ['color' => 'gray', 'count' => 1],
            ],
        ];
        $pool = ['green', 'red', 'blue', 'yellow'];
        $icons = getHeartIconsFromBladeHeart('all2', $pool, [$live]);
        $this->assertCount(2, $icons);
        foreach ($icons as $c) {
            $this->assertNotSame('', $c);
        }
    }

    public function testMemberBladeHeartCountTreatsAll2AsTwo(): void
    {
        $m = ['blade_hearts' => ['all2']];
        $this->assertSame(2, memberBladeHeartCount($m));
        $m2 = ['blade_hearts' => ['red', 'all2']];
        $this->assertSame(3, memberBladeHeartCount($m2));
    }
}
