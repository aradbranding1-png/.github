<?php

declare(strict_types=1);

namespace App\Modules\Admin;

use App\Core\Db\Connection;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Support\Jalali;
use App\Modules\Analytics\ActiveDays;

/**
 * /admin/reports/active — WHO was active on a day (or a range of days) and WHAT each member did:
 * the names behind "کاربران فعال روزانه". Members come from user_activity_days; their actions from
 * activity_events, letter_messages, proposals and pages in the same (Tehran) days.
 */
final class ActiveUsersController extends AdminController
{
    public const PER_PAGE = 50;
    public const MAX_DAYS = 92;

    /** Columns of the table: key => [label, short label]. */
    public const ACTIONS = [
        'letters' => ['نامه و پاسخ', 'نامه'],
        'proposals_sent' => ['ارسال پیشنهاد', 'ارسال پیشنهاد'],
        'proposals_new' => ['پیشنهاد جدید ثبت کرد', 'پیشنهاد جدید'],
        'pages_new' => ['صفحه تجاری ساخت', 'صفحه جدید'],
        'page_views' => ['صفحه تجاری کامل دید', 'دیدن صفحه'],
        'proposal_views' => ['پیشنهاد دید', 'دیدن پیشنهاد'],
        'connections' => ['ارتباط تجاری ساخت', 'ارتباط'],
        'stars' => ['Stars خرج کرد', 'Stars'],
    ];

    public function index(Request $request): Response
    {
        $db = $this->c->get(Connection::class);
        $today = MetricsService::today();
        $isDay = static fn (mixed $v): bool => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1 && strtotime($v) !== false;

        // Period: ?day=Y-m-d, ?from=&to=, or ?range=7|30 (last N days including today). Default: today.
        $range = (int) $request->query('range', '0');
        $from = $to = $today;
        if (in_array($range, [7, 30], true)) {
            $from = MetricsService::today(-($range - 1));
        } elseif ($isDay($request->query('from')) && $isDay($request->query('to'))) {
            $from = (string) $request->query('from');
            $to = (string) $request->query('to');
            $range = 0;
        } elseif ($isDay($request->query('day'))) {
            $from = $to = (string) $request->query('day');
            $range = 0;
        } else {
            $range = 0;
        }
        if ($to > $today) {
            $to = $today;
        }
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        if ((strtotime($to) - strtotime($from)) / 86400 >= self::MAX_DAYS) {
            $from = date('Y-m-d', strtotime($to . ' -' . (self::MAX_DAYS - 1) . ' day'));
        }
        $single = $from === $to;
        [$fromUtc, $toUtc] = ActiveDays::utcRange($from, $to);

        $show = (string) $request->query('show', 'all');
        if (!in_array($show, ['all', 'doers', 'visitors', 'new'], true)) {
            $show = 'all';
        }
        $page = max(1, (int) $request->query('page', '1'));

        // Staff with a country-scoped "users.view" see only their countries; profile links need users.view.
        $canOpen = $this->allows($request, 'users.view');
        $countries = $canOpen ? $this->scopeCountries($request, 'users.view') : null;
        $scopeSql = '';
        if ($countries !== null) {
            $scopeSql = $countries === [] ? ' AND 0 = 1' : ' AND u.country_id IN (' . implode(',', array_map('intval', $countries)) . ')';
        }

        $members = [];
        foreach ($db->select(
            'SELECT a.user_id, COUNT(*) AS active_days, MIN(a.first_seen_at) AS first_seen, MAX(a.last_seen_at) AS last_seen,
                    u.first_name, u.last_name, u.email, u.handle, u.avatar_path, u.status, u.created_at, u.last_active_at,
                    c.code AS country_code, c.name_fa AS country_fa
               FROM user_activity_days a
               JOIN users u ON u.id = a.user_id
               JOIN countries c ON c.id = u.country_id
              WHERE a.day >= ? AND a.day <= ?' . $scopeSql . '
              GROUP BY a.user_id, u.first_name, u.last_name, u.email, u.handle, u.avatar_path, u.status, u.created_at, u.last_active_at, c.code, c.name_fa
              LIMIT 5000',
            [$from, $to]
        ) as $r) {
            $r['actions'] = array_fill_keys(array_keys(self::ACTIONS), 0);
            $r['is_new'] = (string) $r['created_at'] >= $fromUtc && (string) $r['created_at'] < $toUtc;
            $members[(int) $r['user_id']] = $r;
        }

        // What they did in the same days (one grouped query per source, partition-pruned by created_at).
        $add = static function (int $uid, string $key, int $n) use (&$members): void {
            if (isset($members[$uid])) {
                $members[$uid]['actions'][$key] += $n;
            }
        };
        $eventKey = ['letter_sent' => 'letters', 'letter_replied' => 'letters', 'proposal_sent' => 'proposals_sent',
            'page_viewed' => 'page_views', 'proposal_viewed' => 'proposal_views', 'connection_created' => 'connections'];
        foreach ($db->select(
            "SELECT user_id, event, COUNT(*) AS n FROM activity_events
              WHERE created_at >= ? AND created_at < ? AND user_id IS NOT NULL
                AND event IN ('letter_sent', 'letter_replied', 'proposal_sent', 'page_viewed', 'proposal_viewed', 'connection_created')
              GROUP BY user_id, event",
            [$fromUtc, $toUtc]
        ) as $r) {
            $add((int) $r['user_id'], $eventKey[(string) $r['event']], (int) $r['n']);
        }
        foreach ($db->select(
            'SELECT user_id, COALESCE(SUM(-amount), 0) AS n FROM wallet_transactions
              WHERE type = 2 AND created_at >= ? AND created_at < ? GROUP BY user_id',
            [$fromUtc, $toUtc]
        ) as $r) {
            $add((int) $r['user_id'], 'stars', (int) $r['n']);
        }
        foreach ($db->select('SELECT user_id, COUNT(*) AS n FROM proposals WHERE created_at >= ? AND created_at < ? GROUP BY user_id', [$fromUtc, $toUtc]) as $r) {
            $add((int) $r['user_id'], 'proposals_new', (int) $r['n']);
        }
        foreach ($db->select('SELECT user_id, COUNT(*) AS n FROM pages WHERE created_at >= ? AND created_at < ? GROUP BY user_id', [$fromUtc, $toUtc]) as $r) {
            $add((int) $r['user_id'], 'pages_new', (int) $r['n']);
        }

        // Summary over everyone active in the period (before the filter and the page cut).
        $summary = ['active' => count($members), 'new' => 0, 'doers' => 0, 'visitors' => 0, 'totals' => array_fill_keys(array_keys(self::ACTIONS), 0)];
        foreach ($members as &$m) {
            $m['score'] = array_sum($m['actions']) - $m['actions']['stars'] + ($m['actions']['stars'] > 0 ? 1 : 0);
            $summary['new'] += $m['is_new'] ? 1 : 0;
            $summary[$m['score'] > 0 ? 'doers' : 'visitors']++;
            foreach ($m['actions'] as $k => $n) {
                $summary['totals'][$k] += $n;
            }
        }
        unset($m);

        $list = array_values(array_filter($members, static fn (array $m): bool => match ($show) {
            'doers' => $m['score'] > 0,
            'visitors' => $m['score'] === 0,
            'new' => $m['is_new'],
            default => true,
        }));
        // Busiest first, then most recently seen.
        usort($list, static fn (array $a, array $b): int => [$b['score'], $b['active_days'], $b['last_seen']] <=> [$a['score'], $a['active_days'], $a['last_seen']]);
        $pages = max(1, (int) ceil(count($list) / self::PER_PAGE));
        $page = min($page, $pages);
        $rows = array_slice($list, ($page - 1) * self::PER_PAGE, self::PER_PAGE);

        // Short timeline per member on this page: what exactly they did (latest first, max 12 each).
        $timeline = [];
        $ids = array_map(static fn (array $r): int => (int) $r['user_id'], $rows);
        if ($ids !== []) {
            $in = implode(',', $ids);
            foreach ($db->select(
                "SELECT e.user_id, e.event, e.created_at, e.data, s.first_name, s.last_name
                   FROM activity_events e LEFT JOIN users s ON s.id = e.subject_user_id
                  WHERE e.created_at >= ? AND e.created_at < ? AND e.user_id IN ({$in}) AND e.event <> 'user_registered'
                  ORDER BY e.created_at DESC LIMIT 2000",
                [$fromUtc, $toUtc]
            ) as $e) {
                $uid = (int) $e['user_id'];
                if (count($timeline[$uid] ?? []) < 12) {
                    $timeline[$uid][] = $e;
                }
            }
        }

        // Day picker: the last 60 days with their member counts.
        $counts = array_column($db->select(
            'SELECT day, COUNT(*) AS n FROM user_activity_days WHERE day >= ? GROUP BY day',
            [MetricsService::today(-59)]
        ), 'n', 'day');
        $dayOptions = [];
        for ($i = 0; $i < 60; $i++) {
            $d = MetricsService::today(-$i);
            $dayOptions[$d] = Jalali::full($d) . ' · ' . fa_int((int) ($counts[$d] ?? 0)) . ' نفر';
        }

        return $this->view($request, 'admin/active_users', [
            'title' => 'کاربران فعال',
            'from' => $from,
            'to' => $to,
            'single' => $single,
            'range' => $range,
            'today' => $today,
            'show' => $show,
            'rows' => $rows,
            'timeline' => $timeline,
            'summary' => $summary,
            'filtered' => count($list),
            'page' => $page,
            'pages' => $pages,
            'dayOptions' => $dayOptions,
            'canOpen' => $canOpen,
            'liveDau' => $single ? (int) ($this->c->get(MetricsService::class)->day($from)['dau'] ?? 0) : 0,
        ]);
    }
}
