<?php

declare(strict_types=1);

namespace App\Core\Update;

use App\Core\Cache\Cache;
use App\Core\Config;
use App\Core\Database\Migrator;
use App\Core\Database\PartitionManager;
use App\Core\Db\Connection;
use App\Core\Http\Middleware\MaintenanceMode;
use Throwable;
use ZipArchive;

/**
 * In-app update from a release ZIP (no File Manager needed):
 *   validate → backup (database + code) → maintenance → extract → migrate → health check → done.
 * On failure after files were written, the previous code is restored automatically
 * (migrations are forward-only; the database backup is kept for a manual restore).
 * Never touches .env, storage/, backups/, vendor/ or uploaded media.
 */
final class UpdateManager
{
    private const PROTECTED = ['.env', 'storage/', 'backups/', 'vendor/', 'public_html/media/', '.git/'];
    private const CODE_PATHS = ['app', 'bootstrap', 'config', 'routes', 'database', 'bin', 'tests', 'docs', 'public_html',
        'cron.php', 'VERSION', 'CHANGELOG.md', 'README.md', 'composer.json', 'phpunit.xml', '.env.example', '.gitignore'];
    private const MAX_ENTRIES = 5000;
    private const MAX_BYTES = 150 * 1024 * 1024;

    /** @var list<string> */
    private array $log = [];

    public function __construct(private Connection $db, private Cache $cache, private Config $config)
    {
    }

    public function currentVersion(): string
    {
        return trim((string) @file_get_contents(BASE_PATH . '/VERSION')) ?: 'unknown';
    }

    /**
     * Reads a package without changing anything.
     * @return array{version: string, prefix: string, files: list<string>, notes: string}
     */
    public function inspect(string $zipPath): array
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new UpdateException('فایل ZIP معتبر نیست.');
        }
        try {
            if ($zip->numFiles > self::MAX_ENTRIES) {
                throw new UpdateException('تعداد فایل‌های بسته بیش از حد مجاز است.');
            }
            $names = [];
            $total = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $st = $zip->statIndex($i);
                $names[] = (string) $st['name'];
                $total += (int) $st['size'];
            }
            if ($total > self::MAX_BYTES) {
                throw new UpdateException('حجم بسته بیش از حد مجاز است.');
            }
            // Accept both "VERSION" at the root and a single wrapping folder ("sadt/VERSION").
            $prefix = '';
            if (!in_array('VERSION', $names, true)) {
                foreach ($names as $n) {
                    if (preg_match('~^([^/]+/)VERSION$~', $n, $m)) {
                        $prefix = $m[1];
                        break;
                    }
                }
            }
            if (!in_array($prefix . 'VERSION', $names, true) || !in_array($prefix . 'bootstrap/app.php', $names, true)) {
                throw new UpdateException('این فایل، بسته بروزرسانی سامانه توسعه تجارت نیست (VERSION یا bootstrap پیدا نشد).');
            }
            $version = trim((string) $zip->getFromName($prefix . 'VERSION'));
            if (!preg_match('/^[0-9A-Za-z.\-]{1,40}$/', $version)) {
                throw new UpdateException('شماره نسخه داخل بسته معتبر نیست.');
            }
            $files = [];
            foreach ($names as $n) {
                if ($prefix !== '' && !str_starts_with($n, $prefix)) {
                    continue;
                }
                $rel = substr($n, strlen($prefix));
                if ($rel === '' || str_ends_with($rel, '/')) {
                    continue;
                }
                if (!self::safePath($rel)) {
                    throw new UpdateException('مسیر غیرمجاز در بسته: ' . $rel);
                }
                if (!self::isProtected($rel)) {
                    $files[] = $rel;
                }
            }
            $notes = '';
            $changelog = $zip->getFromName($prefix . 'CHANGELOG.md');
            if (is_string($changelog) && preg_match('/^##\s*' . preg_quote($version, '/') . '\b.*?\R(.*?)(?=^##\s|\z)/ms', $changelog, $m)) {
                $notes = trim($m[1]);
            }
            return ['version' => $version, 'prefix' => $prefix, 'files' => $files, 'notes' => $notes];
        } finally {
            $zip->close();
        }
    }

    /** @return array{ok: bool, message: string, from: string, to: string, notes: string, log: list<string>, backup: ?string} */
    public function apply(string $zipPath, int $adminId, bool $fullBackup = false): array
    {
        @set_time_limit(600);
        @ignore_user_abort(true);
        $this->db->allowLongRunning();
        $from = $this->currentVersion();
        $info = $this->inspect($zipPath);
        $historyId = $this->db->insert(
            'INSERT INTO update_history (from_version, to_version, status, admin_id, started_at) VALUES (?, ?, ?, ?, NOW(3))',
            [$from, $info['version'], 'running', $adminId]
        );
        $backupDir = BASE_PATH . '/backups/update-' . gmdate('Ymd-His') . '-' . preg_replace('/[^0-9A-Za-z.\-]/', '', $from);
        $written = false;
        $result = ['ok' => false, 'message' => '', 'from' => $from, 'to' => $info['version'], 'notes' => $info['notes'], 'log' => [], 'backup' => $backupDir];

        try {
            if (!@mkdir($backupDir, 0750, true) && !is_dir($backupDir)) {
                throw new UpdateException('پوشه backups قابل نوشتن نیست.');
            }
            if ($fullBackup) {
                $rows = (new DatabaseBackup($this->db))->dump($backupDir . '/database.sql.gz');
                $this->log("Database backup: {$rows} rows");
            } else {
                $this->log('Database backup: skipped (switched off)');
            }
            // A small code snapshot is always taken: it is what makes automatic rollback possible.
            $this->zipCode($backupDir . '/code.zip');
            $this->log('Code snapshot: code.zip');

            file_put_contents(MaintenanceMode::FILE, json_encode(['secret' => bin2hex(random_bytes(12)), 'retry' => 60, 'since' => time()]));
            $this->log('Maintenance mode ON');

            $written = true;
            $n = $this->extract($zipPath, $info['prefix'], $info['files']);
            $this->log("Files written: {$n}");
            if (function_exists('opcache_reset')) {
                @opcache_reset();
            }

            $ran = (new Migrator($this->db, BASE_PATH . '/database/migrations'))->migrate();
            $this->log('Migrations applied: ' . (count($ran) ? implode(', ', $ran) : 'none'));
            $parts = (new PartitionManager($this->db, (array) $this->config->get('database.partitioned', []), 3))->ensure();
            $this->log('Partitions created: ' . count($parts));

            $this->db->scalar('SELECT 1');
            if ((new Migrator($this->db, BASE_PATH . '/database/migrations'))->pendingCount() !== 0) {
                throw new UpdateException('بعد از بروزرسانی، Migration اجرا‌نشده باقی ماند.');
            }
            $this->cache->store()->flush();
            $this->log('Cache cleared · health check OK');
            $hardened = \App\Core\Security\FilePermissions::harden();
            if ($hardened !== []) {
                $this->log('Permissions tightened (600/700): ' . implode(', ', $hardened));
            }
            $result['ok'] = true;
            $result['message'] = "سامانه از نسخه {$from} به {$info['version']} بروزرسانی شد.";
            $status = 'success';
        } catch (Throwable $e) {
            $this->log('ERROR: ' . $e->getMessage());
            $status = 'failed';
            if ($written && is_file($backupDir . '/code.zip')) {
                try {
                    $this->restoreCode($backupDir . '/code.zip');
                    if (function_exists('opcache_reset')) {
                        @opcache_reset();
                    }
                    $this->log('Previous code restored');
                    $status = 'rolled_back';
                } catch (Throwable $r) {
                    $this->log('ROLLBACK FAILED: ' . $r->getMessage());
                }
            }
            $result['message'] = $e instanceof UpdateException ? $e->getMessage() : 'بروزرسانی ناموفق بود؛ جزئیات در گزارش آمده است.';
        } finally {
            @unlink(MaintenanceMode::FILE);
            $this->log('Maintenance mode OFF');
        }

        if (!$fullBackup && $status === 'success') {
            // No full backup was requested: drop the temporary code snapshot.
            @unlink($backupDir . '/code.zip');
            @rmdir($backupDir);
            $backupDir = null;
            $result['backup'] = null;
        }
        $result['log'] = $this->log;
        $this->db->exec(
            'UPDATE update_history SET status = ?, backup_dir = ?, log = ?, finished_at = NOW(3) WHERE id = ?',
            [$status, $backupDir, implode("\n", $this->log), $historyId]
        );
        return $result;
    }

    /** @return list<array<string, mixed>> */
    public function history(): array
    {
        return $this->db->select('SELECT * FROM update_history ORDER BY id DESC LIMIT 20');
    }

    private function extract(string $zipPath, string $prefix, array $files): int
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new UpdateException('فایل ZIP باز نشد.');
        }
        $count = 0;
        try {
            foreach ($files as $rel) {
                $data = $zip->getFromName($prefix . $rel);
                if ($data === false) {
                    throw new UpdateException('خواندن فایل از بسته ممکن نشد: ' . $rel);
                }
                $target = BASE_PATH . '/' . $rel;
                $dir = dirname($target);
                if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
                    throw new UpdateException('ساخت پوشه ممکن نشد: ' . dirname($rel));
                }
                $tmp = $target . '.upd-' . bin2hex(random_bytes(3));
                if (file_put_contents($tmp, $data) === false || !rename($tmp, $target)) {
                    @unlink($tmp);
                    throw new UpdateException('نوشتن فایل ممکن نشد: ' . $rel);
                }
                if ($rel === 'bin/console') {
                    @chmod($target, 0755);
                }
                $count++;
            }
        } finally {
            $zip->close();
        }
        return $count;
    }

    /**
     * Packs the code currently on the host into a ZIP that can be fed straight back to apply().
     * Same file set as the rollback snapshot: never media, backups, storage, vendor, .env or .git.
     *
     * @return int number of files written
     */
    public function exportCurrent(string $file): int
    {
        @set_time_limit(600);
        return $this->zipCode($file, 'ساخت فایل خروجی ممکن نشد.');
    }

    private function zipCode(string $file, string $openError = 'ساخت نسخه پشتیبان کد ممکن نشد.'): int
    {
        $zip = new ZipArchive();
        if ($zip->open($file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new UpdateException($openError);
        }
        $count = 0;
        foreach (self::CODE_PATHS as $path) {
            $full = BASE_PATH . '/' . $path;
            if (is_file($full)) {
                $zip->addFile($full, $path);
                $count++;
                continue;
            }
            if (!is_dir($full)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($full, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                $rel = substr($f->getPathname(), strlen(BASE_PATH) + 1);
                if ($f->isFile() && !self::isProtected($rel)) {
                    $zip->addFile($f->getPathname(), $rel);
                    $count++;
                }
            }
        }
        if (!$zip->close()) {
            throw new UpdateException($openError);
        }
        return $count;
    }

    private function restoreCode(string $file): void
    {
        $zip = new ZipArchive();
        if ($zip->open($file) !== true) {
            throw new UpdateException('باز کردن نسخه پشتیبان کد ممکن نشد.');
        }
        try {
            if (!$zip->extractTo(BASE_PATH)) {
                throw new UpdateException('بازگردانی کد ممکن نشد.');
            }
        } finally {
            $zip->close();
        }
    }

    private static function safePath(string $rel): bool
    {
        return !str_contains($rel, '\\') && !str_contains($rel, ':') && !str_starts_with($rel, '/')
            && !in_array('..', explode('/', $rel), true) && !str_contains($rel, "\0");
    }

    private static function isProtected(string $rel): bool
    {
        if (str_ends_with($rel, '.gitkeep')) {
            return true;
        }
        foreach (self::PROTECTED as $p) {
            if ($rel === rtrim($p, '/') || str_starts_with($rel, $p)) {
                return true;
            }
        }
        return false;
    }

    private function log(string $line): void
    {
        $this->log[] = gmdate('H:i:s') . '  ' . $line;
    }
}
