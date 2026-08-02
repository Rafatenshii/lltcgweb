/**
 * TCG HTTP API client — game + account endpoints, Hostinger→VPS overflow, sync meta.
 *
 * Hostinger is primary. When it looks overloaded (timeouts/5xx/429), hub + new-game
 * traffic may temporarily use the VPS overflow origin. In-progress matches stay on
 * whichever origin created the room (never migrate mid-match).
 */
(function (global) {
  'use strict';

  /** Prefer VPS nginx TLS SSE (stream.loveliveradio.ca) — no Hostinger PHP. */
  global.TCG_SYNC_STREAM_URL = global.TCG_SYNC_STREAM_URL
    || 'https://stream.loveliveradio.ca/tcg/sync/stream';
  /** VPS overflow API (nginx → Docker :5003). Used only when Hostinger is unhealthy. */
  global.TCG_OVERFLOW_ORIGIN = global.TCG_OVERFLOW_ORIGIN
    || 'https://stream.loveliveradio.ca/tcg/api';
  global.TCG_OVERFLOW_ENABLED = global.TCG_OVERFLOW_ENABLED !== false;
  /** When true, new matches + in-game actions use VPS overflow API (Redis-backed). Hub/account stay Hostinger. */
  global.TCG_MATCH_API_PRIMARY = global.TCG_MATCH_API_PRIMARY === true
    || global.TCG_MATCH_API_PRIMARY === 1
    || global.TCG_MATCH_API_PRIMARY === '1';
  global.TCG_OVERFLOW_FAIL_THRESHOLD = global.TCG_OVERFLOW_FAIL_THRESHOLD || 3;
  global.TCG_OVERFLOW_HOLD_MS = global.TCG_OVERFLOW_HOLD_MS || 120000;
  global.TCG_OVERFLOW_PROBE_MS = global.TCG_OVERFLOW_PROBE_MS || 45000;

  global.WRAPPED_API = '/wrapped/api.php';
  global.TCG_SYNC_STREAM_FALLBACK_URL = global.TCG_SYNC_STREAM_FALLBACK_URL || './sync-stream';
  global.AUTH_FETCH_TIMEOUT_MS = global.AUTH_FETCH_TIMEOUT_MS || 12000;
  global.RECONNECT_FETCH_TIMEOUT_MS = global.RECONNECT_FETCH_TIMEOUT_MS || 12000;
  global.AUTH_ME_RETRY_COUNT = global.AUTH_ME_RETRY_COUNT || 3;

  const HOSTINGER_URLS = { API: './api.php', ACCOUNT_API: './account.php', origin: 'hostinger' };
  function overflowUrls() {
    const o = String(global.TCG_OVERFLOW_ORIGIN || '').replace(/\/$/, '');
    return { API: o + '/api.php', ACCOUNT_API: o + '/account.php', origin: 'overflow' };
  }

  // Forced primary (legacy). Empty = auto Hostinger with overflow.
  if (global.TCG_API_ORIGIN) {
    const origin = String(global.TCG_API_ORIGIN).replace(/\/$/, '');
    global.API = origin + '/api.php';
    global.ACCOUNT_API = origin + '/account.php';
  } else {
    global.API = HOSTINGER_URLS.API;
    global.ACCOUNT_API = HOSTINGER_URLS.ACCOUNT_API;
  }

  global._tcgOverflow = global._tcgOverflow || {
    hostingerFails: 0,
    overflowUntil: 0,
    overflowDownUntil: 0,
    lastProbeAt: 0,
    activations: 0,
    hubFailovers: 0,
    matchmakeFailovers: 0,
  };

  /** Ranked matchmaking + Hostinger-only account DB features stay on Hostinger — shared queue / SQLite must not split. */
  const OVERFLOW_BLOCKED_ACCOUNT = {
    ranked_join: 1, ranked_leave: 1, ranked_status: 1,
    login_bonus_status: 1, login_bonus_claim: 1,
  };
  const OVERFLOW_BLOCKED_GAME = {
    action: 1, // in-match always follows locked room origin
  };
  const MATCHMAKE_GAME = {
    create_room: 1, join_room: 1, casual_join: 1, casual_leave: 1,
    casual_status: 1, spectate_join: 1,
  };
  const INGAME_GAME = {
    action: 1, ping: 1, sync_ticket: 1, dry_run_actions: 1,
  };

  function sleepMs(ms) {
    return new Promise((resolve) => setTimeout(resolve, ms));
  }

  global.tcgOverflowStats = function tcgOverflowStats() {
    return { ...(global._tcgOverflow || {}) };
  };

  global.tcgNoteHostingerSuccess = function tcgNoteHostingerSuccess() {
    const s = global._tcgOverflow;
    s.hostingerFails = 0;
  };

  global.tcgNoteHostingerFailure = function tcgNoteHostingerFailure(err) {
    if (!global.isTransientAccountError(err) && !(err && (err.httpStatus >= 500 || err.httpStatus === 429 || err.httpStatus === 408))) {
      return;
    }
    const s = global._tcgOverflow;
    s.hostingerFails = (s.hostingerFails || 0) + 1;
    if (s.hostingerFails >= (global.TCG_OVERFLOW_FAIL_THRESHOLD || 3)) {
      const hold = global.TCG_OVERFLOW_HOLD_MS || 120000;
      if (s.overflowUntil < Date.now()) s.activations += 1;
      s.overflowUntil = Date.now() + hold;
      if (global.TCG_DEBUG && typeof global.TCG_DEBUG.warn === 'function') {
        global.TCG_DEBUG.warn('overflow', 'Hostinger unhealthy — overflow window open', {
          fails: s.hostingerFails,
          until: s.overflowUntil,
        });
      }
    }
  };

  global.tcgOverflowActive = function tcgOverflowActive() {
    if (!global.TCG_OVERFLOW_ENABLED || global.TCG_API_ORIGIN) return false;
    const s = global._tcgOverflow;
    if ((s.overflowDownUntil || 0) > Date.now()) return false;
    return (s.overflowUntil || 0) > Date.now();
  };

  /** Lock the match to one origin for its lifetime (no mid-match migrate). */
  global.tcgLockApiOrigin = function tcgLockApiOrigin(origin) {
    global.G = global.G || {};
    global.G.apiOrigin = origin === 'overflow' ? 'overflow' : 'hostinger';
    const urls = global.G.apiOrigin === 'overflow' ? overflowUrls() : HOSTINGER_URLS;
    global.API = urls.API;
    global.ACCOUNT_API = urls.ACCOUNT_API;
  };

  global.tcgClearApiOriginLock = function tcgClearApiOriginLock() {
    if (global.G) global.G.apiOrigin = null;
    if (!global.TCG_API_ORIGIN) {
      global.API = HOSTINGER_URLS.API;
      global.ACCOUNT_API = HOSTINGER_URLS.ACCOUNT_API;
    }
  };

  /**
   * @param {'hub'|'matchmake'|'ingame'} context
   * @param {string} [action]
   */
  global.tcgResolveApiUrls = function tcgResolveApiUrls(context, action) {
    if (global.TCG_API_ORIGIN) {
      const origin = String(global.TCG_API_ORIGIN).replace(/\/$/, '');
      return { API: origin + '/api.php', ACCOUNT_API: origin + '/account.php', origin: 'forced' };
    }
    const locked = global.G && global.G.apiOrigin;
    if (locked === 'overflow') return overflowUrls();
    if (locked === 'hostinger') return HOSTINGER_URLS;

    // Overhaul Part 1B: VPS is primary for match create/join/action (Redis rooms).
    if (global.TCG_MATCH_API_PRIMARY) {
      if (action && OVERFLOW_BLOCKED_ACCOUNT[action]) return HOSTINGER_URLS;
      if (context === 'matchmake' || context === 'ingame'
          || (action && (MATCHMAKE_GAME[action] || INGAME_GAME[action]))) {
        return overflowUrls();
      }
    }

    if (context === 'ingame' || (action && INGAME_GAME[action])) {
      return HOSTINGER_URLS;
    }
    if (action && OVERFLOW_BLOCKED_ACCOUNT[action]) return HOSTINGER_URLS;
    if (action && OVERFLOW_BLOCKED_GAME[action]) return HOSTINGER_URLS;

    if (global.tcgOverflowActive()) {
      if (context === 'matchmake' || (action && MATCHMAKE_GAME[action])) {
        global._tcgOverflow.matchmakeFailovers += 1;
      } else {
        global._tcgOverflow.hubFailovers += 1;
      }
      return overflowUrls();
    }
    return HOSTINGER_URLS;
  };

  global.tcgGameApiUrl = function tcgGameApiUrl() {
    return global.tcgResolveApiUrls('ingame').API;
  };

  async function probeOverflowAlive() {
    const s = global._tcgOverflow;
    if ((s.lastProbeAt || 0) + 10000 > Date.now()) {
      return (s.overflowDownUntil || 0) <= Date.now();
    }
    s.lastProbeAt = Date.now();
    try {
      const urls = overflowUrls();
      const r = await global.fetchWithTimeout(urls.API + '?action=ping', {}, 3500);
      if (!r.ok) throw new Error('overflow ping ' + r.status);
      s.overflowDownUntil = 0;
      return true;
    } catch (e) {
      s.overflowDownUntil = Date.now() + (global.TCG_OVERFLOW_PROBE_MS || 45000);
      return false;
    }
  }

  /** After hold window, probe Hostinger; clear overflow if healthy again. */
  global.tcgMaybeRecoverHostinger = async function tcgMaybeRecoverHostinger() {
    const s = global._tcgOverflow;
    if ((s.overflowUntil || 0) > Date.now()) return;
    if (s.hostingerFails <= 0) return;
    try {
      const r = await global.fetchWithTimeout(HOSTINGER_URLS.API + '?action=ping', {}, 4000);
      if (r.ok) {
        s.hostingerFails = 0;
        s.overflowUntil = 0;
      }
    } catch (e) { /* keep prior state */ }
  };

  global.fetchWithTimeout = async function fetchWithTimeout(url, options = {}, ms = global.AUTH_FETCH_TIMEOUT_MS) {
    const ctrl = new AbortController();
    const timer = setTimeout(() => ctrl.abort(), ms);
    const external = options && options.signal;
    const onExternalAbort = () => {
      try { ctrl.abort(); } catch (e) { /* ignore */ }
    };
    if (external) {
      if (external.aborted) {
        clearTimeout(timer);
        const err = new Error('Request timed out');
        err.httpStatus = 408;
        err.transient = true;
        throw err;
      }
      external.addEventListener('abort', onExternalAbort);
    }
    try {
      const opts = { ...(options || {}) };
      delete opts.signal;
      return await fetch(url, { ...opts, signal: ctrl.signal });
    } catch (e) {
      if (e && e.name === 'AbortError') {
        const err = new Error('Request timed out');
        err.httpStatus = 408;
        err.transient = true;
        throw err;
      }
      if (e && typeof e === 'object') e.transient = true;
      throw e;
    } finally {
      clearTimeout(timer);
      if (external) external.removeEventListener('abort', onExternalAbort);
    }
  };

  global.isAuthRejectedError = function isAuthRejectedError(err) {
    if (!err) return false;
    const status = Number(err.httpStatus) || 0;
    if (status === 401 || status === 403) return true;
    const msg = String(err.message || '').toLowerCase();
    return /authentication required|invalid or expired|invalid token|unauthorized|session expired/.test(msg);
  };

  global.isTransientAccountError = function isTransientAccountError(err) {
    if (!err) return true;
    if (global.isAuthRejectedError(err)) return false;
    if (err.transient) return true;
    const status = Number(err.httpStatus) || 0;
    if (status === 0 || status === 408 || status === 429 || status >= 500) return true;
    const msg = String(err.message || '').toLowerCase();
    return /timed out|timeout|network|failed to fetch|server (error|busy)|account error \(5|could not reach/.test(msg);
  };

  global.handleMissionCompletions = function handleMissionCompletions(res) {
    if (!res || typeof res !== 'object') return;
    if (Array.isArray(res.mission_completions) && res.mission_completions.length) {
      if (global.TCGMissions && typeof global.TCGMissions.onMissionCompletions === 'function') {
        global.TCGMissions.onMissionCompletions(res.mission_completions);
      }
    }
    if (typeof res.claimable_count === 'number' && global.TCGMissions && typeof global.TCGMissions.syncHubBadge === 'function') {
      global.TCGMissions.syncHubBadge(res.claimable_count);
    } else if (res.missions && typeof res.missions.claimable_count === 'number' && global.TCGMissions && typeof global.TCGMissions.syncHubBadge === 'function') {
      global.TCGMissions.syncHubBadge(res.missions.claimable_count);
    }
    global.handleRankedPrReward(res);
  };

  global.handleRankedPrReward = function handleRankedPrReward(res) {
    if (!res || typeof res !== 'object' || !res.ranked_pr_reward) return;
    global.G = global.G || {};
    global.G.lastRankedPrReward = res.ranked_pr_reward;
    const reward = res.ranked_pr_reward;
    if (reward && reward.daily && typeof global.syncRankedPrFromReward === 'function') {
      global.syncRankedPrFromReward(reward);
      if (typeof global.updateRankedPrUI === 'function') global.updateRankedPrUI();
    }
    if (reward.star_gems_earned > 0 && global.A && global.A.profile) {
      global.A.profile.star_gems = reward.star_gems ?? global.A.profile.star_gems;
      if (typeof global.syncStarGemsFromProfile === 'function') {
        global.syncStarGemsFromProfile(global.A.profile);
      }
      if (typeof global.updateStarGemsUI === 'function') global.updateStarGemsUI();
    }
    if (typeof global.queueRankedPrReward === 'function') {
      global.queueRankedPrReward(reward);
    }
  };

  global.parseAccountJson = async function parseAccountJson(r) {
    let d;
    try {
      d = await r.json();
    } catch (e) {
      const err = new Error(r.ok ? 'Invalid account response' : ('Account error (' + r.status + ')'));
      err.httpStatus = r.status || (r.ok ? 500 : r.status);
      throw err;
    }
    return d;
  };

  async function accountGetOnce(urls, action, extra) {
    const token = global.getAuthToken();
    const q = new URLSearchParams({ action, token, ...extra });
    let r;
    try {
      r = await global.fetchWithTimeout(urls.ACCOUNT_API + '?' + q);
    } catch (e) {
      if (e && typeof e === 'object' && !e.httpStatus) e.httpStatus = 0;
      throw e;
    }
    const d = await global.parseAccountJson(r);
    if (!d.success && d.error) {
      const err = new Error(d.error);
      err.httpStatus = r.status || 400;
      throw err;
    }
    if (!r.ok) {
      const err = new Error('Account error (' + r.status + ')');
      err.httpStatus = r.status || 500;
      throw err;
    }
    global.handleMissionCompletions(d);
    return d;
  }

  global.accountGet = async function accountGet(action, extra = {}) {
    void global.tcgMaybeRecoverHostinger();
    const primary = global.tcgResolveApiUrls('hub', action);
    try {
      const d = await accountGetOnce(primary, action, extra);
      if (primary.origin === 'hostinger') global.tcgNoteHostingerSuccess();
      return d;
    } catch (e) {
      if (primary.origin === 'hostinger') global.tcgNoteHostingerFailure(e);
      if (global.isAuthRejectedError(e)) throw e;
      if (OVERFLOW_BLOCKED_ACCOUNT[action]) throw e;
      if (!global.TCG_OVERFLOW_ENABLED || primary.origin === 'overflow') throw e;
      if (!global.isTransientAccountError(e)) throw e;
      if (!(await probeOverflowAlive())) throw e;
      global._tcgOverflow.hubFailovers += 1;
      return accountGetOnce(overflowUrls(), action, extra);
    }
  };

  global.accountGetMeWithRetry = async function accountGetMeWithRetry(retries) {
    const max = Math.max(1, Number(retries != null ? retries : global.AUTH_ME_RETRY_COUNT) || 3);
    let lastErr = null;
    for (let attempt = 0; attempt < max; attempt++) {
      try {
        return await global.accountGet('me');
      } catch (e) {
        lastErr = e;
        if (global.isAuthRejectedError(e)) throw e;
        if (!global.isTransientAccountError(e) || attempt >= max - 1) throw e;
        await sleepMs(400 * (2 ** attempt));
      }
    }
    throw lastErr || new Error('Account load failed');
  };

  async function accountPostOnce(urls, action, body) {
    const token = global.getAuthToken();
    const r = await global.fetchWithTimeout(urls.ACCOUNT_API + '?action=' + encodeURIComponent(action), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Auth-Token': token },
      body: JSON.stringify({ ...body, token }),
    });
    const d = await global.parseAccountJson(r);
    if (!d.success && d.error) {
      const err = new Error(d.error);
      err.httpStatus = r.status || 400;
      if (d.retryable) err.retryable = true;
      if (d.code) err.code = d.code;
      throw err;
    }
    if (!r.ok) {
      const err = new Error('Account error (' + r.status + ')');
      err.httpStatus = r.status || 500;
      throw err;
    }
    global.handleMissionCompletions(d);
    return d;
  }

  global.accountPost = async function accountPost(action, body = {}) {
    void global.tcgMaybeRecoverHostinger();
    const primary = global.tcgResolveApiUrls('hub', action);
    const maxAttempts = 4;
    let lastErr = null;
    for (let attempt = 0; attempt < maxAttempts; attempt++) {
      try {
        const d = await accountPostOnce(primary, action, body);
        if (primary.origin === 'hostinger') global.tcgNoteHostingerSuccess();
        return d;
      } catch (e) {
        lastErr = e;
        if (global.isAuthRejectedError(e)) throw e;
        if (!global.isRetryableApiError(e) || attempt >= maxAttempts - 1) {
          break;
        }
        await sleepMs(150 * (2 ** attempt));
      }
    }
    if (primary.origin === 'hostinger') global.tcgNoteHostingerFailure(lastErr);
    if (global.isAuthRejectedError(lastErr)) throw lastErr;
    if (OVERFLOW_BLOCKED_ACCOUNT[action]) throw lastErr;
    if (!global.TCG_OVERFLOW_ENABLED || primary.origin === 'overflow') throw lastErr;
    if (!global.isTransientAccountError(lastErr) && !global.isRetryableApiError(lastErr)) throw lastErr;
    if (!(await probeOverflowAlive())) throw lastErr;
    global._tcgOverflow.hubFailovers += 1;
    return accountPostOnce(overflowUrls(), action, body);
  };

  global.createApiError = function createApiError(message, status, extra = {}) {
    const err = new Error(message || 'Request failed');
    err.httpStatus = Number(status) || 0;
    if (extra.retryable) err.retryable = true;
    if (extra.code) err.code = extra.code;
    return err;
  };

  global.isRetryableApiError = function isRetryableApiError(err) {
    if (!err) return false;
    if (err.retryable) return true;
    const status = Number(err.httpStatus) || 0;
    if (status === 503) return true;
    const msg = String(err.message || '');
    return /Server busy|Lock timeout|Cannot acquire lock|database is locked|SQLITE_BUSY/i.test(msg);
  };

  global.parseGameApiResponse = async function parseGameApiResponse(r) {
    const status = r.status || 0;
    let d;
    try {
      d = await r.json();
    } catch (e) {
      const msg = status >= 500 ? 'Server error' : (status >= 400 ? `Request failed (${status})` : 'Invalid server response');
      throw global.createApiError(msg, status >= 400 ? status : 500);
    }
    if (d && d.error) {
      throw global.createApiError(d.error, status >= 400 ? status : 400, {
        retryable: !!d.retryable,
        code: d.code || '',
      });
    }
    if (!r.ok) {
      throw global.createApiError(`Request failed (${status})`, status || 500);
    }
    return d;
  };

  global.closeApiErrorPopup = function closeApiErrorPopup() {
    const ov = document.getElementById('overlay-api-error');
    if (ov) ov.classList.remove('open');
    global._apiErrorPopupOpen = false;
  };

  global.showApiErrorPopup = function showApiErrorPopup(message, opts = {}) {
    const status = Number(opts.status) || 0;
    const msg = String(message || 'Request failed').trim();
    if (!msg) return;

    const key = `${status}|${msg.slice(0, 240)}`;
    const now = Date.now();
    if (global._apiErrorPopupOpen && global._lastApiErrorPopupKey === key) return;
    if (global._lastApiErrorPopupKey === key && global._lastApiErrorPopupAt && now - global._lastApiErrorPopupAt < 8000) return;
    global._lastApiErrorPopupKey = key;
    global._lastApiErrorPopupAt = now;

    let ov = document.getElementById('overlay-api-error');
    if (!ov) return;
    const tFn = global.LLTCG_I18N && global.LLTCG_I18N.t;
    const t = typeof tFn === 'function' ? tFn : (_k, _v) => _k;
    const titleEl = ov.querySelector('#api-error-title');
    const msgEl = ov.querySelector('#api-error-msg');
    const hintEl = ov.querySelector('#api-error-hint');
    if (titleEl) titleEl.textContent = status >= 500 ? t('apiError.titleServer') : t('apiError.titleClient');
    if (msgEl) msgEl.textContent = msg;
    if (hintEl) hintEl.textContent = status >= 500 ? t('apiError.hintServer') : t('apiError.hintClient');
    ov.classList.add('open');
    global._apiErrorPopupOpen = true;
    if (typeof global.sfxPlay === 'function') global.sfxPlay('error', { volume: 0.8 });
  };

  global.reportApiError = function reportApiError(err, opts = {}) {
    if (!err || opts.silent) return;
    const status = err.httpStatus || opts.status || 0;
    const msg = err.message || 'Request failed';
    if (global.TCG_DEBUG && typeof global.TCG_DEBUG.warn === 'function') {
      global.TCG_DEBUG.warn('api', opts.source || 'error', msg, status);
    }
    if (!opts.force && status < 400) return;
    global.showApiErrorPopup(msg, { status });
  };

  async function apiPostOnce(urls, action, body, opts) {
    const payload = { ...body };
    const authToken = typeof global.getAuthToken === 'function' ? global.getAuthToken() : '';
    const headers = { 'Content-Type': 'application/json' };
    if (authToken) {
      headers['X-Auth-Token'] = authToken;
      if (action === 'action') {
        payload.auth_token = authToken;
      } else if (!payload.token) {
        payload.token = authToken;
      }
    }
    const r = await fetch(`${urls.API}?action=${action}`, {
      method: 'POST',
      headers,
      body: JSON.stringify(payload),
    });
    try {
      const d = await global.parseGameApiResponse(r);
      global.handleMissionCompletions(d);
      return d;
    } catch (e) {
      if (!opts.silent && !opts.deferErrorReport) {
        global.reportApiError(e, { source: 'apiPost:' + action, silent: !!opts.silent });
      }
      throw e;
    }
  }

  global.apiPost = async function apiPost(action, body = {}, opts = {}) {
    void global.tcgMaybeRecoverHostinger();
    const ctx = MATCHMAKE_GAME[action] ? 'matchmake' : (INGAME_GAME[action] ? 'ingame' : 'hub');
    const primary = global.tcgResolveApiUrls(ctx, action);
    // In-match actions (and busy account writes) auto-retry lock/503 before surfacing.
    const maxAttempts = (action === 'action' || opts.retryBusy) ? 4 : 1;
    let lastErr = null;
    try {
      for (let attempt = 0; attempt < maxAttempts; attempt++) {
        try {
          const d = await apiPostOnce(primary, action, body, {
            ...opts,
            deferErrorReport: maxAttempts > 1,
          });
          if (primary.origin === 'hostinger') global.tcgNoteHostingerSuccess();
          if (MATCHMAKE_GAME[action] && d && (d.room_id || d.ok || d.token || d.player_token)) {
            global.tcgLockApiOrigin(primary.origin === 'overflow' ? 'overflow' : 'hostinger');
          }
          return d;
        } catch (e) {
          lastErr = e;
          if (!global.isRetryableApiError(e) || attempt >= maxAttempts - 1) {
            break;
          }
          await sleepMs(150 * (2 ** attempt));
        }
      }
      if (lastErr) {
        if (primary.origin === 'hostinger') global.tcgNoteHostingerFailure(lastErr);
        global.reportApiError(lastErr, { source: 'apiPost:' + action, silent: !!opts.silent });
        if (ctx === 'ingame' || (global.G && global.G.apiOrigin === 'hostinger')) throw lastErr;
        if (OVERFLOW_BLOCKED_GAME[action] || OVERFLOW_BLOCKED_ACCOUNT[action]) throw lastErr;
        if (!global.TCG_OVERFLOW_ENABLED || primary.origin === 'overflow') throw lastErr;
        if (!global.isTransientAccountError(lastErr)
            && !(lastErr && (lastErr.httpStatus >= 500 || lastErr.httpStatus === 429 || lastErr.httpStatus === 408 || lastErr.httpStatus === 503))) {
          throw lastErr;
        }
        if (!(await probeOverflowAlive())) throw lastErr;
        global._tcgOverflow.matchmakeFailovers += 1;
        const d = await apiPostOnce(overflowUrls(), action, body, opts);
        if (MATCHMAKE_GAME[action]) global.tcgLockApiOrigin('overflow');
        return d;
      }
      throw lastErr || new Error('Request failed');
    } catch (e) {
      throw e;
    }
  };

  global.captureSyncMeta = function captureSyncMeta(res) {
    if (!res || typeof res !== 'object' || !global.G) return;
    if (res.sync_enabled && res.sync_ticket) {
      global.G.syncEnabled = true;
      global.G.syncTicket = res.sync_ticket;
    } else if (res.sync_enabled === false) {
      global.G.syncEnabled = false;
      global.G.syncTicket = null;
    }
  };

  function initApiErrorPopup() {
    const ov = document.getElementById('overlay-api-error');
    const btn = document.getElementById('btn-api-error-ok');
    if (!ov || ov.dataset.apiErrorBound) return;
    ov.dataset.apiErrorBound = '1';
    const close = () => global.closeApiErrorPopup();
    btn?.addEventListener('click', close);
    ov.addEventListener('click', (ev) => { if (ev.target === ov) close(); });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initApiErrorPopup);
  else initApiErrorPopup();
})(window);
