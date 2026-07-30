<?php
/**
 * Regression: dual-stale human seats must not mass-forfeit (shared Hostinger outage).
 */
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class DisconnectPresenceGraceTest extends TestCase {
    public function testBothHumanSeatsStaleDoesNotForfeit(): void {
        if (!function_exists('applyDisconnectForfeits')) {
            $this->markTestSkipped('api.php helpers not loaded');
        }
        $roomId = 'TESTDUALSTALE';
        $state = [
            'status' => 'active',
            'mode' => 'casual',
            'seq' => 3,
            'log' => [],
            'players' => [
                'p1' => ['token' => 'tok_p1', 'name' => 'A', 'is_cpu' => false],
                'p2' => ['token' => 'tok_p2', 'name' => 'B', 'is_cpu' => false],
            ],
        ];
        // Mark both seats stale via presence file if helpers allow — otherwise skip.
        if (!function_exists('touchPresence') || !defined('GAMES_DIR')) {
            $this->markTestSkipped('presence helpers unavailable');
        }
        $file = GAMES_DIR . 'presence_' . $roomId . '.json';
        @mkdir(GAMES_DIR, 0755, true);
        file_put_contents($file, json_encode([
            'tok_p1' => time() - 1000,
            'tok_p2' => time() - 1000,
        ]));
        $changed = applyDisconnectForfeits($state, $roomId);
        @unlink($file);
        $this->assertFalse($changed);
        $this->assertSame('active', $state['status']);
    }
}
