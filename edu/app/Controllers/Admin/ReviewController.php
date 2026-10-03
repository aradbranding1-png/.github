<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\Notify;
use App\Core\Request;
use App\Core\Validator;
use App\Core\Xlsx;
use App\Services\Enrollment;
use App\Services\ExamService;
use App\Services\Growth;
use App\Services\PathService;
use App\Services\Scope;

/** Exercise review, essay grading, practical evaluations and certificates */
final class ReviewController
{
    private function scopeSql(string $userCol, string $courseCol): array
    {
        $u = Scope::userSql($userCol);
        $c = Scope::courseSql($courseCol);
        if (Scope::level() === 'own') return $c; // instructors: their courses
        return $u;
    }

    public function index(): string
    {
        $tab = Request::str('tab', 'pending');
        $s1 = $this->scopeSql('s.user_id', 'x.course_id');
        $s2 = $this->scopeSql('a.user_id', 'x.course_id');
        $subWhere = $tab === 'done' ? "s.status <> 'submitted'" : "s.status = 'submitted'";
        $subs = DB::all("SELECT s.*, x.title, x.max_score, c.title AS course_title, u.first_name, u.last_name, u.avatar_path FROM exercise_submissions s JOIN exercises x ON x.id = s.exercise_id JOIN courses c ON c.id = x.course_id JOIN users u ON u.id = s.user_id WHERE $subWhere" . $s1['sql'] . ' ORDER BY s.id ' . ($tab === 'done' ? 'DESC' : 'ASC') . ' LIMIT 100', $s1['params']);
        $attWhere = $tab === 'done' ? "a.status = 'graded' AND a.graded_by IS NOT NULL" : "a.status = 'pending_review'";
        $atts = DB::all("SELECT a.*, x.title, u.first_name, u.last_name, u.avatar_path FROM exam_attempts a JOIN exams x ON x.id = a.exam_id JOIN users u ON u.id = a.user_id WHERE $attWhere" . $s2['sql'] . ' ORDER BY a.id ' . ($tab === 'done' ? 'DESC' : 'ASC') . ' LIMIT 100', $s2['params']);
        return view('admin/reviews/index', ['title' => 'بررسی و تصحیح', 'tab' => $tab, 'subs' => $subs, 'atts' => $atts]);
    }

    private function loadSubmission(int $id): array
    {
        $s = DB::one('SELECT s.*, x.title, x.instructions, x.max_score, x.pass_score, x.course_id, c.title AS course_title, f.uuid AS file_uuid, f.original_name, f.kind AS file_kind FROM exercise_submissions s JOIN exercises x ON x.id = s.exercise_id JOIN courses c ON c.id = x.course_id LEFT JOIN files f ON f.id = s.file_id WHERE s.id = ?', [$id]) ?? throw new HttpException(404);
        if (Scope::level() === 'own') Scope::authorizeCourse(['id' => $s['course_id']]); else Scope::authorizeUser((int)$s['user_id']);
        return $s;
    }

    public function submission(int $id): string
    {
        $s = $this->loadSubmission($id);
        $u = DB::find('users', (int)$s['user_id']);
        $history = DB::all('SELECT * FROM exercise_submissions WHERE exercise_id = ? AND user_id = ? AND id <> ? ORDER BY id DESC', [(int)$s['exercise_id'], (int)$s['user_id'], $id]);
        return view('admin/reviews/submission', ['title' => 'بررسی تمرین', 's' => $s, 'u' => $u, 'history' => $history]);
    }

    public function reviewSubmission(int $id): never
    {
        $s = $this->loadSubmission($id);
        $d = Validator::validate(['status' => 'required|in:accepted,needs_revision,rejected', 'score' => 'numeric', 'feedback' => 'max:5000'], ['status' => 'نتیجه', 'score' => 'نمره', 'feedback' => 'بازخورد']);
        $score = ($d['score'] ?? '') !== '' ? max(0, min((float)$s['max_score'], (float)$d['score'])) : null;
        $status = $d['status'];
        if ($status === 'accepted' && $score !== null && $score < (float)$s['pass_score']) $status = 'needs_revision';
        DB::update('exercise_submissions', ['status' => $status, 'score' => $score, 'feedback' => $d['feedback'] ?? null, 'reviewed_by' => Auth::id(), 'reviewed_at' => now()], 'id = ?', [$id]);
        Enrollment::recalc((int)$s['user_id'], (int)$s['course_id']);
        PathService::advance((int)$s['user_id']);
        Growth::evaluate((int)$s['user_id']);
        Notify::send((int)$s['user_id'], 'exercise_review', 'نتیجه بررسی تمرین «' . $s['title'] . '»: ' . label('status', $status), (string)($d['feedback'] ?? ''), url('/learn/exercise/' . $s['exercise_id']));
        Audit::log('reviews.exercise', 'exercise_submission', $id, 'success', ['status' => $status, 'score' => $score]);
        flash('success', 'نتیجه ثبت و به فراگیر اطلاع داده شد.');
        redirect('/admin/reviews');
    }

    private function loadAttempt(int $id): array
    {
        $a = DB::one('SELECT a.*, x.title, x.course_id, x.pass_score FROM exam_attempts a JOIN exams x ON x.id = a.exam_id WHERE a.id = ?', [$id]) ?? throw new HttpException(404);
        if (Scope::level() === 'own') { if ($a['course_id']) Scope::authorizeCourse(['id' => $a['course_id']]); } else Scope::authorizeUser((int)$a['user_id']);
        return $a;
    }

    public function attempt(int $id): string
    {
        $a = $this->loadAttempt($id);
        $u = DB::find('users', (int)$a['user_id']);
        $answers = DB::all('SELECT aa.*, q.text, q.type, q.explanation FROM attempt_answers aa JOIN questions q ON q.id = aa.question_id WHERE aa.attempt_id = ? ORDER BY aa.id', [$id]);
        foreach ($answers as &$an) $an['options'] = DB::all('SELECT id, text, is_correct FROM question_options WHERE question_id = ? ORDER BY sort, id', [(int)$an['question_id']]);
        unset($an);
        return view('admin/reviews/attempt', ['title' => 'تصحیح آزمون', 'a' => $a, 'u' => $u, 'answers' => $answers]);
    }

    public function gradeAttempt(int $id): never
    {
        $a = $this->loadAttempt($id);
        $grades = [];
        foreach ((array)($_POST['g'] ?? []) as $aid => $g) $grades[(int)$aid] = ['score' => (float)($g['score'] ?? 0), 'feedback' => (string)($g['feedback'] ?? '')];
        ExamService::finalizeReview($a, $grades, (int)Auth::id());
        Audit::log('reviews.exam', 'exam_attempt', $id, 'success', ['answers' => count($grades)]);
        flash('success', 'تصحیح ثبت شد و نتیجه نهایی به فراگیر اعلام شد.');
        redirect('/admin/reviews');
    }

    // ------------------------------------------------------------------ practical evaluations
    public function evaluations(): string
    {
        $sc = $this->scopeSql('p.user_id', 'p.course_id');
        $rows = DB::all('SELECT p.*, u.first_name, u.last_name, c.title AS course_title, s.name AS stage_name, ev.first_name AS ev_first, ev.last_name AS ev_last FROM practical_evaluations p JOIN users u ON u.id = p.user_id LEFT JOIN courses c ON c.id = p.course_id LEFT JOIN growth_stages s ON s.id = p.stage_id LEFT JOIN users ev ON ev.id = p.evaluator_id WHERE 1=1' . $sc['sql'] . ' ORDER BY p.id DESC LIMIT 200', $sc['params']);
        $us = Scope::userSql('id');
        $users = DB::all('SELECT id, first_name, last_name, mobile FROM users WHERE deleted_at IS NULL AND status = \'active\'' . $us['sql'] . ' ORDER BY last_name LIMIT 3000', $us['params']);
        $cs = Scope::courseSql('id');
        return view('admin/reviews/evaluations', ['title' => 'ارزیابی عملی', 'rows' => $rows, 'users' => $users,
            'courses' => DB::pairs('SELECT id, title FROM courses WHERE deleted_at IS NULL' . $cs['sql'] . ' ORDER BY title', $cs['params']),
            'stages' => DB::pairs("SELECT s.id, CONCAT(g.name, ' — ', s.name) FROM growth_stages s JOIN `groups` g ON g.id = s.group_id ORDER BY g.sort, s.sort")]);
    }

    public function saveEvaluation(): never
    {
        $d = Validator::validate(['user_id' => 'required|int', 'title' => 'required|max:200', 'score' => 'numeric', 'max_score' => 'numeric', 'notes' => 'max:5000'], ['user_id' => 'فراگیر', 'title' => 'عنوان ارزیابی']);
        $uid = (int)$d['user_id'];
        Scope::authorizeUser($uid);
        $cid = Request::intOrNull('course_id');
        if ($cid && Scope::level() === 'own') Scope::authorizeCourse(['id' => $cid]);
        $max = (float)($d['max_score'] ?? 100) ?: 100;
        $score = ($d['score'] ?? '') !== '' ? max(0, min($max, (float)$d['score'])) : null;
        $id = DB::insert('practical_evaluations', [
            'user_id' => $uid, 'course_id' => $cid, 'stage_id' => Request::intOrNull('stage_id'), 'title' => $d['title'], 'criteria' => Request::str('criteria') ?: null,
            'score' => $score, 'max_score' => $max, 'passed' => Request::bool('passed') ? 1 : 0, 'notes' => $d['notes'] ?? null,
            'evaluator_id' => Auth::id(), 'evaluated_at' => now(), 'created_at' => now(),
        ]);
        if ($cid) Enrollment::recalc($uid, $cid);
        PathService::advance($uid);
        Growth::evaluate($uid);
        Notify::send($uid, 'exercise_review', 'نتیجه ارزیابی عملی «' . $d['title'] . '» ثبت شد', Request::bool('passed') ? 'قبول' : 'نیازمند تلاش بیشتر', url('/learn/growth'));
        Audit::log('evaluations.create', 'evaluation', $id, 'success', ['user' => $uid, 'passed' => Request::bool('passed')]);
        flash('success', 'ارزیابی عملی ثبت شد.');
        redirect('/admin/evaluations');
    }

    public function deleteEvaluation(int $id): never
    {
        $p = DB::find('practical_evaluations', $id) ?? throw new HttpException(404);
        Scope::authorizeUser((int)$p['user_id']);
        DB::delete('practical_evaluations', 'id = ?', [$id]);
        Audit::log('evaluations.delete', 'evaluation', $id);
        flash('success', 'ارزیابی حذف شد.');
        redirect('/admin/evaluations');
    }

    // ------------------------------------------------------------------ certificates
    public function certificates(): string
    {
        $w = '1=1'; $p = [];
        if (($q = Request::str('q')) !== '') { $w .= ' AND (c.code LIKE ? OR c.user_name LIKE ? OR c.course_title LIKE ?)'; array_push($p, "%$q%", "%$q%", "%$q%"); }
        $sc = Scope::userSql('c.user_id');
        $w .= $sc['sql']; $p = array_merge($p, $sc['params']);
        if (Request::str('export') === '1' && can('certificates.export')) {
            $rows = DB::all("SELECT c.* FROM certificates c WHERE $w ORDER BY c.id DESC", $p);
            Xlsx::download('certificates', ['کد', 'نام', 'دوره', 'مدرس', 'نمره', 'تاریخ صدور', 'اعتبار تا', 'وضعیت'], array_map(fn($r) => [$r['code'], $r['user_name'], $r['course_title'], $r['instructor_name'], $r['score'] !== null ? (float)$r['score'] : '', jdate($r['issued_at']), jdate($r['expires_at']), $r['revoked_at'] ? 'ابطال‌شده' : 'معتبر'], $rows), 'گواهی‌ها');
        }
        $page = DB::paginate("SELECT c.* FROM certificates c WHERE $w ORDER BY c.id DESC", $p, 30);
        $candidates = can('certificates.create') ? DB::all("SELECT e.user_id, e.course_id, u.first_name, u.last_name, co.title FROM enrollments e JOIN users u ON u.id = e.user_id JOIN courses co ON co.id = e.course_id WHERE e.status = 'completed' AND NOT EXISTS (SELECT 1 FROM certificates c WHERE c.user_id = e.user_id AND c.course_id = e.course_id AND c.revoked_at IS NULL)" . Scope::userSql('e.user_id')['sql'] . ' ORDER BY e.completed_at DESC LIMIT 300', Scope::userSql('e.user_id')['params']) : [];
        return view('admin/reviews/certificates', ['title' => 'گواهی‌ها', 'page' => $page, 'candidates' => $candidates]);
    }

    public function issueCertificate(): never
    {
        [$uid, $cid] = array_map('intval', explode(':', Request::str('pair') . ':0'));
        Scope::authorizeUser($uid);
        if (!$uid || !$cid) { flash('danger', 'فراگیر و دوره را انتخاب کنید.'); redirect('/admin/certificates'); }
        Enrollment::issueCertificate($uid, $cid, true);
        flash('success', 'گواهی صادر شد.');
        redirect('/admin/certificates');
    }

    public function revokeCertificate(int $id): never
    {
        $c = DB::find('certificates', $id) ?? throw new HttpException(404);
        Scope::authorizeUser((int)$c['user_id']);
        DB::update('certificates', ['revoked_at' => now(), 'revoke_reason' => mb_substr(Request::str('reason'), 0, 250) ?: null], 'id = ?', [$id]);
        Audit::log('certificates.revoke', 'certificate', $id, 'success', ['code' => $c['code']]);
        flash('success', 'گواهی ابطال شد.');
        redirect('/admin/certificates');
    }
}
