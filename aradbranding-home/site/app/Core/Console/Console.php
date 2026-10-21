<?php

declare(strict_types=1);

namespace App\Core\Console;

/** Tiny CLI dispatcher: `php bin/console <command> [args] [--option=value]`. */
final class Console
{
    /** @var array<string, array{description: string, handler: \Closure}> */
    private array $commands = [];

    public function register(string $name, string $description, \Closure $handler): void
    {
        $this->commands[$name] = ['description' => $description, 'handler' => $handler];
    }

    /** @param list<string> $argv */
    public function run(array $argv): int
    {
        $name = $argv[1] ?? 'list';
        if ($name === 'list' || !isset($this->commands[$name])) {
            if ($name !== 'list') {
                self::error("Unknown command: {$name}");
            }
            self::line('سامانه توسعه تجارت · CLI');
            ksort($this->commands);
            foreach ($this->commands as $cmd => $meta) {
                self::line(sprintf('  %-20s %s', $cmd, $meta['description']));
            }
            return $name === 'list' ? 0 : 1;
        }

        $args = [];
        $options = [];
        foreach (array_slice($argv, 2) as $token) {
            if (str_starts_with($token, '--')) {
                [$k, $v] = array_pad(explode('=', substr($token, 2), 2), 2, true);
                $options[$k] = $v;
            } else {
                $args[] = $token;
            }
        }
        return (int) ($this->commands[$name]['handler'])($args, $options);
    }

    public static function line(string $text = ''): void
    {
        fwrite(STDOUT, $text . PHP_EOL);
    }

    public static function error(string $text): void
    {
        fwrite(STDERR, "\033[31m" . $text . "\033[0m" . PHP_EOL);
    }

    public static function status(string $status): string
    {
        return match ($status) {
            'ok' => "\033[32m OK \033[0m",
            'warn' => "\033[33mWARN\033[0m",
            default => "\033[31mFAIL\033[0m",
        };
    }
}
