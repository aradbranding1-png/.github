<?php

declare(strict_types=1);

namespace App\Core\Auth;

use App\Core\Db\Connection;

/**
 * RBAC: permission codes (module.action) with a data scope (own < team < department < all).
 * Customers act on their own data through ownership policies, not permissions.
 */
final class Gate
{
    private const SCOPE_RANK = ['own' => 1, 'team' => 2, 'department' => 3, 'all' => 4];

    /** @var array<int, array<string, string>> */
    private array $memo = [];

    public function __construct(private Connection $db)
    {
    }

    /** @return array<string, string> permission code => widest scope */
    public function permissions(int $userId): array
    {
        if (!isset($this->memo[$userId])) {
            $rows = $this->db->select(
                'SELECT p.code, rp.scope
                 FROM user_roles ur
                 JOIN role_permissions rp ON rp.role_id = ur.role_id
                 JOIN permissions p ON p.id = rp.permission_id
                 WHERE ur.user_id = ?',
                [$userId]
            );
            $perms = [];
            foreach ($rows as $row) {
                $current = $perms[$row['code']] ?? 'own';
                if (!isset($perms[$row['code']]) || self::SCOPE_RANK[$row['scope']] > self::SCOPE_RANK[$current]) {
                    $perms[$row['code']] = $row['scope'];
                }
            }
            $this->memo[$userId] = $perms;
        }
        return $this->memo[$userId];
    }

    public function allows(int $userId, string $permission): bool
    {
        return isset($this->permissions($userId)[$permission]);
    }

    public function scope(int $userId, string $permission): ?string
    {
        return $this->permissions($userId)[$permission] ?? null;
    }

    public function hasRole(int $userId, string $slug): bool
    {
        return $this->db->scalar(
            'SELECT 1 FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ? AND r.slug = ?',
            [$userId, $slug]
        ) !== null;
    }
}
