/** Card sleeves — catalog, deck picker, and match back/peek apply. */
(function (global) {
  'use strict';

  const DEFAULT_BACK = 'lltcg-back.png';
  const CONFORM_KEY = 'tcg_sleeve_conform';

  /**
   * Equippable sleeves. Leave empty until assets are wired in.
   * Each entry: { id, name, src } — src is a site-relative image path.
   * @type {{ id: string, name: string, src: string }[]}
   */
  const SLEEVE_CATALOG = [];

  function normalizeSleeveId(raw) {
    const id = String(raw == null ? '' : raw).trim().toLowerCase();
    if (!id || id === 'none' || id === 'default') return '';
    if (!/^[a-z0-9][a-z0-9._-]{0,63}$/.test(id)) return '';
    return id;
  }

  function getSleeve(id) {
    const key = normalizeSleeveId(id);
    if (!key) return null;
    return SLEEVE_CATALOG.find((s) => s.id === key) || null;
  }

  function sleeveImageUrl(id) {
    const s = getSleeve(id);
    return s && s.src ? String(s.src) : '';
  }

  function cssImageUrl(path) {
    if (!path) return '';
    return 'url("' + String(path).replace(/\\/g, '\\\\').replace(/"/g, '\\"') + '")';
  }

  function sleeveConformEnabled() {
    try {
      return localStorage.getItem(CONFORM_KEY) === '1';
    } catch (e) {
      return false;
    }
  }

  function syncSleeveConformSetting() {
    document.documentElement.classList.toggle('sleeve-conform', sleeveConformEnabled());
    const chk = document.getElementById('chk-sleeve-conform');
    if (chk) chk.checked = sleeveConformEnabled();
  }

  function initSleeveConformSetting() {
    try {
      if (localStorage.getItem(CONFORM_KEY) === null) localStorage.setItem(CONFORM_KEY, '0');
    } catch (e) {}
    syncSleeveConformSetting();
    const chk = document.getElementById('chk-sleeve-conform');
    if (chk && !chk.dataset.sleeveBound) {
      chk.dataset.sleeveBound = '1';
      chk.addEventListener('change', () => {
        try { localStorage.setItem(CONFORM_KEY, chk.checked ? '1' : '0'); } catch (e) {}
        syncSleeveConformSetting();
      });
    }
  }

  function applySeatSleeve(root, seat, sleeveId) {
    const url = sleeveImageUrl(sleeveId);
    const has = !!url;
    root.classList.toggle('has-' + seat + '-sleeve', has);
    if (has) {
      root.style.setProperty('--' + seat + '-sleeve-image', cssImageUrl(url));
    } else {
      root.style.removeProperty('--' + seat + '-sleeve-image');
    }
  }

  function clearMatchSleeves(root) {
    if (!root) return;
    root.classList.remove('has-my-sleeve', 'has-opp-sleeve');
    root.style.removeProperty('--my-sleeve-image');
    root.style.removeProperty('--opp-sleeve-image');
  }

  function applyMatchSleeves(state) {
    const root = document.getElementById('screen-game');
    if (!root) return;
    const myId = global.G && global.G.playerId;
    if (!state || !state.players || !myId) {
      clearMatchSleeves(root);
      return;
    }
    const oppId = myId === 'p1' ? 'p2' : 'p1';
    applySeatSleeve(root, 'my', state.players[myId] && state.players[myId].sleeve_id);
    applySeatSleeve(root, 'opp', state.players[oppId] && state.players[oppId].sleeve_id);
  }

  function makeSleeveTile(id, selected, sleeve) {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'deck-sleeve-tile' + (selected ? ' is-selected' : '');
    btn.dataset.sleeveId = id;
    const thumb = document.createElement('span');
    thumb.className = 'deck-sleeve-tile__art';
    if (sleeve && sleeve.src) {
      thumb.style.backgroundImage = cssImageUrl(sleeve.src);
    } else {
      thumb.classList.add('deck-sleeve-tile__art--none');
      thumb.style.backgroundImage = cssImageUrl(DEFAULT_BACK);
    }
    const label = document.createElement('span');
    label.className = 'deck-sleeve-tile__label';
    const tFn = typeof global.t === 'function' ? global.t : null;
    label.textContent = sleeve
      ? sleeve.name
      : ((tFn && tFn('deck.sleeveNone')) || 'None');
    btn.appendChild(thumb);
    btn.appendChild(label);
    btn.setAttribute('aria-pressed', selected ? 'true' : 'false');
    btn.title = label.textContent;
    return btn;
  }

  function renderDeckSleevePicker(selectedId, onPick) {
    const host = document.getElementById('deck-sleeve-picker');
    if (!host) return;
    const selected = normalizeSleeveId(selectedId);
    host.replaceChildren();
    host.appendChild(makeSleeveTile('', selected === '', null));
    SLEEVE_CATALOG.forEach((s) => {
      host.appendChild(makeSleeveTile(s.id, s.id === selected, s));
    });
    const hint = document.getElementById('deck-sleeve-empty-hint');
    if (hint) hint.hidden = SLEEVE_CATALOG.length > 0;
    host.querySelectorAll('.deck-sleeve-tile').forEach((btn) => {
      btn.addEventListener('click', () => {
        const next = normalizeSleeveId(btn.dataset.sleeveId);
        if (typeof onPick === 'function') onPick(next);
      });
    });
  }

  global.LLTCG_SLEEVES = {
    catalog: SLEEVE_CATALOG,
    defaultBack: DEFAULT_BACK,
    normalize: normalizeSleeveId,
    get: getSleeve,
    imageUrl: sleeveImageUrl,
    applyMatchSleeves,
    clearMatchSleeves,
    renderPicker: renderDeckSleevePicker,
    conformEnabled: sleeveConformEnabled,
    syncConform: syncSleeveConformSetting,
    initConform: initSleeveConformSetting,
  };
})(window);
