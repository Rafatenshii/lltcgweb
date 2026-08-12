<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * Issue #110 — Eternalize Love!! (PL!N-pb1-042-L) Live Start:
 * 2+ same-name Nijigasaki Members on Stage → −3 Gray required hearts.
 */
final class Issue110EternalizeLoveTest extends TestCase
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

    public function testSameNameNijigasakiReducesGrayHeartsByThree(): void
    {
        $live = $this->cardByNo('PL!N-pb1-042-L', 'eternalize');
        $s1 = $this->cardByNo('PL!N-bp1-010-R', 'sh1');
        $s2 = $this->cardByNo('PL!N-bp3-010-R', 'sh2');
        // Different printings, same name_en key.
        $this->assertSame(\cardNameKey($s1), \cardNameKey($s2));

        $p1 = $this->emptyPlayer('p1', 'P1');
        $p1['stage'] = [
            'left' => $s1,
            'center' => null,
            'right' => $s2,
        ];
        $p1['live_zone'] = [$live];

        $state = [
            'room_id' => 'ISSUE110',
            'status' => 'playing',
            'seq' => 1,
            'turn' => 2,
            'phase' => 'live_start_effects',
            'first_player' => 'p1',
            'active_player' => 'p1',
            'log' => [],
            'players' => [
                'p1' => $p1,
                'p2' => $this->emptyPlayer('p2', 'P2'),
            ],
        ];

        $ab = $live['abilities'][0];
        $this->assertSame('reduce_hearts_if_same_name_duplicate', $ab['type'] ?? null);

        $state = \resolveAbilityEffect($state, 'p1', $live, $ab, [
            'phase' => 'live_start',
            'ability_index' => 0,
        ]);

        $lc = $state['players']['p1']['live_zone'][0];
        $this->assertSame(3, intval($lc['hearts_color_reduction']['any'] ?? 0));

        $eff = \applyLiveHeartReductions($lc['required_hearts'] ?? $lc['hearts'] ?? [], $lc);
        $any = 0;
        foreach ($eff as $h) {
            if (($h['color'] ?? '') === 'any') {
                $any += intval($h['count'] ?? 0);
            }
        }
        $this->assertSame(9, $any);
    }

    public function testNoDuplicateDoesNotReduce(): void
    {
        $live = $this->cardByNo('PL!N-pb1-042-L', 'eternalize');
        $s1 = $this->cardByNo('PL!N-bp1-010-R', 'sh1');
        $ayumu = $this->cardByNo('PL!N-bp1-001-R', 'ayumu');

        $p1 = $this->emptyPlayer('p1', 'P1');
        $p1['stage'] = [
            'left' => $s1,
            'center' => $ayumu,
            'right' => null,
        ];
        $p1['live_zone'] = [$live];

        $state = [
            'room_id' => 'ISSUE110B',
            'status' => 'playing',
            'seq' => 1,
            'turn' => 2,
            'phase' => 'live_start_effects',
            'first_player' => 'p1',
            'active_player' => 'p1',
            'log' => [],
            'players' => [
                'p1' => $p1,
                'p2' => $this->emptyPlayer('p2', 'P2'),
            ],
        ];

        $state = \resolveAbilityEffect($state, 'p1', $live, $live['abilities'][0], [
            'phase' => 'live_start',
            'ability_index' => 0,
        ]);

        $lc = $state['players']['p1']['live_zone'][0];
        $this->assertSame(0, intval($lc['hearts_color_reduction']['any'] ?? 0));
        $this->assertSame(0, intval($lc['hearts_reduction_gray'] ?? 0));
    }
}
