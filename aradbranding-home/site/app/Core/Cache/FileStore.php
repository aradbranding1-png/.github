<?php

declare(strict_types=1);

namespace App\Core\Cache;

/**
 * File cache for SHARED data only (reference lists, public pages, pricing).
 * Atomic writes via rename; locks via flock. Values must be scalars/arrays.
 */
final class FileStore implements CacheStore
{
    public function __construct(private string $dir)
    {
    }

    public function get(string $key): mixed
    {
        $raw = @file_get_contents($this->path($key));
        if ($raw === false || strlen($raw) < 10) {
            return null;
        }
        $expires = (int) substr($raw, 0, 10);
        if ($expires !== 0 && $expires < time()) {
            @unlink($this->path($key));
            return null;
        }
        $value = @unserialize(substr($raw, 10), ['allowed_classes' => false]);
        return $value === false && substr($raw, 10) !== serialize(false) ? null : $value;
    }

    public function put(string $key, mixed $value, int $ttl): void
    {
        $path = $this->path($key);
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $expires = $ttl > 0 ? time() + $ttl : 0;
        if (@file_put_contents($tmp, sprintf('%010d', $expires) . serialize($value)) !== false) {
            @rename($tmp, $path);
        }
    }

    public function forget(string $key): void
    {
        @unlink($this->path($key));
    }

    public function increment(string $key, int $by = 1, int $ttl = 0): int
    {
        $path = $this->path($key);
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $handle = fopen($path . '.lock', 'c');
        if ($handle === false) {
            throw new \RuntimeException('Cache lock file not writable');
        }
        try {
            flock($handle, LOCK_EX);
            $raw = @file_get_contents($path);
            $current = 0;
            $expires = $ttl > 0 ? time() + $ttl : 0;
            if ($raw !== false && strlen($raw) >= 10) {
                $oldExpires = (int) substr($raw, 0, 10);
                if ($oldExpires === 0 || $oldExpires >= time()) {
                    $current = (int) @unserialize(substr($raw, 10), ['allowed_classes' => false]);
                    $expires = $oldExpires; // keep the original window
                }
            }
            $new = $current + $by;
            $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
            file_put_contents($tmp, sprintf('%010d', $expires) . serialize($new));
            rename($tmp, $path);
            return $new;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function lock(string $key, int $seconds): ?Lock
    {
        $path = $this->path('lock:' . $key) . '.lock';
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $handle = fopen($path, 'c');
        if ($handle === false || !flock($handle, LOCK_EX | LOCK_NB)) {
            if ($handle !== false) {
                fclose($handle);
            }
            return null;
        }
        return new Lock(static function () use ($handle): void {
            if (is_resource($handle)) {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
        });
    }

    public function flush(): void
    {
        if (!is_dir($this->dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if ($item->getFilename() === '.gitkeep') {
                continue;
            }
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
    }

    /** Remove expired files. Called by cron, never on the request path. */
    public function prune(int $maxFiles = 50_000): int
    {
        $removed = 0;
        $seen = 0;
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($items as $item) {
            if (++$seen > $maxFiles) {
                break;
            }
            if (!$item->isFile() || str_ends_with($item->getFilename(), '.lock') || $item->getFilename() === '.gitkeep') {
                continue;
            }
            $head = @file_get_contents($item->getPathname(), false, null, 0, 10);
            if ($head !== false && (int) $head !== 0 && (int) $head < time()) {
                @unlink($item->getPathname());
                $removed++;
            }
        }
        return $removed;
    }

    private function path(string $key): string
    {
        $hash = hash('xxh128', $key);
        return $this->dir . '/' . substr($hash, 0, 2) . '/' . substr($hash, 2, 2) . '/' . $hash;
    }
}
