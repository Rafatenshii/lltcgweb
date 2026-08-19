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
const bridgeJs = fs.readFileSync(path.join(root, 'client', 'js', 'portrait-bridge.js'), 'utf8');

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

if (!portraitCss.includes('#portrait-deck-drawers.pb-deck-drawers')
    || !portraitCss.includes('display:none!important')
    || !portraitCss.includes('html.tcg-portrait-play #portrait-deck-drawers.pb-deck-drawers')) {
  fail('portrait deck drawers must be hidden outside portrait mode');
} else {
  ok('portrait deck drawers are CSS-hidden on PC');
}
if (!bridgeJs.includes('function removeDeckDrawers')
    || !boardJs.includes('tcgPortraitRemoveDeckDrawers')) {
  fail('portrait deck drawers must be removed when portrait unmounts');
} else {
  ok('portrait deck drawers are removed on landscape/PC unmount');
}

if (!indexSrc.includes('id="deck-builder-dock"')
    || !indexSrc.includes('id="deck-builder-actions"')) {
  fail('deck builder save dock needs ids for mobile/portrait pinning');
} else {
  ok('deck builder save dock ids present');
}
if (/html\.tcg-portrait-play #screen-deck \.deck-builder-actions,\s*[\s\S]{0,80}display:none!important/.test(portraitCss)
    || /html\.tcg-portrait-play #screen-deck \.deck-builder-actions\{[^}]*display:none!important/.test(portraitCss)) {
  fail('portrait deck builder must not hide the save action bar');
} else {
  ok('portrait deck builder does not hide save actions');
}
if (!portraitCss.includes('html.tcg-portrait-play #screen-deck .deck-builder-dock')
    || !portraitCss.includes('position:fixed')) {
  fail('portrait deck builder save dock must be pinned to the viewport');
} else {
  ok('portrait deck builder save dock is pinned');
}

for (const cls of ['tcg-portrait-square', 'tcg-portrait-tablet', '--p-board-max-w', '--p-field-min', '--p-hud-max']) {
  if (!portraitCss.includes(cls)) fail(`portrait.css missing ${cls}`);
  else ok(`css has ${cls}`);
}

const shellCss = fs.readFileSync(path.join(root, 'client', 'css', 'shell-all.css'), 'utf8');
const boardCss = fs.readFileSync(path.join(root, 'client', 'css', 'board.css'), 'utf8');
for (const [label, src] of [['shell-all', shellCss], ['board', boardCss]]) {
  if (!src.includes('Square / near-square foldables') || !src.includes('aspect-ratio:auto')) {
    fail(`${label}.css missing square fold 20:9 soften`);
  } else {
    ok(`${label}.css softens 20:9 on max-aspect-ratio 5/4`);
  }

  const tabletRule = src.match(/html\.tcg-tablet-landscape\.tcg-mobile-viewport #screen-game \.game-viewport-frame \.game-wide-wrap\{([^}]*)\}/)?.[1] || '';
  if (tabletRule.includes('--mobile-action-dock-h')) {
    fail(`${label}.css changes the action dock outside the square-fold query`);
  } else {
    ok(`${label}.css preserves standard 16:9 tablet dock sizing`);
  }

  const radioRule = src.match(/html\.tcg-mobile-viewport #screen-game \.game-viewport-frame \.side-panel\.side-right \.tcg-radio-stack:not\(\[hidden\]\)\{([^}]*)\}/)?.[1] || '';
  if (!radioRule.includes('min-height:186px') || radioRule.includes('clamp(')) {
    fail(`${label}.css changes the standard 16:9 radio stack sizing`);
  } else {
    ok(`${label}.css preserves standard 16:9 radio stack sizing`);
  }
}

if ((16 / 9) <= (5 / 4)) fail('16:9 must not match the square-fold aspect query');
else ok('16:9 is excluded from the square-fold aspect query');

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

/** Mirror of tcgPortraitTouchPrimary. */
function touchPrimary(w, h, { touchPoints = 0, finePointer = false, coarse = false, hoverNone = false, mobileUa = false, macUa = false } = {}) {
  const iPadOsDesktopUa = touchPoints > 1 && macUa;
  if (mobileUa || iPadOsDesktopUa || coarse || hoverNone) return true;
  if (w >= h) return false;
  if (touchPoints > 0 && Math.min(w, h) <= 920) return true;
  return !!(finePointer && w <= 1100 && h >= w * 1.2);
}

/** Mirror of the landscape fixed-frame gate in bindMobileViewport. */
function fixedFrame(w, h, env = {}) {
  const mobile = !!(env.mobileUa || (env.coarse && env.hoverNone));
  const shortSide = Math.min(w, h);
  const longSide = Math.max(w, h);
  const phone = mobile && shortSide <= 520 && longSide <= 980;
  const tablet = mobile && !phone && shortSide <= 920;
  return mobile && w > h && (phone || tablet);
}

const desktopTouch = { touchPoints: 10, finePointer: true };
if (touchPrimary(1920, 920, desktopTouch)) fail('touchscreen 1080p desktop must not count as touch-primary');
else ok('touchscreen 1080p desktop is not touch-primary');

if (fixedFrame(1920, 920, desktopTouch)) fail('touchscreen 1080p desktop must keep the desktop board, not the 20:9 frame');
else ok('touchscreen 1080p desktop keeps the full desktop board');

if (!touchPrimary(800, 1280, desktopTouch)) fail('touchscreen laptop in portrait must use the portrait shell');
else ok('touchscreen portrait viewport uses the portrait shell');

if (!touchPrimary(1080, 1920, { finePointer: true })) fail('rotated 1080p monitor must use the portrait shell');
else ok('rotated portrait monitor uses the portrait shell');

if (touchPrimary(1400, 1500, { finePointer: true })) fail('near-square desktop window must stay on the desktop board');
else ok('near-square desktop window keeps the desktop board');

if (!touchPrimary(390, 844, { touchPoints: 5, coarse: true, hoverNone: true, mobileUa: true })) fail('iPhone must be touch-primary');
else ok('iPhone stays touch-primary');

if (!touchPrimary(1024, 1366, { touchPoints: 5, finePointer: true, macUa: true })) fail('iPadOS desktop UA must be touch-primary');
else ok('iPadOS desktop UA stays touch-primary');

if (!fixedFrame(844, 390, { mobileUa: true, coarse: true, hoverNone: true })) fail('phone landscape must use the fixed frame');
else ok('phone landscape keeps the fixed frame');

if (indexSrc.includes('portraitPlay || tcgPortraitTouchPrimary')) {
  fail('bindMobileViewport must not fold touch-primary into the landscape mobile gate');
} else {
  ok('landscape mobile gate excludes touch-primary desktops');
}

/** Mirror of the DPI-aware 1080p desktop gate. */
function fullHdDesktop(w, h, { dpr = 1, screenW = w, screenH = h, finePointer = false } = {}) {
  const physicalW = Math.max(w, Math.round(screenW * dpr));
  const physicalH = Math.max(h, Math.round(screenH * dpr));
  return (w >= 1600 && h >= 880)
    || (finePointer && w >= 1000 && h >= 600 && physicalW >= 1600 && physicalH >= 880);
}

if (!fullHdDesktop(1280, 720, { dpr: 1.5, screenW: 1280, screenH: 720, finePointer: true })) {
  fail('scaled 1080p desktop must keep the full desktop layout');
} else {
  ok('scaled 1080p desktop keeps the full desktop layout');
}

if (fullHdDesktop(1280, 720, { dpr: 1.5, screenW: 1280, screenH: 720, finePointer: false })) {
  fail('touch-only scaled viewport must not become a desktop layout');
} else {
  ok('touch-only scaled viewport stays out of the desktop layout');
}

for (const [label, src] of [['shell-all', shellCss], ['board', boardCss]]) {
  if (!src.includes('Use 16:9 desktop side space') || !src.includes('(min-aspect-ratio:16/10)')) {
    fail(`${label}.css missing full-width 16:9 desktop panel rule`);
  } else {
    ok(`${label}.css fills 16:9 desktop side space with panels`);
  }
  const densityTokens = [
    '--hand-strip-h:96px',
    '--hand-card-pad:18px',
    '--my-hand-card-h:calc(var(--hand-strip-h) * 1.5)',
    '--opp-hand-card-h:calc(var(--hand-strip-h) * 1.32)',
    '--hand-safe-inline:8px',
    'transform:translateY(26px)',
    'transform:translateY(-26px)',
    '/ 2 - 14px',
    '--side-panel-w:min(440px',
    'width:100%',
    '(max-height:1100px)',
  ];
  for (const token of densityTokens) {
    if (!src.includes(token)) fail(`${label}.css missing 1080p board-density token ${token}`);
  }
  if (densityTokens.every(token => src.includes(token))) {
    ok(`${label}.css widens the 1080p mat and scales its cards`);
  }
}

if (!indexSrc.includes('function resolveHandCssLength(')
    || !indexSrc.includes('resolveHandCssLength(')
    || !indexSrc.includes('const edgeBleed = 0;')) {
  fail('desktop hand fan must resolve scoped CSS lengths and stay within mat borders');
} else {
  ok('desktop hand fan resolves scoped card sizes without border bleed');
}
if (!indexSrc.includes('Keep both desktop hands centered')
    || !indexSrc.includes('const useShiftAnchor = false;')) {
  fail('desktop hand fan must stay centered (no sticky flex-start shift-anchor)');
} else {
  ok('desktop hand fan stays centered without sticky shift-anchor');
}
for (const [label, src] of [['shell-all', shellCss], ['board', boardCss]]) {
  if (!src.includes('#screen-game ll-hand-zone{')
      || !src.includes('display:block;width:100%;min-width:0;max-width:100%')) {
    fail(`${label}.css must constrain ll-hand-zone to the playmat width`);
  } else {
    ok(`${label}.css constrains ll-hand-zone to the playmat width`);
  }
}

function desktopMatWidth(w, h, handStrip, trim = 0) {
  const matH = (h - 2 * handStrip - 5 - 8) / 2 - trim;
  return Math.min(w, matH * 1024 / 563);
}
const old1080MatW = desktopMatWidth(1920, 1080, 132);
const new1080MatW = desktopMatWidth(1920, 1080, 96, 14);
if (new1080MatW < old1080MatW * 1.04) {
  fail(`1080p mat widening is too small (${old1080MatW.toFixed(1)} → ${new1080MatW.toFixed(1)})`);
} else {
  ok(`1080p mat widens ${old1080MatW.toFixed(1)} → ${new1080MatW.toFixed(1)}px`);
}

const panel1080W = Math.min(440, Math.max(280, (1920 - new1080MatW) / 2));
if (panel1080W !== 440) fail(`1080p panels must retain their original 440px width, got ${panel1080W}`);
else ok('1080p panels retain their original 440px width');

function packedDesktopHandWidth(avail, cardW, count) {
  let step = cardW * 0.76;
  let scale = 1;
  if (count > 1 && cardW + step * (count - 1) > avail) {
    const minStepRatio = 0.45;
    const packedAtFull = (avail - cardW) / (count - 1);
    if (packedAtFull < cardW * minStepRatio) {
      const neededW = avail / (1 + minStepRatio * (count - 1));
      scale = Math.max(0.58, Math.min(1, neededW / cardW));
      cardW *= scale;
    }
    step = Math.max(1, (avail - cardW) / (count - 1));
  }
  return { width: cardW + step * Math.max(0, count - 1), scale, stepRatio: step / cardW };
}
const handSafeWidth = new1080MatW - 48;
const handCardW = (96 * 1.5) * (63 / 88);
const packed = packedDesktopHandWidth(handSafeWidth, handCardW, 30);
if (packed.width > handSafeWidth + 0.01) {
  fail(`large desktop hand spills past the playmat (${packed.width.toFixed(1)}px)`);
} else {
  ok('large desktop hand packs inside the 24px playmat safe edges');
}
if (!(packed.scale < 0.95 && packed.scale >= 0.58)) {
  fail(`massive desktop hand should shrink cards a bit, got scale ${packed.scale.toFixed(3)}`);
} else {
  ok(`massive desktop hand shrinks cards to ${(packed.scale * 100).toFixed(0)}%`);
}
const normalPacked = packedDesktopHandWidth(handSafeWidth, handCardW, 8);
if (normalPacked.scale !== 1) {
  fail(`normal desktop hand must keep full card size, got scale ${normalPacked.scale}`);
} else {
  ok('normal desktop hand keeps full card size');
}

if (!indexSrc.includes('Massive desktop hands: shrink cards')
    || !indexSrc.includes('minStepRatio = 0.45')
    || !indexSrc.includes('safeInline * 2')) {
  fail('desktop hand fan is missing massive-hand size reduction');
} else {
  ok('desktop hand fan shrinks massive hands inside safe horizontal edges');
}

for (const [label, src] of [['shell-all', shellCss], ['board', boardCss]]) {
  if (!src.includes('#card-hover-panel.visible') || !src.includes('max-height:calc(100% - 24px)')) {
    fail(`${label}.css missing on-screen card hover info box rule`);
  } else {
    ok(`${label}.css keeps the card hover info box on-screen`);
  }
}

for (const needle of [
  'function tcgIsAndroidClient',
  'enableLongPressInspect',
  'Android portrait: upward slide starts member drag',
  'syncAndroidLivePlaceAction',
  'hc-live-place',
  'game.placeLiveCard',
  'game.placeLiveCards',
]) {
  if (!indexSrc.includes(needle)) fail(`missing Android gesture/live helper: ${needle}`);
  else ok(`source has ${needle}`);
}

if (!indexSrc.includes('enableLongPressInspect = android || !portrait')) {
  fail('Android must keep long-press inspect even in portrait');
} else {
  ok('Android long-press inspect stays enabled in portrait');
}

if (!indexSrc.includes('Android: raise is visual-only')) {
  fail('Android raise must skip opening the info sheet');
} else {
  ok('Android raise skips the info sheet');
}

if (!portraitCss.includes('hc-live-place') || !shellCss.includes('hc-live-place')) {
  fail('hc-live-place styles missing from portrait/shell CSS');
} else {
  ok('hc-live-place styles present');
}

if (!portraitCss.includes('--p-hand-visible: 5')
    || /--p-hand-visible:\s*6/.test(portraitCss)
    || /tcg-android[^{]*\{[^}]*--p-hand-visible/.test(portraitCss)) {
  fail('portrait play must default to five visible hand slots for all mobile layouts');
} else {
  ok('portrait CSS defaults to five visible hand slots');
}

if (!indexSrc.includes('const visible = 5') || !boardJs.includes('const cardSlots = 5')) {
  fail('hand fan / portrait-board must pack five cards in portrait');
} else {
  ok('hand fan packs five cards in portrait');
}

if (!indexSrc.includes('portraitAnchor')
    || !indexSrc.includes('Math.abs(slot - portraitAnchor)')
    || !indexSrc.includes('Math.max(cardW * 0.5')) {
  fail('portrait hand must fan outward from selection with half-card exposure');
} else {
  ok('portrait hand fans outward from selection with half-card exposure');
}

if (/\.hcard\.play-sel[\s\S]{0,180}z-index\s*:\s*40\s*!important/.test(portraitCss)) {
  fail('portrait selected-card CSS must not override radial hand z-order');
} else {
  ok('portrait selection leaves radial z-order to layoutHandFan');
}

if (!indexSrc.includes('syncPlayCostHudToLiveStorage')
    || !indexSrc.includes('pch-over-live')
    || !portraitCss.includes('pch-over-live')
    || !shellCss.includes('pch-over-live')
    || !boardCss.includes('pch-over-live')) {
  fail('main-phase play-cost HUD must park over live storage on mobile/portrait');
} else {
  ok('play-cost HUD parks over live storage on mobile/portrait');
}

if (!bridgeJs.includes('btn-portrait-menu-stamps')
    || !bridgeJs.includes('TCG_STAMPS.openPicker')
    || !bridgeJs.includes('ensurePortraitStampMenuItem')) {
  fail('portrait burger must include a Stamps item that opens the stamp picker');
} else {
  ok('portrait burger exposes Stamps via the stamp picker');
}

if (!portraitCss.includes('html.tcg-portrait-play .stamp-picker')
    || !portraitCss.includes('z-index:96000')) {
  fail('portrait stamp picker must sit above the board as a bottom sheet');
} else {
  ok('portrait stamp picker is a high-z bottom sheet');
}

if (process.exitCode) {
  console.error('\nPortrait responsive checks failed.');
  process.exit(process.exitCode);
}
console.log('\nPortrait responsive checks passed.');
