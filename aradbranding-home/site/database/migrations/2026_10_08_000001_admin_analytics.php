<?php

declare(strict_types=1);

use App\Core\Db\Connection;

/*
 * Phase 5: aggregated daily metrics, staff data scope (countries), impersonation log, async exports,
 * and the indexes the nightly aggregation needs (range by day, never full scans).
 */
return new class {
    private const OPTS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci';

    public function up(Connection $db): void
    {
        $indexes = [
            ['users', 'ix_last_active', 'ADD KEY ix_last_active (last_active_at)'],
            ['users', 'ix_created', 'ADD KEY ix_created (created_at)'],
            ['pages', 'ix_created', 'ADD KEY ix_created (created_at)'],
            ['proposals', 'ix_created', 'ADD KEY ix_created (created_at)'],
            ['letter_threads', 'ix_created', 'ADD KEY ix_created (created_at, type)'],
            ['user_connections', 'ix_created', 'ADD KEY ix_created (created_at)'],
            ['wallet_transactions', 'ix_type_created', 'ADD KEY ix_type_created (type, created_at)'],
            ['proposal_feed', 'ix_user', 'ADD KEY ix_user (user_id)'],
        ];
        foreach ($indexes as [$table, $name, $ddl]) {
            if ($db->scalar('SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1', [$table, $name]) === null) {
                $db->statement("ALTER TABLE `{$table}` {$ddl}");
            }
        }

        $db->statement('CREATE TABLE IF NOT EXISTS daily_metrics (
            day        DATE NOT NULL,
            metric     VARCHAR(40) NOT NULL,
            country_id SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            value      BIGINT NOT NULL,
            updated_at DATETIME(3) NOT NULL,
            PRIMARY KEY (day, metric, country_id),
            KEY ix_metric (metric, country_id, day)
        ) ' . self::OPTS);

        $db->statement('CREATE TABLE IF NOT EXISTS staff_assignments (
            user_id    BIGINT UNSIGNED NOT NULL,
            country_id SMALLINT UNSIGNED NOT NULL,
            PRIMARY KEY (user_id, country_id)
        ) ' . self::OPTS);

        $db->statement('CREATE TABLE IF NOT EXISTS impersonations (
            id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            admin_id       BIGINT UNSIGNED NOT NULL,
            target_user_id BIGINT UNSIGNED NOT NULL,
            reason         VARCHAR(255) NOT NULL,
            ip             VARBINARY(16) NULL,
            started_at     DATETIME(3) NOT NULL,
            ended_at       DATETIME(3) NULL,
            PRIMARY KEY (id),
            KEY ix_admin (admin_id, started_at)
        ) ' . self::OPTS);

        $db->statement('CREATE TABLE IF NOT EXISTS exports (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            public_id   BINARY(16) NOT NULL,
            user_id     BIGINT UNSIGNED NOT NULL,
            type        VARCHAR(32) NOT NULL,
            format      VARCHAR(8) NOT NULL,
            status      VARCHAR(16) NOT NULL,
            file        VARCHAR(255) NULL,
            row_count   INT UNSIGNED NOT NULL DEFAULT 0,
            created_at  DATETIME(3) NOT NULL,
            finished_at DATETIME(3) NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_public (public_id),
            KEY ix_user (user_id, id)
        ) ' . self::OPTS);
    }
};
