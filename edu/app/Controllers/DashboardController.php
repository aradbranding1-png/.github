<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\DB;
use App\Services\Growth;
use App\Services\PathService;
use App\Services\Recommend;

/** Learner dashboard (پیشخوان کاربر) */
final class DashboardController
{
    public function home(): string
    {
        $me = Auth::user();
        $uid = (int)$me['id'];
        $en = DB::all("SELECT e.*, c.title, c.image_file_id, c.duration_minutes, cat.color AS cat_color, cat.icon AS cat_icon, cat.name AS cat_name
                         FROM enrollments e JOIN courses c ON c.id = e.course_id LEFT JOIN categories cat ON cat.id = c.category_id
                        WHERE e.user_id = ? AND c.deleted_at IS NULL ORDER BY COALESCE(e.last_activity_at, e.created_at) DESC", [$uid]);
        $stats = ['total' => count($en), 'mandatory' => 0, 'mandatory_open' => 0, 'in_progress' => 0, 'completed' => 0, 'overdue' => 0];
        $sum = 0;
        foreach ($en as $e) {
            $sum += (float)$e['progress_pct'];
            if ($e['training_type'] === 'mandatory') { $stats['mandatory']++; if ($e['status'] !== 'completed') $stats['mandatory_open']++; }
            if (in_array($e['status'], ['in_progress', 'needs_retake'], true)) $stats['in_progress']++;
            if ($e['status'] === 'completed') $stats['completed']++;
            if ($e['status'] === 'expired' || ($e['due_at'] && $e['due_at'] < now() && $e['status'] !== 'completed')) $stats['overdue']++;
        }
        $overall = $en ? round($sum / count($en)) : 0;
        // the whole training library available to this user (same visibility as the catalog)
        [$vis, $vp] = LearnController::visibleCourseSql($me);
        $library = DB::one("SELECT COUNT(DISTINCT c.id) AS courses, COUNT(l.id) AS lessons, COALESCE(SUM(l.duration_minutes), 0) AS minutes
                              FROM courses c LEFT JOIN lessons l ON l.course_id = c.id AND l.deleted_at IS NULL AND l.status = 'published'
                             WHERE $vis", $vp) ?? ['courses' => 0, 'lessons' => 0, 'minutes' => 0];
        $continue = array_values(array_filter($en, fn($e) => in_array($e['status'], ['in_progress', 'not_started', 'needs_retake'], true)));
        $mandatory = array_values(array_filter($en, fn($e) => $e['training_type'] === 'mandatory' && $e['status'] !== 'completed'));
        usort($mandatory, fn($a, $b) => strcmp((string)($a['due_at'] ?? '9999'), (string)($b['due_at'] ?? '9999')));

        $exams = [
            'passed' => (int)DB::value('SELECT COUNT(DISTINCT exam_id) FROM exam_attempts WHERE user_id = ? AND passed = 1', [$uid]),
            'pending' => (int)DB::value("SELECT COUNT(*) FROM exams x JOIN enrollments e ON e.course_id = x.course_id AND e.user_id = ? WHERE x.status = 'published' AND x.deleted_at IS NULL AND e.status NOT IN ('locked','completed') AND NOT EXISTS (SELECT 1 FROM exam_attempts a WHERE a.exam_id = x.id AND a.user_id = ? AND a.passed = 1)", [$uid, $uid]),
            'avg' => DB::value("SELECT AVG(best) FROM (SELECT MAX(percent) best FROM exam_attempts WHERE user_id = ? AND status IN ('submitted','graded') GROUP BY exam_id) t", [$uid]),
        ];
        $exercises = [
            'review' => (int)DB::value("SELECT COUNT(*) FROM exercise_submissions WHERE user_id = ? AND status = 'submitted'", [$uid]),
            'revise' => (int)DB::value("SELECT COUNT(*) FROM exercise_submissions WHERE user_id = ? AND status = 'needs_revision'", [$uid]),
            'accepted' => (int)DB::value("SELECT COUNT(DISTINCT exercise_id) FROM exercise_submissions WHERE user_id = ? AND status = 'accepted'", [$uid]),
        ];
        $certs = (int)DB::value('SELECT COUNT(*) FROM certificates WHERE user_id = ? AND revoked_at IS NULL', [$uid]);

        $pathEn = DB::one("SELECT pe.*, p.title, p.color FROM path_enrollments pe JOIN learning_paths p ON p.id = pe.path_id WHERE pe.user_id = ? AND p.deleted_at IS NULL ORDER BY pe.status = 'completed', pe.id DESC LIMIT 1", [$uid]);
        $pathSteps = $pathEn ? PathService::steps((int)$pathEn['path_id']) : [];

        $growth = \App\Services\TraderGrowth::participates($me) && \App\Services\TraderGrowth::count() ? \App\Services\TraderGrowth::evaluate($uid) : null;

        // last 14 days activity
        $days = [];
        for ($i = 13; $i >= 0; $i--) $days[date('Y-m-d', strtotime("-$i days"))] = 0;
        foreach (DB::all('SELECT day, learning_events + logins AS n FROM user_daily_activity WHERE user_id = ? AND day >= ?', [$uid, array_key_first($days)]) as $r) $days[$r['day']] = (int)$r['n'];
        $chart = ['type' => 'bar', 'labels' => array_map(fn($d) => fa(\App\Core\Jalali::format('m/d', strtotime($d))), array_keys($days)), 'series' => [['name' => 'فعالیت', 'data' => array_values($days), 'color' => '#6366f1']]];

        $recent = DB::all("SELECT a.*, l.title AS lesson_title, c.title AS course_title, x.title AS exam_title FROM activity_logs a
                            LEFT JOIN lessons l ON a.ref_type = 'lesson' AND l.id = a.ref_id
                            LEFT JOIN courses c ON a.ref_type = 'course' AND c.id = a.ref_id
                            LEFT JOIN exams x ON a.ref_type = 'exam' AND x.id = a.ref_id
                           WHERE a.user_id = ? AND a.type <> 'login' ORDER BY a.id DESC LIMIT 8", [$uid]);

        $sessions = \App\Services\EventService::upcomingFor($me, 4);
        return view('learn/home', [
            'sessions' => $sessions, 'library' => $library, 'announced' => \App\Services\EventService::announcements($me, 6),
            'title' => 'پیشخوان من', 'me' => $me, 'stats' => $stats, 'overall' => $overall, 'continue' => array_slice($continue, 0, 4),
            'mandatory' => array_slice($mandatory, 0, 6), 'exams' => $exams, 'exercises' => $exercises, 'certs' => $certs,
            'pathEn' => $pathEn, 'pathSteps' => $pathSteps, 'growth' => $growth, 'chart' => $chart, 'recent' => $recent,
            'recommend' => Recommend::forUser($me, 3),
        ]);
    }
}
