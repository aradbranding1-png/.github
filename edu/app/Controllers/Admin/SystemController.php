<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\Notify;
use App\Core\Request;
use App\Core\Settings;
use App\Core\Validator;
use App\Core\Xlsx;
use App\Services\Health;
use App\Services\SsoClient;
use App\Services\Targeting;

/** Settings, SSO, health, errors, audit log, API tokens, broadcast notifications */
final class SystemController
{
    public function settings(): string
    {
        return view('admin/system/settings', ['title' => 'تنظیمات سامانه', 's' => Settings::all(), 'editable' => can('settings.edit')]);
    }

    public function saveSettings(): never
    {
        $d = Validator::validate(['site_name' => 'required|max:150', 'site_tagline' => 'max:250', 'inactivity_days' => 'required|int', 'deadline_warning_days' => 'required|int', 'max_upload_mb' => 'required|int', 'video_complete_percent' => 'required|int', 'certificate_issuer' => 'max:150'], ['site_name' => 'نام سامانه', 'inactivity_days' => 'روزهای عدم فعالیت', 'max_upload_mb' => 'حداکثر حجم آپلود']);
        $segs = array_values(array_intersect(Request::arr('registration_segments'), ['merchant', 'employee', 'agent']));
        Settings::set([
            'site_name' => $d['site_name'], 'site_tagline' => $d['site_tagline'] ?? '', 'certificate_issuer' => $d['certificate_issuer'] ?? '',
            'registration_enabled' => Request::bool('registration_enabled') ? '1' : '0', 'registration_requires_approval' => Request::bool('registration_requires_approval') ? '1' : '0',
            'registration_segments' => implode(',', $segs ?: ['merchant']),
            'minutes_enabled' => Request::bool('minutes_enabled') ? '1' : '0', 'minutes_charge_text' => mb_substr(Request::str('minutes_charge_text'), 0, 500),
            'inactivity_days' => (string)max(1, (int)$d['inactivity_days']), 'deadline_warning_days' => (string)max(0, (int)$d['deadline_warning_days']),
            'max_upload_mb' => (string)max(1, min(4096, (int)$d['max_upload_mb'])), 'video_complete_percent' => (string)max(50, min(100, (int)$d['video_complete_percent'])),
            'update_require_signature' => is_root() ? (Request::bool('update_require_signature') ? '1' : '0') : (string)setting('update_require_signature'),
        ]);
        Audit::log('settings.update', 'settings', null);
        flash('success', 'تنظیمات ذخیره شد.');
        redirect('/admin/settings');
    }

    public function sso(): string
    {
        return view('admin/system/sso', ['title' => 'اتصال SSO', 's' => Settings::all(), 'errors' => SsoClient::configErrors(), 'callback' => SsoClient::callbackUrl(), 'editable' => can('sso.edit'),
            'env' => ['SSO_CLIENT_ID' => (bool)env('SSO_CLIENT_ID'), 'SSO_CLIENT_SECRET' => (bool)env('SSO_CLIENT_SECRET'), 'SSO_SHARED_SECRET' => (bool)env('SSO_SHARED_SECRET'), 'MY_API_KEY' => (bool)env('MY_API_KEY')],
            'linked' => (int)DB::value('SELECT COUNT(*) FROM users WHERE my_user_id IS NOT NULL AND deleted_at IS NULL'),
            'probe' => Request::str('probe') === '1' ? SsoClient::probe() : null]);
    }

    public function saveSso(): never
    {
        $urls = ['sso_authorize_url', 'sso_token_url', 'sso_userinfo_url', 'sso_logout_url'];
        $vals = [];
        foreach ($urls as $k) {
            $v = trim((string)($_POST[$k] ?? ''));
            if ($v !== '' && !filter_var(str_replace('{id}', '0', $v), FILTER_VALIDATE_URL)) { flash('danger', 'آدرس «' . $k . '» معتبر نیست.'); redirect('/admin/sso'); }
            $vals[$k] = $v;
        }
        foreach (['sso_scopes', 'sso_button_label', 'sso_map_id', 'sso_map_first_name', 'sso_map_last_name', 'sso_map_mobile', 'sso_map_email', 'sso_map_avatar', 'sso_map_status', 'sso_active_values'] as $k) {
            $vals[$k] = mb_substr(trim((string)($_POST[$k] ?? '')), 0, 200);
        }
        $vals['sso_mode'] = Request::str('sso_mode') === 'jwt' ? 'jwt' : 'oauth2';
        $vals['sso_enabled'] = Request::bool('sso_enabled') ? '1' : '0';
        $vals['sso_auto_register'] = Request::bool('sso_auto_register') ? '1' : '0';
        $vals['sso_sync_avatar'] = Request::bool('sso_sync_avatar') ? '1' : '0';
        $vals['sso_default_segment'] = in_array(Request::str('sso_default_segment'), ['merchant', 'employee', 'agent'], true) ? Request::str('sso_default_segment') : 'merchant';
        Settings::set($vals);
        Audit::log('sso.update', 'settings', null, 'success', ['enabled' => $vals['sso_enabled'], 'mode' => $vals['sso_mode']]);
        $errs = SsoClient::configErrors();
        flash($errs && $vals['sso_enabled'] === '1' ? 'warning' : 'success', 'تنظیمات SSO ذخیره شد.' . ($errs && $vals['sso_enabled'] === '1' ? ' موارد ناقص: ' . implode('، ', $errs) : ''));
        redirect('/admin/sso');
    }

    public function health(): string
    {
        $checks = Health::run(Request::str('deep') === '1');
        return view('admin/system/health', ['title' => 'سلامت سامانه', 'checks' => $checks, 'summary' => Health::summary($checks),
            'info' => ['php' => PHP_VERSION, 'sapi' => PHP_SAPI, 'server' => $_SERVER['SERVER_SOFTWARE'] ?? '—', 'upload_max' => ini_get('upload_max_filesize'), 'post_max' => ini_get('post_max_size'), 'memory' => ini_get('memory_limit'), 'max_exec' => ini_get('max_execution_time'), 'db' => (string)DB::value('SELECT VERSION()'), 'version' => app_version(), 'tz' => date_default_timezone_get()]]);
    }

    public function errors(): string
    {
        $files = glob(STORAGE_PATH . '/logs/app-*.log') ?: [];
        rsort($files);
        $sel = basename(Request::str('file', $files ? basename($files[0]) : ''));
        $lines = [];
        $path = STORAGE_PATH . '/logs/' . $sel;
        if ($sel && preg_match('/^app-\d{4}-\d{2}-\d{2}\.log$/', $sel) && is_file($path)) {
            $all = file($path, FILE_IGNORE_NEW_LINES) ?: [];
            $level = Request::str('level');
            if ($level !== '') $all = array_values(array_filter($all, fn($l) => str_contains($l, '.' . strtoupper($level) . ' ')));
            $lines = array_reverse(array_slice($all, -400));
        }
        return view('admin/system/errors', ['title' => 'خطاهای سامانه', 'files' => array_map('basename', $files), 'sel' => $sel, 'lines' => $lines]);
    }

    public function audit(): string
    {
        $w = '1=1'; $p = [];
        if (($q = Request::str('action')) !== '') { $w .= ' AND a.action LIKE ?'; $p[] = "%$q%"; }
        if ($u = Request::int('user')) { $w .= ' AND a.user_id = ?'; $p[] = $u; }
        if (($r = Request::str('result')) !== '') { $w .= ' AND a.result = ?'; $p[] = $r; }
        if ($f = \App\Core\Jalali::parse(Request::str('from'))) { $w .= ' AND a.created_at >= ?'; $p[] = $f . ' 00:00:00'; }
        if ($t = \App\Core\Jalali::parse(Request::str('to'))) { $w .= ' AND a.created_at <= ?'; $p[] = $t . ' 23:59:59'; }
        $sql = "SELECT a.*, u.first_name, u.last_name, i.first_name AS imp_first, i.last_name AS imp_last FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id LEFT JOIN users i ON i.id = a.impersonator_id WHERE $w ORDER BY a.id DESC";
        if (Request::str('export') === '1' && can('audit.export')) {
            $rows = DB::all($sql . ' LIMIT 50000', $p);
            Xlsx::download('audit-log', ['تاریخ', 'ساعت', 'کاربر', 'از طرف مدیر کل', 'عملیات', 'هدف', 'شناسه هدف', 'نتیجه', 'IP', 'جزئیات'], array_map(fn($r) => [jdate($r['created_at']), date('H:i:s', strtotime($r['created_at'])), trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')) ?: 'سیستم', trim(($r['imp_first'] ?? '') . ' ' . ($r['imp_last'] ?? '')), $r['action'], $r['target_type'], $r['target_id'], $r['result'], $r['ip'], $r['details']], $rows), 'Audit Log');
        }
        $page = DB::paginate($sql, $p, 50);
        $imps = DB::all('SELECT i.*, r.first_name AS r_first, r.last_name AS r_last, t.first_name AS t_first, t.last_name AS t_last FROM impersonation_logs i JOIN users r ON r.id = i.root_id JOIN users t ON t.id = i.target_id ORDER BY i.id DESC LIMIT 20');
        return view('admin/system/audit', ['title' => 'Audit Log', 'page' => $page, 'imps' => $imps]);
    }

    // ------------------------------------------------------------------ API tokens
    public function tokens(): string
    {
        $rows = DB::all('SELECT t.*, u.first_name, u.last_name FROM api_tokens t LEFT JOIN users u ON u.id = t.created_by ORDER BY t.id DESC');
        $new = $_SESSION['_new_token'] ?? null;
        unset($_SESSION['_new_token']);
        return view('admin/system/tokens', ['title' => 'کلیدهای API', 'rows' => $rows, 'new' => $new, 'abilities' => \App\Controllers\Api\V1Controller::ABILITIES]);
    }

    public function createToken(): never
    {
        $d = Validator::validate(['name' => 'required|max:100'], ['name' => 'نام کلید']);
        $ab = array_values(array_intersect(Request::arr('abilities'), array_keys(\App\Controllers\Api\V1Controller::ABILITIES)));
        $plain = 'ae_' . bin2hex(random_bytes(24));
        $days = Request::int('days');
        $id = DB::insert('api_tokens', ['name' => $d['name'], 'token_hash' => hash('sha256', $plain), 'token_hint' => substr($plain, -6), 'abilities' => implode(',', $ab), 'expires_at' => $days > 0 ? date('Y-m-d H:i:s', strtotime("+$days days")) : null, 'created_by' => Auth::id(), 'created_at' => now()]);
        Audit::log('api_tokens.create', 'api_token', $id, 'success', ['abilities' => $ab]);
        $_SESSION['_new_token'] = $plain;
        redirect('/admin/api-tokens');
    }

    public function revokeToken(int $id): never
    {
        DB::update('api_tokens', ['revoked_at' => now()], 'id = ?', [$id]);
        Audit::log('api_tokens.revoke', 'api_token', $id);
        flash('success', 'کلید ابطال شد.');
        redirect('/admin/api-tokens');
    }

    // ------------------------------------------------------------------ broadcast notifications
    public function notifications(): string
    {
        $recent = DB::all("SELECT title, body, type, MIN(created_at) created_at, COUNT(*) n, SUM(read_at IS NOT NULL) seen FROM notifications WHERE dedupe_key LIKE 'bc-%' GROUP BY dedupe_key, title, body, type ORDER BY MIN(id) DESC LIMIT 20");
        return view('admin/system/notifications', ['title' => 'ارسال اعلان', 'recent' => $recent,
            'groups' => DB::pairs('SELECT id, name FROM `groups` ORDER BY sort'), 'roles' => DB::pairs('SELECT id, name FROM roles ORDER BY sort'),
            'orgs' => DB::pairs("SELECT o.id, CONCAT(t.name, ': ', o.name) FROM org_units o JOIN org_unit_types t ON t.id = o.type_id ORDER BY t.sort, o.name")]);
    }

    public function sendNotification(): never
    {
        $d = Validator::validate(['title' => 'required|max:200', 'body' => 'max:2000', 'target' => 'required|in:all,segment,group,role,org_unit', 'link' => 'max:300'], ['title' => 'عنوان', 'target' => 'مخاطب']);
        $t = $d['target'];
        $ids = match ($t) {
            'all' => array_map('intval', DB::column("SELECT id FROM users WHERE status = 'active' AND deleted_at IS NULL")),
            'segment' => Targeting::users('segment', Request::str('segment')),
            'group' => Targeting::users('group', Request::int('group_id')),
            'role' => Targeting::users('role', Request::int('role_id')),
            'org_unit' => Targeting::users('org_unit', Request::int('org_id')),
        };
        $sc = \App\Services\Scope::userIds();
        if ($sc !== null) $ids = array_values(array_intersect($ids, $sc));
        $link = trim((string)($d['link'] ?? ''));
        if ($link !== '' && !str_starts_with($link, '/')) $link = '';
        $n = Notify::send($ids, 'system', $d['title'], (string)($d['body'] ?? ''), $link !== '' ? url($link) : null, 'bc-' . bin2hex(random_bytes(6)));
        Audit::log('notifications.broadcast', 'notification', null, 'success', ['target' => $t, 'count' => $n]);
        flash('success', 'اعلان برای ' . fa($n) . ' نفر ارسال شد.');
        redirect('/admin/notifications');
    }
}
