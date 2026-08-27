# Replay format (schema v2)

Live matches still record a small **baseline** + **action_log** on the room
(see `captureReplayBaselineIfNeeded` / `appendReplayAction` in `replay.php`).

**Exports, account library, and `replay_view` rooms use schema v2:**

- `frames[0]` = board at start (sanitized baseline)
- `frames[k]` = board after the first `k` recorded actions
- Seek (`replay_goto`) **installs** `frames[step]` — it does not re-run the rules engine
- Frames are **slimmed** (no image URLs / deck stubs / dropped log+timer junk) so
  finished-match export stays small enough for autosave → Hostinger library
- Library SQLite gzip-encodes payloads over ~512 KB (`LLTCG_GZ1:`)

Opening a schema v1 JSON converts once via `convertReplayPayloadToV2` (best-effort
re-sim with soft-skips), then seeks on frames. CLI:
`php scripts/convert_replays_to_v2.php path/to/replay.json`.
