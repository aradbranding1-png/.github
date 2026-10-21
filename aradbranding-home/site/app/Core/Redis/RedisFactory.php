<?php

declare(strict_types=1);

namespace App\Core\Redis;

use App\Core\Env;
use Redis;
use RuntimeException;

/**
 * One phpredis connection per logical database per PHP process.
 *
 * .env:
 *   REDIS_HOST=127.0.0.1   (or a unix socket path such as /var/run/redis/redis.sock)
 *   REDIS_PORT=6379
 *   REDIS_PASSWORD=
 *   REDIS_CACHE_DB=1       cache + rate limits + locks
 *   REDIS_SESSION_DB=2     sessions (keep separate so cache:clear never logs users out)
 *   REDIS_PREFIX=sadt:
 *   REDIS_PERSISTENT=true  reuse the TCP connection across requests in PHP-FPM
 */
final class RedisFactory
{
    /** @var array<int, Redis> */
    private static array $connections = [];

    public static function available(): bool
    {
        return extension_loaded('redis');
    }

    public static function connect(int $db): Redis
    {
        if (isset(self::$connections[$db])) {
            return self::$connections[$db];
        }
        if (!self::available()) {
            throw new RuntimeException('phpredis extension is not installed');
        }
        $host = (string) Env::get('REDIS_HOST', '127.0.0.1');
        $port = (int) Env::get('REDIS_PORT', 6379);
        $timeout = (float) Env::get('REDIS_TIMEOUT', 1.0);
        $persistent = (bool) Env::get('REDIS_PERSISTENT', true);

        $r = new Redis();
        $ok = str_starts_with($host, '/')
            ? ($persistent ? $r->pconnect($host, 0, $timeout, 'sadt' . $db) : $r->connect($host, 0, $timeout))
            : ($persistent ? $r->pconnect($host, $port, $timeout, 'sadt' . $db) : $r->connect($host, $port, $timeout));
        if (!$ok) {
            throw new RuntimeException('Redis connection failed');
        }
        $password = (string) Env::get('REDIS_PASSWORD', '');
        if ($password !== '' && !$r->auth($password)) {
            throw new RuntimeException('Redis authentication failed');
        }
        if (!$r->select($db)) {
            throw new RuntimeException('Redis SELECT failed');
        }
        $r->setOption(Redis::OPT_READ_TIMEOUT, $timeout);
        return self::$connections[$db] = $r;
    }

    public static function prefix(): string
    {
        return (string) Env::get('REDIS_PREFIX', 'sadt:');
    }
}
