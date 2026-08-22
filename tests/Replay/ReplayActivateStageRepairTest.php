<?php

declare(strict_types=1);

namespace LLTCG\Tests\Replay;

use PHPUnit\Framework\TestCase;

final class ReplayActivateStageRepairTest extends TestCase
{
    private function joinedMainFirstState(): array
    {
        $created = createRoom(['name' => 'Replay P1', 'deck' => 'nijigasaki']);
        joinRoom([
            'room_id' => $created['room_id'],
            'name' => 'Replay P2',
            'deck' => 'cpu',
            'cpu_difficulty' => 'easy',
            'first_player' => 'p1',
        ]);
        $state = loadGame($created['room_id']);
        $this->assertIsArray($state);
        $state = applyAction($state, 'p1', 'mulligan', ['card_ids' => []]);
        $state = applyAction($state, 'p2', 'mulligan', ['card_ids' => []]);
        $this->assertSame('main_first', $state['phase'] ?? '');
        return $state;
    }

    public function testEnsureMemberOnStagePullsFromWaitingRoom(): void
    {
        $state = $this->joinedMainFirstState();
        $card = array_shift($state['players']['p2']['hand']);
        $this->assertIsArray($card);
        $iid = (string)($card['instance_id'] ?? '');
        $this->assertNotSame('', $iid);
        $state['players']['p2']['waiting_room'][] = $card;

        replayEnsureMemberOnStage($state, 'p2', $iid, 'center');

        $this->assertSame($iid, $state['players']['p2']['stage']['center']['instance_id'] ?? null);
        foreach ($state['players']['p2']['waiting_room'] as $c) {
            $this->assertNotSame($iid, $c['instance_id'] ?? '');
        }
    }

    public function testActivateAbilityFromWaitingRoomDoesNotAbortReplay(): void
    {
        $state = $this->joinedMainFirstState();
        $member = [
            'instance_id' => 'card_replay_wait_self_1',
            'card_type' => 'メンバー',
            'name' => 'Replay Waiter',
            'name_en' => 'Replay Waiter',
            'group' => 'Nijigasaki',
            'abilities' => [[
                'trigger' => 'activated',
                'type' => 'wait_self_only',
                'once_per_turn' => true,
            ]],
        ];
        $state['players']['p2']['waiting_room'][] = $member;
        $state['active_player'] = 'p2';
        $state['phase'] = 'main_second';

        $after = replayApplyRecordedAction(
            $state,
            'p2',
            'activate_ability',
            ['card_id' => 'card_replay_wait_self_1', 'ability_index' => 0],
            83
        );

        $this->assertIsArray($after);
        $onStage = false;
        foreach ($after['players']['p2']['stage'] as $mbr) {
            if (($mbr['instance_id'] ?? '') === 'card_replay_wait_self_1') {
                $onStage = true;
            }
        }
        $waited = memberIsInWait($after['players']['p2']['stage']['center'] ?? [])
            || memberIsInWait($after['players']['p2']['stage']['left'] ?? [])
            || memberIsInWait($after['players']['p2']['stage']['right'] ?? []);
        $logBlob = json_encode($after['log'] ?? []);
        $this->assertTrue(
            $onStage || $waited || str_contains((string)$logBlob, 'skipped unresolved prompt'),
            'activate_ability should repair onto Stage or soft-skip, not throw'
        );
    }
}
