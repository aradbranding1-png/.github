<?php
/**
 * ═══════════════════════════════════════════════════════════════════════
 *  گزارشِ روزانه‌ی A4ِ سرپرست — موتورِ عمومیِ گزارشِ تیم‌ها (داده‌ها)
 * ═══════════════════════════════════════════════════════════════════════
 *  A1 نیروی انسانیِ روز            A2 روندِ نیروی انسانی (اولِ ماه تا تاریخِ گزارش)
 *  B1 عملکردِ تجاریِ روز            B2 روندِ عملکردِ تجاری
 *  C1 شاخص‌های اختصاصیِ تیم در روز   C2 روندِ شاخص‌های اختصاصیِ تیم
 *
 *  report_date: تاریخِ گزارش متغیر است (ورودیِ صفحه)؛ بخش‌های ۱ همان روز، نمودارهای ۲ از اولِ ماه تا همان روز.
 *
 *  تیم = کلِ نیروهای تحتِ مدیریتِ سرپرست (حضوری، غیرحضوری/دورکار و بقیه) + خودِ سرپرست — نه فقط ثبت‌های سرپرست.
 *  مسیر: سرپرست ← تیمِ او ← اعضای تیم ← داده‌های ثبت‌شده‌ی همه‌ی اعضا ← تجمیع.
 *
 *  تعریف‌ها (یک جا، برای همه‌ی بخش‌ها):
 *   - لید      : هر فردی (مشتری، نه همکار/خانواده) که در آن روز با تیم در ارتباط قرار گرفته، بدونِ توجه به نتیجه:
 *                پیگیری/تماسِ ثبت‌شده (پاسخ داد، پاسخ نداد، منصرف شد، هر وضعیتی)، فردی که همان روز به سامانه اضافه شده،
 *                جلسه‌ی برگزارشده. ملاک ثبتِ ارتباط است، نه موفقیتِ آن.
 *   - مذاکره    : از همان لیدها، کسانی که پاسخ داده‌اند و گفت‌وگو ثبت شده: تماسِ برقرار (بیش از حدِ «برقرار»)،
 *                پیگیریِ دستیِ غیر از «عدم پاسخ»، یا جلسه‌ی برگزارشده.
 *   - پ ج / جدید: پولِ «اولین پرداختِ» یک مشتری (اولین پولِ تأییدشده‌ی آن شخص در کلِ سامانه) / تعدادِ همین مشتریان
 *   - پ ق / قدیم: هر پولِ دیگری (مشتری قبلاً پول داده؛ قسط‌ها، خریدِ دوم …) / تعدادِ همین مشتریان
 *   - پ کل      : پ ج + پ ق — همان تعریفِ «گزارش فروش» (خالص، هر پرداخت روزِ تأییدش، به نامِ صاحبِ سهم)
 *   - قانونِ تعداد: توسعه‌ی لازم = ⌈عملیات ÷ ۸⌉ + ⌈ستادی ÷ ۲⌉ ؛ ✓ اگر توسعه ≥ توسعه‌ی لازم
 *   - قانونِ پ  : حقوقِ روزانه‌ی هر نیرو = حقوقِ ثابتِ ماهانه ÷ ۲۴ ؛ هدفِ روزانه = مجموعِ حقوقِ روزانه‌ی تیم × ۱۰ ؛
 *                هدفِ دوره = هدفِ روزانه × روزهای دوره (اولِ ماه تا تاریخِ گزارش، بدونِ جمعه) ؛ ✓ اگر پ کلِ دوره ≥ هدف.
 *                حقوقِ ثابت = custom_fixed_salary اگر وارد شده، وگرنه پیش‌فرضِ نوعِ نیرو (سرپرست / حضوری / غیرحضوری) — قابلِ تنظیم.
 *   - C1/C2    : شاخص‌های هر تیم از تنظیماتِ همان تیم (team_metrics)؛ مقدارِ روز از team_metric_values
 *                (یا شمارشِ خودکار، اگر شاخص منبعِ خودکار دارد؛ عددِ ثبت‌شده بر خودکار مقدم است).
 */

function sd_ready(PDO $pdo): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    if (function_exists('team_names_sync_v1')) team_names_sync_v1($pdo);
    $flag = __DIR__ . '/../storage/.supervisor_daily_v2';
    try {
        if (is_file($flag)) return $ok = true;
        $cols = $pdo->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'")->fetchAll(PDO::FETCH_COLUMN) ?: [];
        if (!in_array('custom_fixed_salary', $cols, true)) $pdo->exec('ALTER TABLE users ADD COLUMN custom_fixed_salary BIGINT UNSIGNED NULL DEFAULT NULL');
        // حقوقی که قبلاً در «حقوقِ ثابتِ ماهانه» ثبت شده بود ← حقوقِ سفارشی
        if (in_array('monthly_salary', $cols, true)) $pdo->exec('UPDATE users SET custom_fixed_salary = monthly_salary WHERE custom_fixed_salary IS NULL AND monthly_salary > 0');
        $pdo->exec("CREATE TABLE IF NOT EXISTS sup_team_daily (
            team_id INT UNSIGNED NOT NULL, day DATE NOT NULL,
            dev INT NOT NULL DEFAULT 0, ops INT NOT NULL DEFAULT 0, staff INT NOT NULL DEFAULT 0, unknown INT NOT NULL DEFAULT 0, total INT NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (team_id, day)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS sd_settings (
            k VARCHAR(40) NOT NULL PRIMARY KEY, v VARCHAR(255) NOT NULL,
            updated_by INT UNSIGNED NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS team_metrics (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            team_id INT UNSIGNED NOT NULL, metric_name VARCHAR(150) NOT NULL, metric_key VARCHAR(60) NOT NULL,
            source VARCHAR(30) NOT NULL DEFAULT 'manual', sort_order INT NOT NULL DEFAULT 0, is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_tm_key (team_id, metric_key), KEY idx_tm_team (team_id, is_active, sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS team_metric_values (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            team_id INT UNSIGNED NOT NULL, metric_id INT UNSIGNED NOT NULL, report_date DATE NOT NULL,
            value DECIMAL(14,2) NOT NULL DEFAULT 0, entered_by INT UNSIGNED NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_tmv (team_id, metric_id, report_date), KEY idx_tmv_date (report_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        sd_seed_metrics($pdo);
        sd_migrate_services_v2($pdo);
        @file_put_contents($flag, date('c'));
        return $ok = true;
    } catch (Throwable $e) {
        error_log('sd_ready: ' . $e->getMessage());
        return $ok = false;
    }
}

/** شاخص‌های پیش‌فرضِ C1 هر تیم (فقط برای تیمی که هنوز هیچ شاخصی ندارد ثبت می‌شود؛ بعد از آن از صفحه‌ی گزارش قابلِ تغییر است) */
function sd_default_metrics(): array
{
    return [
        106 => ['ارتباط با تاجر', 'میتینگ B', 'پاسخ تیکت', 'مکاتبه رسمی', 'جلسه حضوری استخدام', 'میتینگ آنلاین'],
        109 => ['جذب نماینده داخلی', 'جذب نماینده خارجی', 'تعداد دفتر خارجی جدید', 'تعداد دفتر داخلی جدید', 'شوروم داخلی جدید', 'شوروم خارجی جدید',
                'تجارت داخلی', 'تجارت خارجی', 'تاجر متصل به داخلی', 'تاجر متصل به خارجی', 'جلسه استخدامی', 'جلسه B'],
        102 => ['تعداد رایزنی', 'تعداد مذاکره', 'تعداد قرارداد تجارت', 'تعداد انجام عملیات بازرگانی', 'تماس با تاجر', 'ارتباط با تاجر در فضای مجازی'],
        107 => ['شروع مذاکره با مشتری داخلی', 'شروع مذاکره با مشتری خارجی', 'پیگیری مذاکره موجود داخلی', 'پیگیری مذاکره موجود خارجی',
                'تجارت انجام شده داخلی', 'تجارت انجام شده خارجی', 'ارتباط با تاجران', 'مذاکره مجازی', 'میتینگ خارجی', 'میتینگ B', 'ارتباط با نمایندگان خارجی'],
        115 => ['تعداد تولید محتوا تجارتخانه', 'تعداد تولید محتوا سایت تاجر', 'تعداد راه‌اندازی سایت', 'تیک', 'نظارت محتوا',
                'تولید محتوا تجارتخانه معوقه', 'تولید محتوا سایت تاجر معوقه', 'طراحی سایت معوقه'],
        112 => ['میتینگ استخدام', 'میتینگ B', 'پیگیری نماینده خارجی', 'پاسخگویی تیکت', 'تماس با تاجر', 'انتصاب سمت', 'جلسه حضوری برای استخدام'],
        104 => ['تاجر به تامین رسید', 'بار ارسال شده', 'تامین واقعی جدید انجام شده', 'استعلام لجستیک داده شده', 'میتینگ استخدام',
                'جلسه حضوری برای استخدام', 'پاسخ به تیکت', 'تماس با تاجر', 'جلسه حضوری با تاجر', 'ارتباط با شرکت‌های حمل'],
        110 => ['تعداد پیشنهادهای تجاری', 'تعداد درخواست آمده', 'تعداد ارجاع موفق', 'تعداد تجارت انجام شده خارجی', 'ارائه بانک ارتباطات داخلی به تاجر',
                'میتینگ استخدام', 'میتینگ B', 'ایجاد بانک جدید', 'پاسخ به تیکت', 'مطالبات', 'پیگیری مشتری', 'بارگذاری پیشنهاد روی سایت', 'معوقه'],
        103 => ['تعداد نیرو', 'گزارش کار', 'پاسخگویی تیکت', 'تماس تلفنی با نیروی دورکار', 'تماس تلفنی با تاجر', 'میتینگ استخدام', 'جلسه حضوری استخدام'],
        119 => ['لوگو', 'کارت ویزیت', 'پاکت نامه', 'سربرگ', 'کاتالوگ', 'کمپانی پروفایل', 'پاسخ تیکت', 'پیگیری مطالبات', 'معوقه'],
        105 => ['تماس‌های پشتیبانی تجاری', 'تماس‌های مشاوره تجاری', 'تماس مشاوره انتخاب محصول', 'تیکت‌های پاسخ داده شده', 'مطالبات پیگیری شده',
                'تماس‌های مشاوره سیستم‌سازی', 'جلسه B', 'بستن فاکتور و ارسال به مالی'],
        101 => ['چک کامنت', 'افزایش امتیاز تاجران', 'پاسخ به تیکت', 'پیگیری و انتشار استوری', 'پیگیری مطالبه تاجران', 'انتشار خبر', 'انتشار نظرسنجی',
                'جلسه حضوری برای استخدام', 'ارتباط با مشتری', 'تولید محتوا در سامانه', 'خدمات اکانت سامانه آموزشی', 'میتینگ آموزشی'],
        108 => ['پاسخگویی به تیکت‌های پیشنهادات و انتقادات', 'میتینگ استخدام'],
        // ۱۱۷ (تیم استخدام) و ۱۱۸ (قرارداد و مالی): فعلاً شاخصی تعریف نشده — از صفحه‌ی گزارش اضافه می‌شود
    ];
}

/** منبعِ پیش‌فرضِ یک شاخص از روی نامش (فقط موارد بدیهی؛ بقیه دستی) */
function sd_guess_source(string $name): string
{
    if (in_array($name, ['میتینگ B', 'جلسه B'], true)) return 'meeting_b';
    if (in_array($name, ['جلسه حضوری استخدام', 'جلسه حضوری برای استخدام'], true)) return 'inperson_hire';
    if ($name === 'مکاتبه رسمی') return 'letters';
    return 'manual';
}

function sd_seed_metrics(PDO $pdo): void
{
    $has = $pdo->prepare('SELECT COUNT(*) FROM team_metrics WHERE team_id = ?');
    $ins = $pdo->prepare('INSERT IGNORE INTO team_metrics (team_id, metric_name, metric_key, source, sort_order) VALUES (?,?,?,?,?)');
    foreach (sd_default_metrics() as $tid => $names) {
        $has->execute([$tid]);
        if ((int) $has->fetchColumn() > 0) continue;
        foreach (array_values($names) as $i => $n) $ins->execute([$tid, $n, sprintf('m%02d', $i + 1), sd_guess_source($n), ($i + 1) * 10]);
    }
}

/** یک‌بار: عددهای ثبت‌شده در «خدماتِ» قبلی (یک فهرست برای همه) ← شاخصِ هم‌نامِ همان تیم */
function sd_migrate_services_v2(PDO $pdo): void
{
    try {
        $rows = $pdo->query('SELECT d.team_id, d.day, d.cnt, d.entered_by, t.title FROM sup_service_daily d JOIN sup_service_types t ON t.id = d.service_id')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return; // جدولِ قبلی وجود ندارد
    }
    $find = $pdo->prepare('SELECT id FROM team_metrics WHERE team_id = ? AND metric_name = ? LIMIT 1');
    $ins = $pdo->prepare('INSERT IGNORE INTO team_metric_values (team_id, metric_id, report_date, value, entered_by) VALUES (?,?,?,?,?)');
    foreach ($rows as $r) {
        $find->execute([(int) $r['team_id'], (string) $r['title']]);
        if ($mid = (int) $find->fetchColumn()) $ins->execute([(int) $r['team_id'], $mid, $r['day'], (int) $r['cnt'], $r['entered_by']]);
    }
}

/** منبعِ شمارشِ خودکارِ هر شاخص (هر شاخصِ جدید می‌تواند یکی از این‌ها باشد؛ «دستی» = سرپرست عددِ روز را ثبت می‌کند) */
function sd_metric_sources(): array
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

/** شاخص‌های C1ِ یک تیم */
function sd_metrics(PDO $pdo, int $teamId, bool $activeOnly = true): array
{
    if (!sd_ready($pdo) || $teamId <= 0) return [];
    $st = $pdo->prepare('SELECT * FROM team_metrics WHERE team_id = ?' . ($activeOnly ? ' AND is_active = 1' : '') . ' ORDER BY sort_order, id');
    $st->execute([$teamId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** تنظیمات (حقوقِ پیش‌فرض و …) */
function sd_setting(PDO $pdo, string $k, string $default = ''): string
{
    static $all = null;
    if ($all === null) {
        $all = [];
        try { $all = $pdo->query('SELECT k, v FROM sd_settings')->fetchAll(PDO::FETCH_KEY_PAIR) ?: []; } catch (Throwable $e) {}
    }
    return array_key_exists($k, $all) ? (string) $all[$k] : $default;
}

function sd_setting_set(PDO $pdo, string $k, string $v, int $by): void
{
    $pdo->prepare('INSERT INTO sd_settings (k, v, updated_by) VALUES (?,?,?) ON DUPLICATE KEY UPDATE v = VALUES(v), updated_by = VALUES(updated_by)')->execute([$k, $v, $by ?: null]);
}

/** حقوقِ ثابتِ پیش‌فرض برای هر نوعِ نیرو (تومان) — قابلِ تنظیم */
function sd_default_salaries(PDO $pdo): array
{
    return [
        'leader' => (int) sd_setting($pdo, 'salary_leader', '60000000'),
        'onsite' => (int) sd_setting($pdo, 'salary_onsite', '24000000'),
        'remote' => (int) sd_setting($pdo, 'salary_remote', '0'),
    ];
}

function sd_salary_type_label(string $t): string
{
    return ['leader' => 'سرپرست', 'onsite' => 'حضوری', 'remote' => 'غیرحضوری / دورکار'][$t] ?? $t;
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

/** شناسه‌ی همه‌ی افرادِ تیم: همه‌ی اعضا (حضوری، غیرحضوری، دورکار، …) + سرپرست */
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

/**
 * اولین پولِ تأییدشده‌ی این شخص (۳۶۰) در کلِ سامانه: ['order', orderId] یا ['payment', paymentId]
 * (پیش‌پرداختِ سفارش روزِ تأییدِ سفارش، قسط/پرداخت روزِ تأییدِ خودش)
 */
function sd_first_money(PDO $pdo, int $customerId): ?array
{
    static $cache = [];
    if (array_key_exists($customerId, $cache)) return $cache[$customerId];
    $ids = [$customerId];
    try {
        if (!function_exists('cc_person_ids')) require_once __DIR__ . '/customer_credit.php';
        $ids = array_values(array_unique(array_merge($ids, array_map('intval', cc_person_ids($pdo, $customerId) ?: []))));
    } catch (Throwable $e) {}
    $in = implode(',', array_map('intval', $ids));
    $r = null;
    try {
        $r = $pdo->query("SELECT k, id FROM (
                SELECT 'order' k, o.id, o.decided_at t FROM sales_orders o
                 WHERE o.customer_id IN ($in) AND o.status = 'approved' AND COALESCE(o.confirmed_amount, o.total_amount) > 0
                UNION ALL
                SELECT 'payment' k, p.id, p.decided_at t FROM sales_order_payments p JOIN sales_orders o ON o.id = p.order_id
                 WHERE o.customer_id IN ($in) AND o.status = 'approved' AND p.status = 'confirmed' AND p.kind = 'extra' AND p.amount > 0
            ) x ORDER BY t ASC, k = 'payment', id ASC LIMIT 1")->fetch(PDO::FETCH_NUM) ?: null;
    } catch (Throwable $e) {
        error_log('sd_first_money: ' . $e->getMessage());
    }
    return $cache[$customerId] = $r ? [(string) $r[0], (int) $r[1]] : null;
}

/** «اولین خریدِ تأییدشده‌ی این شخص است؟» (برای سازگاری با فراخوانی‌های قبلی) */
function sd_is_first_order(PDO $pdo, int $orderId, int $customerId, string $decidedAt): bool
{
    $f = sd_first_money($pdo, $customerId);
    return $f !== null && $f[0] === 'order' && $f[1] === $orderId;
}

/**
 * عملکردِ تجاریِ تیم برای هر روز: [day => leads, nego, new_cnt, new_amt, old_cnt, old_amt, total_amt]
 * همه بر اساسِ داده‌ی خامِ «همه‌ی» اعضای تیم ($ids).
 */
function sd_sales_series(PDO $pdo, array $ids, array $days): array
{
    $blank = ['leads' => 0, 'nego' => 0, 'new_cnt' => 0, 'new_amt' => 0, 'old_cnt' => 0, 'old_amt' => 0, 'total_amt' => 0];
    $out = array_fill_keys($days, $blank);
    if (!$ids || !$days) return $out;
    $in = implode(',', array_map('intval', $ids));
    $from = reset($days);
    $to = end($days);
    $minTalk = defined('STAFF_REPORT_CONNECTED_MIN') ? (int) STAFF_REPORT_CONNECTED_MIN : 10;
    $isCustomer = "COALESCE(c.contact_type, 'customer') = 'customer'";
    $lead = [];
    $nego = [];
    $rows = static function (string $sql, array $params = []) use ($pdo, $from, $to): array {
        try {
            $st = $pdo->prepare($sql);
            $st->execute($params ?: [$from, $to]);
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('sd_sales_series: ' . $e->getMessage());
            return [];
        }
    };
    // ۱) هر پیگیری/تماسِ ثبت‌شده با هر نتیجه‌ای ← لید؛ اگر پاسخ داده و گفت‌وگو ثبت شده ← مذاکره
    foreach ($rows("SELECT f.followup_date d, f.customer_id cid,
            MAX(CASE WHEN f.call_duration_seconds > $minTalk THEN 1
                     WHEN f.source = 'manual' AND COALESCE(f.status_after, '') <> 'عدم پاسخ' THEN 1 ELSE 0 END) talked
        FROM followups f JOIN customers c ON c.id = f.customer_id
        WHERE f.created_by IN ($in) AND f.followup_date BETWEEN ? AND ? AND $isCustomer
        GROUP BY f.followup_date, f.customer_id") as $r) {
        $lead[$r['d']][(int) $r['cid']] = true;
        if ((int) $r['talked']) $nego[$r['d']][(int) $r['cid']] = true;
    }
    // ۲) فردی که همان روز توسطِ تیم به سامانه اضافه شده ← لید
    foreach ($rows("SELECT DATE(l.created_at) d, l.customer_id cid FROM customer_activity_logs l JOIN customers c ON c.id = l.customer_id
        WHERE l.activity_type = 'create' AND l.user_id IN ($in) AND l.created_at BETWEEN ? AND ? AND $isCustomer", [$from . ' 00:00:00', $to . ' 23:59:59']) as $r) {
        $lead[$r['d']][(int) $r['cid']] = true;
    }
    foreach ($rows("SELECT DATE(c.created_at) d, c.id cid FROM customers c
        WHERE c.owner_user_id IN ($in) AND c.created_at BETWEEN ? AND ? AND $isCustomer", [$from . ' 00:00:00', $to . ' 23:59:59']) as $r) {
        $lead[$r['d']][(int) $r['cid']] = true;
    }
    // ۳) جلسه‌ی برگزارشده ← لید + مذاکره
    foreach ($rows("SELECT b.meeting_date d, b.customer_id cid FROM meeting_bookings b JOIN customers c ON c.id = b.customer_id
        WHERE b.status = 'held' AND b.staff_id IN ($in) AND b.meeting_date BETWEEN ? AND ?") as $r) {
        $lead[$r['d']][(int) $r['cid']] = true;
        $nego[$r['d']][(int) $r['cid']] = true;
    }
    foreach ($lead as $d => $set) if (isset($out[$d])) $out[$d]['leads'] = count($set);
    foreach ($nego as $d => $set) if (isset($out[$d])) $out[$d]['nego'] = count($set);

    // پول (همان تعریفِ «گزارش فروش»: خالص، هر پرداخت روزِ تأییدش، به نامِ صاحبِ سهم):
    //   پ ج = اولین پولِ آن مشتری در کلِ سامانه؛ بقیه پ ق. جدید/قدیم = تعدادِ مشتریانِ یکتای هر دسته در آن روز.
    try {
        if (!function_exists('sales_user_events_sql')) require_once __DIR__ . '/sales_credit.php';
        $st = $pdo->prepare("SELECT x.order_id, x.kind, x.payment_id, DATE(x.at) d, SUM(x.net) net, o.customer_id
            FROM (" . sales_user_events_sql($pdo) . ") x JOIN sales_orders o ON o.id = x.order_id
            WHERE x.uid IN ($in) GROUP BY x.order_id, x.kind, x.payment_id, DATE(x.at), o.customer_id");
        $st->execute(sales_user_events_params($pdo, $from, $to));
        $cust = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $e) {
            if (!isset($out[$e['d']])) continue;
            $net = max(0, (int) $e['net']);
            if ($net <= 0) continue;
            $first = sd_first_money($pdo, (int) $e['customer_id']);
            $isNew = $first !== null && (($e['kind'] === 'order' && $first[0] === 'order' && $first[1] === (int) $e['order_id'])
                || ($e['kind'] === 'payment' && $first[0] === 'payment' && $first[1] === (int) $e['payment_id']));
            $k = $isNew ? 'new' : 'old';
            $out[$e['d']][$k . '_amt'] += $net;
            $out[$e['d']]['total_amt'] += $net;
            $cust[$e['d']][$k][(int) $e['customer_id']] = true;
        }
        foreach ($cust as $d => $kk) foreach ($kk as $k => $set) $out[$d][$k . '_cnt'] = count($set);
    } catch (Throwable $e) {
        error_log('sd money: ' . $e->getMessage());
    }
    return $out;
}

/**
 * حقوقِ ثابتِ ماهانه‌ی هر نفرِ تیم:
 *   [id => ['name','type' (leader|onsite|remote),'type_assumed','default','custom','amount','source' (custom|default)]]
 * اولویت: custom_fixed_salary ← وگرنه پیش‌فرضِ نوعِ نیرو (سرپرست / حضوری / غیرحضوری-دورکار).
 * نیرویی که «حضوری/دورکار» برایش ثبت نشده، حضوری فرض می‌شود (type_assumed).
 */
function sd_salaries(PDO $pdo, array $ids, int $leaderId = 0): array
{
    $out = [];
    if (!$ids) return $out;
    $def = sd_default_salaries($pdo);
    $in = implode(',', array_map('intval', $ids));
    $cols = function_exists('users_work_cols') ? users_work_cols($pdo) : [];
    $sel = 'id, full_name, role, is_active, custom_fixed_salary'
        . (!empty($cols['work_location']) ? ', work_location' : ', NULL AS work_location')
        . (!empty($cols['work_mode']) ? ', work_mode' : ', NULL AS work_mode');
    try {
        $rows = $pdo->query("SELECT $sel FROM users WHERE id IN ($in) ORDER BY role = 'leader' DESC, full_name")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('sd_salaries: ' . $e->getMessage());
        $rows = [];
    }
    foreach ($rows as $u) {
        $uid = (int) $u['id'];
        // نیروی غیرفعال در حقوقِ تیم حساب نمی‌شود (داده‌ی گذشته‌اش در عملکرد می‌ماند)
        if ((int) $u['is_active'] !== 1 && $uid !== $leaderId) continue;
        $loc = function_exists('users_work_location_of') ? users_work_location_of($u) : null;
        $assumed = false;
        if ($uid === $leaderId || $u['role'] === 'leader') $type = 'leader';
        elseif ($loc === 'remote') $type = 'remote';
        else { $type = 'onsite'; $assumed = $loc === null; }
        $custom = $u['custom_fixed_salary'] !== null ? (int) $u['custom_fixed_salary'] : null;
        $out[$uid] = ['name' => (string) $u['full_name'], 'role' => (string) $u['role'], 'type' => $type, 'type_assumed' => $assumed,
            'default' => $def[$type], 'custom' => $custom, 'amount' => $custom ?? $def[$type], 'source' => $custom !== null ? 'custom' : 'default'];
    }
    return $out;
}

/**
 * قانونِ پ در دوره (اولِ ماه تا تاریخِ گزارش):
 * حقوقِ روزانه = حقوقِ ماهانه ÷ ۲۴ ؛ هدفِ روزانه = مجموعِ حقوقِ روزانه‌ی تیم × ۱۰ ؛ هدفِ دوره = هدفِ روزانه × روزهای دوره (بدونِ جمعه)
 */
function sd_p_rule(PDO $pdo, array $ids, array $days, int $actual, int $leaderId = 0): array
{
    $sal = sd_salaries($pdo, $ids, $leaderId);
    $monthly = array_sum(array_column($sal, 'amount'));
    $work = count(array_filter($days, static fn($d) => (int) date('N', strtotime($d)) !== 5));
    $dailySalary = $monthly / 24;
    $dailyTarget = $dailySalary * 10;
    $target = (int) round($dailyTarget * max(1, $work));
    return ['ok' => $actual >= $target, 'target' => $target, 'actual' => $actual, 'monthly' => $monthly, 'daily_salary' => (int) round($dailySalary),
        'daily_target' => (int) round($dailyTarget), 'work_days' => $work, 'salaries' => $sal,
        'assumed' => count(array_filter($sal, static fn($s) => $s['type_assumed']))];
}

/**
 * مقدارِ شاخص‌های تیم برای هر روز: [metric_id => [day => n]] — عددِ ثبت‌شده بر شمارشِ خودکار مقدم است.
 * $auto: فقط مقدارِ خودکار؛ $manual: فقط عددِ ثبت‌شده (برای فرمِ ثبت).
 */
function sd_metric_series(PDO $pdo, int $teamId, array $ids, array $days, array $metrics, ?array &$auto = null, ?array &$manual = null): array
{
    $out = [];
    $auto = [];
    $manual = [];
    if (!$days || !$metrics) return $out;
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
    foreach ($metrics as $t) {
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
    $st = $pdo->prepare('SELECT metric_id, report_date, value FROM team_metric_values WHERE team_id = ? AND report_date BETWEEN ? AND ?');
    $st->execute([$teamId, $from, $to]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) $manual[(int) $r['metric_id']][$r['report_date']] = (float) $r['value'];
    foreach ($metrics as $t) {
        $mid = (int) $t['id'];
        foreach ($days as $d) {
            $a = (float) ($bySource[(string) $t['source']][$d] ?? 0);
            $auto[$mid][$d] = $a;
            $out[$mid][$d] = $manual[$mid][$d] ?? $a;
        }
    }
    return $out;
}

/** همه‌ی داده‌ی یک صفحه‌ی گزارش برای یک سرپرست و یک تاریخِ گزارش */
function sd_build(PDO $pdo, array $leader, string $reportDate): array
{
    $teamId = (int) $leader['team_id'];
    $lid = (int) $leader['id'];
    $days = sd_month_days($reportDate);
    $ids = sd_team_ids($pdo, $teamId, $lid);
    $metrics = sd_metrics($pdo, $teamId);
    $hc = sd_headcount_series($pdo, $teamId, $lid, $days);
    $sales = sd_sales_series($pdo, $ids, $days);
    $mv = sd_metric_series($pdo, $teamId, $ids, $days, $metrics, $auto, $manual);
    $mtd = array_sum(array_column($sales, 'total_amt'));
    return [
        'leader' => $leader, 'day' => $reportDate, 'days' => $days, 'ids' => $ids, 'metrics' => $metrics,
        'hc' => $hc, 'hc_day' => $hc[$reportDate], 'staff_rule' => sd_staff_rule($hc[$reportDate]),
        'sales' => $sales, 'sales_day' => $sales[$reportDate], 'p_rule' => sd_p_rule($pdo, $ids, $days, $mtd, $lid),
        'mv' => $mv, 'mv_auto' => $auto, 'mv_manual' => $manual,
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

/** عددِ شاخص (اعشار فقط وقتی لازم است) */
function sd_num($v): string
{
    $v = (float) $v;
    return to_persian_digits(abs($v - round($v)) < 0.005 ? number_format((int) round($v)) : str_replace('.', '٫', rtrim(rtrim(number_format($v, 2, '.', ','), '0'), '.')));
}

/** نمودارِ کوچک (small multiple) برای یک شاخص: خط + نقطه‌ی آخر (مقدارِ آخر کنارِ نام، بیرونِ SVG) */
function sd_spark(array $labels, array $values, string $color, int $w = 200, int $h = 46): string
{
    $n = count($values);
    $vals = array_map('floatval', array_values($values));
    $max = max(1.0, $vals ? max($vals) : 0);
    $padL = 3; $padR = 3; $padT = 4; $padB = 4;
    $x = static fn(int $i): float => $padL + ($n <= 1 ? ($w - $padL - $padR) / 2 : ($w - $padL - $padR) * $i / ($n - 1));
    $y = static fn(float $v): float => $padT + ($h - $padT - $padB) * (1 - $v / $max);
    $pts = [];
    foreach ($vals as $i => $v) $pts[] = round($x($i), 1) . ',' . round($y($v), 1);
    $last = $n ? $vals[$n - 1] : 0;
    $svg = '<svg class="sdr-spark" viewBox="0 0 ' . $w . ' ' . $h . '" width="100%" preserveAspectRatio="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">'
        . '<line x1="' . $padL . '" x2="' . ($w - $padR) . '" y1="' . ($h - $padB) . '" y2="' . ($h - $padB) . '" stroke="#d6d5cf" stroke-width="0.8"/>';
    if ($n > 1) $svg .= '<polyline fill="none" stroke="' . $color . '" stroke-width="1.8" stroke-linejoin="round" stroke-linecap="round" points="' . implode(' ', $pts) . '"/>';
    if ($n) $svg .= '<circle cx="' . round($x($n - 1), 1) . '" cy="' . round($y($last), 1) . '" r="2.6" fill="' . $color . '" stroke="#fff" stroke-width="1"/>';
    return $svg . '</svg>';
}

/** یک صفحه‌ی A4 (HTML) — سربرگ فقط: نامِ سرپرست، نامِ تیم، تاریخ */
function sd_render_sheet(array $R): string
{
    $L = $R['leader'];
    $pal = sd_palette();
    $labels = array_map('sd_day_label', $R['days']);
    $num = static fn(int $n): string => to_persian_digits(number_format($n));
    $row = static fn(string $k, string $v, string $u = '', string $cls = ''): string => '<div class="sdr-kv ' . $cls . '"><span class="k">' . e($k) . '</span><span class="v">' . $v . ($u !== '' ? ' <small>' . e($u) . '</small>' : '') . '</span></div>';
    $mark = static fn(?bool $ok): string => $ok === null ? '<b class="na">—</b>' : ($ok ? '<b class="ok">✓</b>' : '<b class="no">✕</b>');

    // A1 / A2 — نیروی انسانی
    $hc = $R['hc_day'];
    $a1 = '<h3>وضعیت نیروی انسانی</h3>' . $row('توسعه', $num($hc['dev']), 'نفر') . $row('عملیات', $num($hc['ops']), 'نفر') . $row('ستادی', $num($hc['staff']), 'نفر')
        . ($hc['unknown'] > 0 ? $row('نامشخص', $num($hc['unknown']), 'نفر', 'muted') : '')
        . $row('کل نیروها', $num($hc['total']), 'نفر', 'sum') . '<div class="sdr-rule">قانون تعداد ' . $mark($R['staff_rule']['ok']) . '</div>';
    $hcSeries = [
        ['name' => 'توسعه', 'color' => $pal[0], 'values' => array_column($R['hc'], 'dev')],
        ['name' => 'عملیات', 'color' => $pal[1], 'values' => array_column($R['hc'], 'ops')],
        ['name' => 'ستادی', 'color' => $pal[2], 'values' => array_column($R['hc'], 'staff')],
    ];
    $a2 = '<h3>روند نیروی انسانی</h3>' . sd_legend($hcSeries) . sd_line_chart($labels, $hcSeries, 'نفر', 430, 180);

    // B1 / B2 — عملکردِ تجاری
    $s = $R['sales_day'];
    $b1 = '<h3>عملکرد تجاری</h3>' . $row('لید', $num($s['leads']), 'نفر') . $row('مذاکره', $num($s['nego']), 'نفر')
        . $row('پ ج', sd_million($s['new_amt']), 'میلیون') . $row('جدید', $num($s['new_cnt']), 'نفر')
        . $row('پ ق', sd_million($s['old_amt']), 'میلیون') . $row('قدیم', $num($s['old_cnt']), 'نفر')
        . $row('پ کل', sd_million($s['total_amt']), 'میلیون', 'sum') . '<div class="sdr-rule">قانون پ ' . $mark($R['p_rule']['ok']) . '</div>';
    $mil = static fn(array $v): array => array_map(static fn($x) => $x / 1000000, $v);
    $pSeries = [
        ['name' => 'پ ج', 'color' => $pal[0], 'values' => $mil(array_column($R['sales'], 'new_amt'))],
        ['name' => 'پ ق', 'color' => $pal[1], 'values' => $mil(array_column($R['sales'], 'old_amt'))],
        ['name' => 'پ کل', 'color' => $pal[2], 'values' => $mil(array_column($R['sales'], 'total_amt'))],
    ];
    $cSeries = [
        ['name' => 'لید', 'color' => $pal[3], 'values' => array_column($R['sales'], 'leads')],
        ['name' => 'مذاکره', 'color' => $pal[0], 'values' => array_column($R['sales'], 'nego')],
        ['name' => 'جدید', 'color' => $pal[1], 'values' => array_column($R['sales'], 'new_cnt')],
        ['name' => 'قدیم', 'color' => $pal[2], 'values' => array_column($R['sales'], 'old_cnt')],
    ];
    $b2 = '<h3>روند عملکرد تجاری</h3><div class="sdr-two"><div>' . sd_legend($pSeries) . sd_line_chart($labels, $pSeries, 'میلیون', 430, 140)
        . '</div><div>' . sd_legend($cSeries) . sd_line_chart($labels, $cSeries, 'نفر', 430, 140) . '</div></div>';

    // C1 / C2 — شاخص‌های اختصاصیِ تیم (از تنظیماتِ همان تیم)
    $metrics = $R['metrics'];
    $cnt = count($metrics);
    if (!$cnt) {
        $c1 = '<h3>شاخص‌های اختصاصی تیم</h3><div class="sdr-empty">برای این تیم هنوز شاخصی تعریف نشده است.</div>';
        $c2 = '<h3>روند شاخص‌های اختصاصی تیم</h3><div class="sdr-empty">—</div>';
    } else {
        $dense = $cnt > 9 ? ' sdr-dense' : '';
        $c1 = '<h3>شاخص‌های اختصاصی تیم</h3><div class="sdr-list' . $dense . '">';
        foreach ($metrics as $m) $c1 .= $row((string) $m['metric_name'], sd_num($R['mv'][(int) $m['id']][$R['day']] ?? 0));
        $c1 .= '</div>';
        if ($cnt <= 4) {
            $mSeries = [];
            foreach ($metrics as $i => $m) $mSeries[] = ['name' => (string) $m['metric_name'], 'color' => $pal[$i % count($pal)], 'values' => array_values($R['mv'][(int) $m['id']] ?? [])];
            $c2 = '<h3>روند شاخص‌های اختصاصی تیم</h3>' . sd_legend($mSeries) . sd_line_chart($labels, $mSeries, 'مورد', 430, 190, false);
        } else {
            // بیش از ۴ شاخص: هر شاخص نمودارِ کوچکِ خودش (خوانا، بدونِ تکیه بر رنگ)
            $c2 = '<h3>روند شاخص‌های اختصاصی تیم <small>(' . e(reset($labels) . ' تا ' . end($labels)) . ')</small></h3><div class="sdr-multi' . ($cnt > 9 ? ' c3' : '') . '">';
            foreach ($metrics as $m) {
                $vals = $R['mv'][(int) $m['id']] ?? [];
                $c2 .= '<div class="sdr-mini"><div class="nm"><span>' . e((string) $m['metric_name']) . '</span><b>' . sd_num($vals ? end($vals) : 0) . '</b></div>'
                    . sd_spark($labels, $vals, $pal[0]) . '</div>';
            }
            $c2 .= '</div>';
        }
    }

    return '<section class="sdr-sheet">'
        . '<header class="sdr-head"><div class="n">' . e((string) $L['full_name']) . '</div><div class="t">' . e(team_display_name($L['team_name'] ?? null, (int) $L['team_id'])) . '</div><div class="d">' . e(to_persian_digits(to_jalali($R['day']))) . '</div></header>'
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
.sdr-empty{font-size:11.5pt;color:#8a8984;padding-top:2mm}
.sdr-dense .sdr-kv{font-size:10.5pt;line-height:1.42}
.sdr-sheet h3 small{font-size:9pt;font-weight:400;color:#8a8984}
.sdr-multi{display:grid;grid-template-columns:1fr 1fr;gap:1.2mm 5mm}
.sdr-multi.c3{grid-template-columns:1fr 1fr 1fr;gap:1mm 4mm}
.sdr-mini{min-width:0}
.sdr-mini .nm{display:flex;justify-content:space-between;gap:2mm;font-size:9pt;color:#3a3936;line-height:1.3}
.sdr-mini .nm span{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;min-width:0}
.sdr-mini .nm b{font-variant-numeric:tabular-nums;color:#1d1c1a}
.sdr-spark{display:block;height:9mm}
.sdr-multi.c3 .sdr-spark{height:7.5mm}
CSS;
}
