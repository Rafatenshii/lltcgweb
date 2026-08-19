/**
 * Pure presentation-heal guards (browser + Node).
 * Never unstick Main / abort spectacle while Live Win/Loss (heart check / judge) is live.
 */
(function (root, factory) {
  const api = factory();
  if (typeof module === 'object' && module.exports) {
    module.exports = api;
  }
  if (root) root.LLTCG_PRESENTATION_GUARDS = api;
})(typeof globalThis !== 'undefined' ? globalThis : this, function () {
  'use strict';

  const LIVE_WIN_LOSS_PHASES = {
    live_start_effects: true,
    live_performance_first: true,
    live_performance_second: true,
    live_judge: true,
    live_success_effects: true,
  };

  const SETTLED_PLAY_PHASES = {
    main_first: true,
    main_second: true,
    active_first: true,
    active_second: true,
    live_set: true,
  };

  const MAIN_STABLE_PHASES = {
    main_first: true,
    main_second: true,
    active_first: true,
    active_second: true,
  };

  function liveShowInFlight(state) {
    const stage = state?.live_show?.stage;
    return !!(stage && stage !== 'done');
  }

  function isLiveWinLossPipelinePhase(ph) {
    return !!LIVE_WIN_LOSS_PHASES[ph];
  }

  function isSettledPlayPhase(ph) {
    return !!SETTLED_PLAY_PHASES[ph];
  }

  function isMainStablePhase(ph) {
    return !!MAIN_STABLE_PHASES[ph];
  }

  function liveWinLossPresentationActive(state, flags) {
    flags = flags || {};
    if (flags.heartCheckHold || flags.liveShowRunner) return true;
    if (liveShowInFlight(state)) return true;
    if (isLiveWinLossPipelinePhase(state?.phase)) return true;
    const stage = state?.live_show?.stage;
    if (stage === 'performance' || stage === 'outcomes' || stage === 'judge') return true;
    return false;
  }

  /**
   * Force-apply End Main / play catch-up behind leftover On Enter holds.
   * Must stay false for any Live Win/Loss / live_show cursor — aborting the
   * director there parks both players on "Checking hearts…" / Live Win/Loss Check.
   */
  function mayForceApplyHeldSnapshot(prev, next, flags) {
    flags = flags || {};
    if (!prev || !next) return false;
    if ((next.seq ?? 0) <= (prev.seq ?? 0)) return false;
    if (liveWinLossPresentationActive(prev, flags) || liveWinLossPresentationActive(next, flags)) {
      return false;
    }
    if (flags.perfSpectacle || flags.spectacleGate) return false;
    return isTurnAdvanceSnapshot(prev, next) || isMainBoardCatchupSnapshot(prev, next);
  }

  function isTurnAdvanceSnapshot(prev, next) {
    if (!prev || !next) return false;
    if ((next.seq ?? 0) <= (prev.seq ?? 0)) return false;
    if (liveShowInFlight(next)) return false;
    if (isLiveWinLossPipelinePhase(next.phase)) return false;
    if (prev.phase !== next.phase) return isSettledPlayPhase(prev.phase);
    if ((prev.phase === 'main_first' || prev.phase === 'main_second')
        && prev.active_player !== next.active_player) {
      return true;
    }
    if (prev.phase === 'live_set' && next.phase === 'live_set') {
      return prev.active_player !== next.active_player
        || (prev.live_set_player || prev.active_player) !== (next.live_set_player || next.active_player)
        || JSON.stringify(prev.live_ready || {}) !== JSON.stringify(next.live_ready || {});
    }
    return false;
  }

  function stageOccupancyKey(state, pid) {
    const stage = state?.players?.[pid]?.stage || {};
    return ['left', 'center', 'right'].map((slot) => stage[slot]?.instance_id || '').join(',');
  }

  function isMainBoardCatchupSnapshot(prev, next) {
    if (!prev || !next) return false;
    if ((next.seq ?? 0) <= (prev.seq ?? 0)) return false;
    if (liveShowInFlight(next) || liveShowInFlight(prev)) return false;
    if (prev.phase !== next.phase) return false;
    if (!isMainStablePhase(prev.phase)) return false;
    const pid = next.active_player || prev.active_player;
    if (!pid) return false;
    if (stageOccupancyKey(prev, pid) !== stageOccupancyKey(next, pid)) return true;
    const ph = prev.players?.[pid]?.hand?.length ?? -1;
    const nh = next.players?.[pid]?.hand?.length ?? -1;
    if (ph !== nh) return true;
    const pe = (prev.players?.[pid]?.energy_zone || []).length;
    const ne = (next.players?.[pid]?.energy_zone || []).length;
    return pe !== ne;
  }

  /** Poll heal that clears G.animating on Main. False during Win/Loss / live_show. */
  function mayUnstickStuckMainPresentation(state, flags, flightCount) {
    flags = flags || {};
    if (liveWinLossPresentationActive(state, flags)) return false;
    if (flags.perfSpectacle || flags.spectacleGate || flags.liveShowRunner) return false;
    if (!isMainStablePhase(state?.phase)) return false;
    if ((flightCount || 0) > 0) return false;
    return !!(flags.animating || flags.liveRoundPlayback || flags.logSync || flags.directorActive);
  }

  /**
   * Closing leftover Performance chrome. Never on live_judge without a pick, and
   * never while live_show is still presenting hearts / outcomes / judge.
   */
  function mayClearStuckPerfSpectacle(state, flags) {
    flags = flags || {};
    if (!flags.perfSpectacle) return false;
    if (flags.animating || flags.spectacleGate || flags.liveRoundPlayback || flags.directorActive) {
      return false;
    }
    if (liveShowInFlight(state)) return false;
    if (flags.heartCheckHold) return false;
    const ph = state?.phase;
    if (isLiveWinLossPipelinePhase(ph) && ph !== 'live_judge') return false;
    if (ph === 'live_judge') {
      const pr = state?.pending_prompt?.type
        || state?.pending_prompt_meta?.type
        || null;
      const pickReady = pr === 'pick_judge_success_live'
        || pr === 'replace_success_with_wr_live'
        || pr === 'sbp6_live_wr_deck_position';
      return !!(pickReady && flags.postSpectacleReady);
    }
    return isMainStablePhase(ph);
  }

  /** Dead live_show runner while the cursor still needs acks (Win/Loss freeze). */
  function shouldResumeLiveShowRunner(state, flags) {
    flags = flags || {};
    if (!state || flags.liveShowRunner || flags.isSpectator) return false;
    if (state.pending_prompt) return false;
    const stage = state.live_show?.stage;
    return !!(stage && stage !== 'done');
  }

  return {
    liveShowInFlight,
    isLiveWinLossPipelinePhase,
    isSettledPlayPhase,
    isMainStablePhase,
    liveWinLossPresentationActive,
    isTurnAdvanceSnapshot,
    isMainBoardCatchupSnapshot,
    mayForceApplyHeldSnapshot,
    mayUnstickStuckMainPresentation,
    mayClearStuckPerfSpectacle,
    shouldResumeLiveShowRunner,
  };
});
