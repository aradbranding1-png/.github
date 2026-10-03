<?php
/**
 * KPIهای سیستمیِ تیم — یک منبع و یک منطق برای همه‌ی گزارش‌ها
 * (گزارشِ A4ِ سرپرست: supervisor_daily_report.php و گزارشِ تیم/سرپرست: admin/admin_team_leader_report.php).
 *
 *   get_team_daily_kpis($pdo, $teamId, $from, $to = null)
 *
 * افرادِ تیم: سرپرست + همه‌ی اعضای تیم (حضوری، غیرحضوری، دورکار، …).
 *
 * لید = ارتباطِ جدید: کسی که در آن روز «برای اولین بار» واردِ فرآیندِ ارتباطی شده —
 *   یعنی اولین ارتباطِ ثبت‌شده با او در کلِ سامانه (هر پیگیری/تماس با هر نتیجه‌ای: پاسخ داده، نداده، منصرف، …،
 *   یا ثبتِ دستیِ مشتری توسطِ کارشناس) در همان روز و توسطِ یکی از افرادِ تیم بوده است.
 *   فرقی نمی‌کند همان روز ثبت شده باشد یا از قبل در سامانه بوده و آن روز اولین ارتباطش برقرار شده.
 *   هر شخص فقط یک‌بار (روزِ اولین ارتباطش) لید حساب می‌شود.
 * مذاکره = موفق‌های جدید: از بینِ همان لیدهای همان روز، کسانی که همان روز گفت‌وگوی واقعی داشته‌اند
 *   (تماسِ برقرار بیش از STAFF_REPORT_CONNECTED_MIN ثانیه، پیگیریِ دستیِ غیرِ «عدم پاسخ»، یا جلسه‌ی برگزارشده).
 *   پس همیشه مذاکره ⊆ لید.
 * پول (همان تعریفِ «گزارش فروش»: خالص، هر پرداخت روزِ تأییدش، به نامِ صاحبِ سهمِ فروش):
 *   پ ج = اولین پولِ آن مشتری در کلِ سامانه؛ بقیه پ ق؛ پ کل = جمع.
 *   جدید / قدیم = تعدادِ مشتریانِ یکتای هر دسته.
 */

require_once __DIR__ . '/team_sales.php';

/** افرادِ تیم برای KPI: سرپرستِ تیم + همه‌ی اعضا */
function team_kpi_member_ids(PDO $pdo, int $teamId): array
{
    static $cache = [];
    if (isset($cache[$teamId])) return $cache[$teamId];
    $ids = function_exists('tsr_team_user_ids') ? tsr_team_user_ids($pdo, $teamId) : [];
    try {
        $st = $pdo->prepare('SELECT leader_user_id FROM teams WHERE id = ?');
        $st->execute([$teamId]);
        if ($lid = (int) $st->fetchColumn()) $ids[] = $lid;
    } catch (Throwable $e) {}
    return $cache[$teamId] = array_values(array_unique(array_map('intval', $ids)));
}

function team_kpi_blank(): array
{
    return ['leads' => 0, 'nego' => 0, 'new_cnt' => 0, 'new_amt' => 0, 'old_cnt' => 0, 'old_amt' => 0, 'total_amt' => 0];
}

/**
 * KPIهای تیم برای هر روزِ بازه + جمعِ بازه + تفکیکِ هر نفر.
 * @return array{ids: int[], days: array<string,array>, total: array, by_user: array<int,array>}
 */
function get_team_daily_kpis(PDO $pdo, int $teamId, string $from, ?string $to = null): array
{
    return team_kpis_for_ids($pdo, team_kpi_member_ids($pdo, $teamId), $from, $to ?? $from);
}

/** همان محاسبه برای یک فهرستِ افراد (هسته‌ی مشترک) */
function team_kpis_for_ids(PDO $pdo, array $ids, string $from, string $to): array
{
    static $cache = [];
    $ids = array_values(array_unique(array_map('intval', $ids)));
    sort($ids);
    $ck = implode(',', $ids) . '|' . $from . '|' . $to;
    if (isset($cache[$ck])) return $cache[$ck];

    $days = [];
    for ($t = strtotime($from); $t <= strtotime($to); $t += 86400) $days[] = date('Y-m-d', $t);
    $out = ['ids' => $ids, 'days' => array_fill_keys($days, team_kpi_blank()), 'total' => team_kpi_blank(), 'by_user' => []];
    if (!$ids || !$days) return $cache[$ck] = $out;
    $team = array_flip($ids);
    $in = implode(',', $ids);
    $minTalk = defined('STAFF_REPORT_CONNECTED_MIN') ? (int) STAFF_REPORT_CONNECTED_MIN : 10;
    $isCustomer = "COALESCE(c.contact_type, 'customer') = 'customer'";
    $q = static function (string $sql, array $params) use ($pdo): array {
        try {
            $st = $pdo->prepare($sql);
            $st->execute($params);
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('team_kpis: ' . $e->getMessage());
            return [];
        }
    };
    $user = static function (array &$out, int $uid): void {
        if (!isset($out['by_user'][$uid])) $out['by_user'][$uid] = team_kpi_blank();
    };

    // ─── ۱) نامزدها: هر ارتباطِ تیم در بازه (پیگیری/تماس با هر نتیجه) + ثبتِ دستیِ مشتری توسطِ تیم ───
    $cand = [];     // customer_id => true
    $talked = [];   // day => [customer_id => true]  (گفت‌وگوی واقعیِ تیم در آن روز)
    foreach ($q("SELECT f.followup_date d, f.customer_id cid,
            MAX(CASE WHEN f.call_duration_seconds > $minTalk THEN 1
                     WHEN f.source IN ('manual', 'manual_excel_import') AND COALESCE(f.status_after, '') <> 'عدم پاسخ' THEN 1 ELSE 0 END) ok
        FROM followups f JOIN customers c ON c.id = f.customer_id
        WHERE f.created_by IN ($in) AND f.followup_date BETWEEN ? AND ? AND $isCustomer
        GROUP BY f.followup_date, f.customer_id", [$from, $to]) as $r) {
        $cand[(int) $r['cid']] = true;
        if ((int) $r['ok']) $talked[$r['d']][(int) $r['cid']] = true;
    }
    foreach ($q("SELECT DISTINCT l.customer_id cid FROM customer_activity_logs l JOIN customers c ON c.id = l.customer_id
        WHERE l.activity_type = 'create' AND l.user_id IN ($in) AND l.created_at BETWEEN ? AND ? AND $isCustomer", [$from . ' 00:00:00', $to . ' 23:59:59']) as $r) {
        $cand[(int) $r['cid']] = true;
    }
    // جلسه‌ی برگزارشده = گفت‌وگوی واقعی
    foreach ($q("SELECT b.meeting_date d, b.customer_id cid FROM meeting_bookings b JOIN customers c ON c.id = b.customer_id
        WHERE b.status = 'held' AND b.staff_id IN ($in) AND b.meeting_date BETWEEN ? AND ? AND $isCustomer", [$from, $to]) as $r) {
        $talked[$r['d']][(int) $r['cid']] = true;
    }

    // ─── ۲) اولین ارتباطِ هر نامزد در کلِ سامانه (هر کسی ثبت کرده باشد) ───
    if ($cand) {
        foreach (array_chunk(array_keys($cand), 2000) as $chunk) {
            $cin = implode(',', $chunk);
            $first = []; // cid => [day, uid, kind]
            foreach ($q("SELECT f.customer_id cid, f.followup_date d, f.created_by uid FROM followups f
                    JOIN (SELECT customer_id, MIN(followup_date) md FROM followups WHERE customer_id IN ($cin) GROUP BY customer_id) m
                      ON m.customer_id = f.customer_id AND m.md = f.followup_date
                    WHERE f.customer_id IN ($cin) ORDER BY f.customer_id, f.id", []) as $r) {
                $cid = (int) $r['cid'];
                // چند ارتباط در همان اولین روز: اگر یکی از تیم بوده، به نامِ تیم
                if (!isset($first[$cid]) || (!isset($team[$first[$cid][1]]) && isset($team[(int) $r['uid']]))) $first[$cid] = [$r['d'], (int) $r['uid']];
            }
            foreach ($q("SELECT l.customer_id cid, DATE(MIN(l.created_at)) d, SUBSTRING_INDEX(GROUP_CONCAT(l.user_id ORDER BY l.created_at, l.id), ',', 1) uid
                    FROM customer_activity_logs l WHERE l.activity_type = 'create' AND l.customer_id IN ($cin) AND l.user_id IS NOT NULL GROUP BY l.customer_id", []) as $r) {
                $cid = (int) $r['cid'];
                if (!isset($first[$cid]) || $r['d'] < $first[$cid][0]) $first[$cid] = [$r['d'], (int) $r['uid']];
            }
            foreach ($first as $cid => [$d, $uid]) {
                if (!isset($out['days'][$d]) || !isset($team[$uid])) continue; // اولین ارتباط بیرون از بازه یا توسطِ کسی بیرون از تیم
                $out['days'][$d]['leads']++;
                $user($out, $uid);
                $out['by_user'][$uid]['leads']++;
                if (isset($talked[$d][$cid])) {
                    $out['days'][$d]['nego']++;
                    $out['by_user'][$uid]['nego']++;
                }
            }
        }
    }

    // ─── ۳) پول: پ ج / پ ق / پ کل و تعدادِ مشتریانِ جدید / قدیم ───
    try {
        if (!function_exists('sales_user_events_sql')) require_once __DIR__ . '/sales_credit.php';
        if (!function_exists('sd_first_money')) require_once __DIR__ . '/supervisor_daily.php';
        $st = $pdo->prepare("SELECT x.order_id, x.kind, x.payment_id, x.uid, DATE(x.at) d, SUM(x.net) net, o.customer_id
            FROM (" . sales_user_events_sql($pdo) . ") x JOIN sales_orders o ON o.id = x.order_id
            WHERE x.uid IN ($in) GROUP BY x.order_id, x.kind, x.payment_id, x.uid, DATE(x.at), o.customer_id");
        $st->execute(sales_user_events_params($pdo, $from, $to));
        $cust = [];
        $custAll = [];
        $custUser = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $e) {
            if (!isset($out['days'][$e['d']])) continue;
            $net = max(0, (int) $e['net']);
            if ($net <= 0) continue;
            $first = sd_first_money($pdo, (int) $e['customer_id']);
            $isNew = $first !== null && (($e['kind'] === 'order' && $first[0] === 'order' && $first[1] === (int) $e['order_id'])
                || ($e['kind'] === 'payment' && $first[0] === 'payment' && $first[1] === (int) $e['payment_id']));
            $k = $isNew ? 'new' : 'old';
            $uid = (int) $e['uid'];
            $cid = (int) $e['customer_id'];
            $out['days'][$e['d']][$k . '_amt'] += $net;
            $out['days'][$e['d']]['total_amt'] += $net;
            $user($out, $uid);
            $out['by_user'][$uid][$k . '_amt'] += $net;
            $out['by_user'][$uid]['total_amt'] += $net;
            $cust[$e['d']][$k][$cid] = true;
            $custAll[$k][$cid] = true;
            $custUser[$uid][$k][$cid] = true;
        }
        foreach ($cust as $d => $kk) foreach ($kk as $k => $set) $out['days'][$d][$k . '_cnt'] = count($set);
        foreach ($custUser as $uid => $kk) foreach ($kk as $k => $set) $out['by_user'][$uid][$k . '_cnt'] = count($set);
        $out['total']['new_cnt'] = count($custAll['new'] ?? []);
        $out['total']['old_cnt'] = count($custAll['old'] ?? []);
    } catch (Throwable $e) {
        error_log('team_kpis money: ' . $e->getMessage());
    }
    foreach (['leads', 'nego', 'new_amt', 'old_amt', 'total_amt'] as $k) $out['total'][$k] = array_sum(array_column($out['days'], $k));
    return $cache[$ck] = $out;
}
