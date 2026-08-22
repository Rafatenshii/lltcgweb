/**
 * Android APK asset cache — full pack vs on-demand.
 * No-op unless LoveCaAndroid / Capacitor native (not mobile Chrome layout).
 */
(function (global) {
  'use strict';

  const MODE_KEY = 'tcg_apk_asset_mode';
  const COMPLETE_KEY = 'tcg_apk_full_complete';
  const CACHE_NAME = 'lltcg-apk-assets-v1';
  const MODE_URL = './apk-asset-mode.json';
  const MANIFEST_URL = 'apk_asset_manifest.json?v=2';
  const FS_DIR = 'apk-assets';
  const CONCURRENCY = 4;

  const state = {
    mode: '',
    paused: false,
    running: false,
    progress: { done: 0, total: 0, failed: 0 },
    listeners: [],
    fsIndex: Object.create(null),
    blobMap: Object.create(null),
  };

  function tApk(key, fallback, vars) {
    const I18N = global.LLTCG_I18N;
    const fn = I18N && typeof I18N.t === 'function' ? I18N.t : (typeof global.t === 'function' ? global.t : null);
    if (!fn) return fallback;
    const v = fn(key, vars);
    return (v && v !== key) ? v : fallback;
  }

  function isNativeApk() {
    if (typeof global.tcgPortraitForceNative === 'function') {
      try {
        return !!global.tcgPortraitForceNative();
      } catch (e) { /* fall through */ }
    }
    const ua = String(global.navigator && global.navigator.userAgent || '');
    if (/LoveCaAndroid/i.test(ua)) return true;
    try {
      const Cap = global.Capacitor;
      if (Cap && typeof Cap.isNativePlatform === 'function' && Cap.isNativePlatform()) return true;
      if (Cap && typeof Cap.getPlatform === 'function' && Cap.getPlatform() === 'android') return true;
    } catch (e) { /* ignore */ }
    return false;
  }

  function getMode() {
    try {
      const v = localStorage.getItem(MODE_KEY);
      return v === 'full' || v === 'on_demand' ? v : '';
    } catch (e) {
      return '';
    }
  }

  function notify() {
    state.listeners.slice().forEach((fn) => {
      try { fn(getStatus()); } catch (e) { /* ignore */ }
    });
  }

  function getStatus() {
    return {
      native: isNativeApk(),
      mode: state.mode || getMode(),
      paused: state.paused,
      running: state.running,
      complete: isFullComplete(),
      progress: { ...state.progress },
    };
  }

  function absUrl(url) {
    try {
      return new URL(String(url || ''), global.document?.baseURI || global.location?.href || '').href;
    } catch (e) {
      return String(url || '');
    }
  }

  function urlEligible(url) {
    const s = String(url || '');
    if (!s || /^(data:|blob:)/i.test(s)) return false;
    try {
      const u = new URL(s, global.location?.href || '');
      if (/cardimg\.php$/i.test(u.pathname)) return true;
      return /\/assets\/(sleeves|playmats|stamps|sfx)\//i.test(u.pathname);
    } catch (e) {
      return /cardimg\.php/i.test(s) || /assets\/(sleeves|playmats|stamps|sfx)\//i.test(s);
    }
  }

  function filesystemPlugin() {
    try {
      return global.Capacitor?.Plugins?.Filesystem || null;
    } catch (e) {
      return null;
    }
  }

  function convertFileSrc(path) {
    try {
      const Cap = global.Capacitor;
      if (Cap && typeof Cap.convertFileSrc === 'function') return Cap.convertFileSrc(path);
    } catch (e) { /* ignore */ }
    return '';
  }

  function fsPathForUrl(url) {
    const abs = absUrl(url);
    let key = abs.replace(/^https?:\/\//i, '');
    key = key.replace(/[<>:"|?*]/g, '_').replace(/\\/g, '/');
    return FS_DIR + '/' + key;
  }

  async function persistStorage() {
    try {
      if (global.navigator?.storage?.persist) await global.navigator.storage.persist();
    } catch (e) { /* ignore */ }
  }

  async function openCache() {
    if (!global.caches || typeof global.caches.open !== 'function') return null;
    return global.caches.open(CACHE_NAME);
  }

  async function writeModeRecord(mode) {
    const cache = await openCache();
    if (!cache) return;
    const body = JSON.stringify({ mode: mode || '' });
    await cache.put(MODE_URL, new Response(body, {
      headers: { 'Content-Type': 'application/json' },
    }));
  }

  async function registerSw() {
    if (!isNativeApk()) return;
    if (!('serviceWorker' in global.navigator)) return;
    try {
      await global.navigator.serviceWorker.register('./apk-asset-sw.js?v=5', { scope: './' });
    } catch (e) { /* ignore */ }
  }

  async function cacheMatch(url) {
    const cache = await openCache();
    if (!cache) return null;
    return cache.match(absUrl(url), { ignoreVary: true });
  }

  async function arrayBufferFor(url) {
    if (!isNativeApk() || !url) return null;
    try {
      const hit = await cacheMatch(url);
      if (!hit || !hit.ok) return null;
      return await hit.arrayBuffer();
    } catch (e) {
      return null;
    }
  }

  async function blobUrlFor(url) {
    if (!isNativeApk() || !url) return '';
    const abs = absUrl(url);
    if (state.blobMap[abs]) return state.blobMap[abs];
    try {
      const hit = await cacheMatch(abs);
      if (!hit || !hit.ok) return '';
      const blob = await hit.blob();
      const obj = URL.createObjectURL(blob);
      state.blobMap[abs] = obj;
      return obj;
    } catch (e) {
      return '';
    }
  }

  async function cachePutUrl(url) {
    const abs = absUrl(url);
    const cache = await openCache();
    if (!cache) return false;
    const hit = await cache.match(abs, { ignoreVary: true });
    if (hit) return true;
    const res = await fetch(abs, { credentials: 'same-origin' });
    if (!res.ok) return false;
    try {
      await cache.put(abs, res.clone());
      return true;
    } catch (e) {
      return await filesystemPut(abs, res);
    }
  }

  async function filesystemPut(abs, res) {
    const Fs = filesystemPlugin();
    if (!Fs || typeof Fs.writeFile !== 'function') return false;
    try {
      const buf = await res.arrayBuffer();
      const bytes = new Uint8Array(buf);
      let binary = '';
      const chunk = 0x8000;
      for (let i = 0; i < bytes.length; i += chunk) {
        binary += String.fromCharCode.apply(null, bytes.subarray(i, i + chunk));
      }
      const b64 = btoa(binary);
      const path = fsPathForUrl(abs);
      await Fs.writeFile({
        path,
        data: b64,
        directory: 'DATA',
        recursive: true,
      });
      let served = path;
      try {
        if (typeof Fs.getUri === 'function') {
          const got = await Fs.getUri({ path, directory: 'DATA' });
          if (got && got.uri) served = convertFileSrc(got.uri) || got.uri;
        }
      } catch (e2) { /* keep path */ }
      state.fsIndex[abs] = served;
      persistFsIndex();
      return true;
    } catch (e) {
      return false;
    }
  }

  function persistFsIndex() {
    try {
      localStorage.setItem('tcg_apk_fs_index', JSON.stringify(state.fsIndex));
    } catch (e) { /* ignore */ }
  }

  function loadFsIndex() {
    try {
      const raw = localStorage.getItem('tcg_apk_fs_index');
      const o = raw ? JSON.parse(raw) : null;
      if (o && typeof o === 'object') state.fsIndex = o;
    } catch (e) { /* ignore */ }
  }

  function rewriteSync(url) {
    if (!isNativeApk() || !url || !urlEligible(url)) return url;
    const abs = absUrl(url);
    const pathOrUrl = state.fsIndex[abs];
    if (!pathOrUrl) return url;
    if (/^https?:\/\//i.test(pathOrUrl) || /^capacitor:/i.test(pathOrUrl)) return pathOrUrl;
    return convertFileSrc(pathOrUrl) || url;
  }

  async function ensureCached(url) {
    if (!isNativeApk() || !urlEligible(url)) return url;
    const mode = state.mode || getMode();
    if (mode !== 'on_demand' && mode !== 'full') return url;
    const abs = absUrl(url);
    if (state.fsIndex[abs]) return rewriteSync(url);
    await cachePutUrl(abs);
    return url;
  }

  async function loadManifest() {
    const r = await fetch(MANIFEST_URL, { cache: 'no-cache' });
    if (!r.ok) throw new Error('manifest HTTP ' + r.status);
    const d = await r.json();
    const list = Array.isArray(d?.assets) ? d.assets : [];
    return list.map((row) => (typeof row === 'string' ? row : row?.url)).filter(Boolean);
  }

  async function runFullQueue() {
    if (state.running) return;
    state.running = true;
    state.paused = false;
    notify();
    let urls = [];
    try {
      urls = await loadManifest();
    } catch (e) {
      state.running = false;
      notify();
      return;
    }
    state.progress.total = urls.length;
    state.progress.done = 0;
    state.progress.failed = 0;
    let i = 0;
    const worker = async () => {
      while (i < urls.length) {
        if (state.paused || state.mode !== 'full') break;
        if (global.document?.hidden) {
          await new Promise((res) => setTimeout(res, 800));
          continue;
        }
        if (global.navigator && global.navigator.onLine === false) {
          await new Promise((res) => setTimeout(res, 1500));
          continue;
        }
        const idx = i++;
        const u = urls[idx];
        try {
          const ok = await cachePutUrl(u);
          if (ok) state.progress.done++;
          else state.progress.failed++;
        } catch (e) {
          state.progress.failed++;
        }
        if (idx % 2 === 0) notify();
      }
    };
    await Promise.all(Array.from({ length: CONCURRENCY }, () => worker()));
    state.running = false;
    const processed = (state.progress.done || 0) + (state.progress.failed || 0);
    const tot = state.progress.total || 0;
    const failRatio = tot > 0 ? (state.progress.failed || 0) / tot : 1;
    const finished = tot > 0 && processed >= tot && !state.paused;
    if (finished && failRatio < 0.05) {
      try { localStorage.setItem(COMPLETE_KEY, '1'); } catch (e) { /* ignore */ }
    }
    notify();
  }

  async function setMode(mode) {
    if (mode !== 'full' && mode !== 'on_demand') return;
    state.mode = mode;
    try { localStorage.setItem(MODE_KEY, mode); } catch (e) { /* ignore */ }
    await writeModeRecord(mode);
    notify();
    if (mode === 'full') {
      try { localStorage.removeItem(COMPLETE_KEY); } catch (e) { /* ignore */ }
      void runFullQueue();
    } else {
      state.paused = true;
    }
  }

  function pauseFull() {
    state.paused = true;
    notify();
  }

  function resumeFull() {
    if (getMode() !== 'full' && state.mode !== 'full') return;
    state.mode = 'full';
    state.paused = false;
    void runFullQueue();
  }

  async function clearCache() {
    try {
      if (global.caches) await global.caches.delete(CACHE_NAME);
    } catch (e) { /* ignore */ }
    state.fsIndex = Object.create(null);
    persistFsIndex();
    state.progress = { done: 0, total: 0, failed: 0 };
    try { localStorage.removeItem(COMPLETE_KEY); } catch (e) { /* ignore */ }
    notify();
  }

  function onStatus(fn) {
    if (typeof fn === 'function') state.listeners.push(fn);
  }

  function patchUrlHelpers() {
    /* Keep cardimg.php and catalog src URLs unchanged so CSS/img/SW work. */
  }

  function isFullComplete() {
    try { return localStorage.getItem(COMPLETE_KEY) === '1'; } catch (e) { return false; }
  }

  function pctOf(st) {
    const tot = st.progress.total || 0;
    const n = (st.progress.done || 0) + (st.progress.failed || 0);
    if (tot <= 0) return st.running ? 1 : 0;
    return Math.max(0, Math.min(100, Math.round((n / tot) * 100)));
  }

  function setBar(el, fill, pct) {
    if (!el || !fill) return;
    fill.style.width = pct + '%';
    el.setAttribute('aria-valuenow', String(pct));
    el.hidden = false;
  }

  function showDownloadOverlay(active) {
    const overlay = document.getElementById('apk-asset-first-launch');
    const choice = document.getElementById('apk-asset-choice-block');
    const prog = document.getElementById('apk-asset-progress-block');
    if (!overlay) return;
    overlay.hidden = !active;
    if (choice) choice.hidden = active;
    if (prog) prog.hidden = !active;
    if (active && typeof global.LLTCG_I18N?.applyI18n === 'function') {
      global.LLTCG_I18N.applyI18n(overlay);
    }
  }

  function paintProgress(st) {
    const pct = pctOf(st);
    const downloading = st.mode === 'full' && st.running;
    const overlay = document.getElementById('apk-asset-first-launch');
    const choice = document.getElementById('apk-asset-choice-block');
    if (downloading && overlay) {
      overlay.hidden = false;
      if (choice) choice.hidden = true;
      const prog = document.getElementById('apk-asset-progress-block');
      if (prog) prog.hidden = false;
      setBar(document.getElementById('apk-asset-bar'), document.getElementById('apk-asset-bar-fill'), pct);
      const lab = document.getElementById('apk-asset-bar-label');
      if (lab) {
        const bits = [tApk('options.apkAssets.percent', '{pct}%', { pct })];
        if (st.progress.total) {
          bits.push(tApk('options.apkAssets.progress', '{done} / {total} files', {
            done: st.progress.done,
            total: st.progress.total,
          }));
        }
        if (st.paused) bits.push(tApk('options.apkAssets.paused', 'paused'));
        lab.textContent = bits.join(' · ');
      }
    } else if (overlay && (getMode() || !st.running)) {
      if (getMode()) overlay.hidden = true;
    }
    const optBar = document.getElementById('options-apk-asset-bar');
    const optFill = document.getElementById('options-apk-asset-bar-fill');
    if (optBar && optFill) {
      const showBar = st.mode === 'full' && st.progress.total && !st.complete && (st.running || st.paused);
      if (showBar) {
        optBar.hidden = false;
        setBar(optBar, optFill, pct);
      } else {
        optBar.hidden = true;
      }
    }
  }

  function bindUi() {
    const overlay = document.getElementById('apk-asset-first-launch');
    const opts = document.getElementById('options-apk-assets-row');
    if (!isNativeApk()) {
      if (overlay) overlay.hidden = true;
      if (opts) opts.hidden = true;
      return;
    }
    if (opts) opts.hidden = false;
    if (typeof global.LLTCG_I18N?.applyI18n === 'function') {
      if (overlay) global.LLTCG_I18N.applyI18n(overlay);
      if (opts) global.LLTCG_I18N.applyI18n(opts);
    }
    const mode = getMode();
    state.mode = mode;
    if (!mode && overlay) {
      overlay.hidden = false;
      const choice = document.getElementById('apk-asset-choice-block');
      const prog = document.getElementById('apk-asset-progress-block');
      if (choice) choice.hidden = false;
      if (prog) prog.hidden = true;
    } else if (overlay && !(mode === 'full' && !isFullComplete() && state.running)) {
      overlay.hidden = true;
    }
    const btnFull = document.getElementById('btn-apk-assets-full');
    const btnDemand = document.getElementById('btn-apk-assets-demand');
    const btnOptFull = document.getElementById('btn-apk-opt-full');
    const btnOptDemand = document.getElementById('btn-apk-opt-demand');
    const btnPause = document.getElementById('btn-apk-opt-pause');
    const btnResume = document.getElementById('btn-apk-opt-resume');
    const btnClear = document.getElementById('btn-apk-opt-clear');
    const choose = async (m) => {
      if (m === 'full') showDownloadOverlay(true);
      await setMode(m);
      if (m !== 'full' && overlay) overlay.hidden = true;
    };
    btnFull?.addEventListener('click', () => void choose('full'));
    btnDemand?.addEventListener('click', () => void choose('on_demand'));
    btnOptFull?.addEventListener('click', () => void setMode('full'));
    btnOptDemand?.addEventListener('click', () => void setMode('on_demand'));
    btnPause?.addEventListener('click', pauseFull);
    btnResume?.addEventListener('click', resumeFull);
    btnClear?.addEventListener('click', () => {
      const msg = tApk('options.apkAssets.clearConfirm', 'Delete downloaded card art and cosmetics from this device?');
      if (global.confirm(msg)) void clearCache();
    });
    onStatus((st) => {
      updateOptionsStatus(st);
      paintProgress(st);
    });
    updateOptionsStatus(getStatus());
    paintProgress(getStatus());
    if (mode === 'full' && !isFullComplete()) void runFullQueue();
  }

  function syncOptionsDownloadButtons(st) {
    const complete = !!(st.complete || isFullComplete());
    const fullActive = st.mode === 'full' && !complete && (st.running || st.paused);
    const btnPause = document.getElementById('btn-apk-opt-pause');
    const btnResume = document.getElementById('btn-apk-opt-resume');
    if (btnPause) btnPause.hidden = !(fullActive && !st.paused);
    if (btnResume) btnResume.hidden = !(fullActive && st.paused);
  }

  function updateOptionsStatus(st) {
    const el = document.getElementById('options-apk-assets-status');
    syncOptionsDownloadButtons(st);
    if (!el) return;
    const mode = st.mode === 'full'
      ? tApk('options.apkAssets.modeFull', 'Download all')
      : (st.mode === 'on_demand'
        ? tApk('options.apkAssets.modeDemand', 'Download when needed')
        : tApk('options.apkAssets.modeUnset', 'Not chosen yet'));
    let extra = '';
    if (st.mode === 'full' && st.progress.total && !(st.complete || isFullComplete())) {
      extra = ' — ' + tApk('options.apkAssets.progress', '{done} / {total} files', {
        done: st.progress.done,
        total: st.progress.total,
      });
      if (st.paused) extra += ' (' + tApk('options.apkAssets.paused', 'paused') + ')';
    }
    el.textContent = mode + extra;
  }

  async function boot() {
    if (!isNativeApk()) {
      bindUi();
      return;
    }
    loadFsIndex();
    state.mode = getMode();
    await persistStorage();
    await registerSw();
    if (state.mode) await writeModeRecord(state.mode);
    patchUrlHelpers();
    bindUi();
  }

  global.LLTCG_APK_ASSETS = {
    isNativeApk,
    urlEligible,
    getMode,
    setMode,
    getStatus,
    ensureCached,
    blobUrlFor,
    arrayBufferFor,
    rewriteSync,
    pauseFull,
    resumeFull,
    clearCache,
    onStatus,
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => void boot());
  } else {
    void boot();
  }
})(typeof window !== 'undefined' ? window : globalThis);
