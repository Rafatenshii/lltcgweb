/**
 * TCG state apply pipeline — onState gate, applyStateUpdate, pending queue.
 */
(function (global) {
  'use strict';

  function isReplayViewingState(s) {
    return !!(s && ((s.mode || '') === 'replay_view' || s.replay))
      || (typeof global.isReplayViewing === 'function' && global.isReplayViewing());
  }

  function replayStepDelta(prev, next) {
    const prevStep = prev?.replay?.step;
    const nextStep = next?.replay?.step ?? G.replayStep;
    if (typeof prevStep === 'number' && typeof nextStep === 'number') return nextStep - prevStep;
    return null;
  }

  function primeReplaySnapshotPresentationContext(s) {
    if (typeof clearPerfSpectacleDoneStorage === 'function') clearPerfSpectacleDoneStorage();
    if (typeof primePerfSpectacleDoneKeysFromLog === 'function') primePerfSpectacleDoneKeysFromLog(s);
    if (typeof primeReplayEmptyLivePresentedFromLog === 'function') {
      primeReplayEmptyLivePresentedFromLog(s);
    }
    if (typeof clearPhaseBannerShownKeys === 'function') clearPhaseBannerShownKeys();
    // Scrub lands mid-match: treat the current phase splash as already shown so the
    // next +1 step does not re-fire Main/Live splash for the same turn/phase.
    if (typeof markPhaseBannerShownForState === 'function') markPhaseBannerShownForState(s);
    G._announceBaseline = (s?.log || []).length;
    G._prevLogLen = (s?.log || []).length;
    G._lastPhase = s?.phase ?? null;
    G._deferPerfSpectaclePrev = null;
    G._livePostRevealBoard = null;
    G._liveStorageOutcomePending = false;
    G._liveStorageOutcomesPlayedKey = null;
    G._liveStorageOutcomesPlayedBluffKey = null;
    G._liveStorageOutcomesPlayedLiveKey = null;
    G._liveShowPlayedIids = null;
    if (typeof resetMovementLedger === 'function') resetMovementLedger();
    G._liveRoundPlaybackActive = false;
    G._liveRoundPostSpectacleReady = false;
    G._liveSpectacleGateRunning = false;
    G._postSpectacleSplashPause = false;
    if (typeof resyncGameLogFromState === 'function') resyncGameLogFromState(s);
  }

  function applyReplaySnapshot(s) {
    G._lastAppliedAt = Date.now();
    G._pendingStateQueue = [];
    G._promptSubmitKey = null;
    G._resolvePromptSentKey = null;
    G.lastSeq = s?.seq ?? G.lastSeq;
    G.playerId = G.isSpectator
      ? ((G.spectatorViewAs === 'p1' || G.spectatorViewAs === 'p2') ? G.spectatorViewAs : (s.view_as || 'p1'))
      : (s.my_id || G.playerId);
    if (typeof applyReplayStateFromPoll === 'function') applyReplayStateFromPoll(s);
    if (document.querySelector('.screen.active')?.id !== 'screen-game') showScr('game');
    if (typeof dismissAllGameplayOverlays === 'function') dismissAllGameplayOverlays();
    const recType = (typeof replayActionTypeAtStep === 'function')
      ? replayActionTypeAtStep(s?.replay?.step ?? G.replayStep)
      : '';
    const coinStep = typeof replayShouldShowCoinOverlay === 'function'
      ? replayShouldShowCoinOverlay(s?.replay?.step ?? G.replayStep, s)
      : (recType === 'ack_coin_flip'
        || (recType === 'choose_first_player' && s?.phase === 'coin_flip')
        || (!recType && s?.phase === 'coin_flip'));
    if (!coinStep && typeof resetCoinFlipPresentation === 'function') {
      resetCoinFlipPresentation();
    }
    primeReplaySnapshotPresentationContext(s);
    G.gameState = s;
    renderGame(s, { skipPrompt: true });
    G._presentationAborted = false;
    G._replayLastAppliedStep = s?.replay?.step ?? G.replayStep;
    if (typeof syncReplayPromptReadOnlyUi === 'function') syncReplayPromptReadOnlyUi(true);
    if (typeof syncReplayControlBar === 'function') syncReplayControlBar();
  }

  async function applyReplayStateUpdate(prev, s) {
    const delta = replayStepDelta(prev, s);
    const setupPhase = (prev?.phase === 'coin_flip' || prev?.phase === 'setup'
      || s?.phase === 'coin_flip' || s?.phase === 'setup');
    const scrub = !prev || G._replaySeekWasScrub || delta == null || delta !== 1 || setupPhase;
    if (typeof abortGameplayPresentation === 'function') {
      // Soft forward keeps coin/mull overlays and avoids wiping a banner only to
      // re-queue the same Main Phase splash on the next live→main mis-detect.
      // Coin/mulligan setup still snapshots so each step matches the recorded overlay.
      abortGameplayPresentation(scrub ? {} : { softReplayForward: true, skipAbortFlag: true });
    }
    if (scrub) {
      applyReplaySnapshot(s);
      G._replaySeekWasScrub = false;
      return;
    }
    G._replayForwardApply = true;
    G._presentationAborted = false;
    try {
      if (typeof holdLivePolls === 'function') holdLivePolls();
      await global.applyStateUpdate(s);
      // Replay steps are discrete — always land on the sought server board so the
      // next +1 does not treat a stale live_* board as prev (Main Phase loop).
      if (s && (G.gameState?.seq ?? 0) <= (s.seq ?? 0)) {
        const phaseDesync = G.gameState && G.gameState.phase !== s.phase;
        const turnDesync = G.gameState && G.gameState.active_player !== s.active_player;
        if (!G.gameState || phaseDesync || turnDesync || (G.gameState.seq ?? 0) < (s.seq ?? 0)) {
          G.gameState = s;
          renderGame(s, { skipPrompt: true });
        }
      }
    } finally {
      G._replayForwardApply = false;
      G._replayLastAppliedStep = s?.replay?.step ?? G.replayStep;
      G._replaySeekWasScrub = false;
      if (typeof releaseLivePolls === 'function') releaseLivePolls();
      if (typeof syncReplayPromptReadOnlyUi === 'function') syncReplayPromptReadOnlyUi(true);
      if (typeof syncReplayControlBar === 'function') syncReplayControlBar();
    }
  }

  function presentationFlagsFromG() {
    return {
      animating: !!G.animating,
      perfSpectacle: !!G._perfSpectacleActive,
      heartCheckHold: !!G._perfHeartCheckHold,
      spectacleGate: !!G._liveSpectacleGateRunning,
      liveRoundPlayback: !!G._liveRoundPlaybackActive,
      liveShowRunner: !!G._liveShowRunnerActive,
      logSync: !!G._logSyncInFlight,
      directorActive: typeof LiveRoundDirector !== 'undefined' && !!LiveRoundDirector.active,
      postSpectacleReady: !!G._liveRoundPostSpectacleReady,
      isSpectator: !!G.isSpectator,
    };
  }

  function applyTurnAdvanceNow(s) {
    TCG_DEBUG.warn('state', 'apply turn-advance despite presentation hold', {
      seq: s.seq,
      from: G.gameState?.phase,
      to: s.phase,
      active: s.active_player,
    });
    G._pendingStateQueue = (G._pendingStateQueue || []).filter(st => (st.seq ?? 0) > (s.seq ?? 0));
    if (typeof LiveRoundDirector !== 'undefined' && LiveRoundDirector.active) {
      LiveRoundDirector.abort('turn-advance');
    }
    // Force-apply must not leave an old picker open while the new board paints.
    if (typeof global.dismissLocalPromptChrome === 'function') {
      global.dismissLocalPromptChrome('turn-advance');
    } else {
      G._promptSubmitKey = null;
      G._resolvePromptSentKey = null;
      G._lastResolvedPromptKey = null;
      G._lastSurfacedPromptKey = null;
      if (typeof clearDeferredPromptState === 'function') {
        clearDeferredPromptState({ skipBannerRefresh: true });
      }
    }
    G.animating = false;
    G._liveRoundPlaybackActive = false;
    G._liveSpectacleGateRunning = false;
    G._liveShowRunnerActive = false;
    G._presentationAborted = false;
    if (typeof dropStaleLiveRoundPlaybackBoards === 'function') {
      dropStaleLiveRoundPlaybackBoards('turn-advance');
    }
    G._animHideIids = null;
    G._logSyncInFlight = false;
    if (typeof clearHandArrivingFlags === 'function') clearHandArrivingFlags();
    if (G._livePollHold && typeof releaseLivePolls === 'function') releaseLivePolls();
    applyStateUpdate(s);
  }

  global.onState = function onState(s) {
    if (G.isTutorial && !G.tutorialLive) return;
    // Keep reconnect credentials fresh while the match is live, and through the
    // finished win/loss overlay so refresh can restore that screen.
    if (!G.isSpectator && G.roomId && G.token && s
        && typeof global.saveActiveGameSession === 'function') {
      const now = Date.now();
      if (!G._lastActiveGameSaveAt || now - G._lastActiveGameSaveAt > 5000) {
        G._lastActiveGameSaveAt = now;
        try { global.saveActiveGameSession(); } catch (e) { /* ignore */ }
      }
    }
    if (isReplayViewingState(s)) {
      if (typeof G._replaySeekAppliedStep === 'number' && s.replay) {
        const pollStep = s.replay.step ?? 0;
        if (pollStep < G._replaySeekAppliedStep) {
          TCG_DEBUG.logOnce('state', `replay-stale:${pollStep}`, 'skip stale replay poll', {
            pollStep,
            applied: G._replaySeekAppliedStep,
          });
          return;
        }
      }
      if (G._replaySeekInFlight && s.replay) {
        const pollStep = s.replay.step ?? 0;
        const target = G._replaySeekTarget ?? G.replayStep;
        if (pollStep < target) {
          TCG_DEBUG.logOnce('state', `replay-inflight:${pollStep}`, 'skip replay poll during seek', {
            pollStep,
            target,
          });
          return;
        }
      }
      TCG_DEBUG.log('state', 'apply replay snapshot', TCG_DEBUG.snap(s));
      applyStateUpdate(s);
      return;
    }
    if(s.seq<=G.lastSeq && G.gameState) {
      const delayedSpec = !!(G.isSpectator && (s.spectate_stream_delayed || s.spectate_stream_waiting));
      const sameBoard = (s.seq === (G.gameState.seq ?? 0))
        && !!s.spectate_stream_waiting === !!G.gameState.spectate_stream_waiting;
      if (!delayedSpec || sameBoard) {
        TCG_DEBUG.logOnce('state', `stale:${s.seq}`, 'skip stale', { incoming: s.seq, last: G.lastSeq });
        if (typeof tryFlushSpectacleRecovery === 'function') tryFlushSpectacleRecovery();
        return;
      }
    }
    // Spectators use the same queue/apply path as players so LIVE reveal + Performance
    // spectacle can run. Do not force-clear animating / poll hold here.
    if (G.isSpectator) clearPvPWatchdog();
    if (s.status === 'finished') {
      clearPvPWatchdog();
      // Persist signed-in players' Recent Matches entry as soon as the finished
      // server state arrives. Final spectacle or win-overlay presentation must not
      // be able to prevent replay autosave.
      if (!G.isSpectator && typeof global.autosaveFinishedReplay === 'function') {
        void global.autosaveFinishedReplay({ toast: false });
      }
      // Rematch votes bump seq while both sides stay on finished — sync UI without
      // replaying triumph / re-opening the win flow from scratch.
      if (G.rematchWaiting && G.gameState?.status === 'finished') {
        if ((s.seq ?? 0) < (G.lastSeq ?? 0)) return;
        G.lastSeq = s.seq;
        G.gameState = s;
        if (typeof global.syncWinRematchUi === 'function') global.syncWinRematchUi(s);
        return;
      }
      TCG_DEBUG.log('state', 'apply finished (immediate)', TCG_DEBUG.snap(s));
      void applyStateUpdate(s);
      return;
    }
    if (G.rematchWaiting && s.status !== 'finished') {
      G.rematchWaiting = false;
      G.rematchRequested = false;
      global.el?.('overlay-win')?.classList.remove('open');
    }
    const guards = global.LLTCG_PRESENTATION_GUARDS;
    const holdFlags = presentationFlagsFromG();
    if (guards && guards.mayForceApplyHeldSnapshot(G.gameState, s, holdFlags)
        && (G.animating || G._liveRoundPlaybackActive || G._logSyncInFlight
          || holdFlags.directorActive
          || shouldHoldStateForLocalPrompt(s))) {
      applyTurnAdvanceNow(s);
      return;
    }
    if (shouldHoldStateForLocalPrompt(s)) {
      TCG_DEBUG.log('state', 'queue (local prompt open)', { seq: s.seq, phase: s.phase, q: (G._pendingStateQueue?.length || 0) + 1 });
      if (G.tutorialLive && typeof global.TutorialInteractive?.onIncomingState === 'function') {
        global.TutorialInteractive.onIncomingState(s, G.gameState);
      }
      enqueuePendingState(s);
      return;
    }
    if (G.animating || G._perfSpectacleActive || G._liveSpectacleGateRunning || G._liveRoundPlaybackActive
        || (typeof LiveRoundDirector !== 'undefined' && LiveRoundDirector.active)) {
      // Authoritative live_show cursor advances must not sit behind open chrome —
      // that freezes Win/Loss for players waiting on an opponent ack, and spectators
      // who never ack but must still observe stage_seq changes.
      const incomingShow = s?.live_show || null;
      const currentShow = G.gameState?.live_show || null;
      const liveShowCursorAdvanced = !!(
        incomingShow
        && (
          !currentShow
          || (incomingShow.stage || '') !== (currentShow.stage || '')
          || (incomingShow.stage_seq ?? 0) !== (currentShow.stage_seq ?? 0)
          || incomingShow.stage === 'done'
        )
      ) || (!!currentShow && currentShow.stage !== 'done' && !incomingShow);
      if (liveShowCursorAdvanced && !G._liveShowRunnerActive) {
        TCG_DEBUG.log('state', 'apply live_show advance during spectacle', {
          seq: s.seq,
          from: currentShow?.stage,
          to: incomingShow?.stage,
          stageSeq: incomingShow?.stage_seq,
        });
        applyStateUpdate(s);
        return;
      }
      TCG_DEBUG.log('state', 'queue (animating)', { seq: s.seq, phase: s.phase, q: (G._pendingStateQueue?.length || 0) + 1 });
      if (G.tutorialLive && typeof global.TutorialInteractive?.onIncomingState === 'function') {
        global.TutorialInteractive.onIncomingState(s, G.gameState);
      }
      enqueuePendingState(s);
      return;
    }
    TCG_DEBUG.log('state', 'apply', TCG_DEBUG.snap(s));
    applyStateUpdate(s);
  };

  global.applyFinishedState = async function applyFinishedState(s, prev) {
    // Drop Success-Live pick / skill overlays immediately so a late resurface cannot
    // cover the win screen after a 3rd Success (softlock until refresh).
    if (typeof global.closeM === 'function') {
      global.closeM('overlay-pick');
      global.closeM('overlay-prompt');
      global.closeM('overlay-hand-pick');
    }
    G._livePostRevealBoard = null;
    G._liveStorageOutcomePending = false;
    G._liveStorageOutcomesPlayedKey = null;
    G._liveStorageOutcomesPlayedBluffKey = null;
    G._liveStorageOutcomesPlayedLiveKey = null;
    if (typeof resetMovementLedger === 'function') resetMovementLedger();
    G._promptSubmitKey = null;
    G._resolvePromptSentKey = null;
    G._lastResolvedPromptKey = null;
    G._lastSurfacedPromptKey = null;
    if (typeof global.clearDeferredPromptState === 'function') {
      global.clearDeferredPromptState({ skipBannerRefresh: true });
    }
    if (G.isSpectator) {
      G.lastSeq = s.seq;
      if (typeof alignSpectatorStageBoard === 'function') {
        s = alignSpectatorStageBoard(s);
      }
      const cur = document.querySelector('.screen.active')?.id;
      if (cur !== 'screen-game') showScr('game');

      const prevLogLen = prev?.log?.length || 0;
      const newEntries = (s.log || []).slice(prevLogLen);
      const resigned = typeof gameResignedBy === 'function' && !!gameResignedBy(s);
      let playedFinalLiveRound = false;
      if (!resigned && typeof maybePlayFinalLiveRoundPresentation === 'function') {
        playedFinalLiveRound = await maybePlayFinalLiveRoundPresentation(prev, s, newEntries);
      }
      if (typeof waitForLivePresentationIdle === 'function') {
        const idle = await waitForLivePresentationIdle(12000);
        if (!idle) {
          if (typeof abortGameplayPresentation === 'function') {
            abortGameplayPresentation({ skipAbortFlag: true });
          }
          G.animating = false;
          G._perfSpectacleActive = false;
          G._liveRoundPlaybackActive = false;
          G._liveSpectacleGateRunning = false;
        }
      }

      abortGameplayPresentation();
      stopPoll();
      // Drop resume session so refresh does not rejoin a finished room; keep G.token for leave.
      if (typeof clearSpectatorSession === 'function') clearSpectatorSession();

      if (typeof shouldPlaySuccessLiveTriumph === 'function' && shouldPlaySuccessLiveTriumph(prev, s)) {
        G.animating = true;
        try {
          G.gameState = s;
          renderGame(s, { skipLog: true });
          await playSuccessLiveTriumphCelebration(s, G.playerId);
        } finally {
          G.animating = false;
        }
      } else if (!playedFinalLiveRound) {
        G.gameState = s;
        renderGame(s, { skipLog: true });
      }

      if (typeof catchUpGameLog === 'function') catchUpGameLog(s, prev);
      if (prev && !resigned && typeof flushPostLiveLogBanners === 'function') {
        flushPostLiveLogBanners(prev, s, G.playerId);
      }
      showWin(s);
      return;
    }
    G.lastSeq = s.seq;
    G.playerId = s.my_id || G.playerId;
    const cur = document.querySelector('.screen.active')?.id;
    if (cur !== 'screen-game') showScr('game');

    const prevLogLen = prev?.log?.length || 0;
    const newEntries = (s.log || []).slice(prevLogLen);
    const resigned = !!gameResignedBy(s);
    let playedFinalLiveRound = false;
    if (!resigned) {
      playedFinalLiveRound = await maybePlayFinalLiveRoundPresentation(prev, s, newEntries);
    }
    // Cap wait so a stuck spectacle cannot delay the win/loss screen until refresh.
    if (typeof waitForLivePresentationIdle === 'function') {
      const idle = await waitForLivePresentationIdle(12000);
      if (!idle) {
        TCG_DEBUG.warn('state', 'finished: presentation still busy — forcing win overlay');
        if (typeof abortGameplayPresentation === 'function') {
          abortGameplayPresentation({ skipAbortFlag: true });
        }
        G.animating = false;
        G._perfSpectacleActive = false;
        G._liveRoundPlaybackActive = false;
        G._liveSpectacleGateRunning = false;
      }
    }

    abortGameplayPresentation();
    const rematchSettings = typeof global.captureRematchSettings === 'function'
      ? global.captureRematchSettings(s) : null;
    const rematchEligible = typeof global.isFriendPvpRematchEligible === 'function'
      && global.isFriendPvpRematchEligible(rematchSettings);
    if (rematchEligible) {
      G.rematchWaiting = true;
      resumePollingTick(400);
    } else {
      stopPoll();
    }

    if (shouldPlaySuccessLiveTriumph(prev, s)) {
      G.animating = true;
      try {
        G.gameState = s;
        renderGame(s, { skipLog: true });
        await playSuccessLiveTriumphCelebration(s, G.playerId);
      } finally {
        G.animating = false;
      }
    } else if (!playedFinalLiveRound) {
      G.gameState = s;
      renderGame(s, { skipLog: true });
    }

    catchUpGameLog(s, prev);
    if (prev && !resigned) flushPostLiveLogBanners(prev, s, G.playerId);
    showWin(s);
    if (G.rematchWaiting && typeof global.syncWinRematchUi === 'function') {
      global.syncWinRematchUi(s);
    }
  };

  /** Apply one server state snapshot: spectacle gate, log anims, or direct render. */
  global.applyStateUpdate = async function applyStateUpdate(s) {
    if (G.isTutorial && !G.tutorialLive) return;
    if (isReplayViewingState(s) && !G._replayForwardApply) {
      await applyReplayStateUpdate(G.gameState, s);
      return;
    }
    const replayForward = !!G._replayForwardApply;
    G._lastAppliedAt = Date.now();
    G._presentationAborted = false;
    if (!replayForward) syncPromptSubmitState(s);
    if (!s?.pending_prompt) clearDeferredPromptState();
    clearStaleOpponentSkillWaitIfResolved(s, G.playerId);
    const prev = G.gameState;
    restorePerfSpectacleDoneKey();
    markSpectacleDoneFromState(s, prev);
    if (typeof settledMainBlocksLiveSpectacle === 'function'
        && settledMainBlocksLiveSpectacle(prev, s)
        && typeof dropStaleLiveRoundPlaybackBoards === 'function') {
      dropStaleLiveRoundPlaybackBoards('applyState: settled Main (On Enter / baton / play)');
    }
    if (prev && !isLiveSetPhase(prev.phase) && isLiveSetPhase(s.phase)) {
      G._perfYellRevealCache = null;
      G._deferPerfSpectaclePrev = null;
      G._liveSetStorageBaseline = null;
      G._livePostRevealBoard = null;
      G._perfSplashShownForTurn = null;
      G._spectacleRecoveryPending = null;
      G._spectacleRecoveryAttempts = 0;
      // Allow this turn's pre-Performance flip even if Main polls sealed reveal early.
      if (s.turn != null) G._liveStorageRevealDoneTurns?.delete(s.turn);
    }
    syncDeferredHandDrawMask(prev, s, G.playerId);
    syncLiveSuccessPresentationDefer(prev, s);
    if (isLiveSetPhase(s.phase)) refreshLiveSetStorageBaseline(s);
    const oppId = G.playerId === 'p1' ? 'p2' : 'p1';
    const truncated = prev && logWasTruncated(prev, s);
    if (truncated) resyncGameLogFromState(s);
    const prevLogLen = truncated ? 0 : (prev?.log?.length || 0);
    const newEntries = (s.log || []).slice(prevLogLen);
    const hasAnimSteps = newEntries.some(e => e.anim?.length);
    ensurePerfSpectacleNotStaleDone(prev, s);
    maybeToastWrFizzleFromLog(newEntries);

    G.lastSeq = s.seq;
    G.playerId = G.isSpectator
      ? ((G.spectatorViewAs === 'p1' || G.spectatorViewAs === 'p2') ? G.spectatorViewAs : (s.view_as || 'p1'))
      : (s.my_id || G.playerId);
    if (G.isSpectator && typeof alignSpectatorStageBoard === 'function') {
      s = alignSpectatorStageBoard(s);
    }
    maybeResetBatonTouchToggle(prev, s);
    applyReplayStateFromPoll(s);
    stashPerfYellRevealCache(s);
    if (s.status === 'waiting') {
      G.gameState = s;
      updateWaitingTimerInfo(s.phase_timer_cfg);
      showScr('waiting');
      return;
    }
    if (s.status === 'finished') {
      await applyFinishedState(s, prev);
      return;
    }
    const cur = document.querySelector('.screen.active')?.id;
    if (cur !== 'screen-game') showScr('game');

    // Advance tutorial dialogue from the incoming state before board animations so
    // players can start reading the next tip while presentation is still playing.
    if (!replayForward && G.tutorialLive
        && typeof global.TutorialInteractive?.onIncomingState === 'function') {
      global.TutorialInteractive.onIncomingState(s, prev);
    }

    // Spectators previously returned here with a bare renderGame — that skipped LIVE
    // storage reveal and Performance spectacle. Fall through the same presentation path.

    if (G._announceBaseline == null && isActiveGameplay(s)) {
      G._announceBaseline = prev?.log?.length ?? (s.log || []).length;
      if (!prev) G._lastPhase = s.phase;
    }

    const livePrev = (typeof effectiveLiveRoundPrev === 'function'
      ? effectiveLiveRoundPrev(prev, s)
      : null) ?? effectiveEmptyLiveRoundPrev(prev, s);

    // Member-only / empty LIVE rounds must present the "No Lives played" splash
    // before live_show or Performance gates can soft-lock or skip them.
    const emptyRoundPrev = livePrev || prev;
    const emptyRoundDue = !liveSetPlacementInProgress(s)
      && !(s.live_show?.stage && s.live_show.stage !== 'done')
      && (
        shouldPresentEmptyLiveRound(emptyRoundPrev, s)
        || shouldPresentEmptyLiveRound(prev, s)
        || (
          newEntries.some(e => e.msg === 'No Lives played this turn.')
          && !emptyLiveRoundAlreadyPresented(
            emptyLiveRoundShowTurn(prev, s) ?? emptyLiveRoundShowTurn(emptyRoundPrev, s) ?? s.turn
          )
        )
      );
    if (emptyRoundDue && typeof presentLiveRound === 'function') {
      // Keep lastSeq at the pre-presentation board until empty-round playback commits.
      // Bumping early made get_state return unchanged while UI stayed on live_set.
      G.lastSeq = prev?.seq ?? G.lastSeq ?? 0;
      G.animating = true;
      try {
        await presentLiveRound(emptyRoundPrev, s, G.playerId, {
          newEntries,
          forceEmptyRound: true,
        });
        G.lastSeq = Math.max(G.lastSeq ?? 0, s.seq ?? 0);
        if ((G.gameState?.seq ?? 0) < (s.seq ?? 0)) {
          G.gameState = s;
          if (typeof renderGame === 'function') {
            renderGame(s, { skipLog: true });
          }
        }
        if (!replayForward && G.isCPU && !G.animating && !(G.tutorialLive && G.tutorialHoldCpu)) {
          doCPU(G.gameState || s);
          armWatchdog(G.gameState || s);
        }
      } finally {
        G._animHideIids = null;
        clearHandArrivingFlags();
        G.animating = false;
        G.lastSeq = Math.max(G.lastSeq ?? 0, s.seq ?? 0);
        releaseLivePollsAndFlush();
      }
      return;
    }

    const pendingSpectacleTurn = detectPendingLiveSpectacleTurn(livePrev, s)
      ?? detectPendingLiveSpectacleTurn(prev, s);
    const spectacleGateActive = pendingSpectacleTurn != null && !liveSpectacleDoneForTurn(pendingSpectacleTurn);

    const skipPromptForLive = typeof shouldDeferPromptForLivePresentation === 'function'
      && shouldDeferPromptForLivePresentation(s, G.playerId);
    const commitServerBoardToUi = (board) => {
      if (!board) return;
      G.gameState = board;
      renderGame(board, {
        skipLog: true,
        skipPrompt: skipPromptForLive || replayForward,
      });
    };
    const uiBehindServer = (board = G.gameState) => {
      if (!board) return true;
      if ((board.seq ?? 0) < (s.seq ?? 0)) return true;
      if (board.phase !== s.phase) return true;
      if (board.active_player !== s.active_player) return true;
      const br = board.live_ready || null;
      const sr = s.live_ready || null;
      if (!!br !== !!sr) return true;
      if (br && sr && (br.p1 !== sr.p1 || br.p2 !== sr.p2)) return true;
      return false;
    };

    if (s.live_show?.stage && s.live_show.stage !== 'done'
        && typeof presentServerLiveShowStage === 'function') {
      // Soft-update only while the stage runner owns the cursor — avoids re-entry.
      if (G._liveShowRunnerActive) {
        G.lastSeq = Math.max(G.lastSeq ?? 0, s.seq ?? 0);
        G.gameState = s;
        // Chained Live Start skills arrive while the runner owns the cursor —
        // paint + surface the next prompt (soft-assign alone softlocks until refresh).
        if (!replayForward && typeof renderGame === 'function') {
          renderGame(s, {
            skipLog: true,
            skipPrompt: skipPromptForLive || replayForward,
          });
        }
        if (!replayForward && s.pending_prompt?.responder === G.playerId
            && typeof ensurePendingPromptSurfaced === 'function') {
          ensurePendingPromptSurfaced(s, G.playerId);
        }
        return;
      }
      // Paint only the board-safe state first. Judge chrome remains gated by
      // live_show.stage while the director seeks/resumes the persisted beat.
      if (typeof holdLiveShowStorageBeforeOutcomePaint === 'function') {
        holdLiveShowStorageBeforeOutcomePaint(prev, s);
      }
      commitServerBoardToUi(s);
      await presentServerLiveShowStage(prev, s, G.playerId);
      const live = G.gameState || s;
      if (live.pending_prompt?.responder === G.playerId
          && typeof ensurePendingPromptSurfaced === 'function') {
        ensurePendingPromptSurfaced(live, G.playerId);
      }
      if (!replayForward && G.isCPU && !G.animating && !(G.tutorialLive && G.tutorialHoldCpu)) {
        doCPU(live);
        armWatchdog(live);
      }
      // Always stop here — the runner chains stages and seals the turn so the
      // legacy presentLiveRound path cannot start a second broken show.
      return;
    }

    // Finished live_show cursor: seal so legacy recovery cannot re-open spectacle
    // with lives already moved to Success / Waiting Room.
    if (s.live_show?.stage === 'done' && typeof sealLiveShowSpectacleTurn === 'function') {
      if (G._perfSpectacleActive && typeof perfCloseSpectacle === 'function') perfCloseSpectacle();
      sealLiveShowSpectacleTurn(s, prev);
      if (typeof playLiveShowPostJudgeStorageExits === 'function') {
        await playLiveShowPostJudgeStorageExits(prev, s, G.playerId);
      }
      commitServerBoardToUi(s);
      const live = G.gameState || s;
      if (!replayForward && live.pending_prompt?.responder === G.playerId
          && typeof ensurePendingPromptSurfaced === 'function') {
        ensurePendingPromptSurfaced(live, G.playerId);
      }
      if (!replayForward && G.isCPU && !G.animating && !(G.tutorialLive && G.tutorialHoldCpu)) {
        doCPU(live);
        armWatchdog(live);
      }
      return;
    } else if (G._perfSpectacleActive
        && prev?.live_show?.stage
        && prev.live_show.stage !== 'done'
        && !s.live_show?.stage) {
      // Server often unsets live_show after Success (esp. match-ending 3rd Success)
      // instead of leaving stage=done — close observer chrome stuck from the last beat.
      if (typeof perfCloseSpectacle === 'function') perfCloseSpectacle();
      if (typeof sealLiveShowSpectacleTurn === 'function') sealLiveShowSpectacleTurn(s, prev);
      if (typeof playLiveShowPostJudgeStorageExits === 'function') {
        await playLiveShowPostJudgeStorageExits(prev, s, G.playerId);
      }
      commitServerBoardToUi(s);
      const live = G.gameState || s;
      if (!replayForward && live.pending_prompt?.responder === G.playerId
          && typeof ensurePendingPromptSurfaced === 'function') {
        ensurePendingPromptSurfaced(live, G.playerId);
      }
      if (!replayForward && G.isCPU && !G.animating && !(G.tutorialLive && G.tutorialHoldCpu)) {
        doCPU(live);
        armWatchdog(live);
      }
      return;
    }

    if (spectacleGateActive && (G.gameState?.seq ?? 0) < (s.seq ?? 0)) {
      // Soft-merge only while Live Start skill waits own the round — not for every
      // stuck _liveRoundPlaybackActive (that caused ghost Performance loops).
      const softMergeLiveStart = !!(G._awaitingLiveStartPrompts
        || (G._liveRoundPlaybackActive
          && G.gameState?.phase === 'live_start_effects'
          && typeof liveStartPromptNeedsWait === 'function'
          && liveStartPromptNeedsWait(G.gameState, G.playerId)));
      if (softMergeLiveStart) {
        // Keep reveal/spectacle pipeline alive; presentLiveRound wait observes G.gameState.
        TCG_DEBUG.log('state', 'soft-merge during Live Start wait', {
          fromSeq: G.gameState?.seq, toSeq: s.seq, phase: s.phase,
        });
        G.lastSeq = Math.max(G.lastSeq ?? 0, s.seq ?? 0);
        G.gameState = s;
        renderGame(s, {
          skipLog: true,
          skipPrompt: skipPromptForLive || replayForward,
        });
        if (!replayForward && typeof ensurePendingPromptSurfaced === 'function') {
          ensurePendingPromptSurfaced(s, G.playerId);
        }
        // Soft-merge returns before the applyStateUpdate doCPU tail — keep CPU Live Start moving.
        if (!replayForward && G.isCPU && !(G.tutorialLive && G.tutorialHoldCpu)) {
          const cpuId = typeof cpuOpponentId === 'function' ? cpuOpponentId() : 'p2';
          if (s.pending_prompt?.responder === cpuId && typeof scheduleCpuResolvePrompt === 'function') {
            scheduleCpuResolvePrompt(s, s.players?.[cpuId]);
            if (typeof armCpuPromptHangWatch === 'function') armCpuPromptHangWatch(s);
          } else if (typeof doCPU === 'function') {
            doCPU(s);
            if (typeof armWatchdog === 'function') armWatchdog(s);
          }
        }
        // Do not re-enter the gate / Main paint path while presentLiveRound owns the show.
        if (G._liveSpectacleGateRunning || G._liveRoundPlaybackActive) return;
      } else if (typeof abortStuckLiveRoundPlayback === 'function') {
        abortStuckLiveRoundPlayback('behind server during spectacle');
        // Must render — committing seq without paint leaves LIVE chrome stuck until refresh.
        commitServerBoardToUi(s);
      }
    }

    if (await runLiveSpectacleGate(prev, s, newEntries, G.playerId)) {
      if (replayForward) commitServerBoardToUi(s);
      const live = G.gameState || s;
      if (!replayForward && live.pending_prompt?.responder === G.playerId
          && (live.pending_prompt?.type === 'pick_judge_success_live'
              || live.phase === 'live_success_effects'
              || (typeof isPostLiveSkillPrompt === 'function' && isPostLiveSkillPrompt(live)))) {
        ensurePendingPromptSurfaced(live, G.playerId);
      }
      if (!replayForward && G.isCPU && !G.animating && !(G.tutorialLive && G.tutorialHoldCpu)) {
        doCPU(live);
        armWatchdog(live);
      }
      return;
    }

    if (spectacleGateActive && !liveSpectacleDoneForTurn(pendingSpectacleTurn)) {
      const stuckOnLiveSet = typeof isLiveSetPhase === 'function'
        && isLiveSetPhase(G.gameState?.phase) && !isLiveSetPhase(s.phase);
      if (stuckOnLiveSet && typeof abortStuckLiveRoundPlayback === 'function') {
        abortStuckLiveRoundPlayback('stale live_set after server advance');
        commitServerBoardToUi(s);
      } else if (replayForward) {
        commitServerBoardToUi(s);
      } else if (!G.animating && !G._liveSpectacleGateRunning && !G._liveRoundPlaybackActive
          && uiBehindServer()) {
        commitServerBoardToUi(s);
      }
      // Replay: once the sought board is already past LIVE pipeline, do not soft-lock
      // every main-phase step behind a stale spectacle gate (caused Main Phase splash loops).
      const replayPastLiveGate = replayForward
        && typeof isLiveSetPhase === 'function'
        && !isLiveSetPhase(s.phase)
        && (typeof isLiveSpectaclePipelinePhase !== 'function' || !isLiveSpectaclePipelinePhase(s.phase));
      if (!replayPastLiveGate) {
        if (!replayForward && !G.animating && !G._liveSpectacleGateRunning
            && typeof shouldRecoverMissedLiveSpectacle === 'function'
            && shouldRecoverMissedLiveSpectacle(prev, s)) {
          G.animating = true;
          try {
            await runLiveSpectacleGate(prev, s, newEntries, G.playerId);
          } finally {
            G.animating = false;
            releaseLivePollsAndFlush();
          }
          const live = G.gameState || s;
          if (live.pending_prompt?.responder === G.playerId) {
            ensurePendingPromptSurfaced(live, G.playerId);
          }
          if (!replayForward && G.isCPU && !G.animating && !(G.tutorialLive && G.tutorialHoldCpu)) {
            doCPU(live);
            armWatchdog(live);
          }
          return;
        }
        const live = G.gameState || s;
        if (!replayForward && live.pending_prompt?.responder === G.playerId) {
          ensurePendingPromptSurfaced(live, G.playerId);
        }
        if (!replayForward && G.isCPU && !G.animating && !(G.tutorialLive && G.tutorialHoldCpu)) {
          doCPU(live);
          armWatchdog(live);
        }
        return;
      }
    }

      if (prev && newEntries.length && hasAnimSteps) {
      TCG_DEBUG.log('apply', 'playLogSyncedSequence', { entries: newEntries.length, anims: newEntries.filter(e => e.anim?.length).length, ...TCG_DEBUG.trans(prev, s) });
      G.animating = true;
      try {
        await playLogSyncedSequence(prev, s, newEntries, G.playerId);
        if (!G.gameState || (G.gameState.seq ?? 0) < (s.seq ?? 0)) {
          G.gameState = s;
        }
      } finally {
        G.animating = false;
        flushPendingState();
      }
    } else {
      G._prevLogLen = prevLogLen;
      G._prevRects = prev ? collectCardRects() : {};
      G._handSlotsBefore = prev ? collectHandSlotRects() : null;
      let moves = prev ? diffCardMoves(prev, s) : [];
      if (G._deferredHandDrawIids?.size) {
        moves = filterDeferredHandDrawMoves(moves, G._deferredHandDrawIids);
      }
      if (prev) {
        moves = filterLiveStorageDeferredMoves(prev, moves, s);
      }
      if (emptyLiveRoundPresentationPending(prev, s)) {
        moves = filterEmptyLivePendingWrMoves(prev, moves, s);
        // Suppress turn-prep Energy/Draw flights until playEmptySkipTurnPrepSequence.
        moves = (moves || []).filter(m => {
          if (typeof isHiddenSourceToHand === 'function' && isHiddenSourceToHand(m)) return false;
          if (m.to?.zone === 'energy' && m.from?.zone === 'energy_deck') return false;
          return true;
        });
      }
      const openingDeal = isOpeningHandDealTransition(prev, s);
      const setupMulliganOnly = prev?.phase === 'setup' && s.phase === 'setup';
      if (setupMulliganOnly) moves = [];
      if (openingDeal) {
        const openingIds = openingHandDealIids(s);
        moves = moves.filter(m => !openingIds.has(m.iid));
      }
      G._animHideIids = prev && moves.length ? animHideIidsForMoves(prev, moves) : null;
      G._liveRevealFlips = prev && typeof resolveLiveRevealFlipKeys === 'function'
        ? resolveLiveRevealFlipKeys(prev, s, G.playerId)
        : new Set();
      rememberPerfSpectacleBaseline(prev, s);
      const livePlan = liveRoundPresentationPlan(livePrev, s);
      const emptySkip = !liveSetPlacementInProgress(s)
        && (livePlan.wantsEmptyRound || shouldPresentEmptyLiveRound(livePrev, s));
      if (!spectacleGateActive && pendingSpectacleTurn == null
          && (livePlan.needsLiveReveal || livePlan.wantsSpectacle || emptySkip)) {
        TCG_DEBUG.log('apply', 'presentLiveRound', { ...livePlan, emptySkip, solo: isSoloPlayerEmptyLiveRound(livePrev, s) }, TCG_DEBUG.trans(livePrev, s));
        G.animating = true;
        try {
          await presentLiveRound(livePrev, s, G.playerId, {
            newEntries,
            forceEmptyRound: emptySkip && !livePlan.wantsEmptyRound,
          });
          if (!replayForward) {
            const after = (typeof pickLatestStateForPlayback === 'function'
              ? pickLatestStateForPlayback(G.gameState) : null) || G.gameState || s;
            ensurePendingPromptSurfaced(after, G.playerId);
          }
        } finally {
          G._animHideIids = null;
          clearHandArrivingFlags();
          G.animating = false;
          if (!replayForward) {
            const after = (typeof pickLatestStateForPlayback === 'function'
              ? pickLatestStateForPlayback(G.gameState) : null) || G.gameState || s;
            if (after.pending_prompt?.responder === G.playerId) {
              ensurePendingPromptSurfaced(after, G.playerId);
            }
          }
          releaseLivePollsAndFlush();
        }
      } else {
          const emptyPending = emptyLiveRoundPresentationPending(prev, s);
          TCG_DEBUG.log('apply', 'direct render', { moves: moves.length, newLog: newEntries.length, emptyPending, ...TCG_DEBUG.trans(prev, s) });
          let animPrev = prev;
          let emptyRoundHandled = false;
          if (emptyPending && isLeavingLiveSetPhase(prev, s)) {
            G.animating = true;
            try {
              await presentLiveRound(prev, s, G.playerId, { newEntries, forceEmptyRound: true });
              if (!replayForward) {
                const after = (typeof pickLatestStateForPlayback === 'function'
                  ? pickLatestStateForPlayback(G.gameState) : null) || G.gameState || s;
                ensurePendingPromptSurfaced(after, G.playerId);
              }
              emptyRoundHandled = true;
            } finally {
              G._animHideIids = null;
              clearHandArrivingFlags();
              G.animating = false;
              releaseLivePollsAndFlush();
            }
          } else if (shouldAnimateEmptyLiveStorageWr(animPrev, s) && shouldPresentEmptyLiveRound(prev, s)) {
            G.animating = true;
            try {
              const wrFrom = buildEmptyLiveWrPlayback(animPrev, s) || animPrev;
              if (wrFrom && liveStorageHasCards(wrFrom)) {
                G.gameState = wrFrom;
                renderGame(wrFrom, { skipLog: true });
                await runLiveStorageRevealSequence(wrFrom, s, G.playerId, {
                  deferWrDiscards: true,
                  skipIntroBanner: true,
                });
              }
              await queueEmptyLiveRoundBanner();
              await waitForBannersIdle();
              const revealBoard = G._livePostRevealBoard || wrFrom;
              if (revealBoard && collectLiveStorageWrDiscards(revealBoard, s).length) {
                await playLiveStorageWrDiscards(revealBoard, s, G.playerId, { initialDelayMs: LIVE_BLUFF_WR_DELAY_MS });
                animPrev = G.gameState;
              }
              G._livePostRevealBoard = null;
              moves = diffCardMoves(animPrev, s);
              if (G._deferredHandDrawIids?.size) {
                moves = filterDeferredHandDrawMoves(moves, G._deferredHandDrawIids);
              }
              if (prev) {
                moves = filterLiveStorageDeferredMoves(prev, moves, s);
              }
              moves = filterEmptyLivePendingWrMoves(prev, moves, s);
              G._prevRects = collectCardRects();
              G._handSlotsBefore = collectHandSlotRects();
              G._animHideIids = animPrev && moves.length ? animHideIidsForMoves(animPrev, moves) : null;
              flushPostLiveLogBanners(animPrev, s, G.playerId, { emptySkip: true });
              markEmptyLiveRoundPresented(prev, s);
              clearEmptyLiveRoundPerfState();
              await playEmptySkipTurnPrepSequence(prev, s, newEntries, G.playerId);
              emptyRoundHandled = true;
              nudgeCpuAfterStatePresentation(s);
            } finally {
              G.animating = false;
            }
          } else if (G._livePostRevealBoard) {
            G.animating = true;
            try {
              if (await maybeAnimatePendingLiveStorageWr(s, G.playerId)) {
                animPrev = G.gameState;
                moves = diffCardMoves(animPrev, s);
                if (G._deferredHandDrawIids?.size) {
                  moves = filterDeferredHandDrawMoves(moves, G._deferredHandDrawIids);
                }
                if (prev) {
                  moves = filterLiveStorageDeferredMoves(prev, moves, s);
                }
                G._prevRects = collectCardRects();
                G._handSlotsBefore = collectHandSlotRects();
                G._animHideIids = animPrev && moves.length ? animHideIidsForMoves(animPrev, moves) : null;
              }
            } finally {
              G.animating = false;
            }
          }
          if (!emptyRoundHandled) {
          applyTurnPrepEntriesToState(s, s, newEntries);
          if (!liveSpectaclePendingForTransition(prev, s)) {
            queueStateAnnouncements(prev, s, G.playerId, {
              emptyLiveSkip: isEmptyLiveSkipTransition(prev, s),
              replayForward,
            });
          }
          const hideHandsOnMat = handsHiddenOnMat(s);
          const deferHand = hideHandsOnMat || openingDeal || handLayoutDeferForPlayer(moves, G.playerId);
          const deferOppHand = hideHandsOnMat || openingDeal || shouldDeferOpponentHandLayout(moves, s, G.playerId);
          captureHandShiftBaselines(moves, G.playerId);
          captureFlightArtClones(moves, G.playerId, animPrev);
          prepareWrPileAnimPending(animPrev, s, moves);
          G.gameState = s;
          renderGame(s, { skipHand: deferHand, skipOppHand: deferOppHand });
          // Keep round-scoped departure latches so newer-seq paints cannot invent
          // ghost Live→WR/Success flights after the first owned handoff.
          const silentWrAdds = animPrev ? wrCardsAddedWithoutAnimMoves(animPrev, s, moves) : [];
          if (silentWrAdds.length) {
            void refreshWaitingRoomPiles(s, G.playerId, {
              releaseIids: silentWrAdds.map(x => x.iid),
            });
          }
          if (moves.length && (deferHand || deferOppHand) && !openingDeal) {
            primeDeferredHandLayoutsAfterRender(s, G.playerId, moves);
          }
          const handSlotsAfter = (deferHand || deferOppHand)
            ? projectHandSlotRects(s, G.playerId)
            : collectHandSlotRects();
          if (openingDeal) {
            G.animating = true;
            try {
              await playOpeningHandDeal(prev, s, G.playerId);
              if (moves.length) {
                await playCardMoveAnimations(prev, s, G._prevRects, G.playerId, G._handSlotsBefore, handSlotsAfter, moves);
              }
            } finally {
              clearWrPileAnimPending();
              clearHandDepartRemovals();
              clearHandShiftBaselines();
              G._animHideIids = null;
              clearHandArrivingFlags();
              G.animating = false;
              flushPendingState();
            }
          } else if (animPrev && moves.length) {
            G.animating = true;
            if (!(deferHand || deferOppHand)) markHandDepartRemovals(moves);
            try {
              const liveSetPlacements = isLiveSetPhase(s.phase)
                && moves.length > 0
                && moves.every(m => m.from?.zone === 'hand' && m.to?.zone === 'live');
              if (liveSetPlacements) {
                await playHandToLiveStoragePlacements(animPrev, s, G.playerId, moves);
              } else {
                await playCardMoveAnimations(animPrev, s, G._prevRects, G.playerId, G._handSlotsBefore, handSlotsAfter, moves);
              }
            } finally {
              clearWrPileAnimPending();
              if (wrCardsAddedWithoutAnimMoves(animPrev, s, moves).length) {
                void refreshWaitingRoomPiles(s, G.playerId, { clearPending: true });
              }
              finalizeDeferredHandLayouts(s, G.playerId, { deferMine: deferHand, deferOpp: deferOppHand });
              clearHandDepartRemovals();
              clearHandShiftBaselines();
              G._animHideIids = null;
              clearHandArrivingFlags();
              G.animating = false;
              flushPendingState();
            }
          } else {
            G._animHideIids = null;
            if (prev && wrCardsAddedWithoutAnimMoves(prev, s, moves).length) {
              void refreshWaitingRoomPiles(s, G.playerId, { clearPending: true });
            }
            flushPendingState();
          }
          }
      }
    }

    if (G._liveSetLockPid && s.phase === 'live_set') {
      const lockPid = G._liveSetLockPid;
      // set_live_cards responses omit live_ready — keep optimistic lock-in so the
      // End LIVE Phase button cannot reappear while end_live_set is still in flight.
      if (!s.live_ready?.[lockPid]) {
        s.live_ready = { ...(s.live_ready || {}), [lockPid]: true };
      }
    }
    if (G._liveSetLockPid && s.phase !== 'live_set') {
      G._liveSetLockPid = null;
    } else if (G._liveSetLockPid && s.live_ready?.[G._liveSetLockPid]
        && s.active_player && s.active_player !== G._liveSetLockPid) {
      G._liveSetLockPid = null;
    }
    if (liveSetPlacementInProgress(s)
        && (G._liveRoundPlaybackActive || G._perfSpectacleActive || G._liveSpectacleGateRunning || G._livePollHold)) {
      TCG_DEBUG.warn('live', 'abort stuck presentation during live_set placement');
      abortGameplayPresentation({ skipAbortFlag: true });
    }
    if (G.isTutorial && !G.tutorialLive) return;
    if (newEntries.some(e => (e?.msg || '').includes('cannot attempt a Live; Live cards in storage went to the Waiting Room.'))
        && typeof refreshWaitingRoomPiles === 'function') {
      void refreshWaitingRoomPiles(G.gameState || s, G.playerId, { clearPending: true });
    }
    tcgDebugOnStateApplied(prev, s, newEntries);
    ensurePollHoldReleased(G.gameState || s);
    if (!replayForward && !G.animating && !G._perfSpectacleActive && !G._liveSpectacleGateRunning) {
      if (shouldRecoverMissedLiveSpectacle(prev, s)) {
        await runLiveSpectacleGate(prev, s, newEntries, G.playerId);
      }
      // Keep recovery if the show is still owed after the gate attempt.
      const after = G.gameState || s;
      if (!shouldRecoverMissedLiveSpectacle(prev, after)
          && !(typeof liveSpectacleStillOwedOnBoard === 'function'
            && liveSpectacleStillOwedOnBoard(prev, after))) {
        G._spectacleRecoveryPending = null;
      }
    } else if (!replayForward && shouldRecoverMissedLiveSpectacle(prev, s)) {
      G._spectacleRecoveryPending = { prev, s, newEntries, myId: G.playerId };
    }
    clearStalePerfDeferState(prev, s);
    if (!G.animating && !G._liveRoundPlaybackActive && !liveSetPlacementInProgress(s)
        && shouldPresentEmptyLiveRound(prev, s)) {
      G.animating = true;
      try {
        await presentLiveRound(prev, s, G.playerId, { newEntries, forceEmptyRound: true });
      } finally {
        G.animating = false;
        releaseLivePollsAndFlush();
      }
    }
    flushPendingState();
    if (!replayForward && !G.animating && !G._perfSpectacleActive && s.pending_prompt?.responder === G.playerId
        && (s.phase === 'live_success_effects'
            || s.phase === 'live_start_effects'
            || s.phase === 'main_first'
            || s.phase === 'main_second'
            || (s.phase === 'live_judge' && s.pending_prompt?.type === 'pick_judge_success_live'))) {
      ensurePendingPromptSurfaced(s, G.playerId);
    }
    // Softlock heal only when deferred still matches live pending_prompt.
    // Never resurrect a resolved picker when live already cleared the prompt.
    if (!replayForward && !G.animating && !G._perfSpectacleActive
        && G._deferredPromptState?.pending_prompt?.responder === G.playerId) {
      if (typeof maySurfaceDeferredPromptState === 'function'
          && maySurfaceDeferredPromptState(G._deferredPromptState, s)) {
        ensurePendingPromptSurfaced(G._deferredPromptState, G.playerId);
      } else if (!s.pending_prompt && typeof clearDeferredPromptState === 'function') {
        clearDeferredPromptState({ skipBannerRefresh: true });
      }
    }
    clearStaleCpuPromptBusyIfResolved(G.gameState || s);
    if (G.playerId) updateOpponentSkillWaitBanner(G.gameState || s, G.playerId);
    if (!replayForward && G.isCPU && !G.animating && !(G.tutorialLive && G.tutorialHoldCpu)) {
      doCPU(G.gameState || s);
      armWatchdog(G.gameState || s);
    } else if (!replayForward && G.isCPU
        && (G.gameState || s)?.pending_prompt?.responder === (typeof cpuOpponentId === 'function' ? cpuOpponentId() : 'p2')
        && !(G.tutorialLive && G.tutorialHoldCpu)) {
      const cpuId = typeof cpuOpponentId === 'function' ? cpuOpponentId() : 'p2';
      scheduleCpuResolvePrompt(G.gameState || s, (G.gameState || s).players?.[cpuId]);
      armCpuPromptHangWatch(G.gameState || s);
    } else if (!replayForward && !G.isCPU && !G.isSpectator) {
      armPvPWatchdog(G.gameState || s);
    }
    if (G.tutorialLive && typeof global.TutorialInteractive?.onStateApplied === 'function') {
      global.TutorialInteractive.onStateApplied(G.gameState || s, prev);
    }
  };

  global.enqueuePendingState = function enqueuePendingState(s) {
    if (!s || s.seq <= G.lastSeq) return;
    const q = G._pendingStateQueue || [];
    const seq = s.seq ?? 0;
    const last = G.lastSeq ?? 0;
    // Full get_state boards are large (log + zones). During Live Start / spectacle
    // holds every seq used to push another snapshot → Chrome memory spikes /
    // Page Unresponsive. Keep only the oldest still-owed board (transition prev)
    // and the newest (authoritative catch-up).
    let oldest = null;
    for (let i = 0; i < q.length; i++) {
      const st = q[i];
      const stSeq = st?.seq ?? 0;
      if (stSeq <= last || stSeq >= seq) continue;
      if (!oldest || stSeq < (oldest.seq ?? 0)) oldest = st;
    }
    G._pendingStateQueue = oldest ? [oldest, s] : [s];
  };

  const LIVE_POLL_HOLD_WATCHDOG_MS = 10000;

  function clearLivePollHoldWatchdog() {
    if (G._livePollHoldWatchdog) {
      clearTimeout(G._livePollHoldWatchdog);
      G._livePollHoldWatchdog = null;
    }
  }

  /**
   * Soft Main heal only: clear a stuck poll hold when playback is already idle.
   * Never abort LiveRoundDirector / close spectacle (0343150 watchdog did that
   * and skipped Live animations / desynced clients until refresh).
   */
  function armLivePollHoldWatchdog() {
    clearLivePollHoldWatchdog();
    G._livePollHoldWatchdog = setTimeout(function livePollHoldWatchdogTick() {
      G._livePollHoldWatchdog = null;
      if (!G._livePollHold) return;
      const s = G.gameState;
      const ph = s?.phase;
      const stage = s?.live_show?.stage;
      const onSettledMain = ph === 'main_first' || ph === 'main_second'
        || ph === 'active_first' || ph === 'active_second';
      const liveBusy = !!(stage && stage !== 'done')
        || ph === 'live_start_effects' || ph === 'live_performance_first'
        || ph === 'live_performance_second' || ph === 'live_judge'
        || ph === 'live_success_effects' || ph === 'live_set'
        || G._liveRoundPlaybackActive || G._liveSpectacleGateRunning
        || G._liveShowRunnerActive || G._perfSpectacleActive
        || (typeof LiveRoundDirector !== 'undefined' && LiveRoundDirector.active);
      if (!onSettledMain || liveBusy) {
        // Still in a legal hold window — check again later, do not nuke presentation.
        G._livePollHoldWatchdog = setTimeout(livePollHoldWatchdogTick, LIVE_POLL_HOLD_WATCHDOG_MS);
        return;
      }
      TCG_DEBUG.warn('poll', 'live poll hold watchdog — soft release on idle Main');
      releaseLivePolls({ forceResume: true });
    }, LIVE_POLL_HOLD_WATCHDOG_MS);
  }

  global.holdLivePolls = function holdLivePolls() {
    if (!G._livePollHold) TCG_DEBUG.log('poll', 'holdLivePolls');
    G._livePollHold = true;
    G._livePollHoldAt = Date.now();
    armLivePollHoldWatchdog();
  };

  global.releaseLivePolls = function releaseLivePolls(opts = {}) {
    clearLivePollHoldWatchdog();
    G._livePollHoldAt = 0;
    const wasHeld = !!G._livePollHold;
    G._livePollHold = false;
    if (!wasHeld && !opts.forceResume) return;
    TCG_DEBUG.log('poll', 'releaseLivePolls', { forceResume: !!opts.forceResume, wasHeld });
    if (!G.polling) return;
    // Always re-arm sync. Spectacle chrome may still be up while waiting on the
    // opponent's live_show ack — polls are allowed then, and skipping resume left
    // one client stale until the ~3.2s safety poll (looks like "their turn").
    if (G.syncEnabled && G.syncTicket && typeof scheduleDeferredSyncPull === 'function') {
      scheduleDeferredSyncPull(250);
    } else if (typeof resumePollingTick === 'function') {
      resumePollingTick(250);
    }
  };

  global.releaseLivePollsAndFlush = function releaseLivePollsAndFlush() {
    releaseLivePolls();
    flushPendingState();
    // Defer recovery so runLiveSpectacleGate's finally cannot synchronously re-enter the gate
    // (that path used to chain release → recovery → gate → release → poll=0).
    if (typeof tryFlushSpectacleRecovery === 'function') {
      clearTimeout(G._spectacleRecoveryTimer);
      G._spectacleRecoveryTimer = setTimeout(() => {
        G._spectacleRecoveryTimer = null;
        tryFlushSpectacleRecovery();
      }, 0);
    }
  };

  global.flushPendingState = function flushPendingState() {
    if (G.animating || isPresentationSuperseded()) return;
    if (typeof global.isReplayViewing === 'function' && global.isReplayViewing() && !G._replayForwardApply) return;
    const q = G._pendingStateQueue;
    if (!q?.length) {
      // Do NOT schedule a sync pull on every empty flush — that re-arms poll=0 forever
      // whenever apply/release ends with an empty queue. Catch-up is owned by SSE
      // (onSyncStateEvent), releaseLivePolls → resumePollingTick, and explicit pulls.
      return;
    }
    const next = q.shift();
    TCG_DEBUG.log('state', 'flush pending', { seq: next?.seq, remaining: q.length });
    if (next && next.seq > G.lastSeq) {
      const cur = G.gameState;
      if (cur && typeof liveSpectaclePendingForTransition === 'function'
          && liveSpectaclePendingForTransition(cur, next)) {
        TCG_DEBUG.log('state', 'flush pending: spectacle still owed — apply queued state', { seq: next.seq });
      }
      applyStateUpdate(next);
    }
    tryFlushSpectacleRecovery();
  };

  const _origApplyStateUpdate = global.applyStateUpdate;
  if (typeof _origApplyStateUpdate === 'function' && !_origApplyStateUpdate.__stampsHooked) {
    const wrapped = async function applyStateUpdateWithStamps(s) {
      await _origApplyStateUpdate(s);
      global.TCG_STAMPS?.syncGameUi?.(s);
      global.TCG_STAMPS?.onState?.(s);
    };
    wrapped.__stampsHooked = true;
    global.applyStateUpdate = wrapped;
  }

})(window);
