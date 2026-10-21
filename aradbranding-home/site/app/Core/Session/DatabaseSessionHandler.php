<?php

declare(strict_types=1);

namespace App\Core\Session;

use App\Core\Db\Connection;
use SessionHandlerInterface;
use SessionUpdateTimestampHandlerInterface;

/**
 * Sessions in MySQL so several web servers can share them (Redis later).
 * The primary key is SHA-256(session id): a leaked table cannot be replayed.
 * Timestamps are refreshed at most once every SESSION_TOUCH_SECONDS (default 300) instead of on every
 * request, and validateId()+read() share one SELECT, so an unchanged session costs one read per request.
 * For hundreds of concurrent users switch to SESSION_DRIVER=redis (RedisSessionHandler).
 */
final class DatabaseSessionHandler implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface
{
    private int $lastActivity = 0;

    /** @var array{0: string, 1: array<string, mixed>|null}|null row fetched by validateId(), reused by read() */
    private ?array $prefetched = null;

    public function __construct(
        private Connection $db,
        private int $lifetime,
        private string $ip,
        private string $ua,
        private int $touchEvery = 300,
    ) {
    }

    /** @return array<string, mixed>|null */
    private function fetch(string $id): ?array
    {
        if ($this->prefetched !== null && $this->prefetched[0] === $id) {
            return $this->prefetched[1];
        }
        $row = $this->db->first(
            'SELECT payload, last_activity FROM sessions WHERE id = ? AND last_activity > ?',
            [self::hash($id), time() - $this->lifetime]
        );
        $this->prefetched = [$id, $row];
        return $row;
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
        $row = $this->fetch($id);
        $this->prefetched = null;
        $this->lastActivity = (int) ($row['last_activity'] ?? 0);
        return (string) ($row['payload'] ?? '');
    }

    public function write(string $id, string $data): bool
    {
        $now = time();
        $userId = isset($_SESSION['_uid']) ? (int) $_SESSION['_uid'] : null;
        $ip = @inet_pton($this->ip) ?: null;
        $uaHash = hash('sha256', $this->ua, true);
        $this->db->exec(
            'INSERT INTO sessions (id, user_id, payload, ip, ua_hash, last_activity) VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE user_id = ?, payload = ?, ip = ?, ua_hash = ?, last_activity = ?',
            [self::hash($id), $userId, $data, $ip, $uaHash, $now, $userId, $data, $ip, $uaHash, $now]
        );
        $this->lastActivity = $now;
        return true;
    }

    public function destroy(string $id): bool
    {
        $this->db->exec('DELETE FROM sessions WHERE id = ?', [self::hash($id)]);
        $this->prefetched = null;
        return true;
    }

    public function gc(int $max_lifetime): int|false
    {
        // Real cleanup runs from cron in bounded batches (see CleanupTask).
        return 0;
    }

    public function validateId(string $id): bool
    {
        return $this->fetch($id) !== null;
    }

    public function updateTimestamp(string $id, string $data): bool
    {
        if ($this->lastActivity === 0 || time() - $this->lastActivity < $this->touchEvery) {
            return true; // 0 = row never stored (empty anonymous session): nothing to touch
        }
        $this->db->exec('UPDATE sessions SET last_activity = ? WHERE id = ?', [time(), self::hash($id)]);
        return true;
    }

    public static function hash(string $id): string
    {
        return hash('sha256', $id, true);
    }
}
