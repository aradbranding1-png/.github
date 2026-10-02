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

    public function finance(Request $request): Response
    {
        $db = $this->c->get(Connection::class);
        $metrics = $this->c->get(MetricsService::class);
        $status = $request->query('status');
        $before = (int) $request->query('before', '0');
        $where = ['1 = 1'];
        $bind = [];
        if (is_string($status) && ctype_digit($status)) {
            $where[] = 'p.status = ?';
            $bind[] = (int) $status;
        }
        if ($before > 0) {
            $where[] = 'p.id < ?';
            $bind[] = $before;
        }
        $payments = $db->select(
            'SELECT p.id, p.amount_minor, p.stars, p.bonus_stars, p.status, p.gateway_ref, p.created_at, g.name AS gateway,
                    u.id AS user_id, u.first_name, u.last_name
             FROM payments p JOIN payment_gateways g ON g.id = p.gateway_id JOIN users u ON u.id = p.user_id
             WHERE ' . implode(' AND ', $where) . ' ORDER BY p.id DESC LIMIT 31',
            $bind
        );
        $next = null;
        if (count($payments) > 30) {
            array_pop($payments);
            $next = (int) end($payments)['id'];
        }
        $sum = static function (string $m) use ($metrics): int {
            return array_sum(array_column($metrics->series($m, 'daily', 30), 'value'));
        };
        return $this->view($request, 'admin/finance', [
            'title' => 'مالی',
            'payments' => $payments,
            'next' => $next,
            'status' => is_string($status) ? $status : '',
            'month' => [
                'revenue' => $sum('revenue_rial'), 'payments' => $sum('payments'), 'sold' => $sum('stars_sold'),
                'used' => $sum('stars_used'), 'bonus' => $sum('stars_bonus'),
            ],
            'totals' => $metrics->totals(),
            'revenueSeries' => $metrics->series('revenue_rial', 'daily', 30),
        ]);
    }
}
