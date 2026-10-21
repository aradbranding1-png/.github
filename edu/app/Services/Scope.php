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
    /** Report audience filter (segment + organizational group, with sub-groups) — set by report pages only */
    private static array $aud = ['seg' => '', 'group' => 0, 'gids' => []];

    public static function setAudience(string $seg, int $group): void
    {
        $seg = isset(\App\Core\Labels::SEGMENT[$seg]) ? $seg : '';
        $gids = $group > 0 ? Targeting::descendants('groups', $group) : [];
        self::$aud = ['seg' => $seg, 'group' => $gids ? $group : 0, 'gids' => $gids];
    }

    public static function audience(): array
    {
        return self::$aud;
    }

    /** SQL fragment for the report audience filter (empty when no filter is chosen) */
    public static function audienceSql(string $col): array
    {
        $sql = ''; $params = [];
        if (self::$aud['seg'] !== '') { $sql .= " AND $col IN (SELECT a0.id FROM users a0 WHERE a0.segment = ?)"; $params[] = self::$aud['seg']; }
        if (self::$aud['gids']) { $sql .= " AND $col IN (SELECT a1.user_id FROM group_members a1 WHERE a1.group_id IN (" . DB::in(self::$aud['gids']) . '))'; $params = array_merge($params, self::$aud['gids']); }
        return ['sql' => $sql, 'params' => $params];
    }

    /**
     * Report audience filter applied to course rows (alias of the courses table):
     * a course is listed for a segment / group only when it is defined for it (or for everyone),
     * or when someone from that audience is actually enrolled — so wrong enrollments still show up.
     */
    public static function courseAudienceSql(string $c = 'c'): array
    {
        $params = [];
        if (self::$aud['seg'] === '' && !self::$aud['gids']) return ['sql' => '', 'cond' => '', 'params' => []];
        $enr = self::audienceSql('ea.user_id');
        $has = "EXISTS (SELECT 1 FROM enrollments ea WHERE ea.course_id = $c.id" . $enr['sql'] . ')';
        // audience of the course (course_audience): none = everyone
        $own = [];
        if (self::$aud['seg'] !== '') {
            $own[] = "(NOT EXISTS (SELECT 1 FROM course_audience s0 WHERE s0.course_id = $c.id) OR EXISTS (SELECT 1 FROM course_audience s1 JOIN `groups` sg ON sg.id = s1.group_id WHERE s1.course_id = $c.id AND sg.segment = ?))";
            $params[] = self::$aud['seg'];
        }
        if (self::$aud['gids']) {
            // the chosen group, its sub-groups and its parents (a course for «کارمندان» is also for «واحد A» of کارمندان)
            $g = array_values(array_unique(array_merge(self::$aud['gids'], CourseAudience::ancestors((int)self::$aud['group']))));
            $own[] = "(NOT EXISTS (SELECT 1 FROM course_audience g0 WHERE g0.course_id = $c.id) OR EXISTS (SELECT 1 FROM course_audience g1 WHERE g1.course_id = $c.id AND g1.group_id IN (" . DB::in($g) . ')))';
            $params = array_merge($params, $g);
        }
        $cond = '((' . implode(' AND ', $own) . ") OR $has)";
        return ['sql' => ' AND ' . $cond, 'cond' => $cond, 'params' => array_merge($params, $enr['params'])];
    }

    /** Keep only the user ids that match the report audience filter */
    public static function filterAudience(array $ids): array
    {
        if (!$ids || (self::$aud['seg'] === '' && !self::$aud['gids'])) return $ids;
        $a = self::audienceSql('u.id');
        return array_map('intval', DB::column('SELECT u.id FROM users u WHERE u.id IN (' . DB::in($ids) . ')' . $a['sql'], array_merge($ids, $a['params'])));
    }

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

    /**
     * SQL fragment restricting a user id column. Returns ['sql' => ' AND ...', 'params' => [...]]
     * The general admin (root) never appears in lists, counts or reports. $withRoot = true (user management)
     * lets a root viewer see root accounts — nobody else ever does.
     */
    public static function userSql(string $col = 'u.id', bool $withRoot = false): array
    {
        $noRoot = ($withRoot && Gate::isRoot()) ? '' : " AND $col NOT IN (SELECT r0.id FROM users r0 WHERE r0.is_root = 1)";
        $a = self::audienceSql($col);
        $ids = self::userIds();
        if ($ids === null) return ['sql' => $noRoot . $a['sql'], 'params' => $a['params']];
        if (!$ids) return ['sql' => ' AND 1 = 0', 'params' => []];
        return ['sql' => " AND $col IN (" . DB::in($ids) . ')' . $noRoot . $a['sql'], 'params' => array_merge($ids, $a['params'])];
    }

    /** Is this user a root account hidden from the current viewer? */
    public static function hiddenRoot(int $userId): bool
    {
        if ($userId === (int)Auth::id() || Gate::isRoot()) return false;
        static $roots = null;
        $roots ??= array_map('intval', DB::column('SELECT id FROM users WHERE is_root = 1'));
        return in_array($userId, $roots, true);
    }

    public static function canSeeUser(int $userId): bool
    {
        if (self::hiddenRoot($userId)) return false;
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
