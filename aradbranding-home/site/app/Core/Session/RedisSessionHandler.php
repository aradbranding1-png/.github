<?php

declare(strict_types=1);

namespace App\Core\Session;

use App\Core\Db\Connection;
use Redis;
use SessionHandlerInterface;
use SessionUpdateTimestampHandlerInterface;

/**
 * Sessions in Redis (SESSION_DRIVER=redis): no MySQL read or write per request.
 *
 * Keys (in REDIS_SESSION_DB):
 *   {prefix}s:{sha256(id)}   payload, expires after SESSION_LIFETIME of inactivity
 *   {prefix}u:{user_id}      set of that user's session hashes, so "sign out everywhere",
 *                            bans and password resets can still revoke every device
 *
 * Switching from the database driver does not sign anyone out: on a miss the old MySQL row
 * is read once and copied into Redis.
 */
final class RedisSessionHandler implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface
{
    private int $lastTouch = 0;

    /** @var array<string, string> legacy lookups done in this request */
    private array $legacyMemo = [];

    public function __construct(
        private Redis $redis,
        private int $lifetime,
        private string $prefix = 'sadt:',
        private ?Connection $legacyDb = null,
        private int $touchEvery = 300,
    ) {
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        $hash = self::hash($id);
        $raw = $this->redis->get($this->key($hash));
        if (is_string($raw)) {
            $ttl = (int) $this->redis->ttl($this->key($hash));
            // Approximate time of the last write/touch from the remaining TTL.
            $this->lastTouch = $ttl > 0 ? time() - ($this->lifetime - $ttl) : 0;
            return $raw;
        }
        $this->lastTouch = 0;
        return $this->fromDatabase($hash);
    }

    public function write(string $id, string $data): bool
    {
        $hash = self::hash($id);
        $this->redis->setex($this->key($hash), $this->lifetime, $data);
        $uid = isset($_SESSION['_uid']) ? (int) $_SESSION['_uid'] : 0;
        if ($uid > 0) {
            $this->redis->sAdd($this->userKey($uid), $hash);
            $this->redis->expire($this->userKey($uid), $this->lifetime);
        }
        $this->lastTouch = time();
        return true;
    }

    public function destroy(string $id): bool
    {
        $this->redis->del($this->key(self::hash($id)));
        return true;
    }

    public function gc(int $max_lifetime): int|false
    {
        return 0; // Redis expires keys itself
    }

    public function validateId(string $id): bool
    {
        $hash = self::hash($id);
        if ($this->redis->exists($this->key($hash))) {
            return true;
        }
        return $this->fromDatabase($hash) !== '';
    }

    public function updateTimestamp(string $id, string $data): bool
    {
        if ($this->lastTouch === 0 || time() - $this->lastTouch < $this->touchEvery) {
            return true;
        }
        $this->redis->expire($this->key(self::hash($id)), $this->lifetime);
        $this->lastTouch = time();
        return true;
    }

    /** Remove every session of a user (ban, password change, "sign out other devices"). */
    public function revokeUser(int $userId): int
    {
        $members = $this->redis->sMembers($this->userKey($userId)) ?: [];
        $keys = array_map(fn (string $h): string => $this->key($h), $members);
        $keys[] = $this->userKey($userId);
        return (int) $this->redis->del($keys);
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

    /** One-time copy of a session that still lives in MySQL (after switching drivers). */
    private function fromDatabase(string $hash): string
    {
        if ($this->legacyDb === null) {
            return '';
        }
        if (array_key_exists($hash, $this->legacyMemo)) {
            return $this->legacyMemo[$hash];
        }
        try {
            $row = $this->legacyDb->first(
                'SELECT user_id, payload FROM sessions WHERE id = ? AND last_activity > ?',
                [$hash, time() - $this->lifetime]
            );
        } catch (\Throwable) {
            $row = null;
        }
        if ($row === null) {
            return $this->legacyMemo[$hash] = '';
        }
        $payload = (string) $row['payload'];
        $this->redis->setex($this->key($hash), $this->lifetime, $payload);
        if (!empty($row['user_id'])) {
            $this->redis->sAdd($this->userKey((int) $row['user_id']), $hash);
            $this->redis->expire($this->userKey((int) $row['user_id']), $this->lifetime);
        }
        try {
            $this->legacyDb->exec('DELETE FROM sessions WHERE id = ?', [$hash]);
        } catch (\Throwable) {
        }
        $this->lastTouch = time();
        return $this->legacyMemo[$hash] = $payload;
    }

    private function key(string $hash): string
    {
        return $this->prefix . 's:' . bin2hex($hash);
    }

    private function userKey(int $uid): string
    {
        return $this->prefix . 'u:' . $uid;
    }

    public static function hash(string $id): string
    {
        return hash('sha256', $id, true);
    }
}
