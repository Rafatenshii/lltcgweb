<?php
/**
 * Write apk_asset_manifest.json (cardimg.php variants + served cosmetics/SFX).
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/scripts/apk_asset_urls.php';

$assets = tcgBuildApkAssetManifestEntries($root);
$payload = [
    'version' => 1,
    'generated_at' => gmdate('c'),
    'count' => count($assets),
    'assets' => $assets,
];
$json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if ($json === false) {
    fwrite(STDERR, "encode failed\n");
    exit(1);
}
$dest = $root . '/apk_asset_manifest.json';
if (file_put_contents($dest, $json) === false) {
    fwrite(STDERR, "write failed: $dest\n");
    exit(1);
}
echo $dest . ' (' . count($assets) . " urls)\n";
