<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;

/**
 * Course recommendations based on: role/segment, groups, level, growth stage requirements,
 * training needs (categories) and learning history (categories of completed courses).
 */
final class Recommend
{
    public static function forUser(array $user, int $limit = 6): array
    {
        $uid = (int)$user['id'];
        $groups = array_map('intval', DB::column('SELECT group_id FROM group_members WHERE user_id = ?', [$uid]));
        $levels = array_map('intval', DB::column('SELECT level_id FROM user_levels WHERE user_id = ?', [$uid]));
        $needCats = array_map('intval', DB::column("SELECT category_id FROM training_needs WHERE user_id = ? AND status <> 'resolved' AND category_id IS NOT NULL", [$uid]));
        $histCats = array_map('intval', DB::column("SELECT DISTINCT c.category_id FROM enrollments e JOIN courses c ON c.id = e.course_id WHERE e.user_id = ? AND e.status = 'completed' AND c.category_id IS NOT NULL", [$uid]));
        // courses required by the trader's current growth stage (and reopened earlier stages)
        $stageCourses = [];
        $stageNo = (int)($user['tg_stage'] ?? 0);
        if ($stageNo > 0) {
            $ids = [];
            foreach (TraderGrowth::stages() as $st) if ($st['no'] <= $stageNo) $ids[] = (int)$st['id'];
            if ($ids) $stageCourses = array_map('intval', DB::column("SELECT ref_id FROM tg_items WHERE kind = 'course' AND stage_id IN (" . DB::in($ids) . ') AND (track_id IS NULL OR track_id = ?)', array_merge($ids, [(int)($user['tg_track_id'] ?? 0)])));
        }
        $candidates = DB::all(
            "SELECT c.*, cat.name AS category_name, cat.color AS category_color, cat.icon AS category_icon,
                    (SELECT COUNT(*) FROM enrollments x WHERE x.course_id = c.id) AS popularity
               FROM courses c LEFT JOIN categories cat ON cat.id = c.category_id
              WHERE c.status = 'published' AND c.deleted_at IS NULL AND (c.publish_at IS NULL OR c.publish_at <= NOW()) AND (c.expire_at IS NULL OR c.expire_at > NOW())
                AND (c.target_segment IS NULL OR c.target_segment = ?)
                AND NOT EXISTS (SELECT 1 FROM enrollments e WHERE e.user_id = ? AND e.course_id = c.id)
              LIMIT 300",
            [$user['segment'], $uid]
        );
        foreach ($candidates as &$c) {
            $s = 0; $why = [];
            if (in_array((int)$c['id'], $stageCourses, true)) { $s += 60; $why[] = 'لازمه مرحله فعلی نظام رشد شما'; }
            if ($c['category_id'] && in_array((int)$c['category_id'], $needCats, true)) { $s += 50; $why[] = 'مطابق نیاز آموزشی ثبت‌شده'; }
            if ($c['group_id'] && in_array((int)$c['group_id'], $groups, true)) { $s += 30; $why[] = 'ویژه گروه شما'; }
            if ($c['level_id'] && in_array((int)$c['level_id'], $levels, true)) { $s += 25; $why[] = 'متناسب با سطح شما'; }
            if ($c['target_segment'] === $user['segment']) { $s += 15; if (!$why) $why[] = 'مخصوص ' . label('segment_one', $user['segment']) . '‌ها'; }
            if ($c['category_id'] && in_array((int)$c['category_id'], $histCats, true)) { $s += 10; $why[] = 'ادامه مسیر یادگیری شما'; }
            if ($c['training_type'] === 'suggested') $s += 5;
            $s += min(10, (int)$c['popularity'] / 5);
            $c['_score'] = $s;
            $c['_why'] = $why ? $why[0] : 'دوره محبوب';
        }
        unset($c);
        usort($candidates, fn($a, $b) => $b['_score'] <=> $a['_score']);
        return array_slice($candidates, 0, $limit);
    }
}
