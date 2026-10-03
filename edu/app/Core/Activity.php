<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Learner activity tracking. Every event is stored in activity_logs and aggregated per day
 * into user_daily_activity (fast calendar / daily-monthly-yearly statistics).
 */
final class Activity
{
    private const COUNTERS = [
        'login' => 'logins',
        'lesson_view' => 'lessons_viewed',
        'lesson_complete' => 'lessons_completed',
        'course_complete' => 'courses_completed',
        'exam_submit' => 'exams',
        'exercise_submit' => 'exercises',
        'file_view' => 'content_views',
        'file_download' => 'content_views',
    ];

    public static function track(int $userId, string $type, ?string $refType = null, ?int $refId = null, array $meta = [], int $seconds = 0): void
    {
        try {
            DB::insert('activity_logs', [
                'user_id' => $userId, 'type' => $type, 'ref_type' => $refType, 'ref_id' => $refId,
                'meta' => $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
                'ip' => PHP_SAPI === 'cli' ? null : Request::ip(), 'day' => today(), 'created_at' => now(),
            ]);
            self::bump($userId, $type, $seconds);
        } catch (\Throwable $e) {
            Logger::warning('activity track failed: ' . $e->getMessage());
        }
    }

    /** Increase daily counters without a detailed log row (e.g. time heartbeat). */
    public static function bump(int $userId, string $type, int $seconds = 0): void
    {
        $col = self::COUNTERS[$type] ?? null;
        $inc = $col ? 1 : 0;
        $colSql = $col ? ", `$col` = `$col` + 1" : '';
        $learning = in_array($type, ['lesson_view', 'lesson_complete', 'course_complete', 'exam_submit', 'exercise_submit', 'file_view', 'file_download', 'heartbeat'], true) ? 1 : 0;
        DB::run(
            'INSERT INTO user_daily_activity (user_id, day, logins, lessons_viewed, lessons_completed, courses_completed, exams, exercises, content_views, seconds_spent, learning_events)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE seconds_spent = seconds_spent + VALUES(seconds_spent), learning_events = learning_events + VALUES(learning_events)' . $colSql,
            [
                $userId, today(),
                $col === 'logins' ? $inc : 0, $col === 'lessons_viewed' ? $inc : 0, $col === 'lessons_completed' ? $inc : 0,
                $col === 'courses_completed' ? $inc : 0, $col === 'exams' ? $inc : 0, $col === 'exercises' ? $inc : 0,
                $col === 'content_views' ? $inc : 0, max(0, min($seconds, 600)), $learning,
            ]
        );
    }
}
