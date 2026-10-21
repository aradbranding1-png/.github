<?php

declare(strict_types=1);

namespace App\Core\Security;

use App\Core\Db\Connection;
use PDOException;

/**
 * Single-use keys (form tokens, callback ids). Call claim() INSIDE the business
 * transaction: a duplicate request fails the PK insert and gets the first result back.
 */
final class Idempotency
{
    public function __construct(private Connection $db)
    {
    }

    /** true = first time; false = already used (see previous()). */
    public function claim(string $scope, string $key, int $userId): bool
    {
        try {
            $this->db->exec(
                'INSERT INTO idempotency_keys (scope, key_hash, user_id, created_at) VALUES (?, ?, ?, NOW(3))',
                [$scope, hash('sha256', $key, true), $userId]
            );
            return true;
        } catch (PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                return false;
            }
            throw $e;
        }
    }

    public function complete(string $scope, string $key, int $resultRef): void
    {
        $this->db->exec(
            'UPDATE idempotency_keys SET result_ref = ? WHERE scope = ? AND key_hash = ?',
            [$resultRef, $scope, hash('sha256', $key, true)]
        );
    }

    public function previous(string $scope, string $key): ?int
    {
        $ref = $this->db->scalar(
            'SELECT result_ref FROM idempotency_keys WHERE scope = ? AND key_hash = ?',
            [$scope, hash('sha256', $key, true)]
        );
        return $ref === null ? null : (int) $ref;
    }

    /** Random token to embed in a form for one submission. */
    public static function token(): string
    {
        return bin2hex(random_bytes(16));
    }
}
