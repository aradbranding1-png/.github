<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\DB;
use App\Core\HttpException;
use App\Services\Credit;
use App\Services\Enrollment;
use App\Services\ExamService;

/** Learner side of exams */
final class ExamController
{
    private function exam(int $id): array
    {
        $x = DB::one('SELECT x.*, c.title AS course_title FROM exams x LEFT JOIN courses c ON c.id = x.course_id WHERE x.id = ? AND x.deleted_at IS NULL', [$id]);
        if (!$x) throw new HttpException(404);
        $uid = (int)Auth::id();
        $staff = can('exams.view');
        if ($x['status'] !== 'published' && !$staff) throw new HttpException(404);
        if ($x['course_id']) {
            $en = Enrollment::get($uid, (int)$x['course_id']);
            if ((!$en || $en['status'] === 'locked') && !$staff) throw new HttpException(403, 'برای شرکت در این آزمون ابتدا در دوره مربوطه ثبت‌نام کنید و پیش‌نیازها را تکمیل کنید.');
        }
        return $x;
    }

    public function myExams(): string
    {
        $uid = (int)Auth::id();
        $rows = DB::all("SELECT x.*, c.title AS course_title,
                           (SELECT MAX(percent) FROM exam_attempts a WHERE a.exam_id = x.id AND a.user_id = ? AND a.status IN ('submitted','graded')) AS best,
                           (SELECT MAX(passed) FROM exam_attempts a WHERE a.exam_id = x.id AND a.user_id = ?) AS passed,
                           (SELECT COUNT(*) FROM exam_attempts a WHERE a.exam_id = x.id AND a.user_id = ? AND a.status <> 'in_progress') AS used,
                           (SELECT COUNT(*) FROM exam_attempts a WHERE a.exam_id = x.id AND a.user_id = ? AND a.status = 'pending_review') AS pending,
                           (SELECT COUNT(*) FROM exam_attempts a WHERE a.exam_id = x.id AND a.user_id = ? AND a.started_at >= ?) AS used_today
                          FROM exams x JOIN courses c ON c.id = x.course_id JOIN enrollments e ON e.course_id = x.course_id AND e.user_id = ?
                         WHERE x.status = 'published' AND x.deleted_at IS NULL AND e.status <> 'locked' ORDER BY passed IS NULL DESC, x.id DESC", [$uid, $uid, $uid, $uid, $uid, ExamService::dayStart(), $uid]);
        $attempts = DB::all('SELECT a.*, x.title FROM exam_attempts a JOIN exams x ON x.id = a.exam_id WHERE a.user_id = ? AND a.status <> \'in_progress\' ORDER BY a.id DESC LIMIT 30', [$uid]);
        return view('learn/exams', ['title' => 'آزمون‌های من', 'rows' => $rows, 'attempts' => $attempts]);
    }

    public function intro(int $id): string
    {
        $x = $this->exam($id);
        $uid = (int)Auth::id();
        $used = ExamService::attemptsUsed($id, $uid);
        $open = DB::one("SELECT id FROM exam_attempts WHERE exam_id = ? AND user_id = ? AND status = 'in_progress'", [$id, $uid]);
        $attempts = DB::all("SELECT * FROM exam_attempts WHERE exam_id = ? AND user_id = ? AND status <> 'in_progress' ORDER BY id DESC", [$id, $uid]);
        $passed = (bool)array_filter($attempts, fn($a) => (int)$a['passed'] === 1);
        $qcount = $x['random_count'] ?: (int)DB::value('SELECT COUNT(*) FROM exam_questions WHERE exam_id = ?', [$id]);
        $window = (!$x['available_from'] || $x['available_from'] <= now()) && (!$x['available_until'] || $x['available_until'] >= now());
        return view('learn/exam_intro', ['title' => $x['title'], 'x' => $x, 'credit' => Credit::examAccess($x), 'used' => $used, 'left' => ExamService::remainingToday($x, $uid), 'open' => $open, 'attempts' => $attempts, 'passed' => $passed, 'qcount' => $qcount, 'window' => $window]);
    }

    public function start(int $id): never
    {
        $x = $this->exam($id);
        $uid = (int)Auth::id();
        if ($x['available_from'] && $x['available_from'] > now()) { flash('warning', 'زمان شروع آزمون هنوز فرا نرسیده است.'); redirect('/learn/exam/' . $id); }
        if ($x['available_until'] && $x['available_until'] < now()) { flash('warning', 'مهلت شرکت در این آزمون به پایان رسیده است.'); redirect('/learn/exam/' . $id); }
        $open = DB::value("SELECT id FROM exam_attempts WHERE exam_id = ? AND user_id = ? AND status = 'in_progress'", [$id, $uid]);
        if (!$open && !Credit::examAccess($x)['ok']) { flash('danger', 'اعتبار این دوره آموزشی را ندارید. برای دریافت اعتبار با کارشناسان آراد برندینگ تماس بگیرید.'); redirect('/learn/exam/' . $id); }
        if (!$open && ExamService::remainingToday($x, $uid) === 0) { flash('warning', 'امروز ' . fa((int)$x['max_attempts']) . ' بار در این آزمون شرکت کرده‌اید که سقف مجاز روزانه است. از فردا دوباره می‌توانید شرکت کنید.'); redirect('/learn/exam/' . $id); }
        if (!$open && DB::value('SELECT 1 FROM exam_attempts WHERE exam_id = ? AND user_id = ? AND passed = 1', [$id, $uid])) { flash('info', 'شما قبلاً در این آزمون قبول شده‌اید.'); redirect('/learn/exam/' . $id); }
        $aid = ExamService::start($x, $uid);
        redirect('/learn/attempt/' . $aid);
    }

    private function attempt(int $id): array
    {
        $a = DB::find('exam_attempts', $id);
        if (!$a || (int)$a['user_id'] !== (int)Auth::id()) throw new HttpException(404);
        return $a;
    }

    public function take(int $id): string
    {
        $a = $this->attempt($id);
        if ($a['status'] !== 'in_progress') redirect('/learn/attempt/' . $id . '/result');
        if ($a['deadline_at'] && strtotime($a['deadline_at']) + 60 < time()) { ExamService::submit($a, []); redirect('/learn/attempt/' . $id . '/result'); }
        $x = DB::find('exams', (int)$a['exam_id']);
        $qids = json_decode($a['question_ids'], true) ?: [];
        $order = json_decode((string)$a['option_order'], true) ?: [];
        $questions = [];
        $smap = ExamService::scoreMap(array_map('intval', $qids), DB::pairs('SELECT question_id, score FROM exam_questions WHERE exam_id = ?', [(int)$x['id']]));
        foreach ($qids as $qid) {
            $q = DB::one('SELECT id, type, text, score FROM questions WHERE id = ?', [(int)$qid]);
            if (!$q) continue;
            $q['score'] = $smap[(int)$qid] ?? $q['score'];
            $opts = [];
            if ($q['type'] !== 'short' && $q['type'] !== 'essay') {
                $all = [];
                foreach (DB::all('SELECT id, text FROM question_options WHERE question_id = ?', [(int)$qid]) as $o) $all[(int)$o['id']] = $o;
                foreach ($order[$qid] ?? array_keys($all) as $oid) if (isset($all[(int)$oid])) $opts[] = $all[(int)$oid];
            }
            $q['options'] = $opts;
            $questions[] = $q;
        }
        return view('learn/exam_take', ['title' => $x['title'], 'a' => $a, 'x' => $x, 'questions' => $questions]);
    }

    public function submit(int $id): never
    {
        $a = $this->attempt($id);
        if ($a['status'] !== 'in_progress') redirect('/learn/attempt/' . $id . '/result');
        $late = $a['deadline_at'] && strtotime($a['deadline_at']) + 60 < time();
        $answers = $late ? [] : (array)($_POST['q'] ?? []);
        ExamService::submit($a, $answers);
        if ($late) flash('warning', 'زمان آزمون به پایان رسیده بود؛ پاسخ‌ها پس از مهلت پذیرفته نشد.');
        redirect('/learn/attempt/' . $id . '/result');
    }

    public function result(int $id): string
    {
        $a = $this->attempt($id);
        if ($a['status'] === 'in_progress') redirect('/learn/attempt/' . $id);
        $x = DB::find('exams', (int)$a['exam_id']);
        $answers = DB::all('SELECT aa.*, q.text, q.type, q.explanation FROM attempt_answers aa JOIN questions q ON q.id = aa.question_id WHERE aa.attempt_id = ? ORDER BY aa.id', [$id]);
        foreach ($answers as &$an) $an['options'] = DB::all('SELECT id, text, is_correct FROM question_options WHERE question_id = ? ORDER BY sort, id', [(int)$an['question_id']]);
        unset($an);
        $remaining = ExamService::remainingToday($x, (int)Auth::id()) ?? 99;
        return view('learn/exam_result', ['title' => 'نتیجه ' . $x['title'], 'a' => $a, 'x' => $x, 'answers' => $answers, 'remaining' => $remaining]);
    }
}
