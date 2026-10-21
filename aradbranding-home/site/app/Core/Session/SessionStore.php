<?php

declare(strict_types=1);

namespace App\Core\Session;

use App\Core\Db\Connection;
use App\Core\Env;
use App\Core\Log\Logger;
use App\Core\Redis\RedisFactory;
use SessionHandlerInterface;
use Throwable;

/**
 * Picks the session backend from SESSION_DRIVER (database | redis) and revokes sessions
 * on whichever backend is in use. If Redis is configured but unreachable, the site falls
 * back to MySQL sessions (logged) instead of failing every request.
 */
final class SessionStore
{
    private ?RedisSessionHandler $redisHandler = null;
    private bool $redisTried = false;

    public function __construct(private Connection $db, private Logger $logger, private int $lifetime)
    {
    }

    public static function driver(): string
    {
        return strtolower((string) Env::get('SESSION_DRIVER', 'database')) === 'redis' ? 'redis' : 'database';
    }

    public function handler(string $ip, string $ua): SessionHandlerInterface
    {
        $touch = max(60, (int) Env::get('SESSION_TOUCH_SECONDS', 300));
        $redis = $this->redis();
        if ($redis !== null) {
            return $redis;
        }
        return new DatabaseSessionHandler($this->db, $this->lifetime, $ip, $ua, $touch);
    }

    /** Sign a user out on every device. */
    public function revokeUser(int $userId): void
    {
        $this->db->exec('DELETE FROM sessions WHERE user_id = ?', [$userId]);
        $redis = $this->redis();
        if ($redis !== null) {
            $redis->revokeUser($userId);
        }
    }

    public function redis(): ?RedisSessionHandler
    {
        if (self::driver() !== 'redis') {
            return null;
        }
        if (!$this->redisTried) {
            $this->redisTried = true;
            try {
                $this->redisHandler = new RedisSessionHandler(
                    RedisFactory::connect((int) Env::get('REDIS_SESSION_DB', 2)),
                    $this->lifetime,
                    RedisFactory::prefix(),
                    $this->db, // one-time migration of sessions that still live in MySQL
                    max(60, (int) Env::get('SESSION_TOUCH_SECONDS', 300)),
                );
            } catch (Throwable $e) {
                $this->logger->error('Redis sessions unavailable, falling back to MySQL', ['error' => $e->getMessage()]);
                $this->redisHandler = null;
            }
        }
        return $this->redisHandler;
    }
}
