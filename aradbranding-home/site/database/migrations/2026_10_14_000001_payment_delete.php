<?php

declare(strict_types=1);

use App\Core\Db\Connection;

/*
 * 1.17: payments can be removed from the finance reports (soft delete: the row and its events stay for the audit
 * trail). New permission payments.delete for Super Admin, Admin and Finance Manager; Admin also gets payments.view
 * so it can open «مالی».
 */
return new class {
    public function up(Connection $db): void
    {
        if ($db->scalar("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payments' AND COLUMN_NAME = 'deleted_at' LIMIT 1") === null) {
            $db->statement('ALTER TABLE payments ADD COLUMN deleted_at DATETIME(3) NULL, ADD COLUMN deleted_by BIGINT UNSIGNED NULL, ADD COLUMN delete_reason VARCHAR(255) NULL, ADD KEY ix_live (deleted_at, status, updated_at)');
        }
        $db->exec("INSERT IGNORE INTO permissions (code, module) VALUES ('payments.delete', 'payments')");
        foreach (['super_admin' => ['payments.delete'], 'admin' => ['payments.delete', 'payments.view'], 'finance_manager' => ['payments.delete']] as $role => $codes) {
            foreach ($codes as $code) {
                $db->exec(
                    "INSERT IGNORE INTO role_permissions (role_id, permission_id, scope)
                     SELECT r.id, p.id, 'all' FROM roles r JOIN permissions p ON p.code = ? WHERE r.slug = ?",
                    [$code, $role]
                );
            }
        }
    }
};
