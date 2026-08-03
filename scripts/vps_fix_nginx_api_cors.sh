#!/bin/bash
# Fix CORS on stream.loveliveradio.ca /tcg/api/ and /tcg/sync/stream:
# - Hide PHP/upstream duplicate CORS headers
# - Echo Origin for apex + www (hardcoded apex alone breaks www and some browsers)
#
# Applies nginx config and reloads. Requires operator OK for: systemctl reload nginx
set -euo pipefail
CONF=/etc/nginx/sites-available/stream-hls
cp -a "$CONF" "${CONF}.bak.api_cors_$(date +%Y%m%d%H%M%S)"
python3 <<'PY'
from pathlib import Path
import re
p = Path('/etc/nginx/sites-available/stream-hls')
text = p.read_text()

map_block = '''map $http_origin $tcg_cors_origin {
    default "";
    "https://loveliveradio.ca" $http_origin;
    "https://www.loveliveradio.ca" $http_origin;
}

'''
if 'map $http_origin $tcg_cors_origin' not in text:
    text2, n = re.subn(r'(server\s*\{)', map_block + r'\1', text, count=1)
    if n != 1:
        raise SystemExit('could not insert cors map before server block')
    text = text2

api_block = '''    # TCG game API (Phase 2 Docker on :5003) — enabled when upstream is up
    location /tcg/api/ {
        proxy_pass http://127.0.0.1:5003/;
        proxy_http_version 1.1;
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_connect_timeout 5s;
        proxy_read_timeout 60s;
        # PHP also emits CORS — hide upstream so the client sees a single ACAO.
        proxy_hide_header Access-Control-Allow-Origin;
        proxy_hide_header Access-Control-Allow-Methods;
        proxy_hide_header Access-Control-Allow-Headers;
        proxy_hide_header Access-Control-Allow-Credentials;
        proxy_hide_header Vary;
        # Reflect allowlisted Origin (apex + www). Empty map → omit ACAO.
        add_header Access-Control-Allow-Origin $tcg_cors_origin always;
        add_header Access-Control-Allow-Methods "GET, POST, OPTIONS" always;
        add_header Access-Control-Allow-Headers "Content-Type, X-Player-Token, X-Auth-Token, Authorization" always;
        add_header Vary Origin always;
        if ($request_method = OPTIONS) { return 204; }
    }'''
text2, n = re.subn(
    r'    # TCG game API \(Phase 2 Docker on :5003\).*?location /tcg/api/ \{.*?\n    \}',
    api_block,
    text,
    count=1,
    flags=re.S,
)
if n != 1:
    raise SystemExit(f'api location replace count={n}')
text = text2

sse_block = '''    location = /tcg/sync/stream {
        proxy_pass http://127.0.0.1:5001/api/tcg/sync/stream;
        proxy_http_version 1.1;
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header Connection "";
        proxy_buffering off;
        proxy_cache off;
        chunked_transfer_encoding off;
        proxy_read_timeout 120s;
        proxy_send_timeout 120s;
        proxy_hide_header Access-Control-Allow-Origin;
        proxy_hide_header Access-Control-Allow-Methods;
        proxy_hide_header Access-Control-Allow-Headers;
        proxy_hide_header Vary;
        add_header Cache-Control "no-cache, no-transform" always;
        add_header X-Accel-Buffering "no" always;
        add_header Access-Control-Allow-Origin $tcg_cors_origin always;
        add_header Access-Control-Allow-Methods "GET, OPTIONS" always;
        add_header Access-Control-Allow-Headers "*" always;
        add_header Vary Origin always;
        if ($request_method = OPTIONS) { return 204; }
    }'''
text2, n = re.subn(
    r'    location = /tcg/sync/stream \{.*?\n    \}',
    sse_block,
    text,
    count=1,
    flags=re.S,
)
if n != 1:
    raise SystemExit(f'sse location replace count={n}')
text = text2

p.write_text(text)
print('rewrote /tcg/api/ + /tcg/sync/stream CORS (origin map)')
PY
nginx -t
systemctl reload nginx
echo RELOAD_OK
echo '--- apex api ---'
curl -sS -D - -o /dev/null -H "Origin: https://loveliveradio.ca" \
  "https://stream.loveliveradio.ca/tcg/api/api.php?action=ping" | grep -iE 'HTTP/|access-control|vary' || true
echo '--- www api ---'
curl -sS -D - -o /dev/null -H "Origin: https://www.loveliveradio.ca" \
  "https://stream.loveliveradio.ca/tcg/api/api.php?action=ping" | grep -iE 'HTTP/|access-control|vary' || true
echo '--- apex sse ---'
curl -sS -D - -o /dev/null -H "Accept: text/event-stream" -H "Origin: https://loveliveradio.ca" \
  "https://stream.loveliveradio.ca/tcg/sync/stream?room_id=TEST&ticket=invalid&last_seq=0" | grep -iE 'HTTP/|access-control' || true
echo '--- www sse ---'
curl -sS -D - -o /dev/null -H "Accept: text/event-stream" -H "Origin: https://www.loveliveradio.ca" \
  "https://stream.loveliveradio.ca/tcg/sync/stream?room_id=TEST&ticket=invalid&last_seq=0" | grep -iE 'HTTP/|access-control' || true
