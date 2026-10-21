<?php

declare(strict_types=1);

namespace App\Core\Log;

/** JSON-lines logger with daily files and automatic secret redaction. */
final class Logger
{
    private const LEVELS = [
        'debug' => 100, 'info' => 200, 'notice' => 250,
        'warning' => 300, 'error' => 400, 'critical' => 500,
    ];

    private static string $requestId = '';
    private int $min;

    public function __construct(private string $dir, string $minLevel = 'info')
    {
        $this->min = self::LEVELS[$minLevel] ?? 200;
    }

    public static function setRequestId(string $id): void
    {
        self::$requestId = $id;
    }

    /** @param array<string, mixed> $context */
    public function log(string $level, string $message, array $context = [], string $channel = 'app'): void
    {
        if ((self::LEVELS[$level] ?? 200) < $this->min) {
            return;
        }
        $line = json_encode([
            'ts' => gmdate('Y-m-d\TH:i:s.v\Z'),
            'level' => $level,
            'msg' => $message,
            'rid' => self::$requestId ?: null,
            'ctx' => self::redact($context),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);

        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0775, true);
        }
        @file_put_contents(
            sprintf('%s/%s-%s.log', $this->dir, $channel, gmdate('Y-m-d')),
            $line . "\n",
            FILE_APPEND | LOCK_EX
        );
    }

    /** @param array<string, mixed> $c */
    public function debug(string $m, array $c = []): void { $this->log('debug', $m, $c); }
    /** @param array<string, mixed> $c */
    public function info(string $m, array $c = []): void { $this->log('info', $m, $c); }
    /** @param array<string, mixed> $c */
    public function warning(string $m, array $c = []): void { $this->log('warning', $m, $c); }
    /** @param array<string, mixed> $c */
    public function error(string $m, array $c = []): void { $this->log('error', $m, $c); }
    /** @param array<string, mixed> $c */
    public function critical(string $m, array $c = []): void { $this->log('critical', $m, $c); }

    /**
     * @param array<mixed> $context
     * @return array<mixed>
     */
    public static function redact(array $context): array
    {
        foreach ($context as $key => $value) {
            if (is_string($key) && preg_match('/pass|secret|token|authorization|cookie|api_?key|otp/i', $key)) {
                $context[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $context[$key] = self::redact($value);
            }
        }
        return $context;
    }
}
