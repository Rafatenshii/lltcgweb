<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

final class FanfareBp6031TargetTest extends TestCase
{
    public function testQualifiedShufflePromptsForWhichHimeGetsBlade(): void
    {
        $members = [];
        for ($i = 1; $i <= 15; $i++) {
            $members[] = [
                'instance_id' => "wr-$i",
                'card_type' => 'メンバー',
                'name_en' => "Member $i",
                'subunit' => 'みらくらぱーく!',
            ];
        }
        $left = ['instance_id' => 'hime-left', 'name_en' => 'Hime Anyoji', 'card_type' => 'メンバー'];
        $right = ['instance_id' => 'hime-right', 'name_en' => 'Hime Anyoji', 'card_type' => 'メンバー'];
        $state = [
            'seq' => 1,
            'log' => [],
            'players' => [
                'p1' => [
                    'name' => 'P1',
                    'waiting_room' => $members,
                    'main_deck' => [],
                    'stage' => ['left' => $left, 'center' => null, 'right' => $right],
                ],
            ],
        ];
        $prompt = [
            'type' => 'optional_shuffle_wr_members_deck_bottom',
            'owner' => 'p1',
            'responder' => 'p1',
            'source_name' => 'Fanfare!!!',
            'subunit' => 'みらくらぱーく!',
            'min_subunit' => 15,
            'named' => 'Hime Anyoji',
            'blade' => 3,
        ];
        $state['pending_prompt'] = $prompt;

        $result = hsResolveHasunosoraPrompt($state, 'p1', $prompt, 'yes', []);

        $this->assertSame('pick_named_member_blade', $result['pending_prompt']['type'] ?? null);
        $this->assertSame(
            ['left', 'right'],
            array_column($result['pending_prompt']['candidates'] ?? [], 'slot')
        );
        $this->assertArrayNotHasKey('live_blade_bonus', $result['players']['p1']['stage']['left']);
        $this->assertArrayNotHasKey('live_blade_bonus', $result['players']['p1']['stage']['right']);

        $targetPrompt = $result['pending_prompt'];
        unset($targetPrompt['continue_live_start']);
        $resolved = hsResolveHasunosoraPrompt($result, 'p1', $targetPrompt, '', ['slot' => 'right']);

        $this->assertArrayNotHasKey('live_blade_bonus', $resolved['players']['p1']['stage']['left']);
        $this->assertSame(3, $resolved['players']['p1']['stage']['right']['live_blade_bonus'] ?? null);
    }
}
