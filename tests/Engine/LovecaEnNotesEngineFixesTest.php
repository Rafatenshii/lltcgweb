<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * Engine / IR fixes from Loveca EN spreadsheet notes (logic pass before full EN import).
 */
final class LovecaEnNotesEngineFixesTest extends TestCase
{
    private function cardByNo(string $cardNo): array
    {
        $data = json_decode((string) file_get_contents((string) constant('CARDS_FILE')), true);
        foreach ($data['cards'] ?? [] as $card) {
            if (($card['card_no'] ?? '') === $cardNo) {
                $card['instance_id'] = 'src_' . preg_replace('/[^A-Za-z0-9]+/', '_', $cardNo);
                return $card;
            }
        }
        $this->fail("Missing card $cardNo");
    }

    private function emptyPlayer(string $id): array
    {
        return [
            'id' => $id,
            'name' => strtoupper($id),
            'hand' => [],
            'waiting_room' => [],
            'stage' => ['left' => null, 'center' => null, 'right' => null],
            'energy_zone' => [],
            'main_deck' => [],
            'energy_deck' => [],
            'live_zone' => [],
            'success_lives' => [],
        ];
    }

    private function baseState(): array
    {
        return [
            'status' => 'playing',
            'phase' => 'main_first',
            'seq' => 1,
            'turn' => 2,
            'active_player' => 'p1',
            'log' => [],
            'players' => [
                'p1' => $this->emptyPlayer('p1'),
                'p2' => $this->emptyPlayer('p2'),
            ],
        ];
    }

    public function testBp5014LookRevealAcceptsBlueOrPurpleHeart(): void
    {
        $cfg = [
            'filter' => 'member',
            'heart_colors' => ['blue', 'purple'],
        ];
        $blue = [
            'card_type' => 'メンバー',
            'hearts' => [['color' => 'blue', 'count' => 1]],
        ];
        $purple = [
            'card_type' => 'メンバー',
            'hearts' => [['color' => 'purple', 'count' => 1]],
        ];
        $red = [
            'card_type' => 'メンバー',
            'hearts' => [['color' => 'red', 'count' => 1]],
        ];
        $this->assertTrue(\cardMatchesLookPick($blue, $cfg));
        $this->assertTrue(\cardMatchesLookPick($purple, $cfg));
        $this->assertFalse(\cardMatchesLookPick($red, $cfg));
    }

    public function testHanamaruRequireScoreIconNotNumericScore(): void
    {
        $hana = $this->cardByNo('PL!S-sd1-007-SD');
        $ab = ($hana['abilities'] ?? [])[0] ?? [];
        $this->assertTrue(!empty($ab['require_score_icon']));

        $withIcon = [
            'instance_id' => 'icon',
            'card_type' => 'ライブ',
            'group' => 'Sunshine',
            'score' => 1,
            'yell_score_icon' => true,
        ];
        $highScoreNoIcon = [
            'instance_id' => 'hi',
            'card_type' => 'ライブ',
            'group' => 'Sunshine',
            'score' => 4,
        ];
        $cfg = \wrPickCfgFromAbility($ab);
        $cfg['group'] = 'Sunshine';
        $cfg['filter'] = 'live';
        unset($cfg['min_score']);
        $cfg['require_score_icon'] = true;

        $this->assertTrue(\cardMatchesWrPick($withIcon, $cfg));
        $this->assertFalse(\cardMatchesWrPick($highScoreNoIcon, $cfg));
    }

    public function testYouPb1ReplacesPrintedHeartsGreenNotBlade(): void
    {
        foreach (['PL!S-pb1-003-P＋', 'PL!S-pb1-003-R'] as $no) {
            $card = $this->cardByNo($no);
            $ab = ($card['abilities'] ?? [])[0] ?? [];
            $this->assertSame('optional_pay_energy', $ab['type'] ?? null, $no);
            $this->assertSame('replace_member_hearts_color', $ab['then']['type'] ?? null, $no);
            $this->assertSame('green', $ab['then']['color'] ?? null, $no);
        }

        $you = $this->cardByNo('PL!S-pb1-003-P＋');
        $you['hearts'] = [['color' => 'red', 'count' => 2], ['color' => 'yellow', 'count' => 1]];
        $state = $this->baseState();
        $state['phase'] = 'live_start_effects';
        $state['players']['p1']['stage']['center'] = $you;
        $state['players']['p1']['energy_zone'] = [
            ['instance_id' => 'e1', 'active' => true],
            ['instance_id' => 'e2', 'active' => true],
        ];

        $state = \resolveAbilityEffect($state, 'p1', $you, $you['abilities'][0], [
            'phase' => 'live_start',
            'confirm' => true,
            'pay' => true,
        ]);
        $m = $state['players']['p1']['stage']['center'];
        $this->assertSame(['green'], $m['replaced_hearts'] ?? null);
        $this->assertArrayNotHasKey('hearts_as_blade_color', $m);
    }

    public function testKanonSd2GrantsBlueNotGray(): void
    {
        $card = $this->cardByNo('PL!N-sd2-019-SD2');
        $ab = ($card['abilities'] ?? [])[0] ?? [];
        $this->assertSame('grant_bonus_hearts', $ab['type'] ?? null);
        $this->assertSame('blue', $ab['hearts'][0]['color'] ?? null);
    }

    public function testKekePb2GrantsTwoPurple(): void
    {
        foreach (['PL!SP-pb2-002-PP', 'PL!SP-pb2-002-R'] as $no) {
            $card = $this->cardByNo($no);
            $ab = ($card['abilities'] ?? [])[0] ?? [];
            $this->assertSame('purple', $ab['heart_color'] ?? null, $no);
            $this->assertSame(2, intval($ab['heart_count'] ?? 0), $no);
        }
    }

    public function testSyncriseMoveGrantsPlusFourBladeOncePerTurn(): void
    {
        $observer = $this->cardByNo('PL!SP-pb2-022-P＋');
        $moved = [
            'instance_id' => 'moved_sync',
            'card_type' => 'メンバー',
            'group' => 'Superstar',
            'subunit' => '5yncri5e!',
            'name_en' => 'Sync Member',
        ];
        $state = $this->baseState();
        $state['players']['p1']['stage']['left'] = $observer;
        $state['players']['p1']['stage']['center'] = $moved;

        $state = \spBp2TriggerMoveToCenterHeart($state, 'p1', $moved, 'center');
        $obs = $state['players']['p1']['stage']['left'];
        $this->assertSame(4, intval($obs['live_blade_bonus'] ?? 0));

        // Once per turn — second move does not stack.
        $state = \spBp2TriggerMoveToCenterHeart($state, 'p1', $moved, 'center');
        $obs = $state['players']['p1']['stage']['left'];
        $this->assertSame(4, intval($obs['live_blade_bonus'] ?? 0));
    }

    public function testLiellaContinuousHeartsUsePrintedColors(): void
    {
        $map = [
            'PL!SP-pb2-023-N' => 'red',
            'PL!SP-pb2-027-N' => 'yellow',
            'PL!SP-pb2-032-N' => 'purple',
        ];
        foreach ($map as $no => $color) {
            $card = $this->cardByNo($no);
            foreach ($card['abilities'] ?? [] as $ab) {
                $this->assertSame('hearts_if_min_energy', $ab['type'] ?? null, $no);
                $this->assertSame($color, $ab['hearts'][0]['color'] ?? null, $no);
            }
        }
        $active = $this->cardByNo('PL!SP-pb2-026-N');
        $ab = ($active['abilities'] ?? [])[0] ?? [];
        $this->assertSame('hearts_if_active_energy', $ab['type'] ?? null);
        $this->assertSame('red', $ab['hearts'][0]['color'] ?? null);
        $this->assertSame(2, intval($ab['hearts'][0]['count'] ?? 0));
    }

    public function testSumireChooseReplaceHeartChoices(): void
    {
        $card = $this->cardByNo('PL!SP-pb2-030-N');
        $ab = ($card['abilities'] ?? [])[0] ?? [];
        $this->assertSame('choose_replace_member_hearts', $ab['type'] ?? null);
        $this->assertSame(['red', 'yellow', 'purple'], $ab['heart_choices'] ?? null);
    }
}
