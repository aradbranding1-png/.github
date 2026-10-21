<?php

declare(strict_types=1);

namespace App\Core\Cache;

/** Storage backend. Phase 1: FileStore. Phase 2: RedisStore with the same contract. */
interface CacheStore
{
    public function get(string $key): mixed;

    /** @param int $ttl seconds; 0 = no expiry */
    public function put(string $key, mixed $value, int $ttl): void;

    public function forget(string $key): void;

    /** Atomic increment. Creates the key with $ttl if missing. Returns the new value. */
    public function increment(string $key, int $by = 1, int $ttl = 0): int;

    /** Non-blocking exclusive lock; null if another process holds it. */
    public function lock(string $key, int $seconds): ?Lock;

    public function flush(): void;
}
