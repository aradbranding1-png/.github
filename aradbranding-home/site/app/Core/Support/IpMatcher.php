<?php

declare(strict_types=1);

namespace App\Core\Support;

/**
 * Matches an IP against a list of single IPs and CIDR ranges (IPv4 and IPv6).
 * Used for TRUSTED_PROXIES (CDN / load balancer ranges are published as CIDRs)
 * and OPS_ALLOWED_IPS.
 */
final class IpMatcher
{
    /** @param string|list<string> $list comma-separated string or list, e.g. "10.0.0.5, 173.245.48.0/20, 2400:cb00::/32" */
    public static function matches(string $ip, string|array $list): bool
    {
        $bin = @inet_pton($ip);
        if ($bin === false) {
            return false;
        }
        $items = is_array($list) ? $list : explode(',', $list);
        foreach ($items as $item) {
            $item = trim((string) $item);
            if ($item === '') {
                continue;
            }
            if (!str_contains($item, '/')) {
                $other = @inet_pton($item);
                if ($other !== false && $other === $bin) {
                    return true;
                }
                continue;
            }
            [$net, $bits] = explode('/', $item, 2);
            $netBin = @inet_pton(trim($net));
            if ($netBin === false || strlen($netBin) !== strlen($bin) || !ctype_digit(trim($bits))) {
                continue;
            }
            $bits = (int) $bits;
            if ($bits > strlen($bin) * 8) {
                continue;
            }
            if (self::prefixEquals($bin, $netBin, $bits)) {
                return true;
            }
        }
        return false;
    }

    private static function prefixEquals(string $a, string $b, int $bits): bool
    {
        $bytes = intdiv($bits, 8);
        if ($bytes > 0 && substr($a, 0, $bytes) !== substr($b, 0, $bytes)) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;
        return (ord($a[$bytes]) & $mask) === (ord($b[$bytes]) & $mask);
    }
}
