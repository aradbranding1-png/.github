<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Core\Jalali;
use App\Core\Labels;

/**
 * Management report of a course or a single lesson: who took part in a period (viewed / completed a lesson),
 * who did not, split by segment (merchant / employee / agent), searchable per person.
 *
 * «Took part» = a lesson_view or lesson_complete event in activity_logs inside the period.
 * «Did not take part» = the audience (enrolled learners of the course, or every active user) minus the participants.
 */
final class Participation
{
    public const PRESETS = [
        'today' => 'امروز', 'yesterday' => 'دیروز', 'week' => 'این هفته', 'last7' => '۷ روز اخیر',
        'month' => 'این ماه', 'prev_month' => 'ماه قبل', 'last30' => '۳۰ روز اخیر', 'year' => 'امسال', 'all' => 'از ابتدا',
    ];

    /**
     * Period from the query string.
     * @return array{key:string, from:string, to:string, label:string, from_j:string, to_j:string}  to is exclusive
     */
    public static function range(array $q): array
    {
        $key = (string)($q['period'] ?? 'month');
        $today = strtotime(date('Y-m-d'));
        $day = 86400;
        [$jy, $jm] = Jalali::toJalali((int)date('Y', $today), (int)date('n', $today), (int)date('j', $today));
        $monthStart = fn(int $y, int $m): int => strtotime(vsprintf('%04d-%02d-%02d', Jalali::toGregorian($y, $m, 1)));
        $from = $to = null; $label = self::PRESETS[$key] ?? '';

        if (preg_match('/^m(\d{4})-(\d{1,2})$/', $key, $mm) && (int)$mm[2] >= 1 && (int)$mm[2] <= 12) {
            $from = $monthStart((int)$mm[1], (int)$mm[2]);
            $to = $from + Jalali::monthLength((int)$mm[1], (int)$mm[2]) * $day;
            $label = Jalali::format('F Y', $from);
        } elseif ($key === 'custom') {
            $f = Jalali::parse((string)($q['from'] ?? ''));
            $t = Jalali::parse((string)($q['to'] ?? ''));
            if ($f || $t) {
                $from = $f ? strtotime($f) : strtotime('2000-01-01');
                $to = ($t ? strtotime($t) : $today) + $day;
                if ($to <= $from) [$from, $to] = [$to - $day, $from + $day];
                $label = 'از ' . ($f ? Jalali::format('Y/m/d', $from) : 'ابتدا') . ' تا ' . Jalali::format('Y/m/d', $to - $day);
            } else {
                $key = 'month';
            }
        }
        if ($from === null) {
            switch ($key) {
                case 'today': $from = $today; $to = $today + $day; break;
                case 'yesterday': $from = $today - $day; $to = $today; break;
                case 'week': $from = $today - ((int)date('w', $today) + 1) % 7 * $day; $to = $today + $day; break; // week starts on Saturday
                case 'last7': $from = $today - 6 * $day; $to = $today + $day; break;
                case 'last30': $from = $today - 29 * $day; $to = $today + $day; break;
                case 'prev_month':
                    [$py, $pm] = $jm === 1 ? [$jy - 1, 12] : [$jy, $jm - 1];
                    $from = $monthStart($py, $pm); $to = $monthStart($jy, $jm); break;
                case 'year': $from = $monthStart($jy, 1); $to = $today + $day; break;
                case 'all': $from = strtotime('2000-01-01'); $to = $today + $day; break;
                default: $key = 'month'; $from = $monthStart($jy, $jm); $to = $today + $day;
            }
            $label = self::PRESETS[$key];
        }
        return [
            'key' => $key, 'from' => date('Y-m-d H:i:s', $from), 'to' => date('Y-m-d H:i:s', $to), 'label' => $label,
            'from_j' => $key === 'all' ? '' : Jalali::format('Y/m/d', $from), 'to_j' => Jalali::format('Y/m/d', $to - $day),
        ];
    }

    /** The last 12 Jalali months for the month picker: key => «مهر 1405» */
    public static function months(): array
    {
        $t = time();
        [$y, $m] = Jalali::toJalali((int)date('Y', $t), (int)date('n', $t), (int)date('j', $t));
        $out = [];
        for ($i = 0; $i < 12; $i++) {
            $out["m$y-$m"] = Jalali::MONTHS[$m] . ' ' . $y;
            if (--$m === 0) { $m = 12; $y--; }
        }
        return $out;
    }

    public const EXAM_STATES = ['took' => 'شرکت کردند', 'passed' => 'قبول شدند', 'failed' => 'قبول نشدند', 'pending' => 'در انتظار تصحیح', 'none' => 'شرکت نکردند'];
    public const EX_STATES = ['sent' => 'ارسال کردند', 'accepted' => 'تأیید شد', 'pending' => 'در انتظار بررسی', 'rejected' => 'رد شد / نیازمند اصلاح', 'none' => 'ارسال نکردند'];

    /** «exam-113-failed» / «ex-2-rejected» → [type, id, state, title] when the item belongs to the lesson */
    public static function parseTask(string $t, int $lessonId): ?array
    {
        if (!preg_match('/^(exam|ex)-(\d+)-([a-z]+)$/', $t, $m)) return null;
        $states = $m[1] === 'exam' ? self::EXAM_STATES : self::EX_STATES;
        if (!isset($states[$m[3]])) return null;
        $title = DB::value('SELECT title FROM ' . ($m[1] === 'exam' ? 'exams' : 'exercises') . ' WHERE id = ? AND lesson_id = ? AND deleted_at IS NULL', [(int)$m[2], $lessonId]);
        if ($title === null || $title === false) return null;
        return ['type' => $m[1], 'id' => (int)$m[2], 'state' => $m[3], 'title' => (string)$title, 'label' => $states[$m[3]]];
    }

    /** SQL condition: user $u is in state $state for exam / exercise $id */
    public static function taskCond(string $type, int $id, string $state, string $u = 'u.id'): string
    {
        if ($type === 'exam') {
            $att = fn(string $c) => "EXISTS (SELECT 1 FROM exam_attempts t WHERE t.exam_id = $id AND t.user_id = $u AND $c)";
            $took = $att("t.status IN ('submitted','graded','pending_review')");
            $passed = $att('t.passed = 1');
            $pend = $att("t.status = 'pending_review'");
            return match ($state) {
                'took' => $took, 'passed' => $passed, 'none' => "NOT $took",
                'pending' => "($pend AND NOT $passed)",
                default => "($took AND NOT $passed AND NOT $pend)", // failed
            };
        }
        $last = "(SELECT s.status FROM exercise_submissions s WHERE s.exercise_id = $id AND s.user_id = $u ORDER BY s.id DESC LIMIT 1)";
        return match ($state) {
            'sent' => "$last IS NOT NULL", 'accepted' => "$last = 'accepted'", 'none' => "$last IS NULL",
            'rejected' => "$last IN ('needs_revision','rejected')",
            default => "$last NOT IN ('accepted','needs_revision','rejected')", // pending review
        };
    }

    /**
     * Exams and exercises of a lesson with how many enrolled learners are in each state (current state).
     * @return array{exams: array, exercises: array}
     */
    public static function lessonAssess(int $courseId, int $lessonId, string $segment = ''): array
    {
        $scope = Scope::userSql('u.id');
        $base = "FROM enrollments e0 JOIN users u ON u.id = e0.user_id WHERE e0.course_id = ? AND u.deleted_at IS NULL" . ($segment ? ' AND u.segment = ?' : '') . $scope['sql'];
        $bp = array_merge([$courseId], $segment ? [$segment] : [], $scope['params']);
        $out = ['exams' => [], 'exercises' => []];
        foreach (DB::all("SELECT id, title, is_required, pass_score FROM exams WHERE lesson_id = ? AND status = 'published' AND deleted_at IS NULL ORDER BY id", [$lessonId]) as $x) {
            $cols = [];
            foreach (array_keys(self::EXAM_STATES) as $st) $cols[] = 'SUM(CASE WHEN ' . self::taskCond('exam', (int)$x['id'], $st) . " THEN 1 ELSE 0 END) AS `$st`";
            $row = DB::one('SELECT ' . implode(', ', $cols) . ", (SELECT COUNT(*) FROM exam_attempts t JOIN users u ON u.id = t.user_id WHERE t.exam_id = " . (int)$x['id'] . " AND t.status IN ('submitted','graded','pending_review')" . ($segment ? ' AND u.segment = ' . DB::pdo()->quote($segment) : '') . ") AS attempts $base", $bp) ?: [];
            $out['exams'][] = $x + array_map('intval', $row);
        }
        foreach (DB::all("SELECT id, title, is_required FROM exercises WHERE lesson_id = ? AND status = 'published' AND deleted_at IS NULL ORDER BY id", [$lessonId]) as $x) {
            $cols = [];
            foreach (array_keys(self::EX_STATES) as $st) $cols[] = 'SUM(CASE WHEN ' . self::taskCond('ex', (int)$x['id'], $st) . " THEN 1 ELSE 0 END) AS `$st`";
            $row = DB::one('SELECT ' . implode(', ', $cols) . " $base", $bp) ?: [];
            $out['exercises'][] = $x + array_map('intval', $row);
        }
        return $out;
    }

    /** Per person: best score / attempts / passed for each exam, latest status / sends for each exercise */
    public static function taskRows(array $userIds, array $assess): array
    {
        $userIds = array_values(array_unique(array_map('intval', $userIds)));
        if (!$userIds) return [];
        $out = [];
        $examIds = array_map(fn($x) => (int)$x['id'], $assess['exams']);
        if ($examIds) {
            foreach (DB::all("SELECT user_id, exam_id, COUNT(*) n, MAX(percent) best, MAX(passed) passed, SUM(status = 'pending_review') pending
                                FROM exam_attempts WHERE status IN ('submitted','graded','pending_review') AND exam_id IN (" . DB::in($examIds) . ') AND user_id IN (' . DB::in($userIds) . ') GROUP BY user_id, exam_id',
                             array_merge($examIds, $userIds)) as $r) $out[(int)$r['user_id']]['exam' . $r['exam_id']] = $r;
        }
        $exIds = array_map(fn($x) => (int)$x['id'], $assess['exercises']);
        if ($exIds) {
            foreach (DB::all('SELECT s.user_id, s.exercise_id, s.status, t.n FROM exercise_submissions s JOIN (SELECT user_id, exercise_id, MAX(id) mid, COUNT(*) n FROM exercise_submissions
                                WHERE exercise_id IN (' . DB::in($exIds) . ') AND user_id IN (' . DB::in($userIds) . ') GROUP BY user_id, exercise_id) t ON t.mid = s.id',
                             array_merge($exIds, $userIds)) as $r) $out[(int)$r['user_id']]['ex' . $r['exercise_id']] = $r;
        }
        return $out;
    }

    /**
     * A completion recorded in the period only counts if it is still valid today: the lesson is still
     * completed (not cancelled as suspicious), and its required exam is passed / exercise not rejected.
     */
    public static function stillCompleteSql(string $u, string $l): string
    {
        $flags = self::flagsReady() ? " AND NOT EXISTS (SELECT 1 FROM lesson_completion_flags cf WHERE cf.user_id = $u AND cf.lesson_id = $l AND cf.status IN ('open','reset'))" : '';
        return "(EXISTS (SELECT 1 FROM lesson_progress vp WHERE vp.user_id = $u AND vp.lesson_id = $l AND vp.status = 'completed')$flags
            AND NOT EXISTS (SELECT 1 FROM exams vx WHERE vx.lesson_id = $l AND vx.is_required = 1 AND vx.status = 'published' AND vx.deleted_at IS NULL
                            AND NOT EXISTS (SELECT 1 FROM exam_attempts vt WHERE vt.exam_id = vx.id AND vt.user_id = $u AND (vt.passed = 1 OR vt.status = 'pending_review')))
            AND NOT EXISTS (SELECT 1 FROM exercises vy WHERE vy.lesson_id = $l AND vy.is_required = 1 AND vy.status = 'published' AND vy.deleted_at IS NULL
                            AND COALESCE((SELECT vs.status FROM exercise_submissions vs WHERE vs.exercise_id = vy.id AND vs.user_id = $u ORDER BY vs.id DESC LIMIT 1), 'none') IN ('none','needs_revision','rejected')))";
    }

    private static function flagsReady(): bool
    {
        static $ok = null;
        if ($ok === null) { try { DB::value('SELECT 1 FROM lesson_completion_flags LIMIT 1'); $ok = true; } catch (\Throwable) { $ok = false; } }
        return $ok;
    }

    /**
     * Full report.
     * @param int[] $lessonIds  lessons the report covers (one for a lesson report, all of the course otherwise)
     * @param array $opt  segment, q (search), tab (in|out), audience (enrolled|all)
     */
    public static function build(int $courseId, array $lessonIds, ?int $lessonId, array $range, array $opt): array
    {
        $segment = isset(Labels::SEGMENT[$opt['segment'] ?? '']) ? (string)$opt['segment'] : '';
        $audience = ($opt['audience'] ?? '') === 'all' ? 'all' : 'enrolled';
        $q = trim((string)($opt['q'] ?? ''));
        $scope = Scope::userSql('u.id');

        if (!$lessonIds) $lessonIds = [0];
        $in = DB::in($lessonIds);
        // participants in the period (sub-query, reused everywhere)
        $ok = self::stillCompleteSql('activity_logs.user_id', 'activity_logs.ref_id');
        $partSql = "SELECT user_id, SUM(type = 'lesson_view') views, SUM(type = 'lesson_complete' AND $ok) completes,
                           COUNT(DISTINCT CASE WHEN type = 'lesson_view' THEN ref_id END) lessons, MIN(created_at) first_at, MAX(created_at) last_at
                      FROM activity_logs
                     WHERE type IN ('lesson_view','lesson_complete') AND ref_type = 'lesson' AND ref_id IN ($in) AND created_at >= ? AND created_at < ?
                     GROUP BY user_id";
        $partParams = array_merge($lessonIds, [$range['from'], $range['to']]);

        // audience: enrolled learners of the course, or every active user
        $audSql = $audience === 'all'
            ? "SELECT u.id FROM users u WHERE u.deleted_at IS NULL AND u.status = 'active'"
            : 'SELECT e.user_id AS id FROM enrollments e JOIN users u ON u.id = e.user_id WHERE e.course_id = ? AND u.deleted_at IS NULL';
        $audParams = $audience === 'all' ? [] : [$courseId];

        // ---- counters per segment
        $segIn = DB::pairs("SELECT u.segment, COUNT(*) FROM ($partSql) a JOIN users u ON u.id = a.user_id WHERE u.deleted_at IS NULL" . $scope['sql'] . ' GROUP BY u.segment', array_merge($partParams, $scope['params']));
        $segAud = DB::pairs("SELECT u.segment, COUNT(*) FROM ($audSql) x JOIN users u ON u.id = x.id WHERE 1 = 1" . $scope['sql'] . ' GROUP BY u.segment', array_merge($audParams, $scope['params']));
        $segOut = DB::pairs("SELECT u.segment, COUNT(*) FROM ($audSql) x JOIN users u ON u.id = x.id
                              WHERE x.id NOT IN (SELECT user_id FROM ($partSql) p)" . $scope['sql'] . ' GROUP BY u.segment', array_merge($audParams, $partParams, $scope['params']));
        $tot = DB::one("SELECT COALESCE(SUM(a.views),0) views, COALESCE(SUM(a.completes > 0),0) completed FROM ($partSql) a JOIN users u ON u.id = a.user_id WHERE u.deleted_at IS NULL" . $scope['sql'] . ($segment ? ' AND u.segment = ?' : ''), array_merge($partParams, $scope['params'], $segment ? [$segment] : []));

        // ---- people list
        $w = ''; $wp = [];
        if ($segment) { $w .= ' AND u.segment = ?'; $wp[] = $segment; }
        if ($q !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], normalize_input($q)) . '%';
            $mob = canonical_mobile(normalize_input($q));
            $w .= " AND (CONCAT(u.first_name, ' ', u.last_name) LIKE ? OR u.mobile LIKE ? OR u.username LIKE ? OR u.email LIKE ?" . ($mob !== '' ? ' OR u.mobile LIKE ?' : '') . ')';
            array_push($wp, $like, $like, $like, $like);
            if ($mob !== '') $wp[] = '%' . ltrim($mob, '0') . '%';
        }
        $w .= $scope['sql']; $wp = array_merge($wp, $scope['params']);
        $progressJoin = $lessonId
            ? 'LEFT JOIN lesson_progress lp ON lp.user_id = u.id AND lp.lesson_id = ' . (int)$lessonId
            : 'LEFT JOIN (SELECT user_id, SUM(time_spent_sec) time_spent_sec FROM lesson_progress WHERE course_id = ' . (int)$courseId . ' GROUP BY user_id) lp ON lp.user_id = u.id';
        $progressCols = $lessonId ? 'lp.status AS lp_status, lp.percent AS lp_percent, lp.time_spent_sec, lp.watched_sec AS lp_watched, ' . self::stillCompleteSql('u.id', (string)(int)$lessonId) . ' AS still_ok' : 'NULL AS lp_status, NULL AS lp_percent, lp.time_spent_sec, NULL AS lp_watched, 1 AS still_ok';
        $flagCol = $lessonId
            ? "(SELECT f.reason FROM lesson_completion_flags f WHERE f.user_id = u.id AND f.lesson_id = " . (int)$lessonId . " AND f.status IN ('open','reset') LIMIT 1) AS flag_reason"
            : "(SELECT COUNT(*) FROM lesson_completion_flags f WHERE f.user_id = u.id AND f.course_id = " . (int)$courseId . " AND f.status IN ('open','reset')) AS flag_count";
        if (!self::flagsReady()) $flagCol = $lessonId ? 'NULL AS flag_reason' : '0 AS flag_count';
        $cols = "u.id AS user_id, u.first_name, u.last_name, u.mobile, u.segment, u.is_root, u.last_activity_at,
                 en.status AS en_status, en.progress_pct, en.started_at AS en_started_at, $progressCols, $flagCol";
        $enJoin = 'LEFT JOIN enrollments en ON en.user_id = u.id AND en.course_id = ' . (int)$courseId;

        $tab = in_array($opt['tab'] ?? '', ['out', 'done', 'task'], true) ? $opt['tab'] : 'in';
        $task = null;
        if ($tab === 'task') {
            $task = $lessonId ? self::parseTask((string)($opt['task'] ?? ''), $lessonId) : null;
            if (!$task) $tab = 'in';
        }
        if ($tab === 'task') {
            // enrolled learners of the course in the chosen exam / exercise state (current state, not limited to the period)
            $sql = "SELECT $cols, NULL AS views, NULL AS completes, NULL AS lessons, NULL AS first_at, NULL AS last_at
                      FROM enrollments ex0 JOIN users u ON u.id = ex0.user_id $enJoin $progressJoin
                     WHERE ex0.course_id = ? AND u.deleted_at IS NULL AND " . self::taskCond($task['type'], $task['id'], $task['state']) . "$w
                     ORDER BY u.first_name, u.last_name";
            $params = array_merge([$courseId], $wp);
        } elseif ($tab !== 'out') {
            // «done» = took part and still has a valid completion in the period (same rule as the counter)
            $sql = "SELECT $cols, a.views, a.completes, a.lessons, a.first_at, a.last_at
                      FROM ($partSql) a JOIN users u ON u.id = a.user_id $enJoin $progressJoin
                     WHERE u.deleted_at IS NULL" . ($tab === 'done' ? ' AND a.completes > 0' : '') . "$w ORDER BY a.last_at DESC";
            $params = array_merge($partParams, $wp);
        } else {
            $sql = "SELECT $cols, NULL AS views, NULL AS completes, NULL AS lessons, NULL AS first_at, NULL AS last_at
                      FROM ($audSql) x JOIN users u ON u.id = x.id $enJoin $progressJoin
                     WHERE x.id NOT IN (SELECT user_id FROM ($partSql) p)$w ORDER BY u.last_activity_at IS NULL, u.last_activity_at DESC, u.id";
            $params = array_merge($audParams, $partParams, $wp);
        }

        // ---- daily trend (unique people per day), only for short periods
        $days = (int)round((strtotime($range['to']) - strtotime($range['from'])) / 86400);
        $daily = [];
        if ($days > 1 && $days <= 62) {
            $rows = DB::pairs("SELECT DATE(a.created_at) d, COUNT(DISTINCT a.user_id) FROM activity_logs a JOIN users u ON u.id = a.user_id
                                WHERE a.type IN ('lesson_view','lesson_complete') AND a.ref_type = 'lesson' AND a.ref_id IN ($in) AND a.created_at >= ? AND a.created_at < ?"
                                . ($segment ? ' AND u.segment = ?' : '') . $scope['sql'] . ' GROUP BY DATE(a.created_at)',
                array_merge($partParams, $segment ? [$segment] : [], $scope['params']));
            for ($t = strtotime($range['from']); $t < strtotime($range['to']); $t += 86400) $daily[date('Y-m-d', $t)] = (int)($rows[date('Y-m-d', $t)] ?? 0);
        }

        // ---- per-lesson breakdown for a course report
        $perLesson = [];
        if (!$lessonId) {
            $rows = DB::all("SELECT a.ref_id, COUNT(DISTINCT a.user_id) people, SUM(a.type = 'lesson_view') views, COUNT(DISTINCT CASE WHEN a.type = 'lesson_complete' AND " . self::stillCompleteSql('a.user_id', 'a.ref_id') . " THEN a.user_id END) completed
                               FROM activity_logs a JOIN users u ON u.id = a.user_id
                              WHERE a.type IN ('lesson_view','lesson_complete') AND a.ref_type = 'lesson' AND a.ref_id IN ($in) AND a.created_at >= ? AND a.created_at < ?"
                              . ($segment ? ' AND u.segment = ?' : '') . $scope['sql'] . ' GROUP BY a.ref_id',
                array_merge($partParams, $segment ? [$segment] : [], $scope['params']));
            foreach ($rows as $r) $perLesson[(int)$r['ref_id']] = $r;
        }

        $sum = fn(array $a) => $segment ? (int)($a[$segment] ?? 0) : array_sum(array_map('intval', $a));
        return [
            'sql' => $sql, 'params' => $params, 'tab' => $tab, 'task' => $task, 'segment' => $segment, 'audience' => $audience, 'q' => $q,
            'seg_in' => array_map('intval', $segIn), 'seg_out' => array_map('intval', $segOut), 'seg_aud' => array_map('intval', $segAud),
            'n_in' => $sum($segIn), 'n_out' => $sum($segOut), 'n_aud' => $sum($segAud),
            'views' => (int)$tot['views'], 'completed' => (int)$tot['completed'], 'daily' => $daily, 'per_lesson' => $perLesson,
        ];
    }
}
