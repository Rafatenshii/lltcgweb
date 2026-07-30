#!/usr/bin/env bash
# Pull Hostinger tcg.db into VPS overflow data/ (keeps account reads usable during failover).
# Requires Hostinger FTP env in Chiichan .env.deploy (not committed).
set -euo pipefail
CHIICHAN_ROOT="${CHIICHAN_ROOT:-$(cd "$(dirname "$0")/../../Chiichan" 2>/dev/null && pwd || true)}"
LLTCG_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
VPS_HOST="${VPS_HOST:-root@45.76.173.164}"
VPS_DIR="${VPS_TCG_DIR:-/opt/lltcgweb}"
CACHE="${LLTCG_ROOT}/.deploy-cache"
mkdir -p "$CACHE"

python3 - <<PY
import ftplib, os
from pathlib import Path

def load_env(path: Path, env: dict):
    if not path.is_file():
        return
    for line in path.read_text(encoding="utf-8", errors="ignore").splitlines():
        line = line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        k, v = line.split("=", 1)
        env[k.strip()] = v.strip().strip('"').strip("'")

env = {}
roots = []
chi = os.environ.get("CHIICHAN_ROOT") or ""
if chi:
    roots.append(Path(chi))
roots.append(Path(r"${CHIICHAN_ROOT}"))
roots.append(Path(r"${LLTCG_ROOT}") / ".." / "Chiichan")
for root in roots:
    load_env(root / "sim" / ".env.deploy", env)
    load_env(root / ".env.deploy", env)

host = env.get("HOSTINGER_FTP_HOST") or env.get("HOSTINGER_HOST")
user = env.get("HOSTINGER_FTP_USER") or env.get("HOSTINGER_USER")
pw = env.get("HOSTINGER_FTP_PASSWORD") or env.get("HOSTINGER_PASSWORD")
base = (env.get("HOSTINGER_FTP_PATH") or env.get("HOSTINGER_SITE_PATH") or "/domains/loveliveradio.ca/public_html").rstrip("/")
if not host or not user or not pw:
    raise SystemExit("missing Hostinger FTP credentials in .env.deploy")

out = Path(r"${CACHE}") / "tcg.db"
ftp = ftplib.FTP(host, timeout=120)
ftp.login(user, pw)
remote = f"{base}/tcg/data/tcg.db"
with out.open("wb") as f:
    ftp.retrbinary("RETR " + remote, f.write)
ftp.quit()
print(f"downloaded {out} ({out.stat().st_size} bytes)")
PY

scp -q "${CACHE}/tcg.db" "${VPS_HOST}:${VPS_DIR}/data/tcg.db"
ssh "$VPS_HOST" "chown -R www-data:www-data '${VPS_DIR}/data' 2>/dev/null || true; ls -la '${VPS_DIR}/data/tcg.db'"
echo "Synced tcg.db to VPS overflow data/"
