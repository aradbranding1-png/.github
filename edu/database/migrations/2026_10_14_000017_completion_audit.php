<?php
/**
 * Suspicious lesson completions («تکمیل بدون دیدن درس»):
 * - lesson_progress.watched_sec : seconds of media actually played (seeking forward does not count)
 * - lessons.media_seconds      : real length of the lesson's audio/video, reported by the player
 * - lesson_completion_flags    : one row per suspicious completion (user + lesson) and what was done about it
 */
return [
    'description' => 'شناسایی تکمیل مشکوک درس (بدون دیدن/شنیدن کامل) و هشدار',
    'up' => function (PDO $db): void {
        if (!$db->query("SHOW COLUMNS FROM lesson_progress LIKE 'watched_sec'")->fetch()) {
            $db->exec('ALTER TABLE lesson_progress ADD COLUMN watched_sec INT UNSIGNED NOT NULL DEFAULT 0 AFTER time_spent_sec');
        }
        if (!$db->query("SHOW COLUMNS FROM lessons LIKE 'media_seconds'")->fetch()) {
            $db->exec('ALTER TABLE lessons ADD COLUMN media_seconds INT UNSIGNED NULL AFTER duration_minutes');
        }
        $db->exec("CREATE TABLE IF NOT EXISTS lesson_completion_flags (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            lesson_id INT UNSIGNED NOT NULL,
            course_id INT UNSIGNED NOT NULL,
            reason VARCHAR(30) NOT NULL,
            severity VARCHAR(10) NOT NULL DEFAULT 'medium',
            expected_sec INT UNSIGNED NOT NULL DEFAULT 0,
            elapsed_sec INT UNSIGNED NULL,
            spent_sec INT UNSIGNED NOT NULL DEFAULT 0,
            watched_sec INT UNSIGNED NOT NULL DEFAULT 0,
            completed_at DATETIME NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'open',
            notified_at DATETIME NULL,
            reviewed_by INT UNSIGNED NULL,
            reviewed_at DATETIME NULL,
            note VARCHAR(255) NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NULL,
            UNIQUE KEY uq_lcf (user_id, lesson_id),
            KEY idx_lcf_status (status, created_at),
            KEY idx_lcf_course (course_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    },
];
