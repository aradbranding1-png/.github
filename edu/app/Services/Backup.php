<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\DB;
use App\Core\Logger;

/**
 * Backups are stored in storage/backups (outside web root), never reachable by URL.
 * Types: db (database only), pre_update (database + config + application code), full (database + config + code + uploaded files)
 */
final class Backup
{
    public static function dir(): string
    {
        $d = STORAGE_PATH . '/backups';
        if (!is_dir($d)) mkdir($d, 0750, true);
        return $d;
    }

    /** Stream a full SQL dump (one statement per line) to a file handle */
    public static function dumpDatabase(string $sqlFile): void
    {
        $pdo = DB::pdo();
        $fh = fopen($sqlFile, 'wb');
        fwrite($fh, "-- AradEdu database dump " . date('c') . " v" . app_version() . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n");
        $tables = DB::column("SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE' ORDER BY table_name");
        foreach ($tables as $t) {
            $t = DB::ident((string)$t);
            $create = DB::one("SHOW CREATE TABLE `$t`");
            $ddl = (string)array_values($create)[1];
            fwrite($fh, "DROP TABLE IF EXISTS `$t`;\n" . preg_replace('/\s*\R\s*/', ' ', $ddl) . ";\n");
            $st = $pdo->query("SELECT * FROM `$t`");
            $batch = [];
            $cols = null;
            while ($row = $st->fetch(\PDO::FETCH_ASSOC)) {
                $cols ??= '`' . implode('`,`', array_keys($row)) . '`';
                $batch[] = '(' . implode(',', array_map(fn($v) => $v === null ? 'NULL' : (is_int($v) || is_float($v) ? (string)$v : $pdo->quote((string)$v)), $row)) . ')';
                if (count($batch) >= 200) { fwrite($fh, "INSERT INTO `$t` ($cols) VALUES " . implode(',', $batch) . ";\n"); $batch = []; }
            }
            if ($batch) fwrite($fh, "INSERT INTO `$t` ($cols) VALUES " . implode(',', $batch) . ";\n");
        }
        fwrite($fh, "SET FOREIGN_KEY_CHECKS=1;\n");
        fclose($fh);
    }

    public static function create(string $type = 'db', string $note = ''): array
    {
        @set_time_limit(0);
        $type = in_array($type, ['db', 'pre_update', 'full'], true) ? $type : 'db';
        $name = 'backup-' . date('Ymd-His') . '-' . $type . '-' . substr(bin2hex(random_bytes(3)), 0, 6) . '.zip';
        $path = self::dir() . '/' . $name;
        $tmpSql = STORAGE_PATH . '/tmp/dump-' . bin2hex(random_bytes(6)) . '.sql';
        try {
            self::dumpDatabase($tmpSql);
            $zip = new \ZipArchive();
            if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) throw new \RuntimeException('cannot create zip');
            $zip->addFile($tmpSql, 'database.sql');
            $zip->addFromString('info.json', json_encode(['app' => 'aradedu', 'version' => app_version(), 'type' => $type, 'created_at' => date('c'), 'php' => PHP_VERSION], JSON_PRETTY_PRINT));
            if ($type !== 'db') {
                if (is_file(BASE_PATH . '/.env')) $zip->addFile(BASE_PATH . '/.env', 'config/.env');
                foreach (['VERSION', 'manifest.json', '.htaccess', 'cli.php', 'cron.php'] as $f) if (is_file(BASE_PATH . '/' . $f)) $zip->addFile(BASE_PATH . '/' . $f, 'code/' . $f);
                foreach (['app', 'database', 'public_html'] as $d) self::addDir($zip, BASE_PATH . '/' . $d, 'code/' . $d);
            }
            if ($type === 'full') {
                self::addDir($zip, STORAGE_PATH . '/uploads', 'storage/uploads');
                self::addDir($zip, STORAGE_PATH . '/avatars', 'storage/avatars');
            }
            $zip->close();
        } finally {
            @unlink($tmpSql);
        }
        @chmod($path, 0640);
        $row = ['filename' => $name, 'type' => $type, 'size' => (int)filesize($path), 'sha256' => hash_file('sha256', $path), 'app_version' => app_version(), 'status' => 'success', 'note' => mb_substr($note, 0, 250) ?: null, 'created_by' => Auth::id(), 'created_at' => now()];
        $row['id'] = DB::insert('backups', $row);
        Audit::log('backups.create', 'backup', $row['id'], 'success', ['type' => $type, 'size' => $row['size']]);
        return $row;
    }

    private static function addDir(\ZipArchive $zip, string $dir, string $prefix): void
    {
        if (!is_dir($dir)) return;
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::LEAVES_ONLY);
        foreach ($it as $f) {
            if (!$f->isFile() || $f->isLink()) continue;
            $rel = substr($f->getPathname(), strlen($dir) + 1);
            $zip->addFile($f->getPathname(), $prefix . '/' . str_replace('\\', '/', $rel));
        }
    }

    public static function path(array $b): string
    {
        return self::dir() . '/' . basename((string)$b['filename']);
    }

    /** Restore the database from a backup (all tables are replaced). */
    public static function restoreDatabase(array $b): int
    {
        @set_time_limit(0);
        $zip = new \ZipArchive();
        if ($zip->open(self::path($b)) !== true) throw new \RuntimeException('backup archive not readable');
        $stream = $zip->getStream('database.sql');
        if (!$stream) throw new \RuntimeException('database.sql missing');
        $pdo = DB::pdo();
        $n = 0; $buf = '';
        while (($line = fgets($stream)) !== false) {
            $buf .= $line;
            if (!str_ends_with(rtrim($buf, "\r\n"), ';')) continue;
            $stmt = trim($buf);
            $buf = '';
            if ($stmt === '' || str_starts_with($stmt, '--')) continue;
            $pdo->exec($stmt);
            $n++;
        }
        fclose($stream);
        $zip->close();
        \App\Core\Settings::clear();
        Logger::info('Database restored from backup ' . $b['filename'] . " ($n statements)");
        return $n;
    }

    /** Restore application code + config from a pre_update/full backup (used by update rollback). */
    public static function restoreCode(array $b): int
    {
        $zip = new \ZipArchive();
        if ($zip->open(self::path($b)) !== true) throw new \RuntimeException('backup archive not readable');
        $n = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string)$zip->getNameIndex($i);
            if (!str_starts_with($name, 'code/') || str_contains($name, '..')) continue;
            $rel = substr($name, 5);
            $dest = BASE_PATH . '/' . $rel;
            if (!is_dir(dirname($dest))) mkdir(dirname($dest), 0755, true);
            file_put_contents($dest . '.rtmp', (string)$zip->getFromIndex($i));
            rename($dest . '.rtmp', $dest);
            $n++;
        }
        $zip->close();
        if (function_exists('opcache_reset')) @opcache_reset();
        return $n;
    }

    /** Keep only the newest N backups of each type */
    public static function prune(int $keep = 10): int
    {
        $n = 0;
        foreach (['db', 'pre_update', 'full'] as $t) {
            $old = DB::all('SELECT * FROM backups WHERE type = ? ORDER BY id DESC LIMIT 1000 OFFSET ' . (int)$keep, [$t]);
            foreach ($old as $b) { @unlink(self::path($b)); DB::delete('backups', 'id = ?', [$b['id']]); $n++; }
        }
        return $n;
    }
}
