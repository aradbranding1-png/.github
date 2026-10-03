<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\DB;
use App\Core\Gate;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Validator;
use App\Services\Scope;
use App\Services\Targeting;

/**
 * نقش‌ها و سطوح دسترسی
 *  - ماتریس دسترسی برای هر نقش (ماژول × عملیات)
 *  - دسترسی فردی (اجازه/منع) برای هر کاربر، علاوه بر نقش‌ها
 *  - جلوگیری از افزایش سطح دسترسی: هیچ مدیری نمی‌تواند مجوزی بدهد که خودش ندارد
 *  - نقش مدیر کل غیرقابل ویرایش/حذف/اعطا است
 */
final class RoleController
{
    public const COLORS = ['primary' => 'نیلی', 'purple' => 'بنفش', 'info' => 'آبی', 'success' => 'سبز', 'warning' => 'نارنجی', 'danger' => 'قرمز', 'dark' => 'تیره', 'gray' => 'خاکستری'];

    public function index(): string
    {
        $roles = DB::all('SELECT r.*, (SELECT COUNT(*) FROM user_roles ur JOIN users u ON u.id = ur.user_id AND u.deleted_at IS NULL WHERE ur.role_id = r.id) AS users_count, (SELECT COUNT(*) FROM role_permissions rp WHERE rp.role_id = r.id) AS perms_count FROM roles r ORDER BY r.is_root DESC, r.is_learner, r.sort, r.id');
        $total = count(Gate::allPermissions()) - count(Gate::registry()['root_only']);
        return view('admin/roles/index', ['title' => 'نقش‌ها و سطوح دسترسی', 'roles' => $roles, 'totalPerms' => $total]);
    }

    public function create(): string
    {
        $roles = DB::all('SELECT id, name FROM roles WHERE is_root = 0 ORDER BY sort');
        return view('admin/roles/create', ['title' => 'ایجاد نقش جدید', 'roles' => $roles, 'colors' => self::COLORS]);
    }

    public function store(): never
    {
        $d = Validator::validate(['name' => 'required|max:100|unique:roles,name', 'description' => 'max:500', 'data_scope' => 'required|in:all,supervised,own', 'color' => 'required|in:' . implode(',', array_keys(self::COLORS))], ['name' => 'نام نقش', 'description' => 'توضیحات', 'data_scope' => 'محدوده داده', 'color' => 'رنگ']);
        $slug = 'role_' . substr(bin2hex(random_bytes(4)), 0, 8);
        $id = DB::insert('roles', ['slug' => $slug, 'name' => $d['name'], 'description' => $d['description'] ?? null, 'color' => $d['color'], 'data_scope' => $d['data_scope'], 'is_learner' => Request::bool('is_learner') ? 1 : 0, 'sort' => 50, 'created_at' => now()]);
        $copy = Request::intOrNull('copy_from');
        if ($copy) {
            foreach (DB::column('SELECT permission_key FROM role_permissions WHERE role_id = ?', [$copy]) as $p) {
                if (Gate::canGrant($p)) DB::insert('role_permissions', ['role_id' => $id, 'permission_key' => $p]);
            }
        }
        Audit::log('roles.create', 'role', $id, 'success', ['name' => $d['name'], 'copy_from' => $copy]);
        flash('success', 'نقش ایجاد شد. اکنون سطوح دسترسی آن را در ماتریس زیر مشخص کنید.');
        redirect('/admin/roles/' . $id);
    }

    public function edit(int $id): string
    {
        $role = DB::find('roles', $id) ?? throw new HttpException(404);
        $granted = array_flip(DB::column('SELECT permission_key FROM role_permissions WHERE role_id = ?', [$id]));
        $members = DB::all('SELECT u.* FROM users u JOIN user_roles ur ON ur.user_id = u.id WHERE ur.role_id = ? AND u.deleted_at IS NULL ORDER BY u.last_name LIMIT 300', [$id]);
        $candidates = can('roles.assign') ? DB::all("SELECT id, first_name, last_name, mobile FROM users WHERE deleted_at IS NULL AND is_root = 0 AND id NOT IN (SELECT user_id FROM user_roles WHERE role_id = ?) ORDER BY last_name, first_name LIMIT 2000", [$id]) : [];
        return view('admin/roles/edit', [
            'title' => 'نقش: ' . $role['name'], 'role' => $role, 'granted' => $granted, 'reg' => Gate::registry(),
            'members' => $members, 'candidates' => $candidates, 'colors' => self::COLORS,
            'editable' => can('roles.edit') && !(int)$role['is_root'],
        ]);
    }

    public function update(int $id): never
    {
        $role = DB::find('roles', $id) ?? throw new HttpException(404);
        if ((int)$role['is_root'] === 1) throw new HttpException(403, 'نقش مدیر کل قابل ویرایش نیست.');
        $action = Request::str('action', 'save');

        if ($action === 'members') {
            Gate::authorize('roles.assign');
            if (!Gate::canAssignRole($role)) throw new HttpException(403, 'این نقش مجوزهایی دارد که شما ندارید؛ امکان تخصیص آن را ندارید.');
            $add = Request::ints('add_users');
            foreach ($add as $uid) {
                $u = DB::find('users', $uid);
                if (!$u || (int)$u['is_root'] === 1 || !Gate::canManageUser($u)) continue;
                DB::run('INSERT IGNORE INTO user_roles (user_id, role_id, assigned_by, assigned_at) VALUES (?,?,?,?)', [$uid, $id, Auth::id(), now()]);
                Targeting::syncUser($uid);
                \App\Services\RoleGrants::apply($uid);
            }
            $remove = Request::ints('remove_users');
            foreach ($remove as $uid) {
                $u = DB::find('users', $uid);
                if (!$u || (int)$u['is_root'] === 1 || !Gate::canManageUser($u)) continue;
                DB::delete('user_roles', 'user_id = ? AND role_id = ?', [$uid, $id]);
            }
            Gate::flush();
            Audit::log('roles.members', 'role', $id, 'success', ['added' => $add, 'removed' => $remove]);
            flash('success', 'اعضای نقش به‌روزرسانی شد.');
            redirect('/admin/roles/' . $id . '#members');
        }

        $d = Validator::validate(['name' => 'required|max:100|unique:roles,name,' . $id, 'description' => 'max:500', 'data_scope' => 'required|in:all,supervised,own', 'color' => 'required|in:' . implode(',', array_keys(self::COLORS))], ['name' => 'نام نقش', 'description' => 'توضیحات', 'data_scope' => 'محدوده داده', 'color' => 'رنگ']);
        $requested = array_flip(array_filter((array)($_POST['perms'] ?? []), 'is_string'));
        $all = Gate::allPermissions();
        $current = array_flip(DB::column('SELECT permission_key FROM role_permissions WHERE role_id = ?', [$id]));
        $added = []; $removed = [];
        DB::transaction(function () use ($id, $d, $requested, $all, $current, &$added, &$removed) {
            DB::update('roles', ['name' => $d['name'], 'description' => $d['description'] ?? null, 'data_scope' => $d['data_scope'], 'color' => $d['color'], 'is_active' => Request::bool('is_active') ? 1 : 0, 'updated_at' => now()], 'id = ?', [$id]);
            foreach ($all as $key => $p) {
                if ($p['root_only'] || !Gate::canGrant($key)) continue; // cannot touch permissions the actor does not own
                $want = isset($requested[$key]);
                $has = isset($current[$key]);
                if ($want && !$has) { DB::insert('role_permissions', ['role_id' => $id, 'permission_key' => $key]); $added[] = $key; }
                if (!$want && $has) { DB::delete('role_permissions', 'role_id = ? AND permission_key = ?', [$id, $key]); $removed[] = $key; }
            }
        });
        Gate::flush();
        Audit::log('roles.update', 'role', $id, 'success', ['added' => $added, 'removed' => $removed, 'scope' => $d['data_scope']]);
        flash('success', 'نقش ذخیره شد' . ($added || $removed ? ' (' . fa(count($added)) . ' مجوز اضافه، ' . fa(count($removed)) . ' مجوز حذف شد).' : '.'));
        redirect('/admin/roles/' . $id);
    }

    public function duplicate(int $id): never
    {
        $role = DB::find('roles', $id) ?? throw new HttpException(404);
        if ((int)$role['is_root'] === 1) throw new HttpException(403);
        $name = $role['name'] . ' (کپی)';
        $i = 2;
        while (DB::value('SELECT 1 FROM roles WHERE name = ?', [$name])) $name = $role['name'] . ' (کپی ' . fa($i++) . ')';
        $nid = DB::insert('roles', ['slug' => 'role_' . substr(bin2hex(random_bytes(4)), 0, 8), 'name' => $name, 'description' => $role['description'], 'color' => $role['color'], 'data_scope' => $role['data_scope'], 'is_learner' => $role['is_learner'], 'sort' => 60, 'created_at' => now()]);
        foreach (DB::column('SELECT permission_key FROM role_permissions WHERE role_id = ?', [$id]) as $p) if (Gate::canGrant($p)) DB::insert('role_permissions', ['role_id' => $nid, 'permission_key' => $p]);
        Audit::log('roles.clone', 'role', $nid, 'success', ['from' => $id]);
        flash('success', 'نقش کپی شد.');
        redirect('/admin/roles/' . $nid);
    }

    public function destroy(int $id): never
    {
        $role = DB::find('roles', $id) ?? throw new HttpException(404);
        if ((int)$role['is_root'] === 1 || (int)$role['is_system'] === 1) throw new HttpException(403, 'نقش‌های سیستمی قابل حذف نیستند؛ می‌توانید آن‌ها را غیرفعال کنید.');
        if (!Gate::canAssignRole($role)) throw new HttpException(403);
        DB::delete('roles', 'id = ?', [$id]);
        Gate::flush();
        Audit::log('roles.delete', 'role', $id, 'success', ['name' => $role['name']]);
        flash('success', 'نقش حذف شد.');
        redirect('/admin/roles');
    }

    public function matrix(): string
    {
        $roles = DB::all('SELECT * FROM roles WHERE is_learner = 0 ORDER BY is_root DESC, sort');
        $map = [];
        foreach (DB::all('SELECT role_id, permission_key FROM role_permissions') as $r) $map[$r['role_id']][$r['permission_key']] = true;
        return view('admin/roles/matrix', ['title' => 'ماتریس کلی دسترسی‌ها', 'roles' => $roles, 'map' => $map, 'reg' => Gate::registry()]);
    }

    // ------------------------------------------------------------------ per-user access

    public function userAccess(int $id): string
    {
        $u = DB::one('SELECT * FROM users WHERE id = ? AND deleted_at IS NULL', [$id]) ?? throw new HttpException(404);
        Scope::authorizeUser($id);
        $roles = DB::all('SELECT * FROM roles WHERE is_root = 0 ORDER BY is_learner, sort');
        $userRoles = array_flip(array_map('intval', DB::column('SELECT role_id FROM user_roles WHERE user_id = ?', [$id])));
        $overrides = DB::pairs('SELECT permission_key, effect FROM user_permissions WHERE user_id = ?', [$id]);
        $fromRoles = array_flip(DB::column('SELECT DISTINCT rp.permission_key FROM role_permissions rp JOIN user_roles ur ON ur.role_id = rp.role_id JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ? AND r.is_active = 1', [$id]));
        $effective = (int)$u['is_root'] === 1 ? array_fill_keys(array_keys(Gate::allPermissions()), true) : Gate::permissionsFor($id);
        return view('admin/roles/user_access', [
            'title' => 'دسترسی‌های ' . full_name($u), 'u' => $u, 'roles' => $roles, 'userRoles' => $userRoles, 'overrides' => $overrides,
            'fromRoles' => $fromRoles, 'effective' => $effective, 'reg' => Gate::registry(),
            'editable' => can('roles.assign') && (int)$u['is_root'] === 0 && Gate::canManageUser($u), 'scope' => Gate::scope($u),
        ]);
    }

    public function saveUserAccess(int $id): never
    {
        $u = DB::one('SELECT * FROM users WHERE id = ? AND deleted_at IS NULL', [$id]) ?? throw new HttpException(404);
        if ((int)$u['is_root'] === 1) throw new HttpException(403, 'دسترسی مدیر کل قابل تغییر نیست.');
        if (!Gate::canManageUser($u)) throw new HttpException(403, 'این کاربر مجوزهایی بالاتر از شما دارد.');
        Scope::authorizeUser($id);
        if ((int)$u['id'] === (int)Auth::id()) throw new HttpException(403, 'امکان تغییر دسترسی‌های خودتان وجود ندارد.');
        $wantRoles = array_flip(Request::ints('roles'));
        $changes = ['roles_added' => [], 'roles_removed' => [], 'overrides' => []];
        DB::transaction(function () use ($id, $wantRoles, &$changes) {
            foreach (DB::all('SELECT * FROM roles WHERE is_root = 0') as $r) {
                $has = (bool)DB::value('SELECT 1 FROM user_roles WHERE user_id = ? AND role_id = ?', [$id, $r['id']]);
                $want = isset($wantRoles[(int)$r['id']]);
                if ($want === $has || !Gate::canAssignRole($r)) continue;
                if ($want) { DB::insert('user_roles', ['user_id' => $id, 'role_id' => (int)$r['id'], 'assigned_by' => Auth::id(), 'assigned_at' => now()]); $changes['roles_added'][] = $r['slug']; }
                else { DB::delete('user_roles', 'user_id = ? AND role_id = ?', [$id, (int)$r['id']]); $changes['roles_removed'][] = $r['slug']; }
            }
            $ov = (array)($_POST['ov'] ?? []);
            foreach (Gate::allPermissions() as $key => $p) {
                if ($p['root_only'] || !Gate::canGrant($key)) continue;
                $val = (string)($ov[str_replace('.', '__', $key)] ?? 'inherit');
                $cur = DB::value('SELECT effect FROM user_permissions WHERE user_id = ? AND permission_key = ?', [$id, $key]);
                if ($val === 'inherit') { if ($cur) { DB::delete('user_permissions', 'user_id = ? AND permission_key = ?', [$id, $key]); $changes['overrides'][$key] = 'inherit'; } continue; }
                if (!in_array($val, ['allow', 'deny'], true) || $cur === $val) continue;
                DB::upsert('user_permissions', ['user_id' => $id, 'permission_key' => $key, 'effect' => $val, 'created_by' => Auth::id(), 'created_at' => now()], ['effect', 'created_by', 'created_at']);
                $changes['overrides'][$key] = $val;
            }
        });
        Gate::flush();
        Targeting::syncUser($id);
        if ($changes['roles_added']) \App\Services\RoleGrants::apply($id);
        Audit::log('users.access', 'user', $id, 'success', $changes);
        flash('success', 'دسترسی‌های کاربر ذخیره شد.');
        redirect('/admin/users/' . $id . '/access');
    }
}
