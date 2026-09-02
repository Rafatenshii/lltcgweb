/**
 * Spectator Picture-in-Picture (desktop Document PiP only).
 * No in-app floating fallback — mobile / unsupported browsers hide the controls.
 * DOM lookups are bridged so existing getElementById/querySelector keep working
 * while the board lives in the PiP window.
 */
(function (global) {
  'use strict';

  const PLACEHOLDER_ID = 'tcg-spectate-pip-placeholder';

  /** Game overlays outside #game-viewport-frame — must follow the board into PiP. */
  const PIP_OVERLAY_IDS = [
    'overlay-mull',
    'overlay-live',
    'overlay-prompt',
    'overlay-skill-reveal',
    'overlay-pick',
    'overlay-hand-pick',
    'overlay-heart',
    'overlay-surveil',
    'overlay-zone',
    'overlay-coin',
    'center-banner',
    'opp-skill-wait',
    'live-judge-overlay',
    'perf-spectacle',
    'card-flight-layer',
    'modal-card',
  ];

  /** Mirror presentation body classes onto the PiP document (not tcg-spectate-doc-pip). */
  const PIP_SYNC_BODY_CLASSES = [
    'perf-spectacle-active',
    'prompt-board-peek',
    'prompt-board-peek-holding',
  ];

  let pipWindow = null;
  let placeholderEl = null;
  let frameHomeParent = null;
  let frameHomeNext = null;
  const overlayHomes = [];
  let mode = null; // 'document' | null
  let bridgeInstalled = false;
  let bodyBridgeInstalled = false;
  let docClassObserver = null;
  const orig = {
    getElementById: null,
    querySelector: null,
    querySelectorAll: null,
    appendChild: null,
    insertBefore: null,
  };
  let onViewportResize = null;

  function t(key, fallback, vars) {
    const fn = global.LLTCG_I18N && global.LLTCG_I18N.t;
    let out = (typeof fn === 'function') ? fn(key, vars) : null;
    if (!out || out === key) out = fallback != null ? String(fallback) : key;
    if (vars && typeof out === 'string') {
      out = out.replace(/\{([^}]+)\}/g, (m, name) =>
        vars[name] != null ? String(vars[name]) : m);
    }
    return out;
  }

  function gameFrame() {
    return global.document.getElementById('game-viewport-frame');
  }

  function pipDoc() {
    return pipWindow && !pipWindow.closed ? pipWindow.document : null;
  }

  function supportsDocumentPip() {
    return !!(global.documentPictureInPicture
      && typeof global.documentPictureInPicture.requestWindow === 'function');
  }

  /** Match app mobile / portrait shell detection — no Document PiP offer there. */
  function isMobileLike() {
    if (typeof global.tcgPortraitPlayActive === 'function' && global.tcgPortraitPlayActive()) {
      return true;
    }
    if (typeof global.tcgPortraitTouchPrimary === 'function' && global.tcgPortraitTouchPrimary()) {
      return true;
    }
    const ua = String(global.navigator?.userAgent || '');
    let coarse = false;
    let hoverNone = false;
    try {
      coarse = global.matchMedia('(pointer: coarse)').matches;
      hoverNone = global.matchMedia('(hover: none)').matches;
    } catch (e) { /* ignore */ }
    const touchPoints = Number(global.navigator?.maxTouchPoints || 0);
    const mobileUa = /Android|iPhone|iPad|iPod|Mobile|webOS|BlackBerry|IEMobile|Opera Mini/i.test(ua);
    const iPadOsDesktopUa = touchPoints > 1 && /Macintosh/i.test(ua);
    if (mobileUa || iPadOsDesktopUa || coarse || hoverNone) return true;
    const w = Number(global.innerWidth || 0);
    const h = Number(global.innerHeight || 0);
    if (w > 0 && h > 0 && w < h && Math.min(w, h) <= 920) return true;
    return false;
  }

  /** True when we should show spectate PiP controls (desktop + Document PiP API). */
  function isOffered() {
    return supportsDocumentPip() && !isMobileLike();
  }

  function isActive() {
    return mode === 'document';
  }

  function installDomBridge() {
    if (bridgeInstalled) return;
    bridgeInstalled = true;
    orig.getElementById = Document.prototype.getElementById;
    orig.querySelector = Document.prototype.querySelector;
    orig.querySelectorAll = Document.prototype.querySelectorAll;

    Document.prototype.getElementById = function (id) {
      const hit = orig.getElementById.call(this, id);
      if (hit || this !== global.document) return hit;
      const pd = pipDoc();
      return pd ? orig.getElementById.call(pd, id) : null;
    };
    Document.prototype.querySelector = function (sel) {
      const hit = orig.querySelector.call(this, sel);
      if (hit || this !== global.document) return hit;
      const pd = pipDoc();
      return pd ? orig.querySelector.call(pd, sel) : null;
    };
    Document.prototype.querySelectorAll = function (sel) {
      const hit = orig.querySelectorAll.call(this, sel);
      if ((hit && hit.length) || this !== global.document) return hit;
      const pd = pipDoc();
      return pd ? orig.querySelectorAll.call(pd, sel) : hit;
    };
  }

  function uninstallDomBridge() {
    if (!bridgeInstalled) return;
    Document.prototype.getElementById = orig.getElementById;
    Document.prototype.querySelector = orig.querySelector;
    Document.prototype.querySelectorAll = orig.querySelectorAll;
    bridgeInstalled = false;
  }

  function rememberOverlayHome(el) {
    if (!el || overlayHomes.some((h) => h.el === el)) return;
    overlayHomes.push({
      el,
      parent: el.parentNode,
      next: el.nextSibling,
    });
  }

  function moveOverlaysToPip(pipBody) {
    PIP_OVERLAY_IDS.forEach((id) => {
      const node = orig.getElementById
        ? orig.getElementById.call(global.document, id)
        : global.document.getElementById(id);
      if (!node || !pipBody) return;
      rememberOverlayHome(node);
      pipBody.appendChild(node);
    });
  }

  function restoreOverlayHomes() {
    overlayHomes.forEach(({ el, parent, next }) => {
      if (!el || !parent) return;
      if (next && next.parentNode === parent) {
        parent.insertBefore(el, next);
      } else {
        parent.appendChild(el);
      }
    });
    overlayHomes.length = 0;
  }

  function pipUiBody() {
    const pd = pipDoc();
    return pd && pd.body ? pd.body : null;
  }

  function installBodyBridge() {
    if (bodyBridgeInstalled) return;
    bodyBridgeInstalled = true;
    orig.appendChild = Node.prototype.appendChild;
    orig.insertBefore = Node.prototype.insertBefore;

    Node.prototype.appendChild = function (child) {
      if (bodyBridgeInstalled && mode === 'document' && this === global.document.body) {
        const pb = pipUiBody();
        if (pb) return orig.appendChild.call(pb, child);
      }
      return orig.appendChild.call(this, child);
    };
    Node.prototype.insertBefore = function (child, ref) {
      if (bodyBridgeInstalled && mode === 'document' && this === global.document.body) {
        const pb = pipUiBody();
        if (pb) return orig.insertBefore.call(pb, child, ref);
      }
      return orig.insertBefore.call(this, child, ref);
    };
  }

  function uninstallBodyBridge() {
    if (!bodyBridgeInstalled) return;
    Node.prototype.appendChild = orig.appendChild;
    Node.prototype.insertBefore = orig.insertBefore;
    bodyBridgeInstalled = false;
  }

  function syncPipDocClasses() {
    const pd = pipDoc();
    if (!pd) return;
    pd.documentElement.className = global.document.documentElement.className;
    const pipBody = pd.body;
    if (!pipBody) return;
    PIP_SYNC_BODY_CLASSES.forEach((cls) => {
      pipBody.classList.toggle(cls, global.document.body.classList.contains(cls));
    });
  }

  function installDocClassSync() {
    uninstallDocClassSync();
    syncPipDocClasses();
    docClassObserver = new MutationObserver(syncPipDocClasses);
    docClassObserver.observe(global.document.documentElement, {
      attributes: true,
      attributeFilter: ['class'],
    });
    docClassObserver.observe(global.document.body, {
      attributes: true,
      attributeFilter: ['class'],
    });
  }

  function uninstallDocClassSync() {
    if (docClassObserver) {
      docClassObserver.disconnect();
      docClassObserver = null;
    }
  }

  function copyStyleSheets(targetDoc) {
    [...global.document.styleSheets].forEach((styleSheet) => {
      try {
        const cssRules = [...styleSheet.cssRules].map((rule) => rule.cssText).join('');
        const style = targetDoc.createElement('style');
        style.textContent = cssRules;
        targetDoc.head.appendChild(style);
      } catch (e) {
        if (!styleSheet.href) return;
        const link = targetDoc.createElement('link');
        link.rel = 'stylesheet';
        link.href = styleSheet.href;
        if (styleSheet.media && styleSheet.media.mediaText) {
          link.media = styleSheet.media.mediaText;
        }
        targetDoc.head.appendChild(link);
      }
    });
    const extra = targetDoc.createElement('style');
    extra.textContent = [
      'html,body{margin:0;padding:0;width:100%;height:100%;overflow:hidden;background:#071018;}',
      'body.tcg-doc-pip-body{display:flex;flex-direction:column;}',
      'body.tcg-doc-pip-body #game-viewport-frame{',
      'flex:1 1 auto;min-height:0;width:100%;height:100%;',
      'max-width:none;max-height:none;border-radius:0;box-shadow:none;',
      '}',
      'body.tcg-doc-pip-body #perf-spectacle,',
      'body.tcg-doc-pip-body #card-flight-layer,',
      'body.tcg-doc-pip-body #center-banner,',
      'body.tcg-doc-pip-body #live-judge-overlay,',
      'body.tcg-doc-pip-body #opp-skill-wait{',
      'position:fixed;inset:0;max-width:none;max-height:none;',
      '}',
      '@media all and (display-mode: picture-in-picture){',
      'body{margin:0;}',
      '}',
    ].join('');
    targetDoc.head.appendChild(extra);
  }

  function rememberFrameHome(frame) {
    frameHomeParent = frame.parentNode;
    frameHomeNext = frame.nextSibling;
  }

  function restoreFrameHome(frame) {
    if (!frame || !frameHomeParent) return;
    if (frameHomeNext && frameHomeNext.parentNode === frameHomeParent) {
      frameHomeParent.insertBefore(frame, frameHomeNext);
    } else {
      frameHomeParent.appendChild(frame);
    }
    frameHomeParent = null;
    frameHomeNext = null;
  }

  function ensurePlaceholder(parent) {
    let node = global.document.getElementById(PLACEHOLDER_ID);
    if (!node) {
      node = global.document.createElement('div');
      node.id = PLACEHOLDER_ID;
      node.className = 'tcg-spectate-pip-placeholder';
      node.innerHTML = '<p class="tcg-spectate-pip-placeholder-msg"></p>'
        + '<button type="button" class="btn-grad tcg-spectate-pip-return-btn"></button>';
      parent.appendChild(node);
      node.querySelector('button')?.addEventListener('click', () => {
        void closePip({ focusOpener: true });
      });
    }
    const msg = node.querySelector('.tcg-spectate-pip-placeholder-msg');
    const btn = node.querySelector('.tcg-spectate-pip-return-btn');
    if (msg) {
      msg.textContent = t(
        'spectate.pipPlaceholder',
        'Spectating in Picture-in-Picture. Close the floating window or tap below to return.'
      );
    }
    if (btn) {
      btn.textContent = t('spectate.pipReturn', 'Return to game');
    }
    placeholderEl = node;
    return node;
  }

  function removePlaceholder() {
    const node = global.document.getElementById(PLACEHOLDER_ID);
    if (node) node.remove();
    placeholderEl = null;
  }

  function bumpLayout() {
    try {
      global.dispatchEvent(new Event('resize'));
    } catch (e) { /* ignore */ }
    if (typeof global.llBoardAfterLayout === 'function') {
      try { global.llBoardAfterLayout(); } catch (e) { /* ignore */ }
    }
  }

  function syncPipButton() {
    const on = isActive();
    const offered = isOffered();
    const spectating = !!(global.G && global.G.isSpectator);
    const show = spectating && offered;
    const btn = global.document.getElementById('btn-spectate-pip');
    if (btn) {
      btn.classList.toggle('is-active', on);
      btn.setAttribute('aria-pressed', on ? 'true' : 'false');
      const tip = on
        ? t('spectate.pipExitTitle', 'Exit Picture-in-Picture')
        : t('spectate.pipTitle', 'Picture-in-Picture — float the match while you multitask');
      btn.title = tip;
      btn.setAttribute('aria-label', on
        ? t('spectate.pipExit', 'Exit PiP')
        : t('spectate.pip', 'Picture-in-Picture'));
      btn.hidden = !show;
      btn.disabled = !show;
    }
    const menu = global.document.getElementById('btn-portrait-menu-pip');
    if (menu) {
      menu.hidden = !show;
      menu.disabled = !show;
      menu.textContent = on
        ? t('spectate.pipExit', 'Exit PiP')
        : t('spectate.pip', 'Picture-in-Picture');
      menu.classList.toggle('is-active', on);
      menu.setAttribute('aria-pressed', on ? 'true' : 'false');
    }
  }

  async function openDocumentPip(frame) {
    const rect = frame.getBoundingClientRect();
    const width = Math.max(320, Math.round(rect.width || global.innerWidth * 0.45));
    const height = Math.max(220, Math.round(rect.height || global.innerHeight * 0.4));

    pipWindow = await global.documentPictureInPicture.requestWindow({
      width,
      height,
      preferInitialWindowPlacement: true,
    });

    installDomBridge();
    installBodyBridge();
    copyStyleSheets(pipWindow.document);
    pipWindow.document.documentElement.className = global.document.documentElement.className;
    pipWindow.document.body.className = (global.document.body.className || '')
      .replace(/\btcg-spectate-doc-pip\b/g, '').trim()
      + ' tcg-doc-pip-body tcg-spectator-mode';

    rememberFrameHome(frame);
    ensurePlaceholder(frameHomeParent || global.document.getElementById('screen-game') || global.document.body);
    pipWindow.document.body.append(frame);
    moveOverlaysToPip(pipWindow.document.body);
    installDocClassSync();

    const onPageHide = () => {
      pipWindow = null;
      if (mode === 'document') {
        mode = null;
        finishClose({ fromPageHide: true, force: true });
      }
    };
    pipWindow.addEventListener('pagehide', onPageHide);

    mode = 'document';
    global.document.body.classList.add('tcg-spectate-doc-pip');
    syncPipButton();
    bumpLayout();
  }

  function finishClose(opts) {
    opts = opts || {};
    const wasActive = mode !== null || !!pipWindow || !!global.document.getElementById(PLACEHOLDER_ID)
      || global.document.body.classList.contains('tcg-spectate-doc-pip');
    if (!wasActive && !opts.force) {
      syncPipButton();
      return;
    }
    const frame = gameFrame()
      || (pipDoc() && pipDoc().getElementById('game-viewport-frame'));
    uninstallDocClassSync();
    restoreOverlayHomes();
    if (frame && frameHomeParent) {
      restoreFrameHome(frame);
    } else if (frame && !global.document.getElementById('game-viewport-frame')) {
      const screen = global.document.getElementById('screen-game');
      if (screen && !screen.contains(frame)) screen.appendChild(frame);
    }
    removePlaceholder();
    uninstallBodyBridge();
    uninstallDomBridge();
    global.document.body.classList.remove('tcg-spectate-doc-pip');
    const win = pipWindow;
    pipWindow = null;
    mode = null;
    if (win && !win.closed) {
      try { win.close(); } catch (e) { /* ignore */ }
    }
    syncPipButton();
    bumpLayout();
    if (opts.focusOpener) {
      try { global.focus(); } catch (e) { /* ignore */ }
    }
  }

  async function closePip(opts) {
    if (!isActive() && !global.document.body.classList.contains('tcg-spectate-doc-pip')) {
      syncPipButton();
      return;
    }
    finishClose(opts);
  }

  async function openPip() {
    if (!(global.G && global.G.isSpectator)) {
      if (typeof global.toast === 'function') {
        global.toast(t('spectate.pipSpectateOnly', 'Picture-in-Picture is available while spectating.'), 2800);
      }
      return;
    }
    if (!isOffered()) {
      syncPipButton();
      return;
    }
    const frame = gameFrame();
    if (!frame) return;

    if (isActive()) {
      await closePip({ focusOpener: true });
      return;
    }

    try {
      await openDocumentPip(frame);
    } catch (e) {
      if (typeof global.toast === 'function') {
        global.toast(t(
          'spectate.pipUnavailable',
          'Picture-in-Picture could not be opened in this browser.'
        ), 2800);
      }
      syncPipButton();
    }
  }

  async function togglePip() {
    if (isActive()) await closePip({ focusOpener: true });
    else await openPip();
  }

  function onLeaveSpectator() {
    if (isActive()) void closePip({ focusOpener: false });
  }

  if (!onViewportResize) {
    onViewportResize = () => {
      syncPipButton();
    };
    global.addEventListener('resize', onViewportResize);
  }

  global.TCGSpectatePip = {
    toggle: togglePip,
    open: openPip,
    close: closePip,
    isActive: isActive,
    isOffered: isOffered,
    supportsDocumentPip: supportsDocumentPip,
    syncButton: syncPipButton,
    onLeaveSpectator: onLeaveSpectator,
  };
})(typeof window !== 'undefined' ? window : globalThis);
