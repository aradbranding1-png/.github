<?php
/** Bale broadcasts: real delivery progress reported by «my» (sent / failed / finished) */
return [
    'description' => 'ربات بله: ذخیره وضعیت واقعی تحویل پیام‌ها',
    'up' => function (PDO $db): void {
        $has = fn(string $c) => (bool)$db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bale_messages' AND COLUMN_NAME = '$c'")->fetchColumn();
        if (!$has('sent_count')) $db->exec('ALTER TABLE bale_messages ADD COLUMN sent_count INT UNSIGNED NULL, ADD COLUMN failed_count INT UNSIGNED NULL, ADD COLUMN finished TINYINT(1) NOT NULL DEFAULT 0, ADD COLUMN checked_at DATETIME NULL');
    },
];
