<?php
/**
 * ایندکس‌های سرعت برای حجمِ بالا (۳ هزار کارشناس، ۳ میلیون مشتری).
 *
 *   - customers.cl_sort: ستونِ مجازیِ «ترتیبِ صفحه‌ی پیگیری مشتریان»
 *     (اولویتِ وضعیت ← تاریخِ پیگیریِ بعدی، خالی‌ها آخر ← جدیدترها اول). چون ایندکس دارد،
 *     صفحه‌ی اولِ فهرست بدونِ مرتب‌کردنِ کلِ مشتری‌ها خوانده می‌شود.
 *   - ایندکس‌های پوشا برای شمارش‌های داشبورد و فهرست (بدونِ خواندنِ خودِ ردیف‌ها).
 *   - ایندکسِ تماس‌های روزانه برای «برترین تماس‌گیرنده‌های دیروز».
 *
 * ساختِ ایندکس روی جدولِ بزرگ چند دقیقه طول می‌کشد؛ برای همین یک‌بار و بعد از فرستادنِ صفحه
 * (پشتِ صحنه و بدونِ قفل‌کردنِ جدول) ساخته می‌شود. تا آماده شدن، صفحه‌ها با روشِ قبلی کار می‌کنند.
 * همین دستورها در docs/PERF_INDEXES.sql هم هست تا در صورتِ نیاز از phpMyAdmin اجرا شوند.
 *
 * اگر ترتیبِ وضعیت‌ها (CL_STATUS_PRIORITY) عوض شد، نسخه‌ی PERF_INDEXES_VERSION را بالا ببرید.
 */

require_once __DIR__ . '/perf_cache.php';

const CL_STATUS_PRIORITY = ['در انتظار پرداخت', 'در انتظار تصمیم', 'جلسه برگزار شد', 'در حال پیگیری', 'تعویق', 'جدید', 'عدم پاسخ', 'مشتری قدیمی', 'خرید کرده'];
const PERF_INDEXES_VERSION = 1;

/** عبارتِ ستونِ مرتب‌سازی (هم‌ارزِ ORDER BY وضعیت، تاریخِ پیگیری با خالی‌ها در آخر، created_at نزولی) */
function cl_sort_expression(PDO $pdo): string
{
    $case = 'CASE `status`';
    foreach (CL_STATUS_PRIORITY as $i => $st) $case .= ' WHEN ' . $pdo->quote($st) . ' THEN ' . ($i + 1);
    $case .= ' ELSE 10 END';
    return "(($case) * 100000000000000000"
        . " + LEAST(GREATEST(IFNULL(TO_DAYS(`next_followup_date`) - 693961, 999999), 0), 999999) * 100000000000"
        . " + (99999999999 - TO_SECONDS(`created_at`)))";
}

function perf_indexes_flag(): string
{
    return __DIR__ . '/../storage/.perf_indexes_v' . PERF_INDEXES_VERSION;
}

/** true = ستون و ایندکس‌ها آماده‌اند. اگر نه، ساختشان پشتِ صحنه شروع می‌شود. */
function perf_indexes_ready(PDO $pdo): bool
{
    static $ready = null;
    if ($ready !== null) return $ready;
    if (is_file(perf_indexes_flag())) return $ready = true;
    $ready = false;
    $lockFile = __DIR__ . '/../storage/.perf_indexes.lock';
    // اگر ساخت در جریان است یا کمتر از ۱۰ دقیقه پیش ناموفق بوده، دوباره شروع نکن
    if (is_file($lockFile) && time() - (int) @filemtime($lockFile) < 600) return false;
    app_defer(static function () use ($pdo, $lockFile) {
        $fh = @fopen($lockFile, 'c');
        if (!$fh || !flock($fh, LOCK_EX | LOCK_NB)) return;
        @touch($lockFile);
        try {
            @set_time_limit(0);
            if (perf_indexes_build($pdo)) @file_put_contents(perf_indexes_flag(), date('c'));
        } catch (Throwable $e) {
            error_log('perf_indexes: ' . $e->getMessage());
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    });
    return false;
}

function perf_has_column(PDO $pdo, string $table, string $col): bool
{
    $st = $pdo->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $st->execute([$table, $col]);
    return (bool) $st->fetchColumn();
}

function perf_has_index(PDO $pdo, string $table, string $name): bool
{
    $st = $pdo->prepare('SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1');
    $st->execute([$table, $name]);
    return (bool) $st->fetchColumn();
}

/** دستورِ ALTER را اول بدونِ قفلِ جدول، و اگر سرور پشتیبانی نکرد، به روشِ معمولی اجرا می‌کند */
function perf_alter_online(PDO $pdo, string $sql): void
{
    try {
        $pdo->exec($sql . ', ALGORITHM=INPLACE, LOCK=NONE');
    } catch (Throwable $e) {
        $pdo->exec($sql);
    }
}

function perf_indexes_build(PDO $pdo): bool
{
    @$pdo->exec('SET SESSION lock_wait_timeout = 30');
    if (!perf_has_column($pdo, 'customers', 'cl_sort')) {
        $col = 'ALTER TABLE customers ADD COLUMN cl_sort BIGINT AS ' . cl_sort_expression($pdo) . ' VIRTUAL';
        try {
            // INVISIBLE: در SELECT * و خروجی‌ها دیده نمی‌شود
            $pdo->exec($col . ' INVISIBLE');
        } catch (Throwable $e) {
            $pdo->exec($col);
        }
    }
    $indexes = [
        ['customers', 'idx_cust_owner_scope', '(owner_user_id, contact_type, status, next_followup_date, cl_sort)'],
        ['customers', 'idx_cust_type_sort', '(contact_type, cl_sort, status, owner_user_id, next_followup_date)'],
        ['customers', 'idx_cust_type_status', '(contact_type, status, next_followup_date, created_at)'],
        ['followups', 'idx_followups_day_calls', '(followup_date, source, call_duration_seconds, created_by, customer_id)'],
    ];
    foreach ($indexes as [$table, $name, $cols]) {
        if (!perf_has_index($pdo, $table, $name)) perf_alter_online($pdo, "ALTER TABLE `$table` ADD INDEX `$name` $cols");
    }
    return true;
}
