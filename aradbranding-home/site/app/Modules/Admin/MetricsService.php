<?php

declare(strict_types=1);

namespace App\Modules\Admin;

use App\Core\Cache\Cache;
use App\Core\Db\Connection;

/**
 * Aggregated metrics for dashboards and reports (architecture doc §86).
 * The cron fills `daily_metrics` hourly for today and yesterday using day-range index scans;
 * dashboards never COUNT millions of rows on request.
 */
final class MetricsService
{
    public const LABELS = [
        'new_users' => 'کاربران جدید', 'dau' => 'کاربران فعال روزانه', 'new_pages' => 'صفحه‌های جدید',
        'new_proposals' => 'پیشنهادهای جدید', 'letters_private' => 'نامه‌های اختصاصی', 'letters_public' => 'گفتگوهای نامه عمومی',
        'proposal_threads' => 'ارسال پیشنهاد', 'replies' => 'پاسخ‌ها', 'connections' => 'ارتباطات جدید',
        'page_views' => 'مشاهده صفحه (پولی)', 'payments' => 'پرداخت‌های موفق', 'revenue_rial' => 'درآمد (ریال)',
        'stars_sold' => 'Stars فروخته‌شده', 'stars_used' => 'Stars مصرف‌شده', 'stars_bonus' => 'Stars هدیه',
    ];

    public function __construct(private Connection $db, private Cache $cache)
    {
    }

    /** Recompute one UTC day. Safe to repeat (upserts). */
    public function computeDay(string $day): void
    {
        $from = $day . ' 00:00:00';
        $to = date('Y-m-d', strtotime($day . ' +1 day')) . ' 00:00:00';
        $r = [$from, $to];
        $m = [];

        $m['new_users'] = (int) $this->db->scalar('SELECT COUNT(*) FROM users WHERE created_at >= ? AND created_at < ?', $r);
        $m['new_pages'] = (int) $this->db->scalar('SELECT COUNT(*) FROM pages WHERE created_at >= ? AND created_at < ?', $r);
        $m['new_proposals'] = (int) $this->db->scalar('SELECT COUNT(*) FROM proposals WHERE created_at >= ? AND created_at < ?', $r);
        foreach ($this->db->select('SELECT type, COUNT(*) AS n FROM letter_threads WHERE created_at >= ? AND created_at < ? GROUP BY type', $r) as $t) {
            $key = [1 => 'letters_private', 2 => 'letters_public', 3 => 'proposal_threads', 4 => 'letters_official'][(int) $t['type']] ?? null;
            if ($key) {
                $m[$key] = (int) $t['n'];
            }
        }
        $messages = (int) $this->db->scalar('SELECT COUNT(*) FROM letter_messages WHERE created_at >= ? AND created_at < ?', $r);
        $threads = ($m['letters_private'] ?? 0) + ($m['letters_public'] ?? 0) + ($m['proposal_threads'] ?? 0) + ($m['letters_official'] ?? 0);
        $m['replies'] = max(0, $messages - $threads);
        $m['connections'] = intdiv((int) $this->db->scalar('SELECT COUNT(*) FROM user_connections WHERE created_at >= ? AND created_at < ?', $r), 2);

        $pay = $this->db->first('SELECT COUNT(*) AS n, COALESCE(SUM(amount_minor), 0) AS s FROM payments WHERE status = 4 AND updated_at >= ? AND updated_at < ?', $r);
        $m['payments'] = (int) $pay['n'];
        $m['revenue_rial'] = (int) $pay['s'];

        $sums = [];
        foreach ($this->db->select(
            'SELECT type, reason, COALESCE(SUM(amount), 0) AS s, COUNT(*) AS n FROM wallet_transactions
             WHERE type IN (1, 2, 3, 4, 6) AND created_at >= ? AND created_at < ? GROUP BY type, reason',
            $r
        ) as $w) {
            $sums[(int) $w['type']][(string) $w['reason']] = ['s' => (int) $w['s'], 'n' => (int) $w['n']];
        }
        $sumType = static fn (int $t): int => array_sum(array_column($sums[$t] ?? [], 's'));
        $m['stars_sold'] = $sumType(1);
        $m['stars_bonus'] = $sumType(6);
        $m['stars_used'] = -($sumType(2) + $sumType(3)) - $sumType(4);
        $m['page_views'] = (int) ($sums[2]['page_view']['n'] ?? 0);

        if ($day === gmdate('Y-m-d')) {
            $m['dau'] = (int) $this->db->scalar('SELECT COUNT(*) FROM users WHERE last_active_at >= ?', [$from]);
            $m['wau'] = (int) $this->db->scalar('SELECT COUNT(*) FROM users WHERE last_active_at >= NOW(3) - INTERVAL 7 DAY');
            $m['mau'] = (int) $this->db->scalar('SELECT COUNT(*) FROM users WHERE last_active_at >= NOW(3) - INTERVAL 30 DAY');
        }

        foreach ($m as $metric => $value) {
            $this->db->exec(
                'INSERT INTO daily_metrics (day, metric, country_id, value, updated_at) VALUES (?, ?, 0, ?, NOW(3))
                 ON DUPLICATE KEY UPDATE value = ?, updated_at = NOW(3)',
                [$day, $metric, $value, $value]
            );
        }
        // Country dimension for new users (country growth report).
        foreach ($this->db->select('SELECT country_id, COUNT(*) AS n FROM users WHERE created_at >= ? AND created_at < ? GROUP BY country_id', $r) as $c) {
            $this->db->exec(
                'INSERT INTO daily_metrics (day, metric, country_id, value, updated_at) VALUES (?, ?, ?, ?, NOW(3))
                 ON DUPLICATE KEY UPDATE value = ?, updated_at = NOW(3)',
                [$day, 'new_users', (int) $c['country_id'], (int) $c['n'], (int) $c['n']]
            );
        }
    }

    /** @return array<string, int> metric => value for one day */
    public function day(string $day): array
    {
        return array_map('intval', array_column(
            $this->db->select('SELECT metric, value FROM daily_metrics WHERE day = ? AND country_id = 0', [$day]),
            'value',
            'metric'
        ));
    }

    /**
     * Every daily metric of the last N days in ONE query (admin dashboard: KPIs, growth, sparklines, trend chart).
     * @return array<string, array<string, int>> 'Y-m-d' => metric => value
     */
    public function window(int $days): array
    {
        $out = [];
        foreach ($this->db->select(
            'SELECT day, metric, value FROM daily_metrics WHERE country_id = 0 AND day >= CURRENT_DATE - INTERVAL ? DAY ORDER BY day',
            [$days]
        ) as $r) {
            $out[(string) $r['day']][(string) $r['metric']] = (int) $r['value'];
        }
        return $out;
    }

    /**
     * Series for charts: grouped by day / week / month / year.
     * @return list<array{label: string, value: int}>
     */
    public function series(string $metric, string $period, int $points): array
    {
        $days = ['daily' => $points, 'weekly' => $points * 7, 'monthly' => $points * 31, 'yearly' => $points * 366][$period] ?? $points;
        $rows = $this->db->select(
            'SELECT day, value FROM daily_metrics WHERE metric = ? AND country_id = 0 AND day >= CURRENT_DATE - INTERVAL ? DAY ORDER BY day',
            [$metric, $days]
        );
        $buckets = [];
        foreach ($rows as $r) {
            $ts = strtotime((string) $r['day']);
            $key = match ($period) {
                'weekly' => date('o-\WW', $ts),
                'monthly' => date('Y-m', $ts),
                'yearly' => date('Y', $ts),
                default => (string) $r['day'],
            };
            $buckets[$key] = ($buckets[$key] ?? 0) + (int) $r['value'];
        }
        $out = [];
        foreach (array_slice($buckets, -$points, null, true) as $label => $value) {
            $out[] = ['label' => (string) $label, 'value' => $value];
        }
        return $out;
    }

    /** Live totals, cached 10 minutes (COUNT on big tables never runs per request). @return array<string, int> */
    public function totals(): array
    {
        return $this->cache->remember('admin:totals', 600, fn (): array => [
            'users' => (int) $this->db->scalar('SELECT COUNT(*) FROM users WHERE deleted_at IS NULL'),
            'pages' => (int) $this->db->scalar('SELECT COUNT(*) FROM pages WHERE status = 2 AND deleted_at IS NULL'),
            'proposals' => (int) $this->db->scalar('SELECT COUNT(*) FROM proposal_feed'),
            'connections' => intdiv((int) $this->db->scalar('SELECT COUNT(*) FROM user_connections'), 2),
            'countries' => (int) $this->db->scalar('SELECT COUNT(DISTINCT country_id) FROM users WHERE deleted_at IS NULL'),
            'languages' => (int) $this->db->scalar('SELECT COUNT(DISTINCT language_id) FROM pages WHERE status = 2 AND deleted_at IS NULL'),
            'wallets' => (int) $this->db->scalar('SELECT COUNT(*) FROM wallets WHERE balance > 0'),
            'stars_in_wallets' => (int) $this->db->scalar('SELECT COALESCE(SUM(balance), 0) FROM wallets'),
        ]);
    }

    /**
     * Country breakdown (users, pages, proposals, letters sent by users of each country), cached 1 hour.
     * @return list<array<string, mixed>>
     */
    public function byCountry(): array
    {
        return $this->cache->remember('admin:by_country', 3600, function (): array {
            $users = array_column($this->db->select('SELECT country_id, COUNT(*) AS n FROM users WHERE deleted_at IS NULL GROUP BY country_id'), 'n', 'country_id');
            $pages = array_column($this->db->select('SELECT country_id, COUNT(*) AS n FROM pages WHERE status = 2 AND deleted_at IS NULL GROUP BY country_id'), 'n', 'country_id');
            $props = array_column($this->db->select('SELECT country_id, COUNT(*) AS n FROM proposal_feed GROUP BY country_id'), 'n', 'country_id');
            $out = [];
            foreach ($users as $cid => $n) {
                $out[] = ['country_id' => (int) $cid, 'users' => (int) $n, 'pages' => (int) ($pages[$cid] ?? 0), 'proposals' => (int) ($props[$cid] ?? 0)];
            }
            usort($out, static fn ($a, $b) => $b['users'] <=> $a['users']);
            return $out;
        });
    }

    /** Most used categories and most active users (product growth questions, doc §114), cached 1 hour. */
    public function leaders(): array
    {
        return $this->cache->remember('admin:leaders', 3600, fn (): array => [
            'categories' => $this->db->select(
                'SELECT c.name_fa, COUNT(*) AS n FROM proposal_feed f JOIN categories c ON c.id = f.category_id GROUP BY c.id, c.name_fa ORDER BY n DESC LIMIT 10'
            ),
            'spenders' => $this->db->select(
                'SELECT u.first_name, u.last_name, u.handle, w.lifetime_spent AS n FROM wallets w JOIN users u ON u.id = w.user_id
                 WHERE w.lifetime_spent > 0 ORDER BY w.lifetime_spent DESC LIMIT 10'
            ),
            'connectors' => $this->db->select(
                'SELECT u.first_name, u.last_name, u.handle, uc.connections AS n FROM user_counters uc JOIN users u ON u.id = uc.user_id
                 WHERE uc.connections > 0 ORDER BY uc.connections DESC LIMIT 10'
            ),
            'actions' => $this->db->select(
                "SELECT reason, SUM(-amount) AS n FROM wallet_transactions WHERE type = 2 AND created_at > NOW(3) - INTERVAL 30 DAY GROUP BY reason ORDER BY n DESC"
            ),
        ]);
    }
}
