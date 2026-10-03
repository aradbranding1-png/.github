<?php
/** System schema: updates history and backups registry. */
return [
    'description' => 'جداول سیستمی: تاریخچه بروزرسانی و پشتیبان‌ها',
    'up' => function (PDO $db): void {
        $T = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        $db->exec("CREATE TABLE IF NOT EXISTS system_updates (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            package_name VARCHAR(200) NOT NULL,
            package_sha256 CHAR(64) NULL,
            from_version VARCHAR(30) NOT NULL,
            to_version VARCHAR(30) NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'uploaded',
            with_backup TINYINT(1) NOT NULL DEFAULT 1,
            backup_id INT UNSIGNED NULL,
            plan_json MEDIUMTEXT NULL,
            migrations_ran TEXT NULL,
            failed_migration VARCHAR(191) NULL,
            health_before TEXT NULL,
            health_after TEXT NULL,
            log_text MEDIUMTEXT NULL,
            error TEXT NULL,
            started_by INT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            started_at DATETIME NULL,
            finished_at DATETIME NULL
        )$T");
        $db->exec("CREATE TABLE IF NOT EXISTS backups (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            filename VARCHAR(200) NOT NULL,
            type VARCHAR(20) NOT NULL DEFAULT 'full',
            size BIGINT UNSIGNED NOT NULL DEFAULT 0,
            sha256 CHAR(64) NULL,
            app_version VARCHAR(30) NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'success',
            note VARCHAR(255) NULL,
            created_by INT UNSIGNED NULL,
            created_at DATETIME NOT NULL
        )$T");
    },
];
