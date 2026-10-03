<?php
declare(strict_types=1);

namespace App\Core;

/** In-app notifications. */
final class Notify
{
    public const TYPES = [
        'assignment' => ['آموزش جدید', 'graduation-cap', 'primary'],
        'course' => ['دوره جدید', 'book-open', 'info'],
        'exam' => ['آزمون', 'clipboard-check', 'purple'],
        'deadline' => ['مهلت', 'clock', 'warning'],
        'exercise' => ['تمرین', 'notebook-pen', 'info'],
        'exam_result' => ['نتیجه آزمون', 'award', 'success'],
        'exercise_review' => ['اصلاح تمرین', 'clipboard-list', 'success'],
        'inactivity' => ['عدم فعالیت', 'triangle-alert', 'danger'],
        'certificate' => ['گواهی', 'medal', 'success'],
        'growth' => ['نظام رشد', 'trending-up', 'success'],
        'event' => ['جلسه آنلاین', 'video', 'purple'],
        'system' => ['اطلاعیه', 'bell', 'gray'],
    ];

    /** @param int|int[] $userIds */
    public static function send(int|array $userIds, string $type, string $title, string $body = '', ?string $link = null, ?string $dedupeKey = null): int
    {
        $ids = array_values(array_unique(array_map('intval', (array)$userIds)));
        $n = 0;
        foreach (array_chunk($ids, 500) as $chunk) {
            $rows = [];
            $params = [];
            foreach ($chunk as $id) {
                if ($id <= 0) continue;
                if ($dedupeKey && DB::value('SELECT 1 FROM notifications WHERE user_id = ? AND dedupe_key = ? LIMIT 1', [$id, $dedupeKey])) continue;
                $rows[] = '(?,?,?,?,?,?,?)';
                array_push($params, $id, $type, mb_substr($title, 0, 200), $body, $link, $dedupeKey, now());
                $n++;
            }
            if ($rows) DB::run('INSERT INTO notifications (user_id, type, title, body, link, dedupe_key, created_at) VALUES ' . implode(',', $rows), $params);
        }
        return $n;
    }

    /** Hides webinar/meeting announcements once the event is over, unpublished or deleted (alias n) */
    public static function visibleSql(string $a = 'n'): string
    {
        return "($a.dedupe_key IS NULL OR $a.dedupe_key NOT LIKE 'event-%'
                 OR EXISTS (SELECT 1 FROM events e WHERE e.id = CAST(SUBSTRING($a.dedupe_key, 7) AS UNSIGNED) AND " . \App\Services\EventService::liveSql() . '))';
    }

    public static function unreadCount(int $userId): int
    {
        return (int)DB::value('SELECT COUNT(*) FROM notifications n WHERE n.user_id = ? AND n.read_at IS NULL AND ' . self::visibleSql(), [$userId]);
    }

    public static function recent(int $userId, int $limit = 8): array
    {
        return DB::all('SELECT n.* FROM notifications n WHERE n.user_id = ? AND ' . self::visibleSql() . ' ORDER BY n.id DESC LIMIT ' . (int)$limit, [$userId]);
    }

    /** Users who hold a permission (for notifying reviewers etc.). Includes root. */
    public static function usersWithPermission(string $perm): array
    {
        return DB::column(
            'SELECT DISTINCT u.id FROM users u
               LEFT JOIN user_roles ur ON ur.user_id = u.id
               LEFT JOIN role_permissions rp ON rp.role_id = ur.role_id
               LEFT JOIN user_permissions up ON up.user_id = u.id AND up.permission_key = ?
             WHERE u.deleted_at IS NULL AND u.status = \'active\' AND (u.is_root = 1 OR rp.permission_key = ? OR up.effect = \'allow\')
               AND NOT EXISTS (SELECT 1 FROM user_permissions d WHERE d.user_id = u.id AND d.permission_key = ? AND d.effect = \'deny\')',
            [$perm, $perm, $perm]
        );
    }
}
