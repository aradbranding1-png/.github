<?php

declare(strict_types=1);

namespace App\Core\Security;

/**
 * Keeps secret files readable by the site owner only.
 *
 * .env holds the DB password, APP_KEY, SIGNING_KEY, HEALTH_TOKEN and OPS_TOKEN; backups hold full
 * database dumps. On a shared/multi-user server 0644 lets every local account read them.
 * PHP can chmod a file only when it runs as the file owner (PHP-FPM / suPHP / LSAPI on DirectAdmin),
 * which is exactly the case where 0600 is still readable by the site, so this is always safe.
 */
final class FilePermissions
{
    /** @return list<string> files/dirs that are still group/world accessible */
    public static function exposed(): array
    {
        $bad = [];
        foreach (self::targets() as [$path, $mode]) {
            $perms = @fileperms($path);
            if ($perms !== false && ($perms & 0o077) !== 0) {
                $bad[] = self::rel($path) . ' (' . substr(sprintf('%o', $perms), -3) . ')';
            }
        }
        return $bad;
    }

    /** Tighten what we can. @return list<string> paths changed */
    public static function harden(): array
    {
        $changed = [];
        foreach (self::targets() as [$path, $mode]) {
            $perms = @fileperms($path);
            if ($perms === false || ($perms & 0o077) === 0) {
                continue;
            }
            if (@chmod($path, $mode)) {
                $changed[] = self::rel($path);
            }
        }
        clearstatcache();
        return $changed;
    }

    /** @return list<array{0: string, 1: int}> */
    private static function targets(): array
    {
        $t = [];
        foreach (['.env', '.env.tmp'] as $f) {
            if (is_file(BASE_PATH . '/' . $f)) {
                $t[] = [BASE_PATH . '/' . $f, 0o600];
            }
        }
        $backups = BASE_PATH . '/backups';
        if (is_dir($backups)) {
            $t[] = [$backups, 0o700];
            foreach (glob($backups . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
                $t[] = [$dir, 0o700];
                foreach (glob($dir . '/*') ?: [] as $file) {
                    if (is_file($file)) {
                        $t[] = [$file, 0o600];
                    }
                }
            }
        }
        foreach (['storage/install.key', 'storage/private'] as $f) {
            $p = BASE_PATH . '/' . $f;
            if (file_exists($p)) {
                $t[] = [$p, is_dir($p) ? 0o700 : 0o600];
            }
        }
        return $t;
    }

    private static function rel(string $path): string
    {
        return ltrim(substr($path, strlen(BASE_PATH)), '/');
    }
}
