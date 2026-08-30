<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * PL!HS-bp6-002 Sayaka — [Always] +2 Blade while no other Members on Stage.
 */
final class SayakaBp6002SoloBladeTest extends TestCase
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

    private function stateWithStage(array $p1Stage): array
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

    public function testSoloStageGainsPlusTwoBlade(): void
    {
        $sayaka = $this->cardByNo('PL!HS-bp6-002-R', 'sayaka');
        $this->assertSame('blade_if_solo_stage', $sayaka['abilities'][0]['type'] ?? null);
        $printed = intval($sayaka['blade'] ?? 0);

        $state = $this->stateWithStage([
            'left' => null,
            'center' => $sayaka,
            'right' => null,
        ]);

        $this->assertSame(
            $printed + 2,
            \getMemberBlade($sayaka, $state, 'p1', 'center')
        );
        $this->assertSame(
            $printed + 2,
            \computeYellBladeTotal($state, 'p1')
        );
    }

    public function testSecondMemberRemovesSoloBonus(): void
    {
        $sayaka = $this->cardByNo('PL!HS-bp6-002-R', 'sayaka');
        $other = $this->cardByNo('PL!HS-bp6-003-R', 'rurino');
        $printed = intval($sayaka['blade'] ?? 0);

        $state = $this->stateWithStage([
            'left' => $other,
            'center' => $sayaka,
            'right' => null,
        ]);

        $this->assertSame(
            $printed,
            \getMemberBlade($sayaka, $state, 'p1', 'center')
        );
    }

    public function testWaitedOtherMemberStillBlocksSoloBonus(): void
    {
        $sayaka = $this->cardByNo('PL!HS-bp6-002-R', 'sayaka');
        $other = $this->cardByNo('PL!HS-bp6-003-R', 'rurino');
        $other['active'] = false;
        $printed = intval($sayaka['blade'] ?? 0);

        $state = $this->stateWithStage([
            'left' => $other,
            'center' => $sayaka,
            'right' => null,
        ]);

        $this->assertSame(
            $printed,
            \getMemberBlade($sayaka, $state, 'p1', 'center'),
            'Waited Members still occupy Stage for "no other Members"'
        );
    }
}
