/**
 * Discord Rich Presence controller (Android Loveca shell only).
 * Browser / desktop: no-op. Requires Capacitor DiscordPresence plugin + Social SDK AAR
 * for live Discord profile activity; deep-link join/spectate works via App Links anyway.
 */
(function (global) {
  'use strict';

  var OPT_IN_KEY = 'tcg_discord_presence_opt_in';
  var THROTTLE_MS = 2500;
  var DISCORD_APP_ID = '1439716818058088612';

  var lastKey = '';
  var lastSentAt = 0;
  var pendingTimer = null;
  var startedAtByKind = Object.create(null);
  var plugin = null;
  var joinListenerBound = false;

  function isAndroidShell() {
    try {
      var ua = navigator.userAgent || '';
      if (/LoveCaAndroid/i.test(ua)) return true;
      if (global.Capacitor && typeof global.Capacitor.isNativePlatform === 'function') {
        return !!global.Capacitor.isNativePlatform();
      }
    } catch (e) { /* ignore */ }
    return false;
  }

  function getPlugin() {
    if (plugin) return plugin;
    try {
      if (global.Capacitor && global.Capacitor.Plugins && global.Capacitor.Plugins.DiscordPresence) {
        plugin = global.Capacitor.Plugins.DiscordPresence;
        return plugin;
      }
      if (global.Capacitor && typeof global.Capacitor.registerPlugin === 'function') {
        plugin = global.Capacitor.registerPlugin('DiscordPresence');
        return plugin;
      }
    } catch (e) { /* ignore */ }
    return null;
  }

  function isOptedIn() {
    try {
      return localStorage.getItem(OPT_IN_KEY) === '1';
    } catch (e) {
      return false;
    }
  }

  function setOptedIn(on) {
    try {
      if (on) localStorage.setItem(OPT_IN_KEY, '1');
      else localStorage.removeItem(OPT_IN_KEY);
    } catch (e) { /* ignore */ }
    syncOptionsUi();
    if (!on) {
      void clearActivity();
    } else {
      scheduleRefresh(true);
    }
  }

  function modeLabel(mode) {
    if (typeof global.gameModeShortLabel === 'function') {
      return global.gameModeShortLabel(mode);
    }
    var m = String(mode || 'standard');
    if (m === 'starters') return 'Starters';
    if (m === 'randomized') return 'Randomized';
    if (m === 'free') return 'Free';
    return 'Standard';
  }

  function currentMode() {
    if (typeof global.currentGameMode === 'function') return global.currentGameMode();
    if (typeof global.rankedGameMode === 'function') return global.rankedGameMode();
    return 'standard';
  }

  function activeScreen() {
    var node = document.querySelector('.screen.active');
    if (!node || !node.id) return '';
    return String(node.id).replace(/^screen-/, '');
  }

  function G() {
    return global.G || {};
  }

  function A() {
    return global.A || {};
  }

  function deriveActivity() {
    var g = G();
    var a = A();
    var screen = activeScreen();
    var mode = currentMode();
    var modeTxt = modeLabel(mode);

    if (!screen || screen === 'auth') {
      return { kind: 'idle', details: 'Signed out', state: '', largeImage: 'loveca', joinable: false };
    }

    if (a.rankedSearching) {
      return {
        kind: 'ranked_queue',
        details: 'Waiting for Ranked game (' + modeTxt + ')',
        state: 'Ranked queue',
        largeImage: 'loveca_ranked',
        joinable: true,
        joinType: 'ranked_queue',
        gameMode: mode,
      };
    }

    if (screen === 'game' && g.roomId && !g.isSpectator) {
      if (g.isCPU) {
        return {
          kind: 'cpu',
          details: 'In CPU game (' + modeTxt + ')',
          state: 'vs CPU',
          largeImage: 'loveca_cpu',
          joinable: false,
        };
      }
      var ranked = (g.gameState && g.gameState.mode === 'ranked') || false;
      if (ranked) {
        return {
          kind: 'ranked_match',
          details: 'In Ranked game (' + modeTxt + ')',
          state: 'In a match',
          largeImage: 'loveca_ranked',
          joinable: true,
          joinType: 'spectate',
          roomId: g.roomId,
        };
      }
      return {
        kind: 'unranked_match',
        details: 'In Unranked game (' + modeTxt + ')',
        state: g.casualRandomMatch ? 'Casual match' : 'Friend match',
        largeImage: 'loveca_casual',
        joinable: true,
        joinType: 'spectate',
        roomId: g.roomId,
      };
    }

    if (screen === 'booster' || screen === 'pack-results') {
      return {
        kind: 'booster',
        details: 'Opening booster packs',
        state: 'Booster shop',
        largeImage: 'loveca_booster',
        joinable: false,
      };
    }
    if (screen === 'sticker') {
      return {
        kind: 'sticker',
        details: 'Browsing the sticker shop',
        state: 'Sticker shop',
        largeImage: 'loveca_sticker',
        joinable: false,
      };
    }
    if (screen === 'ranked') {
      return {
        kind: 'menu_ranked',
        details: 'In menus',
        state: 'Ranked hub',
        largeImage: 'loveca',
        joinable: false,
      };
    }
    if (screen === 'lobby' || screen === 'waiting') {
      return {
        kind: 'menu_unranked',
        details: 'In menus',
        state: 'Unranked lobby',
        largeImage: 'loveca',
        joinable: false,
      };
    }
    if (screen === 'deck') {
      return {
        kind: 'menu_deck',
        details: 'In menus',
        state: 'Deck builder',
        largeImage: 'loveca',
        joinable: false,
      };
    }
    return {
      kind: 'menu',
      details: 'In menus',
      state: screen ? screen.replace(/-/g, ' ') : 'Hub',
      largeImage: 'loveca',
      joinable: false,
    };
  }

  function activityKey(act) {
    return [act.kind, act.details, act.state, act.roomId || '', act.gameMode || '', act.joinable ? '1' : '0'].join('|');
  }

  async function mintJoinSecret(act) {
    if (!act.joinable || !act.joinType) return null;
    if (typeof global.accountPost !== 'function') return null;
    try {
      if (act.joinType === 'spectate' && act.roomId) {
        var spec = await global.accountPost('presence_action_mint', {
          action_type: 'spectate',
          room_id: act.roomId,
        });
        return spec && spec.token ? String(spec.token) : null;
      }
      if (act.joinType === 'ranked_queue') {
        var q = await global.accountPost('presence_action_mint', {
          action_type: 'ranked_queue',
          game_mode: act.gameMode || currentMode(),
        });
        return q && q.token ? String(q.token) : null;
      }
    } catch (e) {
      return null;
    }
    return null;
  }

  async function publish(act, force) {
    if (!isAndroidShell() || !isOptedIn()) return;
    var p = getPlugin();
    if (!p || typeof p.setActivity !== 'function') return;

    var kind = act.kind || 'menu';
    if (!startedAtByKind[kind]) startedAtByKind[kind] = Date.now();
    var startMs = startedAtByKind[kind];

    var joinSecret = null;
    if (act.joinable) {
      joinSecret = await mintJoinSecret(act);
    }

    var payload = {
      applicationId: DISCORD_APP_ID,
      details: act.details || '',
      state: act.state || '',
      largeImage: act.largeImage || 'loveca',
      largeText: 'Loveca',
      startTimestampMs: startMs,
      joinSecret: joinSecret || '',
      partyId: act.roomId ? ('room:' + act.roomId) : (act.joinType === 'ranked_queue' ? ('queue:' + (act.gameMode || 'standard')) : ''),
      partySize: act.joinable && act.joinType === 'spectate' ? 2 : (act.joinable ? 1 : 0),
      partyMax: act.joinable && act.joinType === 'spectate' ? 8 : (act.joinable ? 2 : 0),
      kind: kind,
    };

    try {
      await p.setActivity(payload);
    } catch (e) { /* Discord absent / SDK not linked */ }
  }

  async function clearActivity() {
    lastKey = '';
    startedAtByKind = Object.create(null);
    var p = getPlugin();
    if (!p || typeof p.clearActivity !== 'function') return;
    try { await p.clearActivity(); } catch (e) { /* ignore */ }
  }

  function scheduleRefresh(force) {
    if (!isAndroidShell()) return;
    if (pendingTimer) {
      clearTimeout(pendingTimer);
      pendingTimer = null;
    }
    var delay = force ? 0 : THROTTLE_MS;
    pendingTimer = setTimeout(function () {
      pendingTimer = null;
      void refreshNow(!!force);
    }, delay);
  }

  async function refreshNow(force) {
    if (!isAndroidShell()) return;
    if (!isOptedIn()) {
      await clearActivity();
      return;
    }
    var act = deriveActivity();
    var key = activityKey(act);
    var now = Date.now();
    if (!force && key === lastKey && (now - lastSentAt) < THROTTLE_MS) return;
    // Reset start clock when kind changes.
    if (lastKey && lastKey.split('|')[0] !== act.kind) {
      startedAtByKind[act.kind] = Date.now();
    }
    lastKey = key;
    lastSentAt = now;
    await publish(act, force);
  }

  async function linkDiscord() {
    var p = getPlugin();
    if (!p || typeof p.link !== 'function') {
      throw new Error('Discord Presence plugin unavailable');
    }
    return p.link({ applicationId: DISCORD_APP_ID });
  }

  async function unlinkDiscord() {
    var p = getPlugin();
    if (p && typeof p.unlink === 'function') {
      try { await p.unlink(); } catch (e) { /* ignore */ }
    }
    setOptedIn(false);
  }

  function syncOptionsUi() {
    var row = document.getElementById('options-discord-presence-row');
    var chk = document.getElementById('chk-discord-presence');
    var status = document.getElementById('options-discord-presence-status');
    var btnLink = document.getElementById('btn-discord-presence-link');
    if (row) row.hidden = !isAndroidShell();
    if (!isAndroidShell()) return;
    if (chk) chk.checked = isOptedIn();
    if (status) {
      status.textContent = isOptedIn()
        ? 'On — Discord can show what you are doing in Loveca when Discord is open.'
        : 'Off — enable to share menus, matches, and queue status on Discord.';
    }
    if (btnLink) btnLink.hidden = !isOptedIn();
  }

  function bindOptionsUi() {
    var chk = document.getElementById('chk-discord-presence');
    var btnLink = document.getElementById('btn-discord-presence-link');
    if (chk && !chk._tcgPresenceBound) {
      chk._tcgPresenceBound = true;
      chk.addEventListener('change', function () {
        setOptedIn(!!chk.checked);
      });
    }
    if (btnLink && !btnLink._tcgPresenceBound) {
      btnLink._tcgPresenceBound = true;
      btnLink.addEventListener('click', function () {
        void (async function () {
          try {
            var res = await linkDiscord();
            if (typeof global.toast === 'function') {
              global.toast(
                (res && res.stub)
                  ? 'Presence enabled. Add discord_partner_sdk.aar for live Discord profile activity.'
                  : 'Discord linked for Rich Presence.',
                4200
              );
            }
            scheduleRefresh(true);
          } catch (e) {
            if (typeof global.toast === 'function') {
              global.toast((e && e.message) || 'Could not link Discord Presence', 4200);
            }
          }
        })();
      });
    }
    syncOptionsUi();
  }

  function bindJoinListener() {
    if (joinListenerBound) return;
    var p = getPlugin();
    if (!p || typeof p.addListener !== 'function') return;
    joinListenerBound = true;
    p.addListener('joinAction', function (ev) {
      var secret = ev && (ev.secret || ev.token || '');
      if (secret) consumePresenceActionToken(String(secret));
    });
  }

  function capturePresenceActionFromUrl() {
    try {
      var params = new URLSearchParams(location.search);
      var token = (params.get('presence_action') || '').trim();
      var path = String(location.pathname || '');
      // Discord appends /_discord/join?secret=…
      if (!token && /\/_discord\/join\/?$/i.test(path)) {
        token = (params.get('secret') || '').trim();
      }
      if (!token && /^[a-f0-9]{32,96}$/i.test(String(params.get('secret') || ''))) {
        token = String(params.get('secret') || '').trim();
      }
      if (!token || !/^[a-f0-9]{32,96}$/i.test(token)) return;
      sessionStorage.setItem('tcg_presence_action', token);
      params.delete('presence_action');
      params.delete('secret');
      var qs = params.toString();
      var cleanPath = path.replace(/\/_discord\/join\/?$/i, '/tcg/');
      if (!/\/tcg\/?$/i.test(cleanPath) && /loveliveradio\.ca/i.test(location.host || '')) {
        cleanPath = '/tcg/';
      }
      history.replaceState({}, document.title, cleanPath + (qs ? '?' + qs : '') + location.hash);
    } catch (e) { /* ignore */ }
  }

  function peekPresenceAction() {
    try {
      var t = (sessionStorage.getItem('tcg_presence_action') || '').trim();
      if (t && /^[a-f0-9]{32,96}$/i.test(t)) return t;
    } catch (e) { /* ignore */ }
    return null;
  }

  function clearPresenceAction() {
    try { sessionStorage.removeItem('tcg_presence_action'); } catch (e) { /* ignore */ }
  }

  async function consumePresenceActionToken(token) {
    token = String(token || peekPresenceAction() || '').trim();
    if (!token) return;
    if (typeof global.accountPost !== 'function') return;

    var myId = String((A().user && A().user.id) || (A().profile && A().profile.user && A().profile.user.id) || '');
    try {
      var peek = await global.accountPost('presence_action_redeem', { token: token, peek: true });
      if (peek && peek.action === 'ranked_queue' && !myId) {
        try { sessionStorage.setItem('tcg_presence_action', token); } catch (e0) { /* ignore */ }
        if (typeof global.toast === 'function') {
          global.toast('Sign in with Discord to join that ranked queue.', 4500);
        }
        if (typeof global.showScr === 'function') global.showScr('auth');
        return;
      }
    } catch (ePeek) {
      // Fall through to redeem — expired/unknown tokens fail there too.
    }

    clearPresenceAction();
    try {
      var redeemed = await global.accountPost('presence_action_redeem', { token: token });
      if (redeemed && redeemed.action === 'spectate' && redeemed.room_id) {
        try {
          sessionStorage.setItem('tcg_loveca_spectate', String(redeemed.room_id).toUpperCase());
        } catch (e1) { /* ignore */ }
        if (typeof global.consumeLovecaSpectateInvite === 'function') {
          await global.consumeLovecaSpectateInvite();
        }
        return;
      }
      if (redeemed && redeemed.action === 'ranked_queue') {
        var challenge = String(redeemed.challenge_discord_id || '');
        var gameMode = redeemed.game_mode || currentMode();
        if (!challenge) throw new Error('Invalid ranked presence action');
        if (typeof global.persistGameMode === 'function') global.persistGameMode(gameMode);
        if (A().casualSearching && typeof global.cancelCasualSearch === 'function') {
          await global.cancelCasualSearch();
        }
        var res = await global.accountPost('ranked_join', {
          challenge_discord_id: challenge,
          game_mode: gameMode,
        });
        if (res.match && res.match.status === 'matched' && typeof global.enterRankedMatch === 'function') {
          if (res.queue_stats && typeof global.updateRankedQueueStats === 'function') {
            global.updateRankedQueueStats(res.queue_stats);
          }
          await global.enterRankedMatch(res.match);
          return;
        }
        if (typeof global.toast === 'function') {
          global.toast('Joined the same ranked queue. Waiting for a match…', 3600);
        }
        if (typeof global.openRankedScreen === 'function') global.openRankedScreen();
        return;
      }
    } catch (e) {
      var msg = String((e && e.message) || e || '');
      if (/sign in|auth|401|unauthorized|token required|not logged/i.test(msg)) {
        try { sessionStorage.setItem('tcg_presence_action', token); } catch (e4) {}
        if (typeof global.toast === 'function') {
          global.toast('Sign in with Discord to join that ranked queue.', 4500);
        }
        if (typeof global.showScr === 'function') global.showScr('auth');
        return;
      }
      if (typeof global.toast === 'function') {
        global.toast(msg || 'Could not open that Discord presence link.', 4200);
      }
    }
  }

  async function consumePendingPresenceAction() {
    var token = peekPresenceAction();
    if (!token) return;
    await consumePresenceActionToken(token);
  }

  function init() {
    capturePresenceActionFromUrl();
    bindOptionsUi();
    if (!isAndroidShell()) return;
    bindJoinListener();
    scheduleRefresh(true);
    document.addEventListener('visibilitychange', function () {
      if (document.visibilityState === 'hidden') {
        // Keep presence while backgrounded briefly; clear only on opt-out/logout.
      } else {
        scheduleRefresh(true);
      }
    });
  }

  global.LLTCG_DISCORD_PRESENCE = {
    init: init,
    refresh: function (force) { scheduleRefresh(!!force); },
    clear: clearActivity,
    isAndroidShell: isAndroidShell,
    isOptedIn: isOptedIn,
    setOptedIn: setOptedIn,
    syncOptionsUi: syncOptionsUi,
    consumePending: consumePendingPresenceAction,
    captureFromUrl: capturePresenceActionFromUrl,
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})(typeof window !== 'undefined' ? window : globalThis);
