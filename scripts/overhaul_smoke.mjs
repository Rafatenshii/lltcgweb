#!/usr/bin/env node
/**
 * Overhaul Part 2C smoke: hub mounts; board paint + zone web components.
 * Usage: node scripts/overhaul_smoke.mjs [baseUrl]
 * Default: https://www.loveliveradio.ca/tcg/ (production). For local, pass http://127.0.0.1:PORT/tcg/
 */
import { createServer } from 'node:http';
import { readFileSync, existsSync, statSync } from 'node:fs';
import { extname, join, normalize } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const ROOT = join(fileURLToPath(new URL('.', import.meta.url)), '..');
const PW_ROOT = process.env.PLAYWRIGHT_MODULE
  || 'C:/Users/super/tools/playwright-mcp/node_modules/playwright/index.mjs';

const MIME = {
  '.html': 'text/html; charset=utf-8',
  '.js': 'text/javascript; charset=utf-8',
  '.css': 'text/css; charset=utf-8',
  '.json': 'application/json',
  '.png': 'image/png',
  '.jpg': 'image/jpeg',
  '.webp': 'image/webp',
  '.svg': 'image/svg+xml',
  '.woff2': 'font/woff2',
};

function startStaticServer() {
  const server = createServer((req, res) => {
    try {
      let urlPath = decodeURIComponent((req.url || '/').split('?')[0]);
      if (urlPath === '/' || urlPath === '/tcg' || urlPath === '/tcg/') urlPath = '/index.html';
      if (urlPath.startsWith('/tcg/')) urlPath = urlPath.slice(4);
      const filePath = normalize(join(ROOT, urlPath.replace(/^\/+/, '')));
      if (!filePath.startsWith(ROOT) || !existsSync(filePath) || !statSync(filePath).isFile()) {
        res.writeHead(404);
        res.end('not found');
        return;
      }
      res.writeHead(200, { 'Content-Type': MIME[extname(filePath)] || 'application/octet-stream' });
      res.end(readFileSync(filePath));
    } catch (e) {
      res.writeHead(500);
      res.end(String(e));
    }
  });
  return new Promise((resolve) => {
    server.listen(0, '127.0.0.1', () => {
      const { port } = server.address();
      resolve({ server, base: `http://127.0.0.1:${port}/` });
    });
  });
}

function minimalState() {
  const mk = (id, name) => ({
    instance_id: id,
    card_no: 'LL01-001',
    name_en: name,
    card_type: 'メンバー',
    cost: 1,
    hearts: ['any'],
  });
  return {
    seq: 1,
    phase: 'main_1',
    my_id: 'p1',
    players: {
      p1: {
        name: 'Smoke P1',
        hand: [mk('h1', 'Member A'), mk('h2', 'Member B')],
        stage: { center: mk('s1', 'Center'), back_left: null, back_right: null },
        energy_zone: [],
        main_deck: [],
        waiting_room: [],
        live_zone: [],
        success_lives: [],
        hand_count: 2,
      },
      p2: {
        name: 'Smoke P2',
        hand: [],
        stage: { center: null, back_left: null, back_right: null },
        energy_zone: [],
        main_deck: [],
        waiting_room: [],
        live_zone: [],
        success_lives: [],
        hand_count: 0,
      },
    },
    log: ['Smoke paint'],
  };
}

const argBase = process.argv[2];
let server = null;
let base = argBase;

if (!base) {
  const started = await startStaticServer();
  server = started.server;
  base = started.base;
}

const { chromium } = await import(pathToFileURL(PW_ROOT).href);
const browser = await chromium.launch({ headless: true });
const page = await browser.newPage();
page.setDefaultTimeout(45000);

try {
  await page.goto(base, { waitUntil: 'domcontentloaded', timeout: 60000 });
  await page.waitForSelector('#screen-hub, #screen-auth, #screen-game', { timeout: 30000 });

  const hubOk = await page.evaluate(() => {
    const hub = document.getElementById('screen-hub');
    return !!(hub && hub.querySelector);
  });
  if (!hubOk) throw new Error('hub screen missing');

  const paint = await page.evaluate((state) => {
    window.G = window.G || {};
    G.playerId = 'p1';
    G.isCPU = false;
    G.isSpectator = false;
    G.isTutorial = false;
    G.gameState = state;
    G.selCard = null;
    G.drag = null;
    if (typeof showScr === 'function') showScr('game');
    else {
      document.querySelectorAll('.screen').forEach((s) => {
        s.classList.remove('active');
        s.hidden = true;
      });
      const g = document.getElementById('screen-game');
      if (g) {
        g.hidden = false;
        g.classList.add('active');
      }
    }
    if (typeof renderGame !== 'function') {
      return { ok: false, reason: 'renderGame missing' };
    }
    try {
      renderGame(state, { skipPrompt: true });
    } catch (e) {
      return { ok: false, reason: String(e && e.message ? e.message : e) };
    }
    if (typeof llUpgradeBoardComponents === 'function') {
      llUpgradeBoardComponents(document);
    }
    if (typeof llBoardViewModel === 'function' && typeof llApplyBoardViewModel === 'function') {
      llApplyBoardViewModel(llBoardViewModel(state, 'p1'));
    }
    return {
      ok: true,
      hasStage: !!document.getElementById('game-stage'),
      hasHand: !!document.getElementById('hand-row'),
      customElements: {
        stage: !!customElements.get('ll-stage-board'),
        hand: !!customElements.get('ll-hand-zone'),
        live: !!customElements.get('ll-live-zone'),
        side: !!customElements.get('ll-side-panel'),
      },
      upgraded: {
        stage: !!document.querySelector('ll-stage-board'),
        hand: !!document.querySelector('ll-hand-zone'),
        side: !!document.querySelector('ll-side-panel'),
      },
      boardRenderSrc: [...document.querySelectorAll('script[src*="board-render"]')].map((s) => s.src),
      shellCss: [...document.querySelectorAll('link[href*="shell-all"]')].map((l) => l.href),
    };
  }, minimalState());

  console.log(JSON.stringify({ base, hubOk, paint }, null, 2));
  if (!paint.ok) throw new Error(paint.reason || 'paint failed');
  if (!paint.hasStage || !paint.hasHand) throw new Error('stage/hand missing after paint');
  if (!paint.customElements.stage || !paint.customElements.hand) {
    throw new Error('zone custom elements not registered');
  }
  console.log('overhaul_smoke: PASS');
} finally {
  await browser.close();
  if (server) server.close();
}
