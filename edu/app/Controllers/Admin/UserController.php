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
use App\Core\Xlsx;
use App\Services\Enrollment;
use App\Services\Scope;
use App\Services\Targeting;
use App\Services\UserService;

final class UserController
{
    private function filters(): array
    {
        $w = 'u.deleted_at IS NULL';
        $p = [];
        $q = Request::str('q');
        if ($q !== '') {
            $like = '%' . $q . '%';
            $w .= ' AND (u.first_name LIKE ? OR u.last_name LIKE ? OR CONCAT(u.first_name, \' \', u.last_name) LIKE ? OR u.mobile LIKE ? OR u.email LIKE ? OR u.id = ? OR u.my_user_id = ?)';
            array_push($p, $like, $like, $like, $like, $like, ctype_digit($q) ? (int)$q : 0, $q);
        }
        if (($s = Request::str('segment')) && isset(\App\Core\Labels::SEGMENT[$s])) { $w .= ' AND u.segment = ?'; $p[] = $s; }
        if (($s = Request::str('status')) && in_array($s, ['active', 'inactive', 'pending'], true)) { $w .= ' AND u.status = ?'; $p[] = $s; }
        if ($r = Request::int('role')) { $w .= ' AND EXISTS (SELECT 1 FROM user_roles ur WHERE ur.user_id = u.id AND ur.role_id = ?)'; $p[] = $r; }
        if ($g = Request::int('group')) { $ids = Targeting::descendants('groups', $g); $w .= ' AND EXISTS (SELECT 1 FROM group_members gm WHERE gm.user_id = u.id AND gm.group_id IN (' . DB::in($ids) . '))'; $p = array_merge($p, $ids); }
        if ($o = Request::int('org')) { $ids = Targeting::descendants('org_units', $o); $w .= ' AND EXISTS (SELECT 1 FROM user_org_units x WHERE x.user_id = u.id AND x.org_unit_id IN (' . DB::in($ids) . '))'; $p = array_merge($p, $ids); }
        if (Request::str('sso') === '1') $w .= ' AND u.my_user_id IS NOT NULL';
        if (Request::str('sso') === '0') $w .= ' AND u.my_user_id IS NULL';
        $sc = Scope::userSql('u.id');
        $w .= $sc['sql'];
        $p = array_merge($p, $sc['params']);
        return [$w, $p];
    }

    public function index(): string
    {
        [$w, $p] = $this->filters();
        $page = DB::paginate("SELECT u.*, (SELECT GROUP_CONCAT(r.name SEPARATOR '، ') FROM roles r JOIN user_roles ur ON ur.role_id = r.id WHERE ur.user_id = u.id) AS role_names,
                                     (SELECT ROUND(AVG(progress_pct)) FROM enrollments e WHERE e.user_id = u.id) AS avg_progress
                                FROM users u WHERE $w ORDER BY u.is_root DESC, u.id DESC", $p, 25);
        $counts = DB::pairs("SELECT segment, COUNT(*) FROM users WHERE deleted_at IS NULL GROUP BY segment");
        $pending = (int)DB::value("SELECT COUNT(*) FROM users WHERE deleted_at IS NULL AND status = 'pending'");
        return view('admin/users/index', [
            'title' => 'کاربران', 'page' => $page, 'counts' => $counts, 'pending' => $pending,
            'roles' => DB::pairs('SELECT id, name FROM roles ORDER BY sort'),
            'groups' => DB::all('SELECT id, name, parent_id, segment FROM `groups` ORDER BY sort, name'),
            'orgs' => DB::all('SELECT o.id, o.name, t.name AS type_name FROM org_units o JOIN org_unit_types t ON t.id = o.type_id ORDER BY t.sort, o.name'),
            'allGroups' => DB::pairs('SELECT id, name FROM `groups` ORDER BY sort, name'),
            'courses' => DB::pairs("SELECT id, title FROM courses WHERE deleted_at IS NULL AND status = 'published' ORDER BY title"),
        ]);
    }

    public function export(): never
    {
        [$w, $p] = $this->filters();
        $rows = DB::all("SELECT u.id, u.first_name, u.last_name, u.mobile, u.email, u.segment, u.status, u.my_user_id, u.created_at, u.first_login_at, u.last_login_at, u.login_count,
                                (SELECT GROUP_CONCAT(r.name SEPARATOR '، ') FROM roles r JOIN user_roles ur ON ur.role_id = r.id WHERE ur.user_id = u.id) roles,
                                (SELECT GROUP_CONCAT(g.name SEPARATOR '، ') FROM `groups` g JOIN group_members gm ON gm.group_id = g.id WHERE gm.user_id = u.id) grps,
                                (SELECT COUNT(*) FROM enrollments e WHERE e.user_id = u.id) enr, (SELECT COUNT(*) FROM enrollments e WHERE e.user_id = u.id AND e.status = 'completed') done,
                                (SELECT ROUND(AVG(progress_pct),1) FROM enrollments e WHERE e.user_id = u.id) prog
                           FROM users u WHERE $w ORDER BY u.id", $p);
        $out = array_map(fn($r) => [(int)$r['id'], $r['first_name'], $r['last_name'], $r['mobile'], $r['email'], label('segment_one', $r['segment']), label('status', $r['status']), $r['my_user_id'] ? 'بله' : 'خیر', $r['roles'], $r['grps'], jdate($r['created_at']), jdatetime($r['first_login_at']), jdatetime($r['last_login_at']), (int)$r['login_count'], (int)$r['enr'], (int)$r['done'], (float)$r['prog']], $rows);
        Xlsx::download('users-' . date('Ymd'), ['شناسه', 'نام', 'نام خانوادگی', 'موبایل', 'ایمیل', 'نوع', 'وضعیت', 'متصل به my', 'نقش‌ها', 'گروه‌ها', 'تاریخ ثبت', 'اولین ورود', 'آخرین ورود', 'تعداد ورود', 'دوره‌ها', 'تکمیل‌شده', 'میانگین پیشرفت'], $out, 'کاربران');
    }

    private function formData(?array $u = null): array
    {
        $uid = $u ? (int)$u['id'] : 0;
        $groups = DB::all('SELECT * FROM `groups` ORDER BY segment, parent_id IS NOT NULL, sort, name');
        $levels = DB::all('SELECT l.*, g.name AS group_name FROM levels l JOIN `groups` g ON g.id = l.group_id ORDER BY g.sort, l.rank_no');
        $taxes = DB::all('SELECT * FROM taxonomies ORDER BY segment, sort');
        foreach ($taxes as &$t) $t['terms'] = DB::all('SELECT * FROM taxonomy_terms WHERE taxonomy_id = ? ORDER BY sort, name', [(int)$t['id']]);
        unset($t);
        return [
            'u' => $u,
            'groups' => $groups, 'levels' => $levels, 'taxes' => $taxes,
            'orgs' => DB::all('SELECT o.*, t.name AS type_name, t.sort AS type_sort FROM org_units o JOIN org_unit_types t ON t.id = o.type_id ORDER BY t.sort, o.name'),
            'roles' => DB::all('SELECT * FROM roles WHERE is_root = 0 ORDER BY is_learner DESC, sort'),
            'supervisors' => DB::all("SELECT DISTINCT u.id, u.first_name, u.last_name FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id WHERE u.deleted_at IS NULL AND r.is_learner = 0 ORDER BY u.last_name"),
            'uGroups' => $uid ? array_map('intval', DB::column('SELECT group_id FROM group_members WHERE user_id = ?', [$uid])) : [],
            'uOrgs' => $uid ? array_map('intval', DB::column('SELECT org_unit_id FROM user_org_units WHERE user_id = ?', [$uid])) : [],
            'uLevels' => $uid ? DB::pairs('SELECT group_id, level_id FROM user_levels WHERE user_id = ?', [$uid]) : [],
            'uTerms' => $uid ? array_map('intval', DB::column('SELECT term_id FROM user_terms WHERE user_id = ?', [$uid])) : [],
            'uRoles' => $uid ? array_map('intval', DB::column('SELECT role_id FROM user_roles WHERE user_id = ?', [$uid])) : [],
        ];
    }

    public function create(): string
    {
        return view('admin/users/form', ['title' => 'کاربر جدید'] + $this->formData());
    }

    public function store(): never
    {
        $d = $this->validateUser(null);
        $roleIds = [];
        if (can('roles.assign')) {
            foreach (Request::ints('roles') as $rid) { $r = DB::find('roles', $rid); if ($r && Gate::canAssignRole($r)) $roleIds[] = $rid; }
        }
        $d['password'] = (string)($_POST['password'] ?? '');
        $id = UserService::create($d, $roleIds);
        $this->saveRelations($id);
        Targeting::syncUser($id);
        Audit::log('users.create', 'user', $id, 'success', ['segment' => $d['segment']]);
        $st = DB::value('SELECT status FROM users WHERE id = ?', [$id]);
        flash('success', $st === 'pending' ? 'کاربر ایجاد شد و در انتظار تأیید مدیر است؛ تا زمان تأیید امکان ورود ندارد.' : 'کاربر ایجاد شد.');
        redirect('/admin/users/' . $id);
    }

    private function validateUser(?array $u): array
    {
        $id = $u ? (int)$u['id'] : 0;
        $rules = [
            'first_name' => 'required|max:100', 'last_name' => 'required|max:100',
            'mobile' => 'required|mobile|unique:users,mobile' . ($id ? ',' . $id : ''), 'email' => 'email|max:190|unique:users,email' . ($id ? ',' . $id : ''),
            'username' => 'max:100|unique:users,username' . ($id ? ',' . $id : ''), 'segment' => 'required|in:merchant,employee,agent',
            'status' => 'required|in:active,inactive,pending', 'job_title' => 'max:150',
        ];
        if (!$u) $rules['password'] = 'min:8';
        $d = Validator::validate($rules, ['first_name' => 'نام', 'last_name' => 'نام خانوادگی', 'mobile' => 'موبایل', 'email' => 'ایمیل', 'username' => 'نام کاربری', 'segment' => 'نوع کاربر', 'status' => 'وضعیت', 'password' => 'رمز عبور', 'job_title' => 'عنوان شغلی']);
        // on edit, the password is only touched when the admin explicitly ticked «تغییر رمز» —
        // browsers silently autofilled the admin's own saved password here and overwrote users' passwords
        $pw = (!$u || ($_POST['change_password'] ?? '') === '1') ? (string)($_POST['password'] ?? '') : '';
        if ($u && ($_POST['change_password'] ?? '') === '1' && trim($pw) === '') { keep_old($d); flash('danger', 'برای تغییر رمز، رمز جدید را وارد کنید.'); back(); }
        if ($pw !== '' && !\App\Core\Auth::passwordStrongEnough($pw)) { keep_old($d); flash('danger', 'رمز عبور باید حداقل ۸ کاراکتر و شامل حرف و عدد باشد.'); back(); }
        $d['mobile'] = canonical_mobile((string)$d['mobile']);
        $mobileChanged = !$u || canonical_mobile((string)$u['mobile']) !== $d['mobile'];
        if ($mobileChanged && ($dup = mobile_taken((string)$d['mobile'], $id))) { keep_old($d); flash('danger', 'این شماره موبایل قبلاً برای «' . e(full_name($dup)) . '» (#' . (int)$dup['id'] . ') ثبت شده است؛ دو حساب با یک موبایل باعث خطای ورود می‌شود.'); back(); }
        $d['_pw'] = $pw;
        $d['supervisor_id'] = Request::intOrNull('supervisor_id');
        return $d;
    }

    private function saveRelations(int $id): void
    {
        if (can('groups.assign') || can('users.assign')) {
            DB::delete('group_members', 'user_id = ?', [$id]);
            foreach (Request::ints('groups') as $g) DB::run('INSERT IGNORE INTO group_members (group_id, user_id, joined_at) VALUES (?,?,?)', [$g, $id, now()]);
            $seg = (string)DB::value('SELECT segment FROM users WHERE id = ?', [$id]);
            UserService::ensureSegmentGroup($id, $seg);
            DB::delete('user_levels', 'user_id = ?', [$id]);
            foreach ((array)($_POST['levels'] ?? []) as $gid => $lid) {
                if ((int)$lid > 0) DB::insert('user_levels', ['user_id' => $id, 'group_id' => (int)$gid, 'level_id' => (int)$lid, 'assigned_at' => now()]);
            }
            DB::delete('user_terms', 'user_id = ?', [$id]);
            foreach (Request::ints('terms') as $t) DB::run('INSERT IGNORE INTO user_terms (user_id, term_id) VALUES (?,?)', [$id, $t]);
        }
        if (can('org.assign') || can('users.assign')) {
            DB::delete('user_org_units', 'user_id = ?', [$id]);
            foreach (Request::ints('org_units') as $o) DB::run('INSERT IGNORE INTO user_org_units (user_id, org_unit_id) VALUES (?,?)', [$id, $o]);
        }
    }

    private function load(int $id): array
    {
        $u = DB::one('SELECT * FROM users WHERE id = ? AND deleted_at IS NULL', [$id]) ?? throw new HttpException(404);
        Scope::authorizeUser($id);
        return $u;
    }

    public function show(int $id): string
    {
        $u = $this->load($id);
        $data = $this->formData($u);
        $en = DB::all('SELECT e.*, c.title FROM enrollments e JOIN courses c ON c.id = e.course_id WHERE e.user_id = ? ORDER BY e.id DESC', [$id]);
        $logins = DB::all('SELECT * FROM login_history WHERE user_id = ? ORDER BY id DESC LIMIT 15', [$id]);
        $locked = $u['mobile'] ? Auth::lockedMinutes((string)$u['mobile']) : 0;
        $dupAcc = $u['mobile'] ? mobile_taken((string)$u['mobile'], $id) : null;
        $imps = can('audit.view') || is_root() ? DB::all('SELECT i.*, r.first_name, r.last_name FROM impersonation_logs i JOIN users r ON r.id = i.root_id WHERE i.target_id = ? ORDER BY i.id DESC LIMIT 10', [$id]) : [];
        $supervisor = $u['supervisor_id'] ? DB::find('users', (int)$u['supervisor_id']) : null;
        return view('admin/users/show', ['title' => full_name($u), 'en' => $en, 'logins' => $logins, 'imps' => $imps, 'supervisor' => $supervisor, 'canManage' => Gate::canManageUser($u), 'locked' => $locked, 'dupAcc' => $dupAcc] + $data);
    }

    public function edit(int $id): string
    {
        $u = $this->load($id);
        if (!Gate::canManageUser($u)) throw new HttpException(403, 'امکان ویرایش این کاربر را ندارید.');
        return view('admin/users/form', ['title' => 'ویرایش ' . full_name($u)] + $this->formData($u));
    }

    public function update(int $id): never
    {
        $u = $this->load($id);
        if (!Gate::canManageUser($u)) throw new HttpException(403, 'امکان ویرایش این کاربر را ندارید.');
        $d = $this->validateUser($u);
        $upd = [
            'first_name' => $d['first_name'], 'last_name' => $d['last_name'], 'mobile' => $d['mobile'],
            'email' => ($d['email'] ?? '') !== '' ? strtolower((string)$d['email']) : null, 'username' => ($d['username'] ?? '') !== '' ? $d['username'] : null,
            'segment' => $d['segment'], 'job_title' => $d['job_title'] ?? null, 'supervisor_id' => $d['supervisor_id'] !== $id ? $d['supervisor_id'] : null, 'updated_at' => now(),
        ];
        if ((int)$u['is_root'] !== 1) $upd['status'] = $d['status']; // root cannot be deactivated
        // only approvers may activate a pending account
        $approvedNow = false;
        if ($u['status'] === 'pending' && ($upd['status'] ?? '') === 'active') {
            if (can('users.approve')) $approvedNow = true; else $upd['status'] = 'pending';
        }
        $pw = (string)($d['_pw'] ?? '');
        if ($pw !== '') $upd['password_hash'] = \App\Core\Auth::hashPassword($pw);
        DB::update('users', $upd, 'id = ?', [$id]);
        // the temporary password generated for Arad Contact no longer works → never hand it out again on a retried order
        if ($pw !== '') \App\Services\AradContact::forgetPassword($id);
        if ($approvedNow) UserService::approve($id);
        if (can('roles.assign') && (int)$u['is_root'] !== 1 && $id !== (int)Auth::id()) {
            $want = array_flip(Request::ints('roles'));
            foreach (DB::all('SELECT * FROM roles WHERE is_root = 0') as $r) {
                if (!Gate::canAssignRole($r)) continue;
                $has = (bool)DB::value('SELECT 1 FROM user_roles WHERE user_id = ? AND role_id = ?', [$id, $r['id']]);
                if (isset($want[(int)$r['id']]) && !$has) DB::insert('user_roles', ['user_id' => $id, 'role_id' => (int)$r['id'], 'assigned_by' => Auth::id(), 'assigned_at' => now()]);
                if (!isset($want[(int)$r['id']]) && $has) DB::delete('user_roles', 'user_id = ? AND role_id = ?', [$id, (int)$r['id']]);
            }
            Gate::flush();
        }
        $this->saveRelations($id);
        Targeting::syncUser($id);
        \App\Services\RoleGrants::apply($id);
        Audit::log('users.update', 'user', $id, 'success', ['password_changed' => $pw !== '']);
        if ($pw !== '') {
            Auth::unlock(DB::find('users', $id) ?? $u);
            flash('success', 'اطلاعات کاربر ذخیره شد و رمز جدید فعال است. کاربر باید با موبایل ' . e((string)$upd['mobile']) . ' و همین رمز وارد شود.');
        } else {
            flash('success', 'اطلاعات کاربر ذخیره شد.');
        }
        redirect('/admin/users/' . $id);
    }

    public function status(int $id): never
    {
        $u = $this->load($id);
        if ((int)$u['is_root'] === 1) throw new HttpException(403, 'مدیر کل قابل غیرفعال‌سازی نیست.');
        if (!Gate::canManageUser($u) || $id === (int)Auth::id()) throw new HttpException(403);
        $new = Request::str('status');
        if (!in_array($new, ['active', 'inactive'], true)) throw new HttpException(400);
        if ($new === 'active' && $u['status'] === 'pending') {
            if (!can('users.approve')) throw new HttpException(403, 'تأیید حساب‌های جدید فقط توسط افراد دارای مجوز «تأیید حساب» ممکن است.');
            UserService::approve($id);
        } else {
            DB::update('users', ['status' => $new, 'updated_at' => now()], 'id = ?', [$id]);
        }
        Audit::log('users.status', 'user', $id, 'success', ['from' => $u['status'], 'to' => $new]);
        flash('success', 'وضعیت کاربر تغییر کرد.');
        back();
    }

    /** Approve / reject a pending account */
    public function approve(int $id): never
    {
        $u = $this->load($id);
        if ($u['status'] !== 'pending') { flash('info', 'این حساب در انتظار تأیید نیست.'); back(); }
        if (Request::str('decision') === 'reject') {
            DB::update('users', ['status' => 'inactive', 'updated_at' => now()], 'id = ?', [$id]);
            Audit::log('users.reject', 'user', $id);
            flash('success', 'درخواست حساب «' . full_name($u) . '» رد شد و حساب غیرفعال است.');
        } else {
            UserService::approve($id);
            flash('success', 'حساب «' . full_name($u) . '» تأیید شد و کاربر اکنون می‌تواند وارد شود.');
        }
        back();
    }

    /** Charge (+) or deduct (−) minute credit */
    public function credits(int $id): never
    {
        $u = $this->load($id);
        $C = \App\Services\Credit::class;
        $type = Request::str('ctype', 'course');
        $note = Request::str('note');
        if ($type === 'meeting') {
            if (Request::str('op') === 'end') {
                $C::meetingEnd($id, $note, (int)Auth::id());
                flash('success', 'اشتراک میتینگ کاربر پایان یافت.');
            } else {
                $months = max(0, Request::int('years')) * 12 + max(0, Request::int('months'));
                $from = \App\Core\Jalali::parse(Request::str('from')) ?: null;
                [$ok, $until] = $C::meetingExtend($id, $months, max(0, Request::int('days')), $from, $note, (int)Auth::id());
                if (!$ok) { flash('danger', $until); back(); }
                flash('success', 'اشتراک میتینگ آنلاین تا ' . jdate($until) . ' فعال شد.');
            }
            redirect('/admin/users/' . $id . '#credits');
        }
        if (!isset($C::COLUMNS[$type])) throw new HttpException(400);
        $amount = $type === 'workshop' ? max(0, Request::int('count')) : max(0, Request::int('hours')) * 60 + max(0, Request::int('minutes'));
        if ($amount <= 0) { flash('danger', $type === 'workshop' ? 'تعداد کارگاه را وارد کنید.' : 'مقدار ساعت یا دقیقه را وارد کنید.'); back(); }
        $delta = Request::str('op') === 'deduct' ? -$amount : $amount;
        [$ok, $msg] = $C::adjustType($id, $type, $delta, $note, (int)Auth::id());
        if (!$ok) { flash('danger', $msg); back(); }
        $bal = $C::balances($id)[$type];
        flash('success', $C::LABELS[$type] . ': ' . ($delta > 0 ? $C::amount($type, $amount) . ' افزوده شد.' : $C::amount($type, $amount) . ' کسر شد.') . ' موجودی: ' . $C::amount($type, $bal));
        redirect('/admin/users/' . $id . '#credits');
    }

    public function destroy(int $id): never
    {
        $u = $this->load($id);
        if ((int)$u['is_root'] === 1) throw new HttpException(403, 'مدیر کل قابل حذف نیست.');
        if (!Gate::canManageUser($u) || $id === (int)Auth::id()) throw new HttpException(403);
        // soft delete: learning history is preserved; unique identifiers are released
        DB::update('users', ['deleted_at' => now(), 'status' => 'inactive', 'mobile' => null, 'email' => null, 'username' => null, 'my_user_id' => null], 'id = ?', [$id]);
        Audit::log('users.delete', 'user', $id, 'success', ['mobile' => $u['mobile'], 'name' => full_name($u)]);
        flash('success', 'کاربر حذف شد (سوابق آموزشی برای گزارش‌ها حفظ شده است).');
        redirect('/admin/users');
    }

    public function unlinkMy(int $id): never
    {
        $u = $this->load($id);
        if (!Gate::canManageUser($u)) throw new HttpException(403);
        DB::update('users', ['my_user_id' => null, 'my_linked_at' => null], 'id = ?', [$id]);
        Audit::log('sso.unlink', 'user', $id, 'success', ['my_user_id' => $u['my_user_id']]);
        flash('success', 'اتصال حساب my قطع شد.');
        redirect('/admin/users/' . $id);
    }

    public function impersonate(int $id): never
    {
        $u = DB::one('SELECT * FROM users WHERE id = ? AND deleted_at IS NULL', [$id]) ?? throw new HttpException(404);
        if ($u['status'] !== 'active') { flash('warning', 'فقط امکان ورود به حساب‌های فعال وجود دارد.'); redirect('/admin/users/' . $id); }
        Auth::impersonate($u);
        flash('warning', 'شما اکنون با حساب ' . full_name($u) . ' وارد شده‌اید.');
        redirect('/');
    }

    public function merge(): never
    {
        $src = Request::int('source_id');
        $dst = Request::int('target_id');
        $s = DB::one('SELECT * FROM users WHERE id = ? AND deleted_at IS NULL', [$src]);
        $t = DB::one('SELECT * FROM users WHERE id = ? AND deleted_at IS NULL', [$dst]);
        if (!$s || !$t || $src === $dst || (int)$s['is_root'] === 1) { flash('danger', 'انتخاب کاربران برای ادغام معتبر نیست.'); back(); }
        if (!Gate::canManageUser($s) || !Gate::canManageUser($t)) throw new HttpException(403);
        if ($s['my_user_id'] && $t['my_user_id'] && $s['my_user_id'] !== $t['my_user_id']) { flash('danger', 'هر دو حساب به حساب‌های my متفاوتی متصل هستند و قابل ادغام نیستند.'); back(); }
        UserService::merge($src, $dst);
        flash('success', 'حساب‌ها ادغام شدند. تمام سوابق آموزشی به حساب مقصد منتقل شد.');
        redirect('/admin/users/' . $dst);
    }

    /** Bulk actions on selected users: add to group, assign course */
    public function bulk(): never
    {
        $ids = Request::ints('ids');
        $scopeIds = Scope::userIds();
        if ($scopeIds !== null) $ids = array_values(array_intersect($ids, $scopeIds));
        if (!$ids) { flash('warning', 'کاربری انتخاب نشده است.'); back(); }
        $act = Request::str('bulk_action');
        if ($act === 'group' && can('groups.assign') && ($g = Request::int('group_id'))) {
            foreach ($ids as $uid) { DB::run('INSERT IGNORE INTO group_members (group_id, user_id, joined_at) VALUES (?,?,?)', [$g, $uid, now()]); Targeting::syncUser($uid); }
            flash('success', fa(count($ids)) . ' کاربر به گروه اضافه شدند.');
        } elseif ($act === 'course' && can('assignments.assign') && ($c = Request::int('course_id'))) {
            foreach ($ids as $uid) Enrollment::enroll($uid, $c, ['source' => 'assignment', 'training_type' => Request::str('training_type', 'mandatory')]);
            flash('success', 'دوره به ' . fa(count($ids)) . ' کاربر تخصیص داده شد.');
        } elseif ($act === 'approve' && can('users.approve')) {
            $n = 0;
            foreach ($ids as $uid) { if (DB::value("SELECT 1 FROM users WHERE id = ? AND status = 'pending'", [$uid])) { UserService::approve($uid); $n++; } }
            flash('success', fa($n) . ' حساب تأیید شد.');
        } elseif ($act === 'credit' && can('credits.edit')) {
            $C = \App\Services\Credit::class;
            $ct = Request::str('bulk_ctype', 'course');
            $m = Request::int('bulk_minutes');
            if ($m === 0) { flash('danger', 'مقدار را وارد کنید (منفی برای کسر).'); back(); }
            $n = 0; $fail = 0;
            if ($ct === 'meeting') {
                foreach ($ids as $uid) { if ($m > 0) { [$ok] = $C::meetingExtend($uid, $m, 0, null, 'شارژ گروهی', (int)Auth::id()); $ok ? $n++ : $fail++; } }
                flash('success', 'اشتراک میتینگ ' . fa($m) . ' ماهه برای ' . fa($n) . ' کاربر ثبت شد.');
            } else {
                if (!isset($C::COLUMNS[$ct])) $ct = 'course';
                foreach ($ids as $uid) { [$ok] = $C::adjustType($uid, $ct, $m, Request::str('bulk_note') ?: 'شارژ گروهی', (int)Auth::id()); $ok ? $n++ : $fail++; }
                flash($fail ? 'warning' : 'success', $C::LABELS[$ct] . ': ' . ($m > 0 ? '' : 'کسر ') . $C::amount($ct, abs($m)) . ' برای ' . fa($n) . ' کاربر اعمال شد.' . ($fail ? ' ' . fa($fail) . ' کاربر موجودی کافی برای کسر نداشتند.' : ''));
            }
        } elseif (in_array($act, ['activate', 'deactivate'], true) && can('users.edit')) {
            $st = $act === 'activate' ? 'active' : 'inactive';
            foreach ($ids as $uid) {
                $u = DB::find('users', $uid);
                if (!$u || (int)$u['is_root'] || $uid === (int)Auth::id() || !Gate::canManageUser($u)) continue;
                if ($u['status'] === 'pending' && $st === 'active') { if (can('users.approve')) UserService::approve($uid); continue; }
                DB::update('users', ['status' => $st], 'id = ?', [$uid]);
            }
            flash('success', 'وضعیت کاربران تغییر کرد.');
        } else {
            throw new HttpException(403);
        }
        Audit::log('users.bulk', 'user', null, 'success', ['action' => $act, 'count' => count($ids)]);
        back();
    }

    /** CSV import: first_name,last_name,mobile,email,segment[,group] */
    /** Admin tool: why can't this user sign in? Runs the exact same checks as the login page (nothing is changed). */
    public function loginCheck(int $id): never
    {
        $u = $this->load($id);
        if (!Gate::canManageUser($u)) throw new HttpException(403);
        $ident = trim(Request::str('identifier')) ?: (string)$u['mobile'];
        $pw = (string)($_POST['password'] ?? '');
        $cands = Auth::candidates($ident);
        $lines = [];
        if (!$cands) {
            $lines[] = '✖ با «' . e($ident) . '» هیچ حسابی پیدا نمی‌شود؛ کاربر باید با موبایل ' . e((string)$u['mobile']) . ' وارد شود.';
        } else {
            $ids = array_map(fn($c) => (int)$c['id'], $cands);
            if (!in_array($id, $ids, true)) $lines[] = '✖ «' . e($ident) . '» به حساب دیگری می‌رسد: ' . e(full_name($cands[0])) . ' (#' . $ids[0] . ')، نه این کاربر.';
            elseif (count($cands) > 1) $lines[] = '⚠ «' . e($ident) . '» به ' . fa(count($cands)) . ' حساب می‌رسد (#' . implode('، #', $ids) . ')؛ رمز با همه بررسی می‌شود. بهتر است حساب‌های تکراری ادغام شوند.';
            else $lines[] = '✔ «' . e($ident) . '» درست به همین حساب می‌رسد.';
        }
        if ($pw !== '') {
            $fresh = DB::find('users', $id);
            if (empty($fresh['password_hash'])) $lines[] = '✖ برای این حساب رمزی تعیین نشده است.';
            else $lines[] = Auth::verifyPassword($fresh, $pw) ? '✔ این رمز برای این حساب درست است.' : '✖ این رمز با رمز ذخیره‌شده‌ی این حساب یکی نیست.';
        } else {
            $lines[] = empty($u['password_hash']) ? '✖ برای این حساب رمزی تعیین نشده است.' : 'ℹ رمز تعیین شده است (برای بررسی درستی، رمز را هم وارد کنید).';
        }
        $lines[] = $u['status'] === 'active' ? '✔ حساب فعال است.' : '✖ وضعیت حساب «' . e(label('status', $u['status'])) . '» است و تا فعال نشود ورود ممکن نیست.';
        $lock = Auth::lockedMinutes($ident);
        $lines[] = $lock ? '✖ ورود با این شناسه به خاطر تلاش‌های ناموفق حدود ' . fa($lock) . ' دقیقه مسدود است (دکمه «رفع مسدودی ورود» را بزنید).' : '✔ ورود مسدود نیست.';
        $bad = count(array_filter($lines, fn($l) => str_starts_with($l, '✖')));
        flash($bad ? 'warning' : 'success', '<b>نتیجه بررسی ورود:</b><br>' . implode('<br>', $lines), true);
        redirect('/admin/users/' . $id . '#login-check');
    }

    public function unlock(int $id): never
    {
        $u = $this->load($id);
        if (!Gate::canManageUser($u)) throw new HttpException(403);
        Auth::unlock($u);
        Audit::log('users.unlock_login', 'user', $id);
        flash('success', 'مسدودی ورود این کاربر برداشته شد؛ می‌تواند همین الان دوباره وارد شود.');
        redirect('/admin/users/' . $id . '#login-check');
    }

    public function import(): never
    {
        $f = Request::file('csv');
        if (!$f || $f['error'] !== UPLOAD_ERR_OK || $f['size'] > 5 * 1048576) { flash('danger', 'فایل CSV معتبر انتخاب کنید (حداکثر ۵ مگابایت).'); redirect('/admin/users'); }
        $h = fopen($f['tmp_name'], 'r');
        $created = 0; $skipped = 0; $line = 0; $errors = [];
        $defaultGroup = Request::intOrNull('group_id');
        while (($row = fgetcsv($h, 0, ',', '"', '\\')) !== false) {
            $line++;
            $row = array_map(fn($x) => normalize_input((string)preg_replace('/^\xEF\xBB\xBF/', '', (string)$x)), $row);
            if ($line === 1 && (str_contains(mb_strtolower($row[0] ?? ''), 'name') || str_contains($row[0] ?? '', 'نام'))) continue;
            [$fn, $ln, $mob, $em, $seg] = array_pad($row, 5, '');
            $mob = canonical_mobile($mob);
            if (!preg_match('/^(\+?\d{8,15}|09\d{9})$/', $mob)) { $skipped++; $errors[] = 'سطر ' . $line . ': موبایل نامعتبر'; continue; }
            if (mobile_taken($mob)) { $skipped++; continue; }
            if ($em !== '' && (!filter_var($em, FILTER_VALIDATE_EMAIL) || DB::value('SELECT 1 FROM users WHERE email = ?', [$em]))) $em = '';
            $segKey = ['تاجر' => 'merchant', 'کارمند' => 'employee', 'نماینده' => 'agent'][$seg] ?? (in_array($seg, ['merchant', 'employee', 'agent'], true) ? $seg : 'merchant');
            $id = UserService::create(['first_name' => $fn, 'last_name' => $ln, 'mobile' => $mob, 'email' => $em ?: null, 'segment' => $segKey, 'status' => 'active']);
            if ($defaultGroup) DB::run('INSERT IGNORE INTO group_members (group_id, user_id, joined_at) VALUES (?,?,?)', [$defaultGroup, $id, now()]);
            Targeting::syncUser($id);
            $created++;
        }
        fclose($h);
        Audit::log('users.import', 'user', null, 'success', ['created' => $created, 'skipped' => $skipped]);
        flash($created ? 'success' : 'warning', fa($created) . ' کاربر ایجاد شد، ' . fa($skipped) . ' سطر رد شد (تکراری یا نامعتبر).' . ($errors ? ' ' . implode(' | ', array_slice($errors, 0, 5)) : ''));
        redirect('/admin/users');
    }
}
