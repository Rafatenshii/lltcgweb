#!/usr/bin/env bash
# Cut over TCG dynamic API from Hostinger PHP to VPS Docker (:5003).
# Run from Chiichan repo root on an operator machine with:
#   - SSH to VPS (root@45.76.173.164 or configured host)
#   - Hostinger FTP/.env.deploy for marker upload
#   - Local lltcgweb sibling with data/ to seed (optional)
#
# Does NOT restart wrapped-api or chisato. Starts/updates lltcgweb-api container only.
set -euo pipefail

VPS_HOST="${VPS_HOST:-root@45.76.173.164}"
VPS_TCG_DIR="${VPS_TCG_DIR:-/home/discord/bots/lltcgweb}"
LLTCGWEB_ROOT="${LLTCGWEB_ROOT:-$(cd "$(dirname "$0")/../../lltcgweb" 2>/dev/null && pwd || true)}"
if [[ -z "${LLTCGWEB_ROOT}" || ! -d "${LLTCGWEB_ROOT}" ]]; then
  LLTCGWEB_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
  # When script lives in lltcgweb/scripts
  if [[ ! -f "${LLTCGWEB_ROOT}/api.php" ]]; then
    LLTCGWEB_ROOT="$(cd "$(dirname "$0")/../.." && pwd)/lltcgweb"
  fi
fi

echo "==> VPS host: ${VPS_HOST}"
echo "==> VPS dir:  ${VPS_TCG_DIR}"
echo "==> Local:    ${LLTCGWEB_ROOT}"

if [[ ! -f "${LLTCGWEB_ROOT}/api.php" ]]; then
  echo "error: cannot find lltcgweb api.php at ${LLTCGWEB_ROOT}" >&2
  exit 1
fi

echo "==> Sync code to VPS (excludes runtime data volumes if already seeded)"
rsync -az --delete \
  --exclude '.git/' \
  --exclude 'data/tcg.db' \
  --exclude 'data/rate_limits/' \
  --exclude 'games/*.json' \
  --exclude 'cardimg/' \
  --exclude 'node_modules/' \
  "${LLTCGWEB_ROOT}/" "${VPS_HOST}:${VPS_TCG_DIR}/"

if [[ "${SYNC_DATA:-0}" == "1" ]]; then
  echo "==> Syncing data/ and games/ (SYNC_DATA=1)"
  rsync -az \
    "${LLTCGWEB_ROOT}/data/" "${VPS_HOST}:${VPS_TCG_DIR}/data/" || true
  rsync -az \
    "${LLTCGWEB_ROOT}/games/" "${VPS_HOST}:${VPS_TCG_DIR}/games/" || true
fi

echo "==> Build/start tcg-api on VPS"
ssh "${VPS_HOST}" "mkdir -p '${VPS_TCG_DIR}' && cd '${VPS_TCG_DIR}' && docker compose -f compose.prod.yaml up -d --build"

echo "==> Health check"
for i in 1 2 3 4 5 6 7 8 9 10; do
  if curl -fsS --connect-timeout 3 "http://45.76.173.164:5003/api.php?action=ping" >/dev/null 2>&1; then
    echo "VPS TCG API healthy on :5003"
    break
  fi
  if [[ "$i" -eq 10 ]]; then
    echo "error: VPS API health check failed" >&2
    exit 1
  fi
  sleep 2
done

echo "==> Done. To flip Hostinger traffic: deploy .htaccess and upload empty file tcg/USE_VPS_API"
echo "    Example: LLR_SITE_FILES='tcg/.htaccess tcg/USE_VPS_API' ./scripts/deploy-loveliveradio-ca.sh"
echo "    Create empty USE_VPS_API locally first: touch ../lltcgweb/USE_VPS_API"
