# Match store (Redis / file)

## Goals

- Authoritative live room state off Hostinger filesystem locks.
- Same PHP rules engine (`api.php` / `effects.php`) against a pluggable store.
- Crash recovery via optional disk snapshot.

## Env

| Variable | Values | Default |
|----------|--------|---------|
| `TCG_GAME_STORE` | `file`, `redis` | `file` |
| `TCG_REDIS_URL` | `redis://host:6379` or `tcp://host:6379` | empty |
| `TCG_REDIS_PREFIX` | key prefix | `lltcg:room:` |
| `TCG_GAME_SNAPSHOT_DIR` | optional async snapshot dir | empty (off) |

## Interface

```php
interface GameStore {
    public function load(string $roomId): ?array;
    public function save(string $roomId, array $state): void;
    public function withLock(string $roomId, callable $fn, ?float $timeoutSec = null): mixed;
    public function delete(string $roomId): void;
}
```

Facades `loadGame` / `saveGame` / `withLock` in `api.php` delegate to `tcgGameStore()`.

## Redis key layout

| Key | Type | Purpose |
|-----|------|---------|
| `{prefix}{ROOM}` | string (JSON) | Full room state |
| `{prefix}lock:{ROOM}` | string + SET NX PX | Mutual exclusion |
| `{prefix}meta:{ROOM}` | hash optional | seq, phase, updated_at for cheap notify |

TTL: refresh on save (e.g. 48h) so abandoned rooms expire.

### Lock algorithm

1. `SET lock NX PX timeoutMs`
2. Run callback; on success `save` state JSON
3. `DEL lock` in `finally`
4. Retry with backoff until deadline (mirror current flock timeout)

Prefer Redis `SET NX` over Redlock for single-instance Redis.

## File store

Preserves current behavior: `GAMES_DIR/{ROOM}.json` + `lock_{ROOM}` flock files. Side files (`presence_*`, `poll_tick_*`, `spectators_*`) remain filesystem for v1 even when room body is Redis.

## Snapshot

When `TCG_GAME_SNAPSHOT_DIR` is set, `RedisGameStore::save` may write best-effort JSON copy for ops/replay (not on the request critical path if deferred).

## Cutover

1. Run VPS Docker with Redis + `TCG_GAME_STORE=redis`.
2. Route client match traffic to VPS API.
3. Hostinger keeps `TCG_GAME_STORE=file` only if still serving legacy rooms; prefer no new Hostinger rooms.
