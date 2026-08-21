<?php

declare(strict_types=1);

namespace LLTCG\Tests\Tournament;

use PHPUnit\Framework\TestCase;

final class TournamentPhase2Test extends TestCase
{
    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/tournament_lib.php';
        require_once dirname(__DIR__, 2) . '/tournament_spectate.php';
        require_once dirname(__DIR__, 2) . '/config/paths.php';
    }

    public function testNormalizeSettingsDefaultsAndClamp(): void
    {
        $n = tcgTournamentNormalizeSettings([]);
        $this->assertSame('hidden_hands', $n['fog']);
        $this->assertSame(0, $n['stream_delay_secs']);
        $this->assertSame('standard', $n['rules_template']);
        $this->assertSame('single_elim', $n['format']);

        $n2 = tcgTournamentNormalizeSettings([
            'fog' => 'open_hands',
            'stream_delay_secs' => 30,
            'rules_template' => 'highlander',
            'format' => 'swiss',
            'best_of' => 3,
        ]);
        $this->assertSame('open_hands', $n2['fog']);
        $this->assertSame(30, $n2['stream_delay_secs']);
        $this->assertSame('highlander', $n2['rules_template']);
        $this->assertSame('swiss', $n2['format']);
        $this->assertSame(3, $n2['best_of']);
        $this->assertArrayNotHasKey('cosmetic_prizes', $n2);

        $n3 = tcgTournamentNormalizeSettings(['stream_delay_secs' => 45, 'format' => 'nope']);
        $this->assertSame(0, $n3['stream_delay_secs']);
        $this->assertSame('single_elim', $n3['format']);
    }

    public function testRulesTemplateHighlanderRejectsDupes(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/Highlander/');
        tcgTournamentAssertRulesTemplate('highlander', [
            'main_nos' => ['X-001', 'X-001'],
            'energy_nos' => [],
        ], 'standard');
    }

    public function testRulesTemplatesByMode(): void
    {
        $this->assertSame(
            ['standard', 'pauper', 'highlander'],
            tcgTournamentRulesTemplatesForMode('standard')
        );
        $this->assertSame(['standard'], tcgTournamentRulesTemplatesForMode('starters'));
        $this->assertSame(['standard'], tcgTournamentRulesTemplatesForMode('randomized'));

        $n = tcgTournamentNormalizeSettings(['rules_template' => 'pauper'], 'starters');
        $this->assertSame('standard', $n['rules_template']);

        $legacy = tcgTournamentNormalizeSettings(['rules_template' => 'starters_only'], 'standard');
        $this->assertSame('standard', $legacy['rules_template']);
    }

    public function testRulesTemplateStandardAllowsAnythingShaped(): void
    {
        tcgTournamentAssertRulesTemplate('standard', [
            'main_nos' => ['whatever'],
            'energy_nos' => [],
        ], 'standard');
        $this->assertTrue(true);
    }

    public function testStreamDelayRingServesOlderSnapshot(): void
    {
        $room = 'T' . strtoupper(bin2hex(random_bytes(2)));
        $live = [
            'mode' => 'tournament',
            'seq' => 10,
            'spectate_stream_delay_secs' => 15,
            'players' => [
                'p1' => ['name' => 'A', 'hand' => [], 'main_deck' => [], 'energy_deck' => []],
                'p2' => ['name' => 'B', 'hand' => [], 'main_deck' => [], 'energy_deck' => []],
            ],
            'phase' => 'main_first',
        ];
        // Seed ring with an entry older than delay.
        $path = tcgTournamentDelayFile($room);
        @mkdir(dirname($path), 0755, true);
        $old = $live;
        $old['seq'] = 3;
        $old['phase'] = 'setup';
        file_put_contents($path, json_encode([
            'room_id' => $room,
            'delay_secs' => 15,
            'entries' => [
                ['ts' => time() - 20, 'seq' => 3, 'state' => $old],
                ['ts' => time() - 1, 'seq' => 10, 'state' => $live],
            ],
        ]), LOCK_EX);

        $applied = tcgTournamentApplyStreamDelay($room, $live);
        $this->assertTrue($applied['delayed']);
        $this->assertSame(15, $applied['delay_secs']);
        $this->assertSame(3, (int)($applied['state']['seq'] ?? 0));
        $this->assertSame('setup', (string)($applied['state']['phase'] ?? ''));

        @unlink($path);
    }

    public function testSpectateListAcceptsTournamentCategory(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite extension required');
        }
        // Avoid full api bootstrap if Redis/env is awkward — only assert category gate.
        require_once dirname(__DIR__, 2) . '/spectate.php';
        try {
            tcgListSpectatableMatches('nope');
            $this->fail('expected exception');
        } catch (\Exception $e) {
            $this->assertStringContainsString('ranked, casual, or tournament', $e->getMessage());
        }
        // Empty list is fine when no rooms; must not throw.
        $matches = tcgListSpectatableMatches('tournament');
        $this->assertIsArray($matches);
    }
}
