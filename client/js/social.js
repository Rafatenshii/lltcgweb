/**
 * Profile / friends rail + overlays. Signed-in menus only (hidden on playmat).
 */
(function (global) {
  'use strict';

  function tt(key, fallback, vars) {
    const fn = global.LLTCG_I18N && global.LLTCG_I18N.tt;
    return typeof fn === 'function' ? fn(key, fallback, vars) : (fallback || key);
  }
  function applyI18n(root) {
    if (global.LLTCG_I18N && global.LLTCG_I18N.applyI18n) global.LLTCG_I18N.applyI18n(root);
  }
  function accountPost(action, body) {
    return global.accountPost(action, body || {});
  }
  function esc(s) {
    return String(s || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }
  function myId() {
    const A = global.A || {};
    return String(A.user?.id || A.profile?.user?.id || '');
  }
  function signedIn() {
    return !!myId();
  }
  function isMod() {
    const A = global.A || {};
    return !!A.user?.is_social_mod;
  }
  function cardImg(no) {
    const base = global.CARDIMG || './cardimg.php';
    return `${base}?no=${encodeURIComponent(no)}&w=180`;
  }
  function lookupCard(no) {
    const G = global.G || {};
    return (G.allCards && G.allCards[no]) || { card_no: no, name: no };
  }
  function inspectCard(no) {
    if (!no || typeof global.showCard !== 'function') return;
    global.showCard(lookupCard(no), null, global.G?.gameState, global.G?.playerId);
  }

  let _screen = 'auth';
  let _friendsTab = 'friends';

  /** Menus whose shell is already wider than the 720px hub column. */
  const WIDE_RAIL_SCREENS = {
    deck: 1,
    booster: 1,
    'pack-results': 1,
    sticker: 1,
    'playmat-shop': 1,
    'sleeve-shop': 1,
    'card-list': 1,
    leaderboard: 1,
    lobby: 1,
    tournament: 1,
  };

  function syncSocialRail(screenId) {
    _screen = screenId || _screen;
    const rail = document.getElementById('social-rail');
    if (!rail) return;
    rail.classList.toggle('social-rail--edge', !!WIDE_RAIL_SCREENS[_screen]);
    const hide = _screen === 'game' || !signedIn();
    rail.hidden = hide;
    const logged = signedIn();
    rail.querySelectorAll('button[data-social]').forEach((btn) => {
      btn.disabled = !logged;
      btn.title = logged ? '' : tt('social.signInHint', 'Sign in with Discord to use Profile and Friends');
    });
    const modBtn = document.getElementById('btn-social-mod');
    if (modBtn) modBtn.hidden = !isMod();
  }

  function openOverlay(id) {
    const el = document.getElementById(id);
    if (!el) return;
    el.classList.add('open');
    el.setAttribute('aria-hidden', 'false');
    document.body.classList.add('social-overlay-open');
    applyI18n(el);
  }
  function closeOverlay(id) {
    const el = document.getElementById(id);
    if (!el) return;
    el.classList.remove('open');
    el.setAttribute('aria-hidden', 'true');
    if (!document.querySelector('.social-overlay.open')) {
      document.body.classList.remove('social-overlay-open');
    }
  }
  function closeAllSocial() {
    document.querySelectorAll('.social-overlay.open').forEach((n) => closeOverlay(n.id));
  }

  function setErr(id, msg) {
    const n = document.getElementById(id);
    if (n) n.textContent = msg || '';
  }

  async function openProfile(userId) {
    if (!signedIn()) return;
    const uid = userId || myId();
    openOverlay('overlay-profile');
    const root = document.getElementById('profile-body');
    if (root) root.innerHTML = tt('social.loading', 'Loading…');
    try {
      const data = await accountPost('social_profile', { user_id: uid });
      renderProfile(data);
    } catch (e) {
      if (root) root.textContent = e.message || tt('social.error', 'Could not load profile');
    }
  }

  function renderProfile(data) {
    const p = data.profile || {};
    const self = !!data.is_self;
    const root = document.getElementById('profile-body');
    if (!root) return;
    const bioMax = 100;
    const vis = p.featured_deck?.visibility || 'private';
    const deck = p.featured_deck || {};
    const show = (p.showcase || []).map((s) => {
      const no = s.card_no || '';
      return `<button type="button" class="social-card-thumb" data-card="${esc(no)}" ${no ? '' : 'disabled'}>${
        no ? `<img src="${esc(cardImg(no))}" alt="">` : '+'
      }</button>`;
    }).join('');
    const ranked = p.ranked || {};
    root.innerHTML = `
      <div class="social-head">
        <img class="social-avatar" alt="" src="${esc(p.avatar_url || '')}">
        <div>
          <h3>${esc(p.username || 'Player')}</h3>
          <div class="social-code">${esc(p.friend_code || '')}</div>
          <div class="hub-stat">${tt('profile.noTitles', 'No titles yet')}</div>
        </div>
      </div>
      ${self ? `<textarea class="social-bio" id="profile-bio" maxlength="${bioMax}">${esc(p.bio || '')}</textarea>
        <div class="social-char-count" id="profile-bio-count"></div>` : `<p>${esc(p.bio || tt('profile.emptyBio', 'No bio yet.'))}</p>`}
      ${p.bio_locked && self ? `<p class="social-err">${tt('profile.bioLocked', 'Bio editing is locked.')}</p>` : ''}
      <h4 data-i18n="profile.showcase">${tt('profile.showcase', 'Showcase')}</h4>
      <div class="social-showcase" id="profile-showcase">${show}</div>
      <p>${tt('profile.rankedWl', 'Ranked')}: ${ranked.wins || 0}–${ranked.losses || 0}
         · ${tt('profile.unranked', 'Unranked games')}: ${p.unranked_games || 0}</p>
      <button type="button" class="btn-ghost" id="btn-profile-stats">${tt('profile.gameStats', 'Game stats')}</button>
      <h4>${tt('profile.featuredDeck', 'Featured deck')}</h4>
      ${self ? `<select id="profile-deck-vis">
          <option value="private">${tt('profile.visPrivate', 'Private')}</option>
          <option value="friends">${tt('profile.visFriends', 'Friends')}</option>
          <option value="public">${tt('profile.visPublic', 'Public')}</option>
        </select>
        <textarea id="profile-deck-desc" maxlength="200">${esc(deck.desc || '')}</textarea>
        <select id="profile-deck-id"><option value="0">${tt('profile.useEquipped', 'Currently equipped')}</option></select>
        <button type="button" class="btn-grad" id="btn-profile-save">${tt('profile.save', 'Save')}</button>` : ''}
      <div id="profile-deck-view"></div>
      ${!self ? `<button type="button" class="btn-ghost" id="btn-profile-report">${tt('profile.report', 'Report')}</button>` : ''}
      <p class="social-err" id="profile-err"></p>
    `;
    const visEl = document.getElementById('profile-deck-vis');
    if (visEl) visEl.value = vis;
    const deckSel = document.getElementById('profile-deck-id');
    if (deckSel && Array.isArray(deck.decks)) {
      deck.decks.forEach((d) => {
        const o = document.createElement('option');
        o.value = String(d.id);
        o.textContent = d.name || ('Slot ' + d.slot);
        if (String(d.id) === String(deck.featured_deck_id || '')) o.selected = true;
        deckSel.appendChild(o);
      });
    }
    const bio = document.getElementById('profile-bio');
    const count = document.getElementById('profile-bio-count');
    const tick = () => { if (count && bio) count.textContent = `${bio.value.length}/${bioMax}`; };
    if (bio) { bio.addEventListener('input', tick); tick(); }
    root.querySelectorAll('[data-card]').forEach((b) => {
      b.addEventListener('click', () => inspectCard(b.getAttribute('data-card')));
    });
    document.getElementById('btn-profile-stats')?.addEventListener('click', () => openStats(p.id));
    document.getElementById('btn-profile-save')?.addEventListener('click', () => saveProfile(p));
    document.getElementById('btn-profile-report')?.addEventListener('click', async () => {
      try {
        await accountPost('social_report', { user_id: p.id, field: 'bio', snippet: p.bio || '' });
        setErr('profile-err', tt('profile.reported', 'Report sent.'));
      } catch (e) { setErr('profile-err', e.message); }
    });
    renderDeckComp(deck);
    applyI18n(root);
  }

  function renderDeckComp(deck) {
    const box = document.getElementById('profile-deck-view');
    if (!box) return;
    if (!deck.visible) {
      box.innerHTML = `<p>${tt('profile.deckHidden', 'This deck is private.')}</p>`;
      return;
    }
    const c = deck.composition || {};
    const buckets = c.cost_buckets || {};
    const blades = c.blade_hearts || {};
    const types = c.types || {};
    const chips = Object.keys(buckets).map((k) => `<span class="social-chip">${esc(k)}: ${buckets[k]}</span>`).join('');
    const hearts = Object.keys(blades).map((k) => `<span class="social-chip">${esc(k)} ×${blades[k]}</span>`).join('');
    const grid = (deck.cards || []).map((card) =>
      `<button type="button" class="social-card-thumb" data-card="${esc(card.card_no)}"><img src="${esc(cardImg(card.card_no))}" alt=""></button>`
    ).join('');
    box.innerHTML = `
      <p><strong>${esc(deck.name || '')}</strong></p>
      <p>${esc(deck.desc || '')}</p>
      <div class="social-comp-row">${chips}</div>
      <div class="social-comp-row">${hearts}</div>
      <p>${tt('profile.types', 'Member / Live / Energy')}: ${types.member || 0} / ${types.live || 0} / ${types.energy || 0}</p>
      <div class="social-deck-grid">${grid}</div>`;
    box.querySelectorAll('[data-card]').forEach((b) => {
      b.addEventListener('click', () => inspectCard(b.getAttribute('data-card')));
    });
  }

  async function saveProfile() {
    setErr('profile-err', '');
    const showcase = [];
    document.querySelectorAll('#profile-showcase [data-card]').forEach((b, i) => {
      showcase.push({ slot: i + 1, card_no: b.getAttribute('data-card') || '' });
    });
    try {
      const data = await accountPost('social_save_profile', {
        bio: document.getElementById('profile-bio')?.value || '',
        featured_deck_visibility: document.getElementById('profile-deck-vis')?.value || 'private',
        featured_deck_desc: document.getElementById('profile-deck-desc')?.value || '',
        featured_deck_id: parseInt(document.getElementById('profile-deck-id')?.value || '0', 10),
        showcase,
      });
      renderProfile(data);
    } catch (e) {
      setErr('profile-err', e.message);
    }
  }

  async function openStats(userId) {
    openOverlay('overlay-profile-stats');
    const root = document.getElementById('profile-stats-body');
    if (root) root.innerHTML = tt('social.loading', 'Loading…');
    try {
      const data = await accountPost('social_stats', { user_id: userId });
      const modes = (data.modes || []).map((m) =>
        `<div class="social-row"><span>${esc(m.mode)}</span><span>${m.games} · ${m.wins}–${m.losses} (${m.win_pct}%)</span></div>`
      ).join('');
      const opps = (data.opponents || []).map((o) =>
        `<button type="button" class="social-row" data-uid="${esc(o.id)}"><img class="av" src="${esc(o.avatar_url || '')}" alt=""><span>${esc(o.username)}</span><span>${o.wins}–${o.losses}</span></button>`
      ).join('');
      const maxIdol = Math.max(1, ...(data.idols || []).map((i) => i.count));
      const idols = (data.idols || []).map((i) =>
        `<div class="social-bar"><span>${esc(i.idol)}</span><div class="social-bar-track"><div class="social-bar-fill" style="width:${Math.round(100 * i.count / maxIdol)}%"></div></div><span class="social-bar-n">${i.count}</span></div>`
      ).join('');
      const hist = (data.history || []).map((h) =>
        `<div class="social-row"><span>${esc(h.mode)}</span><span>${esc(h.result)}</span><span>${esc(h.opponent?.username || '')}</span></div>`
      ).join('');
      root.innerHTML = `
        <h3>${tt('profile.byMode', 'By mode')}</h3>${modes || '<p>—</p>'}
        <h3>${tt('profile.opponents', 'Most-played opponents')}</h3>${opps || '<p>—</p>'}
        <h3>${tt('profile.idols', 'Top idols')}</h3><div class="social-bars">${idols || '<p>—</p>'}</div>
        <h3>${tt('profile.history', 'Match history')}</h3>${hist || '<p>—</p>'}`;
      root.querySelectorAll('[data-uid]').forEach((b) => {
        b.addEventListener('click', () => { closeOverlay('overlay-profile-stats'); openProfile(b.getAttribute('data-uid')); });
      });
    } catch (e) {
      if (root) root.textContent = e.message;
    }
  }

  async function openFriends() {
    if (!signedIn()) return;
    openOverlay('overlay-friends');
    await refreshFriends();
  }

  async function refreshFriends() {
    const root = document.getElementById('friends-body');
    if (!root) return;
    try {
      const data = await accountPost('social_friends', {});
      const listFor = (arr, kind) => (arr || []).map((u) => {
        let acts = `<button type="button" class="btn-ghost" data-open="${esc(u.id)}">${tt('friends.view', 'View')}</button>`;
        if (kind === 'in') {
          acts += `<button type="button" class="btn-ghost" data-acc="${esc(u.id)}">${tt('friends.accept', 'Accept')}</button>
                   <button type="button" class="btn-ghost" data-dec="${esc(u.id)}">${tt('friends.decline', 'Decline')}</button>`;
        } else if (kind === 'friends') {
          acts += `<button type="button" class="btn-ghost" data-rm="${esc(u.id)}">${tt('friends.remove', 'Remove')}</button>`;
        }
        return `<div class="social-row"><img class="av" src="${esc(u.avatar_url || '')}" alt=""><span>${esc(u.username)}</span>${acts}</div>`;
      }).join('');
      const pane = _friendsTab === 'requests'
        ? `<h4>${tt('friends.incoming', 'Incoming')}</h4>${listFor(data.incoming, 'in') || '<p>—</p>'}
           <h4>${tt('friends.outgoing', 'Outgoing')}</h4>${listFor(data.outgoing, 'out') || '<p>—</p>'}`
        : _friendsTab === 'recent'
          ? listFor(data.recent, 'recent') || '<p>—</p>'
          : listFor(data.friends, 'friends') || '<p>—</p>';
      root.innerHTML = `
        <p>${tt('friends.yourCode', 'Your code')}: <strong class="social-code">${esc(data.friend_code || '')}</strong>
           (${data.count || 0}/${data.cap || 25})</p>
        <div class="social-tabs">
          <button type="button" data-tab="friends" aria-selected="${_friendsTab === 'friends'}">${tt('friends.tabFriends', 'Friends')}</button>
          <button type="button" data-tab="requests" aria-selected="${_friendsTab === 'requests'}">${tt('friends.tabRequests', 'Requests')}</button>
          <button type="button" data-tab="recent" aria-selected="${_friendsTab === 'recent'}">${tt('friends.tabRecent', 'Recent')}</button>
        </div>
        <form id="friends-add-form">
          <input id="friends-code-input" maxlength="12" placeholder="${esc(tt('friends.codePlaceholder', 'LCXXXXXX'))}">
          <button type="submit" class="btn-grad">${tt('friends.add', 'Add')}</button>
        </form>
        <p class="social-err" id="friends-err"></p>
        <div>${pane}</div>`;
      root.querySelectorAll('[data-tab]').forEach((b) => {
        b.addEventListener('click', () => { _friendsTab = b.getAttribute('data-tab'); refreshFriends(); });
      });
      document.getElementById('friends-add-form')?.addEventListener('submit', async (ev) => {
        ev.preventDefault();
        setErr('friends-err', '');
        try {
          await accountPost('social_friend_add', { friend_code: document.getElementById('friends-code-input')?.value || '' });
          await refreshFriends();
        } catch (e) { setErr('friends-err', e.message); }
      });
      root.querySelectorAll('[data-open]').forEach((b) => b.addEventListener('click', () => openProfile(b.getAttribute('data-open'))));
      root.querySelectorAll('[data-acc]').forEach((b) => b.addEventListener('click', async () => {
        await accountPost('social_friend_accept', { user_id: b.getAttribute('data-acc') }); refreshFriends();
      }));
      root.querySelectorAll('[data-dec]').forEach((b) => b.addEventListener('click', async () => {
        await accountPost('social_friend_decline', { user_id: b.getAttribute('data-dec') }); refreshFriends();
      }));
      root.querySelectorAll('[data-rm]').forEach((b) => b.addEventListener('click', async () => {
        await accountPost('social_friend_remove', { user_id: b.getAttribute('data-rm') }); refreshFriends();
      }));
    } catch (e) {
      root.textContent = e.message;
    }
  }

  async function openMod() {
    if (!isMod()) return;
    openOverlay('overlay-profile-mod');
    const root = document.getElementById('profile-mod-body');
    try {
      const data = await accountPost('social_mod_inbox', {});
      root.innerHTML = (data.reports || []).map((r) => `
        <div class="social-row" style="flex-wrap:wrap">
          <div><strong>${esc(r.username || r.target_id)}</strong> · ${esc(r.field)} · ${tt('profileMod.warns', 'Warnings')}: ${r.profile_warnings || 0}</div>
          <p>${esc(r.snippet || r.bio || '')}</p>
          <button type="button" data-act="clear_bio" data-id="${esc(r.target_id)}" data-rid="${r.id}">${tt('profileMod.clearBio', 'Clear bio')}</button>
          <button type="button" data-act="warn" data-id="${esc(r.target_id)}" data-rid="${r.id}">${tt('profileMod.warn', 'Warn')}</button>
          <button type="button" data-act="lock_bio" data-id="${esc(r.target_id)}" data-rid="${r.id}">${tt('profileMod.lockBio', 'Lock bio')}</button>
          <button type="button" data-act="dismiss" data-id="${esc(r.target_id)}" data-rid="${r.id}">${tt('profileMod.dismiss', 'Dismiss')}</button>
        </div>`).join('') || `<p>${tt('profileMod.empty', 'No open reports.')}</p>`;
      root.querySelectorAll('[data-act]').forEach((b) => {
        b.addEventListener('click', async () => {
          await accountPost('social_mod_action', {
            user_id: b.getAttribute('data-id'),
            report_id: parseInt(b.getAttribute('data-rid') || '0', 10),
            mod_action: b.getAttribute('data-act'),
          });
          openMod();
        });
      });
    } catch (e) {
      root.textContent = e.message;
    }
  }

  function bind() {
    document.getElementById('btn-social-profile')?.addEventListener('click', () => openProfile());
    document.getElementById('btn-social-friends')?.addEventListener('click', () => openFriends());
    document.getElementById('btn-social-mod')?.addEventListener('click', () => openMod());
    document.getElementById('btn-profile-close')?.addEventListener('click', () => closeOverlay('overlay-profile'));
    document.getElementById('btn-profile-stats-close')?.addEventListener('click', () => closeOverlay('overlay-profile-stats'));
    document.getElementById('btn-friends-close')?.addEventListener('click', () => closeOverlay('overlay-friends'));
    document.getElementById('btn-profile-mod-close')?.addEventListener('click', () => closeOverlay('overlay-profile-mod'));
  }

  global.syncSocialRail = syncSocialRail;
  global.openSocialProfile = openProfile;
  global.closeAllSocialOverlays = closeAllSocial;
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', bind);
  else bind();
})(window);
