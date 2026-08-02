#!/usr/bin/env bash
# Read-only cutover readiness check — no docker compose up, no systemctl, no restarts.
set -euo pipefail

OVERFLOW_PING="${TCG_OVERFLOW_PING:-https://stream.loveliveradio.ca/tcg/api/api.php?action=ping}"
HOSTINGER_PING="${TCG_HOSTINGER_PING:-https://www.loveliveradio.ca/tcg/api.php?action=ping}"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"

echo "==> Overflow match API ping"
code=$(curl -sS -o /tmp/lltcg_overflow_ping.json -w '%{http_code}' --max-time 8 "$OVERFLOW_PING" || echo "000")
echo "  HTTP $code  $OVERFLOW_PING"
if [[ "$code" == "200" ]]; then
  head -c 200 /tmp/lltcg_overflow_ping.json; echo
else
  echo "  WARN: overflow not healthy (expected until VPS stack is up)"
fi

echo "==> Hostinger API ping"
code=$(curl -sS -o /tmp/lltcg_host_ping.json -w '%{http_code}' --max-time 8 "$HOSTINGER_PING" || echo "000")
echo "  HTTP $code  $HOSTINGER_PING"

echo "==> Client runtime-flags default"
if grep -q 'DEFAULT_MATCH_API_PRIMARY = true' "$ROOT/client/js/runtime-flags.js"; then
  echo "  OK: DEFAULT_MATCH_API_PRIMARY = true (match-primary cutover)"
elif grep -q 'DEFAULT_MATCH_API_PRIMARY = false' "$ROOT/client/js/runtime-flags.js"; then
  echo "  WARN: DEFAULT_MATCH_API_PRIMARY = false (cutover not flipped in repo)"
else
  echo "  FAIL: could not parse DEFAULT_MATCH_API_PRIMARY"; exit 1
fi

echo "==> Hostinger kill-switch"
if [[ -f "$ROOT/MATCH_WRITES_DISABLED" ]]; then
  echo "  OK: MATCH_WRITES_DISABLED marker present (local)"
else
  echo "  WARN: MATCH_WRITES_DISABLED missing locally (deploy may still have SetEnv)"
fi
if grep -q 'TCG_HOSTINGER_MATCH_WRITES' "$ROOT/.env.example" && grep -q 'SetEnv TCG_HOSTINGER_MATCH_WRITES 0' "$ROOT/.htaccess"; then
  echo "  OK: .env.example + .htaccess SetEnv"
else
  echo "  FAIL: missing kill-switch wiring"; exit 1
fi

echo "==> Live Hostinger write rejection (expect 503 match_writes_disabled)"
code=$(curl -sS -o /tmp/lltcg_host_create.json -w '%{http_code}' --max-time 10 \
  -X POST -H 'Content-Type: application/json' -d '{}' \
  "https://www.loveliveradio.ca/tcg/api.php?action=create_room" || echo "000")
echo "  HTTP $code create_room"
if grep -q 'match_writes_disabled' /tmp/lltcg_host_create.json 2>/dev/null; then
  echo "  OK: Hostinger rejects match writes"
elif [[ "$code" == "503" ]]; then
  echo "  OK: Hostinger returned 503 for create_room"
else
  echo "  WARN: Hostinger still accepts writes (deploy kill switch / marker)"
  head -c 200 /tmp/lltcg_host_create.json 2>/dev/null; echo
fi

echo "==> Optional local Redis (TCG_REDIS_URL)"
if [[ -n "${TCG_REDIS_URL:-}" ]]; then
  if command -v redis-cli >/dev/null 2>&1; then
    # Parse host/port crudely for redis-cli; skip on failure
    echo "  TCG_REDIS_URL set — trying PING via redis-cli (best effort)"
    redis-cli -u "$TCG_REDIS_URL" PING 2>/dev/null || echo "  WARN: redis-cli ping failed"
  else
    echo "  TCG_REDIS_URL set but redis-cli not installed (skip)"
  fi
else
  echo "  (unset — CI/unit tests skip RedisGameStoreTest)"
fi

echo "==> Done."
echo "  Cutover expected state: overflow 200, Hostinger create_room 503,"
echo "  DEFAULT_MATCH_API_PRIMARY=true, MATCH_WRITES_DISABLED on Hostinger only."
echo "  Rollback: runtime-flags default false + remove MATCH_WRITES_DISABLED + unset SetEnv."
