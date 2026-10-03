<?php

declare(strict_types=1);

namespace App\Modules\Admin;

use App\Core\Db\Connection;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Modules\Reference\ReferenceData;

/** /admin, /admin/reports, /admin/finance — all read from aggregated data. */
final class DashboardAdminController extends AdminController
{
    public function dashboard(Request $request): Response
    {
        $perms = $this->c->get(\App\Core\Auth\Gate::class)->permissions((int) $this->user($request)['id']);
        if ($perms === []) {
            throw new \App\Core\Http\HttpException(403);
        }
        if (!isset($perms['reports.view']) && !isset($perms['settings.manage'])) {
            // Staff without reports go straight to their first section.
            return $this->redirect(isset($perms['users.view']) ? '/admin/users' : '/admin/content');
        }
        $metrics = $this->c->get(MetricsService::class);
        $db = $this->c->get(Connection::class);
        $range = in_array((int) $request->query('range', '30'), [7, 30, 60], true) ? (int) $request->query('range', '30') : 30;

        // One query for every daily metric of the last 61 days: KPIs, growth, sparklines and the trend chart.
        $window = $metrics->window(61);
        $days = [];
        for ($i = 60; $i >= 0; $i--) {
            $days[] = gmdate('Y-m-d', strtotime("-{$i} day"));
        }
        $val = static fn (string $day, string $metric): int => (int) ($window[$day][$metric] ?? 0);
        $series = static function (string ...$metricList) use ($days, $val): array {
            return array_map(static fn (string $d): int => array_sum(array_map(static fn (string $m): int => $val($d, $m), $metricList)), $days);
        };
        $today = $window[gmdate('Y-m-d')] ?? [];
        $yesterday = $window[gmdate('Y-m-d', strtotime('-1 day'))] ?? [];

        $countries = [];
        foreach ($this->c->get(ReferenceData::class)->countries() as $country) {
            $countries[(string) $country['code']] = (string) $country['name_fa'];
        }
        $todayStart = gmdate('Y-m-d') . ' 00:00:00';

        // Latest real platform events: new members, pages, proposals and admin actions.
        $activity = $db->select(
            "(SELECT 'user' AS kind, u.id, u.first_name, u.last_name, u.avatar_path, NULL AS title, NULL AS result, u.created_at
               FROM users u WHERE u.deleted_at IS NULL ORDER BY u.id DESC LIMIT 6)
             UNION ALL
             (SELECT 'page', p.id, u.first_name, u.last_name, u.avatar_path, p.title, NULL, p.created_at
               FROM pages p JOIN users u ON u.id = p.user_id WHERE p.deleted_at IS NULL ORDER BY p.id DESC LIMIT 6)
             UNION ALL
             (SELECT 'proposal', p.id, u.first_name, u.last_name, u.avatar_path, p.title, NULL, p.created_at
               FROM proposals p JOIN users u ON u.id = p.user_id WHERE p.deleted_at IS NULL ORDER BY p.id DESC LIMIT 6)
             UNION ALL
             (SELECT a.action, a.id, u.first_name, u.last_name, u.avatar_path, NULL, a.result, a.created_at
               FROM audit_logs a LEFT JOIN users u ON u.id = a.actor_id ORDER BY a.id DESC LIMIT 6)
             ORDER BY created_at DESC LIMIT 6"
        );
        $feed = array_map(static function (array $r): array {
            $card = json_decode((string) $r['card'], true) ?: [];
            return ['card' => $card, 'views' => (int) $r['views_7d'], 'type' => (int) $r['type'], 'published_at' => (string) $r['published_at']];
        }, $db->select('SELECT card, views_7d, type, published_at FROM proposal_feed ORDER BY published_at DESC, proposal_id DESC LIMIT 4'));
        $securityIssues = (int) $db->scalar("SELECT COUNT(*) FROM audit_logs WHERE created_at >= ? AND result <> 'success'", [$todayStart]);

        return $this->view($request, 'admin/dashboard', [
            'title' => 'داشبورد مدیریت',
            'totals' => $metrics->totals(),
            'today' => $today,
            'yesterday' => $yesterday,
            'days' => $days,
            'range' => $range,
            'trend' => [
                'comms' => $series('letters_private', 'letters_public', 'proposal_threads', 'replies'),
                'pages' => $series('new_pages'),
                'proposals' => $series('new_proposals'),
                'users' => $series('new_users'),
            ],
            'spark' => [
                'new_users' => $series('new_users'), 'new_pages' => $series('new_pages'), 'dau' => $series('dau'),
                'wau' => $series('wau'), 'mau' => $series('mau'), 'connections' => $series('connections'), 'new_proposals' => $series('new_proposals'),
            ],
            'revenue' => $series('revenue_rial'),
            'activity' => $activity,
            'feed' => $feed,
            'countries' => $countries,
            'securityIssues' => $securityIssues,
        ]);
    }

    public function reports(Request $request): Response
    {
        $metrics = $this->c->get(MetricsService::class);
        $metric = (string) $request->query('metric', 'new_users');
        if (!isset(MetricsService::LABELS[$metric])) {
            $metric = 'new_users';
        }
        $period = (string) $request->query('period', 'daily');
        if (!in_array($period, ['daily', 'weekly', 'monthly', 'yearly'], true)) {
            $period = 'daily';
        }
        return $this->view($request, 'admin/reports', [
            'title' => 'گزارش‌ها',
            'metric' => $metric,
            'period' => $period,
            'series' => $metrics->series($metric, $period, $period === 'daily' ? 30 : 12),
            'byCountry' => $metrics->byCountry(),
            'leaders' => $metrics->leaders(),
            'countries' => $this->c->get(ReferenceData::class)->countries(),
        ]);
    }

    /** Audience of a payer: staff (any non-customer role) or the member's trade role. */
    private const STAFF_SQL = "EXISTS (SELECT 1 FROM user_roles ur JOIN roles r ON r.id = ur.role_id
                                WHERE ur.user_id = u.id AND r.slug NOT IN ('customer', 'verified_customer', 'restricted_customer'))";

    /** @return array<string, string> audience key => label */
    public static function audiences(): array
    {
        return ['staff' => 'کارکنان سامانه'] + \App\Modules\Users\TradeRoles::TRADE + ['unset' => 'تاجر (نقش ثبت‌نشده)'];
    }

    public function finance(Request $request, array $errors = [], int $httpStatus = 200): Response
    {
        $db = $this->c->get(Connection::class);
        $metrics = $this->c->get(MetricsService::class);
        $status = $request->query('status');
        $audience = (string) $request->query('audience', '');
        $days = (int) $request->query('days', '30');
        $days = in_array($days, [7, 30, 90, 365], true) ? $days : 30;
        $before = (int) $request->query('before', '0');
        $audienceSql = 'CASE WHEN ' . self::STAFF_SQL . " THEN 'staff' ELSE COALESCE(NULLIF(up.trade_role, ''), 'unset') END";

        $where = ['p.deleted_at IS NULL'];
        $bind = [];
        if (is_string($status) && ctype_digit($status)) {
            $where[] = 'p.status = ?';
            $bind[] = (int) $status;
        }
        if (isset(self::audiences()[$audience])) {
            $where[] = $audienceSql . ' = ?';
            $bind[] = $audience;
        } else {
            $audience = '';
        }
        if ($before > 0) {
            $where[] = 'p.id < ?';
            $bind[] = $before;
        }
        $payments = $db->select(
            'SELECT p.id, p.amount_minor, p.stars, p.bonus_stars, p.status, p.gateway_ref, p.created_at, g.name AS gateway,
                    u.id AS user_id, u.first_name, u.last_name, ' . $audienceSql . ' AS audience
             FROM payments p JOIN payment_gateways g ON g.id = p.gateway_id JOIN users u ON u.id = p.user_id
             LEFT JOIN user_profiles up ON up.user_id = u.id
             WHERE ' . implode(' AND ', $where) . ' ORDER BY p.id DESC LIMIT 31',
            $bind
        );
        $next = null;
        if (count($payments) > 30) {
            array_pop($payments);
            $next = (int) end($payments)['id'];
        }

        // Live figures for the chosen period (deleted payments excluded, so removing a test payment updates them at once).
        $paid = \App\Modules\Payments\PaymentService::CREDITED;
        $byAudience = $db->select(
            'SELECT ' . $audienceSql . ' AS audience, COUNT(*) AS payments, COUNT(DISTINCT p.user_id) AS payers,
                    COALESCE(SUM(p.amount_minor), 0) AS amount, COALESCE(SUM(p.stars), 0) AS stars, COALESCE(SUM(p.bonus_stars), 0) AS bonus
               FROM payments p JOIN users u ON u.id = p.user_id LEFT JOIN user_profiles up ON up.user_id = u.id
              WHERE p.deleted_at IS NULL AND p.status = ? AND p.updated_at >= NOW(3) - INTERVAL ' . $days . ' DAY
              GROUP BY audience ORDER BY amount DESC',
            [$paid]
        );
        $sum = static fn (string $k): int => (int) array_sum(array_column($byAudience, $k));
        $ledger = $db->first(
            'SELECT COALESCE(SUM(CASE WHEN type = 6 THEN amount END), 0) AS gifted,
                    COALESCE(SUM(CASE WHEN type = 7 THEN amount END), 0) AS admin_credit,
                    COALESCE(-SUM(CASE WHEN type IN (2, 3, 4) THEN amount END), 0) AS used,
                    COALESCE(SUM(CASE WHEN type = 5 THEN amount END), 0) AS refunded
               FROM wallet_transactions WHERE created_at >= NOW(3) - INTERVAL ' . $days . ' DAY'
        ) ?? [];
        $series = [];
        foreach ($db->select(
            'SELECT DATE(updated_at) AS d, SUM(amount_minor) AS v FROM payments
              WHERE deleted_at IS NULL AND status = ? AND updated_at >= CURRENT_DATE - INTERVAL 29 DAY GROUP BY d ORDER BY d',
            [$paid]
        ) as $r) {
            $series[] = ['label' => (string) $r['d'], 'value' => (int) $r['v']];
        }

        return $this->view($request, 'admin/finance', [
            'title' => 'مالی',
            'payments' => $payments,
            'next' => $next,
            'status' => is_string($status) ? $status : '',
            'audience' => $audience,
            'days' => $days,
            'audiences' => self::audiences(),
            'byAudience' => $byAudience,
            'month' => [
                'revenue' => $sum('amount'), 'payments' => $sum('payments'), 'sold' => $sum('stars'), 'bonus' => $sum('bonus'),
                'gifted' => (int) ($ledger['gifted'] ?? 0), 'admin' => (int) ($ledger['admin_credit'] ?? 0),
                'used' => (int) ($ledger['used'] ?? 0), 'refunded' => (int) ($ledger['refunded'] ?? 0),
            ],
            'totals' => $metrics->totals(),
            'revenueSeries' => $series,
            'canDelete' => $this->allows($request, 'payments.delete'),
            'errors' => $errors,
        ], 'layouts/app', $httpStatus);
    }

    /**
     * POST /admin/finance/payments/{id}/delete — removes a payment from the finance reports (soft delete, audited).
     * With reverse=1 the Stars it credited (purchase + bonus) are taken back from the wallet as well.
     */
    public function deletePayment(Request $request): Response
    {
        $db = $this->c->get(Connection::class);
        $p = $db->first('SELECT * FROM payments WHERE id = ? AND deleted_at IS NULL', [(int) $request->param('id')]);
        if ($p === null) {
            throw new \App\Core\Http\HttpException(404);
        }
        $reason = mb_substr(trim((string) $request->input('reason', '')), 0, 255);
        $fail = fn (string $m): Response => $this->finance($request, ['pay' . $p['id'] => $m], 422);
        if (mb_strlen($reason) < 3) {
            return $fail('دلیل حذف را بنویسید (مثلاً «پرداخت آزمایشی»).');
        }
        if (!$this->reauth($request)) {
            return $fail('رمز عبور شما درست نیست.');
        }
        $admin = (int) $this->user($request)['id'];
        $reversed = 0;
        $credited = (int) $p['status'] === \App\Modules\Payments\PaymentService::CREDITED;
        if ($credited && (string) $request->input('reverse', '') === '1') {
            $reversed = (int) $p['stars'] + (int) $p['bonus_stars'];
            try {
                $this->c->get(\App\Modules\Wallet\WalletService::class)->adminDebit((int) $p['user_id'], $reversed, $admin,
                    'برگشت Stars پرداخت حذف‌شده: ' . $reason, 'payment-delete:' . $p['id']);
            } catch (\App\Modules\Wallet\InsufficientStars $e) {
                return $fail('موجودی کاربر (' . fa_int($e->balance) . ' Star) کمتر از Stars این پرداخت است؛ بدون برگشت Stars حذف کنید یا ابتدا موجودی را اصلاح کنید.');
            }
        }
        $db->exec('UPDATE payments SET deleted_at = NOW(3), deleted_by = ?, delete_reason = ? WHERE id = ? AND deleted_at IS NULL', [$admin, $reason, $p['id']]);
        $this->c->get(\App\Core\Security\Audit::class)->log('payments.delete', $admin, 'payment', (int) $p['id'], 'success', $request,
            ['user' => (int) $p['user_id'], 'amount_minor' => (int) $p['amount_minor'], 'stars' => (int) $p['stars'], 'bonus' => (int) $p['bonus_stars'],
                'status' => (int) $p['status'], 'reason' => $reason, 'reversed_stars' => $reversed], $request->attribute('impersonator_id'));
        return $this->redirect('/admin/finance', 'پرداخت از گزارش‌های مالی حذف شد' . ($reversed > 0 ? ' و ' . fa_int($reversed) . ' Star آن از کیف پول کاربر برگشت داده شد.' : '.'));
    }
}
