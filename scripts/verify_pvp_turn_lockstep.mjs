#!/usr/bin/env node
/**
 * Two-browser PvP lockstep check: both clients must share phase / active_player /
 * live_show.stage within a short window after turn handoffs and live-set end.
 *
 *   node scripts/verify_pvp_turn_lockstep.mjs
 *   TCG_BASE=https://loveliveradio.ca/tcg/ node scripts/verify_pvp_turn_lockstep.mjs --headed
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(__dirname, '..');
const OUT_DIR = path.join(ROOT, 'scripts', 'e2e-output');
const PW_ROOT = process.env.PLAYWRIGHT_PATH
  || 'C:/Users/super/tools/playwright-mcp/node_modules/playwright/index.mjs';

const BASE = (process.env.TCG_BASE || 'https://loveliveradio.ca/tcg/').replace(/\/?$/, '/');
const API = `${BASE}api.php`;
const CACHE_BUST = process.env.TCG_NOCACHE || Date.now();
const PAGE_URL = `${BASE}?nocache=${CACHE_BUST}&debug=live,apply,poll,sync,pvp`;
const headed = process.argv.includes('--headed');
const MAX_SKEW_MS = Number(process.env.TCG_LOCKSTEP_SKEW_MS || 4500);

const { chromium } = await import(pathToFileURL(PW_ROOT).href);

async function apiPost(action, body) {
  const r = await fetch(`${API}?action=${action}`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  });
  const d = await r.json();
  if (d?.error) throw new Error(`${action}: ${d.error}`);
  if (!r.ok) throw new Error(`${action}: HTTP ${r.status}`);
  return d;
}

async function getState(roomId, token) {
  const url = `${API}?action=get_state&room_id=${encodeURIComponent(roomId)}&token=${encodeURIComponent(token)}&seq=0&poll=0`;
  const r = await fetch(url);
  const d = await r.json();
  if (d?.error) throw new Error(`get_state: ${d.error}`);
  return d;
}

async function gameAction(roomId, token, type, data = {}) {
  return apiPost('action', { room_id: roomId, token, type, data });
}

function snapKey(s) {
  if (!s) return 'null';
  const show = s.live_show;
  return [
    s.seq,
    s.phase,
    s.active_player,
    s.turn,
    show?.stage || '-',
    show?.stage_seq ?? '-',
    s.pending_prompt?.type || '-',
    s.pending_prompt?.responder || '-',
  ].join('|');
}

async function clientSnap(page) {
  return page.evaluate(() => {
    const s = window.G?.gameState;
    if (!s) return null;
    const msg = document.querySelector('#phase-msg')?.textContent?.trim() || '';
    return {
      seq: s.seq,
      phase: s.phase,
      active_player: s.active_player,
      turn: s.turn,
      live_show_stage: s.live_show?.stage || null,
      live_show_seq: s.live_show?.stage_seq ?? null,
      prompt: s.pending_prompt?.type || null,
      prompt_responder: s.pending_prompt?.responder || null,
      my_id: window.G.playerId,
      apiOrigin: window.G.apiOrigin || null,
      spectacle: !!window.G._perfSpectacleActive,
      pollHold: !!window.G._livePollHold,
      runner: !!window.G._liveShowRunnerActive,
      phaseMsg: msg.slice(0, 120),
      lastSeq: window.G.lastSeq ?? null,
    };
  });
}

async function waitLockstep(pages, predicate, label, timeoutMs = 20000) {
  const start = Date.now();
  let last = null;
  while (Date.now() - start < timeoutMs) {
    const snaps = await Promise.all(pages.map(clientSnap));
    last = snaps;
    if (predicate(snaps)) {
      const skew = Date.now() - start;
      return { ok: true, snaps, skewMs: skew, label };
    }
    await new Promise(r => setTimeout(r, 200));
  }
  return { ok: false, snaps: last, skewMs: Date.now() - start, label };
}

function sameTurnCursor(snaps) {
  if (!snaps?.[0] || !snaps?.[1]) return false;
  const a = snaps[0];
  const b = snaps[1];
  return a.phase === b.phase
    && a.active_player === b.active_player
    && a.turn === b.turn
    && a.seq === b.seq
    && (a.live_show_stage || null) === (b.live_show_stage || null)
    && (a.live_show_seq ?? null) === (b.live_show_seq ?? null);
}

async function bootPlayer(page, session) {
  await page.goto(PAGE_URL, { waitUntil: 'domcontentloaded', timeout: 60000 });
  await page.evaluate((sess) => {
    sessionStorage.setItem('tcg_active_game', JSON.stringify(sess));
  }, session);
  await page.reload({ waitUntil: 'domcontentloaded', timeout: 60000 });
  await page.waitForFunction(
    () => typeof window.G !== 'undefined' && window.G.gameState && window.G.gameState.phase,
    { timeout: 45000 },
  );
}

async function setupRoom() {
  const p1 = await apiPost('create_room', {
    name: 'Lockstep P1',
    deck: 'nijigasaki',
    phase_timer_enabled: false,
  });
  const p2 = await apiPost('join_room', {
    room_id: p1.room_id,
    name: 'Lockstep P2',
    deck: 'nijigasaki',
    first_player: 'p1',
  });
  await gameAction(p1.room_id, p1.player_token, 'mulligan', { card_ids: [] });
  await gameAction(p1.room_id, p2.player_token, 'mulligan', { card_ids: [] });
  return {
    roomId: p1.room_id,
    p1: { token: p1.player_token },
    p2: { token: p2.player_token },
  };
}

fs.mkdirSync(OUT_DIR, { recursive: true });
const stamp = new Date().toISOString().replace(/[:.]/g, '-');
const results = [];

const browser = await chromium.launch({ headless: !headed });
try {
  const room = await setupRoom();
  console.log('room', room.roomId);

  const ctx1 = await browser.newContext({ viewport: { width: 1280, height: 800 } });
  const ctx2 = await browser.newContext({ viewport: { width: 1280, height: 800 } });
  const p1Page = await ctx1.newPage();
  const p2Page = await ctx2.newPage();
  const pages = [p1Page, p2Page];

  await Promise.all([
    bootPlayer(p1Page, {
      roomId: room.roomId, token: room.p1.token, playerId: 'p1', isCPU: false, screen: 'game',
    }),
    bootPlayer(p2Page, {
      roomId: room.roomId, token: room.p2.token, playerId: 'p2', isCPU: false, screen: 'game',
    }),
  ]);

  // 1) Both on main after setup
  let check = await waitLockstep(pages, (snaps) => (
    sameTurnCursor(snaps)
    && (snaps[0].phase === 'main_first' || snaps[0].phase === 'main_second')
  ), 'both-on-main');
  results.push(check);
  console.log(check.ok ? 'PASS' : 'FAIL', check.label, `skew=${check.skewMs}ms`, check.snaps?.map(snapKey));

  // 2) P1 ends main → both must see live_set (or P2 main) with same cursor
  await gameAction(room.roomId, room.p1.token, 'end_main', {});
  check = await waitLockstep(pages, (snaps) => (
    sameTurnCursor(snaps)
    && snaps[0].phase !== 'main_first'
    && snaps[0].active_player != null
  ), 'after-p1-end-main', 25000);
  results.push(check);
  console.log(check.ok ? 'PASS' : 'FAIL', check.label, `skew=${check.skewMs}ms`, check.snaps?.map(s => ({
    key: snapKey(s), phaseMsg: s?.phaseMsg, origin: s?.apiOrigin,
  })));

  // If still on P2 main, end that too to reach live_set
  let server = await getState(room.roomId, room.p1.token);
  if (server.phase === 'main_first' || server.phase === 'main_second') {
    const tok = server.active_player === 'p1' ? room.p1.token : room.p2.token;
    await gameAction(room.roomId, tok, 'end_main', {});
  }

  check = await waitLockstep(pages, (snaps) => (
    sameTurnCursor(snaps) && snaps[0].phase === 'live_set'
  ), 'both-on-live-set', 25000);
  results.push(check);
  console.log(check.ok ? 'PASS' : 'FAIL', check.label, `skew=${check.skewMs}ms`, check.snaps?.map(snapKey));

  // 3) Both end live_set empty → live_show / next main must lockstep
  server = await getState(room.roomId, room.p1.token);
  for (let i = 0; i < 4 && server.phase === 'live_set'; i++) {
    const pid = server.live_set_player || server.active_player;
    const tok = pid === 'p1' ? room.p1.token : room.p2.token;
    if (!server.live_ready?.[pid]) {
      await gameAction(room.roomId, tok, 'end_live_set', {});
    }
    server = await getState(room.roomId, room.p1.token);
  }

  check = await waitLockstep(pages, (snaps) => {
    if (!sameTurnCursor(snaps)) return false;
    // Accept either mid live_show (same stage) or advanced past live_set together.
    return snaps[0].phase !== 'live_set' || !!snaps[0].live_show_stage;
  }, 'post-live-set-lockstep', 30000);
  results.push(check);
  console.log(check.ok ? 'PASS' : 'FAIL', check.label, `skew=${check.skewMs}ms`, check.snaps?.map(s => ({
    key: snapKey(s),
    phaseMsg: s?.phaseMsg,
    spectacle: s?.spectacle,
    runner: s?.runner,
  })));

  // Sample lockstep for a few seconds while spectacle/show advances
  const samples = [];
  for (let i = 0; i < 12; i++) {
    await new Promise(r => setTimeout(r, 800));
    const snaps = await Promise.all(pages.map(clientSnap));
    samples.push({
      t: i,
      match: sameTurnCursor(snaps),
      keys: snaps.map(snapKey),
      msgs: snaps.map(s => s?.phaseMsg),
    });
  }
  const mismatched = samples.filter(s => !s.match);
  let consecutiveMismatch = 0;
  let maxConsecutive = 0;
  for (const s of samples) {
    if (!s.match) consecutiveMismatch++;
    else consecutiveMismatch = 0;
    maxConsecutive = Math.max(maxConsecutive, consecutiveMismatch);
  }
  const samplePass = maxConsecutive <= Math.ceil(MAX_SKEW_MS / 800);
  results.push({
    ok: samplePass,
    label: 'spectacle-sample-lockstep',
    maxConsecutiveMismatch: maxConsecutive,
    mismatched: mismatched.slice(0, 4),
  });
  console.log(samplePass ? 'PASS' : 'FAIL', 'spectacle-sample-lockstep',
    `maxConsecutiveMismatch=${maxConsecutive}`, mismatched.slice(0, 3));

  await p1Page.screenshot({ path: path.join(OUT_DIR, `${stamp}-lockstep-p1.png`) });
  await p2Page.screenshot({ path: path.join(OUT_DIR, `${stamp}-lockstep-p2.png`) });

  await ctx1.close();
  await ctx2.close();
} finally {
  await browser.close();
}

const failed = results.filter(r => !r.ok);
const out = { stamp, base: BASE, results, failed: failed.length };
fs.writeFileSync(path.join(OUT_DIR, `${stamp}-lockstep.json`), JSON.stringify(out, null, 2));
console.log('\nSummary:', failed.length ? `FAIL (${failed.length})` : 'PASS', path.join(OUT_DIR, `${stamp}-lockstep.json`));
process.exit(failed.length ? 1 : 0);
