<?php

declare(strict_types=1);

namespace App\Core\Cache;

/**
 * Application cache with:
 *  - soft TTL + grace period (stale-while-revalidate),
 *  - single-flight recompute (stampede protection),
 *  - namespace versioning instead of wildcard deletes.
 */
final class Cache
{
    private const GRACE = 300;

    /** @var array<string, mixed> per-request memo */
    private array $memo = [];

    public function __construct(private CacheStore $store, private string $prefix)
    {
    }

    public function store(): CacheStore
    {
        return $this->store;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $envelope = $this->store->get($this->key($key));
        return is_array($envelope) && array_key_exists('v', $envelope) ? $envelope['v'] : $default;
    }

    public function put(string $key, mixed $value, int $ttl): void
    {
        $this->store->put($this->key($key), ['v' => $value, 's' => time() + $ttl], $ttl + self::GRACE);
        $this->memo[$key] = $value;
    }

    public function forget(string $key): void
    {
        unset($this->memo[$key]);
        $this->store->forget($this->key($key));
    }

    /**
     * @template T
     * @param callable(): T $compute
     * @return T
     */
    public function remember(string $key, int $ttl, callable $compute): mixed
    {
        if (array_key_exists($key, $this->memo)) {
            return $this->memo[$key];
        }

        $envelope = $this->store->get($this->key($key));
        $hasValue = is_array($envelope) && array_key_exists('v', $envelope);
        if ($hasValue && $envelope['s'] >= time()) {
            return $this->memo[$key] = $envelope['v'];
        }

        $lock = $this->store->lock($this->key($key), 10);
        if ($lock === null) {
            if ($hasValue) {
                return $this->memo[$key] = $envelope['v']; // stale while another process refreshes
            }
            for ($i = 0; $i < 20; $i++) {
                usleep(100_000);
                $envelope = $this->store->get($this->key($key));
                if (is_array($envelope) && array_key_exists('v', $envelope)) {
                    return $this->memo[$key] = $envelope['v'];
                }
            }
        }

        try {
            $value = $compute();
            $this->put($key, $value, $ttl);
            return $value;
        } finally {
            $lock?->release();
        }
    }

    /** Current version of a namespace, e.g. version("page:42"). Missing = 0. */
    public function version(string $namespace): int
    {
        return (int) ($this->store->get($this->key('ver:' . $namespace)) ?? 0);
    }

    /** Invalidate every key built with this namespace's version. Call AFTER commit. */
    public function bump(string $namespace): int
    {
        $this->memo = [];
        return $this->store->increment($this->key('ver:' . $namespace), 1, 0);
    }

    private function key(string $key): string
    {
        return $this->prefix . ':' . $key;
    }
}
