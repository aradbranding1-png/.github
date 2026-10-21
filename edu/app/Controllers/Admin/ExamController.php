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
            'title' => $d['title'], 'description' => CourseController::richText($_POST['description'] ?? ''), 'course_id' => $cid, 'lesson_id' => $lid,
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
        if (!can('exams.view') && !can_edit_exam($x)) throw new HttpException(403);
        $questions = DB::all('SELECT q.*, eq.score AS eq_score, eq.sort FROM exam_questions eq JOIN questions q ON q.id = eq.question_id WHERE eq.exam_id = ? ORDER BY eq.sort, q.id', [$id]);
        // all options at a glance (?opts=1) so correct answers can be verified without opening each question
        $editAll = Request::str('edit') === '1' && (can('questions.edit') || can_edit_exam($x)) && $questions;
        $showOpts = $editAll || Request::str('opts') === '1';
        $opts = [];
        if ($showOpts && $questions) {
            foreach (DB::all('SELECT id, question_id, text, is_correct FROM question_options WHERE question_id IN (' . DB::in(array_column($questions, 'id')) . ') ORDER BY sort, id', array_map('intval', array_column($questions, 'id'))) as $o) $opts[(int)$o['question_id']][] = $o;
        }
        $lessons = $x['course_id'] ? DB::pairs('SELECT id, title FROM lessons WHERE course_id = ? AND deleted_at IS NULL ORDER BY sort', [(int)$x['course_id']]) : [];
        return view('admin/exams/form', ['title' => $x['title'], 'x' => $x, 'questions' => $questions, 'qcatsAll' => DB::pairs('SELECT id, name FROM question_categories ORDER BY name'), 'levelGroups' => $editAll ? QuestionController::levelGroups() : [], 'showOpts' => $showOpts, 'editAll' => (bool)$editAll, 'opts' => $opts, 'bank' => [], 'courses' => $this->courses(), 'lessons' => $lessons, 'qcats' => DB::pairs('SELECT id, name FROM question_categories ORDER BY name'), 'preCourse' => 0]);
    }

    public function update(int $id): never
    {
        $x = $this->load($id);
        if (!can_edit_exam($x)) throw new HttpException(403);
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
        if (!can_edit_exam($x)) throw new HttpException(403);
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

    /** Edit text, options and correct answers of every question of the exam in one form; only changed questions are saved */
    public function bulkQuestions(int $id): never
    {
        $x = $this->load($id);
        if (!can('questions.edit') && !can_edit_exam($x)) throw new HttpException(403);
        $posted = (array)($_POST['q'] ?? []);
        $rows = DB::all('SELECT q.* FROM exam_questions eq JOIN questions q ON q.id = eq.question_id WHERE eq.exam_id = ? AND q.deleted_at IS NULL ORDER BY eq.sort, q.id', [$id]);
        $plan = [];
        $errors = [];
        foreach ($rows as $i => $q) {
            $qid = (int)$q['id'];
            if (!isset($posted[$qid]) || !is_array($posted[$qid])) continue;
            $in = $posted[$qid];
            $type = $q['type'];
            $text = trim(normalize_input((string)($in['text'] ?? '')));
            $n = $i + 1;
            if ($text === '') { $errors[] = "سؤال $n: متن سؤال خالی است."; continue; }
            $text = mb_substr($text, 0, 5000);
            $opts = [];
            if ($type !== 'essay') {
                $correct = array_map('strval', (array)($in['correct'] ?? []));
                foreach ((array)($in['opt'] ?? []) as $k => $t) {
                    $t = trim(normalize_input((string)$t));
                    if ($t === '') continue;
                    $opts[] = ['text' => mb_substr($t, 0, 2000), 'is_correct' => $type === 'short' ? 1 : (in_array((string)$k, $correct, true) ? 1 : 0)];
                }
                $nc = count(array_filter($opts, fn($o) => $o['is_correct']));
                if ($type === 'short') { if (!$opts) $errors[] = "سؤال $n: حداقل یک پاسخ قابل قبول لازم است."; }
                elseif (count($opts) < 2) $errors[] = "سؤال $n: حداقل دو گزینه لازم است.";
                elseif ($nc < 1) $errors[] = "سؤال $n: گزینه صحیح را مشخص کنید.";
                elseif ($type !== 'multiple' && $nc > 1) $errors[] = "سؤال $n: فقط یک گزینه صحیح مجاز است.";
                elseif ($type === 'truefalse' && count($opts) !== 2) $errors[] = "سؤال $n: درست/غلط دقیقاً دو گزینه دارد.";
            }
            $old = array_map(fn($o) => [(string)$o['text'], (int)$o['is_correct']], DB::all('SELECT text, is_correct FROM question_options WHERE question_id = ? ORDER BY sort, id', [$qid]));
            $new = array_map(fn($o) => [$o['text'], (int)$o['is_correct']], $opts);
            $diff = isset(\App\Core\Labels::DIFFICULTY[(int)($in['difficulty'] ?? 0)]) ? (int)$in['difficulty'] : (int)$q['difficulty'];
            $cat = (int)($in['category_id'] ?? 0) ?: null;
            if ($cat && !DB::value('SELECT 1 FROM question_categories WHERE id = ?', [$cat])) $cat = $q['category_id'] ? (int)$q['category_id'] : null;
            [$lvl, $grp] = QuestionController::levelTarget((string)($in['level_id'] ?? ''));
            $meta = ['difficulty' => $diff, 'category_id' => $cat, 'level_id' => $lvl, 'group_id' => $grp];
            $oldMeta = ['difficulty' => (int)$q['difficulty'], 'category_id' => $q['category_id'] ? (int)$q['category_id'] : null, 'level_id' => $q['level_id'] ? (int)$q['level_id'] : null, 'group_id' => !empty($q['group_id']) ? (int)$q['group_id'] : null];
            if ($text !== trim((string)$q['text']) || $meta !== $oldMeta || ($type !== 'essay' && $old !== $new)) $plan[] = [$qid, $text, $opts, $type, $meta];
        }
        if ($errors) {
            keep_old(['q' => $posted]);
            flash('danger', 'هیچ تغییری ذخیره نشد: ' . implode(' | ', $errors));
            redirect('/admin/exams/' . $id . '?edit=1#questions');
        }
        DB::transaction(function () use ($plan) {
            foreach ($plan as [$qid, $text, $opts, $type, $meta]) {
                DB::update('questions', ['text' => $text, 'updated_at' => now()] + $meta, 'id = ?', [$qid]);
                if ($type === 'essay') continue;
                DB::delete('question_options', 'question_id = ?', [$qid]);
                foreach ($opts as $i => $o) DB::insert('question_options', ['question_id' => $qid, 'text' => $o['text'], 'is_correct' => $o['is_correct'], 'sort' => $i]);
            }
        });
        clear_old();
        foreach ($plan as $pl) Audit::log('questions.update', 'question', $pl[0], 'success', ['exam' => $id, 'bulk' => 1]);
        flash('success', $plan ? fa(count($plan)) . ' سؤال ذخیره شد. (نتایج آزمون‌های قبلی تغییر نمی‌کند)' : 'تغییری برای ذخیره وجود نداشت.');
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
