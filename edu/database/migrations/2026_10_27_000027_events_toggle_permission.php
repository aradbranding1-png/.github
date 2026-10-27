<?php
/** Stop / activate online events: everyone who can open the events pages (events.view) may also stop or re-activate them */
return [
    'description' => 'جلسات آنلاین: مجوز «توقف / فعال‌سازی» برای همه نقش‌ها و افرادی که این صفحه را می‌بینند',
    'up' => function (PDO $db): void {
        $db->exec("INSERT IGNORE INTO permissions (`key`, module, action, label, root_only) VALUES ('events.toggle', 'events', 'toggle', 'جلسات آنلاین (وبینار، کارگاه، میتینگ) — توقف / فعال‌سازی', 0)");
        $db->exec("INSERT IGNORE INTO role_permissions (role_id, permission_key) SELECT role_id, 'events.toggle' FROM role_permissions WHERE permission_key = 'events.view'");
        $db->exec("INSERT IGNORE INTO user_permissions (user_id, permission_key, effect, created_at)
                   SELECT user_id, 'events.toggle', 'allow', NOW() FROM user_permissions WHERE permission_key = 'events.view' AND effect = 'allow'");
    },
];
