<?php

declare(strict_types=1);

use App\Core\Db\Connection;

/*
 * 1.16 — Trust & Safety + official API.
 * abuse_reports: one report per reporter per target; the moderation queue reads (status, id).
 * user_blocks: member-to-member blocks, stored once (blocker → blocked) and checked both ways.
 * users.suspended_until / status_reason: timed suspension that lifts itself at the next sign-in.
 * letter_messages.hidden_at: a reported message hidden by moderation (body kept for the record).
 * api_keys: personal API keys (doc «جدول‌های دیگر»: prefix, key_hash, scopes, expires_at, last_used_at, revoked_at).
 * Refunds stay append-only: a refund is a T_REFUND ledger row with ref_type 'refund_of' → the refunded row.
 */
return new class {
    private const OPTS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci';

    public function up(Connection $db): void
    {
        $column = static fn (string $table, string $col): bool => $db->scalar(
            'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1',
            [$table, $col]
        ) !== null;

        $db->statement('CREATE TABLE IF NOT EXISTS abuse_reports (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            public_id       BINARY(16) NOT NULL,
            reporter_id     BIGINT UNSIGNED NOT NULL,
            target_type     TINYINT UNSIGNED NOT NULL,
            target_id       BIGINT UNSIGNED NOT NULL,
            target_user_id  BIGINT UNSIGNED NOT NULL,
            target_at       DATETIME(3) NULL,
            reason          VARCHAR(20) NOT NULL,
            details         VARCHAR(1000) NULL,
            status          TINYINT UNSIGNED NOT NULL DEFAULT 1,
            action          VARCHAR(30) NULL,
            resolution      VARCHAR(500) NULL,
            handled_by      BIGINT UNSIGNED NULL,
            handled_at      DATETIME(3) NULL,
            created_at      DATETIME(3) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_public (public_id),
            UNIQUE KEY uq_once (reporter_id, target_type, target_id),
            KEY ix_queue (status, id),
            KEY ix_target_user (target_user_id, created_at),
            KEY ix_target (target_type, target_id)
        ) ' . self::OPTS);

        $db->statement('CREATE TABLE IF NOT EXISTS user_blocks (
            user_id    BIGINT UNSIGNED NOT NULL,
            blocked_id BIGINT UNSIGNED NOT NULL,
            created_at DATETIME(3) NOT NULL,
            PRIMARY KEY (user_id, blocked_id),
            KEY ix_blocked (blocked_id, user_id)
        ) ' . self::OPTS);

        if (!$column('users', 'suspended_until')) {
            $db->statement('ALTER TABLE users ADD COLUMN suspended_until DATETIME(3) NULL AFTER status, ADD COLUMN status_reason VARCHAR(255) NULL AFTER suspended_until');
        }
        if (!$column('letter_messages', 'hidden_at')) {
            $db->statement('ALTER TABLE letter_messages ADD COLUMN hidden_at DATETIME(3) NULL, ADD COLUMN hidden_by BIGINT UNSIGNED NULL');
        }

        $db->statement('CREATE TABLE IF NOT EXISTS api_keys (
            id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id      BIGINT UNSIGNED NOT NULL,
            name         VARCHAR(80) NOT NULL,
            prefix       CHAR(8) NOT NULL,
            key_hash     BINARY(32) NOT NULL,
            scopes       VARCHAR(255) NOT NULL,
            expires_at   DATETIME(3) NULL,
            last_used_at DATETIME(3) NULL,
            last_ip      VARBINARY(16) NULL,
            requests     BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at   DATETIME(3) NOT NULL,
            revoked_at   DATETIME(3) NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_prefix (prefix),
            KEY ix_user (user_id, revoked_at)
        ) ' . self::OPTS);

        foreach (['api.enabled' => 'true', 'trust.daily_reports' => '20'] as $key => $json) {
            $db->exec('INSERT IGNORE INTO settings (`key`, value, updated_at) VALUES (?, ?, NOW(3))', [$key, $json]);
        }
        // Moderation managers act on reports (warnings, short suspensions) in their team scope.
        $db->exec(
            "INSERT IGNORE INTO role_permissions (role_id, permission_id, scope)
             SELECT r.id, p.id, 'team' FROM roles r JOIN permissions p ON p.code = 'users.edit' WHERE r.slug = 'moderation_manager'"
        );
    }
};
