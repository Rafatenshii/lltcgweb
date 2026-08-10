#!/usr/bin/env bash
# Fast-forward pull lltcgweb on the VPS match host (/opt/lltcgweb → lltcgweb-api bind mount).
# No Docker/systemd restart. Safe to run after every Hostinger engine deploy + GitHub push.
set -euo pipefail

VPS_HOST="${VPS_HOST:-root@stream.loveliveradio.ca}"
VPS_TCG_DIR="${VPS_TCG_DIR:-/opt/lltcgweb}"
BRANCH="${VPS_TCG_BRANCH:-main}"

echo "==> VPS pull ${VPS_HOST}:${VPS_TCG_DIR} (${BRANCH})"
ssh "${VPS_HOST}" "set -euo pipefail
  cd '${VPS_TCG_DIR}'
  git fetch origin
  git pull --ff-only origin '${BRANCH}'
  echo \"HEAD=\$(git rev-parse --short HEAD) \$(git log -1 --oneline)\"
  curl -fsS --connect-timeout 5 'http://127.0.0.1:5003/api.php?action=ping'
  echo
"
echo "==> VPS lltcgweb pull OK"
