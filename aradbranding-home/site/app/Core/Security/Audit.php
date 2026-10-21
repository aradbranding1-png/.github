<?php

declare(strict_types=1);

namespace App\Core\Security;

use App\Core\Db\Connection;
use App\Core\Http\Request;

/** Append-only audit trail for sensitive actions. Never read on user-facing paths. */
final class Audit
{
    public function __construct(private Connection $db)
    {
    }

    /** @param array<string, mixed> $meta */
    public function log(
        string $action,
        ?int $actorId,
        ?string $targetType = null,
        ?int $targetId = null,
        string $result = 'success',
        ?Request $request = null,
        array $meta = [],
        ?int $impersonatorId = null,
    ): void {
        $this->db->insert(
            'INSERT INTO audit_logs (actor_id, impersonator_id, action, target_type, target_id, result, ip, device, meta, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(3))',
            [
                $actorId,
                $impersonatorId,
                $action,
                $targetType,
                $targetId,
                $result,
                $request ? (@inet_pton($request->ip()) ?: null) : null,
                $request ? substr($request->userAgent(), 0, 191) : null,
                $meta === [] ? null : json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ]
        );
    }
}
