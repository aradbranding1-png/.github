<?php

declare(strict_types=1);

use App\Core\Db\Connection;

/*
 * Mailbox v2:
 *  - category tabs (type filter) need (user_id, folder, thread_type, last_message_at, thread_id)
 *  - group sends ("ارسال نامه") can be a public letter, private letters or a proposal → letter_campaigns.kind
 *  - official letters from Arad Branding: announcements shown to every user (no per-user rows)
 *  - every option editable in settings: upload size, star rate & packages already in tables
 */
return new class {
    private const OPTS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci';

    public function up(Connection $db): void
    {
        if (!$this->hasIndex($db, 'thread_participants', 'ix_box_type')) {
            $db->statement('ALTER TABLE thread_participants ADD KEY ix_box_type (user_id, folder, thread_type, last_message_at, thread_id)');
        }
        if (!$this->hasColumn($db, 'letter_campaigns', 'kind')) {
            $db->statement('ALTER TABLE letter_campaigns ADD COLUMN kind TINYINT UNSIGNED NOT NULL DEFAULT 2 AFTER sender_id, ADD COLUMN proposal_id BIGINT UNSIGNED NULL AFTER kind');
        }
        if (!$this->hasColumn($db, 'user_counters', 'last_announcement_id')) {
            $db->statement('ALTER TABLE user_counters ADD COLUMN last_announcement_id BIGINT UNSIGNED NOT NULL DEFAULT 0');
        }

        $db->statement('CREATE TABLE IF NOT EXISTS announcements (
            id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            public_id    BINARY(16) NOT NULL,
            subject      VARCHAR(150) NOT NULL,
            body         MEDIUMTEXT NOT NULL,
            created_by   BIGINT UNSIGNED NOT NULL,
            published_at DATETIME(3) NOT NULL,
            deleted_at   DATETIME(3) NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_public (public_id),
            KEY ix_published (deleted_at, published_at)
        ) ' . self::OPTS);

        foreach (['upload.max_kb' => '200', 'org.name' => '"آراد برندینگ"'] as $key => $json) {
            $db->exec('INSERT IGNORE INTO settings (`key`, value, updated_at) VALUES (?, ?, NOW(3))', [$key, $json]);
        }
    }

    private function hasColumn(Connection $db, string $table, string $column): bool
    {
        return $db->scalar(
            'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        ) !== null;
    }

    private function hasIndex(Connection $db, string $table, string $index): bool
    {
        return $db->scalar(
            'SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1',
            [$table, $index]
        ) !== null;
    }
};
