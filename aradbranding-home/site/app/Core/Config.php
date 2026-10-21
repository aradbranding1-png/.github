<?php

declare(strict_types=1);

namespace App\Core;

final class Config
{
    /** @param array<string, mixed> $items */
    private function __construct(private array $items)
    {
    }

    public static function load(string $dir): self
    {
        $items = [];
        foreach (glob($dir . '/*.php') ?: [] as $file) {
            $items[basename($file, '.php')] = require $file;
        }
        return new self($items);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->items;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }
        return $value;
    }
}
