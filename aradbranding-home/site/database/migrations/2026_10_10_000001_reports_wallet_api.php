<?php

declare(strict_types=1);

use App\Core\Db\Connection;

/*
 * 1.15: "گزارش‌های من" reads activity_events by user and by subject; admin wallet adjustments and the
 * Arad Contact API (api_clients, api_requests); full business pages are free by default (pages.unlock_charge).
 */
return new class {
    public function up(Connection $db): void
    {
        $has = static fn (string $table, string $index): bool => $db->scalar(
            'SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1',
            [$table, $index]
        ) !== null;
        if (!$has('activity_events', 'ix_user')) {
            $db->statement('ALTER TABLE activity_events ADD KEY ix_user (user_id, created_at)');
        }
        if (!$has('activity_events', 'ix_subject')) {
            $db->statement('ALTER TABLE activity_events ADD KEY ix_subject (subject_user_id, created_at)');
        }
        $db->exec("INSERT IGNORE INTO settings (`key`, value, updated_at) VALUES ('pages.unlock_charge', 'false', NOW(3))");

        // Arad Contact (and any later partner) API: clients with hashed keys, and a request log that is also the
        // idempotency record of each order (client_id + order_id is unique).
        $db->statement(
            'CREATE TABLE IF NOT EXISTS api_clients (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                name VARCHAR(100) NOT NULL,
                key_prefix CHAR(8) NOT NULL,
                secret_hash BINARY(32) NOT NULL,
                scopes VARCHAR(255) NOT NULL,
                ip_allowlist VARCHAR(500) NULL,
                active TINYINT(1) NOT NULL DEFAULT 1,
                last_used_at DATETIME(3) NULL,
                created_by BIGINT UNSIGNED NULL,
                created_at DATETIME(3) NOT NULL,
                revoked_at DATETIME(3) NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_prefix (key_prefix)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci'
        );
        $db->statement(
            'CREATE TABLE IF NOT EXISTS api_requests (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                client_id INT UNSIGNED NOT NULL,
                endpoint VARCHAR(60) NOT NULL,
                order_id VARCHAR(80) NULL,
                user_id BIGINT UNSIGNED NULL,
                stars INT NULL,
                tx_id BIGINT UNSIGNED NULL,
                status SMALLINT UNSIGNED NOT NULL,
                response MEDIUMTEXT NULL,
                ip VARCHAR(45) NOT NULL,
                created_at DATETIME(3) NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_order (client_id, endpoint, order_id),
                KEY ix_client (client_id, created_at),
                KEY ix_user (user_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci'
        );

        // «کیف پول مشتریان»: the Admin role joins Super Admin and Finance Manager.
        $db->exec(
            "INSERT IGNORE INTO role_permissions (role_id, permission_id, scope)
             SELECT r.id, p.id, 'all' FROM roles r JOIN permissions p ON p.code IN ('wallet.view', 'wallet.credit', 'wallet.debit')
              WHERE r.slug IN ('super_admin', 'admin', 'finance_manager')"
        );
    }
};
