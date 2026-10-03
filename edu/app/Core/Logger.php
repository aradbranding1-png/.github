<?php
declare(strict_types=1);

namespace App\Core;

/** File logger. Logs live in storage/logs (outside web root). Secrets are redacted. */
final class Logger
{
    private const REDACT = '/("?(password|passwd|secret|token|api_key|client_secret|authorization|access_token|refresh_token|_token)"?\s*[:=]\s*)("[^"]*"|\'[^\']*\'|[^\s,&]+)/i';

    public static function write(string $level, string $message, array $context = []): string
    {
        $id = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
        $dir = STORAGE_PATH . '/logs';
        if (!is_dir($dir)) @mkdir($dir, 0750, true);
        $ctx = $context ? ' ' . json_encode(self::scrub($context), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
        $line = sprintf("[%s] %s.%s [%s] %s%s\n", date('Y-m-d H:i:s'), env('APP_ENV', 'production'), strtoupper($level), $id, self::redact($message), self::redact($ctx));
        @file_put_contents($dir . '/app-' . date('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);
        return $id;
    }

    public static function error(string $m, array $c = []): string { return self::write('error', $m, $c); }
    public static function warning(string $m, array $c = []): string { return self::write('warning', $m, $c); }
    public static function info(string $m, array $c = []): string { return self::write('info', $m, $c); }

    public static function redact(string $s): string
    {
        return (string)preg_replace(self::REDACT, '$1[REDACTED]', $s);
    }

    private static function scrub(array $a): array
    {
        foreach ($a as $k => $v) {
            if (is_string($k) && preg_match('/pass|secret|token|key|authorization/i', $k)) $a[$k] = '[REDACTED]';
            elseif (is_array($v)) $a[$k] = self::scrub($v);
        }
        return $a;
    }
}
