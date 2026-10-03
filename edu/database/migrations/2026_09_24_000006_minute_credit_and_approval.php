<?php
/**
 * Minute credit (اعتبار زمانی) for selling courses + mandatory account approval.
 *  - users.minute_balance, minute_ledger (every charge/consumption), lesson_unlocks (activated lessons)
 *  - new permissions users.approve, credits.view, credits.edit (granted to system admin, education manager, support)
 *  - registration closed and approval required by default
 */
return [
    'description' => 'اعتبار زمانی (دقیقه) کاربران و تأیید اجباری حساب‌های جدید',
    'up' => function (PDO $db): void {
        $T = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        $now = date('Y-m-d H:i:s');
        $col = $db->query("SHOW COLUMNS FROM users LIKE 'minute_balance'")->fetch();
        if (!$col) $db->exec("ALTER TABLE users ADD COLUMN minute_balance INT NOT NULL DEFAULT 0");

        $db->exec("CREATE TABLE IF NOT EXISTS minute_ledger (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            delta INT NOT NULL,
            balance_after INT NOT NULL,
            kind VARCHAR(20) NOT NULL,
            lesson_id INT UNSIGNED NULL,
            course_id INT UNSIGNED NULL,
            note VARCHAR(255) NULL,
            created_by INT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            KEY idx_ml_user (user_id, id),
            KEY idx_ml_kind (kind, created_at)
        )$T");
        $db->exec("CREATE TABLE IF NOT EXISTS lesson_unlocks (
            user_id INT UNSIGNED NOT NULL,
            lesson_id INT UNSIGNED NOT NULL,
            course_id INT UNSIGNED NOT NULL,
            minutes INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (user_id, lesson_id),
            KEY idx_lu_course (user_id, course_id)
        )$T");

        // permissions
        $perms = [
            'users.approve' => ['users', 'approve', 'کاربران — تأیید حساب‌های جدید'],
            'credits.view' => ['credits', 'view', 'اعتبار زمانی کاربران — مشاهده'],
            'credits.edit' => ['credits', 'edit', 'اعتبار زمانی کاربران — شارژ و کسر'],
        ];
        $ins = $db->prepare('INSERT IGNORE INTO permissions (`key`, module, action, label, root_only) VALUES (?,?,?,?,0)');
        foreach ($perms as $k => [$m, $a, $l]) $ins->execute([$k, $m, $a, $l]);
        $grant = [
            'system_admin' => ['users.approve', 'credits.view', 'credits.edit'],
            'edu_manager' => ['users.approve', 'credits.view', 'credits.edit'],
            'support' => ['users.approve', 'credits.view', 'credits.edit'],
            'report_viewer' => ['credits.view'],
        ];
        $rid = $db->prepare('SELECT id FROM roles WHERE slug = ?');
        $rp = $db->prepare('INSERT IGNORE INTO role_permissions (role_id, permission_key) VALUES (?,?)');
        foreach ($grant as $slug => $keys) {
            $rid->execute([$slug]);
            $id = $rid->fetchColumn();
            if ($id) foreach ($keys as $k) $rp->execute([(int)$id, $k]);
        }

        // settings: registration closed, every new account needs approval, minute credit on
        $set = $db->prepare('INSERT INTO settings (`key`, `value`, updated_at) VALUES (?,?,?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updated_at = VALUES(updated_at)');
        foreach (['registration_enabled' => '0', 'registration_requires_approval' => '1', 'minutes_enabled' => '1'] as $k => $v) $set->execute([$k, $v, $now]);
        @unlink(dirname(__DIR__, 2) . '/storage/cache/settings.php');
    },
];
