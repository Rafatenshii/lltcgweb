<?php

declare(strict_types=1);

namespace LLTCG\Game\Store;

/**
 * In-memory Redis room body; optional disk snapshot for ops.
 */
final class RedisGameStore implements GameStoreInterface
{
    private const DEFAULT_TTL_SEC = 172800; // 48h

    public function __construct(
        private readonly RedisClient $redis,
        private readonly string $prefix = 'lltcg:room:',
        private readonly int $ttlSec = self::DEFAULT_TTL_SEC,
        private readonly ?string $snapshotDir = null,
        private readonly float $defaultLockTimeoutSec = 5.0,
        private readonly ?\Closure $afterSave = null,
    ) {
        if ($this->snapshotDir !== null && $this->snapshotDir !== '' && !is_dir($this->snapshotDir)) {
            mkdir($this->snapshotDir, 0755, true);
        }
    }

    public function normalizeRoomId(string $roomId): string
    {
        return preg_replace('/[^A-Z0-9]/', '', strtoupper($roomId)) ?? '';
    }

    private function stateKey(string $roomId): string
    {
        return $this->prefix . $this->normalizeRoomId($roomId);
    }

    private function lockKey(string $roomId): string
    {
        return $this->prefix . 'lock:' . $this->normalizeRoomId($roomId);
    }

    public function load(string $roomId): ?array
    {
        $raw = $this->redis->get($this->stateKey($roomId));
        if ($raw === null || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    public function save(string $roomId, array $state): void
    {
        $json = json_encode($state);
        if ($json === false) {
            throw new \RuntimeException('Failed to encode room state');
        }
        $this->redis->set($this->stateKey($roomId), $json, $this->ttlSec);
        if ($this->snapshotDir) {
            $path = rtrim($this->snapshotDir, '/\\') . '/' . $this->normalizeRoomId($roomId) . '.json';
            @file_put_contents($path, $json, LOCK_EX);
        }
        if ($this->afterSave) {
            ($this->afterSave)($roomId, $state);
        }
    }

    public function delete(string $roomId): void
    {
        $this->redis->del($this->stateKey($roomId));
        $this->redis->del($this->lockKey($roomId));
        if ($this->snapshotDir) {
            $path = rtrim($this->snapshotDir, '/\\') . '/' . $this->normalizeRoomId($roomId) . '.json';
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    public function withLock(string $roomId, callable $fn, ?float $timeoutSec = null): mixed
    {
        $lockKey = $this->lockKey($roomId);
        $token = bin2hex(random_bytes(8));
        $deadline = microtime(true) + ($timeoutSec ?? $this->defaultLockTimeoutSec);
        $ttlMs = (int)max(1000, (int)(($timeoutSec ?? $this->defaultLockTimeoutSec) * 1000) + 2000);
        $acquired = false;
        while (microtime(true) <= $deadline) {
            if ($this->redis->setNxPx($lockKey, $token, $ttlMs)) {
                $acquired = true;
                break;
            }
            usleep(50000);
        }
        if (!$acquired) {
            throw new \Exception('Lock timeout');
        }
        try {
            return $fn();
        } finally {
            // Best-effort unlock (token check omitted for minimal client).
            $this->redis->del($lockKey);
        }
    }
}
