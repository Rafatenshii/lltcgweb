<?php

declare(strict_types=1);

namespace LLTCG\Tests\Replay;

use PHPUnit\Framework\TestCase;

final class ReplayBatonTouchRepairTest extends TestCase
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

    public function testPlaceBatonTargetSwapsFromOtherSlot(): void
    {
        $state = $this->joinedMainFirstState();
        $a = array_shift($state['players']['p2']['hand']);
        $b = array_shift($state['players']['p2']['hand']);
        $this->assertIsArray($a);
        $this->assertIsArray($b);
        $state['players']['p2']['stage']['left'] = $a;
        $state['players']['p2']['stage']['center'] = $b;

        replayPlaceMemberOnExactSlot($state, 'p2', (string)$a['instance_id'], 'center');

        $this->assertSame($a['instance_id'], $state['players']['p2']['stage']['center']['instance_id'] ?? null);
        $this->assertSame($b['instance_id'], $state['players']['p2']['stage']['left']['instance_id'] ?? null);
    }

    public function testPlayMemberBatonFromWaitingRoomDoesNotAbortReplay(): void
    {
        $state = $this->joinedMainFirstState();
        $incoming = null;
        foreach ($state['players']['p2']['hand'] as $c) {
            if (($c['card_type'] ?? '') === 'メンバー') {
                $incoming = $c;
                break;
            }
        }
        $this->assertIsArray($incoming);
        $baton = [
            'instance_id' => 'card_replay_baton_target',
            'card_type' => 'メンバー',
            'name' => 'Baton Target',
            'name_en' => 'Baton Target',
            'cost' => 3,
        ];
        $state['players']['p2']['waiting_room'][] = $baton;
        $state['active_player'] = 'p2';
        $state['phase'] = 'main_second';
        foreach ($state['players']['p2']['energy_zone'] as &$e) {
            $e['active'] = true;
        }
        unset($e);

        $after = replayApplyRecordedAction(
            $state,
            'p2',
            'play_member',
            [
                'card_id' => $incoming['instance_id'],
                'slot' => 'center',
                'baton_id' => 'card_replay_baton_target',
            ],
            85
        );

        $this->assertIsArray($after);
        $this->assertNotSame(
            'Invalid Baton Touch target',
            $after['error'] ?? null
        );
        $logBlob = json_encode($after['log'] ?? []);
        $onCenter = ($after['players']['p2']['stage']['center']['instance_id'] ?? '') === $incoming['instance_id'];
        $this->assertTrue(
            $onCenter || str_contains((string)$logBlob, 'skipped unresolved prompt'),
            'play_member with drifted Baton target should repair or soft-skip'
        );
    }
}
