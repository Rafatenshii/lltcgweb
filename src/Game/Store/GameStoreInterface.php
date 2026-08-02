<?php

declare(strict_types=1);

namespace LLTCG\Game\Store;

/**
 * Pluggable live-room persistence (file flock vs Redis).
 */
interface GameStoreInterface
{
    public function load(string $roomId): ?array;

    public function save(string $roomId, array $state): void;

    /**
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public function withLock(string $roomId, callable $fn, ?float $timeoutSec = null): mixed;

    public function delete(string $roomId): void;

    /** Normalized uppercase alphanumeric room id. */
    public function normalizeRoomId(string $roomId): string;
}
