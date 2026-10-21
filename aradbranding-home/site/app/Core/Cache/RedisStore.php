<?php

declare(strict_types=1);

namespace App\Core\Cache;

use Redis;

/**
 * Redis backend with the same contract as FileStore (CACHE_DRIVER=redis).
 * No disk I/O per request: rate-limit counters, locks and cached data all live in memory.
 * Every key is prefixed, and flush() only removes our own keys (never FLUSHDB),
 * so the Redis database may be shared safely.
 */
final class RedisStore implements CacheStore
{
    private const RELEASE = "if redis.call('get', KEYS[1]) == ARGV[1] then return redis.call('del', KEYS[1]) else return 0 end";
    private const INCR = "local v = redis.call('incrby', KEYS[1], ARGV[1]) if tonumber(ARGV[2]) > 0 and redis.call('ttl', KEYS[1]) < 0 then redis.call('expire', KEYS[1], ARGV[2]) end return v";

    public function __construct(private Redis $redis, private string $prefix = 'sadt:c:')
    {
    }

    public function get(string $key): mixed
    {
        $raw = $this->redis->get($this->k($key));
        if (!is_string($raw)) {
            return null;
        }
        if (preg_match('/^-?\d+$/', $raw)) {
            return (int) $raw; // written by increment()
        }
        $value = @unserialize($raw, ['allowed_classes' => false]);
        return $value === false && $raw !== serialize(false) ? null : $value;
    }

    public function put(string $key, mixed $value, int $ttl): void
    {
        $raw = serialize($value);
        $ttl > 0 ? $this->redis->setex($this->k($key), $ttl, $raw) : $this->redis->set($this->k($key), $raw);
    }

    public function forget(string $key): void
    {
        $this->redis->del($this->k($key));
    }

    public function increment(string $key, int $by = 1, int $ttl = 0): int
    {
        // Atomic: the window starts with the first hit and is never extended (same as FileStore).
        return (int) $this->redis->eval(self::INCR, [$this->k($key), $by, $ttl], 1);
    }

    public function lock(string $key, int $seconds): ?Lock
    {
        $k = $this->k('lock:' . $key);
        $token = bin2hex(random_bytes(8));
        if ($this->redis->set($k, $token, ['nx', 'ex' => max(1, $seconds)]) !== true) {
            return null;
        }
        $redis = $this->redis;
        return new Lock(static function () use ($redis, $k, $token): void {
            try {
                $redis->eval(self::RELEASE, [$k, $token], 1);
            } catch (\Throwable) {
                // the key expires on its own
            }
        });
    }

    public function flush(): void
    {
        $it = null;
        $pattern = $this->prefix . '*';
        $prev = $this->redis->getOption(Redis::OPT_SCAN);
        $this->redis->setOption(Redis::OPT_SCAN, Redis::SCAN_RETRY);
        try {
            while (($keys = $this->redis->scan($it, $pattern, 1000)) !== false) {
                if ($keys !== []) {
                    $this->redis->unlink($keys);
                }
                if ($it === 0) {
                    break;
                }
            }
        } finally {
            $this->redis->setOption(Redis::OPT_SCAN, $prev);
        }
    }

    public function ping(): bool
    {
        try {
            $pong = $this->redis->ping();
            return $pong === true || $pong === '+PONG' || $pong === 'PONG';
        } catch (\Throwable) {
            return false;
        }
    }

    private function k(string $key): string
    {
        return $this->prefix . $key;
    }
}
