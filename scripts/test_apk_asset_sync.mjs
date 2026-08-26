#!/usr/bin/env node
/**
 * APK full-mode must sync missing manifest assets after a completed pack.
 * node scripts/test_apk_asset_sync.mjs
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const src = fs.readFileSync(path.join(root, 'client/js/apk-asset-cache.js'), 'utf8');
const indexSrc = fs.readFileSync(path.join(root, 'index.html'), 'utf8');

let failed = 0;
function check(name, cond) {
  if (cond) console.log(`OK: ${name}`);
  else {
    console.error(`FAIL: ${name}`);
    failed += 1;
  }
}

check('defines urlsMissingFromCache', /function urlsMissingFromCache/.test(src));
check('persists manifest stamp', /MANIFEST_STAMP_KEY/.test(src));
check('boot syncs full mode even when complete', /if \(mode === 'full'\) void runFullQueue\(\)/.test(src));
check('does not gate boot on !isFullComplete only', !/if \(mode === 'full' && !isFullComplete\(\)\) void runFullQueue\(\)/.test(src));
check('update progress copy', /options\.apkAssets\.updating/.test(src));
check('sets syncKind update', /syncKind = wasComplete \? 'update'/.test(src));
check('index loads apk-asset-cache v8+', /apk-asset-cache\.js\?v=([8-9]|\d{2,})/.test(indexSrc));

if (failed) {
  console.error(`\n${failed} check(s) failed`);
  process.exit(1);
}
console.log('\nAll apk-asset sync checks passed.');
