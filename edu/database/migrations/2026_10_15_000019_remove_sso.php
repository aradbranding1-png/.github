<?php
/** SSO with my.aradbranding.me was removed: drop its settings and permissions. User rows (my_user_id) are kept for history / API. */
return [
    'description' => 'حذف قابلیت SSO (تنظیمات و مجوزهای مربوط)',
    'up' => function (PDO $db): void {
        $db->exec("DELETE FROM settings WHERE `key` LIKE 'sso\\_%'");
        foreach (['role_permissions', 'user_permissions'] as $t) {
            if ($db->query("SHOW TABLES LIKE '$t'")->fetch()) $db->exec("DELETE FROM `$t` WHERE permission_key IN ('sso.view','sso.edit')");
        }
    },
];
