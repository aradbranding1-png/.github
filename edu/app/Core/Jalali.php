<?php
declare(strict_types=1);

namespace App\Core;

/** Gregorian <-> Jalali (Solar Hijri) conversion. */
final class Jalali
{
    public const MONTHS = [1 => 'فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];
    public const WEEKDAYS = ['یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنجشنبه', 'جمعه', 'شنبه'];

    public static function toJalali(int $gy, int $gm, int $gd): array
    {
        $g_d_m = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
        $gy2 = ($gm > 2) ? ($gy + 1) : $gy;
        $days = 355666 + (365 * $gy) + intdiv($gy2 + 3, 4) - intdiv($gy2 + 99, 100) + intdiv($gy2 + 399, 400) + $gd + $g_d_m[$gm - 1];
        $jy = -1595 + (33 * intdiv($days, 12053));
        $days %= 12053;
        $jy += 4 * intdiv($days, 1461);
        $days %= 1461;
        if ($days > 365) {
            $jy += intdiv($days - 1, 365);
            $days = ($days - 1) % 365;
        }
        if ($days < 186) {
            $jm = 1 + intdiv($days, 31);
            $jd = 1 + ($days % 31);
        } else {
            $jm = 7 + intdiv($days - 186, 30);
            $jd = 1 + (($days - 186) % 30);
        }
        return [$jy, $jm, $jd];
    }

    public static function toGregorian(int $jy, int $jm, int $jd): array
    {
        $jy += 1595;
        $days = -355668 + (365 * $jy) + (intdiv($jy, 33) * 8) + intdiv(($jy % 33) + 3, 4) + $jd + (($jm < 7) ? ($jm - 1) * 31 : (($jm - 7) * 30) + 186);
        $gy = 400 * intdiv($days, 146097);
        $days %= 146097;
        if ($days > 36524) {
            $gy += 100 * intdiv(--$days, 36524);
            $days %= 36524;
            if ($days >= 365) $days++;
        }
        $gy += 4 * intdiv($days, 1461);
        $days %= 1461;
        if ($days > 365) {
            $gy += intdiv($days - 1, 365);
            $days = ($days - 1) % 365;
        }
        $gd = $days + 1;
        $sal_a = [0, 31, (($gy % 4 == 0 && $gy % 100 != 0) || ($gy % 400 == 0)) ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
        for ($gm = 0; $gm < 13 && $gd > $sal_a[$gm]; $gm++) $gd -= $sal_a[$gm];
        return [$gy, $gm, $gd];
    }

    public static function monthLength(int $jy, int $jm): int
    {
        if ($jm <= 6) return 31;
        if ($jm <= 11) return 30;
        [$gy, $gm, $gd] = self::toGregorian($jy + 1, 1, 1);
        $last = strtotime(sprintf('%04d-%02d-%02d', $gy, $gm, $gd)) - 86400;
        [, , $d] = self::toJalali((int)date('Y', $last), (int)date('n', $last), (int)date('j', $last));
        return $d;
    }

    public static function format(string $fmt, int $ts): string
    {
        [$jy, $jm, $jd] = self::toJalali((int)date('Y', $ts), (int)date('n', $ts), (int)date('j', $ts));
        $out = '';
        $len = strlen($fmt);
        for ($i = 0; $i < $len; $i++) {
            $c = $fmt[$i];
            $out .= match ($c) {
                'Y' => (string)$jy,
                'y' => substr((string)$jy, 2),
                'm' => sprintf('%02d', $jm),
                'n' => (string)$jm,
                'd' => sprintf('%02d', $jd),
                'j' => (string)$jd,
                'F' => self::MONTHS[$jm],
                'l' => self::WEEKDAYS[(int)date('w', $ts)],
                'H', 'i', 's', 'G' => date($c, $ts),
                default => $c,
            };
        }
        return $out;
    }

    /** Parse a Jalali date string "1403/05/12" (optionally with time) into Y-m-d[ H:i:s] gregorian. */
    public static function parse(?string $s, bool $withTime = false): ?string
    {
        $s = normalize_input((string)$s);
        if ($s === '') return null;
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $s)) return $withTime ? date('Y-m-d H:i:s', strtotime($s)) : substr($s, 0, 10);
        if (!preg_match('~^(\d{4})[/\-.](\d{1,2})[/\-.](\d{1,2})(?:\s+(\d{1,2}):(\d{2}))?~', $s, $m)) return null;
        [$gy, $gm, $gd] = self::toGregorian((int)$m[1], (int)$m[2], (int)$m[3]);
        $d = sprintf('%04d-%02d-%02d', $gy, $gm, $gd);
        if ($withTime) $d .= sprintf(' %02d:%02d:00', (int)($m[4] ?? 0), (int)($m[5] ?? 0));
        return $d;
    }

    public static function input(?string $gregorian): string
    {
        if (!$gregorian || str_starts_with($gregorian, '0000')) return '';
        return self::format('Y/m/d', strtotime($gregorian));
    }
}
