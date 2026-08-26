#!/usr/bin/env node
/**
 * ?resign softlock escape must not fire on mid-match ?debug reloads.
 * node scripts/test_resign_escape.mjs
 */
import fs from 'node:fs';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const require = createRequire(import.meta.url);
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const api = require(path.join(root, 'client/js/resign-escape.js'));
const indexSrc = fs.readFileSync(path.join(root, 'index.html'), 'utf8');

let failed = 0;
function check(name, cond) {
  if (cond) console.log(`OK: ${name}`);
  else {
    console.error(`FAIL: ${name}`);
    failed += 1;
  }
}

const plan = api.planResignEscapeFromSearch;

check('bare ?debug does not escape', plan('?debug').escape === false);
check('bare ?debug clears stale flag', plan('?debug').clearStale === true);
check('?debug=all does not escape', plan('?debug=all').escape === false);
check('?debug=poll,live does not escape', plan('?debug=poll,live').escape === false);
check('empty search clears stale', plan('').clearStale === true && plan('').escape === false);

check('bare ?resign escapes', plan('?resign').escape === true);
check('?resign=1 escapes', plan('?resign=1').escape === true);
check('?resign=true escapes', plan('?resign=true').escape === true);
check('?resign=0 does not escape', plan('?resign=0').escape === false);
check('?resign=false clears stale', plan('?resign=false').clearStale === true);

const both = plan('?debug&resign');
check('?debug&resign still escapes (explicit softlock)', both.escape === true);
check('?debug&resign strips resign, keeps debug', both.remainingSearch === '?debug' || both.remainingSearch === '?debug=');

const debugFirst = plan('?resign=1&debug=all');
check('?resign=1&debug=all escapes', debugFirst.escape === true);
check('strips resign only', debugFirst.remainingSearch === '?debug=all');

check('hasDebugQuery recognizes ?debug', api.hasDebugQuery('?debug') === true);
check('hasDebugQuery ignores bare resign', api.hasDebugQuery('?resign') === false);

check('index loads resign-escape.js', /client\/js\/resign-escape\.js/.test(indexSrc));
check('index uses planResignEscapeFromSearch', /planResignEscapeFromSearch/.test(indexSrc));
check(
  'index does not peek stale flag without capture',
  /captureResignEscapeFromUrl\(\)\s*;/.test(indexSrc)
    || !/captureResignEscapeFromUrl\(\)\s*\|\|\s*peekResignEscape\(\)/.test(indexSrc),
);

if (failed) {
  console.error(`\n${failed} check(s) failed`);
  process.exit(1);
}
console.log('\nAll resign-escape checks passed.');
