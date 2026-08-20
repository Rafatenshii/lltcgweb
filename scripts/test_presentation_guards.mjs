#!/usr/bin/env node
/**
 * Regression contracts for Live Win/Loss Check / heart-check presentation heals.
 * node scripts/test_presentation_guards.mjs
 */
import fs from 'node:fs';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const require = createRequire(import.meta.url);
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const g = require(path.join(root, 'client/js/presentation-guards.js'));
const syncSrc = fs.readFileSync(path.join(root, 'client/js/game-sync.js'), 'utf8');
const indexSrc = fs.readFileSync(path.join(root, 'index.html'), 'utf8');
const applySrc = fs.readFileSync(path.join(root, 'client/js/state-apply.js'), 'utf8');
const spectacleSrc = fs.readFileSync(path.join(root, 'client/js/spectacle.js'), 'utf8');
const cardimgCacheSrc = fs.readFileSync(path.join(root, 'cardimg_cache.php'), 'utf8');

let failed = 0;
function check(name, cond) {
  if (cond) {
    console.log(`OK: ${name}`);
  } else {
    console.error(`FAIL: ${name}`);
    failed += 1;
  }
}

const mainPrev = { seq: 10, phase: 'main_first', active_player: 'p1', players: { p1: { hand: [{}], stage: {}, energy_zone: [] } } };
const mainNext = { seq: 11, phase: 'main_first', active_player: 'p1', players: { p1: { hand: [], stage: { left: { instance_id: 'a' } }, energy_zone: [] } } };

check('play onto empty slot is Main catch-up', g.isMainBoardCatchupSnapshot(mainPrev, mainNext));
check('End Main first→second is turn-advance', g.isTurnAdvanceSnapshot(
  { seq: 4, phase: 'main_first', active_player: 'p1' },
  { seq: 5, phase: 'main_second', active_player: 'p2' },
));

const liveJudge = {
  seq: 20,
  phase: 'live_judge',
  live_show: { stage: 'judge', stage_seq: 4 },
};
const afterJudgeMain = { seq: 21, phase: 'main_first', active_player: 'p1' };

check('live_judge is a Win/Loss pipeline phase', g.isLiveWinLossPipelinePhase('live_judge'));
check('live_show judge is in flight', g.liveShowInFlight(liveJudge));

check('never force-apply while heart-check hold', !g.mayForceApplyHeldSnapshot(mainPrev, mainNext, { heartCheckHold: true }));
check('never force-apply while spectacle chrome is up', !g.mayForceApplyHeldSnapshot(mainPrev, mainNext, { perfSpectacle: true }));
check('never force-apply while live_show runner owns Win/Loss', !g.mayForceApplyHeldSnapshot(mainPrev, mainNext, { liveShowRunner: true }));
check('never force-apply incoming live_judge', !g.mayForceApplyHeldSnapshot(
  { seq: 8, phase: 'live_set', active_player: 'p1' },
  { seq: 9, phase: 'live_judge' },
  {},
));
check('never force-apply live_set → live_performance', !g.mayForceApplyHeldSnapshot(
  { seq: 8, phase: 'live_set', active_player: 'p1' },
  { seq: 9, phase: 'live_performance_first' },
  {},
));
check('never force-apply Main catch-up over live_show performance', !g.mayForceApplyHeldSnapshot(
  { ...mainPrev, live_show: { stage: 'performance' } },
  mainNext,
  {},
));
check('never force-apply while prev is live_judge', !g.mayForceApplyHeldSnapshot(liveJudge, afterJudgeMain, { perfSpectacle: true }));

check('allow Main catch-up with only leftover G.animating', g.mayForceApplyHeldSnapshot(mainPrev, mainNext, { animating: true }));

check('do not unstick Main during live_judge', !g.mayUnstickStuckMainPresentation(liveJudge, { animating: true }, 0));
check('do not unstick Main during Checking hearts', !g.mayUnstickStuckMainPresentation(
  { phase: 'main_first' },
  { heartCheckHold: true, animating: true },
  0,
));
check('do not unstick Main while live_show in flight', !g.mayUnstickStuckMainPresentation(
  { phase: 'main_first', live_show: { stage: 'performance' } },
  { animating: true },
  0,
));
check('unstick Main when no flights and leftover animating', g.mayUnstickStuckMainPresentation(
  { phase: 'main_first' },
  { animating: true },
  0,
));

check('do not close spectacle on live_judge without a pick', !g.mayClearStuckPerfSpectacle(
  { phase: 'live_judge' },
  { perfSpectacle: true },
));
check('do not close spectacle during performance live_show', !g.mayClearStuckPerfSpectacle(
  { phase: 'live_performance_first', live_show: { stage: 'performance' } },
  { perfSpectacle: true },
));
check('may close leftover spectacle on settled Main', g.mayClearStuckPerfSpectacle(
  { phase: 'main_first' },
  { perfSpectacle: true },
));
check('may close spectacle for Success-Live pick after show', g.mayClearStuckPerfSpectacle(
  { phase: 'live_judge', pending_prompt: { type: 'pick_judge_success_live' } },
  { perfSpectacle: true, postSpectacleReady: true },
));

check('resume runner when live_show judge and runner died', g.shouldResumeLiveShowRunner(
  { live_show: { stage: 'judge' } },
  {},
));
check('resume runner on performance heart-check beat', g.shouldResumeLiveShowRunner(
  { live_show: { stage: 'performance' } },
  {},
));
check('do not resume runner when already running', !g.shouldResumeLiveShowRunner(
  { live_show: { stage: 'judge' } },
  { liveShowRunner: true },
));
check('do not resume runner over an open skill prompt', !g.shouldResumeLiveShowRunner(
  { live_show: { stage: 'performance' }, pending_prompt: { type: 'yes_no' } },
  {},
));
check('spectators do not ack via runner resume', !g.shouldResumeLiveShowRunner(
  { live_show: { stage: 'judge' } },
  { isSpectator: true },
));

check('index.html loads presentation-guards before game-sync',
  /presentation-guards\.js\?v=\d+[\s\S]*game-sync\.js/.test(indexSrc));
check('game-sync no longer closes spectacle on promptless live_judge',
  !/judgeWaitNoLocalPrompt/.test(syncSrc));
check('state-apply uses mayForceApplyHeldSnapshot',
  /mayForceApplyHeldSnapshot/.test(applySrc));
check('game-sync resumes dead live_show runner',
  /shouldResumeLiveShowRunner/.test(syncSrc));

check('force-apply dismisses local prompt chrome',
  /dismissLocalPromptChrome\(['"]turn-advance['"]\)/.test(applySrc)
  || /dismissLocalPromptChrome\('turn-advance'\)/.test(applySrc));
check('spectacle defines dismissLocalPromptChrome',
  /function dismissLocalPromptChrome\(/.test(spectacleSrc));
check('spectacle gates deferred resurface with maySurfaceDeferredPromptState',
  /function maySurfaceDeferredPromptState\(/.test(spectacleSrc));
check('state-apply softlock uses maySurfaceDeferredPromptState',
  /maySurfaceDeferredPromptState/.test(applySrc));
check('live_start/success not in needsResurface after resolve',
  !/needsResurface = \(pr\.type === 'pick_judge_success_live'[\s\S]*s\.phase === 'live_start_effects'/.test(spectacleSrc));
check('game-sync has action apply epoch',
  /beginActionApplyEpoch/.test(syncSrc) && /endActionApplyEpoch/.test(syncSrc)
  && /_actionApplyEpoch/.test(syncSrc));
check('sendAct owns apply epoch for resolve/end_main/play',
  /beginActionApplyEpoch/.test(indexSrc) && /endActionApplyEpoch/.test(indexSrc)
  && /ownsApplyEpoch/.test(indexSrc));
check('SSE deferred pull respects action apply epoch',
  /_actionApplyEpochNeedsFollowUp/.test(syncSrc));
check('card image preload has timeout',
  /CARD_IMAGE_PRELOAD_TIMEOUT_MS/.test(indexSrc));
check('cache_card_image prebuilds board thumbs',
  /tcgPrebuildCardImageThumbs/.test(cardimgCacheSrc)
  && /foreach \(\[180, 256\]/.test(cardimgCacheSrc));

if (failed) {
  console.error(`\n${failed} presentation-guard contract(s) failed`);
  process.exit(1);
}
console.log('\npresentation-guards: PASS');
