<?php

declare(strict_types=1);

use App\Core\Db\Connection;

/*
 * 1.18: internal API for «آراد کانتکت» (/api/integrations/arad-contact/*).
 * integration_refs remembers every external_id (one row per kind + external_id): a repeated request — e.g. a retry
 * after a dropped connection — returns the stored result instead of creating a second account or charging twice.
 * A new temporary password is kept encrypted (APP_KEY) for 7 days so a retried "create user" can still return it.
 * New scope users.create is added to existing partner keys that could already issue credentials.
 */
return new class {
    public function up(Connection $db): void
    {
        $db->statement('CREATE TABLE IF NOT EXISTS integration_refs (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            client_id   INT UNSIGNED NOT NULL,
            kind        VARCHAR(16) NOT NULL,
            external_id VARCHAR(120) NOT NULL,
            user_id     BIGINT UNSIGNED NULL,
            tx_id       BIGINT UNSIGNED NULL,
            response    JSON NOT NULL,
            secret      VARBINARY(255) NULL,
            created_at  DATETIME(3) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_ref (kind, external_id),
            KEY ix_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci');
        $db->exec(
            "UPDATE api_clients SET scopes = CONCAT(scopes, ',users.create')
              WHERE revoked_at IS NULL AND FIND_IN_SET('users.credentials', scopes) > 0 AND FIND_IN_SET('users.create', scopes) = 0"
        );
    }
};
