<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Audit;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Settings;
use App\Services\AradContact;

/** Settings and request log of the Arad Contact integration */
final class IntegrationController
{
    public function aradContact(): string
    {
        $w = '1=1'; $p = [];
        if (($q = normalize_input(Request::str('q'))) !== '') {
            $w .= ' AND (l.external_id LIKE ? OR l.mobile LIKE ? OR l.mobile = ?)';
            array_push($p, "%$q%", "%$q%", canonical_mobile($q));
        }
        $st = Request::str('status');
        if ($st === 'ok') $w .= ' AND l.success = 1';
        elseif ($st === 'fail') $w .= ' AND l.success = 0';
        elseif ($st === 'dup') $w .= ' AND l.duplicate = 1';
        elseif ($st === 'new') $w .= ' AND l.user_created = 1';
        if (($ep = Request::str('endpoint')) !== '' && in_array($ep, ['services', 'provision'], true)) { $w .= ' AND l.endpoint = ?'; $p[] = $ep; }
        $page = DB::paginate("SELECT l.id, l.endpoint, l.method, l.ip, l.http_status, l.success, l.duplicate, l.user_created, l.external_id, l.mobile, l.user_id, l.message, l.duration_ms, l.created_at,
                                     u.first_name, u.last_name
                                FROM arad_contact_logs l LEFT JOIN users u ON u.id = l.user_id WHERE $w ORDER BY l.id DESC", $p, 30);
        $since = date('Y-m-d H:i:s', strtotime('-30 days'));
        $stats = DB::one("SELECT COUNT(*) total, COALESCE(SUM(success = 1 AND endpoint = 'provision' AND duplicate = 0), 0) orders, COALESCE(SUM(success = 0), 0) failed,
                                 COALESCE(SUM(user_created = 1), 0) created, COALESCE(SUM(duplicate = 1), 0) dups, MAX(created_at) last_at
                            FROM arad_contact_logs WHERE created_at >= ?", [$since]) ?: [];
        $groupId = AradContact::groupId();
        $new = $_SESSION['_arad_token'] ?? null;
        unset($_SESSION['_arad_token']);
        return view('admin/integrations/arad_contact', [
            'title' => 'اتصال آراد کانتکت', 's' => Settings::all(), 'page' => $page, 'stats' => $stats, 'newToken' => $new,
            'editable' => can('settings.edit'), 'envToken' => AradContact::envToken() !== '', 'configured' => AradContact::configured(),
            'roles' => DB::all('SELECT id, name, is_learner FROM roles WHERE is_root = 0 ORDER BY is_learner DESC, sort, id'),
            'groups' => DB::all('SELECT id, name, parent_id FROM `groups` ORDER BY (parent_id IS NULL) DESC, sort, name'),
            'levels' => DB::all('SELECT l.id, l.name, l.group_id, g.name AS group_name FROM levels l JOIN `groups` g ON g.id = l.group_id ORDER BY g.sort, g.name, l.rank_no'),
            'resolved' => [
                'role' => ($rid = AradContact::roleId()) ? DB::value('SELECT name FROM roles WHERE id = ?', [$rid]) : null,
                'group' => $groupId ? DB::value('SELECT name FROM `groups` WHERE id = ?', [$groupId]) : null,
                'level' => ($lid = AradContact::levelId($groupId)) ? DB::value('SELECT name FROM levels WHERE id = ?', [$lid]) : null,
            ],
            'baseApi' => url('/api/integrations/arad-contact'),
        ]);
    }

    public function saveAradContact(): never
    {
        $ips = array_values(array_filter(array_map('trim', preg_split('/[\s,،]+/u', Request::str('allowed_ips')) ?: [])));
        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP)) { flash('danger', 'IP «' . e($ip) . '» معتبر نیست.'); redirect('/admin/integrations/arad-contact'); }
        }
        $role = Request::int('role_id');
        $group = Request::int('group_id');
        $level = Request::int('level_id');
        if ($level > 0) {
            $lg = (int)DB::value('SELECT group_id FROM levels WHERE id = ?', [$level]);
            $effectiveGroup = $group > 0 ? $group : (int)AradContact::groupId();
            if ($lg !== $effectiveGroup) { flash('danger', 'سطح انتخاب‌شده متعلق به گروه انتخاب‌شده نیست.'); redirect('/admin/integrations/arad-contact'); }
        }
        Settings::set([
            'arad_contact_enabled' => Request::bool('enabled') ? '1' : '0',
            'arad_contact_auto_activate' => Request::bool('auto_activate') ? '1' : '0',
            'arad_contact_role_id' => (string)max(0, $role),
            'arad_contact_group_id' => (string)max(0, $group),
            'arad_contact_level_id' => (string)max(0, $level),
            'arad_contact_allowed_ips' => implode(',', $ips),
        ]);
        Audit::log('arad_contact.settings', 'settings', null, 'success', ['enabled' => Request::bool('enabled'), 'role' => $role, 'group' => $group, 'level' => $level, 'ips' => $ips]);
        flash('success', 'تنظیمات اتصال آراد کانتکت ذخیره شد.');
        redirect('/admin/integrations/arad-contact');
    }

    /** Generate / set / remove the fixed Bearer token (root only). Only its SHA-256 is stored. */
    public function aradContactToken(): never
    {
        $op = Request::str('op');
        if ($op === 'clear') {
            Settings::set(['arad_contact_token_hash' => '', 'arad_contact_token_hint' => '', 'arad_contact_token_set_at' => '']);
            Audit::log('arad_contact.token_clear', 'settings', null);
            flash('warning', 'توکن اتصال حذف شد؛ تا تعریف توکن جدید، درخواست‌های آراد کانتکت پذیرفته نمی‌شوند.');
            redirect('/admin/integrations/arad-contact');
        }
        if ($op === 'generate') {
            $token = 'arc_' . bin2hex(random_bytes(24));
        } elseif ($op === 'custom') {
            $token = trim((string)($_POST['token'] ?? ''));
            if (strlen($token) < 24 || strlen($token) > 200 || !preg_match('/^[\x21-\x7E]+$/', $token)) {
                flash('danger', 'توکن باید دست‌کم ۲۴ و حداکثر ۲۰۰ کاراکتر لاتین، بدون فاصله باشد.');
                redirect('/admin/integrations/arad-contact');
            }
        } else {
            throw new HttpException(400);
        }
        Settings::set(['arad_contact_token_hash' => hash('sha256', $token), 'arad_contact_token_hint' => substr($token, -4), 'arad_contact_token_set_at' => now()]);
        Audit::log('arad_contact.token_set', 'settings', null, 'success', ['op' => $op]);
        if ($op === 'generate') $_SESSION['_arad_token'] = $token;
        flash('success', $op === 'generate' ? 'توکن جدید ساخته شد. آن را همین حالا در آراد کانتکت ثبت کنید؛ دیگر نمایش داده نمی‌شود.' : 'توکن ثبت شد.');
        redirect('/admin/integrations/arad-contact');
    }

    public function aradContactLog(int $id): string
    {
        $log = DB::one('SELECT l.*, u.first_name, u.last_name FROM arad_contact_logs l LEFT JOIN users u ON u.id = l.user_id WHERE l.id = ?', [$id]) ?? throw new HttpException(404);
        $order = $log['external_id'] ? DB::one('SELECT id, external_id, user_id, user_created, replay_count, created_at, last_replay_at FROM arad_contact_orders WHERE external_id = ?', [$log['external_id']]) : null;
        $pretty = function (?string $j): string {
            if ($j === null || $j === '') return '';
            $d = json_decode($j, true);
            return $d === null ? $j : (string)json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        };
        return view('admin/integrations/arad_contact_log', ['title' => 'جزئیات درخواست آراد کانتکت', 'log' => $log, 'order' => $order,
            'request' => $pretty($log['request_json']), 'response' => $pretty($log['response_json'])]);
    }

    // ------------------------------------------------------------------ Bale bot (my.aradbranding.me)

    public function bale(): string
    {
        $page = DB::paginate('SELECT * FROM bale_messages ORDER BY id DESC', [], 30);
        $upcoming = \App\Services\BaleBroadcast::pending(false, 30);
        $stats = DB::one("SELECT COUNT(*) n, COALESCE(SUM(status = 'sent'), 0) sent, COALESCE(SUM(status = 'failed'), 0) failed, COALESCE(SUM(recipients), 0) people FROM bale_messages") ?: [];
        return view('admin/integrations/bale', ['title' => 'ارسال خبر به ربات بله', 'page' => $page, 'upcoming' => $upcoming, 'stats' => $stats]);
    }

    public function saveBale(): never
    {
        $url = rtrim(trim(Request::str('api_url')), '/');
        if ($url !== '' && (!filter_var($url, FILTER_VALIDATE_URL) || !str_starts_with($url, 'https://'))) { flash('danger', 'آدرس API باید با https:// شروع شود.'); redirect('/admin/integrations/bale'); }
        $set = [
            'bale_enabled' => Request::bool('enabled') ? '1' : '0',
            'bale_api_url' => $url,
            'bale_delay_min' => (string)max(0, min(1440, Request::int('delay_min', 60))),
            'bale_events' => Request::bool('events') ? '1' : '0',
            'bale_courses' => Request::bool('courses') ? '1' : '0',
        ];
        $tok = trim((string)($_POST['api_token'] ?? ''));
        if ($tok !== '') $set['bale_api_token'] = $tok;
        if (Request::bool('clear_token')) $set['bale_api_token'] = '';
        // only things published after the first switch-on are announced (never the old archive)
        if ($set['bale_enabled'] === '1' && (string)setting('bale_since', '') === '') $set['bale_since'] = now();
        Settings::set($set);
        Audit::log('bale.settings', 'settings', null, 'success', ['enabled' => $set['bale_enabled'], 'url' => $url, 'delay' => $set['bale_delay_min']]);
        flash('success', 'تنظیمات ارسال به ربات بله ذخیره شد.');
        redirect('/admin/integrations/bale');
    }

    public function baleTest(): never
    {
        $r = \App\Services\BaleBroadcast::recipients('merchant');
        $r['ok'] ? flash('success', 'اتصال برقرار است. تاجران متصل به ربات بله: ' . fa($r['count']) . ' نفر.') : flash('danger', 'اتصال برقرار نشد: ' . $r['error']);
        redirect('/admin/integrations/bale');
    }

    /** skip = never send this item · send = send it now (without waiting) · retry = resend a failed message */
    public function baleAction(): never
    {
        $act = Request::str('act'); $ref = Request::str('ref');
        if (!preg_match('/^(event|course)-(\d+)$/', $ref, $m)) throw new HttpException(400);
        $row = DB::one('SELECT * FROM bale_messages WHERE ref = ?', [$ref]);
        if ($act === 'skip' && !$row) {
            DB::insert('bale_messages', ['ref' => $ref, 'ref_type' => $m[1], 'ref_id' => (int)$m[2], 'title' => mb_substr(Request::str('title'), 0, 250), 'status' => 'skipped', 'created_by' => \App\Core\Auth::id(), 'created_at' => now()]);
            flash('success', 'این مورد به ربات بله ارسال نمی‌شود.');
        } elseif (($act === 'send' && !$row) || ($act === 'retry' && $row && $row['status'] !== 'sent')) {
            if (!\App\Services\BaleBroadcast::configured()) { flash('danger', 'ابتدا آدرس و توکن API را وارد کنید.'); redirect('/admin/integrations/bale'); }
            if (!$row) {
                $src = $m[1] === 'event' ? DB::one('SELECT * FROM events WHERE id = ? AND deleted_at IS NULL', [(int)$m[2]]) : DB::one('SELECT * FROM courses WHERE id = ? AND deleted_at IS NULL', [(int)$m[2]]);
                if (!$src) throw new HttpException(404);
                $text = $m[1] === 'event' ? \App\Services\BaleBroadcast::eventText($src) : \App\Services\BaleBroadcast::courseText($src);
                $id = DB::insert('bale_messages', ['ref' => $ref, 'ref_type' => $m[1], 'ref_id' => (int)$m[2], 'title' => mb_substr(Request::str('title') ?: $src['title'], 0, 250), 'text' => $text, 'status' => 'sending', 'created_by' => \App\Core\Auth::id(), 'created_at' => now()]);
            } else $id = (int)$row['id'];
            $ok = \App\Services\BaleBroadcast::deliver($id, url($m[1] === 'event' ? '/learn/event/' . $m[2] : '/learn/course/' . $m[2]));
            $ok ? flash('success', 'پیام به ربات بله ارسال شد.') : flash('danger', 'ارسال ناموفق بود؛ جزئیات خطا در فهرست پایین آمده است.');
        }
        Audit::log('bale.' . $act, 'settings', null, 'success', ['ref' => $ref]);
        redirect('/admin/integrations/bale');
    }
}
