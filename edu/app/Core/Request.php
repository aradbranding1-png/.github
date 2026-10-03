<?php
declare(strict_types=1);

namespace App\Core;

final class Request
{
    private static ?string $path = null;
    public static array $params = [];

    public static function method(): string
    {
        $m = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if ($m === 'POST' && isset($_POST['_method'])) {
            $o = strtoupper((string)$_POST['_method']);
            if (in_array($o, ['PUT', 'PATCH', 'DELETE'], true)) return $o;
        }
        return $m;
    }

    public static function isPost(): bool
    {
        return ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
    }

    public static function path(): string
    {
        if (self::$path !== null) return self::$path;
        if (isset($_GET['r']) && is_string($_GET['r'])) {
            $p = $_GET['r'];
        } else {
            $p = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
            $base = function_exists('base_path_uri') ? base_path_uri() : '';
            if ($base !== '' && str_starts_with($p, $base)) $p = substr($p, strlen($base));
            if (str_starts_with($p, '/index.php')) $p = substr($p, 10);
        }
        $p = '/' . trim(rawurldecode($p), '/');
        return self::$path = $p;
    }

    public static function input(string $key, mixed $default = null): mixed
    {
        $v = $_POST[$key] ?? $_GET[$key] ?? $default;
        if (is_string($v)) $v = normalize_input($v);
        return $v;
    }

    public static function str(string $key, string $default = ''): string
    {
        $v = self::input($key, $default);
        return is_string($v) ? $v : $default;
    }

    public static function int(string $key, int $default = 0): int
    {
        $v = self::input($key, $default);
        return is_numeric($v) ? (int)$v : $default;
    }

    public static function intOrNull(string $key): ?int
    {
        $v = self::input($key);
        return (is_numeric($v) && (int)$v > 0) ? (int)$v : null;
    }

    public static function bool(string $key): bool
    {
        return in_array(self::input($key), ['1', 'on', 'true', 'yes', 1, true], true);
    }

    public static function arr(string $key): array
    {
        $v = $_POST[$key] ?? $_GET[$key] ?? [];
        return is_array($v) ? $v : [];
    }

    public static function ints(string $key): array
    {
        return array_values(array_unique(array_filter(array_map('intval', self::arr($key)), fn($x) => $x > 0)));
    }

    public static function all(): array
    {
        return array_merge($_GET, $_POST);
    }

    public static function file(string $key): ?array
    {
        $f = $_FILES[$key] ?? null;
        if (!$f || !is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
        return $f;
    }

    /** Client IP. Proxy headers are trusted only when TRUSTED_PROXIES is configured. */
    public static function ip(): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $trusted = array_filter(array_map('trim', explode(',', (string)env('TRUSTED_PROXIES', ''))));
        if ($trusted && in_array($ip, $trusted, true)) {
            foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP'] as $h) {
                if (!empty($_SERVER[$h])) {
                    $c = trim(explode(',', $_SERVER[$h])[0]);
                    if (filter_var($c, FILTER_VALIDATE_IP)) return $c;
                }
            }
        }
        return $ip;
    }

    public static function userAgent(): string
    {
        return mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 250);
    }

    public static function isAjax(): bool
    {
        return ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest' || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
    }

    public static function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') || str_starts_with((string)env('APP_URL', ''), 'https://');
    }

    public static function param(string $k): ?string
    {
        return self::$params[$k] ?? null;
    }
}
