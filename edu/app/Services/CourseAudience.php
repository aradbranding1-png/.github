<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;

/**
 * Who may see / take a course («مخاطبان دوره»):
 *  - course_audience: groups (none = everyone). A user matches a group when he is a member of it or of one of its
 *    sub-groups; for a main group (تاجران / کارمندان / نمایندگان) the user's type (segment) also counts.
 *  - course_levels: levels (none = every level). The user must hold one of them.
 * Both conditions must hold. Users already enrolled keep access to their course.
 */
final class CourseAudience
{
    private static array $userCache = [];

    /** Root and roles with «دوره‌ها — مشاهده همه» see every course, whatever its audience (managers / education staff) */
    public static function seesAll(?array $u = null): bool
    {
        return \App\Core\Gate::allows('courses.viewall', $u);
    }

    /** @return int[] */
    public static function groupIds(int $courseId): array
    {
        return array_map('intval', DB::column('SELECT group_id FROM course_audience WHERE course_id = ?', [$courseId]));
    }

    /** @return int[] */
    public static function levelIds(int $courseId): array
    {
        return array_map('intval', DB::column('SELECT level_id FROM course_levels WHERE course_id = ?', [$courseId]));
    }

    /** Ancestors of a group (excluding itself) */
    public static function ancestors(int $groupId): array
    {
        $out = [];
        $id = $groupId;
        for ($i = 0; $i < 12; $i++) {
            $p = DB::value('SELECT parent_id FROM `groups` WHERE id = ?', [$id]);
            if (!$p || in_array((int)$p, $out, true)) break;
            $out[] = $id = (int)$p;
        }
        return $out;
    }

    /** Levels that may be chosen for these audience groups (levels of the groups and of their sub-groups); [] groups = all levels */
    public static function allowedLevelIds(array $groupIds): ?array
    {
        if (!$groupIds) return null;
        $g = [];
        foreach ($groupIds as $gid) $g = array_merge($g, Targeting::descendants('groups', (int)$gid));
        $g = array_values(array_unique($g));
        return array_map('intval', DB::column('SELECT id FROM levels WHERE group_id IN (' . DB::in($g) . ')', $g));
    }

    /** Save the audience of a course and keep the legacy single columns in step (badges, API, filters) */
    public static function save(int $courseId, array $groupIds, array $levelIds): void
    {
        $groupIds = array_values(array_unique(array_filter(array_map('intval', $groupIds))));
        if ($groupIds) $groupIds = array_map('intval', DB::column('SELECT id FROM `groups` WHERE id IN (' . DB::in($groupIds) . ')', $groupIds));
        $levelIds = array_values(array_unique(array_filter(array_map('intval', $levelIds))));
        $allowed = self::allowedLevelIds($groupIds);
        $levelIds = $levelIds ? array_map('intval', DB::column('SELECT id FROM levels WHERE id IN (' . DB::in($levelIds) . ')', $levelIds)) : [];
        if ($allowed !== null) $levelIds = array_values(array_intersect($levelIds, $allowed));
        DB::delete('course_audience', 'course_id = ?', [$courseId]);
        DB::delete('course_levels', 'course_id = ?', [$courseId]);
        foreach ($groupIds as $g) DB::insert('course_audience', ['course_id' => $courseId, 'group_id' => $g]);
        foreach ($levelIds as $l) DB::insert('course_levels', ['course_id' => $courseId, 'level_id' => $l]);
        // legacy columns: the single segment when every audience group belongs to one segment
        $segs = $groupIds ? array_values(array_unique(DB::column('SELECT segment FROM `groups` WHERE id IN (' . DB::in($groupIds) . ')', $groupIds))) : [];
        $seg = count($segs) === 1 && in_array($segs[0], ['merchant', 'employee', 'agent'], true) ? $segs[0] : null;
        DB::update('courses', ['target_segment' => $seg, 'group_id' => count($groupIds) === 1 ? $groupIds[0] : null, 'level_id' => count($levelIds) === 1 ? $levelIds[0] : null], 'id = ?', [$courseId]);
    }

    /** User context: groups he belongs to (with their ancestors) and his levels */
    private static function userCtx(array $u): array
    {
        $uid = (int)$u['id'];
        if (isset(self::$userCache[$uid])) return self::$userCache[$uid];
        $groups = array_map('intval', DB::column('SELECT group_id FROM group_members WHERE user_id = ?', [$uid]));
        $all = $groups;
        foreach ($groups as $g) $all = array_merge($all, self::ancestors($g));
        $seg = (string)($u['segment'] ?? DB::value('SELECT segment FROM users WHERE id = ?', [$uid]));
        if ($seg !== '') $all = array_merge($all, array_map('intval', DB::column('SELECT id FROM `groups` WHERE segment = ? AND is_system = 1 AND parent_id IS NULL', [$seg])));
        $levels = array_map('intval', DB::column('SELECT level_id FROM user_levels WHERE user_id = ?', [$uid]));
        return self::$userCache[$uid] = ['groups' => array_values(array_unique($all)), 'levels' => $levels];
    }

    /** SQL condition (no leading AND) on a courses alias: the course is meant for this user */
    public static function sqlForUser(array $u, string $c = 'c'): array
    {
        $x = self::userCtx($u);
        $g = $x['groups'] ?: [0];
        $l = $x['levels'] ?: [0];
        return [
            "(NOT EXISTS (SELECT 1 FROM course_audience ca0 WHERE ca0.course_id = $c.id) OR EXISTS (SELECT 1 FROM course_audience ca1 WHERE ca1.course_id = $c.id AND ca1.group_id IN (" . DB::in($g) . ')))'
            . " AND (NOT EXISTS (SELECT 1 FROM course_levels cl0 WHERE cl0.course_id = $c.id) OR EXISTS (SELECT 1 FROM course_levels cl1 WHERE cl1.course_id = $c.id AND cl1.level_id IN (" . DB::in($l) . ')))',
            array_merge($g, $l),
        ];
    }

    public static function allows(array $u, int $courseId): bool
    {
        [$w, $p] = self::sqlForUser($u, 'c');
        return (bool)DB::value("SELECT 1 FROM courses c WHERE c.id = ? AND $w", array_merge([$courseId], $p));
    }

    /** SQL condition (no leading AND) on a users alias: users the course is meant for */
    public static function usersSql(int $courseId, string $u = 'u'): array
    {
        $sql = '1 = 1'; $p = [];
        $groups = self::groupIds($courseId);
        if ($groups) {
            $g = [];
            foreach ($groups as $gid) $g = array_merge($g, Targeting::descendants('groups', $gid));
            $g = array_values(array_unique($g));
            $segs = DB::column('SELECT segment FROM `groups` WHERE id IN (' . DB::in($groups) . ') AND is_system = 1 AND parent_id IS NULL', $groups);
            $sql .= " AND ($u.id IN (SELECT gm.user_id FROM group_members gm WHERE gm.group_id IN (" . DB::in($g) . '))';
            $p = $g;
            if ($segs) { $sql .= " OR $u.segment IN (" . DB::in($segs) . ')'; $p = array_merge($p, $segs); }
            $sql .= ')';
        }
        if ($levels = self::levelIds($courseId)) {
            $sql .= " AND $u.id IN (SELECT ul.user_id FROM user_levels ul WHERE ul.level_id IN (" . DB::in($levels) . '))';
            $p = array_merge($p, $levels);
        }
        return [$sql, $p];
    }

    /** Keep only users the course is meant for */
    public static function filterUsers(int $courseId, array $userIds): array
    {
        if (!$userIds) return [];
        [$w, $p] = self::usersSql($courseId, 'u');
        if ($w === '1 = 1') return array_values(array_map('intval', $userIds));
        return array_map('intval', DB::column('SELECT u.id FROM users u WHERE u.id IN (' . DB::in($userIds) . ") AND $w", array_merge(array_map('intval', $userIds), $p)));
    }

    /** Names for badges, e.g. «تاجران، کارمندان — سطح: طلایی» */
    public static function label(int $courseId): string
    {
        $g = DB::column('SELECT g.name FROM course_audience a JOIN `groups` g ON g.id = a.group_id WHERE a.course_id = ? ORDER BY g.sort, g.name', [$courseId]);
        $l = DB::column('SELECT l.name FROM course_levels a JOIN levels l ON l.id = a.level_id WHERE a.course_id = ? ORDER BY l.rank_no', [$courseId]);
        return ($g ? implode('، ', $g) : 'همه') . ($l ? ' — سطح: ' . implode('، ', $l) : '');
    }
}
