/**
 * TCG poll loop, SSE sync stream, and pullLatestState transport.
 */
(function (global) {
  'use strict';

  global.TCG_PRESENCE_PING_MS = 30000;
  /** Base interval for poll=0 fallback when SSE is down (grows with backoff). */
  global.TCG_SYNC_FALLBACK_POLL_MS = 5000;
  global.TCG_SYNC_FALLBACK_POLL_MAX_MS = 20000;
  global.TCG_SYNC_MAX_FAILS = 6;
  /** Prefer Apache-proxied VPS stream; Hostinger PHP proxy is fallback only. */
  global.TCG_SYNC_USE_PHP_PROXY = false;

  global._tcgSyncStats = global._tcgSyncStats || {
    streamDirect: 0,
    streamProxy: 0,
    streamFails: 0,
    getState: 0,
    disconnectHints: 0,
  };

  global.tcgSyncStatsSnapshot = function tcgSyncStatsSnapshot() {
    return { ...(global._tcgSyncStats || {}) };
  };

  global.stopSyncStream = function stopSyncStream() {
    if (G.syncEventSource) {
      G.syncEventSource.close();
      G.syncEventSource = null;
    }
    clearTimeout(G.syncFallbackTimer);
    G.syncFallbackTimer = null;
    clearTimeout(G._syncReconnectTimer);
    G._syncReconnectTimer = null;
    clearTimeout(G._syncPullTimer);
    G._syncPullTimer = null;
    G._syncPullBlockedSpins = 0;
    clearInterval(G.presenceTimer);
    G.presenceTimer = null;
  };

  /** Match doPollLegacy gates — avoid fetching mid-animation (queues states and stacks anims). */
  global.pollPresentationBlocked = function pollPresentationBlocked() {
    // Soft-heal only when the director is idle — never clear flags under an active run.
    const directorActive = typeof LiveRoundDirector !== 'undefined' && LiveRoundDirector.active;
    const serverLiveShowInFlight = !!G.gameState?.live_show?.stage
      && G.gameState.live_show.stage !== 'done';
    if (G._liveShowRunnerActive) return true;
    if (!directorActive
        && !serverLiveShowInFlight
        && G._liveRoundPlaybackActive && !G.animating && !G._perfSpectacleActive && !G._liveSpectacleGateRunning) {
      TCG_DEBUG.warn('poll', 'clear stale liveRoundPlaybackActive');
      G._liveRoundPlaybackActive = false;
      if (G._livePollHold && typeof releaseLivePolls === 'function') releaseLivePolls();
    }
    // Stuck Performance chrome after the round already reached Main / judge pick softlocks sync.
    const ph = G.gameState?.phase;
    const prType = G.gameState?.pending_prompt?.type;
    const mainStable = ph === 'main_first' || ph === 'main_second'
      || ph === 'active_first' || ph === 'active_second';
    const metaType = G.gameState?.pending_prompt_meta?.type || null;
    const judgePickReady = ph === 'live_judge'
      && (prType === 'pick_judge_success_live' || prType === 'replace_success_with_wr_live' || prType === 'sbp6_live_wr_deck_position'
        || metaType === 'pick_judge_success_live' || metaType === 'replace_success_with_wr_live' || metaType === 'sbp6_live_wr_deck_position')
      && !!G._liveRoundPostSpectacleReady;
    // Spectators never receive full pending_prompt — live_judge must not softlock polls.
    // Do not special-case spectators beyond missing prompts: a broader live_judge clear
    // used to abort Performance mid-show when animating briefly dropped.
    const judgeWaitNoLocalPrompt = ph === 'live_judge' && !prType;
    if (!directorActive
        && !serverLiveShowInFlight
        && G._perfSpectacleActive && !G.animating && !G._liveSpectacleGateRunning
        && !G._liveRoundPlaybackActive
        && (mainStable || judgePickReady || judgeWaitNoLocalPrompt)) {
      TCG_DEBUG.warn('poll', 'clear stuck perfSpectacleActive', { phase: ph, prType, spectator: !!G.isSpectator });
      if (typeof perfCloseSpectacle === 'function') perfCloseSpectacle();
      else G._perfSpectacleActive = false;
      if (G._livePollHold && typeof releaseLivePolls === 'function') releaseLivePolls();
    }
    // Spectators stuck mid Win/Loss with playback held but no animation — release
    // only after the show finished or never started (postSpectacleReady / no defer).
    if (!directorActive
        && !serverLiveShowInFlight
        && !!G.isSpectator
        && ph === 'live_judge'
        && G._liveRoundPlaybackActive
        && !G.animating
        && !G._perfSpectacleActive
        && !G._liveSpectacleGateRunning
        && (G._liveRoundPostSpectacleReady || !G._deferredLiveState)) {
      TCG_DEBUG.warn('poll', 'clear stale spectator liveRoundPlaybackActive on live_judge');
      G._liveRoundPlaybackActive = false;
      G._liveRoundPostSpectacleReady = true;
      if (G._livePollHold && typeof releaseLivePolls === 'function') releaseLivePolls();
    }
    // live_show cursor: allow polls while spectacle chrome is up so stage advances
    // arrive. The runner itself holds polls via _liveShowRunnerActive / _livePollHold.
    const spectacleBlocksPoll = !!G._perfSpectacleActive && !serverLiveShowInFlight;
    return !!(G.animating || spectacleBlocksPoll || G._livePollHold
      || directorActive
      || G._replaySeekInFlight || G._replayForwardApply);
  };

  global.scheduleDeferredSyncPull = function scheduleDeferredSyncPull(delayMs = 400) {
    clearTimeout(G._syncPullTimer);
    if (!G.polling || (G.isTutorial && !G.tutorialLive) || !G.syncEnabled) return;
    G._syncPullTimer = setTimeout(async () => {
      G._syncPullTimer = null;
      if (!G.polling || (G.isTutorial && !G.tutorialLive)) return;
      if (pollPresentationBlocked()) {
        // Re-arm once presentation frees — avoid a tight timer spin while blocked.
        const spins = (G._syncPullBlockedSpins || 0) + 1;
        G._syncPullBlockedSpins = spins;
        scheduleDeferredSyncPull(Math.min(2000, 400 + spins * 100));
        return;
      }
      G._syncPullBlockedSpins = 0;
      await pullLatestState();
    }, delayMs);
  };

  global.resumePollingTick = function resumePollingTick(delayMs = 120) {
    if (!G.polling || (G.isTutorial && !G.tutorialLive)) return;
    clearTimeout(G.pollTimer);
    if (G.syncEnabled && G.syncTicket) scheduleDeferredSyncPull(Math.max(delayMs, 150));
    else G.pollTimer = setTimeout(doPollLegacy, delayMs);
  };

  function pollDelayAfterError(errorMsg) {
    if (typeof errorMsg === 'string' && /rate limit/i.test(errorMsg)) {
      G._pollRateLimitBackoff = Math.min((G._pollRateLimitBackoff || 0) + 1, 6);
      return Math.min(8000, 800 * (2 ** G._pollRateLimitBackoff));
    }
    G._pollRateLimitBackoff = 0;
    return null;
  }

  /** After a player action, keep CPU polling snappy until the server/AI advances. */
  global.markPollFastBurst = function markPollFastBurst(ms = 2500) {
    G._pollFastUntil = Date.now() + Math.max(0, ms | 0);
  };

  /**
   * CPU solo: slow polls while the local human clearly owns input; stay fast when
   * waiting on the server/CPU so AI replies remain snappy.
   */
  function cpuHumanOwnsInput() {
    if (!G.isCPU || G.isSpectator) return false;
    if (G._pollFastUntil && Date.now() < G._pollFastUntil) return false;
    const s = G.gameState;
    if (!s || s.status === 'finished') return true;
    const myId = G.playerId;
    if (!myId) return false;
    const pr = s.pending_prompt;
    if (pr) return pr.responder === myId;
    const ph = s.phase || '';
    if (ph === 'mulligan') {
      const done = s.mulligan_done || s.mulligan || {};
      return !done[myId];
    }
    if (ph === 'coin_flip' || ph === 'choose_first') {
      const ack = s.coin_ack || s.first_player_ack || {};
      if (Object.prototype.hasOwnProperty.call(ack, myId)) return !ack[myId];
      return true;
    }
    if ((ph === 'main_first' || ph === 'main_second'
        || ph === 'active_first' || ph === 'active_second')
        && s.active_player === myId) {
      return true;
    }
    if (ph === 'live_set') {
      const livePid = s.live_set_player || s.active_player;
      return livePid === myId && !s.live_ready?.[myId];
    }
    if (ph === 'live_judge') {
      // Judge prompts use pending_prompt; without one, prefer fast polls.
      return false;
    }
    return false;
  }

  function nextPollDelayMs(errorMsg) {
    const backoff = pollDelayAfterError(errorMsg);
    if (backoff != null) return backoff;
    if (!G.isCPU) return 600;
    return cpuHumanOwnsInput() ? 1100 : 280;
  }

  function getStateUrl(extraQs) {
    let url = `${gameApiBase()}?action=get_state&room_id=${encodeURIComponent(G.roomId)}`
      + `&token=${encodeURIComponent(G.token)}&seq=${encodeURIComponent(String(G.lastSeq ?? 0))}`
      + `&since_seq=${encodeURIComponent(String(G.lastSeq ?? 0))}&poll=0`;
    if (extraQs) url += extraQs;
    return url;
  }

  /** True when server says seq is unchanged (skip onState / full apply). */
  function isUnchangedStatePayload(d) {
    return !!(d && d.unchanged === true && !d.error);
  }

  function syncFallbackDelayMs() {
    const fails = G._syncFailCount || 0;
    const base = global.TCG_SYNC_FALLBACK_POLL_MS || 5000;
    const max = global.TCG_SYNC_FALLBACK_POLL_MAX_MS || 20000;
    return Math.min(max, base * Math.max(1, Math.pow(1.5, Math.min(fails, 6))));
  }

  /** Drop poll responses from a prior room/session (e.g. tutorial boot after CPU match). */
  function pollResponseStillCurrent(epoch, roomId) {
    return !!(G.polling && epoch === G._gameSessionEpoch && roomId && roomId === G.roomId);
  }

  global.stopPoll = function stopPoll() {
    G.polling = false;
    clearTimeout(G.pollTimer);
    clearTimeout(G.watchdogTimer);
    clearPvPWatchdog();
    stopSyncStream();
    // Do not clear G.apiOrigin here — mid-match stop/start must keep the room's
    // origin lock. endGameSession / resetLobby / tcgClearApiOriginLock clear it.
  };

  function gameApiBase() {
    return (typeof global.tcgGameApiUrl === 'function' ? global.tcgGameApiUrl() : (global.API || './api.php'));
  }

  async function tcgPresencePing() {
    if (!G.polling || !G.roomId || !G.token || (G.isTutorial && !G.tutorialLive)) return;
    try {
      await apiPost('ping', { room_id: G.roomId, token: G.token }, { silent: true });
    } catch (e) { /* best effort */ }
  }

  global.startSyncFallbackPoll = function startSyncFallbackPoll() {
    clearTimeout(G.syncFallbackTimer);
    if (!G.polling || (G.isTutorial && !G.tutorialLive)) return;
    G.syncFallbackTimer = setTimeout(async () => {
      G.syncFallbackTimer = null;
      if (!G.polling || (G.isTutorial && !G.tutorialLive)) return;
      // Use pollPresentationBlocked so live_show cursor sync is allowed while chrome is up.
      if (pollPresentationBlocked()) {
        startSyncFallbackPoll();
        return;
      }
      await pullLatestState();
      if (G.polling && G.syncEnabled === false) startSyncFallbackPoll();
      else if (G.polling && (G._syncFailCount || 0) >= TCG_SYNC_MAX_FAILS) startSyncFallbackPoll();
    }, syncFallbackDelayMs());
  };

  global.scheduleSyncReconnect = function scheduleSyncReconnect(ms) {
    clearTimeout(G._syncReconnectTimer);
    G._syncReconnectTimer = setTimeout(async () => {
      G._syncReconnectTimer = null;
      if (!G.polling || (G.isTutorial && !G.tutorialLive) || !G.roomId) return;
      if (!G.syncTicket) {
        try {
          const r = await apiPost('sync_ticket', { room_id: G.roomId, token: G.token }, { silent: true });
          captureSyncMeta(r);
        } catch (e) { /* retry below */ }
      }
      if (G.syncEnabled && G.syncTicket) openSyncStream();
      else if (G.polling) scheduleSyncReconnect(Math.min(12000, ms * 2));
    }, ms);
  };

  function onSyncStateEvent(data) {
    if (!G.polling || !G.roomId) return;
    const seq = parseInt(data?.seq, 10);
    if (!Number.isFinite(seq) || seq <= (G.lastSeq ?? 0)) return;
    TCG_DEBUG.log('sync', 'state event', { seq, last: G.lastSeq });
    if (pollPresentationBlocked()) {
      scheduleDeferredSyncPull(400);
      return;
    }
    void pullLatestState();
  }

  function syncStreamUrl(mode) {
    const qs = `room_id=${encodeURIComponent(G.roomId)}`
      + `&ticket=${encodeURIComponent(G.syncTicket)}&last_seq=${encodeURIComponent(String(G.lastSeq ?? 0))}`;
    if (mode === 'php') {
      return `${global.WRAPPED_API}?action=tcg_sync_stream&${qs}`;
    }
    if (mode === 'apache') {
      const base = (global.TCG_SYNC_STREAM_FALLBACK_URL || './sync-stream').replace(/\?.*$/, '');
      return `${base}?${qs}`;
    }
    const base = (global.TCG_SYNC_STREAM_URL || 'https://stream.loveliveradio.ca/tcg/sync/stream').replace(/\?.*$/, '');
    return `${base}?${qs}`;
  }

  global.openSyncStream = function openSyncStream() {
    stopSyncStream();
    if (!G.polling || (G.isTutorial && !G.tutorialLive) || !G.roomId || !G.syncTicket) return;
    const mode = global.TCG_SYNC_STREAM_MODE || 'vps';
    const url = syncStreamUrl(mode);
    TCG_DEBUG.log('sync', 'connect', { room: G.roomId, seq: G.lastSeq, via: mode });
    if (mode === 'vps') global._tcgSyncStats.streamDirect++;
    else global._tcgSyncStats.streamProxy++;
    const es = new EventSource(url);
    G.syncEventSource = es;
    es.addEventListener('ready', () => {
      G._syncFailCount = 0;
      clearTimeout(G.syncFallbackTimer);
      G.syncFallbackTimer = null;
    });
    es.addEventListener('state', (ev) => {
      try { onSyncStateEvent(JSON.parse(ev.data)); } catch (e) { /* ignore */ }
    });
    es.addEventListener('rotate', () => {
      es.close();
      G.syncEventSource = null;
      if (G.polling) scheduleSyncReconnect(280);
    });
    es.onerror = () => {
      es.close();
      G.syncEventSource = null;
      G._syncFailCount = (G._syncFailCount || 0) + 1;
      global._tcgSyncStats.streamFails++;
      // Escalate: VPS nginx → Hostinger Apache sync-stream → PHP proxy → poll=0
      if (mode === 'vps' && (G._syncFailCount || 0) === 2) {
        TCG_DEBUG.warn('sync', 'VPS stream failed; trying Apache sync-stream');
        global.TCG_SYNC_STREAM_MODE = 'apache';
        if (G.polling) scheduleSyncReconnect(400);
        return;
      }
      if (mode !== 'php' && (G._syncFailCount || 0) === 4) {
        TCG_DEBUG.warn('sync', 'trying PHP proxy');
        global.TCG_SYNC_STREAM_MODE = 'php';
        global.TCG_SYNC_USE_PHP_PROXY = true;
        if (G.polling) scheduleSyncReconnect(400);
        return;
      }
      if (G._syncFailCount >= TCG_SYNC_MAX_FAILS) {
        TCG_DEBUG.warn('sync', 'using poll=0 fallback');
        G.syncEnabled = false;
        startSyncFallbackPoll();
      }
      if (G.polling) scheduleSyncReconnect(Math.min(8000, 400 * Math.pow(2, G._syncFailCount)));
    };
    clearInterval(G.presenceTimer);
    void tcgPresencePing();
    G.presenceTimer = setInterval(() => void tcgPresencePing(), TCG_PRESENCE_PING_MS);
  };

  global.beginGameSync = async function beginGameSync() {
    global.TCG_SYNC_USE_PHP_PROXY = false;
    global.TCG_SYNC_STREAM_MODE = 'vps';
    if (!G.roomId || !G.token) {
      TCG_DEBUG.warn('sync', 'beginGameSync skipped — missing room/token');
      return;
    }
    if (!G.syncTicket) {
      try {
        const r = await apiPost('sync_ticket', { room_id: G.roomId, token: G.token }, { silent: true });
        captureSyncMeta(r);
      } catch (e) {
        TCG_DEBUG.warn('sync', 'sync_ticket failed', e);
      }
    }
    // CPU solo: no opponent to push — short poll=0 (never hold Hostinger long-poll workers).
    if (G.isCPU && !G.isSpectator) {
      G.syncEnabled = false;
      G.syncTicket = null;
      stopSyncStream();
      await pullLatestState();
      doPollLegacy();
      return;
    }
    if (G.syncEnabled && G.syncTicket) {
      openSyncStream();
      // Bootstrap: SSE only pushes seq bumps; missed pre-subscribe notifies need one fetch.
      await pullLatestState();
      return;
    }
    G.syncEnabled = false;
    ensurePresencePingTimer();
    doPollLegacy();
    return;
  };

  global.ensurePresencePingTimer = function ensurePresencePingTimer() {
    if (G.presenceTimer || !G.polling || (G.isTutorial && !G.tutorialLive) || !G.roomId || !G.token) return;
    void tcgPresencePing();
    G.presenceTimer = setInterval(() => void tcgPresencePing(), TCG_PRESENCE_PING_MS);
  };

  global.startPoll = function startPoll() {
    clearTimeout(G.pollTimer);
    clearTimeout(G.watchdogTimer);
    G.polling = true;
    G._syncFailCount = 0;
    if (G.isSpectator) saveSpectatorSession();
    else saveActiveGameSession();
    if ((G.isTutorial && !G.tutorialLive)) return;
    void beginGameSync();
  };

  /**
   * Short poll=0 loop — never use Hostinger long-poll (holds PHP up to 25s).
   * Used for CPU solo and when SSE sync is unavailable.
   */
  global.doPollLegacy = async function doPollLegacy() {
    if (!G.polling || (G.isTutorial && !G.tutorialLive)) return;
    // Must use pollPresentationBlocked — raw _perfSpectacleActive blocked CPU/spectator
    // from seeing live_show stage advances (Win/Loss freeze).
    if (pollPresentationBlocked()) {
      TCG_DEBUG.logOnce(
        'poll',
        `blocked:${G.animating}:${G._perfSpectacleActive}:${!!G.gameState?.live_show?.stage}`,
        'blocked (presentation)',
        {
          animating: G.animating,
          spectacle: G._perfSpectacleActive,
          liveShow: G.gameState?.live_show?.stage || null,
          runner: !!G._liveShowRunnerActive,
          pollHold: !!G._livePollHold,
        }
      );
      if (G.polling) G.pollTimer = setTimeout(doPollLegacy, 400);
      return;
    }
    ensurePollHoldReleased(G.gameState);
    const pollEpoch = G._gameSessionEpoch;
    const pollRoomId = G.roomId;
    let pollError = null;
    try {
      TCG_DEBUG.log('poll', 'fetch', { seq: G.lastSeq, room: pollRoomId, mode: 'poll0' });
      global._tcgSyncStats.getState++;
      const r = await fetch(getStateUrl());
      const d = await parseGameApiResponse(r);
      if (!pollResponseStillCurrent(pollEpoch, pollRoomId)) return;
      G._pollRateLimitBackoff = 0;
      if (isUnchangedStatePayload(d)) {
        if (Number.isFinite(d.seq)) G.lastSeq = Math.max(G.lastSeq ?? 0, d.seq);
      } else {
        if (G._pollFastUntil && (d.seq ?? 0) > (G.lastSeq ?? 0)) G._pollFastUntil = 0;
        onState(d);
      }
    } catch (e) {
      if (!pollResponseStillCurrent(pollEpoch, pollRoomId)) return;
      if (e && /room not found/i.test(String(e.message || ''))) {
        // Ranked rooms live on Hostinger — a VPS miss must recover, not resign.
        if (typeof global.abandonDeadMatchSession === 'function') {
          void global.abandonDeadMatchSession({ silent: false, forceResign: false });
          return;
        }
      }
      if (e && e.httpStatus >= 400) {
        if (handleSpectatorPollError(e.message)) return;
        reportApiError(e, { source: 'poll' });
        pollError = e.message;
      } else {
        TCG_DEBUG.warn('poll', 'fetch failed', e);
        reportApiError(createApiError(
          (global.LLTCG_I18N && typeof global.LLTCG_I18N.t === 'function')
            ? global.LLTCG_I18N.t('apiError.connectionFailed')
            : 'Could not reach the server. Try refreshing the page.',
          503
        ), { source: 'poll' });
        pollError = e && e.message ? e.message : 'fetch failed';
      }
    }
    if (G.polling) G.pollTimer = setTimeout(doPollLegacy, nextPollDelayMs(pollError));
  };

  /** Fetch state during live presentation without queueing behind G.animating. */
  global.pullSkillResolutionState = async function pullSkillResolutionState(opts = {}) {
    if (!G.roomId || !G.token) return G.gameState || null;
    if (G.isTutorial && !G.tutorialLive) return G.gameState || null;
    const pollEpoch = G._gameSessionEpoch;
    const pollRoomId = G.roomId;
    TCG_DEBUG.log('poll', 'pullSkillResolutionState', { seq: G.lastSeq, room: pollRoomId });
    try {
      global._tcgSyncStats.getState++;
      const r = await fetch(getStateUrl());
      const d = await parseGameApiResponse(r);
      if (!pollResponseStillCurrent(pollEpoch, pollRoomId)) return G.gameState || null;
      if (isUnchangedStatePayload(d)) {
        if (Number.isFinite(d.seq)) G.lastSeq = Math.max(G.lastSeq ?? 0, d.seq);
        return G.gameState || null;
      }
      if ((d.seq ?? 0) <= (G.lastSeq ?? 0)) return G.gameState || null;
      // Finished must go through onState/applyFinishedState — advancing lastSeq here
      // alone skips the win overlay and leaves later finished polls as "stale".
      if (d.status === 'finished') {
        if (typeof global.onState === 'function') {
          global.onState(d);
        } else if (typeof global.applyFinishedState === 'function') {
          void global.applyFinishedState(d, G.gameState);
        }
        return d;
      }
      G.lastSeq = d.seq;
      G.playerId = G.isSpectator
        ? ((G.spectatorViewAs === 'p1' || G.spectatorViewAs === 'p2') ? G.spectatorViewAs : (d.view_as || 'p1'))
        : (d.my_id || G.playerId);
      G.gameState = d;
      // Mid-spectacle Kurage / yell-retry: keep deferred in lockstep so presentLiveRound
      // does not resurrect the gate-entry pending_prompt after resolve.
      if (G._perfSpectacleActive || G._liveRoundPlaybackActive || G._livePollHold) {
        G._deferredLiveState = d;
      }
      if (typeof global.renderGame === 'function') {
        const skipPrompt = typeof global.shouldDeferPromptForLivePresentation === 'function'
          && global.shouldDeferPromptForLivePresentation(d, G.playerId);
        global.renderGame(d, { skipLog: true, skipPrompt });
      }
      if (typeof global.ensurePendingPromptSurfaced === 'function') {
        global.ensurePendingPromptSurfaced(d, G.playerId);
      }
      if (d.pending_prompt) {
        if (typeof global.syncPromptSubmitState === 'function') global.syncPromptSubmitState(d);
      } else if (typeof global.clearDeferredPromptState === 'function') {
        global.clearDeferredPromptState();
      }
      // CPU prompt resolve often bypasses applyStateUpdate — resume live_show acks.
      if (!d.pending_prompt
          && d.live_show?.stage
          && d.live_show.stage !== 'done'
          && !G._liveShowRunnerActive
          && typeof presentServerLiveShowStage === 'function') {
        const myId = G.playerId || d.my_id || 'p1';
        void presentServerLiveShowStage(null, d, myId);
      }
      return d;
    } catch (e) {
      if (!opts.silent) {
        TCG_DEBUG.warn('poll', 'pullSkillResolutionState failed', e);
      }
      return G.gameState || null;
    }
  };

  global.pullLatestState = async function pullLatestState(force, opts = {}) {
    if (!G.polling || (G.isTutorial && !G.tutorialLive) || !G.roomId || !G.token) return;
    if (!force && pollPresentationBlocked()) {
      if (G.syncEnabled && G.syncTicket) scheduleDeferredSyncPull(400);
      else resumePollingTick(400);
      return;
    }
    // Coalesce concurrent poll=0 fetches — overlapping callers used to stampede get_state.
    if (G._pullLatestInFlight) {
      await G._pullLatestInFlight;
      if (!force) return;
      // force: after the in-flight result, fall through for one more pull if still current.
      if (!G.polling || (G.isTutorial && !G.tutorialLive) || !G.roomId || !G.token) return;
    }
    const pollEpoch = G._gameSessionEpoch;
    const pollRoomId = G.roomId;
    TCG_DEBUG.log('poll', 'pullLatestState', { seq: G.lastSeq, force: !!force, room: pollRoomId });
    const run = (async () => {
      try {
        global._tcgSyncStats.getState++;
        const forceQs = force ? '&force=1' : '';
        const r = await fetch(getStateUrl(forceQs));
        const d = await parseGameApiResponse(r);
        if (!pollResponseStillCurrent(pollEpoch, pollRoomId)) return;
        if (isUnchangedStatePayload(d)) {
          if (Number.isFinite(d.seq)) G.lastSeq = Math.max(G.lastSeq ?? 0, d.seq);
          if (typeof tryFlushSpectacleRecovery === 'function') tryFlushSpectacleRecovery();
          return;
        }
        if (force && d.status === 'finished') {
          G._pendingStateQueue = (G._pendingStateQueue || []).filter(st => (st.seq ?? 0) > (d.seq ?? 0));
        }
        if (force && G.gameState && (d.seq ?? 0) <= (G.lastSeq ?? 0)) {
          TCG_DEBUG.logOnce('poll', `force-stale:${d.seq}`, 'skip force pull stale', { incoming: d.seq, last: G.lastSeq });
          if (typeof tryFlushSpectacleRecovery === 'function') tryFlushSpectacleRecovery();
          return;
        }
        if (G._pollFastUntil && (d.seq ?? 0) > (G.lastSeq ?? 0)) G._pollFastUntil = 0;
        if (d.end_reason === 'disconnect') global._tcgSyncStats.disconnectHints++;
        onState(d);
      } catch (e) {
        if (!pollResponseStillCurrent(pollEpoch, pollRoomId)) return;
        if (!opts.silent) {
          if (e && e.httpStatus >= 400) {
            if (!handleSpectatorPollError(e.message)) reportApiError(e, { source: 'pullLatestState' });
          } else {
            TCG_DEBUG.warn('poll', 'pullLatestState failed', e);
            reportApiError(createApiError(
              (global.LLTCG_I18N && typeof global.LLTCG_I18N.t === 'function')
                ? global.LLTCG_I18N.t('apiError.connectionFailed')
                : 'Could not reach the server. Try refreshing the page.',
              503
            ), { source: 'pullLatestState' });
          }
        } else {
          TCG_DEBUG.warn('poll', 'pullLatestState failed (silent)', e);
        }
      }
    })();
    G._pullLatestInFlight = run;
    try {
      await run;
    } finally {
      if (G._pullLatestInFlight === run) G._pullLatestInFlight = null;
    }
  };

})(window);
