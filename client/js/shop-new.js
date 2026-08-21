/**
 * Shop "New" badges for playmats / sleeves — same idea as news FAB highlights.
 * Items with catalog `added_at` stay new until the player opens that idol's grid
 * (markCharacterSeen); leaving via ← Shop must also mark if a grid was open.
 */
(function (global) {
  'use strict';

  const SEEN_KEY = 'lltcg.shop.cosmeticsSeen';
  let _seen = null;

  function t(key, vars) {
    const fn = global.LLTCG_I18N && global.LLTCG_I18N.t;
    return typeof fn === 'function' ? fn(key, vars) : key;
  }

  function readSeen() {
    if (_seen) return _seen;
    try {
      const raw = localStorage.getItem(SEEN_KEY);
      const parsed = raw ? JSON.parse(raw) : null;
      _seen = {
        playmats: { ...(parsed?.playmats || {}) },
        sleeves: { ...(parsed?.sleeves || {}) },
      };
    } catch (e) {
      _seen = { playmats: {}, sleeves: {} };
    }
    return _seen;
  }

  function writeSeen() {
    const data = readSeen();
    try {
      localStorage.setItem(SEEN_KEY, JSON.stringify(data));
    } catch (e) { /* ignore */ }
  }

  function kindBucket(kind) {
    const data = readSeen();
    return kind === 'sleeves' ? data.sleeves : data.playmats;
  }

  function itemIsNew(kind, item) {
    if (!item || !item.id) return false;
    if (!item.added_at && !item.addedAt && !item.is_new) return false;
    return !kindBucket(kind)[String(item.id)];
  }

  function listHasNew(kind, items) {
    return (items || []).some((it) => itemIsNew(kind, it));
  }

  function charHasNew(kind, character) {
    const list = kind === 'sleeves' ? character?.sleeves : character?.playmats;
    return listHasNew(kind, list);
  }

  function genHasNew(kind, gen) {
    return (gen?.characters || []).some((c) => charHasNew(kind, c));
  }

  function catalogHasNew(kind, generations) {
    return (generations || []).some((g) => genHasNew(kind, g));
  }

  function markItemsSeen(kind, items) {
    const bucket = kindBucket(kind);
    let changed = false;
    (items || []).forEach((it) => {
      const id = String(it?.id || '');
      if (!id || bucket[id]) return;
      if (!it.added_at && !it.addedAt && !it.is_new) return;
      bucket[id] = true;
      changed = true;
    });
    if (changed) writeSeen();
    return changed;
  }

  function markCharacterSeen(kind, character) {
    const list = kind === 'sleeves' ? character?.sleeves : character?.playmats;
    return markItemsSeen(kind, list);
  }

  function newBadgeEl() {
    const span = document.createElement('span');
    span.className = 'shop-new-badge';
    span.setAttribute('aria-hidden', 'true');
    span.textContent = t('news.newBadge') || 'New!';
    return span;
  }

  function applyNewClass(el, isNew) {
    if (!el) return;
    el.classList.toggle('is-shop-new', !!isNew);
    const existing = el.querySelector(':scope > .shop-new-badge');
    if (isNew && !existing) el.appendChild(newBadgeEl());
    else if (!isNew && existing) existing.remove();
  }

  function refreshHubShopBadges(opts) {
    opts = opts || {};
    const playmatNew = !!opts.playmats;
    const sleeveNew = !!opts.sleeves;
    const any = playmatNew || sleeveNew;
    applyNewClass(document.getElementById('btn-hub-shop'), any);
    applyNewClass(document.getElementById('btn-shop-playmats'), playmatNew);
    applyNewClass(document.getElementById('btn-shop-sleeves'), sleeveNew);
  }

  global.LLTCG_SHOP_NEW = {
    itemIsNew,
    listHasNew,
    charHasNew,
    genHasNew,
    catalogHasNew,
    markItemsSeen,
    markCharacterSeen,
    applyNewClass,
    refreshHubShopBadges,
    newBadgeEl,
  };
})(window);
