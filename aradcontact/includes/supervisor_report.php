<?php
/**
 * گزارشِ سرپرست: فعالیتِ خودِ سرپرست (تماس، جلسه، کارها)، تماسش با نیروهای تیم، و راندمانِ هر نیرو.
 *
 * راندمانِ هر نیرو = دقیقه‌ی مکالمه‌ی مفیدِ او ÷ میانگینِ دقیقه‌ی مکالمه‌ی همه‌ی نیروهای «هم‌نقش» (A/B/C) در کلِ سازمان
 *                   در همان بازه × ۱۰۰   (۱۰۰٪ = برابرِ میانگینِ سازمان)
 * راندمانِ تیم     = میانگینِ راندمانِ نیروهای تیم
 * پوششِ سرپرست     = تعدادِ نیروهایی که سرپرست در بازه با آن‌ها مکالمه‌ی برقرار داشته ÷ کلِ نیروهای تیم
 * «مکالمه‌ی مفید» = تماسِ برقرار (بیش از آستانه‌ی گزارشِ کارکنان) — همان تعریفِ گزارشِ کارکنان.
 */
require_once __DIR__ . '/staff_report.php';

function sup_work_location_label(?string $v): string
{
    return ['onsite' => 'حضوری', 'remote' => 'دورکار'][(string) $v] ?? 'نامشخص';
}

function sup_job_group_label(?string $v): string
{
    $v = trim((string) $v);
    return in_array($v, ['توسعه', 'عملیات', 'ستادی'], true) ? $v : 'نامشخص';
}

/** همه‌ی سرپرست‌ها (با تیم) */
function sup_leaders(PDO $pdo): array
{
    return $pdo->query("SELECT t.id AS team_id, t.name AS team_name, u.id, u.full_name, u.mobile,
            (SELECT COUNT(*) FROM users m WHERE m.team_id = t.id AND m.is_active = 1 AND m.id <> u.id) AS members
        FROM teams t JOIN users u ON u.id = t.leader_user_id WHERE u.is_active = 1 ORDER BY t.id")->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function sup_members(PDO $pdo, int $teamId, int $leaderId): array
{
    // ستون‌های اختیاری اول بررسی می‌شوند (prepareِ واقعیِ MySQL با ستونِ ناموجود همان‌جا خطا می‌دهد)
    static $have = null;
    if ($have === null) {
        $have = [];
        try {
            foreach ($pdo->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_ASSOC) ?: [] as $c) $have[$c['Field']] = true;
        } catch (Throwable $e) {}
    }
    $cols = 'id, full_name, mobile, role, is_active';
    foreach (['mobile_2', 'work_location', 'job_group'] as $c) {
        $cols .= !empty($have[$c]) ? ', `' . $c . '`' : ', NULL AS `' . $c . '`';
    }
    $st = $pdo->prepare("SELECT $cols FROM users WHERE team_id = ? AND is_active = 1 AND id <> ? ORDER BY role, full_name");
    $st->execute([$teamId, $leaderId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** دقیقه‌ی مکالمه‌ی مفیدِ همه‌ی کارکنان در بازه (برای میانگینِ هم‌نقش‌ها) */
function sup_talk_by_user(PDO $pdo, string $from, string $to): array
{
    $min = STAFF_REPORT_CONNECTED_MIN;
    $st = $pdo->prepare("SELECT f.created_by, SUM(CASE WHEN f.call_duration_seconds > {$min} THEN f.call_duration_seconds ELSE 0 END) s
        FROM followups f WHERE f.source IN (" . staff_report_sources_sql() . ") AND f.followup_date BETWEEN ? AND ? GROUP BY f.created_by");
    $st->execute([$from, $to]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) $out[(int) $r['created_by']] = (int) $r['s'];
    return $out;
}

/** میانگینِ ثانیه‌ی مکالمه‌ی مفید برای هر نقش (روی همه‌ی کارکنانِ فعالِ آن نقش؛ کسی که تماس نداشته صفر حساب می‌شود) */
/**
 * «معیارِ» هر نقش (A/B/C/سرپرست) در بازه = میانگینِ دقیقه‌ی مکالمه‌ی مفیدِ کسانی از همان نقش که در این بازه مکالمه داشته‌اند
 * (افرادِ بی‌مکالمه در میانگین حساب نمی‌شوند تا معیار بی‌جهت پایین نیاید).
 */
function sup_role_averages(PDO $pdo, array $talkByUser): array
{
    $avg = [];
    foreach (['A', 'B', 'C', 'leader'] as $role) {
        $ids = $pdo->prepare('SELECT id FROM users WHERE role = ? AND is_active = 1');
        $ids->execute([$role]);
        $sum = 0;
        $n = 0;
        foreach ($ids->fetchAll(PDO::FETCH_COLUMN) ?: [] as $id) {
            $t = (int) ($talkByUser[(int) $id] ?? 0);
            if ($t > 0) { $sum += $t; $n++; }
        }
        $avg[$role] = $n ? $sum / $n : 0;
    }
    return $avg;
}

/** راندمانِ یک نفر از ۱۰۰: مکالمه ÷ معیارِ نقشش × ۱۰۰ — حداکثر ۱۰۰٪ (رسیدن به معیار یا بیشتر = ۱۰۰٪) */
function sup_efficiency(int $talk, float $roleAvg): ?float
{
    if ($roleAvg <= 0) return $talk > 0 ? 100.0 : null;
    return min(100.0, round($talk / $roleAvg * 100, 1));
}

/** آمارِ یک نفر در بازه: تماس‌ها + جلسه + فروش + سهم + روزهای فعال */
function sup_person_stats(PDO $pdo, int $uid, string $from, string $to): array
{
    $sum = staff_report_summary($pdo, $uid, $from, $to, staff_report_parse_filters([]))['data'];
    $min = STAFF_REPORT_CONNECTED_MIN;
    $st = $pdo->prepare("SELECT COUNT(DISTINCT followup_date) FROM followups WHERE created_by = ? AND source IN (" . staff_report_sources_sql() . ")
        AND followup_date BETWEEN ? AND ? AND call_duration_seconds > {$min}");
    $st->execute([$uid, $from, $to]);
    $activeDays = (int) $st->fetchColumn();
    $meet = 0;
    try {
        $q = $pdo->prepare("SELECT COUNT(*) FROM meeting_bookings WHERE staff_id = ? AND status = 'held' AND meeting_date BETWEEN ? AND ?");
        $q->execute([$uid, $from, $to]);
        $meet += (int) $q->fetchColumn();
    } catch (Throwable $e) {}
    try {
        $q = $pdo->prepare("SELECT COUNT(*) FROM meeting_verifications WHERE conductor_id = ? AND status = 'confirmed' AND DATE(COALESCE(resolved_at, created_at)) BETWEEN ? AND ?");
        $q->execute([$uid, $from, $to]);
        $meet += (int) $q->fetchColumn();
    } catch (Throwable $e) {}
    $sales = ['n' => 0, 'net' => 0];
    try {
        $q = $pdo->prepare("SELECT COUNT(*) n, COALESCE(SUM(total_amount - tax_amount),0) net FROM sales_orders WHERE seller_user_id = ? AND status = 'approved' AND DATE(decided_at) BETWEEN ? AND ?");
        $q->execute([$uid, $from, $to]);
        $sales = $q->fetch(PDO::FETCH_ASSOC) ?: $sales;
    } catch (Throwable $e) {}
    $share = 0;
    try {
        $q = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM ps_lines WHERE user_id = ? AND voided = 0 AND pay_date BETWEEN ? AND ?');
        $q->execute([$uid, $from, $to]);
        $share = (int) $q->fetchColumn();
    } catch (Throwable $e) {}
    return $sum + ['active_days' => $activeDays, 'meetings' => $meet, 'sales_count' => (int) $sales['n'], 'sales_net' => (int) $sales['net'], 'share' => $share];
}

/**
 * سرپرست با کدام نیروهایش صحبت کرده؟ (تماس‌های کالیزر/نواتل/دستیِ خودِ سرپرست که شماره‌ی طرف = موبایلِ نیرو)
 * @return array<int, array{calls:int, connected:int, seconds:int, last:?string}>  کلید = شناسه‌ی نیرو
 */
function sup_leader_member_contacts(PDO $pdo, int $leaderId, array $members, string $from, string $to): array
{
    $byPhone = [];
    foreach ($members as $m) {
        foreach (['mobile', 'mobile_2'] as $c) {
            $n = !empty($m[$c]) ? normalize_phone_for_match((string) $m[$c]) : null;
            if ($n) $byPhone[$n] = (int) $m['id'];
        }
    }
    if (!$byPhone) return [];
    $min = STAFF_REPORT_CONNECTED_MIN;
    $st = $pdo->prepare("SELECT f.customer_id, f.followup_date, f.call_duration_seconds, c.mobile, c.mobile_2 FROM followups f JOIN customers c ON c.id = f.customer_id
        WHERE f.created_by = ? AND f.followup_date BETWEEN ? AND ?");
    $st->execute([$leaderId, $from, $to]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
        $mid = null;
        foreach (['mobile', 'mobile_2'] as $c) {
            $n = !empty($r[$c]) ? normalize_phone_for_match((string) $r[$c]) : null;
            if ($n && isset($byPhone[$n])) { $mid = $byPhone[$n]; break; }
        }
        if (!$mid) continue;
        $o = $out[$mid] ?? ['calls' => 0, 'connected' => 0, 'seconds' => 0, 'last' => null, 'customer_ids' => []];
        $o['customer_ids'][(int) $r['customer_id']] = true;
        $o['calls']++;
        if ((int) $r['call_duration_seconds'] > $min) { $o['connected']++; $o['seconds'] += (int) $r['call_duration_seconds']; }
        if ($o['last'] === null || $r['followup_date'] > $o['last']) $o['last'] = $r['followup_date'];
        $out[$mid] = $o;
    }
    return $out;
}

/** «چه کارهایی کرده»: شمارشِ فعالیت‌های ثبت‌شده در روندِ زمانیِ مشتریان + پیش‌فاکتور + سفارش */
function sup_activities(PDO $pdo, int $uid, string $from, string $to): array
{
    $labels = ['create' => 'ثبتِ مشتریِ جدید', 'followup' => 'ثبتِ پیگیری', 'edit' => 'ویرایشِ مشتری', 'update' => 'ویرایشِ مشتری', 'referral' => 'ارجاعِ مشتری',
        'refer' => 'ارجاعِ مشتری', 'status' => 'تغییرِ وضعیت', 'status_change' => 'تغییرِ وضعیت', 'merge' => 'ادغامِ پرونده', 'box' => 'Box (ارجاع/دریافت)',
        'meeting' => 'جلسه', 'note' => 'یادداشت', 'delete' => 'حذف', 'call' => 'تماس'];
    $out = [];
    try {
        $st = $pdo->prepare('SELECT activity_type, COUNT(*) n FROM customer_activity_logs WHERE user_id = ? AND DATE(created_at) BETWEEN ? AND ? GROUP BY activity_type ORDER BY n DESC');
        $st->execute([$uid, $from, $to]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $l = $labels[(string) $r['activity_type']] ?? (string) $r['activity_type'];
            $out[$l] = ($out[$l] ?? 0) + (int) $r['n'];
        }
    } catch (Throwable $e) {}
    foreach ([
        ['پیش‌فاکتورِ ساخته‌شده', 'SELECT COUNT(*) FROM quotes WHERE created_by = ? AND DATE(created_at) BETWEEN ? AND ?'],
        ['سفارشِ ثبت‌شده', 'SELECT COUNT(*) FROM sales_orders WHERE seller_user_id = ? AND DATE(created_at) BETWEEN ? AND ?'],
        ['پیگیریِ دستی', "SELECT COUNT(*) FROM followups WHERE created_by = ? AND COALESCE(source,'manual') = 'manual' AND followup_date BETWEEN ? AND ?"],
    ] as [$l, $sql]) {
        try { $q = $pdo->prepare($sql); $q->execute([$uid, $from, $to]); $n = (int) $q->fetchColumn(); if ($n) $out[$l] = $n; } catch (Throwable $e) {}
    }
    arsort($out);
    return $out;
}

/**
 * مخاطبانی که سرپرست (یا هر کاربر) در بازه با آن‌ها «رکوردِ تماس» دارد (کالیزر/نواتل/ورودیِ تماس) — گروه‌بندی به ازای هر مخاطب.
 * @return array{rows: array, total: int}
 */
function sup_user_contacts(PDO $pdo, int $uid, string $from, string $to, int $page = 1, int $per = 25, array $excludeCustomerIds = []): array
{
    $min = STAFF_REPORT_CONNECTED_MIN;
    $where = "f.created_by = ? AND f.source IN (" . staff_report_sources_sql() . ") AND f.followup_date BETWEEN ? AND ?";
    $ex = array_values(array_filter(array_map('intval', $excludeCustomerIds)));
    if ($ex) $where .= ' AND f.customer_id NOT IN (' . implode(',', $ex) . ')'; // نیروهای تیم در جدولِ نیروها نمایش داده می‌شوند
    $cnt = $pdo->prepare("SELECT COUNT(DISTINCT f.customer_id) FROM followups f WHERE $where");
    $cnt->execute([$uid, $from, $to]);
    $total = (int) $cnt->fetchColumn();
    $off = max(0, ($page - 1) * $per);
    $st = $pdo->prepare("SELECT c.id, c.full_name, c.mobile, c.mobile_2, c.contact_type,
            COUNT(*) AS calls,
            SUM(CASE WHEN f.call_duration_seconds > {$min} THEN 1 ELSE 0 END) AS connected,
            SUM(CASE WHEN f.call_duration_seconds > {$min} THEN f.call_duration_seconds ELSE 0 END) AS seconds,
            MAX(f.call_duration_seconds) AS longest,
            MIN(f.followup_date) AS first_call, MAX(f.followup_date) AS last_call
        FROM followups f JOIN customers c ON c.id = f.customer_id
        WHERE $where GROUP BY c.id, c.full_name, c.mobile, c.mobile_2, c.contact_type
        ORDER BY seconds DESC, calls DESC LIMIT $per OFFSET $off");
    $st->execute([$uid, $from, $to]);
    return ['rows' => $st->fetchAll(PDO::FETCH_ASSOC) ?: [], 'total' => $total];
}
