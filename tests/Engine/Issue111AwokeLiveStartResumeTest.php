<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * Issue #111 — AWOKE (PL!HS-cl1-010-CL) Live Start blade pick must resume Live Start.
 * Replay C86702: pick resolved but finishLiveStart was skipped → effect looped.
 */
final class Issue111AwokeLiveStartResumeTest extends TestCase
{
    private function cardByNo(string $cardNo, string $instanceId): array
    {
        $data = json_decode((string) file_get_contents((string) constant('CARDS_FILE')), true);
        $this->assertIsArray($data);
        foreach ($data['cards'] ?? [] as $card) {
            if (($card['card_no'] ?? '') === $cardNo) {
                $card['instance_id'] = $instanceId;
                $card['active'] = true;
                return $card;
            }
        }
        $this->fail('Missing test card ' . $cardNo);
    }

    private function emptyPlayer(string $id, string $name): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'hand' => [],
            'stage' => ['left' => null, 'center' => null, 'right' => null],
            'waiting_room' => [],
            'energy_zone' => [],
            'main_deck' => [],
            'energy_deck' => [],
            'live_zone' => [],
            'success_lives' => [],
        ];
    }

    public function testAwokeBladePickResumesLiveStartAndDoesNotLoop(): void
    {
        $awoke = $this->cardByNo('PL!HS-cl1-010-CL', 'awoke');
        $kosuzu = $this->cardByNo('PL!HS-bp6-001-P', 'kosuzu');
        // Prefer a printed cost-10 Hasunosora if kosuzu card no differs
        if (intval($kosuzu['cost'] ?? 0) < 10) {
            $kosuzu['cost'] = 10;
            $kosuzu['abilities'] = [];
        }
        $sayaka = $this->cardByNo('PL!HS-pb1-002-R', 'sayaka');
        $sayaka['live_cost_bonus'] = 8; // effective ≥10 like stacked Sayaka in C86702
        $filler = $this->cardByNo('PL!HS-bp1-001-P', 'filler');
        $filler['cost'] = 2;
        $filler['abilities'] = [];

        $p1 = $this->emptyPlayer('p1', 'P1');
        $p1['stage'] = [
            'left' => $filler,
            'center' => $kosuzu,
            'right' => $sayaka,
        ];
        $p1['live_zone'] = [$awoke];
        $p1['hand'] = [
            ['instance_id' => 'h1', 'card_type' => 'メンバー', 'group' => 'Hasunosora', 'cost' => 1],
        ];

        $state = [
            'room_id' => 'ISSUE111',
            'status' => 'playing',
            'seq' => 1,
            'turn' => 3,
            'phase' => 'live_start_effects',
            'first_player' => 'p1',
            'active_player' => 'p1',
            'log' => [],
            'live_attempt' => ['p1'],
            '_live_start_perf_pid' => 'p1',
            'players' => [
                'p1' => $p1,
                'p2' => $this->emptyPlayer('p2', 'P2'),
            ],
        ];

        $GLOBALS['TUT_PERF_MANUAL_PHASES'] = true;
        try {
            $state = \resolveLiveStartAbilities($state, 'p1');
            // May open order prompt if multiple Live Start sources.
            if (($state['pending_prompt']['type'] ?? '') === 'live_start_order_sources') {
                $ids = array_map(
                    static fn($c) => (string)($c['instance_id'] ?? ''),
                    $state['pending_prompt']['candidates'] ?? []
                );
                // Sayaka → Kosuzu → AWOKE (match C86702 style)
                $prefer = ['sayaka', 'kosuzu', 'awoke'];
                $ordered = [];
                foreach ($prefer as $want) {
                    foreach ($ids as $id) {
                        if ($id === $want) {
                            $ordered[] = $id;
                        }
                    }
                }
                foreach ($ids as $id) {
                    if (!in_array($id, $ordered, true)) {
                        $ordered[] = $id;
                    }
                }
                $state = applyAction($state, 'p1', 'resolve_prompt', ['card_ids' => $ordered]);
            }

            // Drain optional Member Live Starts (Kosuzu discard) until AWOKE pick.
            $guard = 0;
            while ($guard++ < 8) {
                $pr = $state['pending_prompt'] ?? null;
                if (!$pr) {
                    break;
                }
                if (($pr['type'] ?? '') === 'cl1_pick_stage_member_blade') {
                    break;
                }
                if (($pr['type'] ?? '') === 'optional_discard_prompt'
                    || ($pr['type'] ?? '') === 'optional_live_start') {
                    $state = applyAction($state, 'p1', 'resolve_prompt', ['choice' => 'no']);
                    continue;
                }
                $this->fail('Unexpected prompt before AWOKE: ' . ($pr['type'] ?? ''));
            }

            $this->assertSame('cl1_pick_stage_member_blade', $state['pending_prompt']['type'] ?? null);
            $this->assertSame('AWOKE', $state['pending_prompt']['source_name'] ?? null);
            $slots = array_map(
                static fn($c) => $c['slot'] ?? '',
                $state['pending_prompt']['candidates'] ?? []
            );
            $this->assertContains('center', $slots);
            $this->assertContains('right', $slots);

            $state = applyAction($state, 'p1', 'resolve_prompt', ['slot' => 'center']);

            // Must not softlock in live_start_effects with AWOKE pending again.
            $this->assertNotSame(
                'cl1_pick_stage_member_blade',
                $state['pending_prompt']['type'] ?? null,
                'AWOKE must not reopen after blade pick (#111)'
            );
            $this->assertSame(
                2,
                intval($state['players']['p1']['stage']['center']['live_blade_bonus'] ?? 0)
            );
            // Live still in storage (not yanked to hand).
            $liveNames = array_map(
                static fn($c) => $c['name_en'] ?? '',
                $state['players']['p1']['live_zone'] ?? []
            );
            $this->assertContains('AWOKE', $liveNames);

            // Resolving again must not stack another +2 (no loop).
            if (!empty($state['pending_prompt'])) {
                // Optional leftovers OK; AWOKE pick is not.
                $this->assertNotSame('cl1_pick_stage_member_blade', $state['pending_prompt']['type']);
            }
            $this->assertTrue(
                !empty($state['_live_start_done']['p1'])
                || ($state['phase'] ?? '') !== 'live_start_effects'
                || empty($state['pending_prompt']),
                'Live Start should finish or leave a non-AWOKE state after blade pick'
            );
        } finally {
            unset($GLOBALS['TUT_PERF_MANUAL_PHASES']);
        }
    }
}
