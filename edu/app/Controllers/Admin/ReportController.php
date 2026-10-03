<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\DB;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Xlsx;
use App\Services\Scope;
use App\Services\Targeting;
use App\Services\UserReport;

/** Management reports with Excel export. All reports respect the viewer's data scope. */
final class ReportController
{
    private function exporting(): bool
    {
        return Request::str('export') === '1' && can('reports.export');
    }

    public function index(): string
    {
        return view('admin/reports/index', ['title' => 'گزارش‌ها']);
    }

    public function users(): string
    {
        $q = Request::str('q');
        $rows = [];
        if ($q !== '' || Request::int('role') || Request::int('group')) {
            $w = 'u.deleted_at IS NULL'; $p = [];
            if ($q !== '') { $like = "%$q%"; $w .= ' AND (u.first_name LIKE ? OR u.last_name LIKE ? OR CONCAT(u.first_name, \' \', u.last_name) LIKE ? OR u.mobile LIKE ? OR u.id = ?)'; array_push($p, $like, $like, $like, $like, ctype_digit($q) ? (int)$q : 0); }
            if ($r = Request::int('role')) { $w .= ' AND EXISTS (SELECT 1 FROM user_roles ur WHERE ur.user_id = u.id AND ur.role_id = ?)'; $p[] = $r; }
            if ($g = Request::int('group')) { $w .= ' AND EXISTS (SELECT 1 FROM group_members gm WHERE gm.user_id = u.id AND gm.group_id = ?)'; $p[] = $g; }
            $sc = Scope::userSql('u.id'); $w .= $sc['sql']; $p = array_merge($p, $sc['params']);
            $rows = DB::all("SELECT u.*, (SELECT ROUND(AVG(progress_pct)) FROM enrollments e WHERE e.user_id = u.id) prog, (SELECT COUNT(*) FROM enrollments e WHERE e.user_id = u.id AND e.status = 'completed') done FROM users u WHERE $w ORDER BY u.last_name LIMIT 100", $p);
        }
        return view('admin/reports/users', ['title' => 'گزارش کاربر', 'rows' => $rows, 'q' => $q, 'roles' => DB::pairs('SELECT id, name FROM roles ORDER BY sort'), 'groups' => DB::pairs('SELECT id, name FROM `groups` ORDER BY sort')]);
    }

    public function user(int $id): string
    {
        $u = DB::one('SELECT id FROM users WHERE id = ?', [$id]) ?? throw new HttpException(404);
        Scope::authorizeUser($id);
        $R = UserReport::build($id, Request::str('view', 'month'), Request::intOrNull('y'), Request::intOrNull('m'), Request::intOrNull('d'));
        if ($this->exporting()) {
            $rows = [];
            foreach ($R['enrollments'] as $e) $rows[] = ['دوره', $e['title'], label('status', $e['status']), (float)$e['progress_pct'], $e['score'] !== null ? (float)$e['score'] : '', jdate($e['completed_at'])];
            foreach ($R['exams'] as $a) $rows[] = ['آزمون', $a['title'], (int)$a['passed'] ? 'قبول' : 'مردود', '', (float)$a['percent'], jdate($a['submitted_at'])];
            foreach ($R['daily'] as $day => $d) $rows[] = ['روز', jdate($day), 'ورود: ' . $d['logins'], 'درس: ' . $d['lessons_viewed'], 'تکمیل: ' . $d['lessons_completed'], round($d['seconds_spent'] / 60) . ' دقیقه'];
            Xlsx::download('user-report-' . $id, ['نوع', 'عنوان', 'وضعیت', 'پیشرفت', 'نمره', 'تاریخ'], $rows, 'گزارش کاربر');
        }
        return view('admin/reports/user', ['title' => 'گزارش ' . full_name($R['u']), 'R' => $R]);
    }

    /** Group/unit/level/term reports */
    public function groups(): string
    {
        $dim = Request::str('dim', 'group');
        if (!in_array($dim, ['group', 'org_unit', 'level', 'term'], true)) $dim = 'group';
        $entities = match ($dim) {
            'group' => DB::pairs('SELECT id, name FROM `groups` ORDER BY segment, sort, name'),
            'org_unit' => DB::pairs("SELECT o.id, CONCAT(t.name, ': ', o.name) FROM org_units o JOIN org_unit_types t ON t.id = o.type_id ORDER BY t.sort, o.name"),
            'level' => DB::pairs("SELECT l.id, CONCAT(g.name, ' — ', l.name) FROM levels l JOIN `groups` g ON g.id = l.group_id ORDER BY g.sort, l.rank_no"),
            'term' => DB::pairs("SELECT tt.id, CONCAT(t.name, ': ', tt.name) FROM taxonomy_terms tt JOIN taxonomies t ON t.id = tt.taxonomy_id ORDER BY t.sort, tt.name"),
        };
        $inact = date('Y-m-d H:i:s', strtotime('-' . (int)setting('inactivity_days', 14) . ' days'));
        $scopeIds = Scope::userIds();
        $rows = [];
        $selected = Request::int('id');
        foreach ($entities as $eid => $name) {
            if ($selected && $eid !== $selected) continue;
            $uids = Targeting::users($dim, $eid);
            if ($scopeIds !== null) $uids = array_values(array_intersect($uids, $scopeIds));
            $n = count($uids);
            $row = ['id' => $eid, 'name' => $name, 'members' => $n, 'active' => 0, 'inactive' => 0, 'prog' => null, 'done' => 0, 'laggards' => 0, 'avg_score' => null];
            if ($n) {
                $in = DB::in($uids);
                $row['active'] = (int)DB::value("SELECT COUNT(*) FROM users WHERE id IN ($in) AND last_login_at >= ?", array_merge($uids, [$inact]));
                $row['inactive'] = $n - $row['active'];
                $row['prog'] = DB::value("SELECT AVG(progress_pct) FROM enrollments WHERE user_id IN ($in)", $uids);
                $row['done'] = (int)DB::value("SELECT COUNT(*) FROM enrollments WHERE status = 'completed' AND user_id IN ($in)", $uids);
                $row['laggards'] = (int)DB::value("SELECT COUNT(DISTINCT user_id) FROM enrollments WHERE user_id IN ($in) AND status NOT IN ('completed') AND ((due_at IS NOT NULL AND due_at < NOW()) OR status IN ('expired','failed'))", $uids);
                $row['avg_score'] = DB::value("SELECT AVG(percent) FROM exam_attempts WHERE status = 'graded' AND user_id IN ($in)", $uids);
                if ($selected) $row['people'] = DB::all("SELECT u.id, u.first_name, u.last_name, u.last_login_at, (SELECT ROUND(AVG(progress_pct)) FROM enrollments e WHERE e.user_id = u.id) prog, (SELECT COUNT(*) FROM enrollments e WHERE e.user_id = u.id AND e.status = 'completed') done, (SELECT COUNT(*) FROM enrollments e WHERE e.user_id = u.id AND e.status NOT IN ('completed') AND ((e.due_at IS NOT NULL AND e.due_at < NOW()) OR e.status IN ('expired','failed'))) late FROM users u WHERE u.id IN ($in) ORDER BY prog", $uids);
            }
            $rows[] = $row;
        }
        if ($this->exporting()) {
            Xlsx::download('group-report', ['عنوان', 'تعداد افراد', 'فعال', 'غیرفعال', 'میانگین پیشرفت', 'دوره‌های تکمیل‌شده', 'افراد عقب‌مانده', 'میانگین نمره'],
                array_map(fn($r) => [$r['name'], $r['members'], $r['active'], $r['inactive'], round((float)$r['prog'], 1), $r['done'], $r['laggards'], $r['avg_score'] !== null ? round((float)$r['avg_score'], 1) : ''], $rows), 'گزارش گروهی');
        }
        return view('admin/reports/groups', ['title' => 'گزارش گروهی', 'dim' => $dim, 'rows' => $rows, 'entities' => $entities, 'selected' => $selected]);
    }

    public function courses(): string
    {
        $sc = Scope::courseSql('c.id');
        $su = Scope::userSql('e.user_id');
        $rows = DB::all("SELECT c.id, c.title, c.status, c.training_type, COUNT(e.id) n, SUM(e.status = 'completed') done, SUM(e.status IN ('in_progress','needs_retake')) active,
                                SUM(e.status = 'not_started') ns, SUM(e.status IN ('expired','failed')) bad, AVG(e.progress_pct) prog, AVG(e.score) score
                           FROM courses c LEFT JOIN enrollments e ON e.course_id = c.id" . ($su['sql'] ? ' ' . $su['sql'] : '') . "
                          WHERE c.deleted_at IS NULL" . $sc['sql'] . ' GROUP BY c.id, c.title, c.status, c.training_type ORDER BY n DESC', array_merge($su['params'], $sc['params']));
        if ($this->exporting()) Xlsx::download('courses-report', ['دوره', 'وضعیت', 'نوع', 'فراگیر', 'تکمیل', 'در جریان', 'شروع‌نشده', 'منقضی/مردود', 'میانگین پیشرفت', 'میانگین نمره'], array_map(fn($r) => [$r['title'], label('status', $r['status']), label('training_type', $r['training_type']), (int)$r['n'], (int)$r['done'], (int)$r['active'], (int)$r['ns'], (int)$r['bad'], round((float)$r['prog'], 1), $r['score'] !== null ? round((float)$r['score'], 1) : ''], $rows), 'دوره‌ها');
        return view('admin/reports/courses', ['title' => 'گزارش دوره‌ها', 'rows' => $rows]);
    }

    public function exams(): string
    {
        $sc = Scope::courseSql('x.course_id');
        $rows = DB::all("SELECT x.id, x.title, c.title AS course_title, COUNT(a.id) attempts, COUNT(DISTINCT a.user_id) users, AVG(CASE WHEN a.status = 'graded' THEN a.percent END) avg_pct, AVG(CASE WHEN a.status = 'graded' THEN a.passed END) pass_rate, SUM(a.status = 'pending_review') pending
                           FROM exams x LEFT JOIN courses c ON c.id = x.course_id LEFT JOIN exam_attempts a ON a.exam_id = x.id AND a.status <> 'in_progress'
                          WHERE x.deleted_at IS NULL" . $sc['sql'] . ' GROUP BY x.id, x.title, c.title ORDER BY attempts DESC', $sc['params']);
        if ($this->exporting()) Xlsx::download('exams-report', ['آزمون', 'دوره', 'دفعات', 'شرکت‌کننده', 'میانگین٪', 'نرخ قبولی٪', 'در انتظار تصحیح'], array_map(fn($r) => [$r['title'], $r['course_title'], (int)$r['attempts'], (int)$r['users'], round((float)$r['avg_pct'], 1), round((float)$r['pass_rate'] * 100, 1), (int)$r['pending']], $rows), 'آزمون‌ها');
        return view('admin/reports/exams', ['title' => 'گزارش آزمون‌ها', 'rows' => $rows]);
    }

    public function progress(): string
    {
        $w = 'u.deleted_at IS NULL AND c.deleted_at IS NULL'; $p = [];
        $state = Request::str('state');
        if ($state === 'overdue') $w .= " AND e.status NOT IN ('completed') AND ((e.due_at IS NOT NULL AND e.due_at < NOW()) OR e.status IN ('expired','failed'))";
        elseif (isset(\App\Core\Labels::STATUS[$state])) { $w .= ' AND e.status = ?'; $p[] = $state; }
        if ($c = Request::int('course')) { $w .= ' AND e.course_id = ?'; $p[] = $c; }
        if (($t = Request::str('type')) && isset(\App\Core\Labels::TRAINING_TYPE[$t])) { $w .= ' AND e.training_type = ?'; $p[] = $t; }
        if ($g = Request::int('group')) { $ids = Targeting::descendants('groups', $g); $w .= ' AND e.user_id IN (SELECT user_id FROM group_members WHERE group_id IN (' . DB::in($ids) . '))'; $p = array_merge($p, $ids); }
        $su = Scope::userSql('e.user_id'); $w .= $su['sql']; $p = array_merge($p, $su['params']);
        $sc = Scope::courseSql('e.course_id'); if (Scope::level() === 'own') { $w .= $sc['sql']; $p = array_merge($p, $sc['params']); }
        $sql = "SELECT e.*, u.first_name, u.last_name, u.mobile, c.title FROM enrollments e JOIN users u ON u.id = e.user_id JOIN courses c ON c.id = e.course_id WHERE $w ORDER BY e.due_at IS NULL, e.due_at, e.progress_pct";
        if ($this->exporting()) {
            $rows = DB::all($sql, $p);
            Xlsx::download('progress-report', ['نام', 'نام خانوادگی', 'موبایل', 'دوره', 'نوع', 'وضعیت', 'پیشرفت', 'نمره', 'مهلت', 'تکمیل'], array_map(fn($r) => [$r['first_name'], $r['last_name'], $r['mobile'], $r['title'], label('training_type', $r['training_type']), label('status', $r['status']), (float)$r['progress_pct'], $r['score'] !== null ? (float)$r['score'] : '', jdate($r['due_at']), jdate($r['completed_at'])], $rows), 'پیشرفت');
        }
        $page = DB::paginate($sql, $p, 40);
        return view('admin/reports/progress', ['title' => 'گزارش پیشرفت', 'page' => $page, 'courses' => DB::pairs('SELECT id, title FROM courses WHERE deleted_at IS NULL ORDER BY title'), 'groups' => DB::pairs('SELECT id, name FROM `groups` ORDER BY sort')]);
    }

    public function inactive(): string
    {
        $days = max(1, Request::int('days', (int)setting('inactivity_days', 14)));
        $since = date('Y-m-d H:i:s', strtotime("-$days days"));
        $sc = Scope::userSql('u.id');
        $sql = "SELECT u.*, (SELECT COUNT(*) FROM enrollments e WHERE e.user_id = u.id AND e.status NOT IN ('completed')) open_courses, (SELECT MAX(day) FROM user_daily_activity d WHERE d.user_id = u.id AND d.learning_events > 0) last_learning
                  FROM users u WHERE u.deleted_at IS NULL AND u.status = 'active' AND (u.last_login_at IS NULL OR u.last_login_at < ?)" . $sc['sql'] . ' ORDER BY u.last_login_at IS NULL DESC, u.last_login_at';
        $p = array_merge([$since], $sc['params']);
        if ($this->exporting()) {
            $rows = DB::all($sql, $p);
            Xlsx::download('inactive-users', ['نام', 'نام خانوادگی', 'موبایل', 'نوع', 'آخرین ورود', 'آخرین فعالیت آموزشی', 'دوره‌های باز'], array_map(fn($r) => [$r['first_name'], $r['last_name'], $r['mobile'], label('segment_one', $r['segment']), $r['last_login_at'] ? jdatetime($r['last_login_at']) : 'هرگز', jdate($r['last_learning']), (int)$r['open_courses']], $rows), 'افراد غیرفعال');
        }
        if (Request::isPost() && Request::str('notify') === '1' && can('notifications.create')) {
            $ids = array_column(DB::all($sql, $p), 'id');
            \App\Core\Notify::send($ids, 'inactivity', 'دلمان برایتان تنگ شده!', 'مدتی است به سامانه آموزش سر نزده‌اید. آموزش‌های شما منتظرتان هستند.', url('/learn'), 'inactive-' . date('Y-m-d'));
            flash('success', 'یادآوری برای ' . fa(count($ids)) . ' نفر ارسال شد.');
        }
        $page = DB::paginate($sql, $p, 40);
        return view('admin/reports/inactive', ['title' => 'افراد غیرفعال', 'page' => $page, 'days' => $days]);
    }

    public function needsReport(): string
    {
        $sc = Scope::userSql('n.user_id');
        $byCat = DB::all("SELECT COALESCE(c.name, 'بدون موضوع') name, COUNT(*) n, SUM(n.status = 'open') open_n, SUM(n.status = 'planned') planned, SUM(n.status = 'resolved') resolved, SUM(n.priority = 'high') high FROM training_needs n LEFT JOIN categories c ON c.id = n.category_id WHERE 1=1" . $sc['sql'] . ' GROUP BY c.name ORDER BY n DESC', $sc['params']);
        if ($this->exporting()) Xlsx::download('needs-report', ['موضوع', 'کل', 'باز', 'برنامه‌ریزی', 'رفع‌شده', 'اولویت بالا'], array_map(fn($r) => [$r['name'], (int)$r['n'], (int)$r['open_n'], (int)$r['planned'], (int)$r['resolved'], (int)$r['high']], $byCat), 'نیازسنجی');
        $chart = ['type' => 'bar', 'stacked' => true, 'labels' => array_column($byCat, 'name'), 'series' => [['name' => 'باز', 'data' => array_map('intval', array_column($byCat, 'open_n')), 'color' => '#f59e0b'], ['name' => 'برنامه‌ریزی', 'data' => array_map('intval', array_column($byCat, 'planned')), 'color' => '#0ea5e9'], ['name' => 'رفع‌شده', 'data' => array_map('intval', array_column($byCat, 'resolved')), 'color' => '#10b981']]];
        return view('admin/reports/needs', ['title' => 'گزارش نیاز آموزشی', 'byCat' => $byCat, 'chart' => $chart]);
    }

    public function content(): string
    {
        $lessons = DB::all("SELECT l.id, l.title, l.content_type, c.title AS course_title, COUNT(lp.user_id) viewers, SUM(lp.status = 'completed') completers, SUM(lp.views) views, AVG(lp.time_spent_sec) avg_time
                              FROM lessons l JOIN courses c ON c.id = l.course_id LEFT JOIN lesson_progress lp ON lp.lesson_id = l.id WHERE l.deleted_at IS NULL GROUP BY l.id, l.title, l.content_type, c.title ORDER BY views DESC LIMIT 100");
        $files = DB::all('SELECT id, title, original_name, kind, views, downloads, size FROM files WHERE deleted_at IS NULL ORDER BY views + downloads DESC LIMIT 50');
        $types = DB::pairs("SELECT l.content_type, SUM(lp.views) FROM lessons l JOIN lesson_progress lp ON lp.lesson_id = l.id GROUP BY l.content_type");
        if ($this->exporting()) Xlsx::download('content-usage', ['درس', 'دوره', 'نوع', 'بیننده', 'تکمیل', 'مجموع بازدید', 'میانگین زمان (دقیقه)'], array_map(fn($r) => [$r['title'], $r['course_title'], label('content_type', $r['content_type']), (int)$r['viewers'], (int)$r['completers'], (int)$r['views'], round((float)$r['avg_time'] / 60, 1)], $lessons), 'استفاده از محتوا');
        $chart = ['type' => 'donut', 'labels' => array_map(fn($k) => label('content_type', $k), array_keys($types)), 'values' => array_values(array_map('intval', $types)), 'center' => 'بازدید'];
        return view('admin/reports/content', ['title' => 'گزارش استفاده از محتوا', 'lessons' => $lessons, 'files' => $files, 'chart' => $chart]);
    }

    /** Supervisor: my team */
    public function team(): string
    {
        $ids = Scope::userIds();
        if ($ids === null) {
            // wide scope: show the users I directly supervise, if any; otherwise everyone I can see (limited)
            $ids = array_map('intval', DB::column('SELECT id FROM users WHERE supervisor_id = ? AND deleted_at IS NULL', [(int)auth()['id']]));
        }
        $rows = $ids ? DB::all("SELECT u.*, (SELECT ROUND(AVG(progress_pct)) FROM enrollments e WHERE e.user_id = u.id) prog, (SELECT COUNT(*) FROM enrollments e WHERE e.user_id = u.id) total,
                         (SELECT COUNT(*) FROM enrollments e WHERE e.user_id = u.id AND e.status = 'completed') done,
                         (SELECT COUNT(*) FROM enrollments e WHERE e.user_id = u.id AND e.training_type = 'mandatory' AND e.status <> 'completed') mand_open,
                         (SELECT COUNT(*) FROM enrollments e WHERE e.user_id = u.id AND e.status NOT IN ('completed') AND ((e.due_at IS NOT NULL AND e.due_at < NOW()) OR e.status IN ('expired','failed'))) late
                         FROM users u WHERE u.id IN (" . DB::in($ids) . ') AND u.deleted_at IS NULL ORDER BY prog', $ids) : [];
        $groups = DB::all('SELECT * FROM `groups` WHERE supervisor_id = ?', [(int)auth()['id']]);
        $units = DB::all('SELECT o.*, t.name AS type_name FROM org_units o JOIN org_unit_types t ON t.id = o.type_id WHERE o.manager_id = ?', [(int)auth()['id']]);
        return view('admin/reports/team', ['title' => 'تیم تحت مسئولیت من', 'rows' => $rows, 'groups' => $groups, 'units' => $units]);
    }
}
