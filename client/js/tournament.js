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

  function t(key, fallback) {
    const fn = global.LLTCG_I18N && global.LLTCG_I18N.t;
    if (typeof fn === 'function') {
      const v = fn(key);
      if (v && v !== key) return v;
    }
    return fallback || key;
  }

  function clientEnabled() {
    return !!global.TCG_TOURNAMENTS_ENABLED;
  }

  function setHubButtons(enabled) {
    ['btn-hub-tournament', 'btn-auth-tournament'].forEach((id) => {
      const btn = el(id);
      if (!btn) return;
      if (enabled) {
        btn.disabled = false;
        btn.removeAttribute('aria-disabled');
        btn.classList.add('llc-menu-hover', 'llc-menu-accent-gold');
        const sub = btn.querySelector('.llc-menu-item-sub');
        if (sub) sub.textContent = t('hub.tournament.subLive', 'Events & brackets');
      } else {
        btn.disabled = true;
        btn.setAttribute('aria-disabled', 'true');
        const sub = btn.querySelector('.llc-menu-item-sub');
        if (sub) sub.textContent = t('hub.tournament.sub', 'Coming Soon');
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

  function setSky(on) {
    document.body.classList.toggle('tcg-tournament-sky', !!on);
  }

  function openScreen() {
    state.open = true;
    setSky(true);
    ensureTimezoneSelect();
    syncTimezoneFromProfile();
    showView('list');
    loadList();
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
  }

  function closeToHub() {
    state.open = false;
    stopTickLoop();
    state.detail = null;
    setSky(false);
    if (typeof global.showScr === 'function') {
      global.showScr('hub');
    }
  }

  function showView(name) {
    state.view = name;
    if (name !== 'create') closeDatePicker();
    ['list', 'create', 'detail', 'register'].forEach((v) => {
      const node = el('tournament-view-' + v);
      if (node) node.hidden = v !== name;
    });
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

  function timezoneLabel(id) {
    const hit = TIMEZONE_OPTIONS.find((z) => z.id === id);
    return hit ? hit.label : id;
  }

  function ensureTimezoneSelect() {
    const sel = el('tournament-timezone');
    if (!sel || sel.dataset.ready === '1') return;
    sel.innerHTML = TIMEZONE_OPTIONS.map((z) => (
      '<option value="' + escapeAttr(z.id) + '">' + escapeHtml(z.label) + '</option>'
    )).join('');
    sel.dataset.ready = '1';
    sel.addEventListener('change', onTimezoneChange);
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
        opt.textContent = tz;
        sel.appendChild(opt);
      }
      sel.value = tz;
    }
    updateTimezoneHint();
    const note = el('tournament-create-tz-note');
    if (note) note.textContent = 'Start time uses ' + timezoneLabel(tz) + '.';
  }

  function updateTimezoneHint() {
    const hint = el('tournament-tz-hint');
    if (hint) hint.textContent = 'Times shown in ' + timezoneLabel(state.timezone);
  }

  async function onTimezoneChange() {
    const sel = el('tournament-timezone');
    if (!sel) return;
    const tz = sel.value || 'Asia/Tokyo';
    state.timezone = tz;
    updateTimezoneHint();
    const note = el('tournament-create-tz-note');
    if (note) note.textContent = 'Start time uses ' + timezoneLabel(tz) + '.';
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
    } catch (e) {
      setErr(e.message || String(e));
    } finally {
      state.loading = false;
    }
  }

  function renderList() {
    const root = el('tournament-list');
    if (!root) return;
    if (!state.list.length) {
      root.innerHTML = '<p class="tournament-muted">No open tournaments yet. Create one to get started.</p>';
      return;
    }
    root.innerHTML = state.list.map((row) => {
      const fee = Number(row.entry_fee_coins) || 0;
      const count = Number(row.entrant_count) || 0;
      const max = Number(row.max_players) || 0;
      return (
        '<button type="button" class="tournament-card" data-tid="' + escapeAttr(row.id) + '">'
        + '<div class="tournament-card-title">' + escapeHtml(row.title) + '</div>'
        + '<div class="tournament-card-meta">'
        + escapeHtml(String(row.status)) + ' · ' + escapeHtml(String(row.game_mode))
        + ' · ' + count + '/' + max
        + ' · fee ' + fee + ' · starts ' + escapeHtml(fmtWhen(row.start_at))
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
      state.detail = res;
      renderDetail();
    } catch (e) {
      setErr(e.message || String(e));
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
      + '<span class="tournament-person-name">' + escapeHtml(username || 'Player') + '</span>'
      + '</span>'
    );
  }

  function renderDetail() {
    const res = state.detail;
    if (!res || !res.tournament) return;
    const trow = res.tournament;
    const head = el('tournament-detail-head');
    if (head) {
      head.innerHTML =
        '<h3 class="tournament-subhead">' + escapeHtml(trow.title) + '</h3>'
        + '<div class="tournament-host-row">'
        + '<span class="tournament-muted">Host</span> '
        + personChipHtml(res.host_discord_id, res.host_username || 'Host', res.host_avatar_url)
        + '</div>'
        + '<p class="tournament-muted">'
        + escapeHtml(trow.status)
        + ' · prize ' + (Number(trow.prize_pool_coins) || 0)
        + ' · starts ' + escapeHtml(fmtWhen(trow.start_at))
        + '</p>';
    }
    wireAvatarFallbacks(head);
    const actions = el('tournament-detail-actions');
    if (actions) {
      const me = res.me;
      const isHost = !!res.is_host;
      const bits = [];
      if (!me && (trow.status === 'open' || trow.status === 'checkin')) {
        bits.push('<button type="button" class="btn-grad" data-act="register">Register</button>');
      }
      if (me && (trow.status === 'open' || trow.status === 'checkin') && me.status === 'registered') {
        bits.push('<button type="button" class="btn-grad" data-act="checkin">Check in</button>');
        bits.push('<button type="button" class="btn-ghost" data-act="unregister">Unregister</button>');
      }
      if (me && me.status === 'checked_in') {
        bits.push('<span class="tournament-muted">Checked in</span>');
      }
      if (isHost && (trow.status === 'open' || trow.status === 'checkin' || trow.status === 'running')) {
        bits.push('<button type="button" class="btn-out" data-act="deposit">Deposit prize</button>');
        bits.push('<button type="button" class="btn-ghost" data-act="cancel">Cancel (refund)</button>');
      }
      bits.push('<button type="button" class="btn-out" data-act="tick">Refresh / tick</button>');
      bits.push('<button type="button" class="btn-grad" data-act="join">Join my match</button>');
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
        + '<span class="tournament-entrant-status">' + escapeHtml(e.status) + '</span>'
        + '</li>'
      )).join('') || '<li class="tournament-muted">No entrants</li>';
      wireAvatarFallbacks(list);
    }
    renderBracket(res.matches || []);
  }

  function wireAvatarFallbacks(root) {
    if (!root) return;
    root.querySelectorAll('img.tournament-avatar').forEach((img) => {
      if (img.dataset.errBound) return;
      img.dataset.errBound = '1';
      img.addEventListener('error', () => onAvatarError(img));
    });
  }

  function renderBracket(matches) {
    const root = el('tournament-bracket');
    if (!root) return;
    if (!matches.length) {
      root.innerHTML = '<p class="tournament-muted">Bracket appears after check-in closes.</p>';
      return;
    }
    const byRound = {};
    let maxRound = 1;
    matches.forEach((m) => {
      const r = Number(m.round) || 1;
      maxRound = Math.max(maxRound, r);
      (byRound[r] || (byRound[r] = [])).push(m);
    });
    Object.keys(byRound).forEach((r) => {
      byRound[r].sort((a, b) => (a.bracket_slot || 0) - (b.bracket_slot || 0));
    });
    const colW = 220;
    const rowH = 72;
    const pad = 24;
    const firstCount = (byRound[1] || []).length || 1;
    const height = pad * 2 + firstCount * rowH;
    const width = pad * 2 + maxRound * colW;
    let svg = '<svg viewBox="0 0 ' + width + ' ' + height + '" width="' + width + '" height="' + height + '">';
    for (let r = 1; r <= maxRound; r++) {
      const col = byRound[r] || [];
      const slots = Math.max(col.length, 1);
      const step = height / slots;
      col.forEach((m, i) => {
        const x = pad + (r - 1) * colW;
        const y = pad + i * step + (step - 56) / 2;
        const p1 = nameFor(m.p1_discord_id);
        const p2 = nameFor(m.p2_discord_id);
        const status = m.status || '';
        const win = m.winner_discord_id ? nameFor(m.winner_discord_id) : '';
        svg += '<rect class="tournament-bracket-node" x="' + x + '" y="' + y + '" width="190" height="56" rx="8"/>';
        svg += '<text class="tournament-bracket-text" x="' + (x + 10) + '" y="' + (y + 18) + '">' + escapeHtml(p1) + '</text>';
        svg += '<text class="tournament-bracket-text" x="' + (x + 10) + '" y="' + (y + 34) + '">'
          + escapeHtml(p2 || (status === 'done' ? '(bye)' : 'TBD')) + '</text>';
        svg += '<text class="tournament-bracket-sub" x="' + (x + 10) + '" y="' + (y + 50) + '">'
          + escapeHtml(status) + (win ? ' · ' + escapeHtml(win) : '')
          + (m.room_id ? ' · ' + escapeHtml(m.room_id) : '')
          + '</text>';
        if (m.room_id && (status === 'ready' || status === 'live')) {
          svg += '<text class="tournament-bracket-sub" data-spec="' + escapeAttr(m.room_id)
            + '" x="' + (x + 140) + '" y="' + (y + 50) + '" style="cursor:pointer;fill:#9cf">Spec</text>';
        }
      });
    }
    svg += '</svg>';
    root.innerHTML = svg;
    root.querySelectorAll('[data-spec]').forEach((node) => {
      node.addEventListener('click', () => spectateRoom(node.getAttribute('data-spec')));
    });
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
        const raw = global.prompt('Coins to deposit into prize vault:', '1000');
        const amount = Number(raw);
        if (!amount || amount <= 0) return;
        await global.accountPost('tournament_deposit_prize', { tournament_id: tid, amount: amount });
      } else if (act === 'cancel') {
        if (!global.confirm('Cancel tournament and refund entrants?')) return;
        await global.accountPost('tournament_cancel', { tournament_id: tid });
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
        setErr('Join helper missing');
        return;
      }
      await openDetail(tid);
    } catch (e) {
      setErr(e.message || String(e));
    }
  }

  async function beginRegister(tid) {
    setErr('');
    state.registerTid = tid;
    try {
      const opts = await global.accountPost('tournament_eligible_decks', { tournament_id: tid });
      if (opts && opts.randomized) {
        await global.accountPost('tournament_register', { tournament_id: tid });
        await openDetail(tid);
        return;
      }
      showView('register');
      renderRegisterPicker(opts || { decks: [], needs_deck_builder: true });
    } catch (e) {
      setErr(e.message || String(e));
    }
  }

  function renderRegisterPicker(opts) {
    const root = el('tournament-register-decks');
    const actions = el('tournament-register-actions');
    const lead = el('tournament-register-lead');
    const decks = (opts && opts.decks) || [];
    if (lead) {
      lead.textContent = decks.length
        ? 'Pick a legal deck to lock in for this event.'
        : 'No eligible deck yet — build one in Deck Builder, then come back.';
    }
    if (root) {
      if (!decks.length) {
        root.innerHTML = '<p class="tournament-muted">No eligible decks for this game mode.</p>';
      } else {
        root.innerHTML = decks.map((d, i) => {
          const title = escapeHtml(d.name || d.label || 'Deck');
          const meta = d.type === 'starter'
            ? 'Starter · ' + escapeHtml(d.label || d.starter || '')
            : 'Preset slot ' + (d.slot || '?') + (d.equipped ? ' · equipped' : '');
          return (
            '<button type="button" class="tournament-deck-pick" data-idx="' + i + '">'
            + '<div class="tournament-deck-pick-title">' + title + '</div>'
            + '<div class="tournament-deck-pick-meta">' + meta + '</div>'
            + '</button>'
          );
        }).join('');
        root.querySelectorAll('[data-idx]').forEach((btn) => {
          btn.addEventListener('click', () => {
            const d = decks[Number(btn.getAttribute('data-idx'))];
            if (d) void confirmRegisterWithDeck(d);
          });
        });
      }
    }
    if (actions) {
      actions.innerHTML = '<button type="button" class="btn-out" id="btn-tournament-goto-deck">Open Deck Builder</button>';
      const go = el('btn-tournament-goto-deck');
      if (go) go.addEventListener('click', openDeckBuilderFromTournament);
    }
  }

  async function confirmRegisterWithDeck(deck) {
    const tid = state.registerTid;
    if (!tid || !deck) return;
    setErr('');
    const body = { tournament_id: tid };
    if (deck.type === 'starter') body.starter = deck.starter;
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

  async function createTournament(ev) {
    if (ev) ev.preventDefault();
    setErr('');
    closeDatePicker();
    const title = (el('tournament-create-title') || {}).value || '';
    const startLocal = (el('tournament-create-start') || {}).value || '';
    const startAt = parseLocalDateTimeValue(startLocal);
    if (!startAt) {
      setErr('Pick a start date and time');
      return;
    }
    if (startAt < Math.floor(Date.now() / 1000) + 60) {
      setErr('Start time must be at least 1 minute from now');
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
      label.textContent = 'Pick date & time';
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
      btn.addEventListener('click', () => {
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
      setErr('Pick a start time at least 1 minute from now');
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
    setErr('Spectate helper missing');
  }

  function startTickLoop() {
    stopTickLoop();
    state.tickTimer = global.setInterval(async () => {
      if (!state.open || !screenActive()) return;
      if (state.view !== 'detail' || !state.detail || !state.detail.tournament) return;
      const tid = state.detail.tournament.id;
      const st = state.detail.tournament.status;
      if (st === 'finished' || st === 'cancelled') return;
      try {
        await global.accountPost('tournament_tick', { tournament_id: tid });
        const res = await global.accountPost('tournament_get', { tournament_id: tid });
        state.detail = res;
        renderDetail();
      } catch (e) { /* ignore poll errors */ }
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
      if (wrap && !wrap.contains(ev.target)) closeDatePicker();
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

  function boot() {
    wire();
    refreshServerFlag();
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
      state.open = false;
      stopTickLoop();
      setSky(false);
    },
    refreshFlag: refreshServerFlag,
    applyEnabled: applyEnabled,
    onAvatarError: onAvatarError,
  };
})(typeof window !== 'undefined' ? window : globalThis);
