#!/usr/bin/env php
<?php
/**
 * Ability IR lint — unknown types fail; required params enforced for catalogued types.
 * See docs/overhaul/03-ability-ir.md
 */
$root = dirname(__DIR__);
if (is_file($root . '/vendor/autoload.php')) {
    require_once $root . '/vendor/autoload.php';
} else {
    require_once $root . '/src/Game/EffectRegistry.php';
    require_once $root . '/src/Game/EffectHandlers.php';
}

use LLTCG\Game\EffectRegistry;

$path = $root . '/cards.json';
if (!is_file($path)) {
    fwrite(STDERR, "MISSING cards.json\n");
    exit(1);
}
$data = json_decode((string) file_get_contents($path), true);
if (!is_array($data)) {
    fwrite(STDERR, "INVALID cards.json\n");
    exit(1);
}

$known = array_fill_keys(EffectRegistry::knownAbilityTypes(), true);
$schema = EffectRegistry::typeParamSchema();
$errors = 0;
$checked = 0;

foreach ($data['cards'] ?? [] as $card) {
    $cardNo = (string) ($card['card_no'] ?? '');
    foreach ($card['abilities'] ?? [] as $i => $ab) {
        if (!is_array($ab)) {
            fwrite(STDERR, "ERROR $cardNo ability #$i: not an object\n");
            $errors++;
            continue;
        }
        $checked++;
        $type = trim((string) ($ab['type'] ?? ''));
        if ($type === '') {
            fwrite(STDERR, "ERROR $cardNo ability #$i: missing type\n");
            $errors++;
            continue;
        }
        if (!isset($known[$type])) {
            fwrite(STDERR, "ERROR $cardNo: unknown ability type '$type'\n");
            $errors++;
            continue;
        }
        if (isset($schema[$type])) {
            foreach ($schema[$type] as $key) {
                if (!array_key_exists($key, $ab)) {
                    fwrite(STDERR, "ERROR $cardNo ($type): missing required param '$key'\n");
                    $errors++;
                }
            }
        }
    }
}

echo "Ability IR: checked $checked abilities against " . count($known) . " known types.\n";
if ($errors > 0) {
    fwrite(STDERR, "FAIL: $errors error(s)\n");
    exit(1);
}
echo "OK\n";
exit(0);
