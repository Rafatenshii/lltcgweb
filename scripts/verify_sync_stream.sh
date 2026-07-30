#!/usr/bin/env bash
# Phase 1E — verify SSE path is Apache→VPS (not Hostinger PHP) and API ping works.
set -euo pipefail
BASE="${TCG_BASE_URL:-https://loveliveradio.ca/tcg}"

echo "==> GET ${BASE}/api.php?action=ping"
curl -fsS "${BASE}/api.php?action=ping" | head -c 200
echo

echo "==> HEAD ${BASE}/sync-stream (expect 401 without ticket or event-stream)"
code=$(curl -sS -o /tmp/tcg_sync_head.txt -w "%{http_code}" \
  -H "Accept: text/event-stream" \
  "${BASE}/sync-stream?room_id=TEST&ticket=invalid&last_seq=0" || true)
echo "HTTP ${code}"
head -c 300 /tmp/tcg_sync_head.txt 2>/dev/null || true
echo

if [[ "${code}" == "000" ]]; then
  echo "FAIL: could not reach sync-stream (proxy/DNS?)" >&2
  exit 1
fi

# 401 from VPS ticket check = Apache proxy reached wrapped_api. 404 = RewriteRule missing.
if [[ "${code}" == "404" ]]; then
  echo "FAIL: sync-stream 404 — deploy tcg/.htaccess with RewriteRule sync-stream" >&2
  exit 1
fi

echo "OK: sync-stream reachable (HTTP ${code}). In-browser: EventSource should use /tcg/sync-stream."
echo "Client stats after a match: tcgSyncStatsSnapshot() in DevTools."
