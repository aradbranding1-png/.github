<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\Jalali;
use App\Core\Request;
use App\Core\Validator;
use App\Core\Xlsx;
use App\Services\Enrollment;
use App\Services\Scope;

final class ExamController
{
    public function index(): string
    {
        $w = 'x.deleted_at IS NULL';
        $p = [];
        if (($q = Request::str('q')) !== '') { $w .= ' AND x.title LIKE ?'; $p[] = "%$q%"; }
        if ($c = Request::int('course')) { $w .= ' AND x.course_id = ?'; $p[] = $c; }
        $sc = Scope::courseSql('x.course_id');
        $w .= $sc['sql']; $p = array_merge($p, $sc['params']);
        $page = DB::paginate("SELECT x.*, c.title AS course_title, (SELECT COUNT(*) FROM exam_questions q WHERE q.exam_id = x.id) qn,
                                (SELECT COUNT(*) FROM exam_attempts a WHERE a.exam_id = x.id AND a.status <> 'in_progress') attempts,
                                (SELECT AVG(percent) FROM exam_attempts a WHERE a.exam_id = x.id AND a.status = 'graded') avg_pct,
                                (SELECT AVG(passed) FROM exam_attempts a WHERE a.exam_id = x.id AND a.status = 'graded') pass_rate
                               FROM exams x LEFT JOIN courses c ON c.id = x.course_id WHERE $w ORDER BY x.id DESC", $p, 20);
        $courses = $this->courses();
        // a course is picked → list its lessons so an exam can be defined for any lesson right from here
        $course = null; $lessonRows = []; $examsByLesson = [];
        if ($c && isset($courses[$c])) {
            $course = ['id' => $c, 'title' => $courses[$c]];
            $lessonRows = $this->courseLessons($c);
            foreach (DB::all('SELECT id, title, status, lesson_id FROM exams WHERE course_id = ? AND deleted_at IS NULL ORDER BY id', [$c]) as $ex) {
                $examsByLesson[(int)($ex['lesson_id'] ?? 0)][] = $ex;
            }
        }
        return view('admin/exams/index', ['title' => 'آزمون‌ها', 'page' => $page, 'courses' => $courses, 'course' => $course, 'lessonRows' => $lessonRows, 'examsByLesson' => $examsByLesson]);
    }

    /** Lessons of a course, in syllabus order */
    private function courseLessons(int $courseId): array
    {
        return DB::all('SELECT id, title, section_title, status, content_type FROM lessons WHERE course_id = ? AND deleted_at IS NULL ORDER BY sort, id', [$courseId]);
    }

    /** JSON: lessons of a course (the exam form reloads its «درس مرتبط» list when the course changes) */
    public function lessons(): never
    {
        $cid = Request::int('course_id');
        if (!$cid || !isset($this->courses()[$cid])) json_out(['ok' => true, 'lessons' => []]);
        $out = [];
        foreach ($this->courseLessons($cid) as $l) {
            $out[] = ['id' => (int)$l['id'], 'title' => (string)$l['title'], 'section' => (string)($l['section_title'] ?? ''), 'draft' => $l['status'] !== 'published'];
        }
        json_out(['ok' => true, 'lessons' => $out]);
    }

    private function courses(): array
    {
        $sc = Scope::courseSql('id');
        return DB::pairs('SELECT id, title FROM courses WHERE deleted_at IS NULL' . $sc['sql'] . ' ORDER BY title', $sc['params']);
    }

    public function create(): string
    {
        return view('admin/exams/form', ['title' => 'آزمون جدید', 'x' => null, 'courses' => $this->courses(), 'lessons' => ($pc = Request::int('course_id')) ? DB::pairs('SELECT id, title FROM lessons WHERE course_id = ? AND deleted_at IS NULL ORDER BY sort, id', [$pc]) : [], 'qcats' => DB::pairs('SELECT id, name FROM question_categories ORDER BY name'), 'preCourse' => Request::int('course_id'), 'preLesson' => Request::int('lesson_id')]);
    }

    private function row(): array
    {
        $d = Validator::validate(['title' => 'required|max:200', 'pass_score' => 'required|numeric', 'max_attempts' => 'int', 'time_limit_minutes' => 'int', 'random_count' => 'int', 'available_from' => 'date', 'available_until' => 'date'], ['title' => 'عنوان', 'pass_score' => 'حد قبولی', 'max_attempts' => 'تعداد دفعات', 'time_limit_minutes' => 'زمان']);
        $cid = Request::intOrNull('course_id');
        if ($cid) { $c = DB::find('courses', $cid); if (!$c) throw new HttpException(422); Scope::authorizeCourse($c); }
        elseif (Scope::level() === 'own') throw new HttpException(403, 'آزمون باید به یکی از دوره‌های شما متصل باشد.');
        // the lesson must belong to the chosen course (a stale lesson from a previously selected course is dropped)
        $lid = Request::intOrNull('lesson_id');
        if ($lid && (!$cid || !DB::value('SELECT 1 FROM lessons WHERE id = ? AND course_id = ? AND deleted_at IS NULL', [$lid, $cid]))) $lid = null;
        return [
            'title' => $d['title'], 'description' => Request::str('description') ?: null, 'course_id' => $cid, 'lesson_id' => $lid,
            'is_required' => Request::bool('is_required') ? 1 : 0, 'time_limit_minutes' => Request::intOrNull('time_limit_minutes'),
            'pass_score' => max(0, min(100, (float)$d['pass_score'])), 'max_attempts' => max(0, Request::int('max_attempts', 1)),
            'shuffle_questions' => Request::bool('shuffle_questions') ? 1 : 0, 'shuffle_options' => Request::bool('shuffle_options') ? 1 : 0,
            'random_count' => Request::intOrNull('random_count'), 'random_category_id' => Request::intOrNull('random_category_id'),
            'show_answers' => Request::bool('show_answers') ? 1 : 0,
            'available_from' => Jalali::parse((string)($d['available_from'] ?? ''), true), 'available_until' => Jalali::parse((string)($d['available_until'] ?? ''), true),
        ];
    }

    public function store(): never
    {
        $status = can('exams.publish') && in_array(Request::str('status'), ['draft', 'published', 'archived'], true) ? Request::str('status') : 'draft';
        $row = $this->row() + ['status' => $status, 'created_by' => Auth::id(), 'created_at' => now()];
        $id = DB::insert('exams', $row);
        Audit::log('exams.create', 'exam', $id);
        flash('success', 'آزمون ایجاد شد. اکنون سؤالات را از بانک سؤال اضافه کنید.');
        redirect('/admin/exams/' . $id);
    }

    private function load(int $id): array
    {
        $x = DB::one('SELECT x.*, c.title AS course_title FROM exams x LEFT JOIN courses c ON c.id = x.course_id WHERE x.id = ? AND x.deleted_at IS NULL', [$id]) ?? throw new HttpException(404);
        if ($x['course_id']) Scope::authorizeCourse(['id' => $x['course_id']]);
        elseif (Scope::level() === 'own') throw new HttpException(403);
        return $x;
    }

    public function edit(int $id): string
    {
        $x = $this->load($id);
        $questions = DB::all('SELECT q.*, eq.score AS eq_score, eq.sort FROM exam_questions eq JOIN questions q ON q.id = eq.question_id WHERE eq.exam_id = ? ORDER BY eq.sort, q.id', [$id]);
        $w = 'q.deleted_at IS NULL AND q.is_active = 1 AND q.id NOT IN (SELECT question_id FROM exam_questions WHERE exam_id = ?)';
        $p = [$id];
        if ($qc = Request::int('qcat')) { $w .= ' AND q.category_id = ?'; $p[] = $qc; }
        if (($qt = Request::str('qtype')) !== '') { $w .= ' AND q.type = ?'; $p[] = $qt; }
        if (($qs = Request::str('qs')) !== '') { $w .= ' AND (q.text LIKE ? OR q.tags LIKE ?)'; $p[] = "%$qs%"; $p[] = "%$qs%"; }
        $bank = DB::all("SELECT q.*, c.name AS cat_name FROM questions q LEFT JOIN question_categories c ON c.id = q.category_id WHERE $w ORDER BY q.id DESC LIMIT 200", $p);
        $lessons = $x['course_id'] ? DB::pairs('SELECT id, title FROM lessons WHERE course_id = ? AND deleted_at IS NULL ORDER BY sort', [(int)$x['course_id']]) : [];
        return view('admin/exams/form', ['title' => $x['title'], 'x' => $x, 'questions' => $questions, 'bank' => $bank, 'courses' => $this->courses(), 'lessons' => $lessons, 'qcats' => DB::pairs('SELECT id, name FROM question_categories ORDER BY name'), 'preCourse' => 0]);
    }

    public function update(int $id): never
    {
        $x = $this->load($id);
        $row = $this->row() + ['updated_at' => now()];
        if (can('exams.publish') && in_array(Request::str('status'), ['draft', 'published', 'archived'], true)) $row['status'] = Request::str('status');
        DB::update('exams', $row, 'id = ?', [$id]);
        if ($x['course_id']) Enrollment::recalcCourse((int)$x['course_id']);
        if ($row['course_id'] && $row['course_id'] !== $x['course_id']) Enrollment::recalcCourse((int)$row['course_id']);
        Audit::log('exams.update', 'exam', $id, 'success', ['status' => $row['status'] ?? $x['status']]);
        flash('success', 'آزمون ذخیره شد.');
        redirect('/admin/exams/' . $id);
    }

    public function destroy(int $id): never
    {
        $x = $this->load($id);
        DB::update('exams', ['deleted_at' => now(), 'status' => 'archived'], 'id = ?', [$id]);
        if ($x['course_id']) Enrollment::recalcCourse((int)$x['course_id']);
        Audit::log('exams.delete', 'exam', $id);
        flash('success', 'آزمون حذف شد (نتایج قبلی حفظ شده است).');
        redirect('/admin/exams');
    }

    public function questions(int $id): never
    {
        $x = $this->load($id);
        $sort = (int)DB::value('SELECT COALESCE(MAX(sort),0) FROM exam_questions WHERE exam_id = ?', [$id]);
        foreach (Request::ints('add') as $qid) DB::run('INSERT IGNORE INTO exam_questions (exam_id, question_id, sort) VALUES (?,?,?)', [$id, $qid, ++$sort]);
        foreach (Request::ints('remove') as $qid) DB::delete('exam_questions', 'exam_id = ? AND question_id = ?', [$id, $qid]);
        foreach ((array)($_POST['score'] ?? []) as $qid => $sc) {
            DB::update('exam_questions', ['score' => $sc === '' ? null : max(0, (float)$sc)], 'exam_id = ? AND question_id = ?', [$id, (int)$qid]);
        }
        Audit::log('exams.questions', 'exam', $id);
        flash('success', 'سؤالات آزمون به‌روزرسانی شد.');
        redirect('/admin/exams/' . $id . '#questions');
    }

    public function report(int $id): string
    {
        $x = $this->load($id);
        $sc = Scope::userSql('a.user_id');
        $attempts = DB::all("SELECT a.*, u.first_name, u.last_name, u.mobile FROM exam_attempts a JOIN users u ON u.id = a.user_id WHERE a.exam_id = ? AND a.status <> 'in_progress'" . $sc['sql'] . ' ORDER BY a.id DESC', array_merge([$id], $sc['params']));
        if (Request::str('export') === '1' && can('exams.export')) {
            Xlsx::download('exam-' . $id, ['نام', 'نام خانوادگی', 'موبایل', 'دفعه', 'تاریخ', 'نمره', 'درصد', 'نتیجه'], array_map(fn($a) => [$a['first_name'], $a['last_name'], $a['mobile'], (int)$a['attempt_no'], jdatetime($a['submitted_at']), (float)$a['score'], (float)$a['percent'], $a['status'] === 'pending_review' ? 'در انتظار تصحیح' : ((int)$a['passed'] ? 'قبول' : 'مردود')], $attempts), 'نتایج آزمون');
        }
        $qstats = DB::all('SELECT q.id, q.text, q.type, COUNT(aa.id) n, AVG(aa.is_correct) rate FROM exam_questions eq JOIN questions q ON q.id = eq.question_id LEFT JOIN attempt_answers aa ON aa.question_id = q.id AND aa.attempt_id IN (SELECT id FROM exam_attempts WHERE exam_id = ?) WHERE eq.exam_id = ? GROUP BY q.id, q.text, q.type ORDER BY rate', [$id, $id]);
        $graded = array_filter($attempts, fn($a) => $a['status'] === 'graded');
        $buckets = array_fill(0, 10, 0);
        foreach ($graded as $a) $buckets[min(9, (int)floor((float)$a['percent'] / 10))]++;
        $dist = ['type' => 'bar', 'labels' => array_map(fn($i) => fa($i * 10) . '-' . fa($i * 10 + 10), range(0, 9)), 'series' => [['name' => 'تعداد', 'data' => $buckets, 'color' => '#8b5cf6']]];
        $sum = ['n' => count($attempts), 'users' => count(array_unique(array_column($attempts, 'user_id'))), 'avg' => $graded ? array_sum(array_column($graded, 'percent')) / count($graded) : null, 'pass' => $graded ? count(array_filter($graded, fn($a) => (int)$a['passed'] === 1)) * 100 / count($graded) : null, 'pending' => count(array_filter($attempts, fn($a) => $a['status'] === 'pending_review'))];
        return view('admin/exams/report', ['title' => 'گزارش ' . $x['title'], 'x' => $x, 'attempts' => $attempts, 'qstats' => $qstats, 'dist' => $dist, 'sum' => $sum]);
    }
}
