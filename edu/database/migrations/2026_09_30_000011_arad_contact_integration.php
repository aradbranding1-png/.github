<?php
/**
 * Arad Contact integration (sales system → automatic account creation and service charging)
 *  - users.must_change_password : forced password change on next sign-in (random password sent by Arad Contact)
 *  - users.account_from / account_until : yearly «اکانت سامانه آموزش» subscription
 *  - arad_contact_orders : one row per external_id (idempotency + stored response for retries)
 *  - arad_contact_logs   : every API call and its result
 *  - level «تاجر آموز» in the traders group (created only when missing)
 */
return [
    'description' => 'اتصال آراد کانتکت: ساخت خودکار حساب، شارژ خدمات، تغییر اجباری رمز و لاگ درخواست‌ها',
    'up' => function (PDO $db): void {
        $T = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        $has = function (string $table, string $col) use ($db): bool {
            return (bool)$db->query("SHOW COLUMNS FROM `$table` LIKE " . $db->quote($col))->fetch();
        };
        if (!$has('users', 'must_change_password')) $db->exec('ALTER TABLE users ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0');
        if (!$has('users', 'account_from')) $db->exec('ALTER TABLE users ADD COLUMN account_from DATE NULL');
        if (!$has('users', 'account_until')) $db->exec('ALTER TABLE users ADD COLUMN account_until DATE NULL');

        $db->exec("CREATE TABLE IF NOT EXISTS arad_contact_orders (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            external_id VARCHAR(191) NOT NULL,
            mobile VARCHAR(20) NOT NULL,
            user_id INT UNSIGNED NULL,
            user_created TINYINT(1) NOT NULL DEFAULT 0,
            request_hash CHAR(64) NOT NULL,
            response_json MEDIUMTEXT NULL,
            password_enc VARCHAR(255) NULL,
            replay_count INT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            last_replay_at DATETIME NULL,
            UNIQUE KEY uq_aco_external (external_id),
            KEY idx_aco_user (user_id),
            KEY idx_aco_mobile (mobile)
        )$T");

        $db->exec("CREATE TABLE IF NOT EXISTS arad_contact_logs (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            endpoint VARCHAR(30) NOT NULL,
            method VARCHAR(10) NOT NULL,
            ip VARCHAR(45) NULL,
            http_status SMALLINT UNSIGNED NOT NULL,
            success TINYINT(1) NOT NULL DEFAULT 0,
            duplicate TINYINT(1) NOT NULL DEFAULT 0,
            user_created TINYINT(1) NOT NULL DEFAULT 0,
            external_id VARCHAR(191) NULL,
            mobile VARCHAR(20) NULL,
            user_id INT UNSIGNED NULL,
            message VARCHAR(500) NULL,
            request_json MEDIUMTEXT NULL,
            response_json MEDIUMTEXT NULL,
            duration_ms INT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            KEY idx_acl_created (created_at),
            KEY idx_acl_external (external_id),
            KEY idx_acl_mobile (mobile),
            KEY idx_acl_status (success, created_at)
        )$T");

        // level «تاجر آموز» in the traders group — only when it does not exist yet (placed before the first level)
        $gid = $db->query("SELECT id FROM `groups` WHERE name = 'تاجران' ORDER BY (parent_id IS NULL) DESC, id LIMIT 1")->fetchColumn()
            ?: $db->query("SELECT id FROM `groups` WHERE segment = 'merchant' AND is_system = 1 AND parent_id IS NULL ORDER BY id LIMIT 1")->fetchColumn();
        if ($gid) {
            $st = $db->prepare("SELECT id FROM levels WHERE group_id = ? AND REPLACE(REPLACE(name, '\u{200C}', ' '), '  ', ' ') = 'تاجر آموز' LIMIT 1");
            $st->execute([(int)$gid]);
            if (!$st->fetchColumn()) {
                $min = $db->prepare('SELECT COALESCE(MIN(rank_no), 1) FROM levels WHERE group_id = ?');
                $min->execute([(int)$gid]);
                $rank = (int)$min->fetchColumn() - 1;
                $db->prepare('INSERT INTO levels (group_id, name, rank_no, color, description, created_at) VALUES (?,?,?,?,?,?)')
                   ->execute([(int)$gid, 'تاجر آموز', $rank, '#94a3b8', 'سطح پیش‌فرض تاجرانی که از طریق آراد کانتکت ثبت‌نام می‌شوند', date('Y-m-d H:i:s')]);
            }
        }
        @unlink(dirname(__DIR__, 2) . '/storage/cache/settings.php');
    },
];
