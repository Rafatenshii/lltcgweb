/** Card sleeves — catalog, deck picker, and match back/peek apply. */
(function (global) {
  'use strict';

  const DEFAULT_BACK = 'lltcg-back.png';
  const CONFORM_KEY = 'tcg_sleeve_conform';

  /** @type {{ id: string, name: string, src: string, group?: string, idol?: string, orientation?: string }[]} */
  let SLEEVE_CATALOG = [];
  let catalogLoadPromise = null;

  function normalizeSleeveId(raw) {
    const id = String(raw == null ? '' : raw).trim().toLowerCase();
    if (!id || id === 'none' || id === 'default') return '';
    if (!/^[a-z0-9][a-z0-9._-]{0,63}$/.test(id)) return '';
    return id;
  }

  /** Strip vendor branding + pack-count suffixes from catalog titles. */
  function cleanSleeveDisplayName(name) {
    let s = String(name == null ? '' : name).trim();
    if (!s) return '';
    s = s.replace(/\bbushiroad\b\s*/gi, '');
    s = s.replace(/\(\s*\d+\s*[- ]?\s*packs?\s*\)/gi, '');
    s = s.replace(/\s{2,}/g, ' ');
    s = s.replace(/\s+([:,])/g, '$1');
    s = s.replace(/([:,])\s*/g, '$1 ');
    return s.replace(/^[\s\-:]+|[\s\-:]+$/g, '');
  }

  function tSleeve(key, fallback, vars) {
    const tFn = typeof global.t === 'function' ? global.t : null;
    if (!tFn) return fallback;
    const v = tFn(key, vars);
    return (v && v !== key) ? v : fallback;
  }

  function localizeIdolLabel(raw, form) {
    const s = String(raw || '').trim();
    if (!s) return s;
    const I18N = global.LLTCG_I18N;
    if (I18N && typeof I18N.idolLocaleName === 'function') {
      const loc = I18N.idolLocaleName(s, { form: form === 'full' ? 'full' : 'given' });
      if (loc) return loc;
    }
    return s;
  }

  function localizeUnitLabel(raw) {
    const s = String(raw || '').trim();
    if (!s) return s;
    const I18N = global.LLTCG_I18N;
    if (I18N && typeof I18N.unitLocaleName === 'function') {
      const loc = I18N.unitLocaleName(s);
      if (loc) return loc;
    }
    return s;
  }

  /** Localize one person / unit fragment (may contain &). Prefer full names in titles. */
  function localizeSleevePersonName(raw, form) {
    const s = String(raw || '').trim();
    if (!s) return s;
    const prefer = form === 'given' ? 'given' : 'full';
    const parts = s.split(/\s*[&＆]\s*/);
    if (parts.length > 1) {
      return parts.map((p) => localizeIdolLabel(p.trim(), prefer) || p.trim()).filter(Boolean).join(' & ');
    }
    // Group / school labels inside 『』
    const unitish = localizeUnitLabel(s);
    if (unitish && unitish !== s) return unitish;
    const seriesKey = ({
      '蓮ノ空女学院': 'sleeveShop.series.hasunosoraSchool',
      'ラブライブ！蓮ノ空女学院スクールアイドルクラブ': 'sleeveShop.series.hasunosora',
      'ラブライブ！シリーズ': 'sleeveShop.series.loveliveSeries',
    })[s];
    if (seriesKey) return tSleeve(seriesKey, s);
    return localizeIdolLabel(s, prefer) || s;
  }

  function localizeSleeveSeriesTitle(title) {
    let s = String(title || '');
    const pairs = [
      ['Love Live! Nijigasaki High School Idol Club', 'sleeveShop.series.nijigasaki'],
      ['Love Live! Hasunosora Girls\' High School Idol Club', 'sleeveShop.series.hasunosora'],
      ['Hasunosora Girls\' High School Idol Club', 'sleeveShop.series.hasunosoraShort'],
      ['Love Live! Super Star!!', 'sleeveShop.series.superstar'],
      ['Love Live! Superstar!!', 'sleeveShop.series.superstar'],
      ['Love Live! Sunshine!!', 'sleeveShop.series.sunshine'],
      ['ラブライブ！虹ヶ咲学園スクールアイドル同好会', 'sleeveShop.series.nijigasaki'],
      ['ラブライブ！蓮ノ空女学院スクールアイドルクラブ', 'sleeveShop.series.hasunosora'],
      ['蓮ノ空女学院スクールアイドルクラブ', 'sleeveShop.series.hasunosoraShort'],
      ['ラブライブ！スーパースター!!', 'sleeveShop.series.superstar'],
      ['ラブライブ！サンシャイン!!', 'sleeveShop.series.sunshine'],
      ['ラブライブ！シリーズ', 'sleeveShop.series.loveliveSeries'],
      ['Love Live! Series', 'sleeveShop.series.loveliveSeries'],
      ['蓮ノ空女学院', 'sleeveShop.series.hasunosoraSchool'],
      ['Hasunosora Girls\' High School', 'sleeveShop.series.hasunosoraSchool'],
      ['Love Live!', 'sleeveShop.series.lovelive'],
      ['ラブライブ！', 'sleeveShop.series.lovelive'],
      ['Sukufesu Series Thanksgiving', 'sleeveShop.series.sukufesuThanks'],
      ['スクフェスシリーズ感謝祭', 'sleeveShop.series.sukufesuThanks'],
      ["Mu's", 'sleeveShop.series.muse'],
      ["µ's", 'sleeveShop.series.muse'],
      ["μ's", 'sleeveShop.series.muse'],
    ];
    pairs.sort((a, b) => b[0].length - a[0].length);
    pairs.forEach(([en, key]) => {
      if (!s.includes(en)) return;
      const rep = tSleeve(key, en);
      s = s.split(en).join(rep);
    });
    // EN "Series - Person" / Part / pack notes
    s = s.replace(/(\s-\s)([A-Za-z][A-Za-z .''-]+?)(?=(\s+Part\.\d+)?(\s*\([^)]*\))?$)/, (_, dash, person) => {
      return dash + localizeSleevePersonName(person.trim(), 'full');
    });
    // JP 『name』 or 『a&b』 — keep corner brackets only for Japanese UI.
    s = s.replace(/『([^』]+)』/g, (_, inner) => {
      const person = localizeSleevePersonName(inner.trim(), 'full');
      const loc = (global.LLTCG_I18N && typeof global.LLTCG_I18N.getLocale === 'function')
        ? global.LLTCG_I18N.getLocale()
        : 'en';
      return loc === 'ja' ? ('『' + person + '』') : person;
    });
    return s;
  }

  function stripRebirthVer(s) {
    return String(s || '')
      .replace(/\s*Reバース\s*ver\.?\s*$/i, '')
      .replace(/\s*ReBirth\s*ver\.?\s*$/i, '')
      .trim();
  }

  /** Localized sleeve catalog title for shop / deck picker. Future titles keep working via patterns. */
  function formatSleeveDisplayName(sleeveOrName) {
    const rawIn = typeof sleeveOrName === 'string'
      ? sleeveOrName
      : (sleeveOrName && (sleeveOrName.name || sleeveOrName.id)) || '';
    let raw = cleanSleeveDisplayName(rawIn);
    if (!raw) return '';

    // Vendor prefixes (JP catalog leftovers)
    raw = raw.replace(/^ブシロード\s*/u, '');
    raw = raw.replace(/^Bushiroad\s*/i, '');

    const bday = raw.match(/^(.+?)\s+Birthday Visual\s+(\d{4})$/i);
    if (bday) {
      const person = localizeSleevePersonName(bday[1].trim(), 'full');
      const year = bday[2];
      return tSleeve(
        'sleeveShop.bdayName',
        person + ' Birthday Visual ' + year,
        { name: person, year }
      );
    }

    const hg = raw.match(/^Sleeve Collection HG Vol\.?\s*(\d+)\s*:?\s*(.+)$/i);
    if (hg) {
      const vol = hg[1];
      const title = localizeSleeveSeriesTitle(hg[2].trim());
      return tSleeve(
        'sleeveShop.hgVolName',
        'Sleeve Collection HG Vol.' + vol + ': ' + title,
        { vol, title }
      );
    }

    let rebirthSuffix = '';
    const stripped = stripRebirthVer(raw);
    if (stripped !== raw) {
      rebirthSuffix = ' (' + tSleeve('sleeveShop.rebirthVer', 'ReBirth ver.') + ')';
      raw = stripped;
    }

    const extraVol = raw.match(/^スリーブコレクション\s*エクストラ\s*Vol\.?\s*(\d+)\s*(.+)$/u)
      || raw.match(/^Sleeve Collection Extra Vol\.?\s*(\d+)\s*:?\s*(.+)$/i);
    if (extraVol) {
      const vol = extraVol[1];
      const title = localizeSleeveSeriesTitle(extraVol[2].trim());
      return tSleeve(
        'sleeveShop.extraVolName',
        'Sleeve Collection Extra Vol.' + vol + ': ' + title,
        { vol, title }
      ) + rebirthSuffix;
    }

    const extra = raw.match(/^スリーブコレクション\s*エクストラ\s*(.+)$/u)
      || raw.match(/^Sleeve Collection Extra\s*:?\s*(.+)$/i);
    if (extra) {
      const title = localizeSleeveSeriesTitle(extra[1].trim());
      return tSleeve(
        'sleeveShop.extraName',
        'Sleeve Collection Extra: ' + title,
        { title }
      ) + rebirthSuffix;
    }

    const deluxe = raw.match(/^Reバース\s*for\s*you\s*スリーブ\s*&\s*カード\s*デラックスセット\s*(.+)$/u)
      || raw.match(/^ReBirth\s*for\s*you\s*Sleeve\s*&\s*Card\s*Deluxe Set\s*:?\s*(.+)$/i);
    if (deluxe) {
      const title = localizeSleeveSeriesTitle(deluxe[1].trim());
      return tSleeve(
        'sleeveShop.rebirthDeluxeName',
        'ReBirth for you Sleeve & Card Deluxe Set: ' + title,
        { title }
      ) + rebirthSuffix;
    }

    return localizeSleeveSeriesTitle(raw) + rebirthSuffix;
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

  function isLandscapeSleeve(sleeveOrId) {
    const s = typeof sleeveOrId === 'string' ? getSleeve(sleeveOrId) : sleeveOrId;
    return !!(s && String(s.orientation || '').toLowerCase() === 'landscape');
  }

  function applySeatSleeve(root, seat, sleeveId) {
    const sleeve = getSleeve(sleeveId);
    const url = sleeve && sleeve.src ? String(sleeve.src) : '';
    const has = !!url;
    const landscape = has && isLandscapeSleeve(sleeve);
    root.classList.toggle('has-' + seat + '-sleeve', has);
    root.classList.toggle('has-' + seat + '-sleeve-landscape', landscape);
    if (has) {
      root.style.setProperty('--' + seat + '-sleeve-image', cssImageUrl(url));
    } else {
      root.style.removeProperty('--' + seat + '-sleeve-image');
    }
  }

  function clearMatchSleeves(root) {
    if (!root) return;
    root.classList.remove('has-my-sleeve', 'has-opp-sleeve', 'has-my-sleeve-landscape', 'has-opp-sleeve-landscape');
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
      if (isLandscapeSleeve(sleeve)) thumb.classList.add('is-landscape');
    } else {
      thumb.classList.add('deck-sleeve-tile__art--none');
      thumb.style.backgroundImage = cssImageUrl(DEFAULT_BACK);
    }
    const label = document.createElement('span');
    label.className = 'deck-sleeve-tile__label';
    const tFn = typeof global.t === 'function' ? global.t : null;
    label.textContent = sleeve
      ? formatSleeveDisplayName(sleeve)
      : ((tFn && tFn('deck.sleeveNone')) || 'None');
    btn.appendChild(thumb);
    btn.appendChild(label);
    btn.setAttribute('aria-pressed', selected ? 'true' : 'false');
    btn.title = label.textContent;
    return btn;
  }

  function ownedSet() {
    const list = (global.A && Array.isArray(global.A.ownedSleeves)) ? global.A.ownedSleeves : [];
    return new Set(list.map(normalizeSleeveId).filter(Boolean));
  }

  function renderDeckSleevePicker(selectedId, onPick) {
    const host = document.getElementById('deck-sleeve-picker');
    if (!host) return;
    const selected = normalizeSleeveId(selectedId);
    const owned = ownedSet();
    const visible = SLEEVE_CATALOG.filter((s) => owned.has(s.id));
    host.replaceChildren();
    host.appendChild(makeSleeveTile('', selected === '', null));
    visible.forEach((s) => {
      host.appendChild(makeSleeveTile(s.id, s.id === selected, s));
    });
    const hint = document.getElementById('deck-sleeve-empty-hint');
    if (hint) {
      hint.hidden = false;
      const tFn = typeof global.t === 'function' ? global.t : null;
      hint.textContent = visible.length
        ? ((tFn && tFn('deck.sleeveOwnedHint')) || 'Owned sleeves from the Sleeve Shop.')
        : ((tFn && tFn('deck.sleeveEmptyHint')) || 'Buy sleeves in the Sleeve Shop.');
    }
    host.querySelectorAll('.deck-sleeve-tile').forEach((btn) => {
      btn.addEventListener('click', () => {
        const next = normalizeSleeveId(btn.dataset.sleeveId);
        if (typeof onPick === 'function') onPick(next);
      });
    });
  }

  async function loadCatalog() {
    if (SLEEVE_CATALOG.length) return SLEEVE_CATALOG;
    if (catalogLoadPromise) return catalogLoadPromise;
    catalogLoadPromise = (async () => {
      try {
        const r = await fetch('sleeves_catalog.json', { cache: 'no-cache' });
        if (!r.ok) return SLEEVE_CATALOG;
        const data = await r.json();
        const items = Array.isArray(data?.items) ? data.items : (Array.isArray(data) ? data : []);
        SLEEVE_CATALOG = items
          .map((row) => ({
            id: normalizeSleeveId(row.id),
            name: cleanSleeveDisplayName(row.name || row.id || ''),
            src: String(row.src || ''),
            group: String(row.group || ''),
            idol: String(row.idol || ''),
            orientation: String(row.orientation || 'portrait').toLowerCase() === 'landscape'
              ? 'landscape'
              : 'portrait',
          }))
          .filter((s) => s.id && s.src);
        global.LLTCG_SLEEVES.catalog = SLEEVE_CATALOG;
      } catch (e) {
        /* keep empty */
      }
      return SLEEVE_CATALOG;
    })();
    return catalogLoadPromise;
  }

  /**
   * Slide sleeve art over default card back, then shine.
   * @param {string} sleeveId
   * @param {{ force?: boolean }} [opts]
   */
  function playEquipIntro(sleeveId, opts) {
    const id = normalizeSleeveId(sleeveId);
    const sleeve = getSleeve(id);
    if (!id || !sleeve?.src) return Promise.resolve();
    if (typeof matchMedia === 'function' && matchMedia('(prefers-reduced-motion: reduce)').matches) {
      return Promise.resolve();
    }
    return new Promise((resolve) => {
      let overlay = document.getElementById('sleeve-equip-intro');
      if (!overlay) {
        overlay = document.createElement('div');
        overlay.id = 'sleeve-equip-intro';
        overlay.className = 'sleeve-equip-intro';
        overlay.innerHTML = '<div class="sleeve-equip-intro__stage">'
          + '<div class="sleeve-equip-intro__back"></div>'
          + '<div class="sleeve-equip-intro__sleeve"></div>'
          + '<div class="sleeve-equip-intro__shine"></div>'
          + '</div>';
        document.body.appendChild(overlay);
      }
      const back = overlay.querySelector('.sleeve-equip-intro__back');
      const sleeveEl = overlay.querySelector('.sleeve-equip-intro__sleeve');
      if (back) back.style.backgroundImage = cssImageUrl(DEFAULT_BACK);
      if (sleeveEl) {
        sleeveEl.style.backgroundImage = cssImageUrl(sleeve.src);
        sleeveEl.classList.toggle('is-landscape', isLandscapeSleeve(sleeve));
        sleeveEl.classList.remove('is-in');
      }
      overlay.classList.add('is-open');
      requestAnimationFrame(() => {
        sleeveEl?.classList.add('is-in');
        overlay.classList.add('is-shine');
      });
      const done = () => {
        overlay.classList.remove('is-open', 'is-shine');
        sleeveEl?.classList.remove('is-in');
        resolve();
      };
      setTimeout(done, 1400);
    });
  }

  global.LLTCG_SLEEVES = {
    get catalog() { return SLEEVE_CATALOG; },
    set catalog(v) { SLEEVE_CATALOG = Array.isArray(v) ? v : []; },
    defaultBack: DEFAULT_BACK,
    normalize: normalizeSleeveId,
    get: getSleeve,
    imageUrl: sleeveImageUrl,
    isLandscape: isLandscapeSleeve,
    displayName: formatSleeveDisplayName,
    idolName: localizeIdolLabel,
    unitName: localizeUnitLabel,
    applyMatchSleeves,
    clearMatchSleeves,
    renderPicker: renderDeckSleevePicker,
    loadCatalog,
    playEquipIntro,
    conformEnabled: sleeveConformEnabled,
    syncConform: syncSleeveConformSetting,
    initConform: initSleeveConformSetting,
  };

  void loadCatalog();
})(window);
