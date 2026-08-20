/**
 * Regression: End LIVE Phase must not show after cards are locked / placed.
 * Mirrors index.html helpers (keep in sync if those change).
 */
function liveZonePlacedThisSetCount(s, myId) {
  const zone = s?.players?.[myId]?.live_zone || [];
  return zone.filter((c) => c && !c.preplaced_live_zone).length;
}

function isLiveSetLockedIn(s, myId, G) {
  if (!myId) return false;
  if (G._liveSetLockPid === myId) return true;
  if (s?.live_ready?.[myId]) return true;
  if (s?.phase === 'live_set' && s?.active_player === myId
      && liveZonePlacedThisSetCount(s, myId) > 0) {
    return true;
  }
  return false;
}

function isLiveSetSelecting(s, myId, G) {
  if (!s || s.phase !== 'live_set') return false;
  if (isLiveSetLockedIn(s, myId, G)) return false;
  return s.active_player === myId;
}

function phaseLabel(s, myId, G, liveSelLen = 0) {
  if (s.phase !== 'live_set') return null;
  const myReady = isLiveSetLockedIn(s, myId, G);
  if (myReady) {
    const oppId = myId === 'p1' ? 'p2' : 'p1';
    if (!s.live_ready?.[oppId]) return 'waitingOpponent';
    return null;
  }
  if (isLiveSetSelecting(s, myId, G)) {
    return liveSelLen > 0 ? 'setLiveCards' : 'endLivePhase';
  }
  return null;
}

function assert(name, cond) {
  if (!cond) {
    console.error('FAIL', name);
    process.exitCode = 1;
  } else {
    console.log('ok', name);
  }
}

const base = {
  phase: 'live_set',
  active_player: 'p1',
  live_ready: {},
  players: {
    p1: { live_zone: [] },
    p2: { live_zone: [] },
  },
};

assert('empty selecting shows End LIVE Phase',
  phaseLabel(base, 'p1', {}, 0) === 'endLivePhase');

assert('raised cards show Set Live',
  phaseLabel(base, 'p1', {}, 2) === 'setLiveCards');

assert('live_ready hides End LIVE Phase',
  phaseLabel({ ...base, live_ready: { p1: true } }, 'p1', {}, 0) === 'waitingOpponent');

assert('local lock pid hides End LIVE Phase',
  phaseLabel(base, 'p1', { _liveSetLockPid: 'p1' }, 0) === 'waitingOpponent');

const placed = {
  ...base,
  players: {
    p1: { live_zone: [{ instance_id: 'a', card_type: 'ライブ' }] },
    p2: { live_zone: [] },
  },
};
assert('cards in zone (no ready) hide End LIVE Phase',
  phaseLabel(placed, 'p1', {}, 0) === 'waitingOpponent');
assert('preplaced only still allows End LIVE Phase',
  phaseLabel({
    ...base,
    players: {
      p1: { live_zone: [{ instance_id: 'a', preplaced_live_zone: true }] },
      p2: { live_zone: [] },
    },
  }, 'p1', {}, 0) === 'endLivePhase');

assert('not my turn: no End LIVE Phase',
  phaseLabel({ ...base, active_player: 'p2' }, 'p1', {}, 0) === null);

if (process.exitCode) {
  console.error('live_set lock UI tests failed');
  process.exit(1);
}
console.log('All live_set lock UI checks passed');
