<?php

declare(strict_types=1);

use App\Core\Session\Session;

if (!function_exists('e')) {
    /** HTML-escape for text and attribute contexts. */
    function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        return '<input type="hidden" name="_token" value="' . e(Session::csrfToken()) . '">';
    }
}

if (!function_exists('asset')) {
    /** Static asset URL with a cache-busting version from the file's mtime. */
    function asset(string $path): string
    {
        static $versions = [];
        $path = ltrim($path, '/');
        if (!isset($versions[$path])) {
            $file = BASE_PATH . '/public_html/assets/' . $path;
            $versions[$path] = is_file($file) ? base_convert((string) filemtime($file), 10, 36) : '0';
        }
        return '/assets/' . $path . '?v=' . $versions[$path];
    }
}

if (!function_exists('media')) {
    function media(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }
        $base = rtrim((string) \App\Core\Env::get('MEDIA_PUBLIC_URL', '/media'), '/');
        return $base . '/' . ltrim($path, '/');
    }
}

if (!function_exists('flag')) {
    /** ISO-3166 alpha-2 → emoji flag (regional indicator symbols). */
    function flag(?string $code): string
    {
        if ($code === null || !preg_match('/^[A-Za-z]{2}$/', $code)) {
            return '';
        }
        $code = strtoupper($code);
        return mb_chr(0x1F1E6 + ord($code[0]) - 65) . mb_chr(0x1F1E6 + ord($code[1]) - 65);
    }
}

if (!function_exists('fa_num')) {
    /** Latin digits → Persian digits for display. */
    function fa_num(int|float|string $value): string
    {
        if (!\App\Core\I18n\I18n::isSource()) {
            return (string) $value;
        }
        return strtr((string) $value, ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴',
            '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']);
    }
}

if (!function_exists('initials')) {
    function initials(string $first, string $last): string
    {
        return mb_substr(trim($first), 0, 1) . mb_substr(trim($last), 0, 1);
    }
}

if (!function_exists('rich_text')) {
    /** Escaped text with line breaks and **bold** only. No other markup is possible. */
    function rich_text(?string $text): string
    {
        $html = e((string) $text);
        $html = (string) preg_replace('/\*\*(.+?)\*\*/us', '<strong>$1</strong>', $html);
        return nl2br(str_replace('**', '', $html), false);
    }
}

if (!function_exists('plain_text')) {
    /** Strips the **bold** markers for meta tags and previews. */
    function plain_text(?string $text): string
    {
        return str_replace('**', '', (string) $text);
    }
}

if (!function_exists('upload_max_kb')) {
    function upload_max_kb(): int
    {
        static $kb = null;
        if ($kb === null) {
            $kb = (int) \App\Core\Env::get('UPLOAD_MAX_KB', 200);
            $c = \App\Core\Container::instance();
            if ($c !== null && $c->has(\App\Core\Settings\Settings::class)) {
                try {
                    $kb = (int) $c->get(\App\Core\Settings\Settings::class)->get('upload.max_kb', $kb);
                } catch (\Throwable) {
                    // before installation there is no settings table: keep the .env value
                }
            }
            $kb = max(20, $kb);
        }
        return $kb;
    }
}

if (!function_exists('fa_int')) {
    /** 1234567 → ۱٬۲۳۴٬۵۶۷ */
    function fa_int(int|float $value): string
    {
        if (!\App\Core\I18n\I18n::isSource()) {
            return \App\Core\I18n\I18n::int($value);
        }
        return fa_num(number_format((float) $value, 0, '.', '٬'));
    }
}

if (!function_exists('toman')) {
    /**
     * Rial amount → readable Toman: 1,000 → "هزار تومان", 50,000 → "۵۰ هزار تومان",
     * 2,500,000 → "۲٫۵ میلیون تومان", otherwise "۱۲٬۳۴۵ تومان".
     */
    function toman(int $rial): string
    {
        $t = intdiv($rial, 10);
        if (!\App\Core\I18n\I18n::isSource()) {
            return t(':n تومان', ['n' => fa_int($t)]);
        }
        if ($t >= 1_000_000 && $t % 100_000 === 0) {
            $m = $t / 1_000_000;
            return ($m == 1 ? 'یک' : fa_num(str_replace('.', '٫', (string) $m))) . ' میلیون تومان';
        }
        if ($t >= 1000 && $t % 1000 === 0) {
            $k = intdiv($t, 1000);
            return ($k === 1 ? '' : fa_int($k) . ' ') . 'هزار تومان';
        }
        return fa_int($t) . ' تومان';
    }
}

if (!function_exists('fa_date')) {
    /**
     * UTC datetime → Persian (Jalali) date in Tehran time. Today shows only the time.
     * Falls back to the Gregorian date if the intl extension is missing.
     */
    function fa_date(?string $utc, bool $withTime = true): string
    {
        if ($utc === null || $utc === '') {
            return '';
        }
        if (!\App\Core\I18n\I18n::isSource()) {
            $dt = (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone(\App\Core\I18n\I18n::timezone()));
            if ($withTime && (new \DateTimeImmutable('now', $dt->getTimezone()))->format('Y-m-d') === $dt->format('Y-m-d')) {
                return $dt->format('H:i');
            }
            return \App\Core\I18n\I18n::date($dt, $withTime);
        }
        $tz = new \DateTimeZone((string) \App\Core\Env::get('DISPLAY_TIMEZONE', 'Asia/Tehran'));
        $dt = (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))->setTimezone($tz);
        $today = (new \DateTimeImmutable('now', $tz))->format('Y-m-d') === $dt->format('Y-m-d');
        if ($today && $withTime) {
            return fa_num($dt->format('H:i'));
        }
        if (class_exists(\IntlDateFormatter::class)) {
            $f = new \IntlDateFormatter('fa_IR@calendar=persian', \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, $tz, \IntlDateFormatter::TRADITIONAL,
                $withTime ? 'd MMMM y، HH:mm' : 'd MMMM y');
            $out = $f->format($dt);
            if (is_string($out)) {
                return $out;
            }
        }
        return fa_num($dt->format($withTime ? 'Y/m/d H:i' : 'Y/m/d'));
    }
}

if (!function_exists('t')) {
    /**
     * UI string in the visitor's language. The argument is the Persian text, returned unchanged for Persian.
     * Placeholders are :name. The result is plain text: escape it (te) unless it goes into a PHP string that is escaped later.
     * @param array<string, string|int|float> $params
     */
    function t(string $source, array $params = []): string
    {
        return \App\Core\I18n\I18n::t($source, $params);
    }
}

if (!function_exists('te')) {
    /** Escaped t() for HTML text and attributes. */
    function te(string $source, array $params = []): string
    {
        return e(\App\Core\I18n\I18n::t($source, $params));
    }
}

if (!function_exists('th')) {
    /**
     * t() for a source that is itself HTML (simple tags such as <b>, <strong>, <br>, <code>, <small> and entities).
     * The translation is escaped and only those plain tags are restored; :params are inserted as given (pass escaped HTML).
     * For Persian the source HTML is output exactly as written.
     * @param array<string, string|int|float> $params
     */
    function th(string $source, array $params = []): string
    {
        $map = [];
        foreach ($params as $k => $v) {
            $map[':' . $k] = (string) $v;
        }
        if (\App\Core\I18n\I18n::isSource()) {
            return strtr($source, $map);
        }
        $html = htmlspecialchars(\App\Core\I18n\I18n::t($source), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false);
        $html = (string) preg_replace('~&lt;(/?)(b|strong|em|i|code|small|br|bdi|u|kbd|mark|sup|sub)\s*/?&gt;~i', '<$1$2>', $html);
        return strtr($html, $map);
    }
}

if (!function_exists('locale')) {
    function locale(): string
    {
        return \App\Core\I18n\I18n::locale();
    }
}

if (!function_exists('localized_rows')) {
    /**
     * Reference rows (countries, languages, categories) with their Persian name column translated for the interface
     * language and re-sorted the way that language sorts. Unchanged in Persian.
     * @template T of array
     * @param array<array-key, T> $rows
     * @return array<array-key, T>
     */
    function localized_rows(array $rows, string $column = 'name_fa', bool $sort = true): array
    {
        if (\App\Core\I18n\I18n::isSource()) {
            return $rows;
        }
        foreach ($rows as &$r) {
            if (isset($r[$column]) && is_string($r[$column])) {
                $r[$column] = t($r[$column]);
            }
        }
        unset($r);
        if ($sort && class_exists(\Collator::class)) {
            $coll = new \Collator(\App\Core\I18n\I18n::intl());
            uasort($rows, static fn (array $a, array $b): int => (int) $coll->compare((string) ($a[$column] ?? ''), (string) ($b[$column] ?? '')));
        }
        return $rows;
    }
}
