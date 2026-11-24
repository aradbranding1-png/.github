<?php

declare(strict_types=1);

use App\Core\Db\Connection;

/*
 * 1.19.21: who was active on which day. Until now "کاربران فعال روزانه" was only a number computed from
 * users.last_active_at, so nobody could see WHICH members were active on a past day. user_activity_days keeps
 * one row per member per Tehran calendar day (written at most about once an hour per member).
 *
 * Backfill: the last 90 days are rebuilt from what the database already knows about each member's activity
 * (successful logins, activity_events, letters, new pages/proposals, sign-ups and last_active_at). Plain
 * page visits before this version were never recorded, so older days can show fewer members than the old
 * DAU number; from this version on every visit is recorded.
 */
return new class {
    private const OPTS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci';

    public function up(Connection $db): void
    {
        $db->statement('CREATE TABLE IF NOT EXISTS user_activity_days (
            day           DATE NOT NULL,
            user_id       BIGINT UNSIGNED NOT NULL,
            first_seen_at DATETIME(3) NOT NULL,
            last_seen_at  DATETIME(3) NOT NULL,
            PRIMARY KEY (day, user_id),
            KEY ix_user (user_id, day)
        ) ' . self::OPTS);

        // "What did this member do" on the admin user page reads letters by sender.
        $hasIndex = $db->scalar(
            "SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'letter_messages' AND INDEX_NAME = 'ix_sender' LIMIT 1"
        ) !== null;
        if (!$hasIndex) {
            $db->statement('ALTER TABLE letter_messages ADD KEY ix_sender (sender_id, created_at)');
        }

        // Timestamps are UTC; days are Tehran (or DISPLAY_TIMEZONE) calendar days.
        $tz = \App\Modules\Admin\MetricsService::timezone();
        $offset = (int) $tz->getOffset(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $since = gmdate('Y-m-d H:i:s', time() - 90 * 86400);

        $sources = [
            "SELECT user_id AS uid, created_at AS ts FROM login_logs WHERE result = 'success' AND user_id IS NOT NULL AND created_at >= ?",
            'SELECT user_id AS uid, created_at AS ts FROM activity_events WHERE user_id IS NOT NULL AND created_at >= ?',
            'SELECT sender_id AS uid, created_at AS ts FROM letter_messages WHERE created_at >= ?',
            'SELECT user_id AS uid, created_at AS ts FROM proposals WHERE created_at >= ?',
            'SELECT user_id AS uid, created_at AS ts FROM pages WHERE created_at >= ?',
            'SELECT id AS uid, created_at AS ts FROM users WHERE created_at >= ?',
            'SELECT id AS uid, last_active_at AS ts FROM users WHERE last_active_at >= ?',
        ];
        foreach ($sources as $sql) {
            try {
                $db->exec(
                    'INSERT INTO user_activity_days (day, user_id, first_seen_at, last_seen_at)
                     SELECT DATE(s.ts + INTERVAL ' . $offset . ' SECOND), s.uid, MIN(s.ts), MAX(s.ts)
                       FROM (' . $sql . ') AS s
                      GROUP BY DATE(s.ts + INTERVAL ' . $offset . ' SECOND), s.uid
                     ON DUPLICATE KEY UPDATE first_seen_at = LEAST(first_seen_at, VALUES(first_seen_at)),
                                             last_seen_at = GREATEST(last_seen_at, VALUES(last_seen_at))',
                    [$since]
                );
            } catch (\Throwable) {
                // A missing table on an old install only means less history; the new tracking still works.
            }
        }
    }
};
