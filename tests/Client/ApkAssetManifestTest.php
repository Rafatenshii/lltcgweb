<?php

declare(strict_types=1);

namespace LLTCG\Tests\Client;

use PHPUnit\Framework\TestCase;

final class ApkAssetManifestTest extends TestCase
{
    public function testEligibleUrlHelper(): void
    {
        require_once dirname(__DIR__, 2) . '/scripts/apk_asset_urls.php';
        $this->assertTrue(tcgApkAssetUrlEligible('cardimg.php?card_no=LL-E-001-SD&w=256'));
        $this->assertTrue(tcgApkAssetUrlEligible('https://loveliveradio.ca/tcg/assets/sleeves/foo.webp'));
        $this->assertTrue(tcgApkAssetUrlEligible('assets/playmats/bar.webp'));
        $this->assertTrue(tcgApkAssetUrlEligible('assets/stamps/image/sticker/st_000_010.png'));
        $this->assertTrue(tcgApkAssetUrlEligible('playmat.png'));
        $this->assertTrue(tcgApkAssetUrlEligible('lltcg-back.png'));
        $this->assertFalse(tcgApkAssetUrlEligible('api.php?action=get_state'));
        $this->assertFalse(tcgApkAssetUrlEligible('https://stream.loveliveradio.ca/tcg/api?action=get_state'));
        $this->assertFalse(tcgApkAssetUrlEligible('playmats_catalog.json'));
        $this->assertFalse(tcgApkAssetUrlEligible('sleeves_catalog.json'));
        $this->assertFalse(tcgApkAssetUrlEligible('data:image/png;base64,xx'));
    }

    public function testManifestShapeAndNoApiUrls(): void
    {
        require_once dirname(__DIR__, 2) . '/scripts/apk_asset_urls.php';
        $path = dirname(__DIR__, 2) . '/apk_asset_manifest.json';
        $this->assertFileExists($path);
        $data = json_decode((string)file_get_contents($path), true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('assets', $data);
        $this->assertGreaterThan(1000, count($data['assets']));
        $urls = [];
        foreach ($data['assets'] as $row) {
            $this->assertIsArray($row);
            $this->assertNotSame('', $row['url'] ?? '');
            $urls[] = $row['url'];
            $this->assertTrue(
                tcgApkAssetUrlEligible($row['url']),
                'ineligible url in manifest: ' . $row['url']
            );
            $this->assertStringNotContainsString('api.php', $row['url']);
            $this->assertStringNotContainsString('get_state', $row['url']);
        }
        $joined = implode("\n", $urls);
        $this->assertStringContainsString('cardimg.php?card_no=', $joined);
        $this->assertStringContainsString('&w=256', $joined);
        $this->assertStringContainsString('assets/sleeves/', $joined);
        $this->assertStringContainsString('assets/playmats/', $joined);
    }

    public function testServiceWorkerDoesNotClaimOrReplayEventRequest(): void
    {
        $sw = (string)file_get_contents(dirname(__DIR__, 2) . '/apk-asset-sw.js');
        $this->assertStringNotContainsString('clients.claim()', $sw);
        $this->assertStringNotContainsString('status: 504', $sw);
        $this->assertStringContainsString('_catalog', $sw);
        $this->assertStringContainsString('api.php', $sw);
        $this->assertStringContainsString('Accept-Ranges', $sw);
        $this->assertStringContainsString('status: 206', $sw);
        $this->assertStringContainsString('destination === \'audio\'', $sw);
    }

    public function testBuildScriptMatchesCommittedManifestCount(): void
    {
        require_once dirname(__DIR__, 2) . '/scripts/apk_asset_urls.php';
        $root = dirname(__DIR__, 2);
        $built = tcgBuildApkAssetManifestEntries($root);
        $data = json_decode((string)file_get_contents($root . '/apk_asset_manifest.json'), true);
        $this->assertSame(count($data['assets']), count($built));
    }
}
