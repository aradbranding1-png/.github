<?php

declare(strict_types=1);

use App\Core\Db\Connection;

/* Dynamic roles/permissions. Seeds are INSERT IGNORE: admin changes survive re-runs. */
return new class {
    private const OPTS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci';

    private const PERMISSIONS = [
        'users.view', 'users.edit', 'users.delete', 'users.impersonate',
        'pages.view', 'pages.edit', 'pages.approve',
        'proposals.view', 'proposals.moderate',
        'letters.view', 'letters.moderate',
        'wallet.view', 'wallet.credit', 'wallet.debit',
        'payments.view', 'payments.refund',
        'reports.view', 'reports.export',
        'audit.view', 'settings.manage', 'roles.manage', 'backup.manage', 'updates.manage',
    ];

    /** slug => [name, is_system, [permission => scope] | '*' for all] */
    private const ROLES = [
        'super_admin' => ['Super Admin', 1, '*'],
        'admin' => ['Admin', 1, ['users.view' => 'all', 'users.edit' => 'all', 'users.delete' => 'all',
            'pages.view' => 'all', 'pages.edit' => 'all', 'pages.approve' => 'all',
            'proposals.view' => 'all', 'proposals.moderate' => 'all',
            'letters.view' => 'all', 'letters.moderate' => 'all', 'reports.view' => 'all', 'audit.view' => 'all']],
        'operations_manager' => ['Operations Manager', 1, ['users.view' => 'department', 'users.edit' => 'department',
            'pages.view' => 'department', 'pages.edit' => 'department', 'pages.approve' => 'department',
            'proposals.view' => 'department', 'proposals.moderate' => 'department', 'reports.view' => 'department']],
        'finance_manager' => ['Finance Manager', 1, ['wallet.view' => 'all', 'wallet.credit' => 'all', 'wallet.debit' => 'all',
            'payments.view' => 'all', 'payments.refund' => 'all', 'reports.view' => 'all', 'reports.export' => 'all']],
        'support_manager' => ['Support Manager', 1, ['users.view' => 'team', 'users.edit' => 'team',
            'wallet.view' => 'team', 'payments.view' => 'team']],
        'content_manager' => ['Content Manager', 1, ['pages.view' => 'all', 'pages.edit' => 'all', 'pages.approve' => 'all',
            'proposals.view' => 'all', 'proposals.moderate' => 'all']],
        'moderation_manager' => ['Moderation Manager', 1, ['pages.approve' => 'team', 'proposals.moderate' => 'team',
            'letters.moderate' => 'team', 'users.view' => 'team']],
        'analyst' => ['Analyst', 1, ['reports.view' => 'all', 'reports.export' => 'all']],
        'customer' => ['Customer', 1, []],
        'verified_customer' => ['Verified Customer', 1, []],
        'restricted_customer' => ['Restricted Customer', 1, []],
    ];

    public function up(Connection $db): void
    {
        $db->statement('CREATE TABLE IF NOT EXISTS roles (
            id        SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
            slug      VARCHAR(40) NOT NULL,
            name      VARCHAR(80) NOT NULL,
            is_system TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY uq_slug (slug)
        ) ' . self::OPTS);

        $db->statement('CREATE TABLE IF NOT EXISTS permissions (
            id     SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
            code   VARCHAR(60) NOT NULL,
            module VARCHAR(30) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_code (code)
        ) ' . self::OPTS);

        $db->statement("CREATE TABLE IF NOT EXISTS role_permissions (
            role_id       SMALLINT UNSIGNED NOT NULL,
            permission_id SMALLINT UNSIGNED NOT NULL,
            scope         ENUM('own','team','department','all') NOT NULL DEFAULT 'own',
            PRIMARY KEY (role_id, permission_id),
            CONSTRAINT fk_rp_role FOREIGN KEY (role_id) REFERENCES roles (id),
            CONSTRAINT fk_rp_perm FOREIGN KEY (permission_id) REFERENCES permissions (id)
        ) " . self::OPTS);

        $db->statement('CREATE TABLE IF NOT EXISTS user_roles (
            user_id    BIGINT UNSIGNED NOT NULL,
            role_id    SMALLINT UNSIGNED NOT NULL,
            created_at DATETIME(3) NOT NULL,
            PRIMARY KEY (user_id, role_id),
            KEY ix_role (role_id),
            CONSTRAINT fk_ur_user FOREIGN KEY (user_id) REFERENCES users (id),
            CONSTRAINT fk_ur_role FOREIGN KEY (role_id) REFERENCES roles (id)
        ) ' . self::OPTS);

        foreach (self::PERMISSIONS as $code) {
            $db->exec('INSERT IGNORE INTO permissions (code, module) VALUES (?, ?)', [$code, explode('.', $code)[0]]);
        }
        foreach (self::ROLES as $slug => [$name, $system, $perms]) {
            $db->exec('INSERT IGNORE INTO roles (slug, name, is_system) VALUES (?, ?, ?)', [$slug, $name, $system]);
            $roleId = (int) $db->scalar('SELECT id FROM roles WHERE slug = ?', [$slug]);
            $grants = $perms === '*' ? array_fill_keys(self::PERMISSIONS, 'all') : $perms;
            foreach ($grants as $code => $scope) {
                $db->exec(
                    'INSERT IGNORE INTO role_permissions (role_id, permission_id, scope)
                     SELECT ?, id, ? FROM permissions WHERE code = ?',
                    [$roleId, $scope, $code]
                );
            }
        }
    }
};
