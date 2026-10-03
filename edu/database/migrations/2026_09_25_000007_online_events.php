<?php
/**
 * Online events: business webinars (hour credit), online workshops (count credit)
 * and online meetings (yearly subscription), with per-user credits and registrations.
 */
return [
    'description' => 'وبینار، کارگاه تجاری آنلاین و میتینگ آنلاین + اعتبارهای مربوط',
    'up' => function (PDO $db): void {
        $T = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        $has = function (string $table, string $col) use ($db): bool {
            return (bool)$db->query("SHOW COLUMNS FROM `$table` LIKE " . $db->quote($col))->fetch();
        };
        if (!$has('users', 'webinar_minutes')) $db->exec('ALTER TABLE users ADD COLUMN webinar_minutes INT NOT NULL DEFAULT 0');
        if (!$has('users', 'workshop_credits')) $db->exec('ALTER TABLE users ADD COLUMN workshop_credits INT NOT NULL DEFAULT 0');
        if (!$has('users', 'meeting_from')) $db->exec('ALTER TABLE users ADD COLUMN meeting_from DATE NULL');
        if (!$has('users', 'meeting_until')) $db->exec('ALTER TABLE users ADD COLUMN meeting_until DATE NULL');
        if (!$has('minute_ledger', 'credit_type')) $db->exec("ALTER TABLE minute_ledger ADD COLUMN credit_type VARCHAR(20) NOT NULL DEFAULT 'course'");
        if (!$has('minute_ledger', 'event_id')) $db->exec('ALTER TABLE minute_ledger ADD COLUMN event_id INT UNSIGNED NULL');

        $db->exec("CREATE TABLE IF NOT EXISTS events (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            type VARCHAR(20) NOT NULL,
            title VARCHAR(200) NOT NULL,
            summary VARCHAR(500) NULL,
            description MEDIUMTEXT NULL,
            image_file_id INT UNSIGNED NULL,
            banner_file_id INT UNSIGNED NULL,
            join_url VARCHAR(500) NULL,
            starts_at DATETIME NULL,
            duration_minutes INT UNSIGNED NOT NULL DEFAULT 60,
            segments VARCHAR(100) NULL,
            group_id INT UNSIGNED NULL,
            host_name VARCHAR(150) NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            created_by INT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NULL,
            deleted_at DATETIME NULL,
            KEY idx_ev_type (type, status, starts_at),
            KEY idx_ev_start (starts_at)
        )$T");
        $db->exec("CREATE TABLE IF NOT EXISTS event_registrations (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            event_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            cost INT NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'registered',
            join_count INT UNSIGNED NOT NULL DEFAULT 0,
            joined_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            UNIQUE KEY uq_er (event_id, user_id),
            KEY idx_er_user (user_id, status)
        )$T");

        $perms = [
            'events.view' => 'جلسات آنلاین (وبینار، کارگاه، میتینگ) — مشاهده',
            'events.create' => 'جلسات آنلاین — ایجاد',
            'events.edit' => 'جلسات آنلاین — ویرایش و تغییر لینک',
            'events.delete' => 'جلسات آنلاین — حذف',
            'events.report' => 'جلسات آنلاین — مشاهده ثبت‌نام‌ها و خروجی',
        ];
        $ins = $db->prepare('INSERT IGNORE INTO permissions (`key`, module, action, label, root_only) VALUES (?,?,?,?,0)');
        foreach ($perms as $k => $l) { [$m, $a] = explode('.', $k); $ins->execute([$k, $m, $a, $l]); }
        $grant = [
            'system_admin' => array_keys($perms),
            'edu_manager' => array_keys($perms),
            'edu_expert' => ['events.view', 'events.create', 'events.edit', 'events.report'],
            'support' => ['events.view', 'events.report'],
            'report_viewer' => ['events.view', 'events.report'],
        ];
        $rid = $db->prepare('SELECT id FROM roles WHERE slug = ?');
        $rp = $db->prepare('INSERT IGNORE INTO role_permissions (role_id, permission_key) VALUES (?,?)');
        foreach ($grant as $slug => $keys) {
            $rid->execute([$slug]);
            $id = $rid->fetchColumn();
            if ($id) foreach ($keys as $k) $rp->execute([(int)$id, $k]);
        }
    },
];
