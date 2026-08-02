# Realtime notify (Part 1C)

## Current (keep)

- VPS Flask [`tcg_sync.py`](../../Chiichan/wrapped/tcg_sync.py) SSE at `https://stream.loveliveradio.ca/tcg/sync/stream`.
- Payload: `{ room_id, seq, phase? }` only — not full board state.
- Client (`game-sync.js`) wakes → `get_state` with `poll=0`.
- Match API `saveGame` → deferred `tcgSyncNotify` after room lock release.

## Why not replace yet

SSE already removed long-held Hostinger PHP workers. Redis room store (Part 1A/B) removes the file-lock bottleneck; notify path is not the primary CPU cost.

## Optional WebSocket follow-up

When poll/`get_state` volume remains high after Redis cutover:

1. Add `/tcg/sync/ws` on VPS (same auth ticket as SSE).
2. Push seq bumps (and optionally compressed state deltas) over WS.
3. Keep SSE as fallback for older clients.

Do not restart `wrapped-api` / nginx without explicit operator confirmation.
