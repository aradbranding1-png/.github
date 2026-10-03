<?php

declare(strict_types=1);

use App\Core\Db\Connection;

/* 1.16.3: the official API is reserved for Super Admin accounts; keys held by anyone else are revoked. */
return new class {
    public function up(Connection $db): void
    {
        $db->exec(
            "UPDATE api_keys k SET k.revoked_at = NOW(3)
              WHERE k.revoked_at IS NULL AND NOT EXISTS (
                    SELECT 1 FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = k.user_id AND r.slug = 'super_admin')"
        );
    }
};
