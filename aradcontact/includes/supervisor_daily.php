<?php
/**
 * ═══════════════════════════════════════════════════════════════════════
 *  گزارشِ روزانه‌ی A4ِ سرپرست — داده‌ها
 * ═══════════════════════════════════════════════════════════════════════
 *  A1 نیروی انسانی (روزِ انتخاب‌شده)        A2 روندِ توسعه/عملیات/ستادی از اولِ ماه تا همان روز
 *  B1 خروجیِ فروش (روزِ انتخاب‌شده)          B2 روندِ آورده (پ جدید/قدیم/کل) + روندِ مذاکره/جدید/قدیم
 *  C1 خدمات (روزِ انتخاب‌شده)               C2 روندِ هر خدمت
 *
 *  تعریف‌ها (یک جا، برای همه‌ی بخش‌ها):
 *   - تیم              : نیروهای فعالِ تیم + خودِ سرپرست (همان تعریفِ «فروشِ تیم» در گزارشِ سرپرست)
 *   - نیروی انسانی      : نیروهای فعالِ تیم (بدونِ سرپرست) به تفکیکِ «گروهِ شغلی»؛ هر روز یک عکس (snapshot) ذخیره می‌شود.
 *                         برای روزی که عکس ندارد، از نیروهای فعلی‌ای که تا آن روز در سامانه ثبت شده بودند تخمین زده می‌شود.
 *   - لید              : مشتری‌های یکتایی (نه همکار/خانواده) که تیم در آن روز برایشان پیگیری/تماس ثبت کرده
 *   - مذاکره            : مشتری‌های یکتایی که در آن روز جلسه‌شان «برگزار شد» یا پیگیری‌شان به «جلسه برگزار شد / در انتظار تصمیم / در انتظار پرداخت» رسید
 *   - جدید / قدیم        : سفارش‌های تأییدشده (تاریخِ تأییدِ مالی) با فروشنده‌ی تیم؛ «جدید» = اولین خریدِ تأییدشده‌ی آن شخص (۳۶۰)، بقیه «قدیم»
 *   - پ (آورده)         : مبلغِ خالصِ همان سفارش‌ها (بدونِ مالیات) — همان عددِ «فروشِ تیم»
 *   - قانونِ تعداد       : توسعه‌ی لازم = ⌈عملیات ÷ ۸⌉ + ⌈ستادی ÷ ۲⌉ ؛ ✓ اگر توسعه ≥ توسعه‌ی لازم
 *   - قانونِ پ          : حقوقِ روزانه = حقوقِ ثابتِ ماهانه ÷ ۲۴؛ هدفِ روزانه = مجموعِ حقوقِ روزانه‌ی تیم × ۱۰؛
 *                         در بازه‌ی گزارش (اولِ ماه تا روزِ انتخاب‌شده) هدف = هدفِ روزانه × تعدادِ روزهای کاری (بدونِ جمعه)؛
 *                         ✓ اگر آورده‌ی همان بازه ≥ هدف. حقوقِ ثابت: ستونِ users.monthly_salary، وگرنه آخرین «حقوق»ِ ثبت‌شده در پرداخت‌ها.
 *   - خدمات            : هر خدمت یا «خودکار» از داده‌ی سامانه شمرده می‌شود، یا سرپرست/مدیر عددِ روز را ثبت می‌کند؛ عددِ ثبت‌شده بر خودکار مقدم است.
 */

function sd_ready(PDO $pdo): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    $flag = __DIR__ . '/../storage/.supervisor_daily_v1';
    try {
        if (is_file($flag)) return $ok = true;
        $cols = $pdo->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'")->fetchAll(PDO::FETCH_COLUMN) ?: [];
        if (!in_array('monthly_salary', $cols, true)) $pdo->exec('ALTER TABLE users ADD COLUMN monthly_salary BIGINT UNSIGNED NULL DEFAULT NULL');
        $pdo->exec("CREATE TABLE IF NOT EXISTS sup_team_daily (
            team_id INT UNSIGNED NOT NULL, day DATE NOT NULL,
            dev INT NOT NULL DEFAULT 0, ops INT NOT NULL DEFAULT 0, staff INT NOT NULL DEFAULT 0, unknown INT NOT NULL DEFAULT 0, total INT NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (team_id, day)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS sup_service_types (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(120) NOT NULL, source VARCHAR(30) NOT NULL DEFAULT 'manual',
            sort_order INT NOT NULL DEFAULT 0, is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS sup_service_daily (
            team_id INT UNSIGNED NOT NULL, day DATE NOT NULL, service_id INT UNSIGNED NOT NULL,
            cnt INT UNSIGNED NOT NULL DEFAULT 0, entered_by INT UNSIGNED NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (team_id, day, service_id), KEY idx_ssd_day (day)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        if (!(int) $pdo->query('SELECT COUNT(*) FROM sup_service_types')->fetchColumn()) {
            $ins = $pdo->prepare('INSERT INTO sup_service_types (title, source, sort_order) VALUES (?,?,?)');
            foreach ([['ارتباط با تاجر', 'manual'], ['میتینگ B', 'meeting_b'], ['پاسخ تیکت', 'manual'], ['مکاتبه رسمی', 'letters'],
                      ['جلسه حضوری استخدام', 'inperson_hire'], ['میتینگ عمومی تاجران', 'manual']] as $i => [$t, $src]) {
                $ins->execute([$t, $src, ($i + 1) * 10]);
            }
        }
        @file_put_contents($flag, date('c'));
        return $ok = true;
    } catch (Throwable $e) {
        error_log('sd_ready: ' . $e->getMessage());
        return $ok = false;
    }
}

/** منبعِ شمارشِ خودکارِ هر خدمت */
function sd_service_sources(): array
{
    return [
        'manual' => 'دستی (سرپرست ثبت می‌کند)',
        'meeting_b' => 'خودکار: جلسه‌های برگزارشده‌ی تیم (تقویم)',
        'inperson_hire' => 'خودکار: مصاحبه‌ی حضوریِ استخدامِ انجام‌شده (برگزارکننده در تیم)',
        'letters' => 'خودکار: نامه‌های ارسالیِ اتوماسیون',
        'tickets' => 'خودکار: تیکت‌های ثبت‌شده برای آراد برندینگ',
        'customer_calls' => 'خودکار: مشتریانی که تماسِ برقرار داشته‌اند',
    ];
}

function sd_service_types(PDO $pdo, bool $activeOnly = true): array
{
    if (!sd_ready($pdo)) return [];
    return $pdo->query('SELECT * FROM sup_service_types' . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY sort_order, id')->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** روزهای اولِ ماهِ شمسی تا همان روز (میلادی) */
function sd_month_days(string $day): array
{
    [$jy, $jm] = array_map('intval', explode('/', normalize_digits(to_jalali($day))));
    $start = to_gregorian(sprintf('%04d/%02d/01', $jy, $jm)) ?: $day;
    $out = [];
    for ($t = strtotime($start); $t <= strtotime($day); $t += 86400) $out[] = date('Y-m-d', $t);
    return $out ?: [$day];
}

/** برچسبِ کوتاهِ روز برای محورِ نمودار: ۱۴۰۵/۰۷/۰۳ ← «۷۰۳» */
function sd_day_label(string $day): string
{
    $p = explode('/', normalize_digits(to_jalali($day)));
    return to_persian_digits((int) ($p[1] ?? 0) . str_pad((string) (int) ($p[2] ?? 0), 2, '0', STR_PAD_LEFT));
}

/** شناسه‌ی همه‌ی افرادِ تیم (نیروها + سرپرست) */
function sd_team_ids(PDO $pdo, int $teamId, int $leaderId): array
{
    $ids = function_exists('tsr_team_user_ids') ? tsr_team_user_ids($pdo, $teamId) : [];
    if ($leaderId > 0) $ids[] = $leaderId;
    return array_values(array_unique(array_map('intval', $ids)));
}

/** نیروی انسانیِ فعلیِ تیم به تفکیکِ گروهِ شغلی */
function sd_headcount_now(PDO $pdo, int $teamId, int $leaderId, ?string $upTo = null): array
{
    $c = ['dev' => 0, 'ops' => 0, 'staff' => 0, 'unknown' => 0, 'total' => 0];
    $created = [];
    if ($upTo !== null) {
        try {
            $st = $pdo->prepare('SELECT id, created_at FROM users WHERE team_id = ?');
            $st->execute([$teamId]);
            $created = $st->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
        } catch (Throwable $e) {}
    }
    foreach (sup_members($pdo, $teamId, $leaderId) as $m) {
        if ($upTo !== null && isset($created[$m['id']]) && substr((string) $created[$m['id']], 0, 10) > $upTo) continue;
        $g = sup_job_group_label($m['job_group'] ?? null);
        $k = ['توسعه' => 'dev', 'عملیات' => 'ops', 'ستادی' => 'staff'][$g] ?? 'unknown';
        $c[$k]++;
        $c['total']++;
    }
    return $c;
}

/** عکسِ امروزِ نیروی انسانیِ تیم (هر بار که گزارش باز می‌شود به‌روز می‌شود) */
function sd_snapshot_today(PDO $pdo, int $teamId, int $leaderId): void
{
    if (!sd_ready($pdo) || $teamId <= 0) return;
    $c = sd_headcount_now($pdo, $teamId, $leaderId);
    try {
        $pdo->prepare('INSERT INTO sup_team_daily (team_id, day, dev, ops, staff, unknown, total) VALUES (?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE dev = VALUES(dev), ops = VALUES(ops), staff = VALUES(staff), unknown = VALUES(unknown), total = VALUES(total)')
            ->execute([$teamId, date('Y-m-d'), $c['dev'], $c['ops'], $c['staff'], $c['unknown'], $c['total']]);
    } catch (Throwable $e) {
        error_log('sd_snapshot_today: ' . $e->getMessage());
    }
}

/** عکسِ امروز برای همه‌ی تیم‌ها */
function sd_snapshot_all(PDO $pdo): void
{
    if (!sd_ready($pdo)) return;
    foreach (sup_leaders($pdo) as $L) sd_snapshot_today($pdo, (int) $L['team_id'], (int) $L['id']);
}

/** نیروی انسانیِ تیم برای هر روز: [day => dev/ops/staff/unknown/total] */
function sd_headcount_series(PDO $pdo, int $teamId, int $leaderId, array $days): array
{
    $snap = [];
    if (sd_ready($pdo) && $days) {
        $st = $pdo->prepare('SELECT * FROM sup_team_daily WHERE team_id = ? AND day BETWEEN ? AND ?');
        $st->execute([$teamId, reset($days), end($days)]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) $snap[$r['day']] = array_map('intval', array_intersect_key($r, array_flip(['dev', 'ops', 'staff', 'unknown', 'total'])));
    }
    $out = [];
    foreach ($days as $d) {
        $out[$d] = $snap[$d] ?? ($d >= date('Y-m-d') ? sd_headcount_now($pdo, $teamId, $leaderId) : sd_headcount_now($pdo, $teamId, $leaderId, $d));
    }
    return $out;
}

/** قانونِ تعداد (همان tsr_staff_rule) با شمارشِ این بخش */
function sd_staff_rule(array $c): array
{
    return tsr_staff_rule(['توسعه' => $c['dev'], 'عملیات' => $c['ops'], 'ستادی' => $c['staff'], 'نامشخص' => $c['unknown']]);
}

/** «اولین خریدِ تأییدشده‌ی این شخص است؟» */
function sd_is_first_order(PDO $pdo, int $orderId, int $customerId, string $decidedAt): bool
{
    static $cache = [];
    if (isset($cache[$orderId])) return $cache[$orderId];
    $ids = [$customerId];
    try {
        if (!function_exists('cc_person_ids')) require_once __DIR__ . '/customer_credit.php';
        $ids = array_values(array_unique(array_merge($ids, array_map('intval', cc_person_ids($pdo, $customerId) ?: []))));
    } catch (Throwable $e) {}
    $in = implode(',', array_map('intval', $ids));
    $st = $pdo->prepare("SELECT COUNT(*) FROM sales_orders WHERE customer_id IN ($in) AND status = 'approved' AND id <> ?
        AND (decided_at < ? OR (decided_at = ? AND id < ?))");
    $st->execute([$orderId, $decidedAt, $decidedAt, $orderId]);
    return $cache[$orderId] = ((int) $st->fetchColumn() === 0);
}

/**
 * فروشِ تیم برای هر روز: [day => leads, nego, new_cnt, new_amt, old_cnt, old_amt, total_amt]
 */
function sd_sales_series(PDO $pdo, array $ids, array $days): array
{
    $blank = ['leads' => 0, 'nego' => 0, 'new_cnt' => 0, 'new_amt' => 0, 'old_cnt' => 0, 'old_amt' => 0, 'total_amt' => 0];
    $out = array_fill_keys($days, $blank);
    if (!$ids || !$days) return $out;
    $in = implode(',', array_map('intval', $ids));
    $from = reset($days);
    $to = end($days);
    // لید: مشتری‌های یکتا با پیگیری/تماس در آن روز
    try {
        $st = $pdo->prepare("SELECT f.followup_date d, COUNT(DISTINCT f.customer_id) n FROM followups f JOIN customers c ON c.id = f.customer_id
            WHERE f.created_by IN ($in) AND f.followup_date BETWEEN ? AND ? AND COALESCE(c.contact_type, 'customer') = 'customer' GROUP BY f.followup_date");
        $st->execute([$from, $to]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) if (isset($out[$r['d']])) $out[$r['d']]['leads'] = (int) $r['n'];
    } catch (Throwable $e) {
        error_log('sd leads: ' . $e->getMessage());
    }
    // مذاکره: جلسه‌ی برگزارشده یا پیگیری با وضعیتِ مرحله‌ی مذاکره (مشتریِ یکتا در روز)
    $nego = [];
    try {
        $st = $pdo->prepare("SELECT f.followup_date d, f.customer_id c FROM followups f JOIN customers cu ON cu.id = f.customer_id
            WHERE f.created_by IN ($in) AND f.followup_date BETWEEN ? AND ? AND COALESCE(cu.contact_type, 'customer') = 'customer'
              AND f.status_after IN ('جلسه برگزار شد', 'در انتظار تصمیم', 'در انتظار پرداخت')");
        $st->execute([$from, $to]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) $nego[$r['d']][(int) $r['c']] = true;
    } catch (Throwable $e) {}
    try {
        $st = $pdo->prepare("SELECT meeting_date d, customer_id c FROM meeting_bookings WHERE status = 'held' AND staff_id IN ($in) AND meeting_date BETWEEN ? AND ?");
        $st->execute([$from, $to]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) $nego[$r['d']][(int) $r['c']] = true;
    } catch (Throwable $e) {}
    foreach ($nego as $d => $set) if (isset($out[$d])) $out[$d]['nego'] = count($set);
    // سفارش‌های تأییدشده ← جدید / قدیم
    try {
        $st = $pdo->prepare("SELECT id, customer_id, decided_at, DATE(decided_at) d, (total_amount - tax_amount) net FROM sales_orders
            WHERE seller_user_id IN ($in) AND status = 'approved' AND DATE(decided_at) BETWEEN ? AND ?");
        $st->execute([$from, $to]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $o) {
            if (!isset($out[$o['d']])) continue;
            $k = sd_is_first_order($pdo, (int) $o['id'], (int) $o['customer_id'], (string) $o['decided_at']) ? 'new' : 'old';
            $out[$o['d']][$k . '_cnt']++;
            $out[$o['d']][$k . '_amt'] += max(0, (int) $o['net']);
            $out[$o['d']]['total_amt'] += max(0, (int) $o['net']);
        }
    } catch (Throwable $e) {
        error_log('sd orders: ' . $e->getMessage());
    }
    return $out;
}

/** حقوقِ ثابتِ ماهانه‌ی هر نفر: [id => ['amount' => n, 'source' => 'field'|'payout'|null]] */
function sd_salaries(PDO $pdo, array $ids): array
{
    $out = [];
    if (!$ids) return $out;
    $in = implode(',', array_map('intval', $ids));
    foreach ($ids as $id) $out[(int) $id] = ['amount' => 0, 'source' => null];
    try {
        foreach ($pdo->query("SELECT id, monthly_salary FROM users WHERE id IN ($in)")->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            if ((int) $r['monthly_salary'] > 0) $out[(int) $r['id']] = ['amount' => (int) $r['monthly_salary'], 'source' => 'field'];
        }
    } catch (Throwable $e) {}
    try {
        $st = $pdo->query("SELECT p.user_id, p.amount FROM perf_payouts p
            WHERE p.user_id IN ($in) AND p.kind = 'salary' AND p.voided_at IS NULL
            ORDER BY p.user_id, COALESCE(p.period_month, '') DESC, p.paid_at DESC, p.id DESC");
        $seen = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $u = (int) $r['user_id'];
            if (isset($seen[$u])) continue;
            $seen[$u] = true;
            if ($out[$u]['source'] === null && (int) $r['amount'] > 0) $out[$u] = ['amount' => (int) $r['amount'], 'source' => 'payout'];
        }
    } catch (Throwable $e) {}
    return $out;
}

/**
 * قانونِ پ در بازه‌ی گزارش (اولِ ماه تا روزِ انتخاب‌شده):
 * هدف = (مجموعِ حقوقِ ماهانه ÷ ۲۴) × ۱۰ × روزهای کاری (بدونِ جمعه)؛ ✓ اگر آورده ≥ هدف. ok = null یعنی حقوقی ثبت نشده.
 */
function sd_p_rule(PDO $pdo, array $ids, array $days, int $actual): array
{
    $sal = sd_salaries($pdo, $ids);
    $monthly = array_sum(array_column($sal, 'amount'));
    $work = count(array_filter($days, static fn($d) => (int) date('N', strtotime($d)) !== 5));
    $dailyTarget = $monthly / 24 * 10;
    $target = (int) round($dailyTarget * max(1, $work));
    return ['ok' => $monthly > 0 ? $actual >= $target : null, 'target' => $target, 'actual' => $actual, 'monthly' => $monthly,
        'daily_target' => (int) round($dailyTarget), 'work_days' => $work, 'missing' => count(array_filter($sal, static fn($s) => $s['source'] === null))];
}

/**
 * خدمات برای هر روز: [service_id => [day => n]] — عددِ ثبت‌شده (دستی) بر شمارشِ خودکار مقدم است.
 * $auto: فقط مقدارِ خودکار (برای نمایش کنارِ فرمِ ثبت).
 */
function sd_services_series(PDO $pdo, int $teamId, array $ids, array $days, array $types, ?array &$auto = null, ?array &$manual = null): array
{
    $out = [];
    $auto = [];
    $manual = [];
    if (!$days) return $out;
    $from = reset($days);
    $to = end($days);
    $in = $ids ? implode(',', array_map('intval', $ids)) : '0';
    $q = static function (string $sql) use ($pdo, $from, $to): array {
        try {
            $st = $pdo->prepare($sql);
            $st->execute([$from, $to]);
            return $st->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    };
    $bySource = [];
    foreach ($types as $t) {
        $src = (string) $t['source'];
        if ($src === 'manual' || isset($bySource[$src])) continue;
        $bySource[$src] = match ($src) {
            'meeting_b' => $q("SELECT meeting_date, COUNT(*) FROM meeting_bookings WHERE status = 'held' AND staff_id IN ($in) AND meeting_date BETWEEN ? AND ? GROUP BY meeting_date"),
            'inperson_hire' => $q("SELECT interview_date, COUNT(*) FROM reception_inperson_interviews WHERE status = 'done' AND supervisor_user_id IN ($in) AND interview_date BETWEEN ? AND ? GROUP BY interview_date"),
            'letters' => $q("SELECT DATE(sent_at), COUNT(*) FROM letters WHERE sender_user_id IN ($in) AND sent_at IS NOT NULL AND DATE(sent_at) BETWEEN ? AND ? GROUP BY DATE(sent_at)"),
            'tickets' => $q("SELECT DATE(COALESCE(sent_at, created_at)), COUNT(*) FROM aradbranding_tickets WHERE created_by IN ($in) AND DATE(COALESCE(sent_at, created_at)) BETWEEN ? AND ? GROUP BY DATE(COALESCE(sent_at, created_at))"),
            'customer_calls' => $q("SELECT f.followup_date, COUNT(DISTINCT f.customer_id) FROM followups f JOIN customers c ON c.id = f.customer_id
                WHERE f.created_by IN ($in) AND f.call_duration_seconds > 0 AND COALESCE(c.contact_type, 'customer') = 'customer' AND f.followup_date BETWEEN ? AND ? GROUP BY f.followup_date"),
            default => [],
        };
    }
    if (sd_ready($pdo)) {
        $st = $pdo->prepare('SELECT service_id, day, cnt FROM sup_service_daily WHERE team_id = ? AND day BETWEEN ? AND ?');
        $st->execute([$teamId, $from, $to]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) $manual[(int) $r['service_id']][$r['day']] = (int) $r['cnt'];
    }
    foreach ($types as $t) {
        $sid = (int) $t['id'];
        foreach ($days as $d) {
            $a = (int) ($bySource[(string) $t['source']][$d] ?? 0);
            $auto[$sid][$d] = $a;
            $out[$sid][$d] = $manual[$sid][$d] ?? $a;
        }
    }
    return $out;
}

/** همه‌ی داده‌ی یک صفحه‌ی گزارش برای یک سرپرست و یک روز */
function sd_build(PDO $pdo, array $leader, string $day): array
{
    $teamId = (int) $leader['team_id'];
    $lid = (int) $leader['id'];
    $days = sd_month_days($day);
    $ids = sd_team_ids($pdo, $teamId, $lid);
    $types = sd_service_types($pdo);
    $hc = sd_headcount_series($pdo, $teamId, $lid, $days);
    $sales = sd_sales_series($pdo, $ids, $days);
    $svc = sd_services_series($pdo, $teamId, $ids, $days, $types, $auto, $manual);
    $mtd = array_sum(array_column($sales, 'total_amt'));
    return [
        'leader' => $leader, 'day' => $day, 'days' => $days, 'ids' => $ids, 'types' => $types,
        'hc' => $hc, 'hc_day' => $hc[$day], 'staff_rule' => sd_staff_rule($hc[$day]),
        'sales' => $sales, 'sales_day' => $sales[$day], 'p_rule' => sd_p_rule($pdo, $ids, $days, $mtd),
        'svc' => $svc, 'svc_auto' => $auto, 'svc_manual' => $manual,
    ];
}

// ─── نمایش ───────────────────────────────────────────────────────────

/** میلیون تومان برای نمایش: ۷۸٬۴۰۰٬۰۰۰ ← «۷۸»، زیرِ ۱۰ میلیون با یک رقمِ اعشار */
function sd_million(int $toman): string
{
    $m = $toman / 1000000;
    $s = $m >= 10 || $m == 0 ? number_format(round($m)) : rtrim(rtrim(number_format($m, 1, '.', ','), '0'), '.');
    return to_persian_digits(str_replace('.', '٫', $s));
}

/** بیشینه‌ی محورِ عمودی: گامِ «گرد» × ۴ (گامِ صحیح؛ ۱٫۵ و ۲٫۵ فقط از ۱۰ به بالا) تا هر ۵ خطِ راهنما عددِ صحیح باشند */
function sd_nice_max(float $v): float
{
    if ($v <= 0) return 4;
    $raw = $v / 4;
    $exp = (int) floor(log10($raw));
    $f = $raw / (10 ** $exp);
    $steps = $exp >= 1 ? [1, 1.5, 2, 2.5, 3, 4, 5, 6, 8, 10] : [1, 2, 3, 4, 5, 6, 8, 10];
    $nf = 10;
    foreach ($steps as $c) if ($f <= $c + 1e-9) { $nf = $c; break; }
    $step = max($nf * (10 ** $exp), $v >= 1 ? 1 : 0.25);
    return $step * 4;
}

/**
 * نمودارِ خطیِ واقعی (SVG) — محورِ افقی روز، محورِ عمودی واحد. $series: [['name','color','values'=>[...]]]
 * خطوطِ ۲px، نقطه‌ی ۸px با حلقه‌ی هم‌رنگِ زمینه، راهنمای کم‌رنگ، برچسبِ مقدارِ آخر برای ≤ ۴ سری؛ راهنما (legend) بالای نمودار.
 */
function sd_line_chart(array $labels, array $series, string $unit, int $w = 430, int $h = 200, bool $endLabels = true): string
{
    $n = count($labels);
    $padL = 34; $padR = 34; $padT = 16; $padB = 22;
    $pw = $w - $padL - $padR; $ph = $h - $padT - $padB;
    $max = 0;
    foreach ($series as $s) foreach ($s['values'] as $v) $max = max($max, (float) $v);
    $max = sd_nice_max($max);
    $x = static fn(int $i): float => $padL + ($n <= 1 ? $pw / 2 : $pw * $i / ($n - 1));
    $y = static fn(float $v): float => $padT + $ph - ($max > 0 ? $ph * $v / $max : 0);
    $fmt = static function (float $v) use ($unit): string {
        $s = $unit === 'میلیون' ? sd_million((int) round($v * 1000000)) : to_persian_digits(number_format((int) round($v)));
        return $s;
    };
    $svg = '<svg class="sdr-chart" viewBox="0 0 ' . $w . ' ' . $h . '" width="100%" preserveAspectRatio="xMidYMid meet" role="img" aria-label="' . e($unit) . '" xmlns="http://www.w3.org/2000/svg">';
    // راهنمای افقی + برچسبِ محورِ عمودی
    for ($k = 0; $k <= 4; $k++) {
        $v = $max * $k / 4;
        $yy = round($y($v), 1);
        $svg .= '<line x1="' . $padL . '" x2="' . ($w - $padR) . '" y1="' . $yy . '" y2="' . $yy . '" stroke="' . ($k === 0 ? '#bdbcb6' : '#e8e7e2') . '" stroke-width="' . ($k === 0 ? 1 : 0.7) . '"/>';
        $svg .= '<text x="' . ($padL - 5) . '" y="' . ($yy + 3) . '" text-anchor="end" font-size="8.5" fill="#6b6a65">' . $fmt($v) . '</text>';
    }
    $svg .= '<text x="' . ($padL - 5) . '" y="' . ($padT - 8) . '" text-anchor="end" font-size="8" fill="#8a8984">' . e($unit) . '</text>';
    // برچسبِ روزها (اگر زیاد باشند یک‌درمیان؛ روزِ آخر همیشه)
    $step = $n > 16 ? 3 : ($n > 9 ? 2 : 1);
    foreach ($labels as $i => $lb) {
        if ($i % $step !== 0 && $i !== $n - 1) continue;
        if ($i !== $n - 1 && $n - 1 - $i < $step) continue;
        $svg .= '<text x="' . round($x($i), 1) . '" y="' . ($h - 8) . '" text-anchor="middle" font-size="8.5" fill="#6b6a65">' . e($lb) . '</text>';
    }
    // خطوط و نقطه‌ها
    $ends = [];
    foreach ($series as $s) {
        $pts = [];
        foreach (array_values($s['values']) as $i => $v) $pts[] = round($x($i), 1) . ',' . round($y((float) $v), 1);
        if ($n > 1) $svg .= '<polyline fill="none" stroke="' . $s['color'] . '" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" points="' . implode(' ', $pts) . '"/>';
        foreach (array_values($s['values']) as $i => $v) {
            $svg .= '<circle cx="' . round($x($i), 1) . '" cy="' . round($y((float) $v), 1) . '" r="' . ($n > 16 ? 2.4 : 3.2) . '" fill="' . $s['color'] . '" stroke="#ffffff" stroke-width="1.2"><title>'
                . e($s['name'] . ' — ' . $labels[$i] . ': ' . $fmt((float) $v) . ' ' . $unit) . '</title></circle>';
        }
        $last = (float) (array_values($s['values'])[$n - 1] ?? 0);
        $ends[] = ['y' => $y($last), 'v' => $last, 'color' => $s['color']];
    }
    // برچسبِ مقدارِ روزِ آخر (≤ ۴ سری) — با فاصله تا روی هم نیفتند
    if ($endLabels && count($series) <= 4) {
        usort($ends, static fn($a, $b) => $a['y'] <=> $b['y']);
        $prev = -100;
        foreach ($ends as $e2) {
            $yy = max($e2['y'] + 3, $prev + 10);
            $prev = $yy;
            $svg .= '<text x="' . ($w - $padR + 5) . '" y="' . round($yy, 1) . '" text-anchor="start" font-size="8.5" font-weight="700" fill="#2b2a27">' . $fmt($e2['v']) . '</text>';
        }
    }
    return $svg . '</svg>';
}

/** راهنمای سری‌ها (رنگ + نام) — شناسایی هرگز فقط با رنگ نیست */
function sd_legend(array $series): string
{
    $h = '<div class="sdr-legend">';
    foreach ($series as $s) $h .= '<span><i style="background:' . $s['color'] . '"></i>' . e($s['name']) . '</span>';
    return $h . '</div>';
}

/** رنگ‌های ثابتِ سری‌ها (پالتِ اعتبارسنجی‌شده، ترتیبِ ثابت) */
function sd_palette(): array
{
    return ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300', '#4a3aa7', '#e34948'];
}

/** یک صفحه‌ی A4 (HTML) */
function sd_render_sheet(array $R): string
{
    $L = $R['leader'];
    $pal = sd_palette();
    $labels = array_map('sd_day_label', $R['days']);
    $num = static fn(int $n): string => to_persian_digits(number_format($n));
    $row = static fn(string $k, string $v, string $u = '', string $cls = ''): string => '<div class="sdr-kv ' . $cls . '"><span class="k">' . e($k) . '</span><span class="v">' . $v . ($u !== '' ? ' <small>' . e($u) . '</small>' : '') . '</span></div>';
    $mark = static fn(?bool $ok): string => $ok === null ? '<b class="na">—</b>' : ($ok ? '<b class="ok">✓</b>' : '<b class="no">✕</b>');

    $hc = $R['hc_day'];
    $a1 = '<h3>نیروی انسانی</h3>' . $row('توسعه', $num($hc['dev']), 'نفر') . $row('عملیات', $num($hc['ops']), 'نفر') . $row('ستادی', $num($hc['staff']), 'نفر')
        . ($hc['unknown'] > 0 ? $row('نامشخص', $num($hc['unknown']), 'نفر', 'muted') : '')
        . $row('کل نیروها', $num($hc['total']), 'نفر', 'sum') . '<div class="sdr-rule">قانون تعداد ' . $mark($R['staff_rule']['ok']) . '</div>';
    $hcSeries = [
        ['name' => 'توسعه', 'color' => $pal[0], 'values' => array_column($R['hc'], 'dev')],
        ['name' => 'عملیات', 'color' => $pal[1], 'values' => array_column($R['hc'], 'ops')],
        ['name' => 'ستادی', 'color' => $pal[2], 'values' => array_column($R['hc'], 'staff')],
    ];
    $a2 = '<h3>روند نیروها</h3>' . sd_legend($hcSeries) . sd_line_chart($labels, $hcSeries, 'نفر', 430, 180);

    $s = $R['sales_day'];
    $b1 = '<h3>خروجی فروش</h3>' . $row('تعداد لید', $num($s['leads'])) . $row('تعداد مذاکره', $num($s['nego'])) . $row('تعداد جدید', $num($s['new_cnt']))
        . $row('پ جدید', sd_million($s['new_amt']), 'میلیون') . $row('قدیم', $num($s['old_cnt'])) . $row('پ قدیم', sd_million($s['old_amt']), 'میلیون')
        . $row('پ کل', sd_million($s['total_amt']), 'میلیون', 'sum') . '<div class="sdr-rule">قانون پ ' . $mark($R['p_rule']['ok']) . '</div>';
    $mil = static fn(array $v): array => array_map(static fn($x) => $x / 1000000, $v);
    $pSeries = [
        ['name' => 'پ جدید', 'color' => $pal[0], 'values' => $mil(array_column($R['sales'], 'new_amt'))],
        ['name' => 'پ قدیم', 'color' => $pal[1], 'values' => $mil(array_column($R['sales'], 'old_amt'))],
        ['name' => 'پ کل', 'color' => $pal[2], 'values' => $mil(array_column($R['sales'], 'total_amt'))],
    ];
    $cSeries = [
        ['name' => 'مذاکره', 'color' => $pal[0], 'values' => array_column($R['sales'], 'nego')],
        ['name' => 'جدید', 'color' => $pal[1], 'values' => array_column($R['sales'], 'new_cnt')],
        ['name' => 'قدیم', 'color' => $pal[2], 'values' => array_column($R['sales'], 'old_cnt')],
    ];
    $b2 = '<h3>روند آورده و نفرات</h3><div class="sdr-two"><div>' . sd_legend($pSeries) . sd_line_chart($labels, $pSeries, 'میلیون', 430, 140)
        . '</div><div>' . sd_legend($cSeries) . sd_line_chart($labels, $cSeries, 'نفر', 430, 140) . '</div></div>';

    $c1 = '<h3>خدمات</h3>';
    $tot = 0;
    $svcSeries = [];
    foreach ($R['types'] as $i => $t) {
        $v = (int) ($R['svc'][(int) $t['id']][$R['day']] ?? 0);
        $tot += $v;
        $c1 .= $row((string) $t['title'], $num($v), 'مورد');
        $svcSeries[] = ['name' => (string) $t['title'], 'color' => $pal[$i % count($pal)], 'values' => array_values($R['svc'][(int) $t['id']] ?? [])];
    }
    $c1 .= $row('مجموع خدمات', $num($tot), 'مورد', 'sum');
    $c2 = '<h3>روند خدمات</h3>' . sd_legend($svcSeries) . sd_line_chart($labels, $svcSeries, 'مورد', 430, 190, false);

    $tn = trim((string) ($L['team_name'] ?? ''));
    $teamTitle = 'سرپرست تیم ' . ($tn !== '' ? $tn : team_display_name(null, (int) $L['team_id']));
    return '<section class="sdr-sheet">'
        . '<header class="sdr-head"><div class="n">' . e((string) $L['full_name']) . '</div><div class="t">' . e($teamTitle) . '</div><div class="d">' . e(to_persian_digits(to_jalali($R['day']))) . '</div></header>'
        . '<div class="sdr-row"><div class="sdr-info">' . $a1 . '</div><div class="sdr-viz">' . $a2 . '</div></div>'
        . '<div class="sdr-row"><div class="sdr-info">' . $b1 . '</div><div class="sdr-viz">' . $b2 . '</div></div>'
        . '<div class="sdr-row"><div class="sdr-info">' . $c1 . '</div><div class="sdr-viz">' . $c2 . '</div></div>'
        . '</section>';
}

/** استایلِ صفحه‌ی A4 (هم پیش‌نمایش، هم چاپ) — فونت: بی‌نازنین (از روی سیستم یا assets/fonts) */
function sd_sheet_css(string $base = ''): string
{
    $fontSrc = [];
    foreach (['BNazanin.woff2' => 'woff2', 'BNazanin.woff' => 'woff', 'BNazanin.ttf' => 'truetype'] as $f => $fmt) {
        if (is_file(__DIR__ . '/../assets/fonts/' . $f)) $fontSrc[] = "url('" . $base . 'assets/fonts/' . $f . "') format('" . $fmt . "')";
    }
    $face = "@font-face{font-family:'SDR Nazanin';src:local('B Nazanin'),local('BNazanin'),local('B Nazanin Regular')" . ($fontSrc ? ',' . implode(',', $fontSrc) : '') . ";font-weight:400}"
        . "@font-face{font-family:'SDR Nazanin';src:local('B Nazanin Bold'),local('BNazaninBold'),local('B Nazanin'),local('BNazanin')" . ($fontSrc ? ',' . implode(',', $fontSrc) : '') . ";font-weight:700}";
    return $face . <<<CSS
@page{size:A4 portrait;margin:0}
.sdr-sheet{direction:rtl;text-align:right;font-family:'SDR Nazanin','B Nazanin',BNazanin,'Vazirmatn',Tahoma,sans-serif;color:#1d1c1a;background:#fff;
  width:210mm;height:297mm;box-sizing:border-box;padding:13mm 13mm 10mm;display:grid;grid-template-rows:auto .82fr 1.3fr 1fr;row-gap:5mm;overflow:hidden;
  break-after:page;page-break-after:always;-webkit-print-color-adjust:exact;print-color-adjust:exact}
.sdr-sheet:last-child{break-after:auto;page-break-after:auto}
.sdr-head{text-align:center;line-height:1.5;padding-bottom:1mm}
.sdr-head .n{font-size:22pt;font-weight:700}
.sdr-head .t{font-size:13pt;color:#52514e}
.sdr-head .d{font-size:13pt;color:#52514e}
.sdr-row{display:grid;grid-template-columns:38% 1fr;column-gap:9mm;min-height:0}
.sdr-info,.sdr-viz{min-width:0;min-height:0}
.sdr-sheet h3{font-size:13pt;font-weight:700;margin:0 0 2.5mm;color:#1d1c1a}
.sdr-kv{display:flex;justify-content:space-between;align-items:baseline;font-size:12.5pt;line-height:1.75}
.sdr-kv .k{color:#3a3936}
.sdr-kv .v{font-weight:700;font-variant-numeric:tabular-nums}
.sdr-kv .v small{font-weight:400;color:#6b6a65;font-size:10pt}
.sdr-kv.sum{margin-top:1mm;font-size:13pt}
.sdr-kv.muted .k,.sdr-kv.muted .v{color:#8a8984}
.sdr-rule{margin-top:2.5mm;font-size:13pt;font-weight:700}
.sdr-rule b{font-size:15pt;margin-right:2mm}
.sdr-rule .ok{color:#0f7a3a}.sdr-rule .no{color:#c0272d}.sdr-rule .na{color:#8a8984}
.sdr-legend{display:flex;flex-wrap:wrap;gap:1mm 4mm;font-size:10pt;color:#3a3936;margin-bottom:1mm}
.sdr-legend i{display:inline-block;width:14px;height:3px;border-radius:2px;margin-left:5px;vertical-align:middle}
.sdr-chart{display:block;font-family:'SDR Nazanin','B Nazanin',BNazanin,'Vazirmatn',Tahoma,sans-serif;direction:ltr}
.sdr-two{display:grid;grid-template-rows:1fr 1fr;row-gap:3mm}
CSS;
}
