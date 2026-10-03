<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\DB;
use App\Core\Gate;

/**
 * Data scope for managers:
 *  - all        : every user / course
 *  - supervised : users whose supervisor is me, members of groups I supervise, members of org units I manage
 *  - own        : courses where I'm the instructor (and learners of those courses)
 */
final class Scope
{
    private static ?array $cache = null;

    public static function level(): string
    {
        return Gate::scope();
    }

    /** null = unrestricted, otherwise list of visible user ids */
    public static function userIds(): ?array
    {
        if (self::level() === 'all') return null;
        if (self::$cache !== null) return self::$cache;
        $me = (int)Auth::id();
        $ids = array_map('intval', DB::column('SELECT id FROM users WHERE supervisor_id = ? AND deleted_at IS NULL', [$me]));
        foreach (DB::column('SELECT id FROM `groups` WHERE supervisor_id = ?', [$me]) as $gid) {
            $ids = array_merge($ids, Targeting::users('group', (int)$gid));
        }
        foreach (DB::column('SELECT id FROM org_units WHERE manager_id = ?', [$me]) as $oid) {
            $ids = array_merge($ids, Targeting::users('org_unit', (int)$oid));
        }
        if (self::level() === 'own') {
            $cids = self::courseIds() ?? [];
            if ($cids) $ids = array_merge($ids, array_map('intval', DB::column('SELECT DISTINCT user_id FROM enrollments WHERE course_id IN (' . DB::in($cids) . ')', $cids)));
        }
        return self::$cache = array_values(array_unique($ids));
    }

    /** SQL fragment restricting a user id column. Returns ['sql' => ' AND ...', 'params' => [...]] */
    public static function userSql(string $col = 'u.id'): array
    {
        $ids = self::userIds();
        if ($ids === null) return ['sql' => '', 'params' => []];
        if (!$ids) return ['sql' => ' AND 1 = 0', 'params' => []];
        return ['sql' => " AND $col IN (" . DB::in($ids) . ')', 'params' => $ids];
    }

    public static function canSeeUser(int $userId): bool
    {
        $ids = self::userIds();
        return $ids === null || in_array($userId, $ids, true) || $userId === (int)Auth::id();
    }

    /** null = all courses; for 'own' scope: courses I teach */
    public static function courseIds(): ?array
    {
        if (self::level() !== 'own') return null;
        return array_map('intval', DB::column('SELECT id FROM courses WHERE instructor_id = ? AND deleted_at IS NULL', [(int)Auth::id()]));
    }

    public static function courseSql(string $col = 'c.id'): array
    {
        $ids = self::courseIds();
        if ($ids === null) return ['sql' => '', 'params' => []];
        if (!$ids) return ['sql' => ' AND 1 = 0', 'params' => []];
        return ['sql' => " AND $col IN (" . DB::in($ids) . ')', 'params' => $ids];
    }

    public static function canManageCourse(array $course): bool
    {
        $ids = self::courseIds();
        return $ids === null || in_array((int)$course['id'], $ids, true);
    }

    public static function authorizeCourse(array $course): void
    {
        if (!self::canManageCourse($course)) throw new \App\Core\HttpException(403, 'این دوره در محدوده دسترسی شما نیست.');
    }

    public static function authorizeUser(int $userId): void
    {
        if (!self::canSeeUser($userId)) throw new \App\Core\HttpException(403, 'این کاربر در محدوده دسترسی شما نیست.');
    }
}
