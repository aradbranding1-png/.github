<?php

declare(strict_types=1);

namespace App\Core\Security;

use App\Core\Support\Str;

/**
 * Keeps off-platform contact details out of text other traders can browse (business pages,
 * proposals, profile text): phone numbers, e-mail addresses, web addresses and messenger /
 * social IDs. Private letters are not filtered: that is where traders exchange contacts.
 *
 * Used twice: on save (reject with a clear message) and on render (mask anything that
 * slipped through or was saved before this rule existed).
 */
final class ContactGuard
{
    private const TLDS = 'com|net|org|ir|app|info|co|io|me|biz|shop|store|online|site|xyz|tr|ae|de|ru|cn|uk|us|eu|in|pk|af|iq|qa|om|kw|sa|az|am|ge|kz|uz|tj|tm';
    private const MESSENGERS = 'telegram|whats\s?app|instagram|insta|signal|wechat|viber|skype|imo|line|تلگرام|واتس\s?اپ|واتساپ|اینستا(?:گرام)?|ایتا|بله|روبیکا|سروش|ایمو|وایبر|اسکایپ|آیدی|ایدی';

    /** @return list<string> kinds found: phone, email, url, handle */
    public static function find(string $text): array
    {
        $t = self::prepare($text);
        $found = [];
        foreach (self::patterns() as $kind => $pattern) {
            if ($kind === 'phone') {
                if (preg_match_all($pattern, $t, $m)) {
                    foreach ($m[0] as $candidate) {
                        if (strlen((string) preg_replace('/\D/', '', $candidate)) >= 7) {
                            $found[] = 'phone';
                            break;
                        }
                    }
                }
            } elseif (preg_match($pattern, $t)) {
                $found[] = $kind;
            }
        }
        return array_values(array_unique($found));
    }

    public static function contains(string $text): bool
    {
        return self::find($text) !== [];
    }

    /** Replaces contact details with "•••". Digit-normalised text is returned for masked parts only. */
    public static function mask(string $text): string
    {
        if (!self::contains($text)) {
            return $text;
        }
        $t = self::prepare($text);
        foreach (self::patterns() as $kind => $pattern) {
            $t = (string) preg_replace_callback($pattern, static function (array $m) use ($kind): string {
                if ($kind === 'phone' && strlen((string) preg_replace('/\D/', '', $m[0])) < 7) {
                    return $m[0];
                }
                return '•••';
            }, $t);
        }
        return $t;
    }

    public static function message(): string
    {
        return t('در این بخش که همه می‌بینند، نوشتن شماره تماس، ایمیل، آیدی واتساپ، تلگرام، اینستاگرام و دیگر شبکه‌های اجتماعی یا نشانی وب‌سایت مجاز نیست. این اطلاعات را می‌توانید در نامه خصوصی برای تاجر مورد نظر بفرستید.');
    }

    private static function prepare(string $text): string
    {
        // Persian/Arabic digits → 0-9, strip zero-width characters used to dodge filters.
        $text = Str::latinDigits($text);
        return (string) preg_replace('/[\x{200B}-\x{200F}\x{2060}\x{FEFF}]/u', '', $text);
    }

    /** @return array<string, string> */
    private static function patterns(): array
    {
        $tld = self::TLDS;
        $msg = self::MESSENGERS;
        return [
            // a@b.c, a (at) b dot c, a [at] b
            'email' => '/[a-z0-9._%+\-]+\s*(?:@|\(at\)|\[at\]|\sat\s)\s*[a-z0-9\-]+(?:\s*(?:\.|\(dot\)|\[dot\]|\sdot\s)\s*[a-z]{2,})+/iu',
            // http(s)://, www., t.me/, wa.me/, domain.tld, "dot com"
            'url' => '/(?:https?:\/\/|www\.|\b(?:t|wa)\.me\/|\b[a-z0-9\-]{2,}(?:\.[a-z0-9\-]{2,})*\.(?:' . $tld . ')\b|\b(?:dot|نقطه)\s*(?:com|ir|net|org|کام|آی\s?آر)\b)/iu',
            // messenger keyword followed by an id / number
            'handle' => '/(?:' . $msg . ')\s*[:：\-]?\s*@?[a-z0-9_.]{3,}|(?<![a-z0-9._%+\-])@[a-z0-9_.]{3,}/iu',
            // 7+ digits with common separators; checked for digit count in find()/mask()
            'phone' => '/(?:\+|00)?\d[\d\s\-\.\(\)\/]{5,}\d/u',
        ];
    }
}
