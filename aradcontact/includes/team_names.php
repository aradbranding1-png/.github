<?php
/**
 * نامِ تیم‌ها (Config) — در گزارش‌ها به‌جای «تیم ۱۰۱» نامِ تیم نمایش داده می‌شود: «تیم آموزش و اطلاعات».
 *   - برای تغییرِ نام یا اضافه‌کردنِ تیمِ جدید همین آرایه را ویرایش کنید (یا از «مدیریتِ تیم‌ها» نامِ تیم را عوض کنید؛
 *     نامِ ثبت‌شده در جدولِ teams بر این فهرست مقدم است).
 *   - یک‌بار این فهرست در جدولِ teams نوشته می‌شود تا همه‌ی صفحه‌ها یک نام نشان دهند.
 */
const TEAM_NAMES = [
    101 => 'آموزش و اطلاعات',
    102 => 'پروژه‌های تجاری کلان',
    103 => 'توسعه فروش',
    104 => 'عملیات بازرگانی',
    105 => 'مشاوره و پشتیبانی',
    106 => 'رویدادهای تجاری',
    107 => 'راهبری و مذاکره',
    108 => 'عملکرد',
    109 => 'شبکه نمایندگان',
    110 => 'فرصت‌ها و ارتباطات',
    112 => 'توسعه برندسازی',
    115 => 'سئو و بازاریابی دیجیتال',
    117 => 'تیم استخدام',
    118 => 'قرارداد و مالی',
    119 => 'برندسازی بصری',
];

/** نامِ تیم (بدونِ پیشوندِ «تیم»): نامِ جدولِ teams، وگرنه همین فهرست؛ '' = نامی تعریف نشده */
function team_config_name(int $id, ?string $dbName = null): string
{
    $n = trim((string) $dbName);
    if ($n === '') $n = TEAM_NAMES[$id] ?? '';
    return preg_replace('/^تیم\s+/u', '', $n);
}

/** یک‌بار: نوشتنِ فهرستِ بالا در جدولِ teams (برای تیم‌هایی که در جدول هستند) */
function team_names_sync_v1(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $flag = __DIR__ . '/../storage/.team_names_sync_v1';
    if (is_file($flag)) return;
    try {
        $st = $pdo->prepare('UPDATE teams SET name = ? WHERE id = ?');
        foreach (TEAM_NAMES as $id => $name) $st->execute([$name, $id]);
        @file_put_contents($flag, date('c'));
    } catch (Throwable $e) {
        error_log('team_names_sync_v1: ' . $e->getMessage());
    }
}
