<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\DB;
use App\Core\Gate;
use App\Core\HttpException;
use App\Core\Jalali;
use App\Core\Notify;
use App\Core\Request;
use App\Core\Upload;
use App\Core\Validator;
use App\Core\Xlsx;
use App\Services\Enrollment;
use App\Services\Scope;

/** Courses, lessons and exercises management */
final class CourseController
{
    public static function richText(?string $s): ?string
    {
        $s = trim((string)$s);
        if ($s === '') return null;
        if ($s === strip_tags($s)) {
            $paras = preg_split("/\R{2,}/u", $s) ?: [$s];
            $s = implode('', array_map(fn($p) => '<p>' . nl2br(e(trim($p))) . '</p>', $paras));
        }
        return clean_html($s);
    }

    public function index(): string
    {
        $w = 'c.deleted_at IS NULL';
        $p = [];
        if (($q = Request::str('q')) !== '') { $w .= ' AND c.title LIKE ?'; $p[] = "%$q%"; }
        if (($s = Request::str('status')) && in_array($s, ['draft', 'published', 'archived'], true)) { $w .= ' AND c.status = ?'; $p[] = $s; }
        if ($cat = Request::int('category')) { $w .= ' AND c.category_id = ?'; $p[] = $cat; }
        if (($seg = Request::str('segment')) && isset(\App\Core\Labels::SEGMENT[$seg])) { $w .= ' AND c.target_segment = ?'; $p[] = $seg; }
        $sc = Scope::courseSql('c.id');
        $w .= $sc['sql']; $p = array_merge($p, $sc['params']);
        $page = DB::paginate("SELECT c.*, cat.name AS category_name, cat.color AS category_color, cat.icon AS category_icon, u.first_name, u.last_name,
                                (SELECT COUNT(*) FROM lessons l WHERE l.course_id = c.id AND l.deleted_at IS NULL) lessons_n,
                                (SELECT COUNT(*) FROM enrollments e WHERE e.course_id = c.id) learners,
                                (SELECT COUNT(*) FROM enrollments e WHERE e.course_id = c.id AND e.status = 'completed') done
                               FROM courses c LEFT JOIN categories cat ON cat.id = c.category_id LEFT JOIN users u ON u.id = c.instructor_id WHERE $w ORDER BY c.id DESC", $p, 18);
        return view('admin/courses/index', ['title' => 'دوره‌ها', 'page' => $page, 'cats' => DB::pairs('SELECT id, name FROM categories ORDER BY sort, name')]);
    }

    private function formData(?array $c = null): array
    {
        return [
            'c' => $c,
            'cats' => DB::all('SELECT id, name, parent_id FROM categories ORDER BY sort, name'),
            'groups' => DB::all('SELECT id, name FROM `groups` ORDER BY sort, name'),
            'levels' => DB::all('SELECT l.id, CONCAT(g.name, \' — \', l.name) AS name FROM levels l JOIN `groups` g ON g.id = l.group_id ORDER BY g.sort, l.rank_no'),
            'instructors' => DB::all("SELECT DISTINCT u.id, u.first_name, u.last_name FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id WHERE u.deleted_at IS NULL AND r.is_learner = 0 ORDER BY u.last_name"),
            'allCourses' => DB::all('SELECT id, title FROM courses WHERE deleted_at IS NULL' . ($c ? ' AND id <> ' . (int)$c['id'] : '') . ' ORDER BY title'),
            'prereqs' => $c ? array_map('intval', DB::column('SELECT prerequisite_id FROM course_prerequisites WHERE course_id = ?', [(int)$c['id']])) : [],
        ];
    }

    public function create(): string
    {
        return view('admin/courses/form', ['title' => 'دوره جدید'] + $this->formData());
    }

    private function validated(): array
    {
        $d = Validator::validate([
            'title' => 'required|max:200', 'summary' => 'max:500', 'code' => 'max:50', 'training_type' => 'required|in:mandatory,optional,supplementary,suggested',
            'pass_score' => 'numeric', 'duration_minutes' => 'int', 'certificate_validity_months' => 'int', 'publish_at' => 'date', 'expire_at' => 'date',
        ], ['title' => 'عنوان', 'summary' => 'خلاصه', 'training_type' => 'نوع آموزش', 'pass_score' => 'نمره قبولی', 'publish_at' => 'تاریخ انتشار', 'expire_at' => 'تاریخ انقضا']);
        $seg = Request::str('target_segment');
        $row = [
            'title' => $d['title'], 'code' => $d['code'] ?? null, 'summary' => $d['summary'] ?? null,
            'description' => self::richText($_POST['description'] ?? ''), 'syllabus' => self::richText($_POST['syllabus'] ?? ''),
            'category_id' => Request::intOrNull('category_id'), 'target_segment' => in_array($seg, ['merchant', 'employee', 'agent'], true) ? $seg : null,
            'group_id' => Request::intOrNull('group_id'), 'level_id' => Request::intOrNull('level_id'), 'instructor_id' => Request::intOrNull('instructor_id'),
            'training_type' => $d['training_type'], 'is_sequential' => Request::bool('is_sequential') ? 1 : 0, 'self_enroll' => Request::bool('self_enroll') ? 1 : 0,
            'duration_minutes' => Request::intOrNull('duration_minutes'), 'pass_score' => max(0, min(100, (float)($d['pass_score'] ?? 80))),
            'has_certificate' => Request::bool('has_certificate') ? 1 : 0, 'certificate_validity_months' => Request::intOrNull('certificate_validity_months'),
            'publish_at' => Jalali::parse((string)($d['publish_at'] ?? ''), true), 'expire_at' => Jalali::parse((string)($d['expire_at'] ?? ''), true),
        ];
        if ($f = Request::file('image')) {
            $file = Upload::store($f, ['image'], ['folder' => 'course-covers', 'max_mb' => 5, 'library' => 1]);
            $row['image_file_id'] = (int)$file['id'];
        } elseif (Request::intOrNull('image_file_id')) {
            $row['image_file_id'] = Request::intOrNull('image_file_id');
        }
        return $row;
    }

    private function savePrereqs(int $id): void
    {
        DB::delete('course_prerequisites', 'course_id = ?', [$id]);
        foreach (Request::ints('prerequisites') as $pid) if ($pid !== $id) DB::insert('course_prerequisites', ['course_id' => $id, 'prerequisite_id' => $pid]);
    }

    public function store(): never
    {
        $row = $this->validated();
        if (Scope::level() === 'own') $row['instructor_id'] = Auth::id();
        $row += ['status' => 'draft', 'created_by' => Auth::id(), 'created_at' => now()];
        $id = DB::insert('courses', $row);
        $this->savePrereqs($id);
        Audit::log('courses.create', 'course', $id, 'success', ['title' => $row['title']]);
        flash('success', 'دوره ایجاد شد. اکنون درس‌ها را اضافه کنید.');
        redirect('/admin/courses/' . $id);
    }

    private function load(int $id): array
    {
        $c = DB::one('SELECT c.*, cat.name AS category_name, cat.color AS category_color FROM courses c LEFT JOIN categories cat ON cat.id = c.category_id WHERE c.id = ? AND c.deleted_at IS NULL', [$id]) ?? throw new HttpException(404);
        Scope::authorizeCourse($c);
        return $c;
    }

    public function show(int $id): string
    {
        $c = $this->load($id);
        $tab = Request::str('tab', 'lessons');
        $lessons = DB::all("SELECT l.*, f.original_name AS media_name, (SELECT COUNT(*) FROM lesson_progress lp WHERE lp.lesson_id = l.id) viewers, (SELECT COUNT(*) FROM lesson_progress lp WHERE lp.lesson_id = l.id AND lp.status = 'completed') completers FROM lessons l LEFT JOIN files f ON f.id = l.media_file_id WHERE l.course_id = ? AND l.deleted_at IS NULL ORDER BY l.sort, l.id", [$id]);
        $exams = DB::all("SELECT x.*, (SELECT COUNT(*) FROM exam_questions q WHERE q.exam_id = x.id) qn, (SELECT COUNT(*) FROM exam_attempts a WHERE a.exam_id = x.id AND a.status <> 'in_progress') attempts FROM exams x WHERE x.course_id = ? AND x.deleted_at IS NULL", [$id]);
        $exercises = DB::all("SELECT x.*, (SELECT COUNT(*) FROM exercise_submissions s WHERE s.exercise_id = x.id) subs, (SELECT COUNT(*) FROM exercise_submissions s WHERE s.exercise_id = x.id AND s.status = 'submitted') pending FROM exercises x WHERE x.course_id = ? AND x.deleted_at IS NULL", [$id]);
        $learners = $tab === 'learners' ? DB::paginate('SELECT e.*, u.first_name, u.last_name, u.mobile, u.avatar_path, u.is_root FROM enrollments e JOIN users u ON u.id = e.user_id WHERE e.course_id = ? ORDER BY e.progress_pct DESC', [$id], 30) : null;
        $stats = DB::one("SELECT COUNT(*) n, SUM(status = 'completed') done, SUM(status IN ('in_progress','needs_retake')) active, SUM(status = 'not_started') ns, AVG(progress_pct) prog, AVG(score) score FROM enrollments WHERE course_id = ?", [$id]);
        $prereq = DB::all('SELECT c.id, c.title FROM course_prerequisites p JOIN courses c ON c.id = p.prerequisite_id WHERE p.course_id = ?', [$id]);
        $instructor = $c['instructor_id'] ? DB::find('users', (int)$c['instructor_id']) : null;
        $sc = Scope::courseSql('c.id');
        $moveTargets = can('lessons.edit') ? DB::all("SELECT c.id, c.title, c.status FROM courses c WHERE c.deleted_at IS NULL AND c.id <> ?" . $sc['sql'] . ' ORDER BY c.title', array_merge([$id], $sc['params'])) : [];
        $sections = [];
        if ($moveTargets) foreach (DB::all("SELECT DISTINCT course_id, section_title FROM lessons WHERE deleted_at IS NULL AND section_title IS NOT NULL AND section_title <> '' ORDER BY course_id, section_title") as $r) $sections[(int)$r['course_id']][] = $r['section_title'];
        return view('admin/courses/show', compact('c', 'tab', 'lessons', 'exams', 'exercises', 'learners', 'stats', 'prereq', 'instructor', 'moveTargets', 'sections') + ['title' => $c['title']]);
    }

    public function edit(int $id): string
    {
        $c = $this->load($id);
        return view('admin/courses/form', ['title' => 'ویرایش ' . $c['title']] + $this->formData($c));
    }

    public function update(int $id): never
    {
        $c = $this->load($id);
        $row = $this->validated();
        if (Scope::level() === 'own') $row['instructor_id'] = $c['instructor_id'];
        $row['updated_at'] = now();
        DB::update('courses', $row, 'id = ?', [$id]);
        self::syncDuration($id);
        $this->savePrereqs($id);
        Enrollment::recalcCourse($id);
        Audit::log('courses.update', 'course', $id);
        flash('success', 'دوره ذخیره شد.');
        redirect('/admin/courses/' . $id);
    }

    public function publish(int $id): never
    {
        $c = $this->load($id);
        $to = Request::str('status', 'published');
        if (!in_array($to, ['draft', 'published', 'archived'], true)) throw new HttpException(400);
        $upd = ['status' => $to, 'updated_at' => now()];
        if ($to === 'published' && !$c['published_at']) $upd['published_at'] = now();
        DB::update('courses', $upd, 'id = ?', [$id]);
        if ($to === 'published' && $c['status'] !== 'published' && Request::bool('notify')) {
            $w = "status = 'active' AND deleted_at IS NULL";
            $p = [];
            if ($c['target_segment']) { $w .= ' AND segment = ?'; $p[] = $c['target_segment']; }
            if ($c['group_id']) { $w .= ' AND id IN (SELECT user_id FROM group_members WHERE group_id = ?)'; $p[] = (int)$c['group_id']; }
            $ids = DB::column("SELECT id FROM users WHERE $w", $p);
            Notify::send($ids, 'course', 'دوره جدید: ' . $c['title'], (string)$c['summary'], url('/learn/course/' . $id), 'course-new-' . $id);
        }
        Audit::log('courses.publish', 'course', $id, 'success', ['status' => $to]);
        flash('success', 'وضعیت انتشار دوره به «' . label('status', $to) . '» تغییر کرد.');
        redirect('/admin/courses/' . $id);
    }

    public function destroy(int $id): never
    {
        $c = $this->load($id);
        // soft delete — enrollments, progress, attempts and certificates remain for reports
        DB::update('courses', ['deleted_at' => now(), 'status' => 'archived'], 'id = ?', [$id]);
        Audit::log('courses.delete', 'course', $id, 'success', ['title' => $c['title']]);
        flash('success', 'دوره حذف شد. سوابق آموزشی کاربران حفظ شده است.');
        redirect('/admin/courses');
    }

    public function export(int $id): never
    {
        $c = $this->load($id);
        $rows = DB::all('SELECT e.*, u.first_name, u.last_name, u.mobile FROM enrollments e JOIN users u ON u.id = e.user_id WHERE e.course_id = ? ORDER BY u.last_name', [$id]);
        Xlsx::download('course-' . $id, ['نام', 'نام خانوادگی', 'موبایل', 'نوع', 'وضعیت', 'پیشرفت', 'نمره', 'شروع', 'تکمیل', 'مهلت'],
            array_map(fn($r) => [$r['first_name'], $r['last_name'], $r['mobile'], label('training_type', $r['training_type']), label('status', $r['status']), (float)$r['progress_pct'], $r['score'] !== null ? (float)$r['score'] : '', jdate($r['started_at']), jdate($r['completed_at']), jdate($r['due_at'])], $rows), mb_substr($c['title'], 0, 30));
    }

    // ------------------------------------------------------------------ lessons

    private function lessonFormData(array $c, ?array $l = null): array
    {
        return [
            'c' => $c, 'l' => $l,
            'library' => DB::all("SELECT id, title, original_name, kind, size FROM files WHERE deleted_at IS NULL AND is_library = 1 ORDER BY id DESC LIMIT 500"),
            'siblings' => DB::all('SELECT id, title FROM lessons WHERE course_id = ? AND deleted_at IS NULL' . ($l ? ' AND id <> ' . (int)$l['id'] : '') . ' ORDER BY sort', [(int)$c['id']]),
            'attached' => $l ? array_map('intval', DB::column('SELECT file_id FROM lesson_files WHERE lesson_id = ?', [(int)$l['id']])) : [],
            'sections' => DB::column('SELECT DISTINCT section_title FROM lessons WHERE course_id = ? AND section_title IS NOT NULL AND deleted_at IS NULL', [(int)$c['id']]),
            'courseLessons' => DB::all('SELECT id, title FROM lessons WHERE course_id = ? AND deleted_at IS NULL ORDER BY sort, id', [(int)$c['id']]),
            'lessonExams' => $l ? DB::all("SELECT x.*, (SELECT COUNT(*) FROM exam_questions q WHERE q.exam_id = x.id) qn FROM exams x WHERE x.lesson_id = ? AND x.deleted_at IS NULL ORDER BY x.id", [(int)$l['id']]) : [],
            'lessonExercises' => $l ? DB::all('SELECT * FROM exercises WHERE lesson_id = ? AND deleted_at IS NULL ORDER BY id', [(int)$l['id']]) : [],
        ];
    }

    public function lessonForm(int $id): string
    {
        $c = $this->load($id);
        return view('admin/courses/lesson_form', ['title' => 'درس جدید'] + $this->lessonFormData($c));
    }

    private function lessonRow(array $c, ?array $existing = null): array
    {
        $d = Validator::validate(['title' => 'required|max:200', 'content_type' => 'required|in:text,video,audio,image,pdf,file,link', 'link_url' => 'url|max:500', 'section_title' => 'max:150', 'duration_minutes' => 'int'], ['title' => 'عنوان', 'content_type' => 'نوع محتوا', 'link_url' => 'لینک']);
        $row = [
            'title' => $d['title'], 'content_type' => $d['content_type'], 'section_title' => ($d['section_title'] ?? '') !== '' ? $d['section_title'] : null,
            'body' => self::richText($_POST['body'] ?? ''), 'link_url' => $d['link_url'] ?? null, 'duration_minutes' => Request::intOrNull('duration_minutes'),
            'prerequisite_lesson_id' => Request::intOrNull('prerequisite_lesson_id'), 'is_preview' => Request::bool('is_preview') ? 1 : 0,
            'status' => Request::str('status') === 'draft' ? 'draft' : 'published', 'media_file_id' => Request::intOrNull('media_file_id'),
        ];
        $kindMap = ['video' => ['video'], 'audio' => ['audio'], 'image' => ['image'], 'pdf' => ['pdf'], 'file' => ['doc', 'pdf', 'image', 'audio', 'video']];
        if (($f = Request::file('media')) && isset($kindMap[$row['content_type']])) {
            $file = Upload::store($f, $kindMap[$row['content_type']], ['folder' => 'course-' . $c['id'], 'viewable' => 1, 'downloadable' => Request::bool('media_downloadable') ? 1 : 0, 'title' => $d['title']]);
            $row['media_file_id'] = (int)$file['id'];
        } elseif (!$row['media_file_id'] && $existing && !Request::bool('remove_media')) {
            // keep the current file, unless a video/audio link was just entered instead of it
            $linkChanged = $row['link_url'] && $row['link_url'] !== ($existing['link_url'] ?? null);
            if (!(in_array($row['content_type'], ['video', 'audio'], true) && $linkChanged)) $row['media_file_id'] = $existing['media_file_id'];
        }
        if (in_array($row['content_type'], ['video', 'audio'], true)) {
            $newFile = Request::file('media') || Request::intOrNull('media_file_id');
            if ($newFile && $row['media_file_id']) $row['link_url'] = null; // a new file replaces the link
            if ($row['link_url'] && !$row['media_file_id']) {
                if (!preg_match('~^https://~i', $row['link_url'])) {
                    flash('danger', 'لینک فیلم یا صوت باید با https:// شروع شود؛ در غیر این صورت مرورگر به دلایل امنیتی آن را پخش نمی‌کند.');
                    keep_old($_POST); back();
                }
                $kind = \App\Services\MediaLink::embed($row['link_url']) ? 'video' : \App\Services\MediaLink::directKind($row['link_url']);
                if ($kind && $kind !== $row['content_type']) $row['content_type'] = $kind; // e.g. an mp3 link saved as "video"
            }
            if (!$row['media_file_id'] && !$row['link_url']) {
                flash('danger', 'برای درس ویدیویی یا صوتی، فایل آپلود کنید، از کتابخانه انتخاب کنید یا لینک فایل را وارد کنید.');
                keep_old($_POST); back();
            }
        }
        return $row;
    }

    private function saveAttachments(int $lessonId): void
    {
        DB::delete('lesson_files', 'lesson_id = ?', [$lessonId]);
        $ids = Request::ints('attachments');
        foreach ((array)($_FILES['new_attachments']['name'] ?? []) as $i => $name) {
            if (($_FILES['new_attachments']['error'][$i] ?? 4) !== UPLOAD_ERR_OK) continue;
            $one = ['name' => $name, 'type' => $_FILES['new_attachments']['type'][$i], 'tmp_name' => $_FILES['new_attachments']['tmp_name'][$i], 'error' => $_FILES['new_attachments']['error'][$i], 'size' => $_FILES['new_attachments']['size'][$i]];
            $file = Upload::store($one, ['doc', 'pdf', 'image', 'audio', 'video'], ['viewable' => 1, 'downloadable' => 1]);
            $ids[] = (int)$file['id'];
        }
        foreach (array_values(array_unique($ids)) as $i => $fid) DB::insert('lesson_files', ['lesson_id' => $lessonId, 'file_id' => $fid, 'sort' => $i]);
    }

    /** Course duration = sum of its published lessons' durations (kept manual only while no published lesson has a duration) */
    public static function syncDuration(int $courseId): ?int
    {
        $sum = DB::one("SELECT COUNT(*) n, COALESCE(SUM(duration_minutes), 0) m FROM lessons WHERE course_id = ? AND deleted_at IS NULL AND status = 'published' AND duration_minutes > 0", [$courseId]);
        if (!(int)$sum['n']) return null;
        DB::update('courses', ['duration_minutes' => (int)$sum['m']], 'id = ?', [$courseId]);
        return (int)$sum['m'];
    }

    /** A lesson went live in this course → it moves to the top of the catalog («همه دوره‌ها» is ordered by this) */
    public static function touchLessons(int $courseId): void
    {
        try { DB::run('UPDATE courses SET last_lesson_at = ? WHERE id = ?', [now(), $courseId]); } catch (\Throwable) { /* migration 000012 not run yet */ }
    }

    public function lessonStore(int $id): never
    {
        $c = $this->load($id);
        $row = $this->lessonRow($c);
        $row += ['course_id' => $id, 'sort' => (int)DB::value('SELECT COALESCE(MAX(sort),0) + 1 FROM lessons WHERE course_id = ?', [$id]), 'created_at' => now()];
        $lid = DB::insert('lessons', $row);
        $this->saveAttachments($lid);
        if (($row['status'] ?? 'published') === 'published') self::touchLessons($id);
        self::syncDuration($id);
        Enrollment::recalcCourse($id);
        Audit::log('lessons.create', 'lesson', $lid, 'success', ['course' => $id]);
        flash('success', 'درس اضافه شد.');
        redirect(Request::bool('add_another') ? '/admin/courses/' . $id . '/lessons/create' : '/admin/courses/' . $id);
    }

    private function loadLesson(int $id): array
    {
        $l = DB::one('SELECT * FROM lessons WHERE id = ? AND deleted_at IS NULL', [$id]) ?? throw new HttpException(404);
        $c = $this->load((int)$l['course_id']);
        return [$l, $c];
    }

    public function lessonEdit(int $id): string
    {
        [$l, $c] = $this->loadLesson($id);
        return view('admin/courses/lesson_form', ['title' => 'ویرایش درس'] + $this->lessonFormData($c, $l));
    }

    public function lessonUpdate(int $id): never
    {
        [$l, $c] = $this->loadLesson($id);
        $row = $this->lessonRow($c, $l);
        $row['updated_at'] = now();
        DB::update('lessons', $row, 'id = ?', [$id]);
        $this->saveAttachments($id);
        // a draft lesson that is published now counts as a new lesson
        if (($row['status'] ?? $l['status']) === 'published' && $l['status'] !== 'published') self::touchLessons((int)$c['id']);
        self::syncDuration((int)$c['id']);
        Enrollment::recalcCourse((int)$c['id']);
        Audit::log('lessons.update', 'lesson', $id);
        flash('success', 'درس ذخیره شد.');
        redirect('/admin/courses/' . $c['id']);
    }

    public function lessonMove(int $id): never
    {
        [$l, $c] = $this->loadLesson($id);
        $all = DB::all('SELECT id FROM lessons WHERE course_id = ? AND deleted_at IS NULL ORDER BY sort, id', [(int)$c['id']]);
        $ids = array_map(fn($r) => (int)$r['id'], $all);
        $pos = array_search($id, $ids, true);
        $dir = Request::str('dir') === 'up' ? -1 : 1;
        $swap = $pos + $dir;
        if ($pos !== false && isset($ids[$swap])) { [$ids[$pos], $ids[$swap]] = [$ids[$swap], $ids[$pos]]; }
        foreach ($ids as $i => $lid) DB::update('lessons', ['sort' => $i + 1], 'id = ?', [$lid]);
        redirect('/admin/courses/' . $c['id']);
    }

    /** Move a lesson (with its files, lesson exams/exercises and learners' progress) to another course */
    public function lessonTransfer(int $id): never
    {
        [$l, $c] = $this->loadLesson($id);
        $to = DB::one('SELECT * FROM courses WHERE id = ? AND deleted_at IS NULL', [Request::int('course_id')]);
        if (!$to || (int)$to['id'] === (int)$c['id']) { flash('danger', 'دوره مقصد را انتخاب کنید.'); redirect('/admin/courses/' . $c['id']); }
        Scope::authorizeCourse($to);
        $mode = Request::str('section_mode', 'keep');
        $section = match ($mode) { 'none' => null, 'pick' => (trim(Request::str('section')) ?: null), default => $l['section_title'] };
        $from = (int)$c['id']; $target = (int)$to['id'];
        DB::transaction(function () use ($id, $from, $target, $section, $l) {
            $sort = (int)DB::value('SELECT COALESCE(MAX(sort), 0) + 1 FROM lessons WHERE course_id = ? AND deleted_at IS NULL', [$target]);
            // a prerequisite in the old course has no meaning in the new one
            $pre = $l['prerequisite_lesson_id'] && DB::value('SELECT 1 FROM lessons WHERE id = ? AND course_id = ?', [(int)$l['prerequisite_lesson_id'], $target]) ? (int)$l['prerequisite_lesson_id'] : null;
            DB::update('lessons', ['course_id' => $target, 'sort' => $sort, 'section_title' => $section, 'prerequisite_lesson_id' => $pre, 'updated_at' => now()], 'id = ?', [$id]);
            DB::run('UPDATE lessons SET prerequisite_lesson_id = NULL WHERE course_id = ? AND prerequisite_lesson_id = ?', [$from, $id]);
            DB::run('UPDATE exams SET course_id = ? WHERE lesson_id = ?', [$target, $id]);
            DB::run('UPDATE exercises SET course_id = ? WHERE lesson_id = ?', [$target, $id]);
            DB::run('UPDATE lesson_progress SET course_id = ? WHERE lesson_id = ?', [$target, $id]);
            DB::run('UPDATE lesson_unlocks SET course_id = ? WHERE lesson_id = ?', [$target, $id]);
            // re-number the old course
            foreach (DB::column('SELECT id FROM lessons WHERE course_id = ? AND deleted_at IS NULL ORDER BY sort, id', [$from]) as $i => $lid) DB::update('lessons', ['sort' => $i + 1], 'id = ?', [(int)$lid]);
        });
        foreach ([$from, $target] as $cid) { self::syncDuration($cid); Enrollment::recalcCourse($cid); }
        if ($l['status'] === 'published') self::touchLessons($target);
        Audit::log('lessons.transfer', 'lesson', $id, 'success', ['from' => $from, 'to' => $target]);
        flash('success', 'درس «' . $l['title'] . '» به دوره «' . $to['title'] . '» منتقل شد.', false);
        redirect(Request::str('after') === 'target' ? '/admin/courses/' . $target : '/admin/courses/' . $from);
    }

    /** Drag & drop order of a course's lessons */
    public function lessonOrder(int $id): never
    {
        $this->load($id);
        $valid = array_map('intval', DB::column('SELECT id FROM lessons WHERE course_id = ? AND deleted_at IS NULL', [$id]));
        $n = 0;
        DB::transaction(function () use ($valid, &$n) {
            foreach (Request::ints('ids') as $lid) if (in_array($lid, $valid, true)) DB::update('lessons', ['sort' => ++$n], 'id = ?', [$lid]);
        });
        Enrollment::recalcCourse($id);
        Audit::log('lessons.reorder', 'course', $id);
        json_out(['ok' => true, 'n' => $n]);
    }

    public function lessonDelete(int $id): never
    {
        [$l, $c] = $this->loadLesson($id);
        DB::update('lessons', ['deleted_at' => now()], 'id = ?', [$id]);
        self::syncDuration((int)$c['id']);
        Enrollment::recalcCourse((int)$c['id']);
        Audit::log('lessons.delete', 'lesson', $id, 'success', ['title' => $l['title']]);
        flash('success', 'درس حذف شد (سوابق مشاهده کاربران حفظ شده است).');
        redirect('/admin/courses/' . $c['id']);
    }

    // ------------------------------------------------------------------ exercises

    private function exerciseRow(): array
    {
        $d = Validator::validate(['title' => 'required|max:200', 'max_score' => 'numeric', 'pass_score' => 'numeric'], ['title' => 'عنوان تمرین']);
        $formats = array_values(array_intersect(Request::arr('formats'), ['text', 'file', 'image', 'video']));
        return [
            'title' => $d['title'], 'instructions' => self::richText($_POST['instructions'] ?? ''), 'formats' => implode(',', $formats ?: ['text']),
            'max_score' => (float)($d['max_score'] ?? 100), 'pass_score' => (float)($d['pass_score'] ?? 80), 'is_required' => Request::bool('is_required') ? 1 : 0,
            'lesson_id' => Request::intOrNull('lesson_id'), 'status' => Request::str('status') === 'draft' ? 'draft' : 'published',
        ];
    }

    /** back to the lesson editor when the exercise was added/edited from there */
    private function exerciseRedirect(int $courseId): never
    {
        $lid = Request::int('return_lesson');
        if ($lid && DB::value('SELECT 1 FROM lessons WHERE id = ? AND course_id = ?', [$lid, $courseId])) redirect('/admin/lessons/' . $lid . '/edit#lesson-extras');
        redirect('/admin/courses/' . $courseId . '?tab=exercises');
    }

    public function exerciseSave(int $id): never
    {
        $c = $this->load($id);
        $row = $this->exerciseRow() + ['course_id' => $id, 'created_by' => Auth::id(), 'created_at' => now()];
        $xid = DB::insert('exercises', $row);
        Enrollment::recalcCourse($id);
        Audit::log('exercises.create', 'exercise', $xid);
        flash('success', 'تمرین اضافه شد.');
        $this->exerciseRedirect($id);
    }

    public function exerciseUpdate(int $id): never
    {
        $x = DB::find('exercises', $id) ?? throw new HttpException(404);
        $this->load((int)$x['course_id']);
        DB::update('exercises', $this->exerciseRow(), 'id = ?', [$id]);
        Enrollment::recalcCourse((int)$x['course_id']);
        flash('success', 'تمرین ذخیره شد.');
        $this->exerciseRedirect((int)$x['course_id']);
    }

    public function exerciseDelete(int $id): never
    {
        $x = DB::find('exercises', $id) ?? throw new HttpException(404);
        $this->load((int)$x['course_id']);
        DB::update('exercises', ['deleted_at' => now()], 'id = ?', [$id]);
        Enrollment::recalcCourse((int)$x['course_id']);
        Audit::log('exercises.delete', 'exercise', $id);
        flash('success', 'تمرین حذف شد.');
        redirect('/admin/courses/' . $x['course_id'] . '?tab=exercises');
    }
}
