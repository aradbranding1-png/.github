<?php

declare(strict_types=1);

use App\Core\Db\Connection;

/*
 * Phase 4: letters (threads, participants read model, partitioned messages), public-letter
 * campaigns with batch deliveries, business connections and notifications.
 */
return new class {
    private const OPTS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci';

    public function up(Connection $db): void
    {
        $db->statement('CREATE TABLE IF NOT EXISTS letter_campaigns (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            public_id       BINARY(16) NOT NULL,
            sender_id       BIGINT UNSIGNED NOT NULL,
            subject         VARCHAR(150) NOT NULL,
            body            MEDIUMTEXT NOT NULL,
            filter          JSON NOT NULL,
            recipient_count INT UNSIGNED NOT NULL DEFAULT 0,
            domestic_count  INT UNSIGNED NOT NULL DEFAULT 0,
            total_cost      BIGINT UNSIGNED NOT NULL DEFAULT 0,
            delivered_count INT UNSIGNED NOT NULL DEFAULT 0,
            spent           BIGINT UNSIGNED NOT NULL DEFAULT 0,
            last_user_id    BIGINT UNSIGNED NOT NULL DEFAULT 0,
            status          TINYINT UNSIGNED NOT NULL,
            created_at      DATETIME(3) NOT NULL,
            updated_at      DATETIME(3) NOT NULL,
            completed_at    DATETIME(3) NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_public (public_id),
            KEY ix_sender (sender_id, id),
            KEY ix_status (status, id)
        ) ' . self::OPTS);

        $db->statement('CREATE TABLE IF NOT EXISTS letter_threads (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            public_id       BINARY(16) NOT NULL,
            type            TINYINT UNSIGNED NOT NULL,
            subject         VARCHAR(150) NOT NULL,
            created_by      BIGINT UNSIGNED NOT NULL,
            campaign_id     BIGINT UNSIGNED NULL,
            proposal_id     BIGINT UNSIGNED NULL,
            message_count   INT UNSIGNED NOT NULL DEFAULT 0,
            last_message_at DATETIME(3) NOT NULL,
            created_at      DATETIME(3) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_public (public_id)
        ) ' . self::OPTS);

        $db->statement('CREATE TABLE IF NOT EXISTS thread_participants (
            user_id         BIGINT UNSIGNED NOT NULL,
            thread_id       BIGINT UNSIGNED NOT NULL,
            peer_id         BIGINT UNSIGNED NOT NULL,
            folder          TINYINT UNSIGNED NOT NULL,
            unread_count    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            thread_type     TINYINT UNSIGNED NOT NULL,
            subject         VARCHAR(150) NOT NULL,
            preview         VARCHAR(160) NOT NULL,
            last_message_at DATETIME(3) NOT NULL,
            PRIMARY KEY (user_id, thread_id),
            KEY ix_box (user_id, folder, last_message_at, thread_id),
            KEY ix_thread (thread_id)
        ) ' . self::OPTS);

        $db->statement("CREATE TABLE IF NOT EXISTS letter_messages (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            thread_id   BIGINT UNSIGNED NOT NULL,
            sender_id   BIGINT UNSIGNED NOT NULL,
            campaign_id BIGINT UNSIGNED NULL,
            body        MEDIUMTEXT NULL,
            created_at  DATETIME(3) NOT NULL,
            PRIMARY KEY (id, created_at),
            KEY ix_thread (thread_id, created_at, id)
        ) " . self::OPTS . "
        PARTITION BY RANGE COLUMNS (created_at) (
            PARTITION p_init VALUES LESS THAN ('2026-01-01'),
            PARTITION pmax VALUES LESS THAN (MAXVALUE)
        )");

        $db->statement('CREATE TABLE IF NOT EXISTS campaign_deliveries (
            campaign_id  BIGINT UNSIGNED NOT NULL,
            recipient_id BIGINT UNSIGNED NOT NULL,
            thread_id    BIGINT UNSIGNED NOT NULL,
            cost         INT UNSIGNED NOT NULL,
            PRIMARY KEY (campaign_id, recipient_id)
        ) ' . self::OPTS);

        $db->statement('CREATE TABLE IF NOT EXISTS user_connections (
            user_id    BIGINT UNSIGNED NOT NULL,
            peer_id    BIGINT UNSIGNED NOT NULL,
            source     TINYINT UNSIGNED NOT NULL,
            thread_id  BIGINT UNSIGNED NULL,
            created_at DATETIME(3) NOT NULL,
            PRIMARY KEY (user_id, peer_id),
            KEY ix_list (user_id, created_at, peer_id)
        ) ' . self::OPTS);

        $db->statement("CREATE TABLE IF NOT EXISTS notifications (
            id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id    BIGINT UNSIGNED NOT NULL,
            type       VARCHAR(32) NOT NULL,
            actor_id   BIGINT UNSIGNED NULL,
            link       VARCHAR(255) NULL,
            data       JSON NULL,
            is_read    TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME(3) NOT NULL,
            PRIMARY KEY (id, created_at),
            KEY ix_user_list (user_id, created_at, id),
            KEY ix_user_unread (user_id, is_read, created_at)
        ) " . self::OPTS . "
        PARTITION BY RANGE COLUMNS (created_at) (
            PARTITION p_init VALUES LESS THAN ('2026-01-01'),
            PARTITION pmax VALUES LESS THAN (MAXVALUE)
        )");

        foreach (['letters.public_max_recipients' => '10000', 'letters.public_daily_campaigns' => '3'] as $key => $json) {
            $db->exec('INSERT IGNORE INTO settings (`key`, value, updated_at) VALUES (?, ?, NOW(3))', [$key, $json]);
        }
    }
};
