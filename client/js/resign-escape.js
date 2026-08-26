/**
 * Softlock resign-escape URL rules (browser + Node).
 * Only an enabled ?resign on THIS load may concede. Stale sessionStorage
 * from an interrupted escape must not fire on later reloads (e.g. ?debug).
 */
(function (root, factory) {
  const api = factory();
  if (typeof module === 'object' && module.exports) {
    module.exports = api;
  }
  if (root) root.LLTCG_RESIGN_ESCAPE = api;
})(typeof globalThis !== 'undefined' ? globalThis : this, function () {
  'use strict';

  const STORAGE_KEY = 'tcg_resign_escape';

  function resignParamEnabled(raw) {
    const v = String(raw ?? '').trim().toLowerCase();
    return v === '' || !['0', 'false', 'no', 'off'].includes(v);
  }

  /**
   * @param {string} search location.search (with or without leading ?)
   * @returns {{
   *   escape: boolean,
   *   clearStale: boolean,
   *   setStale: boolean,
   *   stripResign: boolean,
   *   remainingSearch: string,
   * }}
   */
  function planResignEscapeFromSearch(search) {
    const rawSearch = String(search || '');
    const q = rawSearch.startsWith('?') ? rawSearch.slice(1) : rawSearch;
    const params = new URLSearchParams(q);
    if (!params.has('resign')) {
      // Drop leftover softlock flag so mid-match ?debug (or any non-resign reload)
      // reconnects instead of auto-resigning.
      return {
        escape: false,
        clearStale: true,
        setStale: false,
        stripResign: false,
        remainingSearch: rawSearch.startsWith('?') || rawSearch === '' ? rawSearch : `?${rawSearch}`,
      };
    }
    const enabled = resignParamEnabled(params.get('resign'));
    params.delete('resign');
    const qs = params.toString();
    const remainingSearch = qs ? `?${qs}` : '';
    if (!enabled) {
      return {
        escape: false,
        clearStale: true,
        setStale: false,
        stripResign: true,
        remainingSearch,
      };
    }
    return {
      escape: true,
      clearStale: false,
      setStale: true,
      stripResign: true,
      remainingSearch,
    };
  }

  /** True when URL enables verbose debug tooling (?debug / ?debug=all / …). */
  function hasDebugQuery(search) {
    const rawSearch = String(search || '');
    const q = rawSearch.startsWith('?') ? rawSearch.slice(1) : rawSearch;
    return new URLSearchParams(q).has('debug');
  }

  return {
    STORAGE_KEY,
    resignParamEnabled,
    planResignEscapeFromSearch,
    hasDebugQuery,
  };
});
