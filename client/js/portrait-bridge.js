/**
 * Portrait / Capacitor bridge — log sheet, Android back, offline, low-end flags.
 * Safe no-op when html.tcg-portrait-play is absent.
 */
(function (global) {
  'use strict';

  function portraitActive() {
    return !!(global.document
      && global.document.documentElement
      && (global.document.documentElement.classList.contains('tcg-portrait-play')
        || global.document.documentElement.dataset.tcgPortraitPlay === '1'));
  }

  function t(key, fallback) {
    try {
      let v;
      if (typeof global.t === 'function') v = global.t(key);
      else if (global.LLTCG_I18N && typeof global.LLTCG_I18N.t === 'function') {
        v = global.LLTCG_I18N.t(key);
      }
      if (v != null && v !== '' && v !== key) return v;
    } catch (_) { /* ignore */ }
    return fallback;
  }

  function ensureChrome() {
    if (!portraitActive() || !global.document || global.document.getElementById('portrait-log-sheet')) return;

    const offline = global.document.createElement('div');
    offline.id = 'portrait-offline-banner';
    offline.setAttribute('role', 'status');
    offline.textContent = t('mobile.offlineNeedNetwork', 'No network — reconnect to play.');
    global.document.body.appendChild(offline);

    const logSheet = global.document.createElement('div');
    logSheet.id = 'portrait-log-sheet';
    logSheet.setAttribute('aria-hidden', 'true');
    logSheet.innerHTML =
      '<div class="portrait-sheet-panel" role="dialog" aria-modal="true" aria-labelledby="portrait-log-title">' +
      '<div class="portrait-sheet-head">' +
      '<h3 id="portrait-log-title"></h3>' +
      '<button type="button" class="btn-ghost portrait-sheet-close" id="portrait-log-close" aria-label="Close">✕</button>' +
      '</div>' +
      '<div class="portrait-log-host" id="portrait-log-host"></div>' +
      '</div>';
    global.document.body.appendChild(logSheet);
    const title = logSheet.querySelector('#portrait-log-title');
    if (title) title.textContent = t('game.gameLog', 'Game Log');

    const inspect = global.document.createElement('div');
    inspect.id = 'portrait-inspect-sheet';
    inspect.setAttribute('aria-hidden', 'true');
    inspect.innerHTML =
      '<div class="portrait-sheet-panel" role="dialog" aria-modal="true">' +
      '<div class="portrait-sheet-head">' +
      '<h3>' + t('common.preview', 'Preview') + '</h3>' +
      '<button type="button" class="btn-ghost portrait-sheet-close" id="portrait-inspect-close" aria-label="Close">✕</button>' +
      '</div>' +
      '<div id="portrait-inspect-host"></div>' +
      '</div>';
    global.document.body.appendChild(inspect);

    const foot = global.document.querySelector('#screen-game .sbfoot');
    if (foot && !global.document.getElementById('btn-portrait-log')) {
      const btn = global.document.createElement('button');
      btn.type = 'button';
      btn.id = 'btn-portrait-log';
      btn.className = 'btn-ghost';
      btn.textContent = t('mobile.openLog', 'Log');
      foot.insertBefore(btn, foot.firstChild);
    }

    const game = global.document.getElementById('screen-game');
    if (game && !global.document.getElementById('portrait-play-scrim')) {
      const scrim = global.document.createElement('div');
      scrim.id = 'portrait-play-scrim';
      scrim.setAttribute('aria-hidden', 'true');
      game.appendChild(scrim);
      scrim.addEventListener('click', function () {
        if (typeof global.clearPlaySelection === 'function') global.clearPlaySelection();
      });
    }
  }

  function ensurePlayPanelHost() {
    const panel = global.document.getElementById('card-hover-panel');
    const game = global.document.getElementById('screen-game');
    if (panel && game && panel.parentElement !== game) {
      game.appendChild(panel);
    }
    return panel;
  }

  function syncPlaySheet(card, s, myId) {
    if (!portraitActive()) return;
    ensureChrome();
    const game = global.document.getElementById('screen-game');
    const panel = ensurePlayPanelHost();
    if (!card) {
      game?.classList.remove('portrait-play-selecting');
      if (panel) {
        panel.classList.remove('visible');
        panel.style.removeProperty('display');
        panel.style.removeProperty('visibility');
        panel.style.removeProperty('opacity');
        panel.style.removeProperty('z-index');
      }
      return;
    }
    game?.classList.add('portrait-play-selecting');
    try {
      if (typeof global.showHoverCardPreview === 'function') {
        global.showHoverCardPreview(card, s || global.G?.gameState, myId || global.G?.playerId);
      } else if (typeof global.refreshHandPreviewPanel === 'function') {
        global.refreshHandPreviewPanel(card, s || global.G?.gameState, myId || global.G?.playerId);
      }
      if (typeof global.fillMemberPlayActions === 'function' && global.el) {
        global.fillMemberPlayActions(global.el('hc-actions'), card, s || global.G?.gameState, myId || global.G?.playerId, {
          interactive: true,
          onPlay: function () {
            if (typeof global.clearPlaySelection === 'function') global.clearPlaySelection();
          },
        });
      }
    } catch (_) { /* ignore */ }
    if (panel) {
      panel.classList.add('visible');
      // Force paint above scrim even if other CSS fights us
      panel.style.setProperty('display', 'block', 'important');
      panel.style.setProperty('visibility', 'visible', 'important');
      panel.style.setProperty('opacity', '1', 'important');
      panel.style.setProperty('z-index', '93000', 'important');
    }
    const empty = global.document.getElementById('hc-empty');
    if (empty) empty.style.display = 'none';
  }

  function closeSheet(id) {
    const node = global.document.getElementById(id);
    if (!node) return false;
    if (!node.classList.contains('open')) return false;
    node.classList.remove('open');
    node.setAttribute('aria-hidden', 'true');
    return true;
  }

  function openLogSheet() {
    ensureChrome();
    const sheet = global.document.getElementById('portrait-log-sheet');
    const host = global.document.getElementById('portrait-log-host');
    const log = global.document.getElementById('game-log');
    if (!sheet || !host || !log) return;
    // Move live log into sheet (put back on close)
    if (log.parentElement !== host) {
      host._logHome = log.parentElement;
      host.appendChild(log);
      log.style.display = 'block';
      log.style.maxHeight = '100%';
      log.style.overflow = 'auto';
    }
    sheet.classList.add('open');
    sheet.setAttribute('aria-hidden', 'false');
  }

  function closeLogSheet() {
    const host = global.document.getElementById('portrait-log-host');
    const log = global.document.getElementById('game-log');
    if (host && log && host._logHome) {
      host._logHome.appendChild(log);
      log.style.display = '';
      log.style.maxHeight = '';
      log.style.overflow = '';
    }
    return closeSheet('portrait-log-sheet');
  }

  function openInspectFromHover() {
    const panel = global.document.getElementById('card-hover-panel');
    const host = global.document.getElementById('portrait-inspect-host');
    const sheet = global.document.getElementById('portrait-inspect-sheet');
    if (!panel || !host || !sheet) return;
    host.innerHTML = '';
    const clone = panel.cloneNode(true);
    clone.id = 'portrait-inspect-clone';
    clone.style.display = 'block';
    host.appendChild(clone);
    sheet.classList.add('open');
    sheet.setAttribute('aria-hidden', 'false');
  }

  function anyOverlayOpen() {
    return !!(
      global.document.querySelector('.overlay.open')
      || global.document.getElementById('portrait-log-sheet')?.classList.contains('open')
      || global.document.getElementById('portrait-inspect-sheet')?.classList.contains('open')
      || (global.document.getElementById('stamp-picker') && !global.document.getElementById('stamp-picker').hidden)
    );
  }

  function handleBack() {
    if (!portraitActive()) return false;
    if (global.document.getElementById('screen-game')?.classList.contains('portrait-play-selecting')) {
      if (typeof global.clearPlaySelection === 'function') global.clearPlaySelection();
      return true;
    }
    if (closeLogSheet()) return true;
    if (closeSheet('portrait-inspect-sheet')) return true;
    const stamp = global.document.getElementById('stamp-picker');
    if (stamp && !stamp.hidden) {
      stamp.hidden = true;
      return true;
    }
    const openOverlay = global.document.querySelector('.overlay.open');
    if (openOverlay) {
      openOverlay.classList.remove('open');
      openOverlay.setAttribute('aria-hidden', 'true');
      return true;
    }
    const game = global.document.getElementById('screen-game');
    if (game && game.classList.contains('active')) {
      // Confirm leave via existing resign if available
      const resign = global.document.getElementById('btn-resign');
      if (resign) {
        resign.click();
        return true;
      }
    }
    return false;
  }

  function syncOffline() {
    if (!portraitActive()) return;
    ensureChrome();
    const banner = global.document.getElementById('portrait-offline-banner');
    if (!banner) return;
    const offline = global.navigator && global.navigator.onLine === false;
    banner.classList.toggle('show', offline);
  }

  function detectLowEnd() {
    if (!portraitActive()) return;
    try {
      const mem = global.navigator && global.navigator.deviceMemory;
      const cores = global.navigator && global.navigator.hardwareConcurrency;
      const reduced = global.matchMedia && global.matchMedia('(prefers-reduced-motion: reduce)').matches;
      const low = reduced || (typeof mem === 'number' && mem > 0 && mem <= 4)
        || (typeof cores === 'number' && cores > 0 && cores <= 4);
      if (low) {
        global.document.documentElement.classList.add('tcg-low-end');
        global.TCG_LOW_END = true;
      }
    } catch (_) { /* ignore */ }
  }

  function bindUi() {
    if (!portraitActive()) return;
    ensureChrome();
    detectLowEnd();
    syncOffline();

    // Warm DNS / TLS for match API + hub (mid-range first paint).
    try {
      [['preconnect', 'https://loveliveradio.ca'],
       ['preconnect', 'https://stream.loveliveradio.ca'],
       ['dns-prefetch', 'https://loveliveradio.ca'],
       ['dns-prefetch', 'https://stream.loveliveradio.ca']].forEach(function (pair) {
        var rel = pair[0], href = pair[1];
        if (global.document.querySelector('link[rel="' + rel + '"][href="' + href + '"]')) return;
        var link = global.document.createElement('link');
        link.rel = rel;
        link.href = href;
        if (rel === 'preconnect') link.crossOrigin = 'anonymous';
        global.document.head.appendChild(link);
      });
    } catch (_) { /* ignore */ }

    global.document.getElementById('btn-portrait-log')?.addEventListener('click', openLogSheet);
    global.document.getElementById('portrait-log-close')?.addEventListener('click', closeLogSheet);
    global.document.getElementById('portrait-log-sheet')?.addEventListener('click', function (e) {
      if (e.target && e.target.id === 'portrait-log-sheet') closeLogSheet();
    });
    global.document.getElementById('portrait-inspect-close')?.addEventListener('click', function () {
      closeSheet('portrait-inspect-sheet');
    });
    global.document.getElementById('portrait-inspect-sheet')?.addEventListener('click', function (e) {
      if (e.target && e.target.id === 'portrait-inspect-sheet') closeSheet('portrait-inspect-sheet');
    });

    // Long-press / select on stage cards often fills #card-hover-panel — surface as sheet once painted.
    try {
      const hover = global.document.getElementById('card-hover-panel');
      if (hover && typeof MutationObserver !== 'undefined') {
        new MutationObserver(function () {
          const img = global.document.getElementById('hc-img');
          if (img && img.classList.contains('show') && portraitActive()) {
            // Debounce: only open when game screen active and no other overlay
            if (global.document.getElementById('screen-game')?.classList.contains('active')
              && !anyOverlayOpen()) {
              // Avoid auto-spam — user opens via double-tap on preview host button if we add one later
            }
          }
        }).observe(hover, { attributes: true, subtree: true, attributeFilter: ['class', 'src'] });
      }
    } catch (_) { /* ignore */ }

    global.addEventListener('online', syncOffline);
    global.addEventListener('offline', syncOffline);

    // Capacitor Android back button
    try {
      var Cap = global.Capacitor;
      var App = Cap && Cap.Plugins && Cap.Plugins.App;
      if (App && typeof App.addListener === 'function') {
        App.addListener('backButton', function (ev) {
          if (handleBack()) {
            if (ev && typeof ev.preventDefault === 'function') ev.preventDefault();
            return;
          }
          if (App.exitApp) App.exitApp();
        });
      }
    } catch (_) { /* ignore */ }

    // Browser Escape / Android WebView back polyfill
    global.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && handleBack()) e.preventDefault();
    });
  }

  function boot() {
    if (!portraitActive()) return;
    bindUi();
  }

  global.tcgPortraitPlayActive = global.tcgPortraitPlayActive || function () {
    return portraitActive();
  };
  global.tcgPortraitHandleBack = handleBack;
  global.tcgPortraitOpenLog = openLogSheet;
  global.tcgPortraitOpenInspect = openInspectFromHover;
  global.tcgPortraitSyncPlaySheet = syncPlaySheet;

  if (global.document.readyState === 'loading') {
    global.document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})(typeof window !== 'undefined' ? window : globalThis);
