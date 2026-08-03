/**
 * Daily login bonus calendar (JST) — 5×2 grid, claim on first hub visit of the day.
 */
(function (global) {
  'use strict';

  const SHOWN_KEY = 'tcg_login_bonus_shown_jst';
  const state = {
    payload: null,
    open: false,
    claiming: false,
    pendingPrReward: null,
  };

  function el(id) {
    return document.getElementById(id);
  }

  function t(key, vars) {
    const fn = global.LLTCG_I18N && global.LLTCG_I18N.t;
    return typeof fn === 'function' ? fn(key, vars || {}) : key;
  }

  function sfxPlay(id, opts) {
    try { global.LLTCG_SFX?.play?.(id, opts); } catch (e) { /* ignore */ }
  }

  function isSignedIn() {
    return typeof global.isSignedInAccount === 'function' && global.isSignedInAccount();
  }

  function isHubActive() {
    return !!el('screen-hub')?.classList.contains('active');
  }

  function overlayEl() {
    return el('overlay-login-bonus');
  }

  function syncHubButton(showDot) {
    const btn = el('hub-login-bonus');
    const dot = el('hub-login-bonus-dot');
    if (!btn) return;
    btn.hidden = !isSignedIn();
    if (dot) {
      const claimable = !!(state.payload && !state.payload.claimed_today);
      dot.hidden = !(showDot || claimable);
    }
  }

  function dayLabel(day) {
    const label = day.label || day.type;
    if (day.type === 'gems') {
      return t('loginBonus.reward.gems', { amount: day.amount }) || (day.amount + ' Gems');
    }
    if (day.type === 'seals' && (label === 'seals_sr' || day.tier === 'R')) {
      return t('loginBonus.reward.srSeal', { amount: day.amount })
        || ((day.amount > 1 ? day.amount + ' ' : '') + 'SR Seal');
    }
    if (day.type === 'seals') {
      return t('loginBonus.reward.nSeals', { amount: day.amount })
        || (day.amount + ' N Seals');
    }
    if (day.type === 'pr_pack') {
      return t('loginBonus.reward.prPack') || 'PR Pack';
    }
    return label;
  }

  function dayIcon(day) {
    if (day.type === 'gems') {
      return '<img class="login-bonus-icon" src="assets/Star_Gem.png" alt="" aria-hidden="true">';
    }
    if (day.type === 'seals' && (day.label === 'seals_sr' || day.tier === 'R')) {
      return '<img class="login-bonus-icon" src="assets/seals/R.png" alt="" aria-hidden="true">';
    }
    if (day.type === 'seals') {
      return '<img class="login-bonus-icon" src="assets/seals/N.png" alt="" aria-hidden="true">';
    }
    if (day.type === 'pr_pack') {
      return '<span class="login-bonus-pr-badge" aria-hidden="true">PR</span>';
    }
    return '';
  }

  function renderGrid(payload) {
    const grid = el('login-bonus-grid');
    if (!grid || !payload?.days) return;
    grid.replaceChildren();
    payload.days.forEach((day) => {
      const cell = document.createElement('div');
      cell.className = 'login-bonus-cell login-bonus-cell--' + (day.status || 'locked');
      cell.dataset.index = String(day.index);
      cell.innerHTML =
        '<span class="login-bonus-day">' + t('loginBonus.day', { day: day.day }) + '</span>'
        + '<span class="login-bonus-icon-wrap">' + dayIcon(day) + '</span>'
        + '<span class="login-bonus-reward">' + dayLabel(day) + '</span>';
      grid.appendChild(cell);
    });
  }

  function applyCurrencyFromPayload(payload) {
    if (!payload) return;
    if (typeof payload.star_gems === 'number' && global.A) {
      global.A.starGems = payload.star_gems;
      if (global.A.profile) global.A.profile.star_gems = payload.star_gems;
      if (typeof global.updateStarGemsUI === 'function') global.updateStarGemsUI();
    }
    if (payload.seals && typeof global.syncSealsFromPayload === 'function') {
      global.syncSealsFromPayload({ seals: payload.seals });
    }
  }

  function closeOverlay() {
    const ov = overlayEl();
    if (!ov) return;
    ov.classList.remove('open');
    ov.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('login-bonus-overlay-open');
    state.open = false;
    const pr = state.pendingPrReward;
    state.pendingPrReward = null;
    if (pr && typeof global.queueRankedPrReward === 'function') {
      if (typeof global.playRankedPrReveal === 'function') {
        // Prefer immediate reveal after login calendar (same UI as ranked PR).
        void global.playRankedPrReveal(pr);
      } else {
        global.queueRankedPrReward(pr);
        if (typeof global.schedulePendingRankedPrReward === 'function') {
          global.schedulePendingRankedPrReward();
        }
      }
    }
  }

  function openOverlay() {
    const ov = overlayEl();
    if (!ov) return;
    ov.classList.add('open');
    ov.setAttribute('aria-hidden', 'false');
    document.body.classList.add('login-bonus-overlay-open');
    state.open = true;
    sfxPlay('screen_open', { volume: 0.75 });
  }

  async function animateJustClaimed() {
    const cell = el('login-bonus-grid')?.querySelector('.login-bonus-cell--just_claimed');
    if (!cell) return;
    cell.classList.add('is-claiming');
    sfxPlay('notify', { volume: 0.95 });
    await new Promise((r) => setTimeout(r, 700));
    cell.classList.remove('is-claiming');
    cell.classList.add('is-claimed-pop');
    sfxPlay('menu_confirm', { volume: 0.9 });
    await new Promise((r) => setTimeout(r, 450));
  }

  function markShownToday(dateJst) {
    try {
      if (dateJst) localStorage.setItem(SHOWN_KEY, String(dateJst));
    } catch (e) { /* ignore */ }
  }

  function wasShownToday(dateJst) {
    try {
      return localStorage.getItem(SHOWN_KEY) === String(dateJst || '');
    } catch (e) {
      return false;
    }
  }

  async function fetchStatus() {
    if (typeof global.accountGet !== 'function') return null;
    return global.accountGet('login_bonus_status');
  }

  async function fetchClaim() {
    if (typeof global.accountPost !== 'function') return null;
    return global.accountPost('login_bonus_claim', {});
  }

  function setRewardLead(payload) {
    const lead = el('login-bonus-lead');
    if (!lead) return;
    if (payload?.just_claimed && payload.reward) {
      const r = payload.reward;
      let text = '';
      if (r.type === 'gems') {
        text = t('loginBonus.gotGems', { amount: r.amount }) || ('Received ' + r.amount + ' Star Gems!');
      } else if (r.type === 'seals' && (r.label === 'seals_sr' || r.tier === 'R')) {
        text = t('loginBonus.gotSrSeal', { amount: r.amount }) || ('Received ' + r.amount + ' SR Seal!');
      } else if (r.type === 'seals') {
        text = t('loginBonus.gotNSeals', { amount: r.amount }) || ('Received ' + r.amount + ' N Seals!');
      } else if (r.type === 'pr_pack') {
        text = t('loginBonus.gotPrPack') || 'Received a PR pack! Opening…';
      }
      lead.textContent = text;
      return;
    }
    lead.textContent = t('loginBonus.lead')
      || 'Log in each day (JST) to claim the next bonus. Missed days are skipped — your streak stays.';
  }

  async function openLoginBonus(opts = {}) {
    if (!isSignedIn()) return;
    const force = !!opts.force;
    const auto = !!opts.auto;
    if (state.claiming) return;
    state.claiming = true;
    const err = el('login-bonus-err');
    if (err) err.textContent = '';
    try {
      // Claim is idempotent for the JST day — safe for both auto and manual open.
      const payload = (auto || force)
        ? await fetchClaim()
        : await fetchStatus();
      if (!payload?.success) return;
      state.payload = payload;
      applyCurrencyFromPayload(payload);
      renderGrid(payload);
      setRewardLead(payload);
      syncHubButton(false);

      if (auto && wasShownToday(payload.date_jst)) return;

      openOverlay();
      markShownToday(payload.date_jst);
      if (payload.just_claimed) {
        await animateJustClaimed();
        if (payload.reward?.type === 'pr_pack' && Array.isArray(payload.reward.cards)) {
          state.pendingPrReward = payload.reward;
        }
      }
    } catch (e) {
      if (err) err.textContent = e.message || String(e);
      if (force) openOverlay();
    } finally {
      state.claiming = false;
    }
  }

  function scheduleHubLoginBonus() {
    if (!isSignedIn()) return;
    const attempt = () => {
      if (!isHubActive()) {
        setTimeout(attempt, 200);
        return;
      }
      // Wait for migration / ranked PR overlays so the pack reveal is not buried.
      const migration = el('modal-star-gem-migration');
      if (migration?.classList.contains('open')) {
        setTimeout(attempt, 300);
        return;
      }
      if (typeof global.hasPendingRankedPrReward === 'function' && global.hasPendingRankedPrReward()) {
        setTimeout(attempt, 400);
        return;
      }
      const prOv = el('overlay-ranked-pr-reward');
      if (prOv?.classList.contains('active')) {
        setTimeout(attempt, 400);
        return;
      }
      void openLoginBonus({ auto: true });
    };
    setTimeout(attempt, 280);
  }

  function bindUi() {
    if (document.body.dataset.loginBonusBound) return;
    document.body.dataset.loginBonusBound = '1';
    el('hub-login-bonus')?.addEventListener('click', () => {
      void openLoginBonus({ force: true });
    });
    el('btn-login-bonus-close')?.addEventListener('click', closeOverlay);
    overlayEl()?.addEventListener('click', (e) => {
      if (e.target === overlayEl()) closeOverlay();
    });
  }

  global.TCGLoginBonus = {
    scheduleHubLoginBonus,
    openLoginBonus,
    closeOverlay,
    syncHubButton,
    bindUi,
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bindUi);
  } else {
    bindUi();
  }
})(window);
