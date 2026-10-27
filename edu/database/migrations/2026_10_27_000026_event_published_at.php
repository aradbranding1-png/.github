<?php
/** Events: when an event was (last) published — the Bale bot's delay counts from here, so a stopped and re-activated webinar is still announced */
return [
    'description' => 'جلسات آنلاین: زمان انتشار (برای ارسال به ربات بله پس از توقف/فعال‌سازی)',
    'up' => function (PDO $db): void {
        $has = (bool)$db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'events' AND COLUMN_NAME = 'published_at'")->fetchColumn();
        if (!$has) {
            $db->exec('ALTER TABLE events ADD COLUMN published_at DATETIME NULL AFTER status');
            $db->exec("UPDATE events SET published_at = created_at WHERE status = 'active'");
        }
    },
];
