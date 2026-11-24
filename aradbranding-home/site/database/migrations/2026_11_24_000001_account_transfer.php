<?php

declare(strict_types=1);

use App\Core\Db\Connection;

/*
 * 1.19.28: «انتقال حساب به فرد دیگر» from Arad Contact (POST /api/integrations/arad-contact/users/transfer).
 * New scope users.transfer is added to the existing Arad Contact keys (those that can already create accounts), so
 * Arad Contact keeps using the same key. Each transfer is remembered in integration_refs (kind 'transfer').
 */
return new class {
    public function up(Connection $db): void
    {
        $db->exec(
            "UPDATE api_clients SET scopes = CONCAT(scopes, ',users.transfer')
              WHERE revoked_at IS NULL AND FIND_IN_SET('users.create', scopes) > 0 AND FIND_IN_SET('users.transfer', scopes) = 0"
        );
    }
};
