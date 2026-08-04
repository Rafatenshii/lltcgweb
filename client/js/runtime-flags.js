/**
 * Runtime feature flags — load BEFORE api-client.js.
 * Cutover: set DEFAULT_MATCH_API_PRIMARY to true and redeploy this file only.
 *
 * Override order (first win):
 *  1. ?match_primary=0|1 query param
 *  2. localStorage.tcg_match_api_primary = '0'|'1'
 *  3. Committed default below
 */
(function (global) {
  'use strict';

  /** Production: VPS match-primary cutover (operator-approved). */
  var DEFAULT_MATCH_API_PRIMARY = true;

  function parseBool(raw, fallback) {
    if (raw == null || raw === '') return fallback;
    var s = String(raw).toLowerCase();
    if (s === '1' || s === 'true' || s === 'yes' || s === 'on') return true;
    if (s === '0' || s === 'false' || s === 'no' || s === 'off') return false;
    return fallback;
  }

  var fromQuery = null;
  try {
    var q = new URLSearchParams(global.location && global.location.search ? global.location.search : '');
    if (q.has('match_primary')) {
      fromQuery = parseBool(q.get('match_primary'), null);
    }
  } catch (e) { /* ignore */ }

  var fromStorage = null;
  try {
    if (global.localStorage) {
      var ls = global.localStorage.getItem('tcg_match_api_primary');
      if (ls != null && ls !== '') fromStorage = parseBool(ls, null);
    }
  } catch (e) { /* ignore */ }

  if (fromQuery !== null) {
    global.TCG_MATCH_API_PRIMARY = fromQuery;
  } else if (fromStorage !== null) {
    global.TCG_MATCH_API_PRIMARY = fromStorage;
  } else if (typeof global.TCG_MATCH_API_PRIMARY === 'undefined') {
    global.TCG_MATCH_API_PRIMARY = DEFAULT_MATCH_API_PRIMARY;
  }
})(typeof window !== 'undefined' ? window : globalThis);
