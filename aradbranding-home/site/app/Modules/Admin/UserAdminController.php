<?php

declare(strict_types=1);

namespace App\Modules\Admin;

use App\Core\Auth\Auth;
use App\Core\Cache\Cache;
use App\Core\Db\Connection;
use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Security\Audit;
use App\Core\Session\Session;
use App\Core\Support\Str;
use App\Core\Support\Ulid;
use App\Modules\Reference\ReferenceData;
use App\Modules\Wallet\InsufficientStars;
use App\Modules\Wallet\WalletService;

final class UserAdminController extends AdminController
{
    public const STATUS = [1 => 'فعال', 2 => 'محدود', 3 => 'معلق', 4 => 'مسدود', 9 => 'حذف‌شده'];
    /** Timed suspension lengths (days); 0 = until lifted by hand. */
    public const SUSPEND_DAYS = [1, 3, 7, 30];

    public function index(Request $request): Response
    {
        $countries = $this->scopeCountries($request, 'users.view');
        $q = trim((string) $request->query('q', ''));
        $status = (int) $request->query('status', '0');
        $country = (int) $request->query('country', '0');
        $before = (int) $request->query('before', '0');

        $where = ['1 = 1'];
        $bind = [];
        if ($q !== '') {
            if (str_contains($q, '@')) {
                $where[] = 'u.email LIKE ?';
                $bind[] = mb_strtolower($q) . '%';
            } elseif (preg_match('/^\+?[\d۰-۹\s-]{5,}$/u', $q)) {
                $where[] = 'u.phone LIKE ?';
                $bind[] = ltrim(preg_replace('/\D/', '', Str::latinDigits($q)) ?? '', '0') . '%';
            } else {
                $where[] = '(u.handle LIKE ? OR MATCH(u.search_name) AGAINST (? IN BOOLEAN MODE))';
                $bind[] = strtolower($q) . '%';
                $bind[] = implode(' ', array_map(static fn ($w) => '+' . $w . '*', preg_split('/\s+/u', Str::normalize($q)) ?: []));
            }
        }
        if (isset(self::STATUS[$status])) {
            $where[] = 'u.status = ?';
            $bind[] = $status;
        }
        if ($country > 0) {
            $where[] = 'u.country_id = ?';
            $bind[] = $country;
        }
        if ($countries !== null) {
            $where[] = $countries === [] ? '0 = 1' : 'u.country_id IN (' . implode(',', array_map('intval', $countries)) . ')';
        }
        if ($before > 0) {
            $where[] = 'u.id < ?';
            $bind[] = $before;
        }
        $rows = $this->c->get(Connection::class)->select(
            'SELECT u.id, u.first_name, u.last_name, u.email, u.handle, u.status, u.avatar_path, u.created_at, u.last_active_at,
                    u.business_verified_at, c.code AS country_code, w.balance
             FROM users u JOIN countries c ON c.id = u.country_id LEFT JOIN wallets w ON w.user_id = u.id
             WHERE ' . implode(' AND ', $where) . ' ORDER BY u.id DESC LIMIT 31',
            $bind
        );
        $next = null;
        if (count($rows) > 30) {
            array_pop($rows);
            $next = (int) end($rows)['id'];
        }
        return $this->view($request, 'admin/users', [
            'title' => 'کاربران',
            'rows' => $rows,
            'next' => $next,
            'q' => $q,
            'status' => $status,
            'country' => $country,
            'countries' => $this->c->get(ReferenceData::class)->countries(),
        ]);
    }

    public function show(Request $request, array $errors = [], int $httpStatus = 200): Response
    {
        $u = $this->target($request, 'users.view');
        $db = $this->c->get(Connection::class);
        $id = (int) $u['id'];
        return $this->view($request, 'admin/user', [
            'title' => trim($u['first_name'] . ' ' . $u['last_name']),
            'u' => $u,
            'counters' => $db->first('SELECT * FROM user_counters WHERE user_id = ?', [$id]) ?? [],
            'wallet' => $db->first('SELECT * FROM wallets WHERE user_id = ?', [$id]) ?? ['balance' => 0, 'reserved' => 0],
            'ledger' => $this->c->get(WalletService::class)->history($id, null, 15)['rows'],
            'pages' => $db->select('SELECT p.id, p.title, p.status, l.code FROM pages p JOIN languages l ON l.id = p.language_id WHERE p.user_id = ? AND p.deleted_at IS NULL', [$id]),
            'proposalCount' => (int) $db->scalar('SELECT COUNT(*) FROM proposals WHERE user_id = ? AND deleted_at IS NULL', [$id]),
            'roles' => $db->select('SELECT r.id, r.slug, r.name, (ur.user_id IS NOT NULL) AS assigned FROM roles r LEFT JOIN user_roles ur ON ur.role_id = r.id AND ur.user_id = ? ORDER BY r.id', [$id]),
            'assigned' => array_map('intval', array_column($db->select('SELECT country_id FROM staff_assignments WHERE user_id = ?', [$id]), 'country_id')),
            'countries' => $this->c->get(ReferenceData::class)->countries(),
            'logins' => $db->select('SELECT result, created_at FROM login_logs WHERE user_id = ? ORDER BY created_at DESC LIMIT 5', [$id]),
            'reportCount' => (int) $db->scalar('SELECT COUNT(*) FROM abuse_reports WHERE target_user_id = ?', [$id]),
            'errors' => $errors,
        ], 'layouts/app', $httpStatus);
    }

    public function setStatus(Request $request): Response
    {
        $u = $this->target($request, 'users.edit');
        $status = (int) $request->input('status', 0);
        $reason = mb_substr(trim((string) $request->input('reason', '')), 0, 255);
        $days = (int) $request->input('days', 0);
        if (!isset(self::STATUS[$status]) || $status === 9) {
            return $this->show($request, ['status' => 'وضعیت معتبر نیست.'], 422);
        }
        if ($status !== 1 && mb_strlen($reason) < 3) {
            return $this->show($request, ['status' => 'دلیل را بنویسید؛ کاربر آن را هنگام ورود یا در اعلان می‌بیند.'], 422);
        }
        if (in_array($status, [3, 4], true) && !$this->reauth($request)) {
            return $this->show($request, ['status' => 'برای تعلیق یا مسدودکردن، رمز عبور خود را درست وارد کنید.'], 422);
        }
        if ($this->c->get(\App\Core\Auth\Gate::class)->hasRole((int) $u['id'], 'super_admin') && $status !== 1) {
            return $this->show($request, ['status' => 'حساب مدیر کل را نمی‌توان مسدود کرد.'], 422);
        }
        $until = $status === 3 && in_array($days, self::SUSPEND_DAYS, true) ? gmdate('Y-m-d H:i:s', time() + $days * 86400) : null;
        $this->c->get(\App\Modules\Trust\TrustService::class)->setStatus($u, $status, $until, $status === 1 ? null : $reason);
        if ($status === 2 || ($status === 1 && (int) $u['status'] !== 1)) {
            $this->c->get(\App\Modules\Notifications\NotificationService::class)->notify([(int) $u['id']],
                $status === 2 ? 'trust_restricted' : 'trust_restored', null, '/account/safety', ['subject' => $reason]);
        }
        $this->audit($request, 'users.status', (int) $u['id'], ['status' => $status, 'reason' => $reason, 'until' => $until]);
        return $this->redirect('/admin/users/' . $u['id'], 'وضعیت کاربر به «' . self::STATUS[$status] . '» تغییر کرد.'
            . ($until !== null ? ' تعلیق تا ' . fa_date($until) . ' (خودکار برداشته می‌شود).' : ''));
    }

    public function verify(Request $request): Response
    {
        $u = $this->target($request, 'users.edit');
        $on = $u['business_verified_at'] === null;
        $this->c->get(Connection::class)->exec('UPDATE users SET business_verified_at = ' . ($on ? 'NOW(3)' : 'NULL') . ' WHERE id = ?', [$u['id']]);
        $this->c->get(Cache::class)->bump('owner:' . $u['id']);
        $this->audit($request, 'users.verify', (int) $u['id'], ['verified' => $on]);
        return $this->redirect('/admin/users/' . $u['id'], $on ? 'نشان «تأییدشده» داده شد.' : 'نشان «تأییدشده» برداشته شد.');
    }

    public function wallet(Request $request): Response
    {
        $u = $this->target($request, 'wallet.view');
        $direction = $request->input('direction') === 'debit' ? 'debit' : 'credit';
        if (!$this->allows($request, 'wallet.' . $direction)) {
            throw new HttpException(403);
        }
        // «۱٬۰۰۰», «1,000», «١٠٠٠» and «1000» all mean one thousand Stars (a separator used to cut the number short).
        $stars = (int) (preg_replace('/\D+/', '', Str::latinDigits((string) $request->input('stars', '0'))) ?: '0');
        $note = trim((string) $request->input('note', ''));
        if ($stars <= 0 || $stars > 1_000_000 || $note === '') {
            return $this->show($request, ['wallet' => 'تعداد Star و دلیل الزامی است.'], 422);
        }
        if (!$this->reauth($request)) {
            return $this->show($request, ['wallet' => 'رمز عبور شما درست نیست.'], 422);
        }
        $wallet = $this->c->get(WalletService::class);
        $admin = (int) $this->user($request)['id'];
        try {
            $direction === 'credit'
                ? $wallet->credit((int) $u['id'], $stars, WalletService::T_ADMIN_CREDIT, 'admin', null, null, null, $admin, mb_substr($note, 0, 255))
                : $wallet->adminDebit((int) $u['id'], $stars, $admin, mb_substr($note, 0, 255));
        } catch (InsufficientStars) {
            return $this->show($request, ['wallet' => 'موجودی کاربر کمتر از این مقدار است.'], 422);
        }
        $this->c->get(\App\Modules\Notifications\NotificationService::class)->notify([(int) $u['id']], 'wallet_' . $direction, $admin, '/wallet', [
            'n' => $stars, 'subject' => 'توضیح: ' . mb_substr($note, 0, 255),
        ]);
        $this->audit($request, 'wallet.' . $direction, (int) $u['id'], ['stars' => $stars, 'note' => $note]);
        return $this->redirect('/admin/users/' . $u['id'], fa_int($stars) . ' Star ' . ($direction === 'credit' ? 'اضافه' : 'کسر') . ' شد.');
    }

    public function roles(Request $request): Response
    {
        $u = $this->target($request, 'users.view');
        if (!$this->allows($request, 'roles.manage')) {
            throw new HttpException(403);
        }
        if (!$this->reauth($request)) {
            return $this->show($request, ['roles' => 'برای تغییر نقش‌ها رمز عبور خود را وارد کنید.'], 422);
        }
        $db = $this->c->get(Connection::class);
        $ids = array_map('intval', (array) $request->input('roles', []));
        $valid = array_map('intval', array_column($db->select('SELECT id FROM roles'), 'id'));
        $ids = array_values(array_intersect($ids, $valid));
        $superId = (int) $db->scalar("SELECT id FROM roles WHERE slug = 'super_admin'");
        if ((int) $u['id'] === (int) $this->user($request)['id'] && !in_array($superId, $ids, true)
            && $this->c->get(\App\Core\Auth\Gate::class)->hasRole((int) $u['id'], 'super_admin')) {
            return $this->show($request, ['roles' => 'نمی‌توانید نقش مدیر کل را از خودتان بگیرید.'], 422);
        }
        $countries = array_map('intval', (array) $request->input('countries', []));
        $db->transaction(function (Connection $db) use ($u, $ids, $countries): void {
            $db->exec('DELETE FROM user_roles WHERE user_id = ?', [$u['id']]);
            foreach ($ids as $rid) {
                $db->exec('INSERT INTO user_roles (user_id, role_id, created_at) VALUES (?, ?, NOW(3))', [$u['id'], $rid]);
            }
            $db->exec('DELETE FROM staff_assignments WHERE user_id = ?', [$u['id']]);
            foreach (array_slice(array_unique($countries), 0, 300) as $cid) {
                if ($cid > 0) {
                    $db->exec('INSERT INTO staff_assignments (user_id, country_id) VALUES (?, ?)', [$u['id'], $cid]);
                }
            }
        });
        $this->audit($request, 'roles.assign', (int) $u['id'], ['roles' => $ids, 'countries' => $countries]);
        return $this->redirect('/admin/users/' . $u['id'], 'نقش‌ها و حوزه دسترسی ذخیره شد.');
    }

    /** Super Admin only: act as the user (support). Banner + audit + time-boxed record. */
    public function impersonate(Request $request): Response
    {
        $u = $this->target($request, 'users.view');
        $admin = $this->user($request);
        if (!$this->c->get(\App\Core\Auth\Gate::class)->hasRole((int) $admin['id'], 'super_admin') || (int) $u['id'] === (int) $admin['id']) {
            throw new HttpException(403);
        }
        $reason = trim((string) $request->input('reason', ''));
        if ($reason === '' || !$this->reauth($request)) {
            return $this->show($request, ['impersonate' => 'دلیل و رمز عبور خود را وارد کنید.'], 422);
        }
        $db = $this->c->get(Connection::class);
        $logId = $db->insert(
            'INSERT INTO impersonations (admin_id, target_user_id, reason, ip, started_at) VALUES (?, ?, ?, ?, NOW(3))',
            [$admin['id'], $u['id'], mb_substr($reason, 0, 255), @inet_pton($request->ip()) ?: null]
        );
        $this->audit($request, 'users.impersonate', (int) $u['id'], ['reason' => $reason]);
        Session::regenerate();
        Session::put('_impersonator', (int) $admin['id']);
        Session::put('_impersonation_id', $logId);
        Session::put('_uid', (int) $u['id']);
        return Response::redirect('/dashboard', 303);
    }

    public function stopImpersonating(Request $request): Response
    {
        $adminId = Session::get('_impersonator');
        if ($adminId === null) {
            return Response::redirect('/dashboard', 303);
        }
        $this->c->get(Connection::class)->exec('UPDATE impersonations SET ended_at = NOW(3) WHERE id = ?', [(int) Session::get('_impersonation_id', 0)]);
        $target = (int) Session::get('_uid', 0);
        Session::regenerate();
        Session::forget('_impersonator');
        Session::forget('_impersonation_id');
        Session::put('_uid', (int) $adminId);
        $this->c->get(Audit::class)->log('users.impersonate_end', (int) $adminId, 'user', $target, 'success', $request);
        return Response::redirect('/admin/users/' . $target, 303);
    }

    private function target(Request $request, string $permission): array
    {
        $u = $this->c->get(Connection::class)->first(
            'SELECT u.*, c.code AS country_code, c.name_fa AS country_fa, l.name_fa AS language_fa
             FROM users u JOIN countries c ON c.id = u.country_id JOIN languages l ON l.id = u.language_id WHERE u.id = ?',
            [(int) $request->param('id')]
        );
        if ($u === null || !$this->inScope($request, $permission, (int) $u['country_id'])) {
            throw new HttpException(404);
        }
        return $u;
    }

    private function audit(Request $request, string $action, int $targetId, array $meta): void
    {
        $this->c->get(Audit::class)->log($action, (int) $this->user($request)['id'], 'user', $targetId, 'success', $request, $meta,
            $request->attribute('impersonator_id'));
    }
}
