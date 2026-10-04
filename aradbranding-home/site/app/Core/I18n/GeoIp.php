<?php

declare(strict_types=1);

namespace App\Core\I18n;

/**
 * Offline IP → country lookup used only to pick a first language for new visitors.
 *
 * data/ipv4.bin: sorted 6-byte records (uint32 big-endian range start + 2-letter country, "--" = unassigned).
 * data/ipv6.bin: sorted 10-byte records (top 64 bits of the range start, big-endian + country).
 * A binary search with fseek reads about 20 records, so the files are never loaded into memory.
 * Data: geo-whois-asn-country (ip-location-db), CC BY 4.0, attribution to the Number Resource Organization (https://www.nro.net).
 */
final class GeoIp
{
    private const DIR = __DIR__ . '/data/';

    public static function country(string $ip): ?string
    {
        $bin = @inet_pton($ip);
        if ($bin === false) {
            return null;
        }
        if (strlen($bin) === 16 && str_starts_with($bin, str_repeat("\0", 10) . "\xff\xff")) {
            $bin = substr($bin, 12); // IPv4-mapped IPv6
        }
        if (strlen($bin) === 4) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) && strlen($ip) <= 15) {
                return null;
            }
            return self::search(self::DIR . 'ipv4.bin', 6, $bin);
        }
        return self::search(self::DIR . 'ipv6.bin', 10, substr($bin, 0, 8));
    }

    /** Last record whose start is ≤ key (keys compare as big-endian byte strings). */
    private static function search(string $file, int $size, string $key): ?string
    {
        $fh = @fopen($file, 'rb');
        if ($fh === false) {
            return null;
        }
        $n = intdiv((int) fstat($fh)['size'], $size);
        $klen = $size - 2;
        $lo = 0;
        $hi = $n - 1;
        $found = -1;
        while ($lo <= $hi) {
            $mid = ($lo + $hi) >> 1;
            fseek($fh, $mid * $size);
            $start = (string) fread($fh, $klen);
            if (strcmp($start, $key) <= 0) {
                $found = $mid;
                $lo = $mid + 1;
            } else {
                $hi = $mid - 1;
            }
        }
        $code = null;
        if ($found >= 0) {
            fseek($fh, $found * $size + $klen);
            $c = (string) fread($fh, 2);
            $code = preg_match('/^[A-Z]{2}$/', $c) ? $c : null;
        }
        fclose($fh);
        return $code;
    }
}
