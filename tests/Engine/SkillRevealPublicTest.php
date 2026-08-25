<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/** Card text that says Reveal must show the card to the opponent. */
final class SkillRevealPublicTest extends TestCase
{
    private function live(string $id): array
    {
        return [
            'instance_id' => $id,
            'card_no' => 'TEST-LIVE',
            'name_en' => 'Nijigasaki Live',
            'card_type' => 'ライブ',
            'group' => 'Nijigasaki',
            'score' => 1,
        ];
    }

    private function member(string $id): array
    {
        return [
            'instance_id' => $id,
            'card_no' => 'TEST-MEMBER',
            'name_en' => 'Ceras Yanagida Lilienfeld',
            'card_type' => 'メンバー',
            'group' => 'Hasunosora',
            'cost' => 11,
        ];
    }

    private function state(): array
    {
        $p = static fn(string $id, string $name): array => [
            'id' => $id,
            'name' => $name,
            'token' => $id . '-token',
            'hand' => [],
            'stage' => ['left' => null, 'center' => null, 'right' => null],
            'waiting_room' => [],
            'energy_zone' => [],
            'main_deck' => [],
            'energy_deck' => [],
            'live_zone' => [],
            'success_lives' => [],
        ];
        return [
            'status' => 'playing',
            'phase' => 'main_first',
            'turn' => 1,
            'seq' => 4,
            'first_player' => 'p1',
            'active_player' => 'p1',
            'log' => [],
            'players' => [
                'p1' => $p('p1', 'Alice'),
                'p2' => $p('p2', 'Bob'),
            ],
        ];
    }

    public function testLookRevealPickPublishesChosenCard(): void
    {
        $live = $this->live('rev_live');
        $filler = $this->member('rev_mem');
        $state = $this->state();
        $state['players']['p1']['main_deck'] = [$live, $filler];
        $p = &$state['players']['p1'];
        $state = \beginLookRevealPick($state, 'p1', 'Rina', $p, [
            'look' => 2,
            'pick' => 1,
            'filter' => 'live',
            'optional_pick' => true,
        ]);
        $this->assertSame('pick_looked_deck_hand', $state['pending_prompt']['type'] ?? null);

        $oppView = \filterStateForPlayer($state, 'p2-token');
        $this->assertSame('wait_look', $oppView['pending_prompt']['type'] ?? null);
        $this->assertArrayNotHasKey('candidates', $oppView['pending_prompt'] ?? []);

        $state = \actionResolvePrompt($state, 'p1', ['card_id' => 'rev_live']);
        $this->assertNull($state['pending_prompt'] ?? null);
        $revealedIds = array_column($state['skill_reveals']['cards'] ?? [], 'instance_id');
        $this->assertContains('rev_live', $revealedIds);
        $this->assertNotContains('rev_mem', $revealedIds);

        $oppView = \filterStateForPlayer($state, 'p2-token');
        $this->assertContains('rev_live', array_column($oppView['skill_reveals']['cards'] ?? [], 'instance_id'));
        $log = implode("\n", array_map(
            static fn($e) => is_array($e) ? (string)($e['msg'] ?? '') : (string)$e,
            $oppView['log'] ?? []
        ));
        $this->assertStringContainsString('Nijigasaki Live', $log);
        $this->assertStringContainsString('revealed', $log);
        $handIds = array_column($state['players']['p1']['hand'] ?? [], 'instance_id');
        $this->assertContains('rev_live', $handIds);
    }

    public function testSkillRevealClearedOnNewTurn(): void
    {
        $state = $this->state();
        $state['skill_reveals'] = [
            'seq' => 4,
            'turn' => 1,
            'pid' => 'p1',
            'from' => 'hand',
            'source_name' => 'Test',
            'cards' => [['instance_id' => 'rev_live', 'card_no' => 'TEST-LIVE']],
        ];
        $state['first_player'] = 'p1';
        $state['players']['p1']['main_deck'] = [$this->member('d1'), $this->member('d2')];
        $state['players']['p1']['energy_deck'] = [];
        $state = \startTurn($state);
        $this->assertArrayNotHasKey('skill_reveals', $state);
    }

    public function testSkipLookRevealDoesNotPublishLookedCards(): void
    {
        $live = $this->live('skip_live');
        $state = $this->state();
        $state['players']['p1']['main_deck'] = [$live];
        $p = &$state['players']['p1'];
        $state = \beginLookRevealPick($state, 'p1', 'Rina', $p, [
            'look' => 1,
            'pick' => 1,
            'filter' => 'live',
            'optional_pick' => true,
        ]);
        $state = \actionResolvePrompt($state, 'p1', ['choice' => 'skip']);
        $this->assertEmpty($state['skill_reveals']['cards'] ?? []);
        $this->assertSame([], $state['players']['p1']['hand'] ?? []);
    }

    public function testDrawLogStillHidesCardNameFromOpponent(): void
    {
        $state = $this->state();
        $state = \logEffectDraw($state, 'p1', 'Umi Sonoda', $this->live('draw1'));
        $oppView = \filterStateForPlayer($state, 'p2-token');
        $msg = $oppView['log'][0]['msg'] ?? '';
        $this->assertStringContainsString('drew a card', $msg);
        $this->assertStringNotContainsString('Nijigasaki Live', $msg);
    }
}
