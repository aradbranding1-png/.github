<?php

declare(strict_types=1);

namespace App\Modules\Dashboard;

use App\Core\Db\Connection;
use App\Core\Http\Controller;
use App\Core\Http\Request;
use App\Core\Http\Response;

/**
 * "گزارش‌های من": a trader's own activity as charts. Every number comes from rows that already exist
 * (activity_events, proposal_views, wallet_transactions, counters) and is scoped to the signed-in user.
 * Day ranges use the (user_id|subject_user_id, created_at) indexes added in 2026_10_10.
 */
final class ReportsController extends Controller
{
    public const RANGES = [7, 30, 90];

    /** Events a user causes (user_id) or receives (subject_user_id), with their Persian names. */
    /** sent: what the member did; got: what someone did to them (:name = that trader). Persian sources for t(). */
    public const EVENTS = [
        'letter_sent' => ['sent' => 'نامه ارسال کردید', 'got' => ':name برای شما نامه فرستاد'],
        'letter_replied' => ['sent' => 'به نامه پاسخ دادید', 'got' => ':name به نامه شما پاسخ داد'],
        'proposal_sent' => ['sent' => 'پیشنهاد تجاری فرستادید', 'got' => ':name برای شما پیشنهاد تجاری فرستاد'],
        'proposal_viewed' => ['sent' => 'پیشنهاد تجاری دیدید', 'got' => ':name پیشنهاد تجاری شما را دید'],
        'page_viewed' => ['sent' => 'صفحه تجاری کامل را باز کردید', 'got' => ':name صفحه تجاری شما را کامل دید'],
        'connection_created' => ['sent' => 'ارتباط تجاری تازه ساختید', 'got' => ':name با شما ارتباط تجاری ساخت'],
        'stars_spent' => ['sent' => 'Stars خرج کردید', 'got' => ''],
    ];

    public function index(Request $request): Response
    {
        $user = $this->user($request);
        $uid = (int) $user['id'];
        $db = $this->c->get(Connection::class);
        $days = (int) $request->query('days', 30);
        if (!in_array($days, self::RANGES, true)) {
            $days = 30;
        }
        $step = $days > 30 ? 7 : 1;

        // Buckets: oldest → newest, one per day (or per week for 90 days).
        $today = new \DateTimeImmutable('today');
        $buckets = [];
        for ($i = intdiv($days, $step) - 1; $i >= 0; $i--) {
            $buckets[] = $today->modify('-' . ($i * $step) . ' days');
        }
        // Bucket j holds the days that are intdiv(daysAgo, step) == last - j; its label is its newest day.
        $index = static function (string $day) use ($today, $step, $buckets): ?int {
            $ago = (int) (new \DateTimeImmutable($day))->diff($today)->format('%r%a');
            if ($ago < 0) {
                return null;
            }
            $k = count($buckets) - 1 - intdiv($ago, $step);
            return $k >= 0 ? $k : null;
        };
        $zero = array_fill(0, count($buckets), 0);
        $series = ['sent' => $zero, 'got' => $zero, 'views' => $zero, 'in' => $zero, 'out' => $zero];
        $totals = [];

        // 1) Events caused by and aimed at the user, per day and type.
        $rows = $db->select(
            'SELECT DATE(created_at) AS d, event, 1 AS mine, COUNT(*) AS n FROM activity_events
              WHERE user_id = ? AND created_at >= CURRENT_DATE - INTERVAL ? DAY GROUP BY d, event
             UNION ALL
             SELECT DATE(created_at) AS d, event, 0 AS mine, COUNT(*) AS n FROM activity_events
              WHERE subject_user_id = ? AND created_at >= CURRENT_DATE - INTERVAL ? DAY GROUP BY d, event',
            [$uid, $days - 1, $uid, $days - 1]
        );
        foreach ($rows as $r) {
            $k = $index((string) $r['d']);
            $n = (int) $r['n'];
            $key = ((int) $r['mine'] === 1 ? 'sent:' : 'got:') . $r['event'];
            $totals[$key] = ($totals[$key] ?? 0) + $n;
            if ($k === null) {
                continue;
            }
            if (in_array($r['event'], ['letter_sent', 'letter_replied'], true)) {
                $series[(int) $r['mine'] === 1 ? 'sent' : 'got'][$k] += $n;
            }
            if ($r['event'] === 'proposal_viewed' && (int) $r['mine'] === 0) {
                $series['views'][$k] += $n;
            }
        }

        // 2) Stars in / out.
        foreach ($db->select(
            'SELECT DATE(created_at) AS d, SUM(GREATEST(amount, 0)) AS cin, SUM(GREATEST(-amount, 0)) AS cout
               FROM wallet_transactions WHERE user_id = ? AND created_at >= CURRENT_DATE - INTERVAL ? DAY GROUP BY d',
            [$uid, $days - 1]
        ) as $r) {
            $k = $index((string) $r['d']);
            if ($k !== null) {
                $series['in'][$k] += (int) $r['cin'];
                $series['out'][$k] += (int) $r['cout'];
            }
        }

        // 3) Headline numbers.
        $counters = $db->first('SELECT * FROM user_counters WHERE user_id = ?', [$uid]) ?? [];
        $wallet = $db->first('SELECT balance, lifetime_bought, lifetime_spent FROM wallets WHERE user_id = ?', [$uid]) ?? [];
        $content = $db->first(
            'SELECT (SELECT COUNT(*) FROM proposals WHERE user_id = ? AND deleted_at IS NULL AND status = 2) AS live_proposals,
                    (SELECT COUNT(*) FROM proposals WHERE user_id = ? AND deleted_at IS NULL) AS all_proposals,
                    (SELECT COUNT(*) FROM pages WHERE user_id = ? AND deleted_at IS NULL) AS pages',
            [$uid, $uid, $uid]
        ) ?? [];

        // 4) Latest events with the other trader's name.
        $recent = $db->select(
            '(SELECT e.event, e.created_at, 1 AS mine, e.subject_user_id AS other FROM activity_events e
               WHERE e.user_id = ? ORDER BY e.created_at DESC LIMIT 12)
             UNION ALL
             (SELECT e.event, e.created_at, 0 AS mine, e.user_id AS other FROM activity_events e
               WHERE e.subject_user_id = ? ORDER BY e.created_at DESC LIMIT 12)
             ORDER BY created_at DESC LIMIT 12',
            [$uid, $uid]
        );
        $others = array_values(array_unique(array_filter(array_map(static fn (array $r): int => (int) $r['other'], $recent))));
        $names = [];
        if ($others !== []) {
            foreach ($db->select(
                'SELECT u.id, u.first_name, u.last_name, p.company_name FROM users u LEFT JOIN user_profiles p ON p.user_id = u.id
                  WHERE u.id IN (' . implode(',', array_fill(0, count($others), '?')) . ')',
                $others
            ) as $u) {
                $names[(int) $u['id']] = trim((string) ($u['company_name'] ?: $u['first_name'] . ' ' . $u['last_name']));
            }
        }

        return $this->view($request, 'dashboard/reports', [
            'title' => t('گزارش‌های من'),
            'days' => $days,
            'step' => $step,
            'buckets' => array_map(static fn (\DateTimeImmutable $d): string => $d->format('Y-m-d'), $buckets),
            'series' => $series,
            'totals' => $totals,
            'counters' => $counters,
            'wallet' => $wallet,
            'content' => $content,
            'recent' => $recent,
            'names' => $names,
        ]);
    }
}
