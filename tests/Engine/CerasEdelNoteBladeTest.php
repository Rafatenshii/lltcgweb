<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * Ceras PL!HS-bp5-007 Always is a flat +2 while another standing Edel Note
 * Member is on Stage — not +2 per other Edel, and not from a Waited Izumi.
 */
final class CerasEdelNoteBladeTest extends TestCase
{
    private function cardByNo(string $cardNo, string $instanceId): array
    {
        $data = json_decode((string) file_get_contents((string) constant('CARDS_FILE')), true);
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
            'live_zone' => [],
            'success_lives' => [],
        ];
    }

    private function stateWithCeras(array $p1Stage): array
    {
        $p1 = $this->emptyPlayer('p1', 'P1');
        $p1['stage'] = $p1Stage;
        return [
            'status' => 'playing',
            'phase' => 'main_first',
            'seq' => 1,
            'turn' => 2,
            'first_player' => 'p1',
            'active_player' => 'p1',
            'log' => [],
            'players' => [
                'p1' => $p1,
                'p2' => $this->emptyPlayer('p2', 'P2'),
            ],
        ];
    }

    public function testCerasAlwaysIsFlatPlusTwoWithTwoOtherEdels(): void
    {
        $ceras = $this->cardByNo('PL!HS-bp5-007-P', 'ceras');
        $this->assertSame('blade_if_other_subunit', $ceras['abilities'][1]['type'] ?? null);

        $izumi = $this->cardByNo('PL!HS-bp5-008-R', 'izumi');
        $kaho = $this->cardByNo('PL!HS-bp5-016-N', 'izumi2');
        $printed = intval($ceras['blade'] ?? 0);

        $state = $this->stateWithCeras([
            'left' => $izumi,
            'center' => $ceras,
            'right' => $kaho,
        ]);

        $this->assertSame(
            $printed + 2,
            \getMemberBlade($ceras, $state, 'p1', 'center'),
            'Ceras Always is +2 total, not +2 per other Edel Note Member'
        );
    }

    public function testWaitedIzumiDoesNotEnableCerasPlusTwo(): void
    {
        $ceras = $this->cardByNo('PL!HS-bp5-007-P', 'ceras');
        $izumi = $this->cardByNo('PL!HS-bp5-008-R', 'izumi');
        $printed = intval($ceras['blade'] ?? 0);

        $state = $this->stateWithCeras([
            'left' => $izumi,
            'center' => $ceras,
            'right' => null,
        ]);
        \waitMember($state['players']['p1']['stage']['left'], $state);

        $this->assertTrue(\memberIsInWait($state['players']['p1']['stage']['left']));
        $this->assertSame(
            $printed,
            \getMemberBlade($ceras, $state, 'p1', 'center'),
            'Waited Izumi must not count as the other Edel Note Member for Ceras Always'
        );
        $this->assertSame(
            $printed,
            \computeYellBladeTotal($state, 'p1'),
            'Waited Izumi printed blade must not hit Yell; Ceras has no Always bonus'
        );
    }

    public function testStandingEdelEnablesCerasPlusTwoEvenIfIzumiIsWaited(): void
    {
        $ceras = $this->cardByNo('PL!HS-bp5-007-P', 'ceras');
        $izumi = $this->cardByNo('PL!HS-bp5-008-R', 'izumi');
        $other = $this->cardByNo('PL!HS-bp5-016-N', 'other');
        $printed = intval($ceras['blade'] ?? 0);

        $state = $this->stateWithCeras([
            'left' => $izumi,
            'center' => $ceras,
            'right' => $other,
        ]);
        \waitMember($state['players']['p1']['stage']['left'], $state);

        $this->assertSame($printed + 2, \getMemberBlade($ceras, $state, 'p1', 'center'));
        $this->assertSame(
            $printed + 2 + intval($other['blade'] ?? 0),
            \computeYellBladeTotal($state, 'p1')
        );
    }
}
