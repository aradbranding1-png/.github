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
use App\Services\Growth;
use App\Services\Scope;
use App\Services\Targeting;

/** Manual assignment, automatic Rule Engine, growth stages and training needs */
final class AssignmentController
{
    private function targetsData(): array
    {
        $us = Scope::userSql('id');
        return [
            'users' => DB::all("SELECT id, first_name, last_name, mobile FROM users WHERE deleted_at IS NULL AND status = 'active'" . $us['sql'] . ' ORDER BY last_name LIMIT 5000', $us['params']),
            'groups' => DB::pairs('SELECT id, name FROM `groups` ORDER BY segment, sort, name'),
            'orgs' => DB::pairs("SELECT o.id, CONCAT(t.name, ': ', o.name) FROM org_units o JOIN org_unit_types t ON t.id = o.type_id ORDER BY t.sort, o.name"),
            'roles' => DB::pairs('SELECT id, name FROM roles WHERE is_root = 0 ORDER BY sort'),
            'levels' => DB::pairs("SELECT l.id, CONCAT(g.name, ' — ', l.name) FROM levels l JOIN `groups` g ON g.id = l.group_id ORDER BY g.sort, l.rank_no"),
            'terms' => DB::pairs("SELECT tt.id, CONCAT(t.name, ': ', tt.name) FROM taxonomy_terms tt JOIN taxonomies t ON t.id = tt.taxonomy_id ORDER BY t.segment, t.sort, tt.name"),
            'courses' => DB::pairs("SELECT id, title FROM courses WHERE deleted_at IS NULL AND status IN ('published','draft') ORDER BY title"),
            'paths' => DB::pairs("SELECT id, title FROM learning_paths WHERE deleted_at IS NULL ORDER BY title"),
        ];
    }

    public function index(): string
    {
        $rows = DB::all('SELECT a.*, c.title AS course_title, p.title AS path_title, u.first_name, u.last_name FROM assignments a LEFT JOIN courses c ON c.id = a.course_id LEFT JOIN learning_paths p ON p.id = a.path_id LEFT JOIN users u ON u.id = a.created_by ORDER BY a.id DESC LIMIT 300');
        foreach ($rows as &$r) {
            $r['target_label'] = Targeting::targetLabel($r['target_type'], (int)$r['target_id']);
            if ($r['course_id']) $r['done'] = (int)DB::value("SELECT COUNT(*) FROM enrollments WHERE assignment_id = ? AND status = 'completed'", [(int)$r['id']]);
        }
        unset($r);
        return view('admin/assign/index', ['title' => 'تخصیص آموزش', 'rows' => $rows, 'preCourse' => Request::int('course_id'), 'prePath' => Request::int('path_id')] + $this->targetsData());
    }

    public function store(): never
    {
        $d = Validator::validate(['target_type' => 'required|in:user,group,org_unit,role,level,term', 'training_type' => 'required|in:mandatory,optional,supplementary,suggested', 'due_at' => 'date'], ['target_type' => 'نوع مخاطب', 'training_type' => 'نوع آموزش', 'due_at' => 'مهلت']);
        $courseIds = Request::ints('course_ids');
        $pathId = Request::intOrNull('path_id');
        if (!$courseIds && !$pathId) { flash('danger', 'حداقل یک دوره یا یک مسیر آموزشی انتخاب کنید.'); back(); }
        $type = $d['target_type'];
        $targets = $type === 'user' ? Request::ints('target_users') : Request::ints('target_' . $type);
        if (!$targets) { flash('danger', 'مخاطب تخصیص را انتخاب کنید.'); back(); }
        $scopeIds = Scope::userIds();
        if ($scopeIds !== null && $type !== 'user') throw new HttpException(403, 'شما فقط می‌توانید آموزش را به افراد تحت مسئولیت خود تخصیص دهید.');
        if ($scopeIds !== null) $targets = array_values(array_intersect($targets, $scopeIds));
        $due = Jalali::parse((string)($d['due_at'] ?? ''));
        $due = $due ? $due . ' 23:59:59' : null;
        $total = 0; $created = 0;
        $items = $pathId ? [['path_id' => $pathId, 'course_id' => null]] : array_map(fn($c) => ['course_id' => $c, 'path_id' => null], $courseIds);
        foreach ($items as $it) foreach ($targets as $tid) {
            $row = $it + ['target_type' => $type, 'target_id' => $tid, 'training_type' => $d['training_type'], 'due_at' => $due, 'note' => mb_substr(Request::str('note'), 0, 250) ?: null, 'created_by' => Auth::id(), 'created_at' => now()];
            $row['id'] = DB::insert('assignments', $row);
            $total += Targeting::applyAssignment($row);
            $created++;
        }
        Audit::log('assignments.create', 'assignment', null, 'success', ['type' => $type, 'targets' => count($targets), 'courses' => $courseIds, 'path' => $pathId, 'users' => $total]);
        flash('success', 'تخصیص انجام شد: ' . fa($created) . ' مورد تخصیص برای ' . fa($total) . ' نفر. اعضای جدید گروه/واحد نیز به صورت خودکار این آموزش را دریافت می‌کنند.');
        redirect('/admin/assignments');
    }

    public function reapply(int $id): never
    {
        $a = DB::find('assignments', $id) ?? throw new HttpException(404);
        $n = Targeting::applyAssignment($a);
        flash('success', 'تخصیص مجدداً برای ' . fa($n) . ' نفر اعمال شد.');
        redirect('/admin/assignments');
    }

    public function destroy(int $id): never
    {
        $a = DB::find('assignments', $id) ?? throw new HttpException(404);
        DB::update('assignments', ['is_active' => 0], 'id = ?', [$id]);
        $removed = 0;
        if (Request::bool('remove_unstarted')) $removed = DB::run("DELETE FROM enrollments WHERE assignment_id = ? AND status IN ('not_started','locked') AND progress_pct = 0", [$id])->rowCount();
        Audit::log('assignments.delete', 'assignment', $id, 'success', ['removed_enrollments' => $removed]);
        flash('success', 'تخصیص غیرفعال شد' . ($removed ? ' و ' . fa($removed) . ' ثبت‌نام شروع‌نشده حذف شد.' : '.'));
        redirect('/admin/assignments');
    }

    // ------------------------------------------------------------------ rules
    public function rules(): string
    {
        $rows = DB::all('SELECT r.*, c.title AS course_title, p.title AS path_title FROM assignment_rules r LEFT JOIN courses c ON c.id = r.course_id LEFT JOIN learning_paths p ON p.id = r.path_id ORDER BY r.id DESC');
        foreach ($rows as &$r) {
            $r['conds'] = json_decode((string)$r['conditions'], true) ?: [];
            $r['matches'] = count(Targeting::ruleUsers($r));
        }
        unset($r);
        return view('admin/assign/rules', ['title' => 'تخصیص خودکار', 'rows' => $rows]);
    }

    public function ruleForm(?int $id = null): string
    {
        $rule = $id ? (DB::find('assignment_rules', $id) ?? throw new HttpException(404)) : null;
        $data = $this->targetsData();
        $options = [
            'segment' => ['merchant' => 'تاجر', 'employee' => 'کارمند', 'agent' => 'نماینده'],
            'group' => $data['groups'], 'org_unit' => $data['orgs'], 'role' => $data['roles'], 'level' => $data['levels'], 'term' => $data['terms'],
        ];
        return view('admin/assign/rule_form', ['title' => $rule ? 'ویرایش قانون' : 'قانون جدید', 'rule' => $rule, 'conds' => $rule ? (json_decode((string)$rule['conditions'], true) ?: []) : [['field' => 'segment', 'op' => 'is', 'value' => 'employee']], 'options' => $options, 'preview' => $rule ? count(Targeting::ruleUsers($rule)) : null] + $data);
    }

    public function ruleSave(?int $id = null): never
    {
        $d = Validator::validate(['name' => 'required|max:150', 'training_type' => 'required|in:mandatory,optional,supplementary,suggested', 'due_days' => 'int'], ['name' => 'نام قانون', 'training_type' => 'نوع آموزش']);
        $conds = [];
        foreach ((array)($_POST['cond'] ?? []) as $c) {
            $val = (string)($c['value'] ?? '');
            if (!str_contains($val, ':')) continue;
            [$field, $v] = explode(':', $val, 2);
            if (!isset(Targeting::FIELDS[$field]) || $v === '') continue;
            $conds[] = ['field' => $field, 'op' => ($c['op'] ?? 'is') === 'is_not' ? 'is_not' : 'is', 'value' => $v];
        }
        if (!$conds) { flash('danger', 'حداقل یک شرط تعریف کنید.'); back(); }
        $row = [
            'name' => $d['name'], 'conditions' => json_encode($conds, JSON_UNESCAPED_UNICODE), 'match_type' => Request::str('match_type') === 'any' ? 'any' : 'all',
            'course_id' => Request::intOrNull('path_id') ? null : Request::intOrNull('course_id'), 'path_id' => Request::intOrNull('path_id'),
            'training_type' => $d['training_type'], 'due_days' => Request::intOrNull('due_days'), 'is_active' => Request::bool('is_active') ? 1 : 0,
        ];
        if (!$row['course_id'] && !$row['path_id']) { flash('danger', 'دوره یا مسیر آموزشی مقصد را انتخاب کنید.'); back(); }
        if ($id) DB::update('assignment_rules', $row, 'id = ?', [$id]);
        else $id = DB::insert('assignment_rules', $row + ['created_by' => Auth::id(), 'created_at' => now()]);
        Audit::log('rules.save', 'rule', $id, 'success', ['conditions' => $conds]);
        if (Request::bool('run_now') && can('rules.run') && $row['is_active']) {
            $n = Targeting::runRule(DB::find('assignment_rules', $id));
            flash('success', 'قانون ذخیره و اجرا شد؛ ' . fa($n) . ' ثبت‌نام جدید انجام شد.');
        } else {
            flash('success', 'قانون ذخیره شد. قوانین فعال هر شب و هنگام تغییر گروه/واحد/نقش کاربر به صورت خودکار اجرا می‌شوند.');
        }
        redirect('/admin/rules');
    }

    public function ruleRun(int $id): never
    {
        $r = DB::find('assignment_rules', $id) ?? throw new HttpException(404);
        $n = Targeting::runRule($r);
        Audit::log('rules.run', 'rule', $id, 'success', ['enrolled' => $n]);
        flash('success', 'قانون اجرا شد؛ ' . fa($n) . ' ثبت‌نام جدید.');
        redirect('/admin/rules');
    }

    public function ruleDelete(int $id): never
    {
        DB::delete('assignment_rules', 'id = ?', [$id]);
        Audit::log('rules.delete', 'rule', $id);
        flash('success', 'قانون حذف شد (ثبت‌نام‌های انجام‌شده باقی می‌مانند).');
        redirect('/admin/rules');
    }

    // ------------------------------------------------------------------ growth
    public function growth(): string
    {
        $groups = DB::all('SELECT * FROM `groups` ORDER BY parent_id IS NOT NULL, sort');
        $gid = Request::int('group') ?: (int)($groups[0]['id'] ?? 0);
        $group = $gid ? DB::find('groups', $gid) : null;
        $stages = $gid ? Growth::stagesFor($gid) : [];
        $counts = $gid ? DB::pairs('SELECT stage_id, COUNT(*) FROM user_growth WHERE group_id = ? GROUP BY stage_id', [$gid]) : [];
        $members = $gid ? (int)DB::value('SELECT COUNT(*) FROM group_members WHERE group_id = ?', [$gid]) : 0;
        $chart = ['type' => 'bar', 'labels' => array_column($stages, 'name'), 'series' => [['name' => 'تعداد افراد', 'data' => array_map(fn($s) => (int)($counts[$s['id']] ?? 0), $stages), 'color' => '#10b981']]];
        $us = Scope::userSql('u.id');
        $users = $gid ? DB::all('SELECT u.id, u.first_name, u.last_name FROM users u JOIN group_members gm ON gm.user_id = u.id WHERE gm.group_id = ? AND u.deleted_at IS NULL' . $us['sql'] . ' ORDER BY u.last_name LIMIT 3000', array_merge([$gid], $us['params'])) : [];
        $recent = $gid ? DB::all('SELECT h.*, u.first_name, u.last_name, s.name AS stage_name FROM growth_history h JOIN users u ON u.id = h.user_id JOIN growth_stages s ON s.id = h.stage_id WHERE h.group_id = ? ORDER BY h.id DESC LIMIT 15', [$gid]) : [];
        return view('admin/assign/growth', ['title' => 'نظام رشد قبلی (آرشیو)', 'groups' => $groups, 'group' => $group, 'stages' => $stages, 'counts' => $counts, 'members' => $members, 'chart' => $chart, 'users' => $users, 'recent' => $recent, 'courses' => DB::pairs('SELECT id, title FROM courses WHERE deleted_at IS NULL ORDER BY title'), 'icons' => StructureController::ICONS]);
    }

    public function saveStage(?int $id = null): never
    {
        $d = Validator::validate(['name' => 'required|max:150', 'group_id' => 'required|int', 'sort' => 'int', 'req_min_progress' => 'int', 'req_min_avg_score' => 'numeric', 'req_exercises' => 'int'], ['name' => 'نام مرحله', 'group_id' => 'گروه']);
        $row = [
            'group_id' => (int)$d['group_id'], 'name' => $d['name'], 'description' => Request::str('description') ?: null, 'sort' => Request::int('sort', 1),
            'color' => preg_match('/^#[0-9a-f]{6}$/i', Request::str('color')) ? Request::str('color') : '#10b981', 'icon' => in_array(Request::str('icon'), array_merge(StructureController::ICONS, ['mountain', 'flag']), true) ? Request::str('icon') : 'mountain',
            'req_course_ids' => implode(',', Request::ints('req_course_ids')) ?: null,
            'req_min_progress' => Request::str('req_min_progress') !== '' ? max(0, min(100, Request::int('req_min_progress'))) : null,
            'req_min_avg_score' => Request::str('req_min_avg_score') !== '' ? max(0, min(100, (float)Request::str('req_min_avg_score'))) : null,
            'req_exercises' => Request::intOrNull('req_exercises'), 'req_evaluation' => Request::bool('req_evaluation') ? 1 : 0, 'auto_promote' => Request::bool('auto_promote') ? 1 : 0,
        ];
        if ($id) DB::update('growth_stages', $row, 'id = ?', [$id]); else $id = DB::insert('growth_stages', $row + ['created_at' => now()]);
        Audit::log('growth.stage_save', 'growth_stage', $id);
        flash('success', 'مرحله رشد ذخیره شد.');
        redirect('/admin/growth/legacy?group=' . $row['group_id']);
    }

    public function deleteStage(int $id): never
    {
        $s = DB::find('growth_stages', $id) ?? throw new HttpException(404);
        if (DB::value('SELECT 1 FROM user_growth WHERE stage_id = ?', [$id])) { flash('danger', 'افرادی در این مرحله هستند؛ ابتدا آن‌ها را به مرحله دیگری منتقل کنید.'); redirect('/admin/growth/legacy?group=' . $s['group_id']); }
        DB::delete('growth_stages', 'id = ?', [$id]);
        Audit::log('growth.stage_delete', 'growth_stage', $id);
        flash('success', 'مرحله حذف شد.');
        redirect('/admin/growth/legacy?group=' . $s['group_id']);
    }

    public function promote(): never
    {
        $uid = Request::int('user_id');
        $stage = DB::find('growth_stages', Request::int('stage_id')) ?? throw new HttpException(404);
        Scope::authorizeUser($uid);
        Growth::setStage($uid, (int)$stage['group_id'], (int)$stage['id'], (int)Auth::id(), mb_substr(Request::str('note') ?: 'تعیین توسط مدیر', 0, 250));
        \App\Core\Notify::send($uid, 'growth', 'مرحله رشد شما: ' . $stage['name'], Request::str('note'), url('/learn/growth'));
        Audit::log('growth.promote', 'user', $uid, 'success', ['stage' => (int)$stage['id']]);
        flash('success', 'مرحله رشد کاربر ثبت شد.');
        redirect('/admin/growth/legacy?group=' . $stage['group_id']);
    }

    // ------------------------------------------------------------------ training needs
    public function needs(): string
    {
        $w = '1=1'; $p = [];
        if (($s = Request::str('status')) && in_array($s, ['open', 'planned', 'resolved'], true)) { $w .= ' AND n.status = ?'; $p[] = $s; }
        if ($c = Request::int('category')) { $w .= ' AND n.category_id = ?'; $p[] = $c; }
        $sc = Scope::userSql('n.user_id');
        $w .= $sc['sql']; $p = array_merge($p, $sc['params']);
        $rows = DB::all("SELECT n.*, u.first_name, u.last_name, c.name AS category_name, co.title AS course_title FROM training_needs n JOIN users u ON u.id = n.user_id LEFT JOIN categories c ON c.id = n.category_id LEFT JOIN courses co ON co.id = n.resolved_course_id WHERE $w ORDER BY FIELD(n.priority,'high','medium','low'), n.id DESC LIMIT 300", $p);
        $byCat = DB::all("SELECT COALESCE(c.name, 'بدون موضوع') name, COUNT(*) n FROM training_needs n LEFT JOIN categories c ON c.id = n.category_id WHERE n.status <> 'resolved'" . $sc['sql'] . ' GROUP BY c.name ORDER BY n DESC LIMIT 10', $sc['params']);
        $us = Scope::userSql('id');
        return view('admin/assign/needs', ['title' => 'نیازسنجی آموزشی', 'rows' => $rows, 'byCat' => $byCat,
            'cats' => DB::pairs('SELECT id, name FROM categories ORDER BY sort, name'), 'courses' => DB::pairs('SELECT id, title FROM courses WHERE deleted_at IS NULL ORDER BY title'),
            'users' => DB::all("SELECT id, first_name, last_name, mobile FROM users WHERE deleted_at IS NULL AND status = 'active'" . $us['sql'] . ' ORDER BY last_name LIMIT 5000', $us['params'])]);
    }

    public function saveNeed(?int $id = null): never
    {
        if ($id) {
            $n = DB::find('training_needs', $id) ?? throw new HttpException(404);
            Scope::authorizeUser((int)$n['user_id']);
            $st = Request::str('status');
            DB::update('training_needs', ['status' => in_array($st, ['open', 'planned', 'resolved'], true) ? $st : $n['status'], 'priority' => in_array(Request::str('priority'), ['low', 'medium', 'high'], true) ? Request::str('priority') : $n['priority'], 'resolved_course_id' => Request::intOrNull('resolved_course_id'), 'updated_at' => now()], 'id = ?', [$id]);
            if (Request::intOrNull('resolved_course_id') && Request::bool('assign')) \App\Services\Enrollment::enroll((int)$n['user_id'], Request::int('resolved_course_id'), ['source' => 'assignment', 'training_type' => 'supplementary']);
            flash('success', 'نیاز آموزشی به‌روزرسانی شد.');
        } else {
            $d = Validator::validate(['user_id' => 'required|int', 'title' => 'required|max:200', 'priority' => 'in:low,medium,high'], ['user_id' => 'کاربر', 'title' => 'عنوان']);
            Scope::authorizeUser((int)$d['user_id']);
            $id = DB::insert('training_needs', ['user_id' => (int)$d['user_id'], 'title' => $d['title'], 'description' => Request::str('description') ?: null, 'category_id' => Request::intOrNull('category_id'), 'priority' => $d['priority'] ?? 'medium', 'status' => 'open', 'source' => 'manager', 'created_by' => Auth::id(), 'created_at' => now()]);
            flash('success', 'نیاز آموزشی ثبت شد.');
        }
        Audit::log('needs.save', 'training_need', $id);
        redirect('/admin/needs');
    }

    public function deleteNeed(int $id): never
    {
        $n = DB::find('training_needs', $id) ?? throw new HttpException(404);
        Scope::authorizeUser((int)$n['user_id']);
        DB::delete('training_needs', 'id = ?', [$id]);
        flash('success', 'حذف شد.');
        redirect('/admin/needs');
    }
}
