/**
 * Portrait board mount — reparents existing game nodes into a mockup CSS grid.
 * Keeps board-render IDs intact; only changes DOM ancestry under #screen-game.
 */
(function (global) {
  'use strict';

  function portraitActive() {
    return !!(global.document?.documentElement?.classList.contains('tcg-portrait-play')
      || global.document?.documentElement?.dataset?.tcgPortraitPlay === '1');
  }

  function el(id) {
    return global.document.getElementById(id);
  }

  function wrap(cls) {
    const d = global.document.createElement('div');
    d.className = cls;
    return d;
  }

  function labelZone(node, text) {
    if (!node) return;
    node.dataset.portraitLabel = text;
  }

  function matMountNode(id) {
    const node = el(id);
    if (!node) return null;
    // Overhaul wraps #game-stage in <ll-stage-board> (display:inline by default) —
    // move the host so the field fills the grid cell.
    return node.closest('ll-stage-board') || node;
  }

  function ensureFieldHosts() {
    const board = el('portrait-board');
    if (!board) return false;
    const myField = board.querySelector('.pb-my-field');
    const oppField = board.querySelector('.pb-opp-field');
    const myHand = board.querySelector('.pb-my-hand');
    const myMat = matMountNode('game-stage');
    const oppMat = el('opp-stage');
    const handStrip = document.querySelector('#portrait-board .my-hand-strip')
      || document.querySelector('.my-hand-strip');
    if (myField && myMat && myMat.parentElement !== myField) myField.appendChild(myMat);
    if (oppField && oppMat && oppMat.parentElement !== oppField) oppField.appendChild(oppMat);
    if (myHand && handStrip && handStrip.parentElement !== myHand) myHand.appendChild(handStrip);
    return true;
  }

  function mount() {
    if (!portraitActive()) return false;
    if (el('portrait-board')) {
      ensureFieldHosts();
      syncHandVars();
      return true;
    }

    const viewport = el('game-viewport-frame');
    const wide = viewport?.querySelector?.('.game-wide-wrap');
    if (!viewport || !wide) return false;

    const oppHand = wide.querySelector('.opp-hand-strip');
    const oppMat = el('opp-stage');
    const myMat = matMountNode('game-stage');
    const myHand = wide.querySelector('.my-hand-strip');
    const phase = wide.querySelector('.phase-panel');
    const stats = wide.querySelector('.players-stats-panel');
    const stageBoard = wide.querySelector('.stage-board-panel');
    const phaseBar = el('phase-action-bar');
    const sbfoot = wide.querySelector('.sbfoot');
    const playCost = el('play-cost-hud');
    const liveScore = el('live-score-hud');
    const cardHover = el('card-hover-panel');
    const myNrgPill = wide.querySelector('.player-stat-strip.mine .cpill.nrg');
    const oppNrgPill = wide.querySelector('.player-stat-strip.opp .cpill.nrg');

    if (!oppHand || !oppMat || !myMat || !myHand) return false;

    const board = wrap('portrait-board');
    board.id = 'portrait-board';

    const oppHandSec = wrap('pb-opp-hand');
    const oppFieldSec = wrap('pb-opp-field');
    const hudSec = wrap('pb-hud');
    const myFieldSec = wrap('pb-my-field');
    const myHandSec = wrap('pb-my-hand');
    const chromeSec = wrap('pb-chrome');

    // Mockup top HUD: [energy][wins] / names | phase | [wins][energy] / names
    const wins = wrap('pb-wins-row');
    wins.innerHTML =
      '<div class="pb-side mine">' +
        '<div class="pb-side-top">' +
          '<span class="pb-nrg" id="portrait-my-nrg-host"></span>' +
          '<span class="pb-win-val" id="portrait-my-wins">0/3</span>' +
        '</div>' +
        '<div class="pb-name-host" id="portrait-my-name-host"></div>' +
      '</div>' +
      '<div class="pb-win-center" id="portrait-hud-center"></div>' +
      '<div class="pb-side opp">' +
        '<div class="pb-side-top">' +
          '<span class="pb-win-val" id="portrait-opp-wins">0/3</span>' +
          '<span class="pb-nrg" id="portrait-opp-nrg-host"></span>' +
        '</div>' +
        '<div class="pb-name-host" id="portrait-opp-name-host"></div>' +
      '</div>';

    oppHandSec.appendChild(oppHand);
    oppFieldSec.appendChild(oppMat);

    hudSec.appendChild(wins);
    const myHost = wins.querySelector('#portrait-my-nrg-host');
    const oppHost = wins.querySelector('#portrait-opp-nrg-host');
    if (myNrgPill && myHost) myHost.appendChild(myNrgPill);
    if (oppNrgPill && oppHost) oppHost.appendChild(oppNrgPill);

    const myName = el('my-name');
    const oppName = el('opp-name');
    if (myName) wins.querySelector('#portrait-my-name-host')?.appendChild(myName);
    if (oppName) wins.querySelector('#portrait-opp-name-host')?.appendChild(oppName);

    if (phase) wins.querySelector('#portrait-hud-center')?.appendChild(phase);
    if (stats) {
      stats.classList.add('portrait-stats-shell');
      hudSec.appendChild(stats);
    }
    if (stageBoard) hudSec.appendChild(stageBoard);
    if (phaseBar) hudSec.appendChild(phaseBar);
    if (playCost) hudSec.appendChild(playCost);
    if (liveScore) hudSec.appendChild(liveScore);

    myFieldSec.appendChild(myMat);
    myHandSec.appendChild(myHand);
    if (sbfoot) chromeSec.appendChild(sbfoot);

    if (cardHover && cardHover.parentElement !== el('screen-game')) {
      el('screen-game')?.appendChild(cardHover);
    }

    board.appendChild(oppHandSec);
    board.appendChild(oppFieldSec);
    board.appendChild(hudSec);
    board.appendChild(myFieldSec);
    board.appendChild(myHandSec);
    board.appendChild(chromeSec);

    viewport.insertBefore(board, wide);
    wide.classList.add('portrait-wide-hidden');
    wide.setAttribute('aria-hidden', 'true');

    // Zone labels (mockup copy, shortened for phone)
    labelZone(el('opp-stage-right'), 'Right side area');
    labelZone(el('opp-stage-center'), 'Center area');
    labelZone(el('opp-stage-left'), 'Left side area');
    labelZone(el('my-stage-left'), 'Left side area');
    labelZone(el('my-stage-center'), 'Center area');
    labelZone(el('my-stage-right'), 'Right side area');
    labelZone(el('opp-live-0'), 'Live card storage');
    labelZone(el('opp-live-1'), 'Live card storage');
    labelZone(el('opp-live-2'), 'Live card storage');
    labelZone(el('my-live-0'), 'Live card storage');
    labelZone(el('my-live-1'), 'Live card storage');
    labelZone(el('my-live-2'), 'Live card storage');

    oppMat.querySelector('.playmat-img')?.classList.remove('playmat-img-flipped');

    document.documentElement.classList.add('tcg-portrait-board-mounted');
    syncHandVars();
    try {
      global.addEventListener('resize', syncHandVars, { passive: true });
    } catch (_) { /* ignore */ }
    return true;
  }

  function syncHandVars() {
    const board = el('portrait-board');
    if (!board) return;
    const w = board.clientWidth || global.innerWidth || 360;
    const pad = 16;
    // 1.5× “6 across” → ~4 cards visible; hold-drag scrolls the rest
    const cardW = Math.max(48, ((w - pad) / 6) * 1.5);
    const cardH = cardW * (88 / 63);
    const root = document.documentElement;
    root.style.setProperty('--p-card-w', cardW.toFixed(2) + 'px');
    root.style.setProperty('--p-card-h', cardH.toFixed(2) + 'px');
    root.style.setProperty('--p-hand-mine', (cardH + 12).toFixed(2) + 'px');
    root.style.setProperty('--p-hand-opp', (cardH * 0.92 + 6).toFixed(2) + 'px');
    root.style.setProperty('--p-card-w-opp', (cardW * 0.92).toFixed(2) + 'px');
  }

  function onRender(s, myId) {
    if (!portraitActive() || !s?.players) return;
    mount();
    ensureFieldHosts();
    syncHandVars();
    const me = s.players[myId];
    const oppId = myId === 'p1' ? 'p2' : 'p1';
    const opp = s.players[oppId];
    const myWins = el('portrait-my-wins');
    const oppWins = el('portrait-opp-wins');
    if (myWins) myWins.textContent = String((me?.success_lives || []).length) + '/3';
    if (oppWins) oppWins.textContent = String((opp?.success_lives || []).length) + '/3';

    try {
      if (typeof global.layoutHandFan === 'function') {
        global.layoutHandFan(el('hand-row'), { animate: false });
        global.layoutHandFan(el('opp-hand-zone'), { animate: false });
      }
    } catch (_) { /* ignore */ }
  }

  function boot() {
    if (!portraitActive()) return;
    const tryMount = () => {
      if (mount()) return;
      setTimeout(tryMount, 200);
    };
    tryMount();
  }

  global.tcgPortraitMountBoard = mount;
  global.tcgPortraitOnRender = onRender;

  if (global.document.readyState === 'loading') {
    global.document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})(typeof window !== 'undefined' ? window : globalThis);
