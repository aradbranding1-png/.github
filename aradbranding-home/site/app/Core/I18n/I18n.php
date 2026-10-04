<?php

declare(strict_types=1);

namespace App\Core\I18n;

use App\Core\Cache\Cache;
use App\Core\Container;
use App\Core\Db\Connection;
use App\Core\Http\Request;
use App\Core\Settings\Settings;
use Throwable;

/**
 * Interface language of the member-facing site (home, sign-in/up, member panel).
 *
 * Persian is the source language: every UI string is written in Persian in the code and passed through t().
 * For Persian the source comes back untouched, so the Persian site is exactly what it was before translation existed.
 * Other locales read app/Lang/{locale}.php, then the super admin's overrides (table translations), then fall back to
 * English and finally to the Persian source. Strings with no translation are recorded in translation_misses so the
 * super admin can translate them under «زبان‌ها».
 *
 * Choice of locale, first match wins:
 *   1. a ?lang=xx link, then the visitor's own choice (cookie `lang`, set by /lang/{code}, saved on the account and
 *      restored at sign-in); search engine crawlers without either get Persian,
 *   2. the browser's main language when it is one of ours other than English (a Persian browser on a VPN is still Persian),
 *   3. the device time zone (cookie `tzl`, written by locale.js; a VPN changes the IP, not the clock),
 *   4. the IP country (CDN country header or the bundled offline database),
 *   5. any other language in Accept-Language, then the default locale.
 * The admin area, the API and the installer always stay Persian.
 */
final class I18n
{
    public const SOURCE = 'fa';

    /** code => native name, Persian name, direction, ICU locale (Latin digits everywhere but Persian) */
    public const LOCALES = [
        'fa' => ['native' => 'فارسی', 'fa' => 'فارسی', 'dir' => 'rtl', 'intl' => 'fa_IR'],
        'en' => ['native' => 'English', 'fa' => 'انگلیسی', 'dir' => 'ltr', 'intl' => 'en_GB'],
        'ar' => ['native' => 'العربية', 'fa' => 'عربی', 'dir' => 'rtl', 'intl' => 'ar@numbers=latn'],
        'tr' => ['native' => 'Türkçe', 'fa' => 'ترکی استانبولی', 'dir' => 'ltr', 'intl' => 'tr_TR'],
        'fr' => ['native' => 'Français', 'fa' => 'فرانسوی', 'dir' => 'ltr', 'intl' => 'fr_FR'],
        'ru' => ['native' => 'Русский', 'fa' => 'روسی', 'dir' => 'ltr', 'intl' => 'ru_RU'],
    ];

    /** Countries whose visitors get a language other than English. Everything else falls to the default locale. */
    public const COUNTRY_LOCALE = [
        'IR' => 'fa', 'AF' => 'fa',
        'SA' => 'ar', 'AE' => 'ar', 'QA' => 'ar', 'KW' => 'ar', 'BH' => 'ar', 'OM' => 'ar', 'IQ' => 'ar', 'SY' => 'ar', 'JO' => 'ar',
        'LB' => 'ar', 'PS' => 'ar', 'YE' => 'ar', 'EG' => 'ar', 'LY' => 'ar', 'TN' => 'ar', 'DZ' => 'ar', 'MA' => 'ar', 'SD' => 'ar',
        'MR' => 'ar', 'SO' => 'ar', 'DJ' => 'ar', 'KM' => 'ar', 'EH' => 'ar',
        'TR' => 'tr', 'AZ' => 'tr',
        'RU' => 'ru', 'BY' => 'ru', 'KZ' => 'ru', 'KG' => 'ru', 'UZ' => 'ru', 'TJ' => 'ru', 'TM' => 'ru', 'AM' => 'ru', 'MD' => 'ru',
        'FR' => 'fr', 'BE' => 'fr', 'LU' => 'fr', 'MC' => 'fr', 'SN' => 'fr', 'CI' => 'fr', 'ML' => 'fr', 'BF' => 'fr', 'NE' => 'fr',
        'TG' => 'fr', 'BJ' => 'fr', 'GN' => 'fr', 'CM' => 'fr', 'GA' => 'fr', 'CG' => 'fr', 'CD' => 'fr', 'CF' => 'fr', 'TD' => 'fr',
        'MG' => 'fr', 'HT' => 'fr', 'BI' => 'fr', 'NC' => 'fr', 'PF' => 'fr', 'RE' => 'fr', 'GP' => 'fr', 'MQ' => 'fr', 'GF' => 'fr',
        'YT' => 'fr', 'PM' => 'fr', 'WF' => 'fr', 'MF' => 'fr', 'BL' => 'fr',
    ];

    /** Paths that always stay Persian (staff tools, machine interfaces). */
    private const PERSIAN_ONLY = ['/admin', '/api/', '/install', '/ops/', '/health'];

    private static string $locale = self::SOURCE;
    private static string $how = 'default';
    private static ?string $country = null;
    private static ?string $timezone = null;
    private static bool $track = false;
    /** @var array<string, array<string, string>> */
    private static array $dicts = [];
    /** @var array<string, true> */
    private static array $misses = [];

    /** Picks the locale for this request. Never throws: on any failure the site simply stays Persian. */
    public static function boot(Request $request): void
    {
        self::$locale = self::SOURCE;
        self::$how = 'default';
        self::$country = null;
        self::$misses = [];
        self::$track = false;
        $tz = (string) $request->cookie('tzl');
        self::$timezone = $tz !== '' && strlen($tz) < 64 && in_array($tz, \DateTimeZone::listIdentifiers(), true) ? $tz : null;
        foreach (self::PERSIAN_ONLY as $prefix) {
            if (str_starts_with($request->path, $prefix)) {
                return;
            }
        }
        try {
            [$locale, $how] = self::resolve($request);
        } catch (Throwable) {
            [$locale, $how] = [self::SOURCE, 'default'];
        }
        self::$locale = $locale;
        self::$how = $how;
        self::$track = $locale !== self::SOURCE;
    }

    /** @return array{0: string, 1: string} locale and how it was chosen */
    private static function resolve(Request $request): array
    {
        $enabled = self::enabled();
        $linked = $request->query('lang');
        if (is_string($linked) && isset($enabled[strtolower($linked)])) {
            return [strtolower($linked), 'link'];
        }
        $chosen = strtolower((string) $request->cookie('lang'));
        if (isset($enabled[$chosen])) {
            return [$chosen, 'chosen'];
        }
        // Search engines index the Persian site at the plain address and each language at ?lang=xx (see hreflang).
        if (preg_match('/bot|crawl|spider|slurp|facebookexternalhit|preview/i', $request->userAgent())) {
            return [self::SOURCE, 'bot'];
        }
        $default = self::defaultLocale();
        if (!self::setting('i18n.detect', true)) {
            return [$default, 'default'];
        }
        $accept = self::acceptLanguages((string) $request->header('accept-language', ''));
        if ($accept !== [] && $accept[0] !== 'en' && isset($enabled[$accept[0]])) {
            return [$accept[0], 'browser'];
        }
        if (self::$timezone !== null) {
            $country = self::timezoneCountry(self::$timezone);
            if ($country !== null) {
                self::$country = $country;
                return [self::forCountry($country, $enabled, $default), 'timezone'];
            }
        }
        $country = self::requestCountry($request);
        if ($country !== null) {
            self::$country = $country;
            $locale = self::COUNTRY_LOCALE[$country] ?? null;
            if ($locale !== null && isset($enabled[$locale])) {
                return [$locale, 'ip'];
            }
        }
        // A secondary browser language (often just the default English) is a weak hint: the time zone may still correct it.
        foreach ($accept as $code) {
            if (isset($enabled[$code])) {
                return [$code, 'weak'];
            }
        }
        return [$country !== null ? self::forCountry($country, $enabled, $default) : $default, $country !== null ? 'ip' : 'default'];
    }

    /** @param array<string, true> $enabled */
    private static function forCountry(string $country, array $enabled, string $default): string
    {
        $locale = self::COUNTRY_LOCALE[$country] ?? 'en';
        return isset($enabled[$locale]) ? $locale : $default;
    }

    /** Country from a CDN header (Cloudflare, generic proxies) or, failing that, the bundled offline database. */
    public static function requestCountry(Request $request): ?string
    {
        foreach (['cf-ipcountry', 'x-country-code', 'x-geo-country', 'cloudfront-viewer-country'] as $h) {
            $v = strtoupper(trim((string) $request->header($h, '')));
            if (preg_match('/^[A-Z]{2}$/', $v) && $v !== 'XX' && $v !== 'T1') {
                return $v;
            }
        }
        return GeoIp::country($request->ip());
    }

    public static function timezoneCountry(string $tz): ?string
    {
        if ($tz === 'Asia/Tehran' || $tz === 'Iran') {
            return 'IR';
        }
        try {
            $code = (new \DateTimeZone($tz))->getLocation()['country_code'] ?? '';
        } catch (Throwable) {
            return null;
        }
        return preg_match('/^[A-Z]{2}$/', (string) $code) ? (string) $code : null;
    }

    /** @return list<string> language codes in preference order */
    public static function acceptLanguages(string $header): array
    {
        $out = [];
        foreach (explode(',', $header) as $i => $part) {
            $bits = explode(';', trim($part));
            $code = strtolower(substr(trim($bits[0]), 0, 2));
            if (!preg_match('/^[a-z]{2}$/', $code)) {
                continue;
            }
            $q = 1.0;
            if (isset($bits[1]) && preg_match('/q=([0-9.]+)/', $bits[1], $m)) {
                $q = (float) $m[1];
            }
            if ($q > 0 && !isset($out[$code])) {
                $out[$code] = $q - $i / 1000;
            }
        }
        arsort($out);
        return array_keys($out);
    }

    // ----- current state -----

    public static function locale(): string
    {
        return self::$locale;
    }

    /** For tests and the console: render as a given locale. */
    public static function use(string $locale): void
    {
        self::$locale = isset(self::LOCALES[$locale]) ? $locale : self::SOURCE;
        self::$track = false;
    }

    public static function isSource(): bool
    {
        return self::$locale === self::SOURCE;
    }

    public static function dir(): string
    {
        return self::LOCALES[self::$locale]['dir'];
    }

    public static function intl(): string
    {
        return self::LOCALES[self::$locale]['intl'];
    }

    /** How the locale was chosen: link, chosen, bot, browser, timezone, ip, weak (secondary browser language), default. */
    public static function how(): string
    {
        return self::$how;
    }

    public static function country(): ?string
    {
        return self::$country;
    }

    /** Display time zone: Persian keeps Tehran time; other languages use the device zone when known. */
    public static function timezone(): string
    {
        $tehran = (string) \App\Core\Env::get('DISPLAY_TIMEZONE', 'Asia/Tehran');
        return self::$locale === self::SOURCE ? $tehran : (self::$timezone ?? $tehran);
    }

    /**
     * lang/dir attributes for <html>: exactly `lang="fa" dir="rtl"` for Persian. When the language was only guessed
     * (IP or default) the enabled list and default are added so locale.js can correct the guess from the time zone.
     */
    public static function htmlAttrs(): string
    {
        $attrs = 'lang="' . self::$locale . '" dir="' . self::dir() . '"';
        if (self::guessed()) {
            $attrs .= ' data-lauto="1" data-lon="' . implode(',', array_keys(self::enabled())) . '" data-ldef="' . self::defaultLocale() . '"';
        }
        return $attrs;
    }

    /** lang/dir pair for an element that must follow the interface language (`lang="fa" dir="rtl"` in Persian). */
    public static function langAttrs(): string
    {
        return 'lang="' . self::$locale . '" dir="' . self::dir() . '"';
    }

    /** locale.js for pages in another language or with a guessed language (none for a Persian browser). */
    public static function headScript(): string
    {
        return self::$locale !== self::SOURCE || self::guessed() ? '<script src="' . e(asset('locale.js')) . '"></script>' . "\n" : '';
    }

    /**
     * Wording for the browser scripts (T() in app.js and friends, the globe's labels): a JSON block the scripts read.
     * Nothing for Persian, where the scripts already hold the text.
     */
    public static function jsDict(): string
    {
        if (self::$locale === self::SOURCE) {
            return '';
        }
        $file = BASE_PATH . '/app/Lang/js.php';
        $sources = is_file($file) ? (array) require $file : [];
        $track = self::$track;
        self::$track = false;
        $map = [];
        foreach ($sources as $s) {
            $tr = self::lookup((string) $s);
            if ($tr !== $s) {
                $map[$s] = $tr;
            }
        }
        self::$track = $track;
        return '<script type="application/json" id="i18n-js">'
            . json_encode($map, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) . "</script>\n";
    }

    private static function guessed(): bool
    {
        return self::$how === 'ip' || self::$how === 'default' || self::$how === 'weak';
    }

    /** @return array<string, true> enabled locale codes (Persian is always on) */
    public static function enabled(): array
    {
        $list = self::setting('i18n.enabled', array_keys(self::LOCALES));
        $out = [self::SOURCE => true];
        foreach ((array) $list as $code) {
            if (is_string($code) && isset(self::LOCALES[$code])) {
                $out[$code] = true;
            }
        }
        return $out;
    }

    /** @return array<string, array{native: string, fa: string, dir: string, intl: string}> enabled locales in display order */
    public static function enabledLocales(): array
    {
        return array_intersect_key(self::LOCALES, self::enabled());
    }

    public static function defaultLocale(): string
    {
        $d = (string) self::setting('i18n.default', 'en');
        return isset(self::enabled()[$d]) ? $d : self::SOURCE;
    }

    private static function setting(string $key, mixed $default): mixed
    {
        try {
            $c = Container::instance();
            return $c !== null && $c->has(Settings::class) ? $c->get(Settings::class)->get($key, $default) : $default;
        } catch (Throwable) {
            return $default;
        }
    }

    // ----- translation -----

    /** Persian source → current locale, with :name placeholders. Plain text; escape it like any other value. */
    public static function t(string $source, array $params = []): string
    {
        $text = self::$locale === self::SOURCE ? $source : self::lookup($source);
        if ($params === []) {
            return $text;
        }
        $map = [];
        foreach ($params as $k => $v) {
            $map[':' . $k] = (string) $v;
        }
        if (self::$locale !== self::SOURCE && str_contains($text, '{') && class_exists(\MessageFormatter::class)) {
            $text = self::plural($text, $params);
        }
        return strtr($text, $map);
    }

    /** Raw lookup with the English → Persian fallback chain; records the miss. */
    public static function lookup(string $source, ?string $locale = null): string
    {
        $locale ??= self::$locale;
        if ($locale === self::SOURCE || $source === '') {
            return $source;
        }
        $dict = self::dict($locale);
        if (isset($dict[$source]) && $dict[$source] !== '') {
            return $dict[$source];
        }
        if (self::$track && $locale === self::$locale && preg_match('/\p{Arabic}/u', $source)) {
            self::$misses[$source] = true;
        }
        if ($locale !== 'en') {
            $en = self::dict('en');
            if (isset($en[$source]) && $en[$source] !== '') {
                return $en[$source];
            }
        }
        return $source;
    }

    /** True when the string has its own translation in the given locale (file or override). */
    public static function has(string $source, string $locale): bool
    {
        return $locale === self::SOURCE || (self::dict($locale)[$source] ?? '') !== '';
    }

    /** ICU plural/select blocks inside a translation, fed with the numeric value of each parameter. */
    private static function plural(string $text, array $params): string
    {
        $args = [];
        foreach ($params as $k => $v) {
            $digits = preg_replace('/[^0-9.\-]/', '', strtr((string) $v, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
                '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9']));
            $args[(string) $k] = is_numeric($digits) && preg_match('/\d/', (string) $v) ? (float) $digits : (string) $v;
        }
        try {
            $out = \MessageFormatter::formatMessage(self::intl(), $text, $args);
        } catch (Throwable) {
            $out = false;
        }
        return is_string($out) ? $out : $text;
    }

    /** @return array<string, string> file dictionary merged with the super admin's overrides */
    public static function dict(string $locale): array
    {
        if (!isset(self::$dicts[$locale])) {
            self::$dicts[$locale] = self::fileDict($locale);
            foreach (self::overrides($locale) as $src => $text) {
                self::$dicts[$locale][$src] = $text;
            }
        }
        return self::$dicts[$locale];
    }

    /** @return array<string, string> */
    public static function fileDict(string $locale): array
    {
        $file = BASE_PATH . '/app/Lang/' . $locale . '.php';
        if (!isset(self::LOCALES[$locale]) || !is_file($file)) {
            return [];
        }
        $d = require $file;
        return is_array($d) ? $d : [];
    }

    /** @return array<string, string> */
    private static function overrides(string $locale): array
    {
        try {
            $c = Container::instance();
            if ($c === null || !$c->has(Connection::class)) {
                return [];
            }
            $cache = $c->get(Cache::class);
            $db = $c->get(Connection::class);
            return (array) $cache->remember('i18n:' . $locale . ':v' . $cache->version('i18n'), 3600, static function () use ($db, $locale): array {
                $out = [];
                foreach ($db->select('SELECT source, text FROM translations WHERE locale = ?', [$locale]) as $r) {
                    $out[(string) $r['source']] = (string) $r['text'];
                }
                return $out;
            });
        } catch (Throwable) {
            return [];
        }
    }

    /** Forget loaded dictionaries (after the super admin edits a translation). */
    public static function flush(): void
    {
        self::$dicts = [];
        try {
            Container::instance()?->get(Cache::class)->bump('i18n');
        } catch (Throwable) {
        }
    }

    /** Writes this request's untranslated strings (called once at the end of the request). */
    public static function persistMisses(): void
    {
        if (self::$misses === [] || !self::$track) {
            return;
        }
        $misses = array_slice(array_keys(self::$misses), 0, 300);
        self::$misses = [];
        try {
            $db = Container::instance()?->get(Connection::class);
            if ($db === null) {
                return;
            }
            foreach (array_chunk($misses, 100) as $chunk) {
                $rows = [];
                foreach ($chunk as $src) {
                    array_push($rows, self::$locale, hash('sha256', $src), mb_substr($src, 0, 2000));
                }
                $db->exec(
                    'INSERT INTO translation_misses (locale, source_hash, source, hits, first_seen, last_seen) VALUES '
                        . implode(',', array_fill(0, count($chunk), '(?, ?, ?, 1, NOW(), NOW())'))
                        . ' ON DUPLICATE KEY UPDATE hits = hits + 1, last_seen = NOW()',
                    $rows
                );
            }
        } catch (Throwable) {
            // missing-string tracking must never break a page
        }
    }

    // ----- formatting -----

    /** Grouped integer in the current locale (Persian keeps ۱٬۲۳۴). */
    public static function int(int|float $value): string
    {
        static $fmt = [];
        $loc = self::intl();
        if (!class_exists(\NumberFormatter::class)) {
            return number_format((float) $value, 0, '.', ',');
        }
        $fmt[$loc] ??= new \NumberFormatter($loc, \NumberFormatter::DECIMAL);
        $fmt[$loc]->setAttribute(\NumberFormatter::FRACTION_DIGITS, 0);
        $out = $fmt[$loc]->format(round((float) $value));
        return is_string($out) ? $out : (string) (int) $value;
    }

    /** Gregorian date (and time) in the current locale, in the visitor's time zone. */
    public static function date(\DateTimeImmutable $dt, bool $withTime): string
    {
        $dt = $dt->setTimezone(new \DateTimeZone(self::timezone()));
        $skeleton = $withTime ? 'dMMMyHHmm' : 'dMMMy';
        if (class_exists(\IntlDateFormatter::class)) {
            $pattern = class_exists(\IntlDatePatternGenerator::class)
                ? ((new \IntlDatePatternGenerator(self::intl()))->getBestPattern($skeleton) ?: null)
                : null;
            $f = new \IntlDateFormatter(self::intl() . (str_contains(self::intl(), '@') ? ';' : '@') . 'calendar=gregorian',
                \IntlDateFormatter::MEDIUM, $withTime ? \IntlDateFormatter::SHORT : \IntlDateFormatter::NONE, $dt->getTimezone(),
                \IntlDateFormatter::GREGORIAN, $pattern);
            $out = $f->format($dt);
            if (is_string($out)) {
                return $out;
            }
        }
        return $dt->format($withTime ? 'Y-m-d H:i' : 'Y-m-d');
    }

    /** Weekday and long date for the panel header ("Saturday 3 October 2026"). */
    public static function today(): string
    {
        $tz = new \DateTimeZone(self::timezone());
        if (!class_exists(\IntlDateFormatter::class)) {
            return (new \DateTimeImmutable('now', $tz))->format('l j F Y');
        }
        $pattern = class_exists(\IntlDatePatternGenerator::class) ? (new \IntlDatePatternGenerator(self::intl()))->getBestPattern('EEEEdMMMMy') : 'EEEE d MMMM y';
        $f = new \IntlDateFormatter(self::intl() . (str_contains(self::intl(), '@') ? ';' : '@') . 'calendar=gregorian', \IntlDateFormatter::FULL,
            \IntlDateFormatter::NONE, $tz, \IntlDateFormatter::GREGORIAN, $pattern ?: 'EEEE d MMMM y');
        return (string) $f->format(new \DateTimeImmutable('now', $tz));
    }

    /** Country name in the current locale (ICU), falling back to the stored Persian name. */
    public static function countryName(?string $code, string $persian): string
    {
        if (self::$locale === self::SOURCE || $code === null || !preg_match('/^[A-Z]{2}$/', $code) || !class_exists(\Locale::class)) {
            return $persian;
        }
        $over = self::dict(self::$locale)[$persian] ?? '';
        if ($over !== '') {
            return $over;
        }
        $name = \Locale::getDisplayRegion('-' . $code, self::intl());
        return $name !== '' && $name !== $code ? $name : $persian;
    }

    /** Language name in the current locale (ICU) for an ISO 639 code. */
    public static function languageName(?string $code, string $persian): string
    {
        if (self::$locale === self::SOURCE || $code === null || $code === '' || !class_exists(\Locale::class)) {
            return $persian;
        }
        $over = self::dict(self::$locale)[$persian] ?? '';
        if ($over !== '') {
            return $over;
        }
        $name = \Locale::getDisplayLanguage($code, self::intl());
        if ($name === '' || $name === $code) {
            return $persian;
        }
        return in_array(self::$locale, ['fr', 'ru', 'tr'], true) ? mb_strtoupper(mb_substr($name, 0, 1)) . mb_substr($name, 1) : $name;
    }
}
