<?php

declare(strict_types=1);

namespace App\Core\Support;

use InvalidArgumentException;

/**
 * Monotonic ULID: 48-bit millisecond timestamp + 80 random bits.
 * Stored as BINARY(16), exposed as 26-char Crockford Base32. Sortable by time.
 */
final class Ulid
{
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    private static int $lastMs = 0;
    private static string $lastRandom = '';

    /** @return string 16 raw bytes */
    public static function generateBinary(): string
    {
        $ms = (int) floor(microtime(true) * 1000);
        if ($ms === self::$lastMs && self::$lastRandom !== '') {
            $random = self::increment(self::$lastRandom);
            if ($random === null) {
                usleep(1000);
                return self::generateBinary();
            }
        } else {
            $random = random_bytes(10);
        }
        self::$lastMs = $ms;
        self::$lastRandom = $random;

        $time = '';
        for ($i = 5; $i >= 0; $i--) {
            $time .= chr(($ms >> ($i * 8)) & 0xFF);
        }
        return $time . $random;
    }

    public static function generate(): string
    {
        return self::toString(self::generateBinary());
    }

    public static function toString(string $binary): string
    {
        if (strlen($binary) !== 16) {
            throw new InvalidArgumentException('ULID binary must be 16 bytes');
        }
        $bits = '00';
        foreach (str_split($binary) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        for ($i = 0; $i < 26; $i++) {
            $out .= self::ALPHABET[bindec(substr($bits, $i * 5, 5))];
        }
        return $out;
    }

    public static function toBinary(string $ulid): string
    {
        if (!self::isValid($ulid)) {
            throw new InvalidArgumentException('Invalid ULID');
        }
        $bits = '';
        foreach (str_split(strtoupper($ulid)) as $char) {
            $bits .= str_pad(decbin(strpos(self::ALPHABET, $char)), 5, '0', STR_PAD_LEFT);
        }
        $bits = substr($bits, 2);
        $bin = '';
        foreach (str_split($bits, 8) as $chunk) {
            $bin .= chr(bindec($chunk));
        }
        return $bin;
    }

    public static function isValid(string $ulid): bool
    {
        return (bool) preg_match('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/i', $ulid);
    }

    public static function timestampMs(string $ulid): int
    {
        $bin = self::toBinary($ulid);
        $ms = 0;
        for ($i = 0; $i < 6; $i++) {
            $ms = ($ms << 8) | ord($bin[$i]);
        }
        return $ms;
    }

    private static function increment(string $bytes): ?string
    {
        for ($i = strlen($bytes) - 1; $i >= 0; $i--) {
            $value = ord($bytes[$i]);
            if ($value < 0xFF) {
                $bytes[$i] = chr($value + 1);
                return $bytes;
            }
            $bytes[$i] = "\x00";
        }
        return null;
    }
}
