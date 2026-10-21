<?php
/**
 * Bale broadcast: every new webinar / meeting (and every course meant for merchants) is announced
 * to the users connected to the Bale bot of my.aradbranding.me, through its API. One row per message.
 */
return [
    'description' => 'ارسال خودکار خبر وبینار، میتینگ و دوره تاجران به ربات بله (my.aradbranding.me)',
    'up' => function (PDO $db): void {
        $db->exec("CREATE TABLE IF NOT EXISTS bale_messages (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            ref VARCHAR(60) NOT NULL,
            ref_type VARCHAR(20) NOT NULL,
            ref_id INT UNSIGNED NOT NULL,
            title VARCHAR(250) NULL,
            segment VARCHAR(20) NOT NULL DEFAULT 'merchant',
            text TEXT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'sending',
            recipients INT UNSIGNED NULL,
            attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
            error VARCHAR(500) NULL,
            created_by INT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            sent_at DATETIME NULL,
            UNIQUE KEY uq_bale_ref (ref),
            KEY idx_bale_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    },
];
