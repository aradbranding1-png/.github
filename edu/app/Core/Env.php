<?php
declare(strict_types=1);

namespace App\Core;

/** Minimal .env loader (no external dependencies). */
final class Env
{
    private static array $vars = [];

    public static function load(string $file): void
    {
        if (!is_file($file)) return;
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
            [$k, $v] = explode('=', $line, 2);
            $k = trim($k);
            $v = trim($v);
            if (strlen($v) >= 2 && (($v[0] === '"' && str_ends_with($v, '"')) || ($v[0] === "'" && str_ends_with($v, "'")))) {
                $v = substr($v, 1, -1);
                $v = str_replace(['\\n', '\\"'], ["\n", '"'], $v);
            }
            self::$vars[$k] = $v;
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, self::$vars)) {
            $v = self::$vars[$key];
            return match (strtolower($v)) {
                'true' => true,
                'false' => false,
                'null', '' => $default,
                default => $v,
            };
        }
        $g = getenv($key);
        return $g === false ? $default : $g;
    }

    public static function set(string $key, string $value): void
    {
        self::$vars[$key] = $value;
    }

    /** Write a complete .env file atomically. */
    public static function write(string $file, array $values): bool
    {
        $out = "# Arad Edu environment — DO NOT place this file inside public_html\n";
        foreach ($values as $k => $v) {
            if (is_bool($v)) $v = $v ? 'true' : 'false';
            $v = (string)$v;
            if ($v !== '' && preg_match('/[\s#"\'=]/', $v)) $v = '"' . str_replace('"', '\\"', $v) . '"';
            $out .= $k . '=' . $v . "\n";
        }
        $tmp = $file . '.tmp' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $out, LOCK_EX) === false) return false;
        @chmod($tmp, 0640);
        return rename($tmp, $file);
    }

    public static function all(): array
    {
        return self::$vars;
    }
}
