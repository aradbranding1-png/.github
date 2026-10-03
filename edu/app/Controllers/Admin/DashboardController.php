<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\DB;
use App\Core\Jalali;
use App\Services\Scope;

/** Graphical management dashboard */
final class DashboardController
{
    public function index(): string
    {
        $sc = Scope::userSql('u.id');
        $scE = Scope::userSql('e.user_id');
        $scD = Scope::userSql('d.user_id');
        $inact = (int)setting('inactivity_days', 14);

        $k = [];
        $k['users'] = (int)DB::value("SELECT COUNT(*) FROM users u WHERE u.deleted_at IS NULL AND u.status = 'active'" . $sc['sql'], $sc['params']);
        $k['active30'] = (int)DB::value('SELECT COUNT(DISTINCT d.user_id) FROM user_daily_activity d WHERE d.day >= ?' . $scD['sql'], array_merge([date('Y-m-d', strtotime('-30 days'))], $scD['params']));
        $k['active_today'] = (int)DB::value('SELECT COUNT(DISTINCT d.user_id) FROM user_daily_activity d WHERE d.day = ?' . $scD['sql'], array_merge([today()], $scD['params']));
        $k['courses'] = (int)DB::value("SELECT COUNT(*) FROM courses WHERE deleted_at IS NULL AND status = 'published'");
        $k['courses_all'] = (int)DB::value('SELECT COUNT(*) FROM courses WHERE deleted_at IS NULL');
        $k['enrollments'] = (int)DB::value('SELECT COUNT(*) FROM enrollments e WHERE 1=1' . $scE['sql'], $scE['params']);
        $k['completed'] = (int)DB::value("SELECT COUNT(*) FROM enrollments e WHERE e.status = 'completed'" . $scE['sql'], $scE['params']);
        $k['incomplete'] = $k['enrollments'] - $k['completed'];
        $k['exams'] = (int)DB::value("SELECT COUNT(*) FROM exam_attempts e WHERE e.status <> 'in_progress'" . $scE['sql'], $scE['params']);
        $k['avg_score'] = DB::value("SELECT AVG(e.percent) FROM exam_attempts e WHERE e.status = 'graded'" . $scE['sql'], $scE['params']);
        $k['overdue'] = (int)DB::value("SELECT COUNT(*) FROM enrollments e WHERE e.status NOT IN ('completed') AND ((e.due_at IS NOT NULL AND e.due_at < NOW()) OR e.status = 'expired')" . $scE['sql'], $scE['params']);
        $k['inactive'] = (int)DB::value("SELECT COUNT(*) FROM users u WHERE u.deleted_at IS NULL AND u.status = 'active' AND (u.last_login_at IS NULL OR u.last_login_at < ?)" . $sc['sql'], array_merge([date('Y-m-d H:i:s', strtotime("-$inact days"))], $sc['params']));
        $k['pending_reviews'] = (int)DB::value("SELECT (SELECT COUNT(*) FROM exercise_submissions WHERE status = 'submitted') + (SELECT COUNT(*) FROM exam_attempts WHERE status = 'pending_review')");
        $k['completion_rate'] = $k['enrollments'] ? round($k['completed'] * 100 / $k['enrollments']) : 0;

        // 30-day activity trend
        $days = [];
        for ($i = 29; $i >= 0; $i--) $days[date('Y-m-d', strtotime("-$i days"))] = ['a' => 0, 'l' => 0, 'c' => 0];
        foreach (DB::all('SELECT d.day, COUNT(DISTINCT d.user_id) a, SUM(d.learning_events > 0) l, SUM(d.lessons_completed) c FROM user_daily_activity d WHERE d.day >= ?' . $scD['sql'] . ' GROUP BY d.day', array_merge([array_key_first($days)], $scD['params'])) as $r) {
            $days[$r['day']] = ['a' => (int)$r['a'], 'l' => (int)$r['l'], 'c' => (int)$r['c']];
        }
        $labels = array_map(fn($d) => fa(Jalali::format('m/d', strtotime($d))), array_keys($days));
        $trend = ['type' => 'line', 'labels' => $labels, 'series' => [
            ['name' => 'کاربران فعال', 'data' => array_column($days, 'a'), 'color' => '#0ea5e9'],
            ['name' => 'فعالیت آموزشی', 'data' => array_column($days, 'l'), 'color' => '#6366f1'],
        ]];
        // completions per week (12 weeks)
        $weeks = []; $wl = [];
        for ($i = 11; $i >= 0; $i--) {
            $s = date('Y-m-d', strtotime('-' . ($i * 7 + 6) . ' days')); $e = date('Y-m-d', strtotime('-' . ($i * 7) . ' days'));
            $weeks[] = (int)DB::value("SELECT COUNT(*) FROM enrollments e WHERE e.status = 'completed' AND DATE(e.completed_at) BETWEEN ? AND ?" . $scE['sql'], array_merge([$s, $e], $scE['params']));
            $wl[] = fa(Jalali::format('m/d', strtotime($e)));
        }
        $completions = ['type' => 'bar', 'labels' => $wl, 'series' => [['name' => 'دوره تکمیل‌شده', 'data' => $weeks, 'color' => '#10b981']]];

        $seg = DB::pairs("SELECT u.segment, COUNT(*) FROM users u WHERE u.deleted_at IS NULL AND u.status = 'active'" . $sc['sql'] . ' GROUP BY u.segment', $sc['params']);
        $segChart = ['type' => 'donut', 'labels' => array_map(fn($s) => label('segment', $s), array_keys($seg)), 'values' => array_values(array_map('intval', $seg)), 'colors' => array_map(fn($s) => ['merchant' => '#6366f1', 'employee' => '#0ea5e9', 'agent' => '#f59e0b'][$s] ?? '#94a3b8', array_keys($seg)), 'center' => 'کاربر'];
        $st = DB::pairs('SELECT e.status, COUNT(*) FROM enrollments e WHERE 1=1' . $scE['sql'] . ' GROUP BY e.status', $scE['params']);
        $tone = ['completed' => '#10b981', 'in_progress' => '#6366f1', 'not_started' => '#94a3b8', 'needs_retake' => '#f59e0b', 'failed' => '#ef4444', 'locked' => '#475569', 'expired' => '#dc2626'];
        $stChart = ['type' => 'donut', 'labels' => array_map(fn($s) => label('status', $s), array_keys($st)), 'values' => array_values(array_map('intval', $st)), 'colors' => array_map(fn($s) => $tone[$s] ?? '#94a3b8', array_keys($st)), 'center' => 'ثبت‌نام'];

        $topCourses = DB::all("SELECT c.id, c.title, COUNT(e.id) n, SUM(e.status = 'completed') done, AVG(e.progress_pct) prog FROM courses c JOIN enrollments e ON e.course_id = c.id WHERE c.deleted_at IS NULL" . $scE['sql'] . ' GROUP BY c.id, c.title ORDER BY n DESC LIMIT 6', $scE['params']);
        $laggards = DB::all("SELECT e.*, u.id AS uid, u.first_name, u.last_name, u.avatar_path, u.is_root, c.title FROM enrollments e JOIN users u ON u.id = e.user_id JOIN courses c ON c.id = e.course_id
                              WHERE e.training_type = 'mandatory' AND e.status NOT IN ('completed') AND u.deleted_at IS NULL AND ((e.due_at IS NOT NULL AND e.due_at < DATE_ADD(NOW(), INTERVAL 3 DAY)) OR e.status IN ('expired','failed'))" . $scE['sql'] . ' ORDER BY e.due_at LIMIT 8', $scE['params']);
        $inactiveUsers = DB::all("SELECT u.* FROM users u WHERE u.deleted_at IS NULL AND u.status = 'active' AND (u.last_login_at IS NULL OR u.last_login_at < ?)" . $sc['sql'] . ' ORDER BY u.last_login_at IS NULL, u.last_login_at LIMIT 8', array_merge([date('Y-m-d H:i:s', strtotime("-$inact days"))], $sc['params']));
        $groups = DB::all("SELECT g.id, g.name, g.color, g.icon, COUNT(DISTINCT gm.user_id) members, (SELECT ROUND(AVG(e.progress_pct)) FROM enrollments e JOIN group_members x ON x.user_id = e.user_id WHERE x.group_id = g.id) prog FROM `groups` g LEFT JOIN group_members gm ON gm.group_id = g.id WHERE g.parent_id IS NULL GROUP BY g.id, g.name, g.color, g.icon ORDER BY g.sort LIMIT 6");
        $audit = can('audit.view') ? DB::all('SELECT a.*, u.first_name, u.last_name FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id ORDER BY a.id DESC LIMIT 8') : [];

        return view('admin/dashboard', compact('k', 'trend', 'completions', 'segChart', 'stChart', 'topCourses', 'laggards', 'inactiveUsers', 'groups', 'audit', 'inact') + ['title' => 'داشبورد مدیریتی', 'scope' => Scope::level()]);
    }
}
