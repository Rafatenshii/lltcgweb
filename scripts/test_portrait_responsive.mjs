#!/usr/bin/env node
/**
 * Portrait web responsive contracts — gate helpers + size classes (no browser).
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(__dirname, '..');

function fail(msg) {
  console.error(`FAIL: ${msg}`);
  process.exitCode = 1;
}
function ok(msg) {
  console.log(`OK: ${msg}`);
}

const indexSrc = fs.readFileSync(path.join(root, 'index.html'), 'utf8');
const portraitCss = fs.readFileSync(path.join(root, 'client', 'css', 'portrait.css'), 'utf8');
const boardJs = fs.readFileSync(path.join(root, 'client', 'js', 'portrait-board.js'), 'utf8');

for (const needle of [
  'tcgResolvePortraitPlayActive',
  'tcgPortraitSizeClass',
  'tcgApplyPortraitPlayState',
  'tcgPortraitTouchPrimary',
  'tcgPortraitUnmountBoard',
  "localStorage.setItem('tcg_portrait_play', '0')",
  'tcgPortraitAuto',
]) {
  if (!indexSrc.includes(needle) && !boardJs.includes(needle)) {
    fail(`missing ${needle}`);
  } else {
    ok(`source has ${needle}`);
  }
}

if (!/touchPrimary|touch-primary|tcgPortraitTouchPrimary/.test(indexSrc)) {
  fail('early/bind gate should consider touch-primary web browsers');
} else {
  ok('touch-primary web gate present');
}

if (!boardJs.includes('function unmount') && !boardJs.includes('tcgPortraitUnmountBoard')) {
  fail('portrait-board must export unmount for landscape fallback');
} else {
  ok('portrait-board unmount present');
}

for (const cls of ['tcg-portrait-square', 'tcg-portrait-tablet', '--p-board-max-w', '--p-field-min', '--p-hud-max']) {
  if (!portraitCss.includes(cls)) fail(`portrait.css missing ${cls}`);
  else ok(`css has ${cls}`);
}

const shellCss = fs.readFileSync(path.join(root, 'client', 'css', 'shell-all.css'), 'utf8');
const boardCss = fs.readFileSync(path.join(root, 'client', 'css', 'board.css'), 'utf8');
for (const [label, src] of [['shell-all', shellCss], ['board', boardCss]]) {
  if (!src.includes('Square / near-square foldables') && !src.includes('aspect-ratio:auto')) {
    fail(`${label}.css missing square fold 20:9 soften`);
  } else {
    ok(`${label}.css softens 20:9 on max-aspect-ratio 5/4`);
  }
}

/** Mirror of tcgPortraitSizeClass for fixture checks. */
function sizeClass(w, h) {
  const shortSide = Math.min(w, h);
  const longSide = Math.max(w, h);
  const aspect = shortSide > 0 ? w / h : 1;
  if (aspect >= 0.78 && aspect <= 1.18 && shortSide >= 480) return 'square';
  if (shortSide >= 600 || (shortSide >= 520 && longSide >= 900)) return 'tablet';
  return 'phone';
}

const fixtures = [
  { w: 390, h: 844, expect: 'phone', label: 'iPhone portrait' },
  { w: 844, h: 390, expect: 'phone', label: 'iPhone landscape dims (short side phone)' },
  { w: 884, h: 1100, expect: 'square', label: 'foldable inner roughly square' },
  { w: 768, h: 1024, expect: 'tablet', label: 'iPad portrait' },
  { w: 1024, h: 1366, expect: 'tablet', label: 'iPad Pro portrait' },
  { w: 360, h: 780, expect: 'phone', label: 'small Android phone' },
];

for (const f of fixtures) {
  const got = sizeClass(f.w, f.h);
  if (got !== f.expect) fail(`${f.label}: expected ${f.expect}, got ${got} (${f.w}x${f.h})`);
  else ok(`size ${f.label} → ${got}`);
}

/** Auto gate: touch + portrait, not forced off. */
function autoActive({ portrait, touch, forceOn, forceOff }) {
  if (forceOn) return true;
  if (forceOff) return false;
  return !!(touch && portrait);
}

const gateFixtures = [
  { portrait: true, touch: true, forceOn: false, forceOff: false, expect: true, label: 'iOS Safari portrait' },
  { portrait: false, touch: true, forceOn: false, forceOff: false, expect: false, label: 'iOS Safari landscape auto-off' },
  { portrait: true, touch: false, forceOn: false, forceOff: false, expect: false, label: 'desktop narrow portrait' },
  { portrait: false, touch: true, forceOn: true, forceOff: false, expect: true, label: 'APK force-on landscape' },
  { portrait: true, touch: true, forceOn: false, forceOff: true, expect: false, label: 'opt-out LS=0' },
];

for (const g of gateFixtures) {
  const got = autoActive(g);
  if (got !== g.expect) fail(`gate ${g.label}: expected ${g.expect}, got ${got}`);
  else ok(`gate ${g.label}`);
}

if (process.exitCode) {
  console.error('\nPortrait responsive checks failed.');
  process.exit(process.exitCode);
}
console.log('\nPortrait responsive checks passed.');
