<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/** Regression: Music S.T.A.R.T!! Success Live cost reduction (issue #88). */
final class Issue88MusicStartCostReductionTest extends TestCase
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

    private function baseState(): array
    {
        return [
            'status' => 'playing',
            'phase' => 'main_first',
            'seq' => 1,
            'turn' => 3,
            'first_player' => 'p1',
            'active_player' => 'p1',
            'live_attempt' => ['p1', 'p2'],
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [],
                    'waiting_room' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'energy_zone' => [],
                    'main_deck' => [],
                    'success_lives' => [],
                    'live_zone' => [],
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'P2',
                    'hand' => [],
                    'waiting_room' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'energy_zone' => [],
                    'main_deck' => [],
                    'success_lives' => [],
                    'live_zone' => [],
                ],
            ],
        ];
    }

    public function testMusicStartReducesCost17MuseMember(): void
    {
        $maki = $this->cardByNo('PL!-bp6-006-P', 'issue88_maki');
        $music = $this->cardByNo('PL!-bp6-019-L', 'issue88_music');
        $this->assertSame(17, intval($maki['cost'] ?? 0));

        $state = $this->baseState();
        $this->assertSame(17, \getEffectiveHandCost($state, 'p1', $maki));

        $state['players']['p1']['success_lives'] = [$music];
        $this->assertSame(15, \getEffectiveHandCost($state, 'p1', $maki));
    }

    public function testMusicStartReducesPr015MakiAndBatonPay(): void
    {
        $maki = $this->cardByNo('PL!-PR-015-PR', 'issue88_maki_pr015');
        $kotori = $this->cardByNo('PL!-bp5-003-R＋', 'issue88_kotori11');
        $music = $this->cardByNo('PL!-bp6-019-L', 'issue88_music_pr015');
        $this->assertSame(17, intval($maki['cost'] ?? 0));
        $this->assertSame(11, intval($kotori['cost'] ?? 0));

        $state = $this->baseState();
        $state['players']['p1']['success_lives'] = [$music];
        $this->assertSame(15, \getEffectiveHandCost($state, 'p1', $maki));
        $this->assertSame(
            4,
            \computeMemberPlayCostWithBaton($state, 'p1', $maki, $kotori),
            'Music S.T.A.R.T!! 15 minus Kotori 11'
        );
    }

    public function testMusicStartDoesNotStackAndIgnoresLowCost(): void
    {
        $music = $this->cardByNo('PL!-bp6-019-L', 'issue88_music2');
        $musicCopy = $this->cardByNo('PL!-bp6-019-L', 'issue88_music3');
        $data = json_decode((string) file_get_contents((string) constant('CARDS_FILE')), true);
        $cheap = null;
        foreach ($data['cards'] ?? [] as $card) {
            if (($card['group'] ?? '') !== "μ's" || ($card['card_type'] ?? '') !== 'メンバー') {
                continue;
            }
            if (intval($card['cost'] ?? 0) >= 17) {
                continue;
            }
            $cheap = $card;
            $cheap['instance_id'] = 'issue88_cheap';
            break;
        }
        $this->assertNotNull($cheap);

        $state = $this->baseState();
        $state['players']['p1']['success_lives'] = [$music, $musicCopy];

        $maki = $this->cardByNo('PL!-bp6-006-P', 'issue88_maki2');
        $this->assertSame(15, \getEffectiveHandCost($state, 'p1', $maki), 'does not stack');
        $this->assertSame(
            intval($cheap['cost'] ?? 0),
            \getEffectiveHandCost($state, 'p1', $cheap),
            'cost under 17 unchanged'
        );
    }

    public function testMusicStartAppliesEvenIfMemberHasNoAbilities(): void
    {
        $data = json_decode((string) file_get_contents((string) constant('CARDS_FILE')), true);
        $blank = null;
        foreach ($data['cards'] ?? [] as $card) {
            if (($card['group'] ?? '') !== "μ's" || ($card['card_type'] ?? '') !== 'メンバー') {
                continue;
            }
            if (intval($card['cost'] ?? 0) < 17) {
                continue;
            }
            if (!empty($card['abilities'])) {
                continue;
            }
            $blank = $card;
            $blank['instance_id'] = 'issue88_blank17';
            break;
        }
        if ($blank === null) {
            $this->markTestSkipped('No ability-less μ\'s Member with cost ≥17 in catalog');
        }

        $music = $this->cardByNo('PL!-bp6-019-L', 'issue88_music_blank');
        $state = $this->baseState();
        $state['players']['p1']['success_lives'] = [$music];
        $printed = intval($blank['cost'] ?? 0);
        $this->assertSame($printed - 2, \getEffectiveHandCost($state, 'p1', $blank));
    }
}
