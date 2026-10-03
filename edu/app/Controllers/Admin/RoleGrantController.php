<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Audit;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\Request;
use App\Services\RoleGrants;

/** «شارژ گروهی نقش‌ها»: credit packages of کارمند فراگیر / نماینده and the bulk-charge buttons */
final class RoleGrantController
{
    public function index(): string
    {
        $cfg = RoleGrants::config();
        $packs = [];
        foreach (RoleGrants::PACKAGES as $key => $p) {
            $rid = RoleGrants::roleId($key, $cfg);
            $packs[$key] = $p + [
                'cfg' => $cfg[$key], 'role_id' => $rid,
                'role_name' => $rid ? (string)DB::value('SELECT name FROM roles WHERE id = ?', [$rid]) : null,
                'stats' => RoleGrants::stats($key, $rid),
                'can_run' => can($p['perm']),
            ];
        }
        $roles = DB::pairs('SELECT id, name FROM roles WHERE is_root = 0 ORDER BY is_learner DESC, name');
        $recent = DB::all("SELECT a.*, u.first_name, u.last_name FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id
                            WHERE a.action IN ('roles.grant_bulk', 'roles.grant_settings') ORDER BY a.id DESC LIMIT 10");
        return view('admin/roles/grants', ['title' => 'شارژ گروهی نقش‌ها', 'packs' => $packs, 'roles' => $roles, 'recent' => $recent]);
    }

    public function save(): never
    {
        $in = (array)($_POST['p'] ?? []);
        $cfg = RoleGrants::config();
        foreach (RoleGrants::PACKAGES as $key => $_) {
            $row = (array)($in[$key] ?? []);
            foreach (['role_id', 'course_hours', 'webinar_hours', 'workshop', 'meeting_months'] as $f) {
                if (isset($row[$f])) $cfg[$key][$f] = max(0, (int)normalize_input((string)$row[$f]));
            }
            $cfg[$key]['auto'] = !empty($row['auto']) ? 1 : 0;
        }
        RoleGrants::saveConfig($cfg);
        Audit::log('roles.grant_settings', null, null, 'success', RoleGrants::config());
        flash('success', 'تنظیمات شارژ نقش‌ها ذخیره شد.');
        redirect('/admin/role-grants');
    }

    public function run(string $key): never
    {
        $p = RoleGrants::PACKAGES[$key] ?? throw new HttpException(404);
        if (!can($p['perm'])) throw new HttpException(403, 'اجازه شارژ گروهی ' . $p['title'] . ' را ندارید.');
        $again = Request::bool('again');
        $res = RoleGrants::runBulk($key, $again);
        if (!$res['role_id']) { flash('danger', 'نقش مربوط به ' . $p['title'] . ' پیدا نشد؛ ابتدا نقش را انتخاب و ذخیره کنید.'); redirect('/admin/role-grants'); }
        $msg = 'شارژ گروهی ' . $p['title'] . ': ' . fa($res['charged']) . ' نفر شارژ شدند';
        if ($res['skipped']) $msg .= '، ' . fa($res['skipped']) . ' نفر قبلاً شارژ شده بودند';
        if ($res['failed']) $msg .= '، ' . fa($res['failed']) . ' نفر با خطا مواجه شدند (جزئیات در خطاهای سامانه)';
        flash($res['failed'] ? 'warning' : 'success', $msg . '.');
        redirect('/admin/role-grants');
    }
}
