<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Validator;

/** Independent question bank with categories, difficulty, level, tags and active flag */
final class QuestionController
{
    public function index(): string
    {
        $w = 'q.deleted_at IS NULL';
        $p = [];
        if (($s = Request::str('q')) !== '') { $w .= ' AND (q.text LIKE ? OR q.tags LIKE ?)'; $p[] = "%$s%"; $p[] = "%$s%"; }
        if ($c = Request::int('category')) { $w .= ' AND q.category_id = ?'; $p[] = $c; }
        if (($t = Request::str('type')) !== '' && isset(\App\Core\Labels::QTYPE[$t])) { $w .= ' AND q.type = ?'; $p[] = $t; }
        if ($d = Request::int('difficulty')) { $w .= ' AND q.difficulty = ?'; $p[] = $d; }
        if (Request::str('active') !== '') { $w .= ' AND q.is_active = ?'; $p[] = Request::int('active'); }
        $page = DB::paginate("SELECT q.*, c.name AS cat_name, (SELECT COUNT(*) FROM exam_questions eq WHERE eq.question_id = q.id) used,
                                (SELECT AVG(is_correct) FROM attempt_answers aa WHERE aa.question_id = q.id) rate
                               FROM questions q LEFT JOIN question_categories c ON c.id = q.category_id WHERE $w ORDER BY q.id DESC", $p, 25);
        $cats = DB::all('SELECT c.*, (SELECT COUNT(*) FROM questions q WHERE q.category_id = c.id AND q.deleted_at IS NULL) n FROM question_categories c ORDER BY c.name');
        return view('admin/questions/index', ['title' => 'بانک سؤال', 'page' => $page, 'cats' => $cats]);
    }

    private function formData(?array $q): array
    {
        return ['q' => $q, 'options' => $q ? DB::all('SELECT * FROM question_options WHERE question_id = ? ORDER BY sort, id', [(int)$q['id']]) : [],
            'cats' => DB::pairs('SELECT id, name FROM question_categories ORDER BY name'),
            'levels' => DB::pairs("SELECT l.id, CONCAT(g.name, ' — ', l.name) FROM levels l JOIN `groups` g ON g.id = l.group_id ORDER BY g.sort, l.rank_no")];
    }

    /** Exam the new question should be attached to (when opened from an exam page), or null */
    private function targetExam(): ?array
    {
        $eid = Request::int('exam') ?: Request::int('exam_id');
        if (!$eid || !can('exams.edit')) return null;
        $x = DB::one('SELECT id, title, course_id FROM exams WHERE id = ? AND deleted_at IS NULL', [$eid]);
        if ($x && $x['course_id']) \App\Services\Scope::authorizeCourse(['id' => $x['course_id']]);
        return $x ?: null;
    }

    public function create(): string
    {
        $exam = $this->targetExam();
        $n = $exam ? (int)DB::value('SELECT COUNT(*) FROM exam_questions WHERE exam_id = ?', [(int)$exam['id']]) : 0;
        return view('admin/questions/form', ['title' => 'سؤال جدید', 'forExam' => $exam, 'examCount' => $n] + $this->formData(null));
    }

    private function save(?int $id): int
    {
        $d = Validator::validate(['type' => 'required|in:single,multiple,truefalse,short,essay', 'text' => 'required|max:5000', 'score' => 'numeric', 'difficulty' => 'in:1,2,3', 'tags' => 'max:255'], ['type' => 'نوع سؤال', 'text' => 'متن سؤال', 'score' => 'نمره']);
        $type = $d['type'];
        $opts = [];
        $texts = (array)($_POST['opt_text'] ?? []);
        $correct = array_map('strval', (array)($_POST['opt_correct'] ?? []));
        foreach ($texts as $k => $t) {
            $t = trim(normalize_input((string)$t));
            if ($t === '') continue;
            $opts[] = ['text' => mb_substr($t, 0, 2000), 'is_correct' => $type === 'short' ? 1 : (in_array((string)$k, $correct, true) ? 1 : 0)];
        }
        $err = null;
        if (in_array($type, ['single', 'multiple', 'truefalse'], true)) {
            $nc = count(array_filter($opts, fn($o) => $o['is_correct']));
            if (count($opts) < 2) $err = 'حداقل دو گزینه لازم است.';
            elseif ($nc < 1) $err = 'گزینه صحیح را مشخص کنید.';
            elseif ($type !== 'multiple' && $nc > 1) $err = 'در این نوع سؤال فقط یک گزینه صحیح مجاز است.';
            if ($type === 'truefalse' && count($opts) !== 2) $err = 'سؤال درست/غلط دقیقاً دو گزینه دارد.';
        }
        if ($type === 'short' && !$opts) $err = 'حداقل یک پاسخ قابل قبول وارد کنید.';
        if ($err) { keep_old($_POST); flash('danger', $err); back(); }
        $row = [
            'type' => $type, 'text' => trim((string)$d['text']), 'explanation' => Request::str('explanation') ?: null, 'score' => max(0, (float)($d['score'] ?? 1)),
            'difficulty' => (int)($d['difficulty'] ?? 1), 'category_id' => Request::intOrNull('category_id'), 'level_id' => Request::intOrNull('level_id'),
            'tags' => ($d['tags'] ?? '') !== '' ? $d['tags'] : null, 'is_active' => Request::bool('is_active') ? 1 : 0,
        ];
        DB::transaction(function () use (&$id, $row, $opts, $type) {
            if ($id) { DB::update('questions', $row + ['updated_at' => now()], 'id = ?', [$id]); DB::delete('question_options', 'question_id = ?', [$id]); }
            else { $id = DB::insert('questions', $row + ['created_by' => Auth::id(), 'created_at' => now()]); }
            if ($type !== 'essay') foreach ($opts as $i => $o) DB::insert('question_options', ['question_id' => $id, 'text' => $o['text'], 'is_correct' => $o['is_correct'], 'sort' => $i]);
        });
        clear_old();
        return (int)$id;
    }

    public function store(): never
    {
        $exam = $this->targetExam();
        $id = $this->save(null);
        Audit::log('questions.create', 'question', $id);
        if ($exam) {
            $eid = (int)$exam['id'];
            $sort = (int)DB::value('SELECT COALESCE(MAX(sort),0) FROM exam_questions WHERE exam_id = ?', [$eid]);
            DB::run('INSERT IGNORE INTO exam_questions (exam_id, question_id, sort) VALUES (?,?,?)', [$eid, $id, $sort + 1]);
            Audit::log('exams.questions', 'exam', $eid, 'success', ['added' => $id]);
            $n = (int)DB::value('SELECT COUNT(*) FROM exam_questions WHERE exam_id = ?', [$eid]);
            if (Request::bool('add_another')) {
                flash('success', 'سؤال ذخیره و به آزمون «' . $exam['title'] . '» اضافه شد (' . fa($n) . ' سؤال). سؤال بعدی را وارد کنید.');
                redirect(url('/admin/questions/create', ['exam' => $eid]));
            }
            flash('success', 'سؤال ذخیره شد و آزمون اکنون ' . fa($n) . ' سؤال دارد.');
            redirect('/admin/exams/' . $eid . '#questions');
        }
        flash('success', 'سؤال به بانک اضافه شد.');
        redirect(Request::bool('add_another') ? '/admin/questions/create' : '/admin/questions');
    }

    public function edit(int $id): string
    {
        $q = DB::one('SELECT * FROM questions WHERE id = ? AND deleted_at IS NULL', [$id]) ?? throw new HttpException(404);
        return view('admin/questions/form', ['title' => 'ویرایش سؤال'] + $this->formData($q));
    }

    public function update(int $id): never
    {
        DB::one('SELECT id FROM questions WHERE id = ? AND deleted_at IS NULL', [$id]) ?? throw new HttpException(404);
        $this->save($id);
        Audit::log('questions.update', 'question', $id);
        flash('success', 'سؤال ذخیره شد. (نتایج آزمون‌های قبلی تغییر نمی‌کند)');
        redirect('/admin/questions');
    }

    public function destroy(int $id): never
    {
        DB::update('questions', ['deleted_at' => now(), 'is_active' => 0], 'id = ?', [$id]);
        DB::delete('exam_questions', 'question_id = ?', [$id]);
        Audit::log('questions.delete', 'question', $id);
        flash('success', 'سؤال حذف شد.');
        back();
    }

    public function saveCategory(): never
    {
        $d = Validator::validate(['name' => 'required|max:150'], ['name' => 'نام دسته']);
        DB::insert('question_categories', ['name' => $d['name'], 'parent_id' => Request::intOrNull('parent_id'), 'created_at' => now()]);
        flash('success', 'دسته سؤال ایجاد شد.');
        redirect('/admin/questions');
    }

    public function deleteCategory(int $id): never
    {
        DB::run('UPDATE questions SET category_id = NULL WHERE category_id = ?', [$id]);
        DB::delete('question_categories', 'id = ?', [$id]);
        flash('success', 'دسته حذف شد.');
        redirect('/admin/questions');
    }
}
