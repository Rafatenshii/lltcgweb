#!/usr/bin/env bash
# Phase 1E — verify VPS nginx SSE path (and Hostinger API ping).
set -eu
BASE="${TCG_BASE_URL:-https://loveliveradio.ca/tcg}"
SYNC="${TCG_SYNC_URL:-https://stream.loveliveradio.ca/tcg/sync/stream}"

echo "==> GET ${BASE}/api.php?action=ping"
curl -fsS "${BASE}/api.php?action=ping"
echo

echo "==> GET ${SYNC} (expect 401 without valid ticket)"
code=$(curl -sS -o /tmp/tcg_sync_body.txt -w "%{http_code}" \
  -H "Accept: text/event-stream" \
  -H "Origin: https://loveliveradio.ca" \
  "${SYNC}?room_id=TEST&ticket=invalid&last_seq=0" || true)
echo "HTTP ${code}"
head -c 200 /tmp/tcg_sync_body.txt 2>/dev/null || true
echo

if [[ "${code}" == "000" ]]; then
  echo "FAIL: could not reach sync stream" >&2
  exit 1
fi
if [[ "${code}" == "404" ]]; then
  echo "FAIL: sync stream 404 — check nginx location /tcg/sync/stream" >&2
  exit 1
fi
# 401 = ticket rejected by VPS hub (proxy path OK)
echo "OK: sync stream reachable (HTTP ${code}). Client EventSource should use stream.loveliveradio.ca."
echo "In-browser after a match: tcgSyncStatsSnapshot()"
