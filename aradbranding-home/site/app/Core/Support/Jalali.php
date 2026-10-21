<?php

declare(strict_types=1);

namespace App\Core\Support;

/**
 * Gregorian → Persian (Jalali) calendar, pure PHP (no intl needed). Used by the admin reports, whose days are
 * Tehran calendar days ('Y-m-d' strings).
 */
final class Jalali
{
    public const MONTHS = [1 => 'فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];
    /** By PHP's date('w'): 0 = Sunday. */
    public const WEEKDAYS = ['یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنجشنبه', 'جمعه', 'شنبه'];

    /** @return array{0: int, 1: int, 2: int} [year, month, day] */
    public static function fromGregorian(int $gy, int $gm, int $gd): array
    {
        $sum = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
        $gy2 = $gm > 2 ? $gy + 1 : $gy;
        $days = 355666 + 365 * $gy + intdiv($gy2 + 3, 4) - intdiv($gy2 + 99, 100) + intdiv($gy2 + 399, 400) + $gd + $sum[$gm - 1];
        $jy = -1595 + 33 * intdiv($days, 12053);
        $days %= 12053;
        $jy += 4 * intdiv($days, 1461);
        $days %= 1461;
        if ($days > 365) {
            $jy += intdiv($days - 1, 365);
            $days = ($days - 1) % 365;
        }
        return $days < 186
            ? [$jy, 1 + intdiv($days, 31), 1 + $days % 31]
            : [$jy, 7 + intdiv($days - 186, 30), 1 + ($days - 186) % 30];
    }

    /** @return array{0: int, 1: int, 2: int} for a 'Y-m-d' date */
    public static function ofDate(string $ymd): array
    {
        [$y, $m, $d] = array_map('intval', explode('-', substr($ymd, 0, 10)));
        return self::fromGregorian($y, $m, $d);
    }

    /** «۲۹ مهر» */
    public static function dayMonth(string $ymd): string
    {
        [, $m, $d] = self::ofDate($ymd);
        return fa_num((string) $d) . ' ' . self::MONTHS[$m];
    }

    /** «چهارشنبه ۲۹ مهر ۱۴۰۵» */
    public static function full(string $ymd): string
    {
        [$y, $m, $d] = self::ofDate($ymd);
        return self::WEEKDAYS[(int) date('w', strtotime(substr($ymd, 0, 10) . ' 12:00:00 UTC'))] . ' ' . fa_num((string) $d) . ' ' . self::MONTHS[$m] . ' ' . fa_num((string) $y);
    }
}
