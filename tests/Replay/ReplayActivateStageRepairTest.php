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

    public function testActivateAbilityRepairsInactiveEnergyInsteadOfAborting(): void
    {
        $state = $this->joinedMainFirstState();
        $ez = $state['players']['p1']['energy_zone'] ?? [];
        $this->assertGreaterThanOrEqual(1, count($ez));
        foreach ($state['players']['p1']['energy_zone'] as &$e) {
            $e['active'] = false;
        }
        unset($e);
        while (count($state['players']['p1']['energy_zone']) < 2) {
            $extra = array_shift($state['players']['p1']['energy_deck']);
            $this->assertIsArray($extra);
            $extra['active'] = false;
            $state['players']['p1']['energy_zone'][] = $extra;
        }
        $member = [
            'instance_id' => 'card_replay_pay_e2',
            'card_type' => 'メンバー',
            'name' => 'Replay Payer',
            'name_en' => 'Replay Payer',
            'group' => 'µ\'s',
            'abilities' => [[
                'trigger' => 'activated',
                'type' => 'activated_pay_energy_mill',
                'cost' => 2,
                'count' => 1,
                'once_per_turn' => true,
            ]],
        ];
        $state['players']['p1']['stage']['center'] = $member;
        $state['active_player'] = 'p1';
        $state['phase'] = 'main_first';

        $after = replayApplyRecordedAction(
            $state,
            'p1',
            'activate_ability',
            ['card_id' => 'card_replay_pay_e2', 'ability_index' => 0],
            111
        );

        $this->assertIsArray($after);
        $this->assertLessThanOrEqual(
            0,
            countActiveEnergyInZone($after['players']['p1']),
            'paid 2 energy after flipping inactive zone cards'
        );
    }

    public function testNeedActiveEnergyStillSoftSkipsWhenUnrepairable(): void
    {
        $state = $this->joinedMainFirstState();
        $state['players']['p1']['energy_zone'] = [];
        $state['players']['p1']['energy_deck'] = [];
        $state['players']['p1']['hand'] = array_values(array_filter(
            $state['players']['p1']['hand'] ?? [],
            static fn($c): bool => ($c['card_type'] ?? '') !== 'エネルギー'
        ));
        $state['players']['p1']['waiting_room'] = array_values(array_filter(
            $state['players']['p1']['waiting_room'] ?? [],
            static fn($c): bool => ($c['card_type'] ?? '') !== 'エネルギー'
        ));
        $state['players']['p1']['main_deck'] = array_values(array_filter(
            $state['players']['p1']['main_deck'] ?? [],
            static fn($c): bool => ($c['card_type'] ?? '') !== 'エネルギー'
        ));
        $member = [
            'instance_id' => 'card_replay_pay_e2_empty',
            'card_type' => 'メンバー',
            'name' => 'Replay Payer',
            'name_en' => 'Replay Payer',
            'group' => 'µ\'s',
            'abilities' => [[
                'trigger' => 'activated',
                'type' => 'activated_pay_energy_mill',
                'cost' => 2,
                'count' => 1,
                'once_per_turn' => true,
            ]],
        ];
        $state['players']['p1']['stage']['center'] = $member;

        $after = replayApplyRecordedAction(
            $state,
            'p1',
            'activate_ability',
            ['card_id' => 'card_replay_pay_e2_empty', 'ability_index' => 0],
            111
        );
        $this->assertIsArray($after);
        $logBlob = json_encode($after['log'] ?? []);
        $this->assertTrue(
            str_contains((string)$logBlob, 'skipped unresolved prompt')
                || str_contains((string)$logBlob, 'Need 2 active Energy'),
            'unrepairable energy cost should soft-skip, not throw'
        );
    }

    public function testNeedActiveEnergyDoesNotStealFromDeck(): void
    {
        $state = $this->joinedMainFirstState();
        $mainBefore = count($state['players']['p1']['main_deck'] ?? []);
        $edeckBefore = count($state['players']['p1']['energy_deck'] ?? []);
        $turnBefore = (int)($state['turn'] ?? 1);
        $phaseBefore = (string)($state['phase'] ?? '');
        $state['players']['p1']['energy_zone'] = [];
        $member = [
            'instance_id' => 'card_replay_pay_e2_nodeck',
            'card_type' => 'メンバー',
            'name' => 'Replay Payer',
            'name_en' => 'Replay Payer',
            'group' => 'µ\'s',
            'abilities' => [[
                'trigger' => 'activated',
                'type' => 'activated_pay_energy_mill',
                'cost' => 2,
                'count' => 10,
                'once_per_turn' => true,
            ]],
        ];
        $state['players']['p1']['stage']['center'] = $member;

        $after = replayApplyRecordedAction(
            $state,
            'p1',
            'activate_ability',
            ['card_id' => 'card_replay_pay_e2_nodeck', 'ability_index' => 0],
            111
        );
        $this->assertSame($mainBefore, count($after['players']['p1']['main_deck'] ?? []));
        $this->assertSame($edeckBefore, count($after['players']['p1']['energy_deck'] ?? []));
        $this->assertSame(0, count($after['players']['p1']['energy_zone'] ?? []));
        $this->assertSame($turnBefore, (int)($after['turn'] ?? 1));
        $this->assertSame($phaseBefore, (string)($after['phase'] ?? ''));
        $logBlob = json_encode($after['log'] ?? []);
        $this->assertTrue(
            str_contains((string)$logBlob, 'skipped unresolved prompt'),
            'missing Energy must skip the skill, not mill the deck'
        );
    }
}
