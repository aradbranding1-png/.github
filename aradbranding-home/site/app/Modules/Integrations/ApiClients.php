<?php

declare(strict_types=1);

namespace App\Modules\Integrations;

use App\Core\Db\Connection;

/**
 * API keys for partner systems (Arad Contact). A key looks like `ab_<8-char prefix>_<40-char secret>`; only the
 * prefix and a SHA-256 of the secret are stored, so a key is shown once at creation and can only be revoked.
 */
final class ApiClients
{
    public const SCOPES = [
        'wallet.charge' => 'شارژ کیف پول مشتری (و ساخت حساب در صورت نبودن)',
        'users.read' => 'استعلام حساب و موجودی با شماره موبایل',
        'users.credentials' => 'صدور رمز ورود تازه برای مشتری',
    ];

    public function __construct(private Connection $db)
    {
    }

    /** @return array{id: int, key: string} */
    public function create(string $name, array $scopes, ?string $ips, int $actorId): array
    {
        $scopes = array_values(array_intersect(array_keys(self::SCOPES), $scopes));
        $prefix = substr(bin2hex(random_bytes(8)), 0, 8);
        $secret = bin2hex(random_bytes(20));
        $id = $this->db->insert(
            'INSERT INTO api_clients (name, key_prefix, secret_hash, scopes, ip_allowlist, active, created_by, created_at)
             VALUES (?, ?, ?, ?, ?, 1, ?, NOW(3))',
            [mb_substr($name, 0, 100), $prefix, hash('sha256', $secret, true), implode(',', $scopes), $ips, $actorId]
        );
        return ['id' => $id, 'key' => 'ab_' . $prefix . '_' . $secret];
    }

    /** The active client for a bearer key, or null. Constant-time comparison of the secret hash. */
    public function authenticate(string $key, string $ip): ?array
    {
        if (!preg_match('/^ab_([a-f0-9]{8})_([a-f0-9]{40})$/', $key, $m)) {
            return null;
        }
        $client = $this->db->first('SELECT * FROM api_clients WHERE key_prefix = ? AND active = 1 AND revoked_at IS NULL', [$m[1]]);
        if ($client === null || !hash_equals((string) $client['secret_hash'], hash('sha256', $m[2], true))) {
            return null;
        }
        $allow = array_filter(array_map('trim', explode(',', (string) ($client['ip_allowlist'] ?? ''))));
        if ($allow !== [] && !in_array($ip, $allow, true)) {
            return null;
        }
        $this->db->exec('UPDATE api_clients SET last_used_at = NOW(3) WHERE id = ?', [$client['id']]);
        $client['scopes'] = array_filter(explode(',', (string) $client['scopes']));
        return $client;
    }

    public function revoke(int $id): void
    {
        $this->db->exec('UPDATE api_clients SET active = 0, revoked_at = NOW(3) WHERE id = ? AND revoked_at IS NULL', [$id]);
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return $this->db->select(
            'SELECT c.id, c.name, c.key_prefix, c.scopes, c.ip_allowlist, c.active, c.last_used_at, c.created_at, c.revoked_at,
                    (SELECT COUNT(*) FROM api_requests r WHERE r.client_id = c.id) AS requests,
                    (SELECT COALESCE(SUM(r.stars), 0) FROM api_requests r WHERE r.client_id = c.id AND r.endpoint = \'wallet.charge\' AND r.status = 200) AS stars
               FROM api_clients c ORDER BY c.revoked_at IS NULL DESC, c.id DESC'
        );
    }

    /** @return list<array<string, mixed>> */
    public function recent(int $limit = 20): array
    {
        return $this->db->select(
            'SELECT r.id, r.endpoint, r.order_id, r.stars, r.status, r.created_at, c.name AS client, u.first_name, u.last_name, u.phone_cc, u.phone
               FROM api_requests r JOIN api_clients c ON c.id = r.client_id LEFT JOIN users u ON u.id = r.user_id
              ORDER BY r.id DESC LIMIT ' . max(1, min(100, $limit))
        );
    }
}
