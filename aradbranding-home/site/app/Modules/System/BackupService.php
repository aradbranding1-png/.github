<?php

declare(strict_types=1);

namespace App\Modules\System;

use App\Core\Db\Connection;
use App\Core\Update\DatabaseBackup;

/** Manual and automatic backups (database, optionally code) with retention (doc §76). */
final class BackupService
{
    public const DIR = BASE_PATH . '/backups';

    public function __construct(private Connection $db)
    {
    }

    /** @return string folder name */
    public function create(string $kind = 'manual'): string
    {
        @set_time_limit(900);
        $this->db->allowLongRunning();
        $name = $kind . '-' . gmdate('Ymd-His');
        $dir = self::DIR . '/' . $name;
        if (!@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException('Backups folder is not writable');
        }
        (new DatabaseBackup($this->db))->dump($dir . '/database.sql.gz');
        @file_put_contents($dir . '/VERSION', (string) @file_get_contents(BASE_PATH . '/VERSION'));
        return $name;
    }

    /** @return list<array{name: string, size: int, time: int, has_db: bool, has_code: bool}> newest first */
    public function list(): array
    {
        $out = [];
        foreach (glob(self::DIR . '/*', GLOB_ONLYDIR) ?: [] as $d) {
            $size = 0;
            foreach (glob($d . '/*') ?: [] as $f) {
                $size += (int) @filesize($f);
            }
            $out[] = ['name' => basename($d), 'size' => $size, 'time' => (int) @filemtime($d),
                'has_db' => is_file($d . '/database.sql.gz'), 'has_code' => is_file($d . '/code.zip')];
        }
        usort($out, static fn ($a, $b) => $b['time'] <=> $a['time']);
        return $out;
    }

    public function path(string $name, string $file): ?string
    {
        if (!preg_match('/^[a-z]+-[0-9]{8}-[0-9]{6}(-[0-9A-Za-z.\-]+)?$/', $name) || !in_array($file, ['database.sql.gz', 'code.zip'], true)) {
            return null;
        }
        $p = self::DIR . '/' . $name . '/' . $file;
        return is_file($p) ? $p : null;
    }

    public function delete(string $name): void
    {
        if (!preg_match('/^[a-z]+-[0-9]{8}-[0-9]{6}(-[0-9A-Za-z.\-]+)?$/', $name)) {
            return;
        }
        $dir = self::DIR . '/' . $name;
        foreach (glob($dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($dir);
    }

    /** Remove backups older than $days (the newest one is always kept). */
    public function prune(int $days): int
    {
        $n = 0;
        foreach (array_slice($this->list(), 1) as $b) {
            if ($b['time'] < time() - $days * 86400) {
                $this->delete($b['name']);
                $n++;
            }
        }
        return $n;
    }
}
