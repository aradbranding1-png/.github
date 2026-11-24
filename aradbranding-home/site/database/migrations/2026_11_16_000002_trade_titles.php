<?php

declare(strict_types=1);

use App\Core\Db\Connection;
use App\Modules\Users\TradeTitles;

/*
 * 1.19.21: official Arad Branding titles per country (مدیر انحصاری تجارت / مدیر تجارت / سرپرست تجارت محصول /
 * نماینده تجاری / رایزن تجاری). user_titles holds who has which title for which country (and product);
 * each kind gets a role with team-scoped permissions so the holder manages only their countries.
 */
return new class {
    private const OPTS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci';

    public function up(Connection $db): void
    {
        $db->statement("CREATE TABLE IF NOT EXISTS user_titles (
            id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id    BIGINT UNSIGNED NOT NULL,
            title_key  VARCHAR(40) NOT NULL,
            country_id SMALLINT UNSIGNED NOT NULL,
            product    VARCHAR(100) NOT NULL DEFAULT '',
            granted_by BIGINT UNSIGNED NULL,
            created_at DATETIME(3) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_title (user_id, title_key, country_id, product),
            KEY ix_country (country_id, title_key)
        ) " . self::OPTS);

        $names = [
            'exclusive_manager' => 'Exclusive Trade Manager (country)', 'trade_manager' => 'Trade Manager (country)',
            'product_supervisor' => 'Product Trade Supervisor (country)', 'representative' => 'Trade Representative (country)',
            'attache' => 'Commercial Attache (country)',
        ];
        foreach (TradeTitles::KINDS as $kind => [$slug, , , $perms]) {
            $exists = $db->scalar('SELECT id FROM roles WHERE slug = ?', [$slug]);
            if ($exists !== null) {
                continue; // keep permissions an admin may have changed
            }
            $db->exec('INSERT INTO roles (slug, name, is_system) VALUES (?, ?, 1)', [$slug, $names[$kind]]);
            $roleId = (int) $db->scalar('SELECT id FROM roles WHERE slug = ?', [$slug]);
            foreach ($perms as $code) {
                $db->exec(
                    "INSERT IGNORE INTO role_permissions (role_id, permission_id, scope) SELECT ?, id, 'team' FROM permissions WHERE code = ?",
                    [$roleId, $code]
                );
            }
        }
    }
};
