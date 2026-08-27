#!/usr/bin/env php
<?php
/**
 * Convert schema-v1 (baseline+actions) replay JSON files to schema-v2 (frames).
 *
 * Usage:
 *   php scripts/convert_replays_to_v2.php path/to/replay.json [output.json]
 *   php scripts/convert_replays_to_v2.php path/to/dir/   # in-place *.json
 */
if ($argc < 2) {
    fwrite(STDERR, "Usage: php scripts/convert_replays_to_v2.php <file-or-dir> [output.json]\n");
    exit(1);
}

$root = dirname(__DIR__);
define('TCG_API_LIB_ONLY', true);
require_once $root . '/api.php';

function convertOneFile(string $in, string $out): void {
    $replay = json_decode((string)file_get_contents($in), true);
    if (!is_array($replay)) {
        throw new RuntimeException("Invalid JSON: $in");
    }
    $v2 = ensureReplayPayloadV2($replay);
    $json = json_encode($v2, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        throw new RuntimeException("Encode failed: $in");
    }
    file_put_contents($out, $json . "\n");
    $n = count($v2['frames'] ?? []);
    echo "OK $in → $out (frames=$n)\n";
}

$target = $argv[1];
if (is_dir($target)) {
    $files = glob(rtrim($target, '/\\') . '/*.json') ?: [];
    if ($files === []) {
        fwrite(STDERR, "No JSON files in $target\n");
        exit(1);
    }
    foreach ($files as $f) {
        convertOneFile($f, $f);
    }
    exit(0);
}

$out = $argv[2] ?? $target;
convertOneFile($target, $out);
