<?php

declare(strict_types=1);

namespace LLTCG\Tests\Social;

use PHPUnit\Framework\TestCase;

final class SocialFriendCodeTest extends TestCase
{
    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/social.php';
        require_once dirname(__DIR__, 2) . '/chat_moderation.php';
    }

    public function testFriendCodesAreUniqueLcCrockford(): void
    {
        $seen = [];
        for ($i = 0; $i < 40; $i++) {
            $code = tcgSocialRandomFriendCode();
            $this->assertMatchesRegularExpression('/^LC[0-9A-HJKMNP-TV-Z]{6}$/', $code);
            $this->assertArrayNotHasKey($code, $seen);
            $seen[$code] = true;
        }
    }

    public function testSlurRejectedInBio(): void
    {
        $this->expectException(\Exception::class);
        tcgAssertProfileTextAllowed('retarded bio', 'bio', 100);
    }

    public function testLinkRejectedInBio(): void
    {
        $this->expectException(\Exception::class);
        tcgAssertProfileTextAllowed('see https://evil.example', 'bio', 100);
    }

    public function testCleanBioAllowed(): void
    {
        tcgAssertProfileTextAllowed('Hello from muse', 'bio', 100);
        $this->assertTrue(true);
    }
}
