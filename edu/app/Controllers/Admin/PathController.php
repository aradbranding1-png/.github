<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Validator;
use App\Services\PathService;

/** Learning paths (مسیر آموزشی مرحله‌ای) with pass conditions per step */
final class PathController
{
    public const AUDIENCES = ['merchant' => 'تاجران', 'employee' => 'کارمندان', 'agent' => 'نمایندگان', 'all' => 'مشترک (همه گروه‌ها)'];

    public function index(): string
    {
        $all = DB::all('SELECT p.*, g.name AS group_name, g.segment AS group_segment, (SELECT COUNT(*) FROM path_steps s WHERE s.path_id = p.id) steps, (SELECT COUNT(*) FROM path_enrollments pe WHERE pe.path_id = p.id) learners, (SELECT COUNT(*) FROM path_enrollments pe WHERE pe.path_id = p.id AND pe.status = \'completed\') done FROM learning_paths p LEFT JOIN `groups` g ON g.id = p.group_id WHERE p.deleted_at IS NULL ORDER BY p.sort, p.id');
        // every path belongs to one audience: a segment (merchant / employee / agent) or shared by all
        $counts = array_fill_keys(array_keys(self::AUDIENCES), 0);
        foreach ($all as &$p) { $p['audience'] = \App\Controllers\LearnController::pathAudience($p); $counts[$p['audience']] = ($counts[$p['audience']] ?? 0) + 1; }
        unset($p);
        $seg = Request::str('seg', 'merchant');
        if (!isset(self::AUDIENCES[$seg])) $seg = 'merchant';
        $rows = array_values(array_filter($all, fn($p) => $p['audience'] === $seg));
        return view('admin/paths/index', ['title' => 'مسیرهای آموزشی', 'rows' => $rows, 'seg' => $seg, 'counts' => $counts, 'audiences' => self::AUDIENCES,
            'groups' => DB::pairs('SELECT id, name FROM `groups` ORDER BY sort')]);
    }

    public function save(?int $id = null): never
    {
        $d = Validator::validate(['title' => 'required|max:200', 'description' => 'max:3000'], ['title' => 'عنوان مسیر']);
        $seg = Request::str('target_segment');
        $row = ['title' => $d['title'], 'description' => $d['description'] ?? null, 'target_segment' => in_array($seg, ['merchant', 'employee', 'agent'], true) ? $seg : null, 'group_id' => Request::intOrNull('group_id'), 'color' => preg_match('/^#[0-9a-f]{6}$/i', Request::str('color')) ? Request::str('color') : '#6366f1'];
        if ($id) {
            DB::find('learning_paths', $id) ?? throw new HttpException(404);
            if (can('paths.publish') && in_array(Request::str('status'), ['draft', 'published', 'archived'], true)) $row['status'] = Request::str('status');
            DB::update('learning_paths', $row + ['updated_at' => now()], 'id = ?', [$id]);
            Audit::log('paths.update', 'path', $id);
        } else {
            $sort = (int)DB::value('SELECT COALESCE(MAX(sort), 0) + 1 FROM learning_paths WHERE deleted_at IS NULL');
            $id = DB::insert('learning_paths', $row + ['status' => 'draft', 'sort' => $sort, 'created_by' => Auth::id(), 'created_at' => now()]);
            Audit::log('paths.create', 'path', $id);
        }
        flash('success', 'مسیر ذخیره شد' . ($row['target_segment'] ? ' — مخصوص ' . label('segment', $row['target_segment']) : ' — مشترک برای همه گروه‌ها') . '. حالا مراحل (دوره‌ها) را تعریف کنید.');
        redirect('/admin/paths/' . $id);
    }

    /** Copy a path with all its steps for another group, to build that group's own version from it */
    public function copy(int $id): never
    {
        $p = DB::one('SELECT * FROM learning_paths WHERE id = ? AND deleted_at IS NULL', [$id]) ?? throw new HttpException(404);
        $seg = Request::str('target_segment');
        $seg = in_array($seg, ['merchant', 'employee', 'agent'], true) ? $seg : null;
        $title = trim(Request::str('title')) ?: $p['title'];
        $newId = DB::transaction(function () use ($p, $seg, $title) {
            $sort = (int)DB::value('SELECT COALESCE(MAX(sort), 0) + 1 FROM learning_paths WHERE deleted_at IS NULL');
            $nid = DB::insert('learning_paths', ['title' => mb_substr($title, 0, 200), 'description' => $p['description'], 'target_segment' => $seg, 'group_id' => null,
                'color' => $p['color'], 'status' => 'draft', 'sort' => $sort, 'created_by' => Auth::id(), 'created_at' => now()]);
            foreach (DB::all('SELECT * FROM path_steps WHERE path_id = ? ORDER BY sort, id', [(int)$p['id']]) as $s) {
                unset($s['id']);
                $s['path_id'] = $nid;
                DB::insert('path_steps', $s);
            }
            return $nid;
        });
        Audit::log('paths.copy', 'path', (int)$newId, 'success', ['from' => $id, 'segment' => $seg]);
        flash('success', 'یک نسخه از مسیر برای «' . ($seg ? label('segment', $seg) : 'همه گروه‌ها') . '» ساخته شد (پیش‌نویس). مراحل را به دلخواه تغییر دهید و سپس منتشر کنید.');
        redirect('/admin/paths/' . $newId);
    }

    /** Drag & drop order of the paths (first = first stop of the learners' roadmap) */
    public function order(): never
    {
        // the list on screen is one group's paths: re-use the slots those paths already hold, so other groups keep their order
        $current = DB::pairs('SELECT id, sort FROM learning_paths WHERE deleted_at IS NULL');
        $ids = array_values(array_filter(array_unique(Request::ints('ids')), fn($pid) => isset($current[$pid])));
        $slots = array_map(fn($pid) => (int)$current[$pid], $ids);
        sort($slots);
        if (count(array_unique($slots)) < count($slots)) { // duplicates (old data) → renumber everything first
            $k = 0;
            foreach (DB::column('SELECT id FROM learning_paths WHERE deleted_at IS NULL ORDER BY sort, id') as $pid) $current[(int)$pid] = ++$k;
            foreach ($current as $pid => $sv) DB::update('learning_paths', ['sort' => $sv], 'id = ?', [$pid]);
            $slots = array_map(fn($pid) => (int)$current[$pid], $ids);
            sort($slots);
        }
        $n = 0;
        DB::transaction(function () use ($ids, $slots, &$n) {
            foreach ($ids as $i => $pid) { DB::update('learning_paths', ['sort' => $slots[$i]], 'id = ?', [$pid]); $n++; }
        });
        Audit::log('paths.reorder', 'path', null, 'success', ['n' => $n]);
        json_out(['ok' => true, 'n' => $n]);
    }

    /** Drag & drop order of a path's steps */
    public function stepOrder(int $id): never
    {
        DB::one('SELECT id FROM learning_paths WHERE id = ? AND deleted_at IS NULL', [$id]) ?? throw new HttpException(404);
        $valid = array_map('intval', DB::column('SELECT id FROM path_steps WHERE path_id = ?', [$id]));
        $n = 0;
        DB::transaction(function () use ($valid, &$n) {
            foreach (Request::ints('ids') as $sid) if (in_array($sid, $valid, true)) DB::update('path_steps', ['sort' => ++$n], 'id = ?', [$sid]);
        });
        $this->resync($id);
        Audit::log('paths.reorder_steps', 'path', $id);
        json_out(['ok' => true, 'n' => $n]);
    }

    public function show(int $id): string
    {
        $p = DB::one('SELECT * FROM learning_paths WHERE id = ? AND deleted_at IS NULL', [$id]) ?? throw new HttpException(404);
        $steps = PathService::steps($id);
        $courses = DB::all("SELECT id, title, status FROM courses WHERE deleted_at IS NULL ORDER BY title");
        $learners = DB::all('SELECT pe.*, u.first_name, u.last_name, u.avatar_path FROM path_enrollments pe JOIN users u ON u.id = pe.user_id WHERE pe.path_id = ? ORDER BY pe.progress_pct DESC LIMIT 100', [$id]);
        $dist = DB::pairs('SELECT current_step, COUNT(*) FROM path_enrollments WHERE path_id = ? AND status <> \'completed\' GROUP BY current_step', [$id]);
        return view('admin/paths/show', ['title' => $p['title'], 'p' => $p, 'steps' => $steps, 'courses' => $courses, 'learners' => $learners, 'dist' => $dist, 'groups' => DB::pairs('SELECT id, name FROM `groups` ORDER BY sort')]);
    }

    public function destroy(int $id): never
    {
        DB::update('learning_paths', ['deleted_at' => now(), 'status' => 'archived'], 'id = ?', [$id]);
        Audit::log('paths.delete', 'path', $id);
        flash('success', 'مسیر حذف شد.');
        redirect('/admin/paths');
    }

    private function stepRow(): array
    {
        return [
            'title' => mb_substr(Request::str('title'), 0, 200) ?: null,
            'min_progress' => max(0, min(100, Request::int('min_progress', 100))),
            'min_score' => Request::str('min_score') !== '' ? max(0, min(100, (float)Request::str('min_score'))) : null,
            'require_exercises' => Request::bool('require_exercises') ? 1 : 0,
            'require_evaluation' => Request::bool('require_evaluation') ? 1 : 0,
        ];
    }

    public function addStep(int $id): never
    {
        $cid = Request::int('course_id');
        if (!$cid) { flash('danger', 'دوره را انتخاب کنید.'); redirect('/admin/paths/' . $id); }
        $sort = (int)DB::value('SELECT COALESCE(MAX(sort),0) + 1 FROM path_steps WHERE path_id = ?', [$id]);
        DB::insert('path_steps', $this->stepRow() + ['path_id' => $id, 'course_id' => $cid, 'sort' => $sort]);
        $this->resync($id);
        flash('success', 'مرحله اضافه شد.');
        redirect('/admin/paths/' . $id);
    }

    public function updateStep(int $id): never
    {
        $s = DB::find('path_steps', $id) ?? throw new HttpException(404);
        DB::update('path_steps', $this->stepRow(), 'id = ?', [$id]);
        $this->resync((int)$s['path_id']);
        flash('success', 'شرایط عبور مرحله ذخیره شد.');
        redirect('/admin/paths/' . $s['path_id']);
    }

    public function moveStep(int $id): never
    {
        $s = DB::find('path_steps', $id) ?? throw new HttpException(404);
        $ids = array_map('intval', DB::column('SELECT id FROM path_steps WHERE path_id = ? ORDER BY sort, id', [(int)$s['path_id']]));
        $pos = array_search($id, $ids, true);
        $swap = $pos + (Request::str('dir') === 'up' ? -1 : 1);
        if (isset($ids[$swap])) [$ids[$pos], $ids[$swap]] = [$ids[$swap], $ids[$pos]];
        foreach ($ids as $i => $sid) DB::update('path_steps', ['sort' => $i + 1], 'id = ?', [$sid]);
        $this->resync((int)$s['path_id']);
        redirect('/admin/paths/' . $s['path_id']);
    }

    public function deleteStep(int $id): never
    {
        $s = DB::find('path_steps', $id) ?? throw new HttpException(404);
        DB::delete('path_steps', 'id = ?', [$id]);
        $this->resync((int)$s['path_id']);
        flash('success', 'مرحله حذف شد.');
        redirect('/admin/paths/' . $s['path_id']);
    }

    /** Re-evaluate learners after the path structure changes (new steps get enrolled, locks recomputed). */
    private function resync(int $pathId): void
    {
        $users = array_map('intval', DB::column('SELECT user_id FROM path_enrollments WHERE path_id = ?', [$pathId]));
        if (count($users) > 300) return; // large paths are re-synced by cron
        foreach ($users as $uid) PathService::enroll($uid, $pathId, ['source' => 'path']);
    }
}
