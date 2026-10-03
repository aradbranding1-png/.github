<?php

declare(strict_types=1);

namespace App\Modules\Integrations;

use App\Core\Auth\Auth;
use App\Core\Db\Connection;

/**
 * Personal API keys (official API v1). A key looks like `ark_<8-hex prefix>_<40-hex secret>`; only the prefix and a
 * SHA-256 of the secret are stored, so a key is shown once and can only be revoked. Each key has scopes and an
 * optional expiry; it acts as its owner and stops working while the owner is suspended or banned.
 * Only Super Admin accounts may hold and use keys (1.16.3); keys of anyone else are refused.
 */
final class ApiKeys
{
    public const SCOPES = [
        'profile.read' => 'خواندن مشخصات حساب و پیشخوان',
        'wallet.read' => 'خواندن موجودی و گردش Stars',
        'letters.read' => 'خواندن صندوق نامه‌ها و گفتگوها',
        'letters.send' => 'ارسال نامه اختصاصی و پاسخ (هزینه Stars از کیف پول شما کم می‌شود)',
        'proposals.read' => 'خواندن پیشنهادهای شما و فید پیشنهادها',
        'notifications.read' => 'خواندن اعلان‌ها',
        'directory.read' => 'جستجوی تاجران و مشاهده خلاصه صفحه آن‌ها',
    ];
    /** Scopes that change something; refused while the account is «محدود». */
    public const WRITE_SCOPES = ['letters.send'];
    public const EXPIRY_DAYS = [30, 90, 365, 0];
    public const MAX_ACTIVE = 5;

    public function __construct(private Connection $db)
    {
    }

    /** @return array{id: int, key: string} */
    public function create(int $userId, string $name, array $scopes, int $days): array
    {
        $scopes = array_values(array_intersect(array_keys(self::SCOPES), $scopes));
        $prefix = bin2hex(random_bytes(4));
        $secret = bin2hex(random_bytes(20));
        $id = $this->db->insert(
            'INSERT INTO api_keys (user_id, name, prefix, key_hash, scopes, expires_at, created_at)
             VALUES (?, ?, ?, ?, ?, ' . ($days > 0 ? 'NOW(3) + INTERVAL ' . $days . ' DAY' : 'NULL') . ', NOW(3))',
            [$userId, mb_substr($name, 0, 80), $prefix, hash('sha256', $secret, true), implode(',', $scopes)]
        );
        return ['id' => $id, 'key' => 'ark_' . $prefix . '_' . $secret];
    }

    public function activeCount(int $userId): int
    {
        return (int) $this->db->scalar(
            'SELECT COUNT(*) FROM api_keys WHERE user_id = ? AND revoked_at IS NULL AND (expires_at IS NULL OR expires_at > NOW(3))',
            [$userId]
        );
    }

    /**
     * The key and its owner, or an error code: invalid | expired | revoked | account.
     * @return array{key: ?array<string, mixed>, user: ?array<string, mixed>, error: ?string}
     */
    public function authenticate(string $bearer, string $ip): array
    {
        if (!preg_match('/^ark_([a-f0-9]{8})_([a-f0-9]{40})$/', $bearer, $m)) {
            return ['key' => null, 'user' => null, 'error' => 'invalid'];
        }
        $key = $this->db->first('SELECT *, expires_at IS NOT NULL AND expires_at <= NOW(3) AS is_expired FROM api_keys WHERE prefix = ?', [$m[1]]);
        if ($key === null || !hash_equals((string) $key['key_hash'], hash('sha256', $m[2], true))) {
            return ['key' => null, 'user' => null, 'error' => 'invalid'];
        }
        if ($key['revoked_at'] !== null) {
            return ['key' => null, 'user' => null, 'error' => 'revoked'];
        }
        if ((int) $key['is_expired'] === 1) {
            return ['key' => null, 'user' => null, 'error' => 'expired'];
        }
        $user = $this->db->first(
            'SELECT u.id, u.public_id, u.handle, u.first_name, u.last_name, u.status, u.country_id, u.language_id, u.created_at,
                    c.code AS country_code, l.code AS language_code, p.company_name, p.trade_role
               FROM users u JOIN countries c ON c.id = u.country_id JOIN languages l ON l.id = u.language_id
               LEFT JOIN user_profiles p ON p.user_id = u.id
              WHERE u.id = ? AND u.deleted_at IS NULL',
            [$key['user_id']]
        );
        if ($user === null || in_array((int) $user['status'], [Auth::STATUS_SUSPENDED, Auth::STATUS_BANNED, Auth::STATUS_DELETED], true)) {
            return ['key' => null, 'user' => null, 'error' => 'account'];
        }
        if (!$this->allowed((int) $user['id'])) {
            return ['key' => null, 'user' => null, 'error' => 'forbidden'];
        }
        $this->db->exec('UPDATE api_keys SET last_used_at = NOW(3), last_ip = ?, requests = requests + 1 WHERE id = ?', [@inet_pton($ip) ?: null, $key['id']]);
        $key['scopes'] = array_values(array_filter(explode(',', (string) $key['scopes'])));
        return ['key' => $key, 'user' => $user, 'error' => null];
    }

    /** Official API access is reserved for Super Admin accounts. */
    public function allowed(int $userId): bool
    {
        return $this->db->scalar(
            "SELECT 1 FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ? AND r.slug = 'super_admin'",
            [$userId]
        ) !== null;
    }

    public function revoke(int $id, ?int $userId = null): bool
    {
        return $this->db->exec(
            'UPDATE api_keys SET revoked_at = NOW(3) WHERE id = ? AND revoked_at IS NULL' . ($userId !== null ? ' AND user_id = ?' : ''),
            $userId !== null ? [$id, $userId] : [$id]
        ) > 0;
    }

    /** @return list<array<string, mixed>> */
    public function forUser(int $userId): array
    {
        return $this->db->select(
            'SELECT id, name, prefix, scopes, expires_at, last_used_at, requests, created_at, revoked_at,
                    expires_at IS NOT NULL AND expires_at <= NOW(3) AS is_expired
               FROM api_keys WHERE user_id = ? ORDER BY revoked_at IS NULL DESC, id DESC LIMIT 50',
            [$userId]
        );
    }

    /** All members' keys for the admin overview. @return list<array<string, mixed>> */
    public function all(int $limit = 50): array
    {
        return $this->db->select(
            'SELECT k.id, k.name, k.prefix, k.scopes, k.expires_at, k.last_used_at, k.requests, k.created_at, k.revoked_at,
                    k.expires_at IS NOT NULL AND k.expires_at <= NOW(3) AS is_expired, u.id AS user_id, u.first_name, u.last_name, u.handle
               FROM api_keys k JOIN users u ON u.id = k.user_id
              ORDER BY k.revoked_at IS NULL DESC, k.last_used_at IS NULL, k.last_used_at DESC, k.id DESC LIMIT ' . max(1, min(200, $limit))
        );
    }
}
