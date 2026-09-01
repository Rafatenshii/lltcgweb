/**
 * Friend queue pings + unranked room invites (Android FCM + in-app banner).
 */
(function (global) {
  'use strict';

  var LOVECA_FRIEND_QUEUE_KEY = 'tcg_friend_queue';
  var LOVECA_FRIEND_INVITE_KEY = 'tcg_friend_invite';
  var LOVECA_TOURNAMENT_KEY = 'tcg_tournament_open';
  var pollTimer = null;
  var seenInviteIds = {};

  function t(key, fallback, vars) {
    try {
      if (typeof global.t === 'function') {
        var v = global.t(key, vars || {});
        if (v && v !== key) return v;
      }
    } catch (e) { /* ignore */ }
    var out = fallback || key;
    if (vars) {
      Object.keys(vars).forEach(function (k) {
        out = out.replace(new RegExp('\\{' + k + '\\}', 'g'), String(vars[k]));
      });
    }
    return out;
  }

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

  function accountPost(action, body) {
    if (typeof global.accountPost === 'function') return global.accountPost(action, body || {});
    return Promise.reject(new Error('offline'));
  }

  function toast(msg, ms) {
    if (typeof global.toast === 'function') global.toast(msg, ms || 3200);
  }

  function captureFromUrl() {
    try {
      var params = new URLSearchParams(location.search);
      var lane = (params.get('friend_queue') || '').trim().toLowerCase();
      var mode = (params.get('game_mode') || params.get('mode') || '').trim().toLowerCase();
      var invite = (params.get('friend_invite') || '').trim().toLowerCase();
      var tournament = (params.get('tournament') || '').trim().toUpperCase();
      var changed = false;
      if (lane === 'ranked' || lane === 'unranked' || lane === 'casual') {
        if (lane === 'casual') lane = 'unranked';
        sessionStorage.setItem(LOVECA_FRIEND_QUEUE_KEY, JSON.stringify({ lane: lane, game_mode: mode || 'standard' }));
        params.delete('friend_queue');
        params.delete('game_mode');
        params.delete('mode');
        changed = true;
      }
      if (invite && /^[a-f0-9]{16,32}$/.test(invite)) {
        sessionStorage.setItem(LOVECA_FRIEND_INVITE_KEY, invite);
        params.delete('friend_invite');
        changed = true;
      }
      if (tournament && /^[A-Z0-9]{6,16}$/.test(tournament)) {
        sessionStorage.setItem(LOVECA_TOURNAMENT_KEY, tournament);
        params.delete('tournament');
        changed = true;
      }
      if (!changed) return;
      var qs = params.toString();
      history.replaceState({}, document.title, location.pathname + (qs ? '?' + qs : '') + location.hash);
    } catch (e) { /* ignore */ }
  }

  function peekQueue() {
    try {
      var raw = sessionStorage.getItem(LOVECA_FRIEND_QUEUE_KEY);
      if (!raw) return null;
      var data = JSON.parse(raw);
      if (!data || (data.lane !== 'ranked' && data.lane !== 'unranked')) return null;
      return data;
    } catch (e) {
      return null;
    }
  }

  function peekInvite() {
    try {
      return (sessionStorage.getItem(LOVECA_FRIEND_INVITE_KEY) || '').trim();
    } catch (e) {
      return '';
    }
  }

  function applyQueueDeepLink() {
    var data = peekQueue();
    if (!data) return false;
    try { sessionStorage.removeItem(LOVECA_FRIEND_QUEUE_KEY); } catch (e) {}
    var mode = data.game_mode || 'standard';
    if (typeof global.persistGameMode === 'function') global.persistGameMode(mode);
    if (typeof global.syncGameModeSelects === 'function') global.syncGameModeSelects(mode);
    if (data.lane === 'ranked' && typeof global.openRankedScreen === 'function') {
      void global.openRankedScreen();
    } else if (typeof global.showScr === 'function') {
      global.showScr('lobby');
    }
    toast(t('friendPush.queueTapHint', 'Join the queue if you want to play.'), 4200);
    return true;
  }

  function applyTournamentDeepLink() {
    try {
      var tid = (sessionStorage.getItem(LOVECA_TOURNAMENT_KEY) || '').trim().toUpperCase();
      if (!tid || !/^[A-Z0-9]{6,16}$/.test(tid)) return false;
      if (global.TCGTournamentUI && typeof global.TCGTournamentUI.consumeDeepLink === 'function') {
        return !!global.TCGTournamentUI.consumeDeepLink();
      }
      if (global.TCGTournamentUI && typeof global.TCGTournamentUI.open === 'function') {
        sessionStorage.removeItem(LOVECA_TOURNAMENT_KEY);
        global.TCGTournamentUI.open({ view: 'detail', tournamentId: tid });
        return true;
      }
      return false;
    } catch (e) {
      return false;
    }
  }

  async function acceptInviteId(inviteId) {
    if (!inviteId) return;
    var res = await accountPost('match_invite_accept', { invite_id: inviteId });
    if (!res || !res.accepted || !res.room_id) throw new Error('Invite expired');
    var mode = res.game_mode || 'standard';
    if (typeof global.persistGameMode === 'function') global.persistGameMode(mode);
    if (typeof global.syncGameModeSelects === 'function') global.syncGameModeSelects(mode);
    var nameEl = document.getElementById('inp-name');
    var name = (nameEl && nameEl.value.trim()) || (typeof global.defaultPlayerName === 'function' ? global.defaultPlayerName(2) : 'Player 2');
    if (typeof global.ensureLobbyExperimentDeckReady === 'function') {
      await global.ensureLobbyExperimentDeckReady();
    }
    var payload = typeof global.lobbyDeckPayload === 'function' ? global.lobbyDeckPayload() : {};
    var join = await global.apiPost('join_room', Object.assign({ room_id: res.room_id, name: name }, payload));
    global.G = global.G || {};
    global.G.roomId = res.room_id;
    global.G.token = join.player_token;
    global.G.playerId = join.player_id;
    global.G.isCPU = false;
    if (typeof global.captureSyncMeta === 'function') global.captureSyncMeta(join);
    if (typeof global.showScr === 'function') global.showScr('game');
    if (typeof global.startPoll === 'function') global.startPoll();
  }

  async function consumePendingInvite() {
    var id = peekInvite();
    if (!id) return false;
    try { sessionStorage.removeItem(LOVECA_FRIEND_INVITE_KEY); } catch (e) {}
    try {
      await acceptInviteId(id);
      return true;
    } catch (e) {
      toast((e && e.message) || t('friendPush.inviteExpired', 'That invite expired.'), 4200);
      return false;
    }
  }

  function isOwnerAccount() {
    return !!(global.A && global.A.user && global.A.user.is_social_mod);
  }

  function syncPushTestFab() {
    var show = isAndroidShell() && isOwnerAccount();
    ['btn-push-test-hub', 'btn-push-test-auth'].forEach(function (id) {
      var btn = document.getElementById(id);
      if (btn) btn.hidden = !show;
    });
  }

  async function sendTestPush() {
    try {
      var res = await accountPost('push_test', {});
      if (!res || !res.success) {
        toast(t('friendPush.testFailed', 'Test push failed.'), 4000);
        return;
      }
      if (!res.fcm_configured) {
        toast(t('friendPush.testNoFcm', 'FCM is not configured on the server.'), 5000);
        return;
      }
      if (!res.tokens) {
        toast(t('friendPush.testNoToken', 'No push token registered yet — allow notifications and reopen the app.'), 5500);
        return;
      }
      if (res.sent > 0) {
        toast(t('friendPush.testSent', 'Test push sent ({n} device).', { n: res.sent }), 3600);
        return;
      }
      toast(t('friendPush.testSendFailed', 'Token saved but FCM send returned 0 — check server key / Firebase.'), 5500);
    } catch (e) {
      toast((e && e.message) || t('friendPush.testFailed', 'Test push failed.'), 4200);
    }
  }

  function bindPushTestFab() {
    ['btn-push-test-hub', 'btn-push-test-auth'].forEach(function (id) {
      var btn = document.getElementById(id);
      if (!btn || btn._tcgPushTestBound) return;
      btn._tcgPushTestBound = true;
      btn.addEventListener('click', function () { void sendTestPush(); });
    });
  }

    try {
      if (global.Capacitor && global.Capacitor.Plugins && global.Capacitor.Plugins.PushNotifications) {
        return global.Capacitor.Plugins.PushNotifications;
      }
    } catch (e) { /* ignore */ }
    return null;
  }

  function handlePushData(data) {
    if (!data) return;
    var type = String(data.type || '');
    if (type === 'friend_queue') {
      sessionStorage.setItem(LOVECA_FRIEND_QUEUE_KEY, JSON.stringify({
        lane: data.lane === 'ranked' ? 'ranked' : 'unranked',
        game_mode: data.game_mode || 'standard',
      }));
      applyQueueDeepLink();
      return;
    }
    var inviteId = data.invite_id || data.id;
    if (type === 'friend_invite' && inviteId) {
      sessionStorage.setItem(LOVECA_FRIEND_INVITE_KEY, String(inviteId));
      void consumePendingInvite();
      return;
    }
    var tid = String(data.tournament_id || data.tournament || '').trim().toUpperCase();
    if ((type === 'tournament_start' || tid) && /^[A-Z0-9]{6,16}$/.test(tid)) {
      sessionStorage.setItem(LOVECA_TOURNAMENT_KEY, tid);
      applyTournamentDeepLink();
    }
  }

  async function registerNativePush() {
    var Push = getPushPlugin();
    if (!Push || !isAndroidShell()) return;
    try {
      var perm = await Push.requestPermissions();
      if (perm.receive !== 'granted') return;
      /* v1.2 APK has the push plugin but no google-services.json.
         Push.register() → FirebaseMessaging.getInstance() crashes the whole app. */
      if (global.LOVECA_FCM_REGISTER === true) {
        await Push.register();
      }
      Push.addListener('registration', function (token) {
        if (!token || !token.value) return;
        void accountPost('push_register', { token: token.value, platform: 'android' }).catch(function () {});
      });
      Push.addListener('pushNotificationActionPerformed', function (ev) {
        var data = (ev && ev.notification && ev.notification.data) || {};
        handlePushData(data);
      });
      Push.addListener('pushNotificationReceived', function (ev) {
        var data = (ev && ev.data) || (ev && ev.notification && ev.notification.data) || {};
        if (data && data.type === 'friend_invite') renderInviteBanner(data);
      });
      try {
        var App = global.Capacitor.Plugins.App;
        if (App && App.addListener) {
          App.addListener('appUrlOpen', function (ev) {
            var url = (ev && ev.url) || '';
            if (!url) return;
            try {
              var u = new URL(url);
              var lane = (u.searchParams.get('friend_queue') || '').trim();
              var mode = (u.searchParams.get('game_mode') || '').trim();
              var invite = (u.searchParams.get('friend_invite') || '').trim();
              var tournament = (u.searchParams.get('tournament') || '').trim().toUpperCase();
              if (lane === 'ranked' || lane === 'unranked') {
                sessionStorage.setItem(LOVECA_FRIEND_QUEUE_KEY, JSON.stringify({
                  lane: lane,
                  game_mode: mode || 'standard',
                }));
                applyQueueDeepLink();
              }
              if (invite) {
                sessionStorage.setItem(LOVECA_FRIEND_INVITE_KEY, invite);
                void consumePendingInvite();
              }
              if (tournament && /^[A-Z0-9]{6,16}$/.test(tournament)) {
                sessionStorage.setItem(LOVECA_TOURNAMENT_KEY, tournament);
                applyTournamentDeepLink();
              }
            } catch (err) { /* ignore */ }
          });
        }
      } catch (e2) { /* App plugin optional */ }
    } catch (e) { /* plugin missing until APK rebuild */ }
  }

  function bannerRoot() {
    var el = document.getElementById('friend-push-banner');
    if (el) return el;
    el = document.createElement('div');
    el.id = 'friend-push-banner';
    el.className = 'friend-push-banner';
    el.hidden = true;
    document.body.appendChild(el);
    return el;
  }

  var MODE_LABEL = { standard: 'Standard', starters: 'Starters', randomized: 'Randomized', free: 'Free' };

  function renderInviteBanner(inv) {
    var id = inv && (inv.id || inv.invite_id);
    if (!inv || !id) return;
    if (seenInviteIds[id]) return;
    seenInviteIds[id] = true;
    inv.id = id;
    var el = bannerRoot();
    var name = (inv.from && inv.from.username) || inv.from_name || t('friendPush.aFriend', 'A friend');
    var modeRaw = inv.game_mode || 'standard';
    var mode = MODE_LABEL[modeRaw] || modeRaw;
    el.hidden = false;
    el.innerHTML = '';
    var text = document.createElement('p');
    text.textContent = t('friendPush.inviteBody', '{name} has invited you to a {mode} match!', { name: name, mode: mode });
    var row = document.createElement('div');
    row.className = 'friend-push-banner-actions';
    var yes = document.createElement('button');
    yes.type = 'button';
    yes.className = 'btn-grad';
    yes.textContent = t('friendPush.acceptInvite', 'Accept');
    yes.addEventListener('click', function () {
      el.hidden = true;
      void acceptInviteId(inv.id).catch(function (err) {
        toast((err && err.message) || 'Could not join', 3600);
      });
    });
    var no = document.createElement('button');
    no.type = 'button';
    no.className = 'btn-ghost';
    no.textContent = t('friendPush.declineInvite', 'Decline');
    no.addEventListener('click', function () {
      el.hidden = true;
      void accountPost('match_invite_decline', { invite_id: inv.id }).catch(function () {});
    });
    row.appendChild(yes);
    row.appendChild(no);
    el.appendChild(text);
    el.appendChild(row);
  }

  async function pollInvites() {
    if (!global.A || !global.A.user) return;
    try {
      var res = await accountPost('match_invites_pending', {});
      var list = (res && res.invites) || [];
      if (list[0]) renderInviteBanner(list[0]);
    } catch (e) { /* ignore */ }
  }

  function startInvitePoll() {
    if (pollTimer) return;
    void pollInvites();
    pollTimer = setInterval(function () { void pollInvites(); }, 20000);
  }

  function friendListForInvite() {
    var rail = global.A && global.A.friends;
    if (Array.isArray(rail) && rail.length) return rail;
    return [];
  }

  async function loadFriends() {
    var cached = friendListForInvite();
    if (cached.length) return cached;
    var res = await accountPost('social_friends', {});
    return (res && res.friends) || [];
  }

  async function sendInviteTo(friendId) {
    var G = global.G || {};
    var roomId = G.roomId;
    if (!roomId || G.isCPU) {
      if (typeof global.doCreate !== 'function') throw new Error('Cannot create room');
      await global.doCreate();
      roomId = (global.G || {}).roomId;
    }
    if (!roomId) throw new Error('Create a room first');
    var mode = typeof global.currentGameMode === 'function' ? global.currentGameMode() : 'standard';
    var res = await accountPost('match_invite', {
      friend_id: friendId,
      room_id: roomId,
      game_mode: mode,
    });
    var name = (res && res.to && res.to.username) || 'friend';
    toast(t('friendPush.inviteSent', 'Invite sent to {name}', { name: name }), 3200);
  }

  function openInvitePicker() {
    var overlay = document.getElementById('overlay-invite-friend');
    var list = document.getElementById('invite-friend-list');
    if (!overlay || !list) return;
    list.textContent = t('common.loading', 'Loading…');
    overlay.hidden = false;
    void loadFriends().then(function (friends) {
      list.innerHTML = '';
      if (!friends.length) {
        list.textContent = t('friendPush.noFriends', 'Add friends first from the Friends tab.');
        return;
      }
      friends.forEach(function (f) {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'friend-invite-row';
        btn.textContent = f.username || f.id;
        btn.addEventListener('click', function () {
          overlay.hidden = true;
          void sendInviteTo(f.id).catch(function (err) {
            toast((err && err.message) || 'Could not invite', 3600);
          });
        });
        list.appendChild(btn);
      });
    }).catch(function (err) {
      list.textContent = (err && err.message) || 'Could not load friends';
    });
  }

  function bindLobby() {
    var btn = document.getElementById('btn-invite-friend');
    if (btn && !btn._tcgInviteBound) {
      btn._tcgInviteBound = true;
      btn.addEventListener('click', openInvitePicker);
    }
    var close = document.getElementById('btn-invite-friend-close');
    if (close && !close._tcgInviteBound) {
      close._tcgInviteBound = true;
      close.addEventListener('click', function () {
        var o = document.getElementById('overlay-invite-friend');
        if (o) o.hidden = true;
      });
    }
  }

  function consumeAfterLogin() {
    if (applyQueueDeepLink()) return;
    if (applyTournamentDeepLink()) return;
    void consumePendingInvite();
  }

  captureFromUrl();
  document.addEventListener('DOMContentLoaded', function () {
    bindLobby();
    bindPushTestFab();
  });
  bindLobby();
  bindPushTestFab();

  global.LLTCG_FRIEND_PUSH = {
    captureFromUrl: captureFromUrl,
    consumeAfterLogin: consumeAfterLogin,
    registerNativePush: registerNativePush,
    startInvitePoll: startInvitePoll,
    openInvitePicker: openInvitePicker,
    applyQueueDeepLink: applyQueueDeepLink,
    applyTournamentDeepLink: applyTournamentDeepLink,
    syncPushTestFab: syncPushTestFab,
    sendTestPush: sendTestPush,
  };
})(window);
