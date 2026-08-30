/**
 * Spectator Picture-in-Picture:
 * - Desktop: Document Picture-in-Picture (live board moved into floating window)
 * - Fallback (mobile / unsupported): in-app resizeable floating frame
 * DOM lookups are bridged so existing getElementById/querySelector keep working.
 */
(function (global) {
  'use strict';

  const PLACEHOLDER_ID = 'tcg-spectate-pip-placeholder';
  const INAPP_CHROME_ID = 'tcg-spectate-pip-chrome';

  let pipWindow = null;
  let placeholderEl = null;
  let frameHomeParent = null;
  let frameHomeNext = null;
  let mode = null; // 'document' | 'inapp' | null
  let bridgeInstalled = false;
  const orig = {
    getElementById: null,
    querySelector: null,
    querySelectorAll: null,
  };
  let inappDrag = null;
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

  function isActive() {
    return mode === 'document' || mode === 'inapp';
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
    if (typeof global.syncBracketTreeHeights === 'function') { /* no-op for game */ }
    if (typeof global.llBoardAfterLayout === 'function') {
      try { global.llBoardAfterLayout(); } catch (e) { /* ignore */ }
    }
  }

  function syncPipButton() {
    const on = isActive();
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
      const can = supportsDocumentPip() || true; // in-app always available while spectating
      btn.hidden = !(global.G && global.G.isSpectator);
      btn.disabled = !(global.G && global.G.isSpectator);
      if (!can && !supportsDocumentPip()) {
        /* keep enabled for in-app fallback */
      }
    }
    const menu = global.document.getElementById('btn-portrait-menu-pip');
    if (menu) {
      menu.hidden = !(global.G && global.G.isSpectator);
      menu.textContent = on
        ? t('spectate.pipExit', 'Exit PiP')
        : t('spectate.pip', 'Picture-in-Picture');
      menu.classList.toggle('is-active', on);
      menu.setAttribute('aria-pressed', on ? 'true' : 'false');
    }
  }

  function removeInappChrome() {
    global.document.getElementById(INAPP_CHROME_ID)?.remove();
    global.document.body.classList.remove('tcg-spectate-inapp-pip');
    global.document.getElementById('screen-game')?.removeAttribute('data-pip-hint');
    const frame = gameFrame();
    if (frame) {
      frame.style.left = '';
      frame.style.top = '';
      frame.style.width = '';
      frame.style.height = '';
      frame.style.right = '';
      frame.style.bottom = '';
    }
    inappDrag = null;
  }

  function attachInappChrome(frame) {
    removeInappChrome();
    global.document.body.classList.add('tcg-spectate-inapp-pip');
    const chrome = global.document.createElement('div');
    chrome.id = INAPP_CHROME_ID;
    chrome.className = 'tcg-spectate-pip-chrome';
    chrome.innerHTML = ''
      + '<button type="button" class="tcg-spectate-pip-chrome-drag" aria-label="'
      + t('spectate.pipDrag', 'Drag') + '">⋮⋮</button>'
      + '<span class="tcg-spectate-pip-chrome-label">'
      + t('spectate.pip', 'Picture-in-Picture') + '</span>'
      + '<button type="button" class="tcg-spectate-pip-chrome-expand" title="'
      + t('spectate.pipExpandTitle', 'Return to full game') + '">'
      + t('spectate.pipExpand', 'Full') + '</button>'
      + '<button type="button" class="tcg-spectate-pip-chrome-close" title="'
      + t('spectate.pipExitTitle', 'Exit Picture-in-Picture') + '">✕</button>';
    frame.prepend(chrome);

    chrome.querySelector('.tcg-spectate-pip-chrome-expand')?.addEventListener('click', () => {
      void closePip({ focusOpener: true });
    });
    chrome.querySelector('.tcg-spectate-pip-chrome-close')?.addEventListener('click', () => {
      void closePip({ focusOpener: true });
    });

    const handle = chrome.querySelector('.tcg-spectate-pip-chrome-drag');
    const onPointerDown = (ev) => {
      if (ev.button != null && ev.button !== 0) return;
      const rect = frame.getBoundingClientRect();
      inappDrag = {
        id: ev.pointerId,
        ox: ev.clientX - rect.left,
        oy: ev.clientY - rect.top,
      };
      try { handle.setPointerCapture(ev.pointerId); } catch (e) { /* ignore */ }
      ev.preventDefault();
    };
    const onPointerMove = (ev) => {
      if (!inappDrag || inappDrag.id !== ev.pointerId) return;
      const w = frame.offsetWidth;
      const h = frame.offsetHeight;
      let left = ev.clientX - inappDrag.ox;
      let top = ev.clientY - inappDrag.oy;
      left = Math.max(4, Math.min(global.innerWidth - w - 4, left));
      top = Math.max(4, Math.min(global.innerHeight - h - 4, top));
      frame.style.left = left + 'px';
      frame.style.top = top + 'px';
      frame.style.right = 'auto';
      frame.style.bottom = 'auto';
    };
    const onPointerUp = (ev) => {
      if (!inappDrag || inappDrag.id !== ev.pointerId) return;
      inappDrag = null;
      try { handle.releasePointerCapture(ev.pointerId); } catch (e) { /* ignore */ }
    };
    handle?.addEventListener('pointerdown', onPointerDown);
    handle?.addEventListener('pointermove', onPointerMove);
    handle?.addEventListener('pointerup', onPointerUp);
    handle?.addEventListener('pointercancel', onPointerUp);
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
    copyStyleSheets(pipWindow.document);
    pipWindow.document.documentElement.className = global.document.documentElement.className;
    pipWindow.document.body.className = (global.document.body.className || '') + ' tcg-doc-pip-body tcg-spectator-mode';

    rememberFrameHome(frame);
    ensurePlaceholder(frameHomeParent || global.document.getElementById('screen-game') || global.document.body);
    pipWindow.document.body.append(frame);

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

  function openInappPip(frame) {
    mode = 'inapp';
    const screen = global.document.getElementById('screen-game');
    if (screen) {
      screen.setAttribute(
        'data-pip-hint',
        t('spectate.pipInappHint', 'Floating match — drag, resize, or tap Full to return')
      );
    }
    attachInappChrome(frame);
    // Default floating size — resizeable via CSS resize.
    const vw = Math.min(global.innerWidth - 16, Math.max(280, Math.round(global.innerWidth * 0.72)));
    const vh = Math.min(global.innerHeight - 16, Math.max(200, Math.round(global.innerHeight * 0.42)));
    frame.style.width = vw + 'px';
    frame.style.height = vh + 'px';
    frame.style.left = Math.max(8, global.innerWidth - vw - 12) + 'px';
    frame.style.top = Math.max(8, global.innerHeight - vh - 12) + 'px';
    frame.style.right = 'auto';
    frame.style.bottom = 'auto';
    syncPipButton();
    bumpLayout();
  }

  function finishClose(opts) {
    opts = opts || {};
    const wasActive = mode !== null || !!pipWindow || !!global.document.getElementById(PLACEHOLDER_ID)
      || global.document.body.classList.contains('tcg-spectate-inapp-pip')
      || global.document.body.classList.contains('tcg-spectate-doc-pip');
    if (!wasActive && !opts.force) {
      syncPipButton();
      return;
    }
    const frame = gameFrame()
      || (pipDoc() && pipDoc().getElementById('game-viewport-frame'));
    if (frame && frameHomeParent) {
      restoreFrameHome(frame);
    } else if (frame && !global.document.getElementById('game-viewport-frame')) {
      const screen = global.document.getElementById('screen-game');
      if (screen && !screen.contains(frame)) screen.appendChild(frame);
    }
    removePlaceholder();
    removeInappChrome();
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
    if (!isActive() && !global.document.body.classList.contains('tcg-spectate-doc-pip')
      && !global.document.body.classList.contains('tcg-spectate-inapp-pip')) {
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
    const frame = gameFrame();
    if (!frame) return;

    if (isActive()) {
      await closePip({ focusOpener: true });
      return;
    }

    try {
      if (supportsDocumentPip()) {
        await openDocumentPip(frame);
        return;
      }
    } catch (e) {
      // Fall through to in-app floating window.
    }
    openInappPip(frame);
  }

  async function togglePip() {
    if (isActive()) await closePip({ focusOpener: true });
    else await openPip();
  }

  function onLeaveSpectator() {
    if (isActive()) void closePip({ focusOpener: false });
  }

  // Keep button state after spectate enter/exit.
  if (!onViewportResize) {
    onViewportResize = () => {
      if (mode === 'inapp') bumpLayout();
    };
    global.addEventListener('resize', onViewportResize);
  }

  global.TCGSpectatePip = {
    toggle: togglePip,
    open: openPip,
    close: closePip,
    isActive: isActive,
    supportsDocumentPip: supportsDocumentPip,
    syncButton: syncPipButton,
    onLeaveSpectator: onLeaveSpectator,
  };
})(typeof window !== 'undefined' ? window : globalThis);
