<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Minimal .env loader. Values already present in the real environment win,
 * so production secrets can be injected by the server instead of the file.
 */
final class Env
{
    /** @var array<string, string> */
    private static array $values = [];

    public static function load(string $file): void
    {
        if (!is_file($file) || !is_readable($file)) {
            return;
        }
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = array_map('trim', explode('=', $line, 2));
            if (!preg_match('/^[A-Z][A-Z0-9_]*$/', $key)) {
                continue;
            }
            $value = self::unquote($value);
            $fromServer = getenv($key);
            self::$values[$key] = $fromServer !== false ? $fromServer : $value;
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = self::$values[$key] ?? getenv($key);
        if ($value === false || $value === null) {
            return $default;
        }
        return match (strtolower((string) $value)) {
            'true' => true,
            'false' => false,
            'null', '' => $default,
            default => $value,
        };
    }

    /** @param array<string, string> $values */
    public static function write(string $file, array $values): void
    {
        $existing = is_file($file) ? (file($file, FILE_IGNORE_NEW_LINES) ?: []) : [];
        $seen = [];
        foreach ($existing as $i => $line) {
            foreach ($values as $key => $value) {
                if (preg_match('/^' . preg_quote($key, '/') . '\s*=/', $line)) {
                    $existing[$i] = $key . '=' . self::quote($value);
                    $seen[$key] = true;
                }
            }
        }
        foreach ($values as $key => $value) {
            if (!isset($seen[$key])) {
                $existing[] = $key . '=' . self::quote($value);
            }
        }
        $tmp = $file . '.tmp';
        file_put_contents($tmp, implode("\n", $existing) . "\n", LOCK_EX);
        chmod($tmp, 0600);
        rename($tmp, $file);
    }

    private static function unquote(string $value): string
    {
        if (strlen($value) >= 2) {
            $first = $value[0];
            $last = $value[strlen($value) - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $inner = substr($value, 1, -1);
                return $first === '"' ? stripcslashes($inner) : $inner;
            }
        }
        $hash = strpos($value, ' #');
        return $hash === false ? $value : rtrim(substr($value, 0, $hash));
    }

    private static function quote(string $value): string
    {
        return preg_match('/^[A-Za-z0-9_\-.:\/@+=]*$/', $value) ? $value : '"' . addcslashes($value, "\"\\\n") . '"';
    }
}
