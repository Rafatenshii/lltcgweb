#!/usr/bin/env node
/**
 * Stage / live long-press inspect contracts (source-level).
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
let failed = 0;
function fail(msg) { console.error(`FAIL: ${msg}`); failed = 1; }
function ok(msg) { console.log(`OK: ${msg}`); }

const indexSrc = fs.readFileSync(path.join(root, 'index.html'), 'utf8');
const boardSrc = fs.readFileSync(path.join(root, 'client', 'js', 'board-render.js'), 'utf8');

if (!indexSrc.includes('function bindBoardCardLongPressInspect')
    || !indexSrc.includes('function boardCardShowClickHandler')
    || !indexSrc.includes('_boardCardLongPressSuppressClickUntil')) {
  fail('index.html must define board long-press inspect helpers');
} else {
  ok('index.html defines board long-press inspect helpers');
}

const calls = (boardSrc.match(/bindBoardCardLongPressInspect\(/g) || []).length;
if (calls < 3) {
  fail(`board-render must bind long-press on stage/live/success (got ${calls})`);
} else {
  ok(`board-render binds long-press (${calls} call sites)`);
}

if (!(boardSrc.match(/boardCardShowClickHandler\(/g) || []).length) {
  fail('board-render must use boardCardShowClickHandler to suppress click-after-hold');
} else {
  ok('board-render uses click suppress helper');
}

if (!/live-storage-facedown/.test(indexSrc)
    || !/card\.revealed === false/.test(indexSrc)) {
  fail('long-press must skip face-down / unrevealed cards');
} else {
  ok('long-press skips face-down / unrevealed cards');
}

if (!indexSrc.includes('play-glow-valid')
    || !indexSrc.includes('G.selCard || G.drag?.iid')) {
  fail('long-press must yield to play-targeting on glowing stage slots');
} else {
  ok('long-press yields to play-targeting when a hand card is selected');
}

if (failed) {
  console.error('\nBoard long-press inspect checks failed.');
  process.exit(1);
}
console.log('\nBoard long-press inspect checks passed.');
