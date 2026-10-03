<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Core\Jalali;

/**
 * Complete Activity & Learning Report for one user:
 * logins, active/inactive days, activity calendar (day / month / year, Jalali), learning stats, courses, exams, exercises, paths, growth.
 */
final class UserReport
{
    public static function build(int $uid, string $view = 'month', ?int $jy = null, ?int $jm = null, ?int $jd = null): array
    {
        $u = DB::find('users', $uid);
        [$ty, $tm, $td] = Jalali::toJalali((int)date('Y'), (int)date('n'), (int)date('j'));
        $jy = $jy ?: $ty; $jm = $jm ?: $tm; $jd = $jd ?: $td;
        $jm = max(1, min(12, $jm));
        $view = in_array($view, ['day', 'month', 'year'], true) ? $view : 'month';

        if ($view === 'year') {
            $from = sprintf('%04d-%02d-%02d', ...Jalali::toGregorian($jy, 1, 1));
            $to = sprintf('%04d-%02d-%02d', ...Jalali::toGregorian($jy, 12, Jalali::monthLength($jy, 12)));
        } elseif ($view === 'day') {
            $from = $to = sprintf('%04d-%02d-%02d', ...Jalali::toGregorian($jy, $jm, min($jd, Jalali::monthLength($jy, $jm))));
        } else {
            $from = sprintf('%04d-%02d-%02d', ...Jalali::toGregorian($jy, $jm, 1));
            $to = sprintf('%04d-%02d-%02d', ...Jalali::toGregorian($jy, $jm, Jalali::monthLength($jy, $jm)));
        }
        $daily = [];
        foreach (DB::all('SELECT * FROM user_daily_activity WHERE user_id = ? AND day BETWEEN ? AND ?', [$uid, $from, $to]) as $r) $daily[$r['day']] = $r;

        // active / inactive days in range (counted from account creation, up to today)
        $start = max($from, substr((string)$u['created_at'], 0, 10));
        $end = min($to, date('Y-m-d'));
        $elapsed = $end >= $start ? (int)((strtotime($end) - strtotime($start)) / 86400) + 1 : 0;
        $activeDays = count(array_filter($daily, fn($d) => ((int)$d['logins'] + (int)$d['learning_events']) > 0 && $d['day'] >= $start && $d['day'] <= $end));
        $learningDays = count(array_filter($daily, fn($d) => (int)$d['learning_events'] > 0));
        $sum = fn(string $k) => array_sum(array_map(fn($d) => (int)$d[$k], $daily));
        $totals = [
            'logins' => $sum('logins'), 'lessons_viewed' => $sum('lessons_viewed'), 'lessons_completed' => $sum('lessons_completed'),
            'courses_completed' => $sum('courses_completed'), 'exams' => $sum('exams'), 'exercises' => $sum('exercises'),
            'content_views' => $sum('content_views'), 'seconds' => $sum('seconds_spent'),
            'active_days' => $activeDays, 'inactive_days' => max(0, $elapsed - $activeDays), 'learning_days' => $learningDays, 'elapsed' => $elapsed,
        ];
        $rangeAvg = DB::value("SELECT AVG(percent) FROM exam_attempts WHERE user_id = ? AND status IN ('graded','submitted') AND DATE(submitted_at) BETWEEN ? AND ?", [$uid, $from, $to]);
        $totals['avg_score'] = $rangeAvg === null ? null : round((float)$rangeAvg, 1);

        $overall = [
            'total_active_days' => (int)DB::value('SELECT COUNT(*) FROM user_daily_activity WHERE user_id = ? AND (logins + learning_events) > 0', [$uid]),
            'total_seconds' => (int)DB::value('SELECT COALESCE(SUM(seconds_spent),0) FROM user_daily_activity WHERE user_id = ?', [$uid]),
            'lessons_viewed' => (int)DB::value('SELECT COUNT(*) FROM lesson_progress WHERE user_id = ?', [$uid]),
            'lessons_completed' => (int)DB::value("SELECT COUNT(*) FROM lesson_progress WHERE user_id = ? AND status = 'completed'", [$uid]),
            'avg_score' => DB::value("SELECT AVG(best) FROM (SELECT MAX(percent) best FROM exam_attempts WHERE user_id = ? AND status IN ('graded','submitted') GROUP BY exam_id) t", [$uid]),
            'avg_progress' => DB::value('SELECT AVG(progress_pct) FROM enrollments WHERE user_id = ?', [$uid]),
            'since_days' => (int)((time() - strtotime((string)$u['created_at'])) / 86400) + 1,
        ];
        $overall['total_inactive_days'] = max(0, $overall['since_days'] - $overall['total_active_days']);

        $enrollments = DB::all('SELECT e.*, c.title FROM enrollments e JOIN courses c ON c.id = e.course_id WHERE e.user_id = ? ORDER BY e.status = \'completed\', e.progress_pct DESC', [$uid]);
        $exams = DB::all("SELECT a.*, x.title FROM exam_attempts a JOIN exams x ON x.id = a.exam_id WHERE a.user_id = ? AND a.status <> 'in_progress' ORDER BY a.id DESC LIMIT 50", [$uid]);
        $exercises = DB::all('SELECT s.*, x.title FROM exercise_submissions s JOIN exercises x ON x.id = s.exercise_id WHERE s.user_id = ? ORDER BY s.id DESC LIMIT 50', [$uid]);
        $paths = DB::all('SELECT pe.*, p.title, (SELECT COUNT(*) FROM path_steps s WHERE s.path_id = p.id) steps FROM path_enrollments pe JOIN learning_paths p ON p.id = pe.path_id WHERE pe.user_id = ?', [$uid]);
        $growth = DB::all('SELECT g.name AS group_name, s.name AS stage_name, s.color, ug.achieved_at FROM user_growth ug JOIN growth_stages s ON s.id = ug.stage_id JOIN `groups` g ON g.id = ug.group_id WHERE ug.user_id = ?', [$uid]);
        $events = $view === 'day' ? DB::all("SELECT a.*, l.title AS lesson_title, c.title AS course_title, x.title AS exam_title FROM activity_logs a LEFT JOIN lessons l ON a.ref_type = 'lesson' AND l.id = a.ref_id LEFT JOIN courses c ON a.ref_type = 'course' AND c.id = a.ref_id LEFT JOIN exams x ON a.ref_type = 'exam' AND x.id = a.ref_id WHERE a.user_id = ? AND a.day = ? ORDER BY a.id", [$uid, $from]) : [];

        // 12-month trend (Jalali months ending at selected month)
        $trend = ['labels' => [], 'active' => [], 'learning' => []];
        for ($i = 11; $i >= 0; $i--) {
            $y = $jy; $m = $jm - $i;
            while ($m < 1) { $m += 12; $y--; }
            $f = sprintf('%04d-%02d-%02d', ...Jalali::toGregorian($y, $m, 1));
            $t = sprintf('%04d-%02d-%02d', ...Jalali::toGregorian($y, $m, Jalali::monthLength($y, $m)));
            $r = DB::one('SELECT SUM((logins + learning_events) > 0) a, SUM(learning_events > 0) l FROM user_daily_activity WHERE user_id = ? AND day BETWEEN ? AND ?', [$uid, $f, $t]);
            $trend['labels'][] = Jalali::MONTHS[$m];
            $trend['active'][] = (int)($r['a'] ?? 0);
            $trend['learning'][] = (int)($r['l'] ?? 0);
        }
        $statusDist = DB::pairs('SELECT status, COUNT(*) FROM enrollments WHERE user_id = ? GROUP BY status', [$uid]);

        return compact('u', 'view', 'jy', 'jm', 'jd', 'from', 'to', 'daily', 'totals', 'overall', 'enrollments', 'exams', 'exercises', 'paths', 'growth', 'events', 'trend', 'statusDist');
    }

    /** CSS class for a calendar day */
    public static function dayClass(?array $d): string
    {
        if (!$d) return 'absent';
        if ((int)$d['lessons_completed'] + (int)$d['courses_completed'] > 0) return 'done';
        if ((int)$d['learning_events'] > 0) return 'learn';
        if ((int)$d['logins'] > 0) return 'login';
        return 'absent';
    }
}
