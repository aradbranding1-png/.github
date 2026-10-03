<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Activity;
use App\Core\Auth;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\Notify;
use App\Core\Request;
use App\Core\Upload;
use App\Services\Credit;
use App\Services\Enrollment;
use App\Services\Growth;
use App\Services\PathService;

/** Learner panel: courses, lessons, paths, growth, exercises, certificates. */
final class LearnController
{
    private function me(): array { return Auth::user(); }

    /** Courses visible to a learner in the catalog */
    public static function visibleCourseSql(array $u): array
    {
        // managers / instructors see every published course, whatever its audience
        if (can('courses.view')) return ["c.status = 'published' AND c.deleted_at IS NULL", []];
        return [
            "c.status = 'published' AND c.deleted_at IS NULL AND (c.publish_at IS NULL OR c.publish_at <= NOW()) AND (c.expire_at IS NULL OR c.expire_at > NOW())
             AND (c.target_segment IS NULL OR c.target_segment = ? OR EXISTS (SELECT 1 FROM enrollments e2 WHERE e2.course_id = c.id AND e2.user_id = ?))
             AND (c.group_id IS NULL OR EXISTS (SELECT 1 FROM group_members gm WHERE gm.group_id = c.group_id AND gm.user_id = ?) OR EXISTS (SELECT 1 FROM enrollments e3 WHERE e3.course_id = c.id AND e3.user_id = ?))",
            [$u['segment'], (int)$u['id'], (int)$u['id'], (int)$u['id']],
        ];
    }

    public function myCourses(): string
    {
        $uid = (int)$this->me()['id'];
        $tab = Request::str('tab', 'all');
        $where = 'e.user_id = ? AND c.deleted_at IS NULL';
        $p = [$uid];
        $map = ['mandatory' => "e.training_type = 'mandatory'", 'active' => "e.status IN ('in_progress','needs_retake','not_started')", 'completed' => "e.status = 'completed'", 'locked' => "e.status IN ('locked','expired','failed')"];
        if (isset($map[$tab])) $where .= ' AND ' . $map[$tab];
        $rows = DB::all("SELECT e.*, c.title, c.summary, c.image_file_id, c.duration_minutes, cat.name AS cat_name, cat.color AS cat_color, cat.icon AS cat_icon
                           FROM enrollments e JOIN courses c ON c.id = e.course_id LEFT JOIN categories cat ON cat.id = c.category_id
                          WHERE $where ORDER BY FIELD(e.status,'needs_retake','in_progress','not_started','locked','expired','failed','completed'), e.due_at IS NULL, e.due_at, e.id DESC", $p);
        $counts = DB::pairs("SELECT 'all', COUNT(*) FROM enrollments WHERE user_id = ? UNION ALL SELECT 'mandatory', COUNT(*) FROM enrollments WHERE user_id = ? AND training_type = 'mandatory'
            UNION ALL SELECT 'active', COUNT(*) FROM enrollments WHERE user_id = ? AND status IN ('in_progress','needs_retake','not_started')
            UNION ALL SELECT 'completed', COUNT(*) FROM enrollments WHERE user_id = ? AND status = 'completed'
            UNION ALL SELECT 'locked', COUNT(*) FROM enrollments WHERE user_id = ? AND status IN ('locked','expired','failed')", [$uid, $uid, $uid, $uid, $uid]);
        return view('learn/my_courses', ['title' => 'دوره‌های من', 'rows' => $rows, 'tab' => $tab, 'counts' => $counts]);
    }

    public function catalog(): string
    {
        $u = $this->me();
        [$vis, $vp] = self::visibleCourseSql($u);
        $q = Request::str('q');
        $cat = Request::int('category');
        $type = Request::str('type');
        $where = $vis;
        $p = $vp;
        if ($q !== '') { $where .= ' AND (c.title LIKE ? OR c.summary LIKE ?)'; $p[] = "%$q%"; $p[] = "%$q%"; }
        if ($cat) { $where .= ' AND (c.category_id = ? OR cat.parent_id = ?)'; $p[] = $cat; $p[] = $cat; }
        if (isset(\App\Core\Labels::TRAINING_TYPE[$type])) { $where .= ' AND c.training_type = ?'; $p[] = $type; }
        $page = DB::paginate("SELECT c.*, cat.name AS category_name, cat.color AS category_color, cat.icon AS category_icon, e.status, e.progress_pct, e.course_id
                                FROM courses c LEFT JOIN categories cat ON cat.id = c.category_id LEFT JOIN enrollments e ON e.course_id = c.id AND e.user_id = " . (int)$u['id'] . "
                               WHERE $where ORDER BY COALESCE(c.last_lesson_at, c.published_at, c.created_at) DESC, c.id DESC", $p, 12);
        // topics = top-level categories; the count is the courses THIS user can actually see in the catalog
        // (own courses + courses of its sub-topics), so every role sees exactly the topics that lead to results
        $cats = DB::all("SELECT cat.*, (SELECT COUNT(*) FROM courses c LEFT JOIN categories sub ON sub.id = c.category_id
                                         WHERE (c.category_id = cat.id OR sub.parent_id = cat.id) AND $vis) AS n
                           FROM categories cat WHERE cat.parent_id IS NULL ORDER BY cat.sort, cat.name", $vp);
        return view('learn/catalog', ['title' => 'کاتالوگ دوره‌ها', 'page' => $page, 'cats' => $cats, 'q' => $q, 'cat' => $cat, 'type' => $type]);
    }

    private function loadCourse(int $id, bool $allowPreview = true): array
    {
        $c = DB::one('SELECT c.*, cat.name AS category_name, cat.color AS category_color, cat.icon AS category_icon FROM courses c LEFT JOIN categories cat ON cat.id = c.category_id WHERE c.id = ? AND c.deleted_at IS NULL', [$id]);
        if (!$c) throw new HttpException(404);
        $enrolled = Enrollment::get((int)$this->me()['id'], $id);
        $isStaff = can('courses.view') || can('lessons.view');
        if ($c['status'] !== 'published' && !$enrolled && !($allowPreview && $isStaff)) throw new HttpException(404);
        return [$c, $enrolled, $isStaff];
    }

    public function course(int $id): string
    {
        $uid = (int)$this->me()['id'];
        [$c, $en, $isStaff] = $this->loadCourse($id);
        if ($en) $en = Enrollment::recalc($uid, $id);
        $items = Enrollment::items($id);
        $done = Enrollment::completedLessonIds($uid, $id);
        $prereq = DB::all('SELECT c.id, c.title, e.status FROM course_prerequisites p JOIN courses c ON c.id = p.prerequisite_id LEFT JOIN enrollments e ON e.course_id = c.id AND e.user_id = ? WHERE p.course_id = ?', [$uid, $id]);
        $best = Enrollment::bestAttempts($uid, array_map(fn($x) => (int)$x['id'], $items['exams']));
        $exSt = Enrollment::exerciseStatus($uid, array_map(fn($x) => (int)$x['id'], $items['exercises']));
        $instructor = $c['instructor_id'] ? DB::find('users', (int)$c['instructor_id']) : null;
        $cert = DB::one('SELECT * FROM certificates WHERE user_id = ? AND course_id = ? AND revoked_at IS NULL', [$uid, $id]);
        $stats = DB::one("SELECT COUNT(*) learners, SUM(status = 'completed') completed FROM enrollments WHERE course_id = ?", [$id]);
        $visibleToSelf = $c['status'] === 'published' && ($isStaff && can('courses.view') || ($c['self_enroll'] && (!$c['target_segment'] || $c['target_segment'] === $this->me()['segment'])));
        return view('learn/course', compact('c', 'en', 'items', 'done', 'prereq', 'best', 'exSt', 'instructor', 'cert', 'stats', 'isStaff', 'visibleToSelf') + ['title' => $c['title']]);
    }

    public function enroll(int $id): never
    {
        $u = $this->me();
        $c = DB::one("SELECT * FROM courses WHERE id = ? AND status = 'published' AND deleted_at IS NULL", [$id]);
        $staff = can('courses.view');
        if (!$c || (!$staff && (!(int)$c['self_enroll'] || ($c['target_segment'] && $c['target_segment'] !== $u['segment'])))) throw new HttpException(403, 'امکان ثبت‌نام در این دوره وجود ندارد.');
        Enrollment::enroll((int)$u['id'], $id, ['source' => 'self']);
        flash('success', 'در دوره ثبت‌نام شدید. موفق باشید!');
        redirect('/learn/course/' . $id);
    }

    public function lesson(int $id): string
    {
        $uid = (int)$this->me()['id'];
        $lesson = DB::one('SELECT * FROM lessons WHERE id = ? AND deleted_at IS NULL', [$id]);
        if (!$lesson) throw new HttpException(404);
        [$c, $en, $isStaff] = $this->loadCourse((int)$lesson['course_id']);
        $items = Enrollment::items((int)$c['id']);
        $done = Enrollment::completedLessonIds($uid, (int)$c['id']);
        $preview = false;
        if (!$en) {
            if ($isStaff || (int)$lesson['is_preview'] === 1) $preview = true;
            else { flash('warning', 'برای مشاهده درس‌ها ابتدا در دوره ثبت‌نام کنید.'); redirect('/learn/course/' . $c['id']); }
        } else {
            if (in_array($en['status'], ['locked'], true) && !$isStaff) { flash('warning', 'این دوره هنوز قفل است. ابتدا پیش‌نیازها را تکمیل کنید.'); redirect('/learn/course/' . $c['id']); }
            if ($lesson['status'] !== 'published' && !$isStaff) throw new HttpException(404);
            if (!Enrollment::lessonUnlocked($uid, $lesson, $c, $items['lessons'], $done) && !$isStaff) {
                flash('warning', Enrollment::lockReason($lesson, $c, $items['lessons'], $done, $en));
                redirect('/learn/course/' . $c['id']);
            }
        }
        // minute credit: an enrolled learner must activate (pay for) the lesson once before seeing it
        if ($en && !$preview && Credit::applies() && !Credit::isUnlocked($uid, $lesson)) {
            $cost = Credit::cost($lesson);
            $balance = Credit::balance($uid);
            return view('learn/lesson_unlock', ['title' => $lesson['title'], 'lesson' => $lesson, 'c' => $c, 'en' => $en, 'items' => $items, 'done' => $done,
                'cost' => $cost, 'balance' => $balance, 'courseNeed' => Credit::courseRemaining($uid, $items['lessons'])]);
        }
        $lp = DB::one('SELECT * FROM lesson_progress WHERE user_id = ? AND lesson_id = ?', [$uid, $id]);
        if ($en) {
            DB::run('INSERT INTO lesson_progress (user_id, lesson_id, course_id, status, views, first_viewed_at, last_viewed_at) VALUES (?,?,?,\'in_progress\',1,NOW(),NOW())
                     ON DUPLICATE KEY UPDATE views = views + 1, last_viewed_at = NOW()', [$uid, $id, (int)$c['id']]);
            DB::update('enrollments', ['last_lesson_id' => $id, 'last_activity_at' => now()], 'id = ?', [$en['id']]);
            Activity::track($uid, 'lesson_view', 'lesson', $id, ['course' => (int)$c['id']]);
            if ($en['status'] === 'not_started') Enrollment::recalc($uid, (int)$c['id']);
        }
        $files = DB::all('SELECT f.* FROM lesson_files lf JOIN files f ON f.id = lf.file_id WHERE lf.lesson_id = ? AND f.deleted_at IS NULL ORDER BY lf.sort', [$id]);
        $media = $lesson['media_file_id'] ? DB::one('SELECT * FROM files WHERE id = ? AND deleted_at IS NULL', [(int)$lesson['media_file_id']]) : null;
        $exams = DB::all("SELECT * FROM exams WHERE lesson_id = ? AND status = 'published' AND deleted_at IS NULL ORDER BY id", [$id]);
        $exercises = DB::all("SELECT * FROM exercises WHERE lesson_id = ? AND status = 'published' AND deleted_at IS NULL ORDER BY id", [$id]);
        $ids = array_map(fn($l) => (int)$l['id'], $items['lessons']);
        $pos = array_search($id, $ids, true);
        $prev = $pos !== false && $pos > 0 ? $items['lessons'][$pos - 1] : null;
        $next = $pos !== false && $pos < count($ids) - 1 ? $items['lessons'][$pos + 1] : null;
        // uploaded/library file wins; otherwise the lesson link (Aparat/YouTube page or direct media URL)
        $remote = !$media && in_array($lesson['content_type'], ['video', 'audio', 'link'], true) ? \App\Services\MediaLink::resolve($lesson) : null;
        return view('learn/lesson', compact('lesson', 'c', 'en', 'items', 'done', 'lp', 'files', 'media', 'exams', 'exercises', 'prev', 'next', 'preview', 'remote') + ['title' => $lesson['title']]);
    }

    /** Activate (pay for) one lesson with minute credit */
    public function unlockLesson(int $id): never
    {
        $uid = (int)$this->me()['id'];
        $lesson = DB::one("SELECT * FROM lessons WHERE id = ? AND deleted_at IS NULL AND status = 'published'", [$id]) ?? throw new HttpException(404);
        $en = Enrollment::get($uid, (int)$lesson['course_id']);
        if (!$en || $en['status'] === 'locked') throw new HttpException(403, 'ابتدا باید در این دوره ثبت‌نام شده باشید.');
        $items = Enrollment::items((int)$lesson['course_id']);
        $c = DB::find('courses', (int)$lesson['course_id']);
        if (!Enrollment::lessonUnlocked($uid, $lesson, $c, $items['lessons'], Enrollment::completedLessonIds($uid, (int)$c['id']))) {
            flash('warning', 'ابتدا درس‌های قبلی را تکمیل کنید.'); redirect('/learn/course/' . $c['id']);
        }
        [$ok, $msg] = Credit::unlock($uid, $lesson);
        if (!$ok) { flash('danger', $msg . ' ' . setting('minutes_charge_text')); redirect('/learn/lesson/' . $id); }
        flash('success', Credit::cost($lesson) ? 'درس فعال شد و ' . fa(Credit::cost($lesson)) . ' دقیقه از اعتبار شما کسر شد. موجودی: ' . fa(Credit::balance($uid)) . ' دقیقه.' : 'درس فعال شد.');
        redirect('/learn/lesson/' . $id);
    }

    /** Activate every remaining lesson of a course at once */
    public function unlockCourse(int $id): never
    {
        $uid = (int)$this->me()['id'];
        $en = Enrollment::get($uid, $id);
        if (!$en || $en['status'] === 'locked') throw new HttpException(403, 'ابتدا باید در این دوره ثبت‌نام شده باشید.');
        $items = Enrollment::items($id);
        $need = Credit::courseRemaining($uid, $items['lessons']);
        [$ok, $msg] = Credit::unlockCourse($uid, $items['lessons']);
        if (!$ok) { flash('danger', $msg . ' ' . setting('minutes_charge_text')); redirect('/learn/course/' . $id); }
        flash('success', 'همه درس‌های دوره فعال شد' . ($need ? ' و ' . fa($need) . ' دقیقه از اعتبار شما کسر شد' : '') . '. موجودی: ' . fa(Credit::balance($uid)) . ' دقیقه.');
        redirect('/learn/course/' . $id);
    }

    /** Learner's own minute credit & history */
    public function credits(): string
    {
        $uid = (int)$this->me()['id'];
        return view('learn/credits', ['title' => 'اعتبارهای من', 'totals' => Credit::totals($uid), 'bal' => Credit::balances($uid), 'ledger' => Credit::ledger($uid, 150)]);
    }

    /** Only well-known video platforms are embedded (CSP frame-src allow-list) */
    public static function embedUrl(string $url): ?string
    {
        return \App\Services\MediaLink::embed($url);
    }

    private function enrolledLesson(int $id): array
    {
        $uid = (int)$this->me()['id'];
        $lesson = DB::one('SELECT * FROM lessons WHERE id = ? AND deleted_at IS NULL', [$id]);
        if (!$lesson) throw new HttpException(404);
        $en = Enrollment::get($uid, (int)$lesson['course_id']);
        if (!$en || $en['status'] === 'locked') throw new HttpException(403);
        if (Credit::applies() && !Credit::isUnlocked($uid, $lesson)) throw new HttpException(403, 'این درس هنوز فعال نشده است.');
        return [$lesson, $en, $uid];
    }

    public function progress(int $id): never
    {
        [$lesson, $en, $uid] = $this->enrolledLesson($id);
        $pct = max(0, min(100, Request::int('percent')));
        $pos = max(0, Request::int('position'));
        DB::run('UPDATE lesson_progress SET percent = GREATEST(percent, ?), position_sec = ?, last_viewed_at = NOW() WHERE user_id = ? AND lesson_id = ?', [$pct, $pos, $uid, $id]);
        $auto = false;
        if (in_array($lesson['content_type'], ['video', 'audio'], true) && $pct >= (int)setting('video_complete_percent', 90)) {
            $auto = $this->markComplete($lesson, $uid);
        }
        json_out(['ok' => true, 'completed' => $auto]);
    }

    public function heartbeat(int $id): never
    {
        [$lesson, $en, $uid] = $this->enrolledLesson($id);
        $sec = max(0, min(120, Request::int('seconds', 60)));
        DB::run('UPDATE lesson_progress SET time_spent_sec = time_spent_sec + ? WHERE user_id = ? AND lesson_id = ?', [$sec, $uid, $id]);
        Activity::bump($uid, 'heartbeat', $sec);
        json_out(['ok' => true]);
    }

    private function markComplete(array $lesson, int $uid): bool
    {
        $lp = DB::one('SELECT status FROM lesson_progress WHERE user_id = ? AND lesson_id = ?', [$uid, (int)$lesson['id']]);
        if ($lp && $lp['status'] === 'completed') return false;
        DB::run("INSERT INTO lesson_progress (user_id, lesson_id, course_id, status, percent, views, first_viewed_at, last_viewed_at, completed_at) VALUES (?,?,?,'completed',100,1,NOW(),NOW(),NOW())
                 ON DUPLICATE KEY UPDATE status = 'completed', percent = 100, completed_at = NOW()", [$uid, (int)$lesson['id'], (int)$lesson['course_id']]);
        Activity::track($uid, 'lesson_complete', 'lesson', (int)$lesson['id'], ['course' => (int)$lesson['course_id']]);
        Enrollment::recalc($uid, (int)$lesson['course_id']);
        return true;
    }

    public function complete(int $id): never
    {
        [$lesson, $en, $uid] = $this->enrolledLesson($id);
        // watch-percentage is enforced for uploaded/library files; Aparat/YouTube embeds cannot
        // report progress, and external direct links are checked in the player
        if (in_array($lesson['content_type'], ['video', 'audio'], true) && !empty($lesson['media_file_id'])) {
            $lp = DB::one('SELECT percent FROM lesson_progress WHERE user_id = ? AND lesson_id = ?', [$uid, $id]);
            if ((int)($lp['percent'] ?? 0) < (int)setting('video_complete_percent', 90)) {
                flash('warning', 'برای تکمیل این درس، ابتدا محتوای آن را تا انتها مشاهده کنید.');
                redirect('/learn/lesson/' . $id);
            }
        }
        $this->markComplete($lesson, $uid);
        $next = DB::one("SELECT id FROM lessons WHERE course_id = ? AND deleted_at IS NULL AND status = 'published' AND (sort > ? OR (sort = ? AND id > ?)) ORDER BY sort, id LIMIT 1", [(int)$lesson['course_id'], (int)$lesson['sort'], (int)$lesson['sort'], $id]);
        $en = Enrollment::get($uid, (int)$lesson['course_id']);
        if ($en && $en['status'] === 'completed') { flash('success', 'تبریک! این دوره را کامل کردید.'); redirect('/learn/course/' . $lesson['course_id']); }
        flash('success', 'درس تکمیل شد.');
        redirect($next ? '/learn/lesson/' . $next['id'] : '/learn/course/' . $lesson['course_id']);
    }

    // ------------------------------------------------------------------ paths & growth

    /** Root, admins and training managers (مسئول آموزش) see the paths of every group */
    public static function pathStaff(): bool
    {
        return \App\Core\Gate::isRoot() || can_any(['paths.view', 'team.view', 'dashboard.view']);
    }

    /** Audience of a path: merchant / employee / agent, or 'all' */
    public static function pathAudience(array $p): string
    {
        if (!empty($p['target_segment'])) return (string)$p['target_segment'];
        if (!empty($p['group_segment']) && in_array($p['group_segment'], ['merchant', 'employee', 'agent'], true)) return (string)$p['group_segment'];
        return 'all';
    }

    /** May this learner open / start the path (its audience includes them)? */
    private function pathForMe(array $p, array $u): bool
    {
        if (!empty($p['target_segment']) && $p['target_segment'] !== $u['segment']) return false;
        if (!empty($p['group_id']) && !DB::value('SELECT 1 FROM group_members WHERE group_id = ? AND user_id = ?', [(int)$p['group_id'], (int)$u['id']])) return false;
        return true;
    }

    public function paths(): string
    {
        $u = $this->me();
        $uid = (int)$u['id'];
        $staff = self::pathStaff();
        $sql = "SELECT p.*, g.name AS group_name, g.segment AS group_segment,
                       (SELECT COUNT(*) FROM path_steps s WHERE s.path_id = p.id) AS steps,
                       pe.id AS pe_id, pe.status AS pe_status, pe.progress_pct, pe.current_step"
             . ($staff ? ", (SELECT COUNT(*) FROM path_enrollments x WHERE x.path_id = p.id) AS learners,
                         (SELECT COUNT(*) FROM path_enrollments x WHERE x.path_id = p.id AND x.status = 'completed') AS done" : '') . "
                  FROM learning_paths p
             LEFT JOIN `groups` g ON g.id = p.group_id
             LEFT JOIN path_enrollments pe ON pe.path_id = p.id AND pe.user_id = ?
                 WHERE p.deleted_at IS NULL AND (p.status = 'published' OR pe.id IS NOT NULL)";
        $args = [$uid];
        if (!$staff) {
            $sql .= " AND (pe.id IS NOT NULL OR ((p.target_segment IS NULL OR p.target_segment = ?)
                       AND (p.group_id IS NULL OR EXISTS (SELECT 1 FROM group_members gm WHERE gm.group_id = p.group_id AND gm.user_id = ?))))";
            array_push($args, $u['segment'], $uid);
        }
        $all = DB::all($sql . ' ORDER BY p.sort, p.id', $args);

        // staff: filter by audience (همه / تاجران / کارمندان / نمایندگان)
        $seg = $staff ? Request::str('seg') : '';
        $counts = ['' => count($all), 'merchant' => 0, 'employee' => 0, 'agent' => 0];
        foreach ($all as &$p) {
            $p['audience'] = self::pathAudience($p);
            foreach (['merchant', 'employee', 'agent'] as $sg) if ($p['audience'] === $sg || $p['audience'] === 'all') $counts[$sg]++;
        }
        unset($p);
        if (!isset($counts[$seg])) $seg = '';
        $rows = $seg === '' ? $all : array_values(array_filter($all, fn($p) => $p['audience'] === $seg || $p['audience'] === 'all'));

        $mine = array_filter($rows, fn($p) => $p['pe_id']);
        $doneN = count(array_filter($mine, fn($p) => $p['pe_status'] === 'completed'));
        $avg = $mine ? array_sum(array_map(fn($p) => (float)$p['progress_pct'], $mine)) / count($mine) : 0;
        return view('learn/paths', ['title' => 'مسیرهای آموزشی', 'rows' => $rows, 'staff' => $staff, 'seg' => $seg, 'counts' => $counts,
            'mineN' => count($mine), 'doneN' => $doneN, 'avg' => $avg]);
    }

    public function path(int $id): string
    {
        $u = $this->me();
        $uid = (int)$u['id'];
        $p = DB::one('SELECT p.*, g.name AS group_name, g.segment AS group_segment FROM learning_paths p LEFT JOIN `groups` g ON g.id = p.group_id WHERE p.id = ? AND p.deleted_at IS NULL', [$id]);
        if (!$p) throw new HttpException(404);
        $pe = DB::one('SELECT * FROM path_enrollments WHERE path_id = ? AND user_id = ?', [$id, $uid]);
        $staff = self::pathStaff();
        // learners only see published paths of their own segment / group (or ones assigned to them)
        if (!$pe && !$staff && ($p['status'] !== 'published' || !$this->pathForMe($p, $u))) throw new HttpException(404);
        $steps = PathService::steps($id);
        foreach ($steps as &$s) {
            $s['en'] = Enrollment::get($uid, (int)$s['course_id']);
            $s['passed'] = $pe ? PathService::stepPassed($uid, $s) : false;
        }
        unset($s);
        $canStart = !$pe && $p['status'] === 'published' && $this->pathForMe($p, $u);
        return view('learn/path', ['title' => $p['title'], 'p' => $p, 'pe' => $pe, 'steps' => $steps, 'staff' => $staff, 'canStart' => $canStart,
            'audience' => self::pathAudience($p)]);
    }

    public function enrollPath(int $id): never
    {
        $u = $this->me();
        $p = DB::one("SELECT * FROM learning_paths WHERE id = ? AND status = 'published' AND deleted_at IS NULL", [$id]);
        if (!$p || !$this->pathForMe($p, $u)) throw new HttpException(403, 'این مسیر برای گروه شما تعریف نشده است.');
        PathService::enroll((int)$u['id'], $id, ['source' => 'self', 'training_type' => 'optional']);
        flash('success', 'مسیر آموزشی برای شما فعال شد.');
        redirect('/learn/path/' . $id);
    }

    public function growth(): string
    {
        $uid = (int)$this->me()['id'];
        Growth::evaluate($uid);
        $m = Growth::metrics($uid);
        $tracks = [];
        foreach (Growth::userGroups($uid) as $g) {
            $stages = Growth::stagesFor((int)$g['id']);
            $cur = Growth::current($uid, (int)$g['id']);
            $next = null;
            foreach ($stages as $s) if ((int)$s['sort'] > (int)($cur['sort'] ?? 0)) { $next = $s; break; }
            $tracks[] = ['group' => $g, 'stages' => $stages, 'current' => $cur, 'next' => $next, 'checks' => $next ? Growth::check($next, $m) : []];
        }
        $history = DB::all('SELECT h.*, s.name AS stage_name, s.color, g.name AS group_name FROM growth_history h JOIN growth_stages s ON s.id = h.stage_id JOIN `groups` g ON g.id = h.group_id WHERE h.user_id = ? ORDER BY h.id DESC LIMIT 20', [$uid]);
        return view('learn/growth', ['title' => 'نظام رشد من', 'tracks' => $tracks, 'm' => $m, 'history' => $history]);
    }

    // ------------------------------------------------------------------ exercises

    public function exercises(): string
    {
        $uid = (int)$this->me()['id'];
        $rows = DB::all("SELECT x.*, c.title AS course_title, s.status AS sub_status, s.score AS sub_score, s.created_at AS sub_at
                           FROM exercises x JOIN courses c ON c.id = x.course_id JOIN enrollments e ON e.course_id = x.course_id AND e.user_id = ?
                           LEFT JOIN exercise_submissions s ON s.id = (SELECT MAX(id) FROM exercise_submissions WHERE exercise_id = x.id AND user_id = ?)
                          WHERE x.deleted_at IS NULL AND x.status = 'published' AND c.deleted_at IS NULL AND e.status <> 'locked'
                          ORDER BY s.status = 'accepted', x.id DESC", [$uid, $uid]);
        return view('learn/exercises', ['title' => 'تمرین‌های من', 'rows' => $rows]);
    }

    private function loadExercise(int $id): array
    {
        $uid = (int)$this->me()['id'];
        $x = DB::one("SELECT x.*, c.title AS course_title FROM exercises x JOIN courses c ON c.id = x.course_id WHERE x.id = ? AND x.deleted_at IS NULL", [$id]);
        if (!$x) throw new HttpException(404);
        $en = Enrollment::get($uid, (int)$x['course_id']);
        if ((!$en || $en['status'] === 'locked') && !can('reviews.view')) throw new HttpException(403, 'ابتدا در دوره مربوطه ثبت‌نام کنید.');
        return [$x, $en, $uid];
    }

    public function exercise(int $id): string
    {
        [$x, $en, $uid] = $this->loadExercise($id);
        $subs = DB::all('SELECT s.*, f.uuid AS file_uuid, f.original_name FROM exercise_submissions s LEFT JOIN files f ON f.id = s.file_id WHERE s.exercise_id = ? AND s.user_id = ? ORDER BY s.id DESC', [$id, $uid]);
        return view('learn/exercise', ['title' => $x['title'], 'x' => $x, 'subs' => $subs, 'en' => $en]);
    }

    public function submitExercise(int $id): never
    {
        [$x, $en, $uid] = $this->loadExercise($id);
        if (!$en) throw new HttpException(403);
        $last = DB::one('SELECT status FROM exercise_submissions WHERE exercise_id = ? AND user_id = ? ORDER BY id DESC LIMIT 1', [$id, $uid]);
        if ($last && in_array($last['status'], ['submitted', 'accepted'], true)) { flash('warning', $last['status'] === 'accepted' ? 'این تمرین قبلاً پذیرفته شده است.' : 'پاسخ قبلی شما در حال بررسی است.'); redirect('/learn/exercise/' . $id); }
        $text = trim((string)($_POST['text_answer'] ?? ''));
        $formats = explode(',', (string)$x['formats']);
        $fileId = null;
        if ($f = Request::file('file')) {
            $kinds = [];
            if (in_array('file', $formats, true)) $kinds = array_merge($kinds, ['pdf', 'doc']);
            if (in_array('image', $formats, true)) $kinds[] = 'image';
            if (in_array('video', $formats, true)) $kinds[] = 'video';
            if (in_array('file', $formats, true)) $kinds[] = 'audio';
            $row = Upload::store($f, $kinds, ['library' => 0, 'folder' => 'exercise', 'viewable' => 1, 'downloadable' => 1, 'max_mb' => min(512, (int)setting('max_upload_mb', 512))]);
            $fileId = (int)$row['id'];
        }
        if ($text === '' && !$fileId) { flash('danger', 'پاسخ متنی یا فایل تمرین را ارسال کنید.'); redirect('/learn/exercise/' . $id); }
        DB::insert('exercise_submissions', ['exercise_id' => $id, 'user_id' => $uid, 'text_answer' => mb_substr($text, 0, 20000), 'file_id' => $fileId, 'status' => 'submitted', 'created_at' => now()]);
        Activity::track($uid, 'exercise_submit', 'exercise', $id);
        DB::update('enrollments', ['last_activity_at' => now()], 'id = ?', [$en['id']]);
        Enrollment::recalc($uid, (int)$x['course_id']);
        $reviewers = Notify::usersWithPermission('reviews.approve');
        Notify::send(array_slice($reviewers, 0, 50), 'exercise', 'تمرین جدید برای بررسی: ' . $x['title'], full_name($this->me()) . ' — ' . $x['course_title'], url('/admin/reviews'));
        flash('success', 'تمرین شما ارسال شد و پس از بررسی نتیجه اعلام می‌شود.');
        redirect('/learn/exercise/' . $id);
    }

    // ------------------------------------------------------------------ certificates

    public function certificates(): string
    {
        $rows = DB::all('SELECT * FROM certificates WHERE user_id = ? ORDER BY issued_at DESC', [(int)$this->me()['id']]);
        return view('learn/certificates', ['title' => 'گواهی‌های من', 'rows' => $rows]);
    }

    public function certificate(string $code): string
    {
        $cert = DB::one('SELECT * FROM certificates WHERE code = ?', [$code]);
        if (!$cert || ((int)$cert['user_id'] !== (int)$this->me()['id'] && !can('certificates.view'))) throw new HttpException(404);
        return view('learn/certificate', ['title' => 'گواهی ' . $cert['course_title'], 'cert' => $cert]);
    }

    public function verifyCertificate(string $code): string
    {
        $code = strtoupper(preg_replace('/[^A-Za-z0-9\-]/', '', $code) ?? '');
        $cert = DB::one('SELECT code, user_name, course_title, issued_at, expires_at, revoked_at, score FROM certificates WHERE code = ?', [$code]);
        return view('learn/verify', ['title' => 'استعلام گواهی', 'cert' => $cert, 'code' => $code], 'layouts/bare');
    }
}
