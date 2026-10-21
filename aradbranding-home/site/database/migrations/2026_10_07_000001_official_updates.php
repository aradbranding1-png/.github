<?php

declare(strict_types=1);

use App\Core\Db\Connection;

/*
 * - letters.official: only holders (Super Admin by default) may write official Arad Branding letters.
 * - system_updates: release notes shown to users ("تازه‌های سامانه"), optionally announced to everyone.
 * - update_history: every in-app update (ZIP) with backup location and result.
 */
return new class {
    private const OPTS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci';

    public function up(Connection $db): void
    {
        $db->exec("INSERT IGNORE INTO permissions (code, module) VALUES ('letters.official', 'letters')");
        $db->exec(
            "INSERT IGNORE INTO role_permissions (role_id, permission_id, scope)
             SELECT r.id, p.id, 'all' FROM roles r JOIN permissions p ON p.code = 'letters.official' WHERE r.slug = 'super_admin'"
        );

        $db->statement('CREATE TABLE IF NOT EXISTS system_updates (
            id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            version      VARCHAR(40) NULL,
            title        VARCHAR(150) NOT NULL,
            body         MEDIUMTEXT NOT NULL,
            visible      TINYINT(1) NOT NULL DEFAULT 1,
            announced    TINYINT(1) NOT NULL DEFAULT 0,
            created_by   BIGINT UNSIGNED NOT NULL,
            published_at DATETIME(3) NOT NULL,
            PRIMARY KEY (id),
            KEY ix_visible (visible, published_at)
        ) ' . self::OPTS);

        $db->statement('CREATE TABLE IF NOT EXISTS update_history (
            id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            from_version VARCHAR(40) NULL,
            to_version   VARCHAR(40) NULL,
            status       VARCHAR(20) NOT NULL,
            backup_dir   VARCHAR(255) NULL,
            log          MEDIUMTEXT NULL,
            admin_id     BIGINT UNSIGNED NOT NULL,
            started_at   DATETIME(3) NOT NULL,
            finished_at  DATETIME(3) NULL,
            PRIMARY KEY (id),
            KEY ix_started (started_at)
        ) ' . self::OPTS);
    }
};
