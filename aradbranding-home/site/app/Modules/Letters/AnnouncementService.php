<?php

declare(strict_types=1);

namespace App\Modules\Letters;

use App\Core\Db\Connection;
use App\Core\Support\Ulid;

/**
 * Official letters from Arad Branding to everyone. One row per announcement (no per-user copies);
 * "unread" = announcements newer than user_counters.last_announcement_id.
 */
final class AnnouncementService
{
    public function __construct(private Connection $db)
    {
    }

    public function create(int $adminId, string $subject, string $body): string
    {
        $pub = Ulid::generateBinary();
        $this->db->insert(
            'INSERT INTO announcements (public_id, subject, body, created_by, published_at) VALUES (?, ?, ?, ?, NOW(3))',
            [$pub, $subject, $body, $adminId]
        );
        return strtolower(Ulid::toString($pub));
    }

    /** @return list<array<string, mixed>> */
    public function latest(int $limit = 30): array
    {
        $rows = $this->db->select(
            'SELECT id, public_id, subject, body, published_at FROM announcements
             WHERE deleted_at IS NULL ORDER BY published_at DESC, id DESC LIMIT ' . $limit
        );
        foreach ($rows as &$r) {
            $r['uid'] = strtolower(Ulid::toString($r['public_id']));
        }
        return $rows;
    }

    public function find(string $uid): ?array
    {
        if (!Ulid::isValid($uid)) {
            return null;
        }
        $r = $this->db->first('SELECT * FROM announcements WHERE public_id = ? AND deleted_at IS NULL', [Ulid::toBinary($uid)]);
        if ($r !== null) {
            $r['uid'] = strtolower($uid);
        }
        return $r;
    }

    public function lastSeen(int $userId): int
    {
        return (int) ($this->db->scalar('SELECT last_announcement_id FROM user_counters WHERE user_id = ?', [$userId]) ?? 0);
    }

    public function markSeen(int $userId): void
    {
        $this->db->exec(
            'UPDATE user_counters SET last_announcement_id = (SELECT COALESCE(MAX(id), 0) FROM announcements) WHERE user_id = ?',
            [$userId]
        );
    }

    public function delete(string $uid): void
    {
        if (Ulid::isValid($uid)) {
            $this->db->exec('UPDATE announcements SET deleted_at = NOW(3) WHERE public_id = ?', [Ulid::toBinary($uid)]);
        }
    }
}
