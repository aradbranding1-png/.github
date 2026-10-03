<?php
/**
 * Role credit packages (کارمند فراگیر / نماینده) with bulk-charge buttons.
 *  - roles are now matched by the learner role of the segment (slug employee / agent) or picked in the panel,
 *    not by the display name (the old name match «کارمند فراگیر» never found the role «کارمند»)
 *  - new permissions role_grants.view/edit, grant_employee.run, grant_agent.run (synced after this migration)
 *  - "processed without charge" markers (granted = 0) are removed: those members count as «در انتظار شارژ»
 */
return [
    'description' => 'بسته‌های شارژ نقش (کارمند فراگیر، نماینده) و دکمه‌های شارژ گروهی با مجوزهای جداگانه',
    'up' => function (PDO $db): void {
        $T = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        $db->exec("CREATE TABLE IF NOT EXISTS role_grants (
            user_id INT UNSIGNED NOT NULL,
            role_id INT UNSIGNED NOT NULL,
            granted TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (user_id, role_id)
        )$T");
        $db->exec('DELETE FROM role_grants WHERE granted = 0');
    },
];
