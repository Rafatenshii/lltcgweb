#!/usr/bin/env node
/**
 * Live movement sequencing / ownership regressions.
 * Run: node scripts/verify_live_movement.mjs
 */
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const html = fs.readFileSync(path.join(root, 'index.html'), 'utf8');
const spectacleJs = fs.readFileSync(path.join(root, 'client/js/spectacle.js'), 'utf8');
const sources = [html, spectacleJs];

function extractFn(name) {
  const re = new RegExp(`function ${name}\\([^)]*\\)\\s*\\{`);
  for (const src of sources) {
    const m = re.exec(src);
    if (!m) continue;
    let i = m.index + m[0].length;
    let depth = 1;
    while (i < src.length && depth > 0) {
      const ch = src[i++];
      if (ch === '{') depth++;
      else if (ch === '}') depth--;
    }
    return src.slice(m.index, i);
  }
  throw new Error(`missing function ${name}`);
}

const fnNames = [
  'isHiddenSourceToHand',
  'isHandToLivePlacementMove',
  'isLiveStorageExitMove',
  'isLiveCardOutcomeMove',
  'isMemberLiveStorageWrMove',
  'liveMovementRoundId',
  'movementLedgerKey',
  'ensureMovementLedger',
  'resetMovementLedger',
  'liveCardIidKey',
  'latchLiveStorageDeparture',
  'liveStorageDepartureLatched',
  'shouldClearAnimHideAfterHandoff',
  'reserveMovementPresentation',
  'settleMovementPresentation',
  'movementPresentationOwned',
  'liveStorageOutcomesLogicalKey',
  'liveStorageOutcomesAlreadyPlayed',
  'liveStorageBluffOutcomesAlreadyPlayed',
  'liveStorageLiveOutcomesAlreadyPlayed',
  'flightOrientClass',
  'flightInnerRotation',
  'buildMovementVisualDescriptor',
  'isLiveCard',
  'isMemberCard',
  'isLiveTypeCard',
  'isLiveHandLiveCrossFlight',
  'isLiveHandToLiveFlight',
  'isLiveLiveToHandFlight',
  'isLiveLiveToWrFlight',
  'isPortraitHandToLiveFlight',
  'isMemberLiveToWrFlight',
  'isMemberLiveToHandFlight',
  'isMemberStorageCrossFlight',
  'isMemberStageWaitToWrFlight',
];

const body = `
${fnNames.map(extractFn).join('\n')}
function enrichCard(c) { return c; }
function liveStorageUseArtSpin(c) { return isMemberCard(c); }
`;

const G = {
  roomId: 'room-test',
  playerId: 'p1',
  _movementLedger: null,
  _liveStorageDepartedIids: null,
  _liveStorageDepartedRound: null,
  _liveStorageOutcomesPlayedBluffKey: null,
  _liveStorageOutcomesPlayedLiveKey: null,
  _livePostRevealBoard: null,
};

const sandbox = { G, console, Set, Map, Number, JSON, Math };
vm.createContext(sandbox);
vm.runInContext(body, sandbox);

let failed = 0;
function ok(label, cond) {
  if (cond) console.log(`ok - ${label}`);
  else {
    failed += 1;
    console.error(`FAIL - ${label}`);
  }
}

const {
  isHiddenSourceToHand,
  isHandToLivePlacementMove,
  reserveMovementPresentation,
  settleMovementPresentation,
  movementPresentationOwned,
  resetMovementLedger,
  liveStorageOutcomesLogicalKey,
  liveStorageBluffOutcomesAlreadyPlayed,
  liveStorageLiveOutcomesAlreadyPlayed,
  buildMovementVisualDescriptor,
  flightOrientClass,
  liveStorageDepartureLatched,
  latchLiveStorageDeparture,
  shouldClearAnimHideAfterHandoff,
} = sandbox;

// --- placement vs draw classification ---
const placeMove = {
  iid: 'a1',
  from: { zone: 'hand', pid: 'p1' },
  to: { zone: 'live', pid: 'p1', index: 0 },
  card: { instance_id: 'a1', card_type_en: 'Live' },
};
const drawMove = {
  iid: 'd1',
  from: { zone: 'main_deck', pid: 'p1' },
  to: { zone: 'hand', pid: 'p1' },
  card: { instance_id: 'd1', card_type_en: 'Member' },
};
ok('hand→live classified as placement', isHandToLivePlacementMove(placeMove));
ok('deck→hand classified as hidden draw', isHiddenSourceToHand(drawMove));
ok('placement batch before draws (ordering contract)', (() => {
  const moves = [drawMove, placeMove, drawMove];
  const placements = moves.filter(isHandToLivePlacementMove);
  const draws = moves.filter(m => isHiddenSourceToHand(m));
  return placements.length === 1 && draws.length === 2
    && placements[0].iid === 'a1'
    && draws.every(m => m.iid === 'd1' || m.from.zone === 'main_deck');
})());

// --- movement ledger dedupe ---
resetMovementLedger();
const exitMove = {
  iid: 'live-1',
  from: { zone: 'live', pid: 'p2', index: 1 },
  to: { zone: 'waiting_room', pid: 'p2' },
  card: { instance_id: 'live-1', card_type_en: 'Live' },
};
const r1 = reserveMovementPresentation(5, exitMove);
const r2 = reserveMovementPresentation(5, exitMove);
ok('first reserve owns the exit', r1.ok === true);
ok('second reserve is rejected (dedupe)', r2.ok === false);
settleMovementPresentation(r1.key);
ok('settled move stays owned', movementPresentationOwned(5, exitMove) === true);
const r3 = reserveMovementPresentation(5, {
  ...exitMove,
  // newer seq same logical exit
});
ok('newer-seq logical exit still rejected', r3.ok === false);

ok('departure latch survives turn vs live_show.turn mismatch', (() => {
  G._liveStorageDepartedIids = new Set(['live-1']);
  G._liveStorageDepartedRound = 4;
  return liveStorageDepartureLatched('live-1', 5) === true
    && liveStorageDepartureLatched('live-1', 4) === true
    && liveStorageDepartureLatched('other', 4) === false;
})());
G._livePostRevealBoard = {
  players: {
    p1: { live_zone: [{ instance_id: 'live-1' }, { instance_id: 'keep-1' }] },
    p2: { live_zone: [{ instance_id: 'live-1' }] },
  },
};
latchLiveStorageDeparture('live-1', 4);
ok('latch strips held post-reveal live zone',
  G._livePostRevealBoard.players.p1.live_zone.length === 1
  && G._livePostRevealBoard.players.p1.live_zone[0].instance_id === 'keep-1'
  && G._livePostRevealBoard.players.p2.live_zone.length === 0);
ok('handoff must not unhide a latched Live-storage source',
  shouldClearAnimHideAfterHandoff('live-1', 'live') === false
  && shouldClearAnimHideAfterHandoff('keep-1', 'live') === true
  && shouldClearAnimHideAfterHandoff('live-1', 'hand') === true);

ok('departure latch matches number vs string iid', (() => {
  resetMovementLedger();
  G._livePostRevealBoard = {
    players: {
      p1: { live_zone: [{ instance_id: 42 }] },
      p2: { live_zone: [] },
    },
  };
  latchLiveStorageDeparture(42, 7);
  return liveStorageDepartureLatched('42') === true
    && liveStorageDepartureLatched(42) === true
    && G._livePostRevealBoard.players.p1.live_zone.length === 0;
})());

// different round can reserve again
resetMovementLedger();
const r4 = reserveMovementPresentation(6, exitMove);
ok('next Live round can reserve again', r4.ok === true);

// --- split outcome keys ---
const finalBoard = { room_id: 'room-test', turn: 5, live_show: { turn: 5 } };
const bluffKey = liveStorageOutcomesLogicalKey(finalBoard, 'bluff');
const liveKey = liveStorageOutcomesLogicalKey(finalBoard, 'live');
ok('bluff/live outcome keys differ', bluffKey !== liveKey && bluffKey.includes(':bluff') && liveKey.includes(':live'));
G._liveStorageOutcomesPlayedBluffKey = bluffKey;
ok('bluff played does not imply live played',
  liveStorageBluffOutcomesAlreadyPlayed(finalBoard)
  && !liveStorageLiveOutcomesAlreadyPlayed(finalBoard));
G._liveStorageOutcomesPlayedLiveKey = liveKey;
ok('both outcome kinds marked played',
  liveStorageBluffOutcomesAlreadyPlayed(finalBoard)
  && liveStorageLiveOutcomesAlreadyPlayed(finalBoard));

// --- orientation descriptor ---
const liveCard = { card_type_en: 'Live', card_type: 'ライブ' };
const memberCard = { card_type_en: 'Member', card_type: 'メンバー' };
ok('live storage orient is landscape', flightOrientClass(liveCard, 'live') === 'flight-landscape');
ok('member hand orient is portrait', flightOrientClass(memberCard, 'hand') === 'flight-portrait');
const handToLive = buildMovementVisualDescriptor(memberCard, 'hand', 'live', false);
ok('member hand→live uses art morph once',
  handToLive.liveArtMorph === true && handToLive.memberShellSpin === false);
const liveToWr = buildMovementVisualDescriptor(memberCard, 'live', 'waiting_room', false);
ok('member live→WR uses shell spin once',
  liveToWr.memberShellSpin === true && liveToWr.morphClass.includes('member-shell-spin'));
const liveFaceDown = buildMovementVisualDescriptor(liveCard, 'hand', 'live', true);
ok('face-down live placement keeps landscape destination class',
  liveFaceDown.toClass === 'flight-landscape' && liveFaceDown.faceDown === true);

// --- persisted live_show timing contract ---
const oneBeatStart = spectacleJs.indexOf('async function presentOneLiveShowBeat');
const oneBeatEnd = spectacleJs.indexOf('/**\n * Drive the persisted server live_show cursor', oneBeatStart);
const oneBeatSource = spectacleJs.slice(oneBeatStart, oneBeatEnd);
const postJudgeStart = spectacleJs.indexOf('async function playLiveShowPostJudgeStorageExits');
const postJudgeEnd = spectacleJs.indexOf('async function fetchLiveShowStateNow', postJudgeStart);
const postJudgeSource = spectacleJs.slice(postJudgeStart, postJudgeEnd);
const stateApplyJs = fs.readFileSync(path.join(root, 'client/js/state-apply.js'), 'utf8');
ok('outcomes beat does not fly Member bluffs before verdict',
  !oneBeatSource.includes("kinds: 'bluff'")
  && !oneBeatSource.includes('playLiveStorageWrDiscards('));
ok('post-judge handoff owns every storage exit',
  postJudgeSource.includes("kinds: 'all'")
  && postJudgeSource.includes('liveStorageOutcomesAlreadyPlayed'));
ok('server outcomes board is latched before first paint',
  stateApplyJs.indexOf('holdLiveShowStorageBeforeOutcomePaint(prev, s)')
    < stateApplyJs.indexOf('commitServerBoardToUi(s)', stateApplyJs.indexOf("s.live_show?.stage")));
ok('chained live_show outcomes board is latched before repaint',
  spectacleJs.indexOf('holdLiveShowStorageBeforeOutcomePaint(prior, board)')
    < spectacleJs.indexOf('renderGame(board, {', spectacleJs.indexOf('holdLiveShowStorageBeforeOutcomePaint(prior, board)')));

if (failed) {
  console.error(`\n${failed} failure(s)`);
  process.exit(1);
}
console.log('\nAll live movement checks passed.');
