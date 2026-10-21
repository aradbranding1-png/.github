<?php

declare(strict_types=1);

use App\Core\Db\Connection;

/*
 * Phase 0 core tables. utf8mb4_unicode_520_ci works identically on MySQL 8.4 and MariaDB 11.4.
 * Partitioned tables start with one historical partition + pmax;
 * PartitionManager adds monthly partitions right after migrating.
 */
return new class {
    private const OPTS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci';

    public function up(Connection $db): void
    {
        $db->statement('CREATE TABLE IF NOT EXISTS settings (
            `key`       VARCHAR(100) NOT NULL,
            value       JSON NOT NULL,
            updated_by  BIGINT UNSIGNED NULL,
            updated_at  DATETIME(3) NOT NULL,
            PRIMARY KEY (`key`)
        ) ' . self::OPTS);

        $db->statement('CREATE TABLE IF NOT EXISTS jobs (
            id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            queue        VARCHAR(32) NOT NULL,
            job          VARCHAR(191) NOT NULL,
            payload      JSON NOT NULL,
            attempts     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            available_at DATETIME(3) NOT NULL,
            reserved_at  DATETIME(3) NULL,
            reserved_by  VARCHAR(100) NULL,
            created_at   DATETIME(3) NOT NULL,
            PRIMARY KEY (id),
            KEY ix_pick (queue, reserved_at, available_at, id),
            KEY ix_reserved (reserved_at)
        ) ' . self::OPTS);

        $db->statement('CREATE TABLE IF NOT EXISTS failed_jobs (
            id        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            queue     VARCHAR(32) NOT NULL,
            job       VARCHAR(191) NOT NULL,
            payload   JSON NOT NULL,
            attempts  SMALLINT UNSIGNED NOT NULL,
            error     TEXT NOT NULL,
            failed_at DATETIME(3) NOT NULL,
            PRIMARY KEY (id),
            KEY ix_failed (failed_at)
        ) ' . self::OPTS);

        $db->statement('CREATE TABLE IF NOT EXISTS sessions (
            id            BINARY(32) NOT NULL,
            user_id       BIGINT UNSIGNED NULL,
            payload       MEDIUMBLOB NOT NULL,
            ip            VARBINARY(16) NULL,
            ua_hash       BINARY(32) NULL,
            last_activity INT UNSIGNED NOT NULL,
            PRIMARY KEY (id),
            KEY ix_user (user_id),
            KEY ix_activity (last_activity)
        ) ' . self::OPTS);

        $db->statement('CREATE TABLE IF NOT EXISTS idempotency_keys (
            scope      VARCHAR(32) NOT NULL,
            key_hash   BINARY(32) NOT NULL,
            user_id    BIGINT UNSIGNED NOT NULL,
            result_ref BIGINT UNSIGNED NULL,
            created_at DATETIME(3) NOT NULL,
            PRIMARY KEY (scope, key_hash),
            KEY ix_created (created_at)
        ) ' . self::OPTS);

        $db->statement("CREATE TABLE IF NOT EXISTS activity_events (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event           VARCHAR(40) NOT NULL,
            user_id         BIGINT UNSIGNED NULL,
            subject_user_id BIGINT UNSIGNED NULL,
            ref_id          BIGINT UNSIGNED NULL,
            data            JSON NULL,
            processed_at    DATETIME(3) NULL,
            created_at      DATETIME(3) NOT NULL,
            PRIMARY KEY (id, created_at),
            KEY ix_pending (processed_at, id)
        ) " . self::OPTS . "
        PARTITION BY RANGE COLUMNS (created_at) (
            PARTITION p_init VALUES LESS THAN ('2026-01-01'),
            PARTITION pmax VALUES LESS THAN (MAXVALUE)
        )");

        $db->statement("CREATE TABLE IF NOT EXISTS audit_logs (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            actor_id        BIGINT UNSIGNED NULL,
            impersonator_id BIGINT UNSIGNED NULL,
            action          VARCHAR(64) NOT NULL,
            target_type     VARCHAR(32) NULL,
            target_id       BIGINT UNSIGNED NULL,
            result          VARCHAR(16) NOT NULL,
            ip              VARBINARY(16) NULL,
            device          VARCHAR(191) NULL,
            meta            JSON NULL,
            created_at      DATETIME(3) NOT NULL,
            PRIMARY KEY (id, created_at),
            KEY ix_actor (actor_id, created_at),
            KEY ix_target (target_type, target_id, created_at)
        ) " . self::OPTS . "
        PARTITION BY RANGE COLUMNS (created_at) (
            PARTITION p_init VALUES LESS THAN ('2026-01-01'),
            PARTITION pmax VALUES LESS THAN (MAXVALUE)
        )");
    }
};
