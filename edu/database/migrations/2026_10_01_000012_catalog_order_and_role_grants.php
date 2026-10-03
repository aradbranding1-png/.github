<?php
/**
 * 1) courses.last_lesson_at — when the newest lesson of the course went live; the catalog lists the most recently
 *    updated courses first. Backfilled from each course's newest published lesson.
 * 2) role_grants — one row per (user, role) whose one-time welcome credits were already given (see App\Services\RoleGrants).
 *    Users who already hold a granting role are recorded as processed, so nothing is charged retroactively.
 */
return [
    'description' => 'مرتب‌سازی کاتالوگ بر اساس جدیدترین درس و شارژ خودکار اعتبار هنگام تخصیص نقش «کارمند فراگیر»',
    'up' => function (PDO $db): void {
        $T = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        $has = fn(string $t, string $c): bool => (bool)$db->query("SHOW COLUMNS FROM `$t` LIKE " . $db->quote($c))->fetch();
        $hasIdx = fn(string $t, string $i): bool => (bool)$db->query("SHOW INDEX FROM `$t` WHERE Key_name = " . $db->quote($i))->fetch();

        if (!$has('courses', 'last_lesson_at')) $db->exec('ALTER TABLE courses ADD COLUMN last_lesson_at DATETIME NULL');
        if (!$hasIdx('courses', 'idx_courses_last_lesson')) $db->exec('ALTER TABLE courses ADD KEY idx_courses_last_lesson (last_lesson_at)');
        $db->exec("UPDATE courses c JOIN (SELECT course_id, MAX(created_at) m FROM lessons WHERE deleted_at IS NULL AND status = 'published' GROUP BY course_id) t
                      ON t.course_id = c.id SET c.last_lesson_at = t.m WHERE c.last_lesson_at IS NULL");

        $db->exec("CREATE TABLE IF NOT EXISTS role_grants (
            user_id INT UNSIGNED NOT NULL,
            role_id INT UNSIGNED NOT NULL,
            granted TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (user_id, role_id)
        )$T");

        // current holders of a granting role: mark as processed (no retroactive charge)
        $norm = fn(string $s): string => preg_replace('/[\s\x{200C}]+/u', ' ', trim(strtr($s, ['ي' => 'ی', 'ك' => 'ک'])));
        $ids = [];
        foreach ($db->query('SELECT id, name FROM roles')->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if ($norm((string)$r['name']) === 'کارمند فراگیر') $ids[] = (int)$r['id'];
        }
        if ($ids) {
            $db->exec('INSERT IGNORE INTO role_grants (user_id, role_id, granted, created_at)
                       SELECT user_id, role_id, 0, NOW() FROM user_roles WHERE role_id IN (' . implode(',', $ids) . ')');
        }
    },
];
