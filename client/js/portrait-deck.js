/**
 * Portrait deck builder chrome — compact header + option sheets.
 * Safe no-op when html.tcg-portrait-play is absent.
 */
(function (global) {
  'use strict';

  const SHEETS = {
    filters: { overlay: 'overlay-portrait-deck-filters', host: 'portrait-deck-filters-host', source: 'deck-filter-panel' },
    sort: { overlay: 'overlay-portrait-deck-sort', host: 'portrait-deck-sort-host', source: 'deck-collection-sort-wrap' },
    import: { overlay: 'overlay-portrait-deck-import', host: 'portrait-deck-import-host', source: 'deck-builder-import-search' },
    more: { overlay: 'overlay-portrait-deck-more', host: 'portrait-deck-more-host', source: 'deck-builder-name-wrap' },
  };

  function portraitActive() {
    return !!(global.document?.documentElement?.classList.contains('tcg-portrait-play')
      || global.document?.documentElement?.dataset?.tcgPortraitPlay === '1');
  }

  function el(id) {
    return global.document.getElementById(id);
  }

  function t(key, fallback) {
    try {
      if (typeof global.t === 'function') {
        const v = global.t(key);
        if (v != null && v !== '' && v !== key) return v;
      }
    } catch (_) { /* ignore */ }
    return fallback;
  }

  function rememberHome(node) {
    if (!node || node._pdHome) return;
    node._pdHome = { parent: node.parentElement, next: node.nextSibling };
  }

  function park(node, host) {
    if (!node || !host) return;
    rememberHome(node);
    if (node.parentElement !== host) host.appendChild(node);
  }

  function restore(node) {
    if (!node?._pdHome?.parent) return;
    const { parent, next } = node._pdHome;
    if (next && next.parentElement === parent) parent.insertBefore(node, next);
    else if (node.parentElement !== parent) parent.appendChild(node);
  }

  function restoreAllParked() {
    ['deck-sleeve-picker', 'deck-playmat-picker', 'deck-pool-search-wrap', 'deck-filter-panel',
      'deck-collection-sort-wrap', 'deck-builder-import-search',
      'deck-builder-name-wrap', 'btn-deck-seal-batch', 'deck-seal-batch-bar']
      .forEach((id) => restore(el(id)));
  }

  function syncBatchModeUi() {
    if (!portraitActive()) return;
    const screen = el('screen-deck');
    const bar = el('deck-seal-batch-bar');
    const host = el('portrait-deck-batch-host');
    const inBatch = !!(global.A && global.A.deckSealBatchMode);
    if (!screen) return;
    screen.classList.toggle('portrait-deck-batch-mode', inBatch);
    if (inBatch) {
      closeAllSheets();
      setView('pool');
      if (host && bar) {
        park(bar, host);
        bar.hidden = false;
      }
    } else if (bar) {
      restore(bar);
    }
  }

  function wrapBatchModeHooks() {
    ['enterDeckSealBatchMode', 'exitDeckSealBatchMode', 'updateDeckSealBatchBar'].forEach((name) => {
      const orig = global[name];
      if (typeof orig !== 'function' || orig._pdWrapped) return;
      const wrapped = function () {
        const result = orig.apply(this, arguments);
        try { syncBatchModeUi(); } catch (_) { /* ignore */ }
        return result;
      };
      wrapped._pdWrapped = true;
      global[name] = wrapped;
    });
  }

  function closeSheet(id) {
    const overlay = el(id);
    if (!overlay || !overlay.classList.contains('open')) return false;
    overlay.classList.remove('open');
    overlay.setAttribute('aria-hidden', 'true');
    Object.values(SHEETS).forEach((spec) => {
      if (spec.overlay === id) restore(el(spec.source));
    });
    restore(el('deck-builder-name-wrap'));
    restore(el('btn-deck-seal-batch'));
    return true;
  }

  function closeAllSheets() {
    let closed = false;
    Object.values(SHEETS).forEach((spec) => {
      if (closeSheet(spec.overlay)) closed = true;
    });
    return closed;
  }

  function openSheet(name) {
    if (!portraitActive()) return;
    const spec = SHEETS[name];
    if (!spec) return;
    closeAllSheets();
    const overlay = el(spec.overlay);
    const host = el(spec.host);
    const source = el(spec.source);
    if (!overlay || !host) return;
    if (name === 'import') {
      // Search lives in the chrome; park remaining import tools.
      park(el('deck-pool-search-wrap'), el('portrait-deck-search-host'));
    }
    park(source, host);
    if (name === 'more') {
      park(el('btn-deck-seal-batch'), host);
    }
    overlay.classList.add('open');
    overlay.setAttribute('aria-hidden', 'false');
  }

  function setView(which) {
    const screen = el('screen-deck');
    if (!screen) return;
    const pool = which !== 'deck';
    screen.classList.toggle('portrait-deck-view-pool', pool);
    screen.classList.toggle('portrait-deck-view-deck', !pool);
    const poolBtn = el('btn-portrait-deck-view-pool');
    const deckBtn = el('btn-portrait-deck-view-deck');
    poolBtn?.classList.toggle('is-active', pool);
    deckBtn?.classList.toggle('is-active', !pool);
    if (poolBtn) poolBtn.setAttribute('aria-selected', pool ? 'true' : 'false');
    if (deckBtn) deckBtn.setAttribute('aria-selected', !pool ? 'true' : 'false');
    syncStats();
  }

  function fillPresetSelect() {
    const sel = el('sel-portrait-deck-preset');
    const wrap = sel?.closest('.portrait-deck-preset-wrap');
    if (!sel) return;
    const A = global.A || {};
    const experiment = A.deckBuilderMode === 'experiment';
    const signedExp = experiment && typeof global.isSignedInAccount === 'function' && global.isSignedInAccount();
    if (experiment && !signedExp) {
      if (wrap) wrap.hidden = true;
      return;
    }
    if (wrap) wrap.hidden = false;
    const active = experiment ? (A.experimentSlot || 1) : (A.deckSlot || 1);
    const decks = experiment ? (A.experimentDecks || []) : (A.decks || []);
    const html = [];
    for (let i = 1; i <= 10; i++) {
      const saved = decks.find((d) => d.slot === i);
      let label = '#' + i;
      if (saved) {
        const name = saved.name || (experiment ? ('Exp ' + i) : ('Deck ' + i));
        const wip = typeof global.savedDeckIsPlayable === 'function'
          ? !global.savedDeckIsPlayable(saved)
          : false;
        label = '#' + i + ' · ' + name + (saved.equipped ? ' ★' : '') + (wip ? ' …' : '');
      }
      html.push('<option value="' + i + '"' + (i === active ? ' selected' : '') + '>' +
        String(label).replace(/</g, '') + '</option>');
    }
    const next = html.join('');
    if (sel.dataset.html !== next) {
      sel.innerHTML = next;
      sel.dataset.html = next;
    }
    if (Number(sel.value) !== active) sel.value = String(active);
  }

  function syncStats() {
    const out = el('portrait-deck-stats');
    if (!out) return;
    const screen = el('screen-deck');
    const showDeck = screen?.classList.contains('portrait-deck-view-deck');
    const src = el(showDeck ? 'deck-current-stats' : 'deck-collection-stats');
    out.textContent = src?.textContent || '';
  }

  function syncImportButton() {
    const btn = el('btn-portrait-deck-import');
    if (!btn) return;
    const A = global.A || {};
    const experiment = A.deckBuilderMode === 'experiment';
    const signed = typeof global.isSignedInAccount === 'function' && global.isSignedInAccount();
    btn.hidden = !(experiment || signed);
  }

  function mountChrome() {
    if (!portraitActive()) {
      restoreAllParked();
      closeAllSheets();
      return;
    }
    const chrome = el('portrait-deck-chrome');
    const screen = el('screen-deck');
    if (!chrome || !screen) return;
    chrome.hidden = false;
    park(el('deck-sleeve-picker'), el('portrait-deck-sleeve-host'));
    park(el('deck-playmat-picker'), el('portrait-deck-playmat-host'));
    park(el('deck-pool-search-wrap'), el('portrait-deck-search-host'));
    if (!screen.classList.contains('portrait-deck-view-deck')) {
      screen.classList.add('portrait-deck-view-pool');
    }
    fillPresetSelect();
    syncImportButton();
    syncStats();
  }

  function unmountChrome() {
    closeAllSheets();
    restoreAllParked();
    const screen = el('screen-deck');
    screen?.classList.remove('portrait-deck-batch-mode');
    const chrome = el('portrait-deck-chrome');
    if (chrome) chrome.hidden = true;
  }

  function bindOnce() {
    if (global.document._portraitDeckBound) return;
    global.document._portraitDeckBound = true;

    el('btn-portrait-deck-view-pool')?.addEventListener('click', () => setView('pool'));
    el('btn-portrait-deck-view-deck')?.addEventListener('click', () => setView('deck'));
    el('sel-portrait-deck-preset')?.addEventListener('change', function () {
      const slot = parseInt(this.value, 10);
      if (!slot) return;
      const A = global.A || {};
      if (A.deckBuilderMode === 'experiment' && typeof global.selectExperimentSlot === 'function') {
        global.selectExperimentSlot(slot);
      } else if (typeof global.selectDeckSlot === 'function') {
        global.selectDeckSlot(slot);
      }
    });
    el('btn-portrait-deck-filters')?.addEventListener('click', () => openSheet('filters'));
    el('btn-portrait-deck-sort')?.addEventListener('click', () => openSheet('sort'));
    el('btn-portrait-deck-import')?.addEventListener('click', () => openSheet('import'));
    el('btn-portrait-deck-more')?.addEventListener('click', () => openSheet('more'));

    Object.entries({
      'btn-portrait-deck-filters-close': 'overlay-portrait-deck-filters',
      'btn-portrait-deck-sort-close': 'overlay-portrait-deck-sort',
      'btn-portrait-deck-import-close': 'overlay-portrait-deck-import',
      'btn-portrait-deck-more-close': 'overlay-portrait-deck-more',
    }).forEach(([btnId, overlayId]) => {
      el(btnId)?.addEventListener('click', () => closeSheet(overlayId));
    });

    Object.values(SHEETS).forEach((spec) => {
      el(spec.overlay)?.addEventListener('click', function (e) {
        if (e.target === this) closeSheet(spec.overlay);
      });
    });
  }

  function handleBack() {
    if (!portraitActive()) return false;
    if (closeAllSheets()) return true;
    if (global.A?.deckSealBatchMode && typeof global.exitDeckSealBatchMode === 'function') {
      global.exitDeckSealBatchMode();
      return true;
    }
    return false;
  }

  function sync() {
    if (!portraitActive()) return;
    wrapBatchModeHooks();
    bindOnce();
    mountChrome();
    syncBatchModeUi();
  }

  global.tcgPortraitSyncDeckChrome = sync;
  global.tcgPortraitDeckHandleBack = handleBack;
  global.tcgPortraitUnmountDeckChrome = unmountChrome;

  if (global.document.readyState === 'loading') {
    global.document.addEventListener('DOMContentLoaded', bindOnce);
  } else {
    bindOnce();
  }
})(typeof window !== 'undefined' ? window : globalThis);
