<?php
declare(strict_types=1);

namespace App\Core;

final class RateLimiter
{
    /** Returns true when the action is allowed; false when the limit is exceeded. */
    public static function hit(string $key, int $max, int $windowSec): bool
    {
        $key = substr(hash('sha256', $key), 0, 64);
        $row = DB::one('SELECT hits, reset_at FROM rate_limits WHERE `key` = ?', [$key]);
        $now = time();
        if (!$row || (int)$row['reset_at'] < $now) {
            DB::upsert('rate_limits', ['key' => $key, 'hits' => 1, 'reset_at' => $now + $windowSec], ['hits', 'reset_at']);
            return true;
        }
        if ((int)$row['hits'] >= $max) return false;
        DB::run('UPDATE rate_limits SET hits = hits + 1 WHERE `key` = ?', [$key]);
        return true;
    }

    public static function tooMany(string $key, int $max): bool
    {
        $key = substr(hash('sha256', $key), 0, 64);
        $row = DB::one('SELECT hits, reset_at FROM rate_limits WHERE `key` = ?', [$key]);
        return $row && (int)$row['reset_at'] >= time() && (int)$row['hits'] >= $max;
    }

    public static function clear(string $key): void
    {
        DB::delete('rate_limits', '`key` = ?', [substr(hash('sha256', $key), 0, 64)]);
    }

    public static function cleanup(): void
    {
        DB::delete('rate_limits', 'reset_at < ?', [time()]);
    }
}
