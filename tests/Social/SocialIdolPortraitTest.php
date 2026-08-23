<?php

declare(strict_types=1);

namespace LLTCG\Tests\Social;

use PHPUnit\Framework\TestCase;

final class SocialIdolPortraitTest extends TestCase
{
    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/social.php';
    }

    public function testSubstringNamesDoNotStealShorterIdols(): void
    {
        $umi = tcgSocialIdolPortraitUrl('Umi');
        $rin = tcgSocialIdolPortraitUrl('Rin');
        $this->assertNotSame('', $umi);
        $this->assertNotSame('', $rin);

        $this->assertNotSame($umi, tcgSocialIdolPortraitUrl('Izumi'));
        $this->assertNotSame($umi, tcgSocialIdolPortraitUrl('Natsumi'));
        $this->assertNotSame($umi, tcgSocialIdolPortraitUrl('Sumire'));
        $this->assertNotSame($rin, tcgSocialIdolPortraitUrl('Rurino'));
        $this->assertNotSame($rin, tcgSocialIdolPortraitUrl('Rina'));
    }

    public function testFullNamesMatchGivenNameToken(): void
    {
        $this->assertSame(tcgSocialIdolPortraitUrl('Umi'), tcgSocialIdolPortraitUrl('Umi Sonoda'));
        $this->assertSame(tcgSocialIdolPortraitUrl('Honoka'), tcgSocialIdolPortraitUrl('Honoka Kosaka'));
        $this->assertSame(tcgSocialIdolPortraitUrl('Izumi'), tcgSocialIdolPortraitUrl('Izumi Yumemi'));
        $this->assertSame(tcgSocialIdolPortraitUrl('Rurino'), tcgSocialIdolPortraitUrl('Rurino Osawa'));
    }
}
