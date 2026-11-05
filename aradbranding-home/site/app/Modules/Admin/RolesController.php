<?php

declare(strict_types=1);

namespace App\Modules\Admin;

use App\Core\Db\Connection;
use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Security\Audit;

/** Dynamic roles: permission matrix with data scope. Super Admin stays complete and locked. */
final class RolesController extends AdminController
{
    public const PERMISSION_LABELS = [
        'users.view' => 'مشاهده کاربران', 'users.edit' => 'ویرایش و تعلیق کاربران', 'users.delete' => 'حذف کاربران', 'users.impersonate' => 'ورود به جای کاربر',
        'pages.view' => 'مشاهده صفحه‌ها', 'pages.edit' => 'ویرایش صفحه‌ها', 'pages.approve' => 'بررسی صفحه‌ها',
        'proposals.view' => 'مشاهده پیشنهادها', 'proposals.moderate' => 'بررسی پیشنهادها',
        'letters.view' => 'مشاهده نامه‌ها', 'letters.moderate' => 'بررسی نامه‌ها', 'letters.official' => 'نامه رسمی آراد برندینگ',
        'wallet.view' => 'مشاهده کیف پول', 'wallet.credit' => 'افزایش Stars', 'wallet.debit' => 'کاهش Stars',
        'payments.view' => 'مشاهده پرداخت‌ها', 'payments.refund' => 'بازپرداخت',
        'reports.view' => 'گزارش‌ها', 'reports.export' => 'خروجی Excel', 'audit.view' => 'رویدادهای امنیتی',
        'settings.manage' => 'تنظیمات', 'roles.manage' => 'نقش‌ها', 'backup.manage' => 'پشتیبان‌گیری', 'updates.manage' => 'بروزرسانی و تازه‌ها',
    ];
    /** Built-in roles: slug => [Persian name, what the role is for]. Custom roles keep the name they were given. */
    public const ROLE_INFO = [
        'super_admin' => ['مدیر کل', 'همه بخش‌های سامانه، بدون هیچ محدودیتی.'],
        'admin' => ['مدیر', 'مدیریت کاربران، صفحه‌ها، پیشنهادها و نامه‌ها در همه کشورها.'],
        'operations_manager' => ['مدیر عملیات', 'کاربران، صفحه‌ها و پیشنهادهای دپارتمان خود را مدیریت می‌کند.'],
        'finance_manager' => ['مدیر مالی', 'کیف پول، Stars، پرداخت‌ها و بازپرداخت‌ها.'],
        'support_manager' => ['مدیر پشتیبانی', 'کاربران تیم خود را می‌بیند و برای پشتیبانی ویرایش می‌کند.'],
        'content_manager' => ['مدیر محتوا', 'بررسی و تأیید صفحه‌های تجاری و پیشنهادها.'],
        'moderation_manager' => ['مدیر نظارت', 'تأیید یا رد صفحه‌ها، پیشنهادها و نامه‌های تیم خود.'],
        'analyst' => ['تحلیلگر', 'فقط گزارش‌ها و خروجی Excel؛ چیزی را تغییر نمی‌دهد.'],
        'customer' => ['کاربر عادی', 'فقط از امکانات حساب خودش استفاده می‌کند؛ به بخش مدیریت دسترسی ندارد.'],
        'verified_customer' => ['کاربر تأییدشده', 'کاربر عادی با نشان تأیید کسب‌وکار؛ به بخش مدیریت دسترسی ندارد.'],
        'restricted_customer' => ['کاربر محدودشده', 'کاربری که امکان ارسال و انتشار از او گرفته شده است.'],
    ];

    /**
     * Adds `label` (Persian name) and `tip` (what the role can do, for a hover hint) to role rows that carry id, slug, name.
     * @param list<array<string, mixed>> $roles
     * @return list<array<string, mixed>>
     */
    public static function describe(array $roles, Connection $db): array
    {
        $grants = [];
        foreach ($db->select('SELECT rp.role_id, p.code, rp.scope FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id ORDER BY p.module, p.code') as $g) {
            $grants[(int) $g['role_id']][] = (self::PERMISSION_LABELS[$g['code']] ?? $g['code']) . ' (' . (self::SCOPES[$g['scope']] ?? $g['scope']) . ')';
        }
        foreach ($roles as &$r) {
            [$label, $about] = self::ROLE_INFO[$r['slug']] ?? [$r['name'], ''];
            $list = $grants[(int) $r['id']] ?? [];
            $tip = $label . ($about !== '' ? ' — ' . $about : '');
            if ($r['slug'] === 'super_admin') {
                $tip .= "\nدسترسی‌ها: همه";
            } elseif ($list !== []) {
                $tip .= "\nدسترسی‌ها:\n• " . implode("\n• ", $list);
            } else {
                $tip .= "\nدسترسی به بخش مدیریت ندارد.";
            }
            $r['about'] = $about;
            $r['perm_count'] = $r['slug'] === 'super_admin' ? -1 : count($list);
            $r['label'] = $label;
            $r['tip'] = $tip;
        }
        return $roles;
    }

    public const SCOPES = ['own' => 'خودش', 'team' => 'تیم (کشورهای تخصیص‌یافته)', 'department' => 'دپارتمان', 'all' => 'همه'];

    public function index(Request $request): Response
    {
        $db = $this->c->get(Connection::class);
        return $this->view($request, 'admin/roles', [
            'title' => 'نقش‌ها و دسترسی‌ها',
            'roles' => self::describe($db->select('SELECT r.id, r.slug, r.name, r.is_system, (SELECT COUNT(*) FROM user_roles ur WHERE ur.role_id = r.id) AS users FROM roles r ORDER BY r.id'), $db),
            'edit' => null,
        ]);
    }

    public function edit(Request $request): Response
    {
        $db = $this->c->get(Connection::class);
        $role = $db->first('SELECT * FROM roles WHERE id = ?', [(int) $request->param('id')]);
        if ($role === null) {
            throw new HttpException(404);
        }
        $granted = array_column($db->select(
            'SELECT p.code, rp.scope FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = ?',
            [$role['id']]
        ), 'scope', 'code');
        return $this->view($request, 'admin/role', [
            'title' => 'نقش: ' . (self::ROLE_INFO[$role['slug']][0] ?? $role['name']),
            'role' => $role,
            'permissions' => array_column($db->select('SELECT code FROM permissions ORDER BY module, code'), 'code'),
            'granted' => $granted,
        ]);
    }

    public function save(Request $request): Response
    {
        $db = $this->c->get(Connection::class);
        $id = (int) $request->param('id');
        $role = $db->first('SELECT * FROM roles WHERE id = ?', [$id]);
        if ($role === null) {
            throw new HttpException(404);
        }
        if ($role['slug'] === 'super_admin') {
            return $this->redirect('/admin/roles', 'دسترسی‌های مدیر کل قابل تغییر نیست.', 'error');
        }
        $name = trim((string) $request->input('name', $role['name']));
        $perms = (array) $request->input('perm', []);
        $db->transaction(function (Connection $db) use ($id, $name, $perms): void {
            $db->exec('UPDATE roles SET name = ? WHERE id = ?', [mb_substr($name, 0, 80), $id]);
            $db->exec('DELETE FROM role_permissions WHERE role_id = ?', [$id]);
            foreach ($perms as $code => $scope) {
                if (!is_string($scope) || !isset(self::SCOPES[$scope])) {
                    continue;
                }
                $db->exec('INSERT INTO role_permissions (role_id, permission_id, scope) SELECT ?, id, ? FROM permissions WHERE code = ?', [$id, $scope, (string) $code]);
            }
        });
        $this->c->get(Audit::class)->log('roles.update', (int) $this->user($request)['id'], 'role', $id, 'success', $request, ['perms' => $perms]);
        return $this->redirect('/admin/roles/' . $id, 'دسترسی‌های نقش ذخیره شد.');
    }

    public function create(Request $request): Response
    {
        $name = trim((string) $request->input('name', ''));
        $slug = strtolower(trim((string) $request->input('slug', '')));
        if ($name === '' || !preg_match('/^[a-z][a-z0-9_]{2,39}$/', $slug)) {
            return $this->redirect('/admin/roles', 'نام و شناسه انگلیسی (حروف کوچک و _) لازم است.', 'error');
        }
        try {
            $id = $this->c->get(Connection::class)->insert('INSERT INTO roles (slug, name, is_system) VALUES (?, ?, 0)', [$slug, $name]);
        } catch (\PDOException) {
            return $this->redirect('/admin/roles', 'این شناسه قبلاً استفاده شده است.', 'error');
        }
        $this->c->get(Audit::class)->log('roles.create', (int) $this->user($request)['id'], 'role', $id, 'success', $request, ['slug' => $slug]);
        return $this->redirect('/admin/roles/' . $id, 'نقش ساخته شد؛ دسترسی‌هایش را تعیین کنید.');
    }
}
