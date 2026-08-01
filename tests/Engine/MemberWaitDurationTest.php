<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * Wait lasts until the owner's next Active Phase.
 * Members Waited by the opponent earlier the same turn clear on the owner's Active Phase.
 * Self-Wait during own Main stays until the next turn's Active Phase.
 */
final class MemberWaitDurationTest extends TestCase
{
    private function stageMember(string $id, int $blade = 3, int $cost = 4): array
    {
        return [
            'instance_id' => $id,
            'card_type' => 'メンバー',
            'name' => 'Test Member',
            'name_en' => 'Test Member',
            'cost' => $cost,
            'blade' => $blade,
            'hearts' => [
                ['color' => 'pink', 'count' => 1],
                ['color' => 'pink', 'count' => 1],
            ],
            'active' => true,
        ];
    }

    private function baseState(int $turn = 3, string $active = 'p1', string $phase = 'main_first'): array
    {
        return [
            'room_id' => 'WAITTEST',
            'status' => 'playing',
            'seq' => 1,
            'turn' => $turn,
            'phase' => $phase,
            'first_player' => 'p1',
            'active_player' => $active,
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [],
                    'stage' => ['left' => null, 'center' => $this->stageMember('p1c'), 'right' => null],
                    'waiting_room' => [],
                    'energy_zone' => [],
                    'main_deck' => [],
                    'energy_deck' => [],
                    'live_zone' => [],
                    'success_lives' => [],
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'P2',
                    'hand' => [],
                    'stage' => ['left' => null, 'center' => $this->stageMember('p2c'), 'right' => null],
                    'waiting_room' => [],
                    'energy_zone' => [],
                    'main_deck' => [],
                    'energy_deck' => [],
                    'live_zone' => [],
                    'success_lives' => [],
                ],
            ],
        ];
    }

    public function testWaitMemberSetsInWaitFlagAndBlocksDoubleWait(): void
    {
        $state = $this->baseState();
        $mbr = $state['players']['p2']['stage']['center'];
        waitMember($mbr, $state);
        $this->assertTrue(memberIsInWait($mbr));
        $this->assertFalse($mbr['active']);
        $this->assertSame(3, $mbr['waited_turn']);
        $this->assertSame('p1', $mbr['waited_active_player']);

        waitMember($mbr, $state);
        $this->assertTrue(memberIsInWait($mbr));
    }

    /** Opponent Waits your Member on turn N → your Active Phase same turn clears Wait. */
    public function testWaitClearsOnOwnerActivePhaseSameTurnWhenWaitedByOpponent(): void
    {
        $state = $this->baseState(3, 'p1', 'main_first');
        $mbr = &$state['players']['p2']['stage']['center'];
        waitMember($mbr, $state);
        $this->assertSame('p1', $mbr['waited_active_player']);

        $state = doActivePhase($state, 'p2');

        $after = $state['players']['p2']['stage']['center'];
        $this->assertNotNull($after);
        $this->assertFalse(memberIsInWait($after));
        $this->assertTrue($after['active']);
    }

    /** Self-Wait during own Main stays Waited through the rest of the turn. */
    public function testSelfWaitPersistsThroughRestOfOwnTurn(): void
    {
        $state = $this->baseState(3, 'p2', 'main_second');
        $mbr = &$state['players']['p2']['stage']['center'];
        waitMember($mbr, $state);
        $this->assertSame('p2', $mbr['waited_active_player']);

        // Opponent's Active Phase must not clear p2's self-Wait.
        $state = doActivePhase($state, 'p1');
        $this->assertTrue(memberIsInWait($state['players']['p2']['stage']['center']));

        // Same-turn second Active for owner is unusual; Wait still same turn + self → keep.
        $state = doActivePhase($state, 'p2');
        $this->assertTrue(memberIsInWait($state['players']['p2']['stage']['center']));
    }

    public function testWaitClearsOnOwnersNextTurnActivePhase(): void
    {
        $state = $this->baseState(3, 'p2', 'main_second');
        $mbr = &$state['players']['p2']['stage']['center'];
        waitMember($mbr, $state);

        $state['turn'] = 4;
        $state['active_player'] = 'p2';
        $state = doActivePhase($state, 'p2');

        $after = $state['players']['p2']['stage']['center'];
        $this->assertNotNull($after);
        $this->assertFalse(memberIsInWait($after));
        $this->assertTrue($after['active']);
    }

    public function testWaitedMemberExcludedFromBladeTotal(): void
    {
        $state = $this->baseState();
        waitMember($state['players']['p2']['stage']['center'], $state);

        $total = computeYellBladeTotal($state, 'p2');
        $this->assertSame(0, $total);
    }

    public function testWaitedMemberStillContributesHearts(): void
    {
        $state = $this->baseState();
        waitMember($state['players']['p1']['stage']['center'], $state);

        $hearts = aggregateStageHeartsByColor($state['players']['p1']['stage']);
        $pink = 0;
        foreach ($hearts as $hg) {
            if (($hg['color'] ?? '') === 'pink') {
                $pink += intval($hg['count'] ?? 0);
            }
        }
        $this->assertSame(2, $pink);
    }

    public function testActivateMemberFullyClearsWait(): void
    {
        $state = $this->baseState();
        $mbr = &$state['players']['p1']['stage']['center'];
        waitMember($mbr, $state);
        activateMemberFully($mbr);
        $this->assertFalse(memberIsInWait($mbr));
        $this->assertTrue($mbr['active']);
        $this->assertArrayNotHasKey('waited_active_player', $mbr);
    }
}
