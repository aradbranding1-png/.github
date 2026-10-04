<?php

declare(strict_types=1);

use App\Core\Db\Connection;

/*
 * 1.19: site languages (English, Arabic, Turkish, French, Russian next to Persian).
 *  - translations: the super admin's own wording for a UI string in a language; overrides app/Lang/{locale}.php.
 *  - translation_misses: UI strings shown in a language that has no translation for them yet (for «زبان‌ها»).
 *  - users.ui_locale: the language a member picked, restored when they sign in on another device.
 *  - permission i18n.manage (Super Admin only) for «زبان‌ها».
 */
return new class {
    public function up(Connection $db): void
    {
        $db->statement('CREATE TABLE IF NOT EXISTS translations (
            id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
            locale      VARCHAR(8) NOT NULL,
            source_hash CHAR(64) CHARACTER SET ascii NOT NULL,
            source      TEXT NOT NULL,
            text        TEXT NOT NULL,
            updated_by  BIGINT UNSIGNED NULL,
            updated_at  DATETIME(3) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_string (locale, source_hash)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci');
        $db->statement('CREATE TABLE IF NOT EXISTS translation_misses (
            locale      VARCHAR(8) NOT NULL,
            source_hash CHAR(64) CHARACTER SET ascii NOT NULL,
            source      TEXT NOT NULL,
            hits        INT UNSIGNED NOT NULL DEFAULT 1,
            first_seen  DATETIME NOT NULL,
            last_seen   DATETIME NOT NULL,
            PRIMARY KEY (locale, source_hash),
            KEY ix_seen (last_seen)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci');
        if ($db->scalar("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'ui_locale' LIMIT 1") === null) {
            $db->statement('ALTER TABLE users ADD COLUMN ui_locale VARCHAR(8) NULL');
        }
        $db->exec("INSERT IGNORE INTO permissions (code, module) VALUES ('i18n.manage', 'system')");
        $db->exec(
            "INSERT IGNORE INTO role_permissions (role_id, permission_id, scope)
             SELECT r.id, p.id, 'all' FROM roles r JOIN permissions p ON p.code = 'i18n.manage' WHERE r.slug = 'super_admin'"
        );
    }
};
