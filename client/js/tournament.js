/**
 * Tournament Mode v1 UI — full screen like Ranked / Unranked.
 * Gate: TCG_TOURNAMENTS_ENABLED / ?tournaments=1 + server flag.
 */
(function (global) {
  'use strict';

  const state = {
    open: false,
    view: 'list',
    list: [],
    detail: null,
    tickTimer: null,
    loading: false,
    err: '',
    filterMode: '',
    timezone: 'Asia/Tokyo',
    registerTid: null,
    registerOpts: null,
  };

  const TIMEZONE_OPTIONS = [
    { id: 'Asia/Tokyo', label: 'Japan (JST)' },
    { id: 'America/New_York', label: 'US Eastern' },
    { id: 'America/Chicago', label: 'US Central' },
    { id: 'America/Denver', label: 'US Mountain' },
    { id: 'America/Los_Angeles', label: 'US Pacific' },
    { id: 'America/Toronto', label: 'Canada Eastern' },
    { id: 'America/Vancouver', label: 'Canada Pacific' },
    { id: 'Europe/London', label: 'UK (London)' },
    { id: 'Europe/Paris', label: 'Central Europe' },
    { id: 'Europe/Berlin', label: 'Berlin' },
    { id: 'Australia/Sydney', label: 'Sydney' },
    { id: 'Asia/Singapore', label: 'Singapore' },
    { id: 'Asia/Seoul', label: 'Korea (KST)' },
    { id: 'Asia/Shanghai', label: 'China' },
    { id: 'Asia/Hong_Kong', label: 'Hong Kong' },
    { id: 'Asia/Bangkok', label: 'Bangkok' },
    { id: 'Pacific/Auckland', label: 'Auckland' },
    { id: 'UTC', label: 'UTC' },
  ];

  function el(id) {
    return document.getElementById(id);
  }

  function t(key, fallback, vars) {
    const fn = global.LLTCG_I18N && global.LLTCG_I18N.t;
    if (typeof fn === 'function') {
      const v = fn(key, vars);
      if (v && v !== key) return v;
    }
    let out = fallback != null ? String(fallback) : key;
    if (vars && typeof out === 'string') {
      out = out.replace(/\{([^}]+)\}/g, (m, name) =>
        vars[name] != null ? String(vars[name]) : m);
    }
    return out;
  }

  function labelStatus(raw) {
    const k = String(raw || '').toLowerCase();
    const map = {
      open: ['tournament.status.open', 'Open'],
      checkin: ['tournament.status.checkin', 'Check-in'],
      running: ['tournament.status.running', 'Running'],
      finished: ['tournament.status.finished', 'Finished'],
      cancelled: ['tournament.status.cancelled', 'Cancelled'],
    };
    const hit = map[k];
    return hit ? t(hit[0], hit[1]) : String(raw || '');
  }

  function labelMode(raw) {
    const k = String(raw || '').toLowerCase();
    const map = {
      standard: ['tournament.mode.standard', 'Standard'],
      starters: ['tournament.mode.starters', 'Starters'],
      randomized: ['tournament.mode.randomized', 'Randomized'],
      free: ['tournament.mode.free', 'Free'],
    };
    const hit = map[k];
    return hit ? t(hit[0], hit[1]) : String(raw || '');
  }

  function labelFormat(raw) {
    const k = String(raw || 'single_elim').toLowerCase();
    const map = {
      single_elim: ['tournament.format.single_elim', 'Single elimination'],
      double_elim_bracket: ['tournament.format.double_elim_bracket', 'Double elim (Winners/Losers)'],
      double_elim: ['tournament.format.double_elim', 'Double elim (2 lives)'],
      swiss: ['tournament.format.swiss', 'Swiss'],
    };
    const hit = map[k];
    return hit ? t(hit[0], hit[1]) : String(raw || '');
  }

  function labelFog(raw, short) {
    const k = String(raw || 'hidden_hands').toLowerCase();
    if (k === 'open_hands') {
      return short
        ? t('tournament.fog.openHandsShort', 'open hands')
        : t('tournament.fog.openHands', 'Open hands');
    }
    return short
      ? t('tournament.fog.hiddenHandsShort', 'hidden hands')
      : t('tournament.fog.hiddenHands', 'Hidden hands (spectators)');
  }

  function labelRules(raw) {
    return rulesTemplateInfo(raw).label;
  }

  function labelEntrantStatus(raw) {
    const k = String(raw || '').toLowerCase();
    const map = {
      registered: ['tournament.entrant.registered', 'Registered'],
      checked_in: ['tournament.entrant.checked_in', 'Checked in'],
      no_show: ['tournament.entrant.no_show', 'No-show'],
      eliminated: ['tournament.entrant.eliminated', 'Eliminated'],
      active: ['tournament.entrant.active', 'Active'],
    };
    const hit = map[k];
    return hit ? t(hit[0], hit[1]) : String(raw || '');
  }

  function metaSep() {
    return ' ' + t('tournament.card.metaSep', '·') + ' ';
  }

  function applyTournamentStaticI18n() {
    if (global.LLTCG_I18N && typeof global.LLTCG_I18N.applyI18n === 'function') {
      global.LLTCG_I18N.applyI18n(el('screen-tournament'));
    }
    ensureTimezoneSelect();
    const delay = el('tournament-create-delay');
    if (delay) {
      Array.from(delay.options).forEach((opt) => {
        const n = Number(opt.value) || 0;
        if (n === 0) opt.textContent = t('tournament.delay.none', 'None');
        else opt.textContent = t('tournament.delay.secs', '{n} seconds', { n: n });
      });
    }
    syncCreateRulesOptions();
    updateTimezoneHint();
    const note = el('tournament-create-tz-note');
    if (note) {
      note.textContent = t(
        'tournament.createTzNote',
        'Start time uses {tz}.',
        { tz: timezoneLabel(state.timezone) }
      );
    }
    syncStartLabel();
  }

  function clientEnabled() {
    return !!global.TCG_TOURNAMENTS_ENABLED;
  }

  function setHubButtons(enabled) {
    [
      { id: 'btn-hub-tournament', live: 'hub.tournament.subLive', soon: 'hub.tournament.sub' },
      { id: 'btn-auth-tournament', live: 'auth.tournament.subLive', soon: 'auth.tournament.sub' },
    ].forEach((cfg) => {
      const btn = el(cfg.id);
      if (!btn) return;
      if (enabled) {
        btn.disabled = false;
        btn.removeAttribute('aria-disabled');
        btn.classList.add('llc-menu-hover', 'llc-menu-accent-violet');
        btn.classList.remove('llc-menu-accent-gold', 'llc-menu-accent-purple');
        const sub = btn.querySelector('.llc-menu-item-sub');
        if (sub) {
          sub.setAttribute('data-i18n', cfg.live);
          sub.textContent = t(cfg.live, 'Events & brackets');
        }
      } else {
        btn.disabled = true;
        btn.setAttribute('aria-disabled', 'true');
        btn.classList.remove('llc-menu-hover', 'llc-menu-accent-violet', 'llc-menu-accent-gold');
        const sub = btn.querySelector('.llc-menu-item-sub');
        if (sub) {
          sub.setAttribute('data-i18n', cfg.soon);
          sub.textContent = t(cfg.soon, 'Coming Soon');
        }
      }
    });
  }

  async function refreshServerFlag() {
    try {
      const res = await global.accountGet('tournament_enabled');
      return applyEnabled(!!(res && res.enabled));
    } catch (e) {
      setHubButtons(false);
      return false;
    }
  }

  /** Apply server allowlist / me.tournament_enabled (unlocks Hub even when client default is off). */
  function applyEnabled(on) {
    on = !!on;
    if (on) {
      global.TCG_TOURNAMENTS_ENABLED = true;
      setHubButtons(true);
      return true;
    }
    if (!clientEnabled()) {
      setHubButtons(false);
      return false;
    }
    // Local ?tournaments=1 / localStorage override still on — keep UI unlocked for testing.
    setHubButtons(true);
    return true;
  }

  function screenActive() {
    return !!el('screen-tournament')?.classList.contains('active');
  }

  const REMIND_KEY = 'tcg_tournament_remind_dismissed';
  const SESSION_KEY = 'tcg_tournament_ui_session';

  function persistSession() {
    try {
      if (!state.open) {
        sessionStorage.removeItem(SESSION_KEY);
        return;
      }
      const tid = (state.detail && state.detail.tournament && state.detail.tournament.id)
        || state.registerTid
        || null;
      const keepTid = (state.view === 'detail' || state.view === 'register') ? tid : null;
      sessionStorage.setItem(SESSION_KEY, JSON.stringify({
        open: true,
        view: state.view || 'list',
        tournamentId: keepTid,
      }));
    } catch (e) { /* ignore */ }
  }

  function clearSession() {
    try { sessionStorage.removeItem(SESSION_KEY); } catch (e) { /* ignore */ }
  }

  function readSession() {
    try {
      const raw = sessionStorage.getItem(SESSION_KEY);
      if (!raw) return null;
      const parsed = JSON.parse(raw);
      return parsed && parsed.open ? parsed : null;
    } catch (e) {
      return null;
    }
  }

  function setSky(on) {
    document.body.classList.toggle('tcg-tournament-sky', !!on);
  }

  /** @param {{view?: string, tournamentId?: string|null}} [opts] */
  function openScreen(opts) {
    opts = opts || {};
    state.open = true;
    setSky(true);
    ensureTimezoneSelect();
    syncTimezoneFromProfile();
    applyTournamentStaticI18n();
    startTickLoop();
    if (typeof global.showScr === 'function') {
      global.showScr('tournament');
    } else {
      const scr = el('screen-tournament');
      if (scr) {
        document.querySelectorAll('.screen').forEach((s) => {
          s.classList.remove('active');
          s.hidden = true;
          s.setAttribute('aria-hidden', 'true');
        });
        scr.hidden = false;
        scr.classList.add('active');
        scr.setAttribute('aria-hidden', 'false');
      }
    }
    const tid = opts.tournamentId ? String(opts.tournamentId) : '';
    if (tid && (opts.view === 'detail' || opts.view === 'register' || !opts.view)) {
      void openDetail(tid);
      persistSession();
      return;
    }
    if (opts.view === 'create') {
      showView('create');
      persistSession();
      return;
    }
    showView('list');
    loadList();
    persistSession();
  }

  function closeToHub() {
    state.open = false;
    stopTickLoop();
    state.detail = null;
    state.registerTid = null;
    clearSession();
    setSky(false);
    if (typeof global.showScr === 'function') {
      global.showScr('hub');
    }
  }

  /** Resume bulletin/event after refresh once auth + allowlist are ready. */
  function tryResumeSession() {
    const saved = readSession();
    if (!saved) return false;
    if (!global.TCG_TOURNAMENTS_ENABLED && !clientEnabled()) return false;
    openScreen({
      view: saved.view || 'list',
      tournamentId: saved.tournamentId || null,
    });
    return true;
  }

  function showView(name) {
    state.view = name;
    if (name !== 'create') closeDatePicker();
    ['list', 'create', 'detail', 'register'].forEach((v) => {
      const node = el('tournament-view-' + v);
      if (node) node.hidden = v !== name;
    });
    if (state.open) persistSession();
  }

  function setErr(msg) {
    state.err = msg || '';
    const node = el('tournament-err');
    if (node) node.textContent = state.err;
  }

  function fmtWhen(ts) {
    try {
      return new Date((Number(ts) || 0) * 1000).toLocaleString(undefined, {
        timeZone: state.timezone || 'Asia/Tokyo',
        weekday: 'short',
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
        timeZoneName: 'short',
      });
    } catch (e) {
      return String(ts);
    }
  }

  function zonedNowParts() {
    const tz = state.timezone || 'Asia/Tokyo';
    const parts = new Intl.DateTimeFormat('en-CA', {
      timeZone: tz,
      year: 'numeric',
      month: '2-digit',
      day: '2-digit',
      hour: '2-digit',
      minute: '2-digit',
      hourCycle: 'h23',
    }).formatToParts(new Date());
    const map = {};
    parts.forEach((p) => { if (p.type !== 'literal') map[p.type] = p.value; });
    return {
      y: Number(map.year),
      m: Number(map.month) - 1,
      d: Number(map.day),
      hh: Number(map.hour),
      mm: Number(map.minute),
    };
  }

  function getTimeZoneOffsetMs(date, timeZone) {
    const dtf = new Intl.DateTimeFormat('en-US', {
      timeZone,
      year: 'numeric',
      month: '2-digit',
      day: '2-digit',
      hour: '2-digit',
      minute: '2-digit',
      second: '2-digit',
      hourCycle: 'h23',
    });
    const parts = dtf.formatToParts(date);
    const map = {};
    parts.forEach((p) => { if (p.type !== 'literal') map[p.type] = p.value; });
    const asUtc = Date.UTC(
      Number(map.year), Number(map.month) - 1, Number(map.day),
      Number(map.hour), Number(map.minute), Number(map.second)
    );
    return asUtc - date.getTime();
  }

  function zonedWallTimeToUnixSeconds(y, mo, d, hh, mm) {
    const tz = state.timezone || 'Asia/Tokyo';
    let utc = Date.UTC(y, mo, d, hh, mm, 0);
    for (let i = 0; i < 3; i++) {
      const offset = getTimeZoneOffsetMs(new Date(utc), tz);
      utc = Date.UTC(y, mo, d, hh, mm, 0) - offset;
    }
    return Math.floor(utc / 1000);
  }

  function formatUtcOffsetBracket(timeZone) {
    try {
      const ms = getTimeZoneOffsetMs(new Date(), timeZone || 'UTC');
      const totalMins = Math.round(ms / 60000);
      const sign = totalMins >= 0 ? '+' : '-';
      const abs = Math.abs(totalMins);
      const h = Math.floor(abs / 60);
      const m = abs % 60;
      if (m === 0) return '(UTC' + sign + h + ')';
      return '(UTC' + sign + h + ':' + String(m).padStart(2, '0') + ')';
    } catch (e) {
      return '';
    }
  }

  function timezoneLabel(id) {
    const tzId = id || 'UTC';
    const hit = TIMEZONE_OPTIONS.find((z) => z.id === tzId);
    const base = t('tournament.tz.' + tzId, hit ? hit.label : tzId);
    const off = formatUtcOffsetBracket(tzId);
    return off ? (base + ' ' + off) : base;
  }

  function ensureTimezoneSelect() {
    const sel = el('tournament-timezone');
    if (!sel) return;
    const prev = sel.value || state.timezone || 'Asia/Tokyo';
    sel.innerHTML = TIMEZONE_OPTIONS.map((z) => (
      '<option value="' + escapeAttr(z.id) + '">' + escapeHtml(timezoneLabel(z.id)) + '</option>'
    )).join('');
    if (prev && ![...sel.options].some((o) => o.value === prev)) {
      const opt = document.createElement('option');
      opt.value = prev;
      opt.textContent = timezoneLabel(prev);
      sel.appendChild(opt);
    }
    if (prev) sel.value = prev;
    if (sel.dataset.ready !== '1') {
      sel.dataset.ready = '1';
      sel.addEventListener('change', onTimezoneChange);
    }
  }

  function syncTimezoneFromProfile() {
    const fromProfile = global.A && global.A.profile && global.A.profile.preferred_timezone;
    const tz = (fromProfile || state.timezone || 'Asia/Tokyo').trim() || 'Asia/Tokyo';
    state.timezone = tz;
    const sel = el('tournament-timezone');
    if (sel) {
      if (![...sel.options].some((o) => o.value === tz)) {
        const opt = document.createElement('option');
        opt.value = tz;
        opt.textContent = timezoneLabel(tz);
        sel.appendChild(opt);
      }
      sel.value = tz;
    }
    updateTimezoneHint();
    const note = el('tournament-create-tz-note');
    if (note) {
      note.textContent = t(
        'tournament.createTzNote',
        'Start time uses {tz}.',
        { tz: timezoneLabel(tz) }
      );
    }
  }

  function updateTimezoneHint() {
    const hint = el('tournament-tz-hint');
    if (hint) {
      hint.textContent = t(
        'tournament.tzHint',
        'Times shown in {tz}',
        { tz: timezoneLabel(state.timezone) }
      );
    }
  }

  async function onTimezoneChange() {
    const sel = el('tournament-timezone');
    if (!sel) return;
    const tz = sel.value || 'Asia/Tokyo';
    state.timezone = tz;
    updateTimezoneHint();
    const note = el('tournament-create-tz-note');
    if (note) {
      note.textContent = t(
        'tournament.createTzNote',
        'Start time uses {tz}.',
        { tz: timezoneLabel(tz) }
      );
    }
    if (state.view === 'list') renderList();
    if (state.view === 'detail') renderDetail();
    if (datePicker.open) renderCalendarGrid();
    try {
      await global.accountPost('timezone_set', { timezone: tz });
      if (global.A) {
        global.A.profile = global.A.profile || {};
        global.A.profile.preferred_timezone = tz;
      }
    } catch (e) {
      setErr(e.message || String(e));
    }
  }

  async function loadList() {
    setErr('');
    state.loading = true;
    try {
      const body = {};
      if (state.filterMode) body.game_mode = state.filterMode;
      const res = await global.accountPost('tournament_list', body);
      state.list = (res && res.tournaments) || [];
      renderList();
      maybeRemindCheckins(state.list, res && res.server_now);
    } catch (e) {
      setErr(e.message || String(e));
    } finally {
      state.loading = false;
    }
  }

  function readRemindDismissed() {
    try {
      const raw = localStorage.getItem(REMIND_KEY);
      const parsed = raw ? JSON.parse(raw) : {};
      return parsed && typeof parsed === 'object' ? parsed : {};
    } catch (e) {
      return {};
    }
  }

  function writeRemindDismissed(map) {
    try {
      localStorage.setItem(REMIND_KEY, JSON.stringify(map || {}));
    } catch (e) { /* ignore */ }
  }

  function maybeRemindCheckins(list, serverNow) {
    const now = Number(serverNow) || Math.floor(Date.now() / 1000);
    const dismissed = readRemindDismissed();
    (list || []).forEach((row) => {
      const id = String(row.id || '');
      if (!id || dismissed[id]) return;
      const opens = Number(row.checkin_opens_at) || (
        Number(row.start_at) - (Number(row.checkin_mins) || 10) * 60
      );
      const inWindow = (opens - now) <= 15 * 60 && now < Number(row.start_at);
      const justOpen = row.status === 'checkin' && (now - opens) < 120;
      if (!inWindow && !justOpen) return;
      const title = row.title || id;
      const msg = (row.status === 'checkin')
        ? t('tournament.notify.checkinOpen', 'Check-in open: {title}', { title: title })
        : t('tournament.notify.checkinSoon', 'Check-in soon: {title}', { title: title });
      if (typeof global.toast === 'function') global.toast(msg, 4500);
      try {
        if (typeof Notification !== 'undefined' && Notification.permission === 'granted') {
          new Notification(t('tournament.notify.title', 'LLTCG Tournament'), { body: msg });
        } else if (typeof Notification !== 'undefined' && Notification.permission === 'default') {
          Notification.requestPermission().catch(function () {});
        }
      } catch (e) { /* ignore */ }
      dismissed[id] = now;
      writeRemindDismissed(dismissed);
    });
  }

  function renderList() {
    const root = el('tournament-list');
    if (!root) return;
    if (!state.list.length) {
      root.innerHTML = '<p class="tournament-muted">'
        + escapeHtml(t('tournament.listEmpty', 'No open tournaments yet. Create one to get started.'))
        + '</p>';
      return;
    }
    const sep = metaSep();
    root.innerHTML = state.list.map((row) => {
      const fee = Number(row.entry_fee_coins) || 0;
      const count = Number(row.entrant_count) || 0;
      const max = Number(row.max_players) || 0;
      const specs = Number(row.spectator_count) || 0;
      const fog = (row.settings && row.settings.fog) || 'hidden_hands';
      const delay = Number((row.settings && row.settings.stream_delay_secs) || 0);
      const rulesKey = (row.settings && row.settings.rules_template) || 'standard';
      return (
        '<button type="button" class="tournament-card" data-tid="' + escapeAttr(row.id) + '">'
        + '<div class="tournament-card-title">' + escapeHtml(row.title) + '</div>'
        + '<div class="tournament-card-meta">'
        + escapeHtml(labelStatus(row.status)) + sep + escapeHtml(labelMode(row.game_mode))
        + sep + count + '/' + max
        + sep + escapeHtml(t('tournament.card.fee', 'fee {n}', { n: fee }))
        + (specs ? (sep + escapeHtml(t('tournament.card.watching', '{n} watching', { n: specs }))) : '')
        + sep + escapeHtml(t('tournament.card.starts', 'starts {when}', { when: fmtWhen(row.start_at) }))
        + '</div>'
        + '<div class="tournament-card-meta tournament-card-settings">'
        + escapeHtml(labelRules(rulesKey))
        + sep + escapeHtml(t('tournament.card.fog', 'fog {fog}', { fog: labelFog(fog, true) }))
        + sep + escapeHtml(t('tournament.card.delay', 'delay {n}s', { n: delay }))
        + '</div></button>'
      );
    }).join('');
    root.querySelectorAll('[data-tid]').forEach((btn) => {
      btn.addEventListener('click', () => openDetail(btn.getAttribute('data-tid')));
    });
  }

  async function openDetail(id) {
    setErr('');
    showView('detail');
    try {
      const res = await global.accountPost('tournament_get', { tournament_id: id });
      if (res && res.tournament && res.tournament.status === 'cancelled') {
        returnToBulletin(t('tournament.err.cancelled', 'This tournament was cancelled.'));
        return;
      }
      state.detail = res;
      renderDetail();
      persistSession();
    } catch (e) {
      const msg = e.message || String(e);
      if (/not found/i.test(msg) || /404/.test(msg)) {
        returnToBulletin(t('tournament.err.unavailable', 'That tournament is no longer available.'));
        return;
      }
      setErr(msg);
    }
  }

  function returnToBulletin(msg) {
    state.detail = null;
    state.registerTid = null;
    showView('list');
    void loadList();
    persistSession();
    if (msg) {
      if (typeof global.toast === 'function') global.toast(msg, 3600);
      else setErr(msg);
    }
  }

  function nameFor(did) {
    const ents = (state.detail && state.detail.entrants) || [];
    const hit = ents.find((e) => e.discord_id === did);
    return (hit && hit.username) || did || '—';
  }

  function discordDefaultAvatar(userId) {
    let defaultIdx = 0;
    const uid = String(userId || '');
    if (/^\d+$/.test(uid)) {
      try { defaultIdx = Number((BigInt(uid) >> 22n) % 6n); } catch (e) { defaultIdx = 0; }
    }
    return 'https://cdn.discordapp.com/embed/avatars/' + defaultIdx + '.png';
  }

  function avatarHtml(userId, avatarUrl, username) {
    const custom = String(avatarUrl || '').trim();
    const fallback = discordDefaultAvatar(userId);
    const src = custom || fallback;
    const name = String(username || '?');
    const initial = (name.trim().charAt(0) || '?').toUpperCase();
    return (
      '<img class="tournament-avatar" alt="" decoding="async" referrerpolicy="no-referrer"'
      + ' src="' + escapeAttr(src) + '"'
      + ' data-fallback="' + escapeAttr(fallback) + '"'
      + ' data-initial="' + escapeAttr(initial) + '">'
    );
  }

  function onAvatarError(img) {
    if (!img) return;
    const fb = img.getAttribute('data-fallback') || '';
    const cur = String(img.currentSrc || img.src || '');
    if (fb && img.dataset.avatarFallback !== '1' && cur !== fb) {
      img.dataset.avatarFallback = '1';
      img.src = fb;
      return;
    }
    const ph = document.createElement('span');
    ph.className = 'tournament-avatar tournament-avatar-fallback';
    ph.setAttribute('aria-hidden', 'true');
    ph.textContent = img.dataset.initial || '?';
    img.replaceWith(ph);
  }

  function personChipHtml(userId, username, avatarUrl) {
    return (
      '<span class="tournament-person">'
      + avatarHtml(userId, avatarUrl, username)
      + '<span class="tournament-person-name">'
      + escapeHtml(username || t('tournament.person.playerFallback', 'Player'))
      + '</span>'
      + '</span>'
    );
  }

  function actionTip(act) {
    const map = {
      register: ['tournament.action.registerTip', 'Lock in a deck and enter this event. Pays the entry fee into the prize pool if one is set.'],
      checkin: ['tournament.action.checkinTip', 'Confirm you are present before the bracket starts. Missing check-in marks you as a no-show.'],
      unregister: ['tournament.action.unregisterTip', 'Leave the event before it starts and refund your entry fee.'],
      deposit: ['tournament.action.depositTip', 'Add Coins from your balance to this event’s prize pool (host only).'],
      cancel: ['tournament.action.cancelTip', 'Cancel the tournament and refund entry fees plus remaining host prize deposits.'],
      tick: ['tournament.action.tickTip', 'Refresh this event and advance server timers (check-in window, bracket, room seeding).'],
      join: ['tournament.action.joinTip', 'Enter your ready tournament match room when your bracket game is available.'],
      'spectate-list': ['tournament.action.spectateListTip', 'Browse and watch live matches from this tournament as a spectator.'],
    };
    const hit = map[act];
    return hit ? t(hit[0], hit[1]) : '';
  }

  function actionButtonHtml(cls, act, label) {
    const tip = actionTip(act);
    return (
      '<button type="button" class="' + cls + '" data-act="' + escapeAttr(act) + '"'
      + (tip ? ' title="' + escapeAttr(tip) + '"' : '')
      + '>' + escapeHtml(label) + '</button>'
    );
  }

  function renderDetail() {
    const res = state.detail;
    if (!res || !res.tournament) return;
    const trow = res.tournament;
    const sep = metaSep();
    const head = el('tournament-detail-head');
    if (head) {
      const fog = (trow.settings && trow.settings.fog) || 'hidden_hands';
      const rulesKey = (trow.settings && trow.settings.rules_template) || 'standard';
      const format = (trow.settings && trow.settings.format) || 'single_elim';
      const bestOf = Number((trow.settings && trow.settings.best_of) || 1);
      const delay = Number((trow.settings && trow.settings.stream_delay_secs) || 0);
      head.innerHTML =
        '<h3 class="tournament-subhead">' + escapeHtml(trow.title) + '</h3>'
        + '<div class="tournament-host-row">'
        + '<span class="tournament-muted">' + escapeHtml(t('tournament.detail.host', 'Host')) + '</span> '
        + personChipHtml(
          res.host_discord_id,
          res.host_username || t('tournament.detail.hostFallback', 'Host'),
          res.host_avatar_url
        )
        + '</div>'
        + '<p class="tournament-muted">'
        + escapeHtml(labelStatus(trow.status))
        + sep + escapeHtml(t('tournament.detail.prize', 'prize {n}', { n: Number(trow.prize_pool_coins) || 0 }))
        + sep + escapeHtml(t('tournament.detail.watching', 'watching {n}', { n: Number(trow.spectator_count) || 0 }))
        + sep + escapeHtml(t('tournament.detail.starts', 'starts {when}', { when: fmtWhen(trow.start_at) }))
        + '</p>'
        + '<p class="tournament-muted">'
        + escapeHtml(t('tournament.detail.mode', 'mode {mode}', { mode: labelMode(trow.game_mode || 'standard') }))
        + sep + escapeHtml(t('tournament.detail.rules', 'rules {rules}', { rules: labelRules(rulesKey) }))
        + sep + escapeHtml(t('tournament.detail.fog', 'fog {fog}', { fog: labelFog(fog, true) }))
        + sep + escapeHtml(t('tournament.detail.streamDelay', 'stream delay {n}s', { n: delay }))
        + sep + escapeHtml(labelFormat(format))
        + sep + escapeHtml(t('tournament.detail.bestOfShort', 'Bo{n}', { n: bestOf }))
        + '</p>'
        + '<p class="tournament-rules-blurb">'
        + escapeHtml(rulesTemplateInfo(rulesKey).help)
        + '</p>';
    }
    wireAvatarFallbacks(head);
    const actions = el('tournament-detail-actions');
    if (actions) {
      const me = res.me;
      const isHost = !!res.is_host;
      const bits = [];
      if (!me && (trow.status === 'open' || trow.status === 'checkin')) {
        bits.push(actionButtonHtml('btn-grad', 'register', t('tournament.action.register', 'Register')));
      }
      if (me && (trow.status === 'open' || trow.status === 'checkin') && me.status === 'registered') {
        bits.push(actionButtonHtml('btn-grad', 'checkin', t('tournament.action.checkin', 'Check in')));
        bits.push(actionButtonHtml('btn-ghost', 'unregister', t('tournament.action.unregister', 'Unregister')));
      }
      if (me && me.status === 'checked_in') {
        bits.push(
          '<span class="tournament-muted" title="'
          + escapeAttr(t('tournament.action.checkedInTip', 'You are checked in and waiting for the bracket to start.'))
          + '">' + escapeHtml(t('tournament.action.checkedIn', 'Checked in')) + '</span>'
        );
      }
      if (isHost && (trow.status === 'open' || trow.status === 'checkin' || trow.status === 'running')) {
        bits.push(actionButtonHtml('btn-out', 'deposit', t('tournament.action.deposit', 'Deposit prize')));
        bits.push(actionButtonHtml('btn-ghost', 'cancel', t('tournament.action.cancel', 'Cancel (refund)')));
      }
      bits.push(actionButtonHtml('btn-out', 'tick', t('tournament.action.tick', 'Refresh / tick')));
      bits.push(actionButtonHtml('btn-grad', 'join', t('tournament.action.join', 'Join my match')));
      if (trow.status === 'running') {
        bits.push(actionButtonHtml('btn-out', 'spectate-list', t('tournament.action.spectateList', 'Spectate matches')));
      }
      actions.innerHTML = bits.join('');
      actions.querySelectorAll('[data-act]').forEach((btn) => {
        btn.addEventListener('click', () => onDetailAction(btn.getAttribute('data-act')));
      });
    }
    const list = el('tournament-entrants');
    if (list) {
      const ents = res.entrants || [];
      list.innerHTML = ents.map((e) => (
        '<li>'
        + '<span class="tournament-entrant-main">'
        + personChipHtml(e.discord_id, e.username || e.discord_id, e.avatar_url)
        + (e.seed != null ? '<span class="tournament-seed">#' + e.seed + '</span>' : '')
        + '</span>'
        + '<span class="tournament-entrant-status">' + escapeHtml(labelEntrantStatus(e.status)) + '</span>'
        + '</li>'
      )).join('') || '<li class="tournament-muted">' + escapeHtml(t('tournament.entrantsEmpty', 'No entrants')) + '</li>';
      wireAvatarFallbacks(list);
    }
    renderBracket(res.matches || [], res.bracket_preview || []);
    renderStandings(res.standings || []);
  }

  function wireAvatarFallbacks(root) {
    if (!root) return;
    root.querySelectorAll('img.tournament-avatar').forEach((img) => {
      if (img.dataset.errBound) return;
      img.dataset.errBound = '1';
      img.addEventListener('error', () => onAvatarError(img));
    });
  }

  function entrantById(did) {
    const ents = (state.detail && state.detail.entrants) || [];
    return ents.find((e) => e.discord_id === did) || null;
  }

  function seatHtml(discordId, opts) {
    opts = opts || {};
    const placeholder = !!opts.placeholder;
    const isBye = !!opts.bye;
    const isWinner = !!opts.winner;
    if (placeholder) {
      return '<div class="tournament-match-seat tournament-match-seat--empty"><span class="tournament-match-seat-name">'
        + escapeHtml(t('tournament.bracket.tbd', 'TBD')) + '</span></div>';
    }
    if (isBye) {
      return '<div class="tournament-match-seat tournament-match-seat--bye"><span class="tournament-match-seat-name">'
        + escapeHtml(t('tournament.bracket.bye', 'Bye')) + '</span></div>';
    }
    if (!discordId) {
      return '<div class="tournament-match-seat tournament-match-seat--empty"><span class="tournament-match-seat-name">'
        + escapeHtml(t('tournament.bracket.waiting', 'Waiting…')) + '</span></div>';
    }
    const ent = entrantById(discordId);
    const name = (ent && ent.username) || nameFor(discordId);
    const avatar = ent ? ent.avatar_url : null;
    return (
      '<div class="tournament-match-seat' + (isWinner ? ' tournament-match-seat--winner' : '') + '">'
      + personChipHtml(discordId, name, avatar)
      + '</div>'
    );
  }

  function formatCaption(format) {
    const f = String(format || 'single_elim');
    if (f === 'swiss') return t('tournament.formatCaption.swiss', 'Swiss rounds');
    if (f === 'double_elim') return t('tournament.formatCaption.doubleElimLives', 'Double elim (2 lives)');
    if (f === 'double_elim_bracket') {
      return t('tournament.formatCaption.doubleElimBracket', 'Double elim (Winners/Losers)');
    }
    return t('tournament.formatCaption.singleElim', 'Single elimination');
  }

  function roundLabel(side, round, slotCount, isPreview) {
    const s = String(side || 'winners');
    const r = Number(round) || 1;
    if (s === 'swiss') return t('tournament.round.swiss', 'Swiss · Round {n}', { n: r });
    if (s === 'losers') {
      return slotCount === 1
        ? t('tournament.round.losersFinal', 'Losers Final')
        : t('tournament.round.losers', 'Losers · R{n}', { n: r });
    }
    if (s === 'grand_final') {
      return r >= 2
        ? t('tournament.round.grandFinalReset', 'Grand Final (Reset)')
        : t('tournament.round.grandFinal', 'Grand Final');
    }
    if (slotCount === 1) return t('tournament.round.winnersFinal', 'Winners Final');
    if (slotCount === 2) return t('tournament.round.semifinals', 'Semifinals');
    return t('tournament.round.roundOf', 'Round of {n}', { n: slotCount * 2 });
  }

  function statusLabel(m) {
    const st = String(m.status || 'pending');
    if (st === 'live') return t('tournament.matchStatus.live', 'Live');
    if (st === 'ready') return t('tournament.matchStatus.ready', 'Ready');
    if (st === 'done') return t('tournament.matchStatus.done', 'Done');
    if (st === 'pending') return t('tournament.matchStatus.pending', 'Upcoming');
    return st;
  }

  function bracketSideTitle(side) {
    const s = String(side || 'winners');
    if (s === 'losers') return t('tournament.bracket.sideLosers', 'Losers bracket');
    if (s === 'grand_final') return t('tournament.bracket.sideGrandFinal', 'Grand Final');
    if (s === 'swiss') return t('tournament.bracket.sideSwiss', 'Swiss rounds');
    return t('tournament.bracket.sideWinners', 'Winners bracket');
  }

  function bracketUsesTreeConnectors(format) {
    const f = String(format || 'single_elim');
    return f === 'single_elim' || f === 'double_elim_bracket';
  }

  const BRACKET_ORIENT_KEY = 'tcg_tournament_bracket_orient';

  function getBracketOrient() {
    try {
      return sessionStorage.getItem(BRACKET_ORIENT_KEY) === 'vertical' ? 'vertical' : 'horizontal';
    } catch (e) {
      return 'horizontal';
    }
  }

  function setBracketOrient(orient) {
    const next = orient === 'vertical' ? 'vertical' : 'horizontal';
    try { sessionStorage.setItem(BRACKET_ORIENT_KEY, next); } catch (e) { /* ignore */ }
    return next;
  }

  function matchCardHtml(m, opts) {
    opts = opts || {};
    const isPreview = !!opts.isPreview;
    const bestOf = Number(opts.bestOf) || 1;
    const status = String(m.status || 'pending');
    const canSpec = !isPreview && m.room_id && (status === 'ready' || status === 'live');
    const p1w = Number(m.p1_wins) || 0;
    const p2w = Number(m.p2_wins) || 0;
    const showSeries = !isPreview && (Number(m.best_of) || bestOf) === 3;
    const side = String(m.bracket_side || opts.side || 'winners');
    const round = Number(m.round) || 1;
    const slot = Number(m.bracket_slot) || 0;
    let html = '<article class="tournament-match-card tournament-match-card--' + escapeAttr(status)
      + (isPreview ? ' tournament-match-card--skeleton' : '') + '"'
      + ' data-side="' + escapeAttr(side) + '"'
      + ' data-round="' + escapeAttr(String(round)) + '"'
      + ' data-slot="' + escapeAttr(String(slot)) + '">';
    html += '<div class="tournament-match-card-head">';
    html += '<span class="tournament-match-status">'
      + escapeHtml(isPreview ? t('tournament.bracket.slot', 'Slot') : statusLabel(m))
      + '</span>';
    if (showSeries) {
      html += '<span class="tournament-match-series">' + p1w + '–' + p2w + '</span>';
    }
    html += '</div>';
    html += '<div class="tournament-match-seats">';
    if (isPreview) {
      html += seatHtml(null, { placeholder: true });
      html += seatHtml(null, { placeholder: true });
    } else {
      const bye = status === 'done' && !m.p2_discord_id;
      html += seatHtml(m.p1_discord_id, {
        winner: m.winner_discord_id && m.winner_discord_id === m.p1_discord_id,
        bye: false,
      });
      html += seatHtml(m.p2_discord_id, {
        winner: m.winner_discord_id && m.winner_discord_id === m.p2_discord_id,
        bye: bye,
      });
    }
    html += '</div>';
    html += '<div class="tournament-match-card-foot">';
    if (canSpec) {
      html += '<button type="button" class="tournament-spec-btn" data-spec="' + escapeAttr(m.room_id) + '"'
        + ' title="' + escapeAttr(t('tournament.bracket.spectateTip', 'Watch this match as a spectator (non-players welcome)')) + '">'
        + '<span class="tournament-spec-btn-icon" aria-hidden="true">👁</span> '
        + escapeHtml(t('tournament.bracket.spectate', 'Spectate')) + '</button>';
    } else if (!isPreview && status === 'done' && m.winner_discord_id) {
      html += '<span class="tournament-muted">'
        + escapeHtml(t('tournament.bracket.winner', 'Winner: {name}', { name: nameFor(m.winner_discord_id) }))
        + '</span>';
    } else if (isPreview) {
      html += '<span class="tournament-muted">'
        + escapeHtml(t('tournament.bracket.namesLock', 'Names lock in at bracket start'))
        + '</span>';
    } else {
      html += '<span class="tournament-muted">' + escapeHtml(statusLabel(m)) + '</span>';
    }
    html += '</div></article>';
    return html;
  }

  function syncBracketTreeHeights(root) {
    root.querySelectorAll('.tournament-bracket-side--tree').forEach((sideEl) => {
      const lists = [...sideEl.querySelectorAll('.tournament-bracket-round-list')];
      if (lists.length < 2) return;
      lists.forEach((list) => { list.style.minHeight = ''; });
      const board = root.querySelector('.tournament-bracket-board');
      const vertical = board && board.dataset.orient === 'vertical';
      if (vertical) {
        const firstW = lists[0].scrollWidth;
        lists.forEach((list) => { list.style.minWidth = Math.max(firstW, 280) + 'px'; });
      } else {
        const firstH = lists[0].scrollHeight;
        lists.forEach((list) => { list.style.minHeight = firstH + 'px'; });
      }
    });
  }

  function layoutBracketConnectors(root) {
    const canvas = root.querySelector('.tournament-bracket-canvas');
    const svg = root.querySelector('svg.tournament-bracket-connectors');
    if (!canvas || !svg) return;
    const board = root.querySelector('.tournament-bracket-board');
    const vertical = !!(board && board.dataset.orient === 'vertical');
    const cRect = canvas.getBoundingClientRect();
    const w = Math.max(canvas.scrollWidth, canvas.clientWidth, 1);
    const h = Math.max(canvas.scrollHeight, canvas.clientHeight, 1);
    svg.setAttribute('width', String(w));
    svg.setAttribute('height', String(h));
    svg.setAttribute('viewBox', '0 0 ' + w + ' ' + h);
    svg.innerHTML = '';

    const ns = 'http://www.w3.org/2000/svg';
    const stroke = 'rgba(186, 228, 255, 0.55)';
    const strokeAccent = 'rgba(255, 107, 168, 0.55)';

    root.querySelectorAll('.tournament-bracket-side--tree').forEach((sideEl, sideIdx) => {
      const rounds = [...sideEl.querySelectorAll('.tournament-bracket-round')];
      for (let ri = 0; ri < rounds.length - 1; ri++) {
        const fromCards = [...rounds[ri].querySelectorAll('.tournament-match-card')];
        const toCards = [...rounds[ri + 1].querySelectorAll('.tournament-match-card')];
        toCards.forEach((parent, pi) => {
          const c0 = fromCards[pi * 2];
          const c1 = fromCards[pi * 2 + 1];
          if (!c0 && !c1) return;
          const pRect = parent.getBoundingClientRect();
          const kids = [c0, c1].filter(Boolean);
          if (!kids.length) return;
          const color = sideIdx % 2 === 0 ? stroke : strokeAccent;
          kids.forEach((child) => {
            const a = child.getBoundingClientRect();
            let x1; let y1; let x2; let y2; let mx; let my;
            if (vertical) {
              x1 = a.left + a.width / 2 - cRect.left;
              y1 = a.bottom - cRect.top;
              x2 = pRect.left + pRect.width / 2 - cRect.left;
              y2 = pRect.top - cRect.top;
              my = (y1 + y2) / 2;
              const path = document.createElementNS(ns, 'path');
              path.setAttribute('d',
                'M ' + x1 + ' ' + y1
                + ' L ' + x1 + ' ' + my
                + ' L ' + x2 + ' ' + my
                + ' L ' + x2 + ' ' + y2);
              path.setAttribute('fill', 'none');
              path.setAttribute('stroke', color);
              path.setAttribute('stroke-width', '2');
              path.setAttribute('stroke-linecap', 'round');
              path.setAttribute('stroke-linejoin', 'round');
              svg.appendChild(path);
            } else {
              x1 = a.right - cRect.left;
              y1 = a.top + a.height / 2 - cRect.top;
              x2 = pRect.left - cRect.left;
              y2 = pRect.top + pRect.height / 2 - cRect.top;
              mx = (x1 + x2) / 2;
              const path = document.createElementNS(ns, 'path');
              path.setAttribute('d',
                'M ' + x1 + ' ' + y1
                + ' L ' + mx + ' ' + y1
                + ' L ' + mx + ' ' + y2
                + ' L ' + x2 + ' ' + y2);
              path.setAttribute('fill', 'none');
              path.setAttribute('stroke', color);
              path.setAttribute('stroke-width', '2');
              path.setAttribute('stroke-linecap', 'round');
              path.setAttribute('stroke-linejoin', 'round');
              svg.appendChild(path);
            }
          });
        });
      }
    });
  }

  let _bracketResizeObs = null;

  function bindBracketLayout(root) {
    const run = () => {
      syncBracketTreeHeights(root);
      layoutBracketConnectors(root);
    };
    requestAnimationFrame(() => requestAnimationFrame(run));
    if (_bracketResizeObs) {
      try { _bracketResizeObs.disconnect(); } catch (e) { /* ignore */ }
    }
    if (typeof ResizeObserver !== 'undefined') {
      _bracketResizeObs = new ResizeObserver(() => run());
      const canvas = root.querySelector('.tournament-bracket-canvas');
      if (canvas) _bracketResizeObs.observe(canvas);
      root.querySelectorAll('.tournament-match-card').forEach((card) => _bracketResizeObs.observe(card));
    }
    const scroll = root.querySelector('.tournament-bracket-scroll');
    if (scroll && !scroll.dataset.connScrollBound) {
      scroll.dataset.connScrollBound = '1';
      scroll.addEventListener('scroll', () => layoutBracketConnectors(root), { passive: true });
    }
  }

  function renderStandings(rows) {
    let box = el('tournament-standings');
    if (!box) {
      const bracket = el('tournament-bracket');
      if (!bracket || !bracket.parentNode) return;
      box = document.createElement('div');
      box.id = 'tournament-standings';
      box.className = 'tournament-standings';
      bracket.parentNode.insertBefore(box, bracket);
    }
    const format = (state.detail && state.detail.tournament && state.detail.tournament.settings
      && state.detail.tournament.settings.format) || 'single_elim';
    if (!rows.length || (format === 'single_elim' && !(state.detail && (state.detail.matches || []).length))) {
      box.hidden = true;
      box.innerHTML = '';
      return;
    }
    if (format === 'single_elim') {
      box.hidden = true;
      box.innerHTML = '';
      return;
    }
    box.hidden = false;
    box.innerHTML =
      '<h4 class="tournament-standings-title">' + escapeHtml(t('tournament.standingsHeading', 'Standings')) + '</h4>'
      + '<ol class="tournament-standings-list">'
      + rows.map((r, i) => (
        '<li><span class="tournament-standings-rank">#' + (i + 1) + '</span> '
        + escapeHtml(r.username || r.discord_id)
        + ' <span class="tournament-muted">'
        + escapeHtml(t('tournament.standings.record', '{wins}W–{losses}L', {
          wins: r.wins,
          losses: r.losses,
        }))
        + (r.status ? (' · ' + escapeHtml(labelEntrantStatus(r.status))) : '')
        + '</span></li>'
      )).join('')
      + '</ol>';
  }

  function renderBracket(matches, preview) {
    const root = el('tournament-bracket');
    if (!root) return;
    const trow = state.detail && state.detail.tournament;
    const format = (trow && trow.settings && trow.settings.format) || 'single_elim';
    const bestOf = (trow && trow.settings && trow.settings.best_of) || 1;
    const live = Array.isArray(matches) ? matches : [];
    const prev = Array.isArray(preview) ? preview : [];
    const isPreview = live.length === 0;
    const source = isPreview ? prev : live;
    const useTree = bracketUsesTreeConnectors(format);
    const orient = getBracketOrient();

    if (!source.length) {
      root.innerHTML = '<p class="tournament-muted">'
        + escapeHtml(t('tournament.bracket.empty', 'Bracket layout appears once max players / format is set.'))
        + '</p>';
      return;
    }

    const groups = {};
    source.forEach((m) => {
      const side = String(m.bracket_side || (format === 'swiss' ? 'swiss' : 'winners'));
      const r = Number(m.round) || 1;
      const key = side + ':' + r;
      if (!groups[key]) {
        groups[key] = { side: side, round: r, label: m.label || '', items: [] };
      }
      groups[key].items.push(m);
    });
    Object.keys(groups).forEach((k) => {
      groups[k].items.sort((a, b) => (a.bracket_slot || 0) - (b.bracket_slot || 0));
    });

    const sideOrder = { winners: 0, swiss: 0, losers: 1, grand_final: 2 };
    const sides = [];
    const sideMap = {};
    Object.keys(groups).forEach((k) => {
      const g = groups[k];
      if (!sideMap[g.side]) {
        sideMap[g.side] = { side: g.side, rounds: [] };
        sides.push(sideMap[g.side]);
      }
      sideMap[g.side].rounds.push(g);
    });
    sides.sort((a, b) => {
      const sa = sideOrder[a.side] != null ? sideOrder[a.side] : 9;
      const sb = sideOrder[b.side] != null ? sideOrder[b.side] : 9;
      return sa - sb;
    });
    sides.forEach((s) => s.rounds.sort((a, b) => a.round - b.round));

    const sep = metaSep();
    const showOrientToggle = useTree || sides.some((s) => s.rounds.length > 1);
    let html = '<div class="tournament-bracket-board'
      + (isPreview ? ' tournament-bracket-board--preview' : '')
      + (useTree ? ' tournament-bracket-board--tree' : ' tournament-bracket-board--columns')
      + '" data-orient="' + escapeAttr(orient) + '">';

    html += '<div class="tournament-bracket-toolbar">';
    html += '<div class="tournament-bracket-caption">'
      + escapeHtml(formatCaption(format))
      + sep + escapeHtml(
        bestOf === 3
          ? t('tournament.bestOf.3', 'Best of 3')
          : t('tournament.bestOf.1', 'Best of 1')
      )
      + (isPreview
        ? (sep + escapeHtml(t('tournament.bracket.previewSuffix', 'Preview (names fill in after check-in)')))
        : '')
      + '</div>';
    if (showOrientToggle) {
      const nextOrient = orient === 'vertical' ? 'horizontal' : 'vertical';
      const btnLabel = orient === 'vertical'
        ? t('tournament.bracket.orientHorizontal', 'Horizontal')
        : t('tournament.bracket.orientVertical', 'Vertical');
      html += '<button type="button" class="btn-ghost tournament-bracket-orient-btn" data-orient-toggle="'
        + escapeAttr(nextOrient) + '" title="'
        + escapeAttr(t('tournament.bracket.orientTip', 'Switch bracket layout')) + '">'
        + escapeHtml(btnLabel)
        + '</button>';
    }
    html += '</div>';

    html += '<div class="tournament-bracket-scroll">';
    html += '<div class="tournament-bracket-canvas">';
    if (useTree) {
      html += '<svg class="tournament-bracket-connectors" aria-hidden="true"></svg>';
    }

    sides.forEach((sideBlock) => {
      const treeClass = useTree ? ' tournament-bracket-side--tree' : '';
      html += '<section class="tournament-bracket-side' + treeClass + '" data-side="'
        + escapeAttr(sideBlock.side) + '">';
      if (sides.length > 1 || format === 'double_elim_bracket') {
        html += '<h5 class="tournament-bracket-side-title">'
          + escapeHtml(bracketSideTitle(sideBlock.side)) + '</h5>';
      }
      html += '<div class="tournament-bracket-rounds">';
      sideBlock.rounds.forEach((g) => {
        const label = g.label || roundLabel(g.side, g.round, g.items.length, isPreview);
        html += '<div class="tournament-bracket-round" data-side="' + escapeAttr(g.side)
          + '" data-round="' + escapeAttr(String(g.round)) + '">';
        html += '<div class="tournament-bracket-round-label">' + escapeHtml(label) + '</div>';
        html += '<div class="tournament-bracket-round-list">';
        g.items.forEach((m) => {
          html += matchCardHtml(m, { isPreview: isPreview, bestOf: bestOf, side: g.side });
        });
        html += '</div></div>';
      });
      html += '</div></section>';
    });

    html += '</div></div></div>';
    root.innerHTML = html;

    root.querySelectorAll('[data-spec]').forEach((node) => {
      node.addEventListener('click', () => spectateRoom(node.getAttribute('data-spec')));
    });
    const orientBtn = root.querySelector('[data-orient-toggle]');
    if (orientBtn) {
      orientBtn.addEventListener('click', () => {
        setBracketOrient(orientBtn.getAttribute('data-orient-toggle'));
        renderBracket(matches, preview);
      });
    }
    wireAvatarFallbacks(root);
    if (useTree) bindBracketLayout(root);
    else {
      // Still equalize column rhythm a bit for swiss-style boards.
      requestAnimationFrame(() => syncBracketTreeHeights(root));
    }
  }

  async function onDetailAction(act) {
    const tid = state.detail && state.detail.tournament && state.detail.tournament.id;
    if (!tid) return;
    setErr('');
    try {
      if (act === 'register') {
        await beginRegister(tid);
        return;
      } else if (act === 'unregister') {
        await global.accountPost('tournament_unregister', { tournament_id: tid });
      } else if (act === 'checkin') {
        await global.accountPost('tournament_checkin', { tournament_id: tid });
      } else if (act === 'deposit') {
        const raw = global.prompt(
          t('tournament.prompt.deposit', 'Coins to deposit into prize vault:'),
          t('tournament.prompt.depositDefault', '1000')
        );
        const amount = Number(raw);
        if (!amount || amount <= 0) return;
        const dep = await global.accountPost('tournament_deposit_prize', { tournament_id: tid, amount: amount });
        if (dep && typeof global.syncCoinsFromProfile === 'function') {
          global.syncCoinsFromProfile(dep);
        }
      } else if (act === 'cancel') {
        if (!global.confirm(t('tournament.confirm.cancel', 'Cancel tournament and refund entrants?'))) return;
        await global.accountPost('tournament_cancel', { tournament_id: tid });
        returnToBulletin(t('tournament.err.cancelRefunded', 'Tournament cancelled — entrants refunded.'));
        return;
      } else if (act === 'tick') {
        await global.accountPost('tournament_tick', { tournament_id: tid });
      } else if (act === 'join') {
        const join = await global.accountPost('tournament_join_match', { tournament_id: tid });
        if (typeof global.tcgEnterTournamentMatch === 'function') {
          state.open = false;
          stopTickLoop();
          setSky(false);
          await global.tcgEnterTournamentMatch(join);
          return;
        }
        setErr(t('tournament.err.joinHelperMissing', 'Join helper missing'));
        return;
      } else if (act === 'spectate-list') {
        if (typeof global.openSpectateList === 'function') {
          void global.openSpectateList('tournament');
        }
        return;
      }
      await openDetail(tid);
    } catch (e) {
      const msg = e.message || String(e);
      if (/not found/i.test(msg) || /cancelled/i.test(msg) || /Already closed/i.test(msg)) {
        returnToBulletin(msg);
        return;
      }
      setErr(msg);
    }
  }

  async function beginRegister(tid) {
    setErr('');
    state.registerTid = tid;
    try {
      const opts = await global.accountPost('tournament_eligible_decks', { tournament_id: tid });
      if (opts && opts.randomized) {
        state.registerOpts = null;
        await global.accountPost('tournament_register', { tournament_id: tid });
        await openDetail(tid);
        return;
      }
      state.registerOpts = opts || { decks: [], needs_deck_builder: true };
      showView('register');
      renderRegisterPicker(state.registerOpts);
    } catch (e) {
      setErr(e.message || String(e));
    }
  }

  function renderRegisterPicker(opts) {
    const root = el('tournament-register-decks');
    const actions = el('tournament-register-actions');
    const lead = el('tournament-register-lead');
    const decks = (opts && opts.decks) || [];
    const isFree = !!(opts && opts.free);
    if (lead) {
      if (isFree) {
        lead.textContent = decks.length
          ? t('tournament.register.leadFreePick', 'Pick a Deck Experiment preset, a saved account deck, or enter an experiment password.')
          : t('tournament.register.leadFreeEmpty', 'No saved Free decks yet — open Deck Experiment, save a preset (or use a share password), then come back.');
      } else {
        lead.textContent = decks.length
          ? t('tournament.register.leadPick', 'Pick a legal deck to lock in for this event.')
          : t('tournament.register.leadEmpty', 'No eligible deck yet — build one in Deck Builder, then come back.');
      }
    }
    if (root) {
      let html = '';
      if (!decks.length) {
        html += '<p class="tournament-muted">'
          + escapeHtml(isFree
            ? t('tournament.register.noFreeDecks', 'No experiment presets or owned account decks found.')
            : t('tournament.register.noEligible', 'No eligible decks for this game mode.'))
          + '</p>';
      } else {
        html += decks.map((d, i) => {
          const title = escapeHtml(d.name || d.label || t('tournament.register.deckFallback', 'Deck'));
          let meta = escapeHtml(t('tournament.register.metaPreset', 'Preset slot {slot}', { slot: d.slot || '?' }))
            + (d.equipped ? (' ' + escapeHtml(t('tournament.register.metaEquipped', '· equipped'))) : '');
          if (d.type === 'starter') {
            meta = escapeHtml(t('tournament.register.metaStarter', 'Starter · {label}', {
              label: d.label || d.starter || '',
            }));
          } else if (d.type === 'experiment_preset') {
            meta = escapeHtml(t('tournament.register.metaExperiment', 'Deck Experiment · slot {slot}', {
              slot: d.slot || '?',
            }));
          }
          return (
            '<button type="button" class="tournament-deck-pick" data-idx="' + i + '">'
            + '<div class="tournament-deck-pick-title">' + title + '</div>'
            + '<div class="tournament-deck-pick-meta">' + meta + '</div>'
            + '</button>'
          );
        }).join('');
      }
      if (isFree) {
        html += '<div class="tournament-register-password">'
          + '<label>' + escapeHtml(t('tournament.register.passwordLabel', 'Experiment password'))
          + '<input type="text" id="tournament-experiment-pwd" maxlength="16" autocomplete="off" placeholder="'
          + escapeAttr(t('tournament.register.passwordPlaceholder', 'Shared Deck Experiment code')) + '">'
          + '</label>'
          + '<button type="button" class="btn-grad" id="btn-tournament-register-pwd">'
          + escapeHtml(t('tournament.register.withPassword', 'Register with password'))
          + '</button>'
          + '</div>';
      }
      root.innerHTML = html;
      root.querySelectorAll('[data-idx]').forEach((btn) => {
        btn.addEventListener('click', () => {
          const d = decks[Number(btn.getAttribute('data-idx'))];
          if (d) void confirmRegisterWithDeck(d);
        });
      });
      const pwdBtn = el('btn-tournament-register-pwd');
      if (pwdBtn) {
        pwdBtn.addEventListener('click', () => {
          const inp = el('tournament-experiment-pwd');
          const pw = (inp && inp.value) ? String(inp.value).trim() : '';
          if (!pw) {
            setErr(t('tournament.err.experimentPassword', 'Enter an experiment password'));
            return;
          }
          void confirmRegisterWithDeck({ type: 'experiment_password', password: pw });
        });
      }
    }
    if (actions) {
      if (isFree) {
        actions.innerHTML = '<button type="button" class="btn-out" id="btn-tournament-goto-experiment">'
          + escapeHtml(t('tournament.register.openDeckExperiment', 'Open Deck Experiment'))
          + '</button>';
        const go = el('btn-tournament-goto-experiment');
        if (go) go.addEventListener('click', openDeckExperimentFromTournament);
      } else {
        actions.innerHTML = '<button type="button" class="btn-out" id="btn-tournament-goto-deck">'
          + escapeHtml(t('tournament.register.openDeckBuilder', 'Open Deck Builder'))
          + '</button>';
        const go = el('btn-tournament-goto-deck');
        if (go) go.addEventListener('click', openDeckBuilderFromTournament);
      }
    }
  }

  async function confirmRegisterWithDeck(deck) {
    const tid = state.registerTid;
    if (!tid || !deck) return;
    setErr('');
    const body = { tournament_id: tid };
    if (deck.type === 'starter') body.starter = deck.starter;
    else if (deck.type === 'experiment_preset') body.experiment_slot = deck.slot;
    else if (deck.type === 'experiment_password') body.experiment_password = deck.password;
    else if (deck.type === 'preset') body.deck_slot = deck.slot;
    try {
      await global.accountPost('tournament_register', body);
      await openDetail(tid);
    } catch (e) {
      setErr(e.message || String(e));
    }
  }

  function openDeckBuilderFromTournament() {
    state.open = false;
    stopTickLoop();
    setSky(false);
    if (global.A) global.A.deckBuilderReturn = 'tournament';
    if (typeof global.openDeckBuilder === 'function') {
      global.openDeckBuilder();
      return;
    }
    if (typeof global.showScr === 'function') global.showScr('deck');
  }

  function openDeckExperimentFromTournament() {
    state.open = false;
    stopTickLoop();
    setSky(false);
    if (global.A) global.A.deckBuilderReturn = 'tournament';
    if (typeof global.openDeckExperiment === 'function') {
      global.openDeckExperiment({ returnTo: 'tournament' });
      return;
    }
    openDeckBuilderFromTournament();
  }

  /** Extra deck rules — English fallbacks (labels + help via i18n). */
  const RULES_TEMPLATE_FALLBACK = {
    standard: {
      label: 'Standard',
      help: 'No extra deck limits beyond the selected game mode. Full rarity and normal copy limits apply.',
    },
    pauper: {
      label: 'Pauper (N/R)',
      help: 'Only lower rarities: N, R, C, U, and CL. Higher rarities (SR+, SEC, etc.) are not allowed.',
    },
    highlander: {
      label: 'Highlander (1-of)',
      help: 'At most one copy of each card in the whole deck (main + energy). No duplicates of any card number.',
    },
  };

  function rulesTemplateInfo(key) {
    const k = String(key || 'standard').toLowerCase();
    const id = RULES_TEMPLATE_FALLBACK[k] ? k : 'standard';
    const fb = RULES_TEMPLATE_FALLBACK[id];
    return {
      label: t('tournament.rules.' + id + '.label', fb.label),
      help: t('tournament.rules.' + id + '.help', fb.help),
    };
  }

  /** Rules templates that apply for a given game mode (mirrors server). */
  function rulesTemplatesForMode(mode) {
    const m = String(mode || 'standard').toLowerCase();
    if (m === 'standard' || m === 'free') return ['standard', 'pauper', 'highlander'];
    return ['standard'];
  }

  function syncCreateRulesHelp() {
    const rulesEl = el('tournament-create-rules');
    const helpEl = el('tournament-create-rules-help');
    if (!helpEl) return;
    const info = rulesTemplateInfo(rulesEl && rulesEl.value);
    let text = info.help;
    const modeEl = el('tournament-create-mode');
    const mode = modeEl ? modeEl.value : 'standard';
    if (rulesTemplatesForMode(mode).length <= 1) {
      text = t(
        'tournament.rulesHelp.modeLockedPrefix',
        'Game mode already sets deck rules — only Standard applies here.'
      ) + ' ' + info.help;
    }
    helpEl.textContent = text;
  }

  function syncCreateRulesOptions() {
    const modeEl = el('tournament-create-mode');
    const rulesEl = el('tournament-create-rules');
    if (!modeEl || !rulesEl) return;
    const allowed = rulesTemplatesForMode(modeEl.value);
    const prev = rulesEl.value;
    const labels = {
      standard: t('tournament.rules.standardOption', 'Standard (no extra limits)'),
      pauper: t('tournament.rules.pauperOption', 'Pauper (N/R)'),
      highlander: t('tournament.rules.highlanderOption', 'Highlander (1-of)'),
    };
    rulesEl.innerHTML = '';
    allowed.forEach((v) => {
      const opt = document.createElement('option');
      opt.value = v;
      opt.textContent = labels[v] || v;
      rulesEl.appendChild(opt);
    });
    rulesEl.value = allowed.indexOf(prev) >= 0 ? prev : 'standard';
    rulesEl.disabled = allowed.length <= 1;
    syncCreateRulesHelp();
  }

  async function createTournament(ev) {
    if (ev) ev.preventDefault();
    setErr('');
    closeDatePicker();
    const title = (el('tournament-create-title') || {}).value || '';
    const startLocal = (el('tournament-create-start') || {}).value || '';
    const startAt = parseLocalDateTimeValue(startLocal);
    if (!startAt) {
      setErr(t('tournament.err.pickStart', 'Pick a start date and time'));
      return;
    }
    if (startAt < Math.floor(Date.now() / 1000) + 60) {
      setErr(t('tournament.err.startTooSoon', 'Start time must be at least 1 minute from now'));
      return;
    }
    const body = {
      title: title.trim(),
      start_at: startAt,
      checkin_mins: Number((el('tournament-create-checkin') || {}).value || 10),
      min_players: Number((el('tournament-create-min') || {}).value || 2),
      max_players: Number((el('tournament-create-max') || {}).value || 8),
      entry_fee_coins: Number((el('tournament-create-fee') || {}).value || 0),
      game_mode: (el('tournament-create-mode') || {}).value || 'standard',
      settings: {
        format: (el('tournament-create-format') || {}).value || 'single_elim',
        best_of: Number((el('tournament-create-bestof') || {}).value || 1),
        fog: (el('tournament-create-fog') || {}).value || 'hidden_hands',
        rules_template: (el('tournament-create-rules') || {}).value || 'standard',
        stream_delay_secs: Number((el('tournament-create-delay') || {}).value || 0),
      },
    };
    try {
      const res = await global.accountPost('tournament_create', body);
      const id = res && res.tournament && res.tournament.id;
      if (id) await openDetail(id);
      else showView('list');
      await loadList();
    } catch (e) {
      setErr(e.message || String(e));
    }
  }

  const datePicker = {
    open: false,
    viewYear: 0,
    viewMonth: 0, // 0-11
    selectedY: 0,
    selectedM: 0,
    selectedD: 0,
  };

  function pad2(n) {
    return String(n).padStart(2, '0');
  }

  function parseLocalDateTimeValue(val) {
    if (!val || typeof val !== 'string') return 0;
    const m = val.match(/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})$/);
    if (!m) return 0;
    return zonedWallTimeToUnixSeconds(
      Number(m[1]), Number(m[2]) - 1, Number(m[3]),
      Number(m[4]), Number(m[5])
    );
  }

  function formatLocalDateTimeValue(y, mo, d, hh, mm) {
    return pad2(y) + '-' + pad2(mo + 1) + '-' + pad2(d) + 'T' + pad2(hh) + ':' + pad2(mm);
  }

  function formatDisplayLabel(y, mo, d, hh, mm) {
    try {
      const unix = zonedWallTimeToUnixSeconds(y, mo, d, hh, mm);
      return new Date(unix * 1000).toLocaleString(undefined, {
        timeZone: state.timezone || 'Asia/Tokyo',
        weekday: 'short',
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
        timeZoneName: 'short',
      });
    } catch (e) {
      return formatLocalDateTimeValue(y, mo, d, hh, mm);
    }
  }

  function syncStartLabel() {
    const hidden = el('tournament-create-start');
    const label = el('tournament-create-start-label');
    if (!label) return;
    const val = hidden && hidden.value;
    if (!val) {
      label.textContent = t('tournament.cal.pickDateTime', 'Pick date & time');
      return;
    }
    const m = val.match(/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})$/);
    if (!m) {
      label.textContent = val;
      return;
    }
    label.textContent = formatDisplayLabel(
      Number(m[1]), Number(m[2]) - 1, Number(m[3]),
      Number(m[4]), Number(m[5])
    );
  }

  function fillTimeSelects() {
    const hour = el('tournament-cal-hour');
    const min = el('tournament-cal-min');
    if (hour && !hour.options.length) {
      for (let h = 0; h < 24; h++) {
        const opt = document.createElement('option');
        opt.value = String(h);
        opt.textContent = pad2(h);
        hour.appendChild(opt);
      }
    }
    if (min && !min.options.length) {
      for (let m = 0; m < 60; m += 5) {
        const opt = document.createElement('option');
        opt.value = String(m);
        opt.textContent = pad2(m);
        min.appendChild(opt);
      }
    }
  }

  function openDatePicker() {
    fillTimeSelects();
    const nowZ = zonedNowParts();
    const hidden = el('tournament-create-start');
    const val = hidden && hidden.value;
    let y = nowZ.y;
    let mo = nowZ.m;
    let d = nowZ.d;
    let hh = nowZ.hh;
    let mm = Math.ceil(nowZ.mm / 5) * 5;
    if (mm >= 60) { mm = 0; hh = (hh + 1) % 24; }
    if (val) {
      const m = val.match(/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})$/);
      if (m) {
        y = Number(m[1]); mo = Number(m[2]) - 1; d = Number(m[3]);
        hh = Number(m[4]); mm = Number(m[5]);
      }
    }
    // Clamp selection to today-or-later in the chosen timezone.
    const todayKey = nowZ.y * 10000 + (nowZ.m + 1) * 100 + nowZ.d;
    const selKey = y * 10000 + (mo + 1) * 100 + d;
    if (selKey < todayKey) {
      y = nowZ.y; mo = nowZ.m; d = nowZ.d;
    }
    datePicker.viewYear = y;
    datePicker.viewMonth = mo;
    datePicker.selectedY = y;
    datePicker.selectedM = mo;
    datePicker.selectedD = d;
    datePicker.open = true;
    const hour = el('tournament-cal-hour');
    const min = el('tournament-cal-min');
    if (hour) hour.value = String(hh);
    if (min) {
      const snapped = Math.round(mm / 5) * 5;
      min.value = String(Math.min(55, snapped));
    }
    const pop = el('tournament-datetime-popover');
    const btn = el('tournament-create-start-btn');
    if (pop) pop.hidden = false;
    if (btn) btn.setAttribute('aria-expanded', 'true');
    renderCalendarGrid();
  }

  function closeDatePicker() {
    datePicker.open = false;
    const pop = el('tournament-datetime-popover');
    const btn = el('tournament-create-start-btn');
    if (pop) pop.hidden = true;
    if (btn) btn.setAttribute('aria-expanded', 'false');
  }

  function toggleDatePicker() {
    if (datePicker.open) closeDatePicker();
    else openDatePicker();
  }

  function renderCalendarGrid() {
    const title = el('tournament-cal-title');
    const grid = el('tournament-cal-grid');
    if (!grid) return;
    const y = datePicker.viewYear;
    const mo = datePicker.viewMonth;
    if (title) {
      try {
        title.textContent = new Date(y, mo, 1).toLocaleString(undefined, { month: 'long', year: 'numeric' });
      } catch (e) {
        title.textContent = (mo + 1) + '/' + y;
      }
    }
    const first = new Date(y, mo, 1);
    const startDow = first.getDay();
    const daysInMonth = new Date(y, mo + 1, 0).getDate();
    const prevDays = new Date(y, mo, 0).getDate();
    const nowZ = zonedNowParts();
    const todayKey = nowZ.y * 10000 + (nowZ.m + 1) * 100 + nowZ.d;
    const cells = [];
    for (let i = 0; i < 42; i++) {
      let cellY = y;
      let cellM = mo;
      let cellD;
      let other = false;
      if (i < startDow) {
        cellD = prevDays - startDow + i + 1;
        cellM = mo - 1;
        if (cellM < 0) { cellM = 11; cellY = y - 1; }
        other = true;
      } else if (i >= startDow + daysInMonth) {
        cellD = i - startDow - daysInMonth + 1;
        cellM = mo + 1;
        if (cellM > 11) { cellM = 0; cellY = y + 1; }
        other = true;
      } else {
        cellD = i - startDow + 1;
      }
      const cellKey = cellY * 10000 + (cellM + 1) * 100 + cellD;
      const isPast = cellKey < todayKey;
      const isSel = cellY === datePicker.selectedY
        && cellM === datePicker.selectedM
        && cellD === datePicker.selectedD;
      const isToday = cellY === nowZ.y && cellM === nowZ.m && cellD === nowZ.d;
      const cls = ['tournament-cal-day'];
      if (other) cls.push('is-other');
      if (isPast) cls.push('is-past');
      if (isSel && !isPast) cls.push('is-selected');
      if (isToday) cls.push('is-today');
      cells.push(
        '<button type="button" class="' + cls.join(' ') + '"'
        + (isPast ? ' disabled' : '')
        + ' data-y="' + cellY + '" data-m="' + cellM + '" data-d="' + cellD + '">'
        + cellD + '</button>'
      );
    }
    grid.innerHTML = cells.join('');
    grid.querySelectorAll('.tournament-cal-day:not(:disabled)').forEach((btn) => {
      btn.addEventListener('click', (ev) => {
        // Keep the popover open until Apply/Cancel — stop the document
        // outside-click handler, and avoid relying on contains() after we
        // replace the grid (which detaches this button mid-click).
        ev.preventDefault();
        ev.stopPropagation();
        datePicker.selectedY = Number(btn.getAttribute('data-y'));
        datePicker.selectedM = Number(btn.getAttribute('data-m'));
        datePicker.selectedD = Number(btn.getAttribute('data-d'));
        datePicker.viewYear = datePicker.selectedY;
        datePicker.viewMonth = datePicker.selectedM;
        renderCalendarGrid();
      });
    });
  }

  function applyDatePicker() {
    const hour = Number((el('tournament-cal-hour') || {}).value || 0);
    const min = Number((el('tournament-cal-min') || {}).value || 0);
    const unix = zonedWallTimeToUnixSeconds(
      datePicker.selectedY,
      datePicker.selectedM,
      datePicker.selectedD,
      hour,
      min
    );
    if (unix < Math.floor(Date.now() / 1000) + 60) {
      setErr(t('tournament.err.pickStartSoon', 'Pick a start time at least 1 minute from now'));
      return;
    }
    const val = formatLocalDateTimeValue(
      datePicker.selectedY,
      datePicker.selectedM,
      datePicker.selectedD,
      hour,
      min
    );
    const hidden = el('tournament-create-start');
    if (hidden) hidden.value = val;
    syncStartLabel();
    closeDatePicker();
  }

  function shiftCalendarMonth(delta) {
    let m = datePicker.viewMonth + delta;
    let y = datePicker.viewYear;
    while (m < 0) { m += 12; y -= 1; }
    while (m > 11) { m -= 12; y += 1; }
    datePicker.viewMonth = m;
    datePicker.viewYear = y;
    renderCalendarGrid();
  }

  function spectateRoom(roomId) {
    if (typeof global.tcgSpectateTournamentRoom === 'function') {
      state.open = false;
      stopTickLoop();
      setSky(false);
      global.tcgSpectateTournamentRoom(roomId);
      return;
    }
    setErr(t('tournament.err.spectateHelperMissing', 'Spectate helper missing'));
  }

  function startTickLoop() {
    stopTickLoop();
    state.tickTimer = global.setInterval(async () => {
      if (!state.open || !screenActive()) return;
      if (state.view !== 'detail' || !state.detail || !state.detail.tournament) return;
      const tid = state.detail.tournament.id;
      const st = state.detail.tournament.status;
      if (st === 'finished') return;
      if (st === 'cancelled') {
        returnToBulletin(t('tournament.err.cancelled', 'This tournament was cancelled.'));
        return;
      }
      try {
        await global.accountPost('tournament_tick', { tournament_id: tid });
        const res = await global.accountPost('tournament_get', { tournament_id: tid });
        if (res && res.tournament && res.tournament.status === 'cancelled') {
          returnToBulletin(t('tournament.err.cancelled', 'This tournament was cancelled.'));
          return;
        }
        state.detail = res;
        renderDetail();
      } catch (e) {
        const msg = (e && e.message) ? String(e.message) : '';
        if (/not found/i.test(msg)) {
          returnToBulletin(t('tournament.err.unavailable', 'That tournament is no longer available.'));
        }
      }
    }, 8000);
  }

  function stopTickLoop() {
    if (state.tickTimer) {
      global.clearInterval(state.tickTimer);
      state.tickTimer = null;
    }
  }

  function escapeHtml(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function escapeAttr(s) {
    return escapeHtml(s).replace(/'/g, '&#39;');
  }

  function wire() {
    on('btn-hub-tournament', () => openScreen());
    on('btn-auth-tournament', () => openScreen());
    on('btn-tournament-back', () => closeToHub());
    on('btn-tournament-refresh', () => loadList());
    on('btn-tournament-create', () => showView('create'));
    on('btn-tournament-create-back', () => showView('list'));
    on('btn-tournament-detail-back', () => { showView('list'); loadList(); });
    on('btn-tournament-register-back', () => {
      if (state.registerTid) openDetail(state.registerTid);
      else showView('detail');
    });
    on('tournament-create-start-btn', () => toggleDatePicker());
    on('tournament-cal-prev', () => shiftCalendarMonth(-1));
    on('tournament-cal-next', () => shiftCalendarMonth(1));
    on('tournament-cal-cancel', () => closeDatePicker());
    on('tournament-cal-apply', () => applyDatePicker());
    const form = el('tournament-create-form');
    if (form) form.addEventListener('submit', createTournament);
    const modeSel = el('tournament-create-mode');
    if (modeSel) {
      modeSel.addEventListener('change', syncCreateRulesOptions);
      syncCreateRulesOptions();
    }
    const rulesSel = el('tournament-create-rules');
    if (rulesSel) {
      rulesSel.addEventListener('change', syncCreateRulesHelp);
    }
    const filter = el('tournament-filter-mode');
    if (filter) {
      filter.addEventListener('change', () => {
        state.filterMode = filter.value || '';
        loadList();
      });
    }
    document.addEventListener('click', (ev) => {
      if (!datePicker.open) return;
      const wrap = el('tournament-datetime');
      if (!wrap) return;
      // Prefer composedPath: day cells are replaced on select, so ev.target may
      // already be detached and wrap.contains(ev.target) would falsely close.
      const path = typeof ev.composedPath === 'function' ? ev.composedPath() : null;
      if (path && path.indexOf(wrap) !== -1) return;
      if (wrap.contains(ev.target)) return;
      closeDatePicker();
    });
    document.addEventListener('keydown', (ev) => {
      if (ev.key === 'Escape' && datePicker.open) closeDatePicker();
    });
    syncStartLabel();
  }

  function on(id, fn) {
    const node = el(id);
    if (node) node.addEventListener('click', fn);
  }

  function refreshActiveView() {
    if (state.view === 'list') renderList();
    else if (state.view === 'detail') renderDetail();
    else if (state.view === 'register' && state.registerOpts) renderRegisterPicker(state.registerOpts);
    else if (state.view === 'create') {
      syncCreateRulesOptions();
      syncStartLabel();
    }
  }

  function boot() {
    wire();
    refreshServerFlag();
    if (global.LLTCG_I18N && typeof global.LLTCG_I18N.onLocaleChange === 'function') {
      global.LLTCG_I18N.onLocaleChange(() => {
        if (!screenActive()) return;
        applyTournamentStaticI18n();
        refreshActiveView();
      });
    }
    global.addEventListener('tcg:auth-ready', () => {
      refreshServerFlag();
      syncTimezoneFromProfile();
    });
    setTimeout(() => { refreshServerFlag(); }, 1500);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }

  global.TCGTournamentUI = {
    open: openScreen,
    close: closeToHub,
    closeQuiet: function () {
      // Leaving the screen without ← Hub — keep session so refresh can resume.
      state.open = false;
      stopTickLoop();
      setSky(false);
    },
    tryResumeSession: tryResumeSession,
    refreshFlag: refreshServerFlag,
    applyEnabled: applyEnabled,
    onAvatarError: onAvatarError,
  };
})(typeof window !== 'undefined' ? window : globalThis);
