#!/usr/bin/env bash
# Pull Hostinger tcg.db into VPS overflow data/ (keeps account reads usable during failover).
# Requires Hostinger FTP env in Chiichan .env.deploy (not committed).
set -euo pipefail
CHIICHAN_ROOT="${CHIICHAN_ROOT:-$(cd "$(dirname "$0")/../../Chiichan" 2>/dev/null && pwd || true)}"
LLTCG_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
VPS_HOST="${VPS_HOST:-root@stream.loveliveradio.ca}"
VPS_DIR="${VPS_TCG_DIR:-/opt/lltcgweb}"
CACHE="${LLTCG_ROOT}/.deploy-cache"
mkdir -p "$CACHE"
# Prefer a real Windows path for Python on Git Bash
if command -v cygpath >/dev/null 2>&1; then
  CACHE_WIN="$(cygpath -w "$CACHE")"
  CHIICHAN_WIN="$(cygpath -w "${CHIICHAN_ROOT}")"
  LLTCG_WIN="$(cygpath -w "${LLTCG_ROOT}")"
else
  CACHE_WIN="$CACHE"
  CHIICHAN_WIN="${CHIICHAN_ROOT}"
  LLTCG_WIN="${LLTCG_ROOT}"
fi

python - <<PY
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
for root in [Path(r"""${CHIICHAN_WIN}"""), Path(r"""${LLTCG_WIN}""") / ".." / "Chiichan"]:
    load_env(root / "sim" / ".env.deploy", env)
    load_env(root / ".env.deploy", env)

host = env.get("HOSTINGER_FTP_HOST") or env.get("HOSTINGER_HOST")
user = env.get("HOSTINGER_FTP_USER") or env.get("HOSTINGER_USER")
pw = (
    env.get("HOSTINGER_FTP_PASSWORD")
    or env.get("HOSTINGER_PASSWORD")
    or env.get("HOSTINGER_PASS")
)
base = (
    env.get("HOSTINGER_FTP_PATH")
    or env.get("HOSTINGER_SITE_PATH")
    or env.get("HOSTINGER_PATH")
    or "/domains/loveliveradio.ca/public_html"
).rstrip("/")
# sim/.env.deploy often points at .../public_html/sim — TCG lives beside it.
if base.endswith("/sim"):
    base = base[: -len("/sim")]
if not host or not user or not pw:
    raise SystemExit("missing Hostinger FTP credentials in .env.deploy")

out_dir = Path(r"""${CACHE_WIN}""")
out_dir.mkdir(parents=True, exist_ok=True)
out = out_dir / "tcg.db"
ftp = ftplib.FTP(host, timeout=120)
ftp.login(user, pw)
candidates = [
    "/domains/loveliveradio.ca/public_html/tcg/data/tcg.db",
    f"{base}/tcg/data/tcg.db",
]
last_err = None
for remote in candidates:
    try:
        with out.open("wb") as f:
            ftp.retrbinary("RETR " + remote, f.write)
        print(f"downloaded {remote} -> {out} ({out.stat().st_size} bytes)")
        last_err = None
        break
    except Exception as e:
        last_err = e
        print(f"retr fail {remote}: {e}")
ftp.quit()
if last_err is not None:
    raise SystemExit(f"could not download tcg.db: {last_err}")
PY

scp -q "${CACHE}/tcg.db" "${VPS_HOST}:${VPS_DIR}/data/tcg.db"
ssh "$VPS_HOST" "chown -R www-data:www-data '${VPS_DIR}/data' 2>/dev/null || chmod -R a+rwX '${VPS_DIR}/data'; ls -la '${VPS_DIR}/data/tcg.db'"
echo "Synced tcg.db to VPS overflow data/"
