<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * Rurino live_ban_until_end (PL!HS-bp2-014-N): Lives may still be placed in
 * storage when cannot_live; they dump to WR before Performance and never enter
 * live_attempt (opponent can still perform alone).
 */
final class Issue121CannotLiveStorageTest extends TestCase
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
            'live_ready' => [],
        ];
    }

    public function testSetLiveCardsAllowsLiveWhenCannotLiveThenDumpsAtPerformance(): void
    {
        $ruri = $this->cardByNo('PL!HS-bp2-014-N', 'ruri_ban');
        $live = $this->cardByNo('PL!HS-pb1-028-L', 'compass_hand');
        $member = $this->cardByNo('PL!HS-bp1-004-SEC', 'tsuzuri_hand');

        $p1 = $this->emptyPlayer('p1', 'P1');
        $p1['stage']['center'] = $ruri;
        $p1['hand'] = [$live, $member];
        $p1['main_deck'] = array_fill(0, 10, ['instance_id' => 'deck', 'card_type' => 'メンバー']);

        $p2 = $this->emptyPlayer('p2', 'P2');
        $p2['live_zone'] = [$this->cardByNo('PL!HS-pb1-028-L', 'opp_live')];

        $state = [
            'status' => 'playing',
            'phase' => 'live_set',
            'seq' => 4,
            'turn' => 1,
            'first_player' => 'p1',
            'active_player' => 'p1',
            'log' => [],
            'players' => ['p1' => $p1, 'p2' => $p2],
        ];
        $state = \resolveAbilityEffect($state, 'p1', $ruri, $ruri['abilities'][0], [
            'phase' => 'on_enter',
            'slot' => 'center',
        ]);
        $this->assertTrue(!empty($state['live_modifiers']['p1']['cannot_live']));

        $afterPlace = \actionSetLiveCards($state, 'p1', ['card_ids' => ['compass_hand']]);
        $this->assertSame(
            'compass_hand',
            $afterPlace['players']['p1']['live_zone'][0]['instance_id'] ?? null,
            'cannot_live must still allow placing Live cards into storage'
        );
        $this->assertFalse(\playerAttemptingLivePerformance($afterPlace, 'p1'));
        $this->assertTrue(\playerAttemptingLivePerformance($afterPlace, 'p2'));

        $after = \beginPerformancePhase($afterPlace);
        $wrIds = array_column($after['players']['p1']['waiting_room'], 'instance_id');
        $this->assertContains('compass_hand', $wrIds);
        $this->assertSame(['p2'], $after['live_attempt'] ?? null);
        $played = $after['live_show']['played_lives']['p1'] ?? [];
        $this->assertSame([], $played, 'banned player must show no Live cards played');
        $this->assertStringContainsString(
            'cannot attempt a Live',
            implode("\n", array_column($after['log'] ?? [], 'msg'))
        );
    }

    public function testSetLiveCardsAllowsMemberBluffWhenCannotLive(): void
    {
        $ruri = $this->cardByNo('PL!HS-bp2-014-N', 'ruri_ban');
        $member = $this->cardByNo('PL!HS-bp1-004-SEC', 'tsuzuri_hand');

        $p1 = $this->emptyPlayer('p1', 'P1');
        $p1['stage']['center'] = $ruri;
        $p1['hand'] = [$member];
        $p1['main_deck'] = [['instance_id' => 'deck1', 'card_type' => 'メンバー']];

        $state = [
            'status' => 'playing',
            'phase' => 'live_set',
            'seq' => 4,
            'turn' => 1,
            'first_player' => 'p1',
            'active_player' => 'p1',
            'log' => [],
            'players' => ['p1' => $p1, 'p2' => $this->emptyPlayer('p2', 'P2')],
        ];
        $state = \resolveAbilityEffect($state, 'p1', $ruri, $ruri['abilities'][0], [
            'phase' => 'on_enter',
            'slot' => 'center',
        ]);

        $after = \actionSetLiveCards($state, 'p1', ['card_ids' => ['tsuzuri_hand']]);
        $this->assertCount(1, $after['players']['p1']['live_zone']);
        $this->assertSame('tsuzuri_hand', $after['players']['p1']['live_zone'][0]['instance_id'] ?? null);
    }

    public function testPreplacedLiveStillDumpedToWaitingRoomAtPerformance(): void
    {
        $ruri = $this->cardByNo('PL!HS-bp2-014-N', 'ruri_ban');
        $live = $this->cardByNo('PL!HS-pb1-028-L', 'set_live');

        $p1 = $this->emptyPlayer('p1', 'P1');
        $p1['stage']['center'] = $ruri;
        $p1['live_zone'] = [$live];
        $p2 = $this->emptyPlayer('p2', 'P2');
        $p2['live_zone'] = [$this->cardByNo('PL!HS-pb1-028-L', 'opp_live')];

        $state = [
            'status' => 'playing',
            'phase' => 'live_set',
            'seq' => 4,
            'turn' => 1,
            'first_player' => 'p1',
            'active_player' => 'p1',
            'log' => [],
            'players' => ['p1' => $p1, 'p2' => $p2],
        ];
        $state = \resolveAbilityEffect($state, 'p1', $ruri, $ruri['abilities'][0], [
            'phase' => 'on_enter',
            'slot' => 'center',
        ]);

        $after = \beginPerformancePhase($state);
        $wrIds = array_column($after['players']['p1']['waiting_room'], 'instance_id');
        $this->assertContains('set_live', $wrIds);
        $this->assertSame(['p2'], $after['live_attempt'] ?? null);
        $this->assertStringContainsString(
            'cannot attempt a Live',
            implode("\n", array_column($after['log'] ?? [], 'msg'))
        );
    }
}
