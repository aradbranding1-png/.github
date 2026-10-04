<?php

declare(strict_types=1);

namespace App\Core\Support;

final class Str
{
    /** Normalise Persian/Arabic text for search and comparison. */
    public static function normalize(string $text): string
    {
        $text = strtr($text, [
            'ي' => 'ی', 'ى' => 'ی', 'ك' => 'ک', 'ة' => 'ه', 'ۀ' => 'ه', 'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا',
            "\u{200C}" => ' ', "\u{200F}" => '', "\u{200E}" => '',
        ]);
        $text = self::latinDigits($text);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        return mb_strtolower(trim($text));
    }

    /**
     * An e-mail address as typed on a phone: Persian keyboards can slip in invisible marks (zero-width non-joiner,
     * left-to-right / right-to-left marks), a no-break space, Persian digits or a full-width @. None of them can be
     * part of an address, so they are taken out before it is checked or stored.
     */
    public static function cleanEmail(string $text): string
    {
        $text = strtr(self::latinDigits($text), ['＠' => '@', '．' => '.']);
        return preg_replace('/[\p{Cf}\p{Z}\s]+/u', '', $text) ?? trim($text);
    }

    /** Persian and Arabic-Indic digits → 0-9 (phone numbers, codes). */
    public static function latinDigits(string $text): string
    {
        return strtr($text, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
    }

    /** Safe local redirect target: must start with a single "/". */
    public static function safeNext(?string $next, string $fallback = '/dashboard'): string
    {
        if ($next === null || $next === '' || !str_starts_with($next, '/') || str_starts_with($next, '//') || str_contains($next, '\\')) {
            return $fallback;
        }
        return $next;
    }

    public static function excerpt(string $text, int $length): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        return mb_strlen($text) <= $length ? $text : rtrim(mb_substr($text, 0, $length - 1)) . '…';
    }
}
