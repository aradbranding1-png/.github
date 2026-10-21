<?php

declare(strict_types=1);

namespace App\Core\Security;

use App\Core\Cache\CacheStore;

/** Fixed-window limiter. Limits per name come from config/security.php. */
final class RateLimiter
{
    /** @param array<string, array{0: int, 1: int}> $limits name => [max, windowSeconds] */
    public function __construct(private CacheStore $store, private array $limits)
    {
    }

    /** @return array{allowed: bool, limit: int, remaining: int, retry_after: int} */
    public function hit(string $name, string $identity): array
    {
        [$max, $window] = $this->limits[$name] ?? $this->limits['default'] ?? [120, 60];
        $slot = intdiv(time(), $window);
        $key = 'rl:' . $name . ':' . hash('sha256', $identity) . ':' . $slot;
        $count = $this->store->increment($key, 1, $window + 5);
        return [
            'allowed' => $count <= $max,
            'limit' => $max,
            'remaining' => max(0, $max - $count),
            'retry_after' => ($slot + 1) * $window - time(),
        ];
    }

    public function tooMany(string $name, string $identity): bool
    {
        [$max, $window] = $this->limits[$name] ?? $this->limits['default'] ?? [120, 60];
        $key = 'rl:' . $name . ':' . hash('sha256', $identity) . ':' . intdiv(time(), $window);
        return (int) ($this->store->get($key) ?? 0) >= $max;
    }
}
