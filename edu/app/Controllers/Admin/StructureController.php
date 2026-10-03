<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Audit;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Validator;
use App\Services\Targeting;

/** Groups, organizational structure, levels & classifications, learning categories — nothing is hard-coded. */
final class StructureController
{
    public const ICONS = ['users', 'store', 'briefcase', 'globe', 'building-2', 'layers', 'target', 'rocket', 'star', 'flag', 'map-pin', 'package', 'graduation-cap', 'book-open', 'sparkles', 'trending-up', 'message-square', 'video', 'box', 'chart-pie', 'lightbulb', 'zap', 'medal', 'crown', 'user-cog', 'layout-grid'];

    // ------------------------------------------------------------------ groups
    public function groups(): string
    {
        $rows = DB::all('SELECT g.*, (SELECT COUNT(*) FROM group_members gm WHERE gm.group_id = g.id) members, (SELECT COUNT(*) FROM levels l WHERE l.group_id = g.id) levels_n, u.first_name, u.last_name FROM `groups` g LEFT JOIN users u ON u.id = g.supervisor_id ORDER BY g.segment, g.sort, g.name');
        $tree = [];
        foreach ($rows as $r) $tree[(int)($r['parent_id'] ?? 0)][] = $r;
        return view('admin/structure/groups', ['title' => 'گروه‌ها', 'tree' => $tree, 'rows' => $rows, 'icons' => self::ICONS, 'supervisors' => $this->supervisors()]);
    }

    private function supervisors(): array
    {
        return DB::all("SELECT DISTINCT u.id, u.first_name, u.last_name FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id WHERE u.deleted_at IS NULL AND r.is_learner = 0 ORDER BY u.last_name");
    }

    public function group(int $id): string
    {
        $g = DB::find('groups', $id) ?? throw new HttpException(404);
        $members = DB::paginate('SELECT u.*, (SELECT ROUND(AVG(progress_pct)) FROM enrollments e WHERE e.user_id = u.id) prog FROM users u JOIN group_members gm ON gm.user_id = u.id WHERE gm.group_id = ? AND u.deleted_at IS NULL ORDER BY u.last_name', [$id], 30);
        $levels = DB::all('SELECT l.*, (SELECT COUNT(*) FROM user_levels ul WHERE ul.level_id = l.id) n FROM levels l WHERE l.group_id = ? ORDER BY rank_no', [$id]);
        $candidates = can('groups.assign') ? DB::all('SELECT id, first_name, last_name, mobile FROM users WHERE deleted_at IS NULL AND id NOT IN (SELECT user_id FROM group_members WHERE group_id = ?) ORDER BY last_name LIMIT 3000', [$id]) : [];
        $children = DB::all('SELECT * FROM `groups` WHERE parent_id = ? ORDER BY sort', [$id]);
        $parent = $g['parent_id'] ? DB::find('groups', (int)$g['parent_id']) : null;
        return view('admin/structure/group', ['title' => $g['name'], 'g' => $g, 'members' => $members, 'levels' => $levels, 'candidates' => $candidates, 'children' => $children, 'parent' => $parent, 'icons' => self::ICONS, 'supervisors' => $this->supervisors(), 'all' => DB::all('SELECT id, name FROM `groups` WHERE id <> ? ORDER BY name', [$id])]);
    }

    public function saveGroup(?int $id = null): never
    {
        $d = Validator::validate(['name' => 'required|max:150', 'description' => 'max:500', 'segment' => 'required|in:merchant,employee,agent,custom'], ['name' => 'نام گروه', 'segment' => 'نوع']);
        $row = [
            'name' => $d['name'], 'description' => $d['description'] ?? null, 'color' => preg_match('/^#[0-9a-f]{6}$/i', Request::str('color')) ? Request::str('color') : '#4f46e5',
            'icon' => in_array(Request::str('icon'), self::ICONS, true) ? Request::str('icon') : 'users', 'supervisor_id' => Request::intOrNull('supervisor_id'), 'sort' => Request::int('sort'),
        ];
        $parent = Request::intOrNull('parent_id');
        if ($id) {
            $g = DB::find('groups', $id) ?? throw new HttpException(404);
            if ((int)$g['is_system'] !== 1) { $row['segment'] = $d['segment']; $row['parent_id'] = $parent && $parent !== $id && !in_array($parent, Targeting::descendants('groups', $id), true) ? $parent : null; }
            DB::update('groups', $row, 'id = ?', [$id]);
            Audit::log('groups.update', 'group', $id);
        } else {
            $row += ['segment' => $d['segment'], 'parent_id' => $parent, 'created_at' => now()];
            $id = DB::insert('groups', $row);
            Audit::log('groups.create', 'group', $id, 'success', ['name' => $d['name']]);
        }
        flash('success', 'گروه ذخیره شد.');
        redirect('/admin/groups/' . $id);
    }

    public function deleteGroup(int $id): never
    {
        $g = DB::find('groups', $id) ?? throw new HttpException(404);
        if ((int)$g['is_system'] === 1) throw new HttpException(403, 'گروه‌های اصلی (تاجران، کارمندان، نمایندگان) قابل حذف نیستند.');
        if (DB::value('SELECT 1 FROM `groups` WHERE parent_id = ?', [$id])) { flash('danger', 'ابتدا زیرگروه‌ها را حذف یا منتقل کنید.'); back(); }
        DB::delete('groups', 'id = ?', [$id]);
        Audit::log('groups.delete', 'group', $id, 'success', ['name' => $g['name']]);
        flash('success', 'گروه حذف شد.');
        redirect('/admin/groups');
    }

    public function groupMembers(int $id): never
    {
        DB::find('groups', $id) ?? throw new HttpException(404);
        $add = Request::ints('add_users');
        foreach ($add as $uid) { DB::run('INSERT IGNORE INTO group_members (group_id, user_id, joined_at) VALUES (?,?,?)', [$id, $uid, now()]); Targeting::syncUser($uid); }
        $rm = Request::ints('remove_users');
        if ($rm) DB::run('DELETE FROM group_members WHERE group_id = ? AND user_id IN (' . DB::in($rm) . ')', array_merge([$id], $rm));
        Audit::log('groups.members', 'group', $id, 'success', ['added' => count($add), 'removed' => count($rm)]);
        flash('success', 'اعضای گروه به‌روزرسانی شد.');
        redirect('/admin/groups/' . $id);
    }

    // ------------------------------------------------------------------ organizational structure
    public function org(): string
    {
        $types = DB::all('SELECT t.*, (SELECT COUNT(*) FROM org_units o WHERE o.type_id = t.id) n FROM org_unit_types t ORDER BY sort');
        $units = DB::all('SELECT o.*, t.name AS type_name, t.icon, (SELECT COUNT(*) FROM user_org_units x WHERE x.org_unit_id = o.id) members, u.first_name, u.last_name FROM org_units o JOIN org_unit_types t ON t.id = o.type_id LEFT JOIN users u ON u.id = o.manager_id ORDER BY o.sort, o.name');
        $tree = [];
        foreach ($units as $u) $tree[(int)($u['parent_id'] ?? 0)][] = $u;
        return view('admin/structure/org', ['title' => 'ساختار سازمانی', 'types' => $types, 'units' => $units, 'tree' => $tree, 'managers' => $this->supervisors()]);
    }

    public function saveUnit(?int $id = null): never
    {
        $d = Validator::validate(['name' => 'required|max:150', 'type_id' => 'required|int', 'code' => 'max:50'], ['name' => 'نام', 'type_id' => 'نوع', 'code' => 'کد']);
        $parent = Request::intOrNull('parent_id');
        if ($id && $parent && ($parent === $id || in_array($parent, Targeting::descendants('org_units', $id), true))) $parent = null;
        $row = ['name' => $d['name'], 'type_id' => (int)$d['type_id'], 'code' => $d['code'] ?? null, 'parent_id' => $parent, 'manager_id' => Request::intOrNull('manager_id'), 'sort' => Request::int('sort')];
        if ($id) { DB::update('org_units', $row, 'id = ?', [$id]); Audit::log('org.update', 'org_unit', $id); }
        else { $id = DB::insert('org_units', $row + ['created_at' => now()]); Audit::log('org.create', 'org_unit', $id, 'success', ['name' => $d['name']]); }
        flash('success', 'واحد سازمانی ذخیره شد.');
        redirect('/admin/org');
    }

    public function deleteUnit(int $id): never
    {
        if (DB::value('SELECT 1 FROM org_units WHERE parent_id = ?', [$id])) { flash('danger', 'این واحد زیرمجموعه دارد؛ ابتدا زیرمجموعه‌ها را حذف یا منتقل کنید.'); redirect('/admin/org'); }
        DB::delete('org_units', 'id = ?', [$id]);
        Audit::log('org.delete', 'org_unit', $id);
        flash('success', 'واحد حذف شد.');
        redirect('/admin/org');
    }

    public function unit(int $id): string
    {
        $o = DB::one('SELECT o.*, t.name AS type_name FROM org_units o JOIN org_unit_types t ON t.id = o.type_id WHERE o.id = ?', [$id]) ?? throw new HttpException(404);
        $ids = Targeting::descendants('org_units', $id);
        $members = DB::all('SELECT DISTINCT u.*, (SELECT ROUND(AVG(progress_pct)) FROM enrollments e WHERE e.user_id = u.id) prog, (SELECT GROUP_CONCAT(ou.name SEPARATOR \'، \') FROM user_org_units x2 JOIN org_units ou ON ou.id = x2.org_unit_id WHERE x2.user_id = u.id) units FROM users u JOIN user_org_units x ON x.user_id = u.id WHERE x.org_unit_id IN (' . DB::in($ids) . ') AND u.deleted_at IS NULL ORDER BY u.last_name', $ids);
        $candidates = can('org.assign') ? DB::all("SELECT id, first_name, last_name, mobile FROM users WHERE deleted_at IS NULL AND id NOT IN (SELECT user_id FROM user_org_units WHERE org_unit_id = ?) ORDER BY last_name LIMIT 3000", [$id]) : [];
        return view('admin/structure/unit', ['title' => $o['name'], 'o' => $o, 'members' => $members, 'candidates' => $candidates]);
    }

    public function unitMembers(int $id): never
    {
        $add = Request::ints('add_users');
        foreach ($add as $uid) { DB::run('INSERT IGNORE INTO user_org_units (user_id, org_unit_id) VALUES (?,?)', [$uid, $id]); Targeting::syncUser($uid); }
        $rm = Request::ints('remove_users');
        if ($rm) DB::run('DELETE FROM user_org_units WHERE org_unit_id = ? AND user_id IN (' . DB::in($rm) . ')', array_merge([$id], $rm));
        Audit::log('org.members', 'org_unit', $id, 'success', ['added' => count($add), 'removed' => count($rm)]);
        flash('success', 'اعضا به‌روزرسانی شد.');
        redirect('/admin/org/units/' . $id);
    }

    public function saveType(?int $id = null): never
    {
        $d = Validator::validate(['name' => 'required|max:100'], ['name' => 'نام سطح ساختار']);
        $row = ['name' => $d['name'], 'sort' => Request::int('sort'), 'icon' => in_array(Request::str('icon'), self::ICONS, true) ? Request::str('icon') : 'building-2'];
        if ($id) DB::update('org_unit_types', $row, 'id = ?', [$id]); else DB::insert('org_unit_types', $row + ['created_at' => now()]);
        Audit::log('org.type_save', 'org_unit_type', $id);
        flash('success', 'سطح ساختار سازمانی ذخیره شد.');
        redirect('/admin/org');
    }

    public function deleteType(int $id): never
    {
        if (DB::value('SELECT 1 FROM org_units WHERE type_id = ?', [$id])) { flash('danger', 'این سطح در واحدهای سازمانی استفاده شده است.'); redirect('/admin/org'); }
        DB::delete('org_unit_types', 'id = ?', [$id]);
        flash('success', 'حذف شد.');
        redirect('/admin/org');
    }

    // ------------------------------------------------------------------ levels & classifications
    public function levels(): string
    {
        $groups = DB::all('SELECT * FROM `groups` ORDER BY segment, parent_id IS NOT NULL, sort');
        $levels = [];
        foreach (DB::all('SELECT l.*, (SELECT COUNT(*) FROM user_levels ul WHERE ul.level_id = l.id) n FROM levels l ORDER BY rank_no') as $l) $levels[$l['group_id']][] = $l;
        $taxes = DB::all('SELECT * FROM taxonomies ORDER BY segment, sort');
        foreach ($taxes as &$t) $t['terms'] = DB::all('SELECT tt.*, (SELECT COUNT(*) FROM user_terms ut WHERE ut.term_id = tt.id) n FROM taxonomy_terms tt WHERE tt.taxonomy_id = ? ORDER BY sort, name', [(int)$t['id']]);
        unset($t);
        return view('admin/structure/levels', ['title' => 'سطح‌بندی و طبقه‌بندی', 'groups' => $groups, 'levels' => $levels, 'taxes' => $taxes]);
    }

    public function saveLevel(?int $id = null): never
    {
        $d = Validator::validate(['name' => 'required|max:100', 'group_id' => 'required|int', 'rank_no' => 'int'], ['name' => 'نام سطح', 'group_id' => 'گروه']);
        $row = ['name' => $d['name'], 'group_id' => (int)$d['group_id'], 'rank_no' => (int)($d['rank_no'] ?? 1), 'color' => preg_match('/^#[0-9a-f]{6}$/i', Request::str('color')) ? Request::str('color') : '#0ea5e9', 'description' => Request::str('description') ?: null];
        if ($id) DB::update('levels', $row, 'id = ?', [$id]); else DB::insert('levels', $row + ['created_at' => now()]);
        Audit::log('levels.save', 'level', $id);
        flash('success', 'سطح ذخیره شد.');
        redirect('/admin/levels');
    }

    public function deleteLevel(int $id): never
    {
        DB::delete('levels', 'id = ?', [$id]);
        Audit::log('levels.delete', 'level', $id);
        flash('success', 'سطح حذف شد.');
        redirect('/admin/levels');
    }

    public function saveTaxonomy(?int $id = null): never
    {
        $d = Validator::validate(['name' => 'required|max:100', 'segment' => 'required|in:merchant,employee,agent,custom'], ['name' => 'نام طبقه‌بندی', 'segment' => 'گروه']);
        if ($id) DB::update('taxonomies', ['name' => $d['name'], 'sort' => Request::int('sort')], 'id = ?', [$id]);
        else DB::insert('taxonomies', ['name' => $d['name'], 'segment' => $d['segment'], 'slug' => 'tx_' . substr(bin2hex(random_bytes(4)), 0, 8), 'sort' => Request::int('sort'), 'created_at' => now()]);
        flash('success', 'طبقه‌بندی ذخیره شد.');
        redirect('/admin/levels#tax');
    }

    public function deleteTaxonomy(int $id): never
    {
        DB::delete('taxonomies', 'id = ?', [$id]);
        Audit::log('levels.taxonomy_delete', 'taxonomy', $id);
        flash('success', 'طبقه‌بندی حذف شد.');
        redirect('/admin/levels#tax');
    }

    public function saveTerm(?int $id = null): never
    {
        $d = Validator::validate(['name' => 'required|max:150'], ['name' => 'عنوان']);
        if ($id) DB::update('taxonomy_terms', ['name' => $d['name']], 'id = ?', [$id]);
        else DB::insert('taxonomy_terms', ['taxonomy_id' => Request::int('taxonomy_id'), 'name' => $d['name'], 'sort' => 0, 'created_at' => now()]);
        flash('success', 'ذخیره شد.');
        redirect('/admin/levels#tax');
    }

    public function deleteTerm(int $id): never
    {
        DB::delete('taxonomy_terms', 'id = ?', [$id]);
        flash('success', 'حذف شد.');
        redirect('/admin/levels#tax');
    }

    // ------------------------------------------------------------------ categories
    public function categories(): string
    {
        $rows = DB::all('SELECT c.*, (SELECT COUNT(*) FROM courses x WHERE x.category_id = c.id AND x.deleted_at IS NULL) courses FROM categories c ORDER BY c.segment, c.sort, c.name');
        $tree = [];
        foreach ($rows as $r) $tree[(int)($r['parent_id'] ?? 0)][] = $r;
        return view('admin/structure/categories', ['title' => 'موضوعات آموزشی', 'rows' => $rows, 'tree' => $tree, 'icons' => self::ICONS]);
    }

    public function saveCategory(?int $id = null): never
    {
        $d = Validator::validate(['name' => 'required|max:150', 'description' => 'max:500'], ['name' => 'نام موضوع']);
        $seg = Request::str('segment');
        $row = [
            'name' => $d['name'], 'description' => $d['description'] ?? null, 'segment' => in_array($seg, ['merchant', 'employee', 'agent'], true) ? $seg : null,
            'parent_id' => Request::intOrNull('parent_id') !== $id ? Request::intOrNull('parent_id') : null, 'icon' => in_array(Request::str('icon'), self::ICONS, true) ? Request::str('icon') : 'book-open',
            'color' => preg_match('/^#[0-9a-f]{6}$/i', Request::str('color')) ? Request::str('color') : '#6366f1', 'sort' => Request::int('sort'),
        ];
        if ($id) DB::update('categories', $row, 'id = ?', [$id]); else $id = DB::insert('categories', $row + ['created_at' => now()]);
        Audit::log('categories.save', 'category', $id);
        flash('success', 'موضوع ذخیره شد.');
        redirect('/admin/categories');
    }

    public function deleteCategory(int $id): never
    {
        if (DB::value('SELECT 1 FROM courses WHERE category_id = ? AND deleted_at IS NULL', [$id]) || DB::value('SELECT 1 FROM categories WHERE parent_id = ?', [$id])) { flash('danger', 'این موضوع دارای دوره یا زیرموضوع است.'); redirect('/admin/categories'); }
        DB::delete('categories', 'id = ?', [$id]);
        Audit::log('categories.delete', 'category', $id);
        flash('success', 'موضوع حذف شد.');
        redirect('/admin/categories');
    }
}
