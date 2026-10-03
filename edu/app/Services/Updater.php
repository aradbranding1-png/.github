<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\DB;
use App\Core\Logger;
use App\Core\Migrator;

/**
 * In-panel update system (root admin only).
 *
 * Package format (ZIP):
 *   manifest.json   {"app":"aradedu","version":"1.1.0","min_version":"1.0.0","requires_php":"8.1.0","requires_extensions":[...],
 *                    "files":{"app/Core/DB.php":"<sha256>",...},"deleted":[...],"changelog":[...],"signature":"<base64 ed25519, optional>"}
 *   files/<path>    every file listed in manifest.files
 *
 * Flow: upload → validate (zip, manifest, version, path traversal, checksums, PHP syntax, signature) → plan (new/changed/deleted files,
 * pending migrations) → [backup] → maintenance on → rollback snapshot → atomic file replace → migrations → health check → maintenance off.
 * Any failure restores the snapshot (and the database from the backup when a migration failed).
 */
final class Updater
{
    public const ALLOWED_PREFIXES = ['app/', 'database/', 'public_html/', 'docs/'];
    public const ALLOWED_ROOT = ['VERSION', 'manifest.json', 'cli.php', 'cron.php', '.htaccess', 'README.md', '.env.example'];

    public static function dir(string $sub = ''): string
    {
        $d = STORAGE_PATH . '/updates' . ($sub ? '/' . $sub : '');
        if (!is_dir($d)) mkdir($d, 0750, true);
        return $d;
    }

    public static function isAllowedPath(string $p): bool
    {
        if ($p === '' || str_contains($p, '..') || str_contains($p, '\\') || str_contains($p, "\0") || str_starts_with($p, '/') || preg_match('~^[a-zA-Z]:~', $p)) return false;
        if (preg_match('~(^|/)\.env$|^storage/|(^|/)\.git/~', $p)) return false;
        if (in_array($p, self::ALLOWED_ROOT, true)) return true;
        foreach (self::ALLOWED_PREFIXES as $pre) if (str_starts_with($p, $pre)) {
            // only index.php may be a PHP file inside the web root
            if ($pre === 'public_html/' && preg_match('/\.(php|phtml|phar)$/i', $p) && $p !== 'public_html/index.php') return false;
            return true;
        }
        return false;
    }

    public static function canonicalManifest(array $m): string
    {
        unset($m['signature']);
        ksort($m);
        if (isset($m['files']) && is_array($m['files'])) ksort($m['files']);
        return json_encode($m, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @return array{manifest: ?array, errors: string[], warnings: string[], plan: array} */
    public static function validate(string $zipPath): array
    {
        $errors = []; $warnings = []; $plan = ['new' => [], 'changed' => [], 'unchanged' => 0, 'deleted' => [], 'migrations' => []];
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CHECKCONS) !== true) return ['manifest' => null, 'errors' => ['فایل ZIP معتبر نیست یا آسیب دیده است.'], 'warnings' => [], 'plan' => $plan];
        $total = 0; $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $st = $zip->statIndex($i);
            $name = (string)$st['name'];
            $total += (int)$st['size'];
            if (str_contains($name, '..') || str_starts_with($name, '/') || str_contains($name, '\\') || str_contains($name, "\0") || preg_match('~^[a-zA-Z]:~', $name)) { $errors[] = 'مسیر غیرمجاز در بسته (Path Traversal): ' . $name; continue; }
            if ($zip->getExternalAttributesIndex($i, $opsys, $attr) && $opsys === \ZipArchive::OPSYS_UNIX && ((($attr >> 16) & 0170000) === 0120000)) { $errors[] = 'لینک نمادین (symlink) مجاز نیست: ' . $name; continue; }
            if (str_starts_with($name, '__MACOSX') || str_ends_with($name, '.DS_Store')) continue;
            $entries[$name] = $i;
        }
        if ($total > 600 * 1048576) $errors[] = 'حجم بسته بیش از حد مجاز است.';
        $mjson = $zip->getFromName('manifest.json');
        $m = $mjson ? json_decode($mjson, true) : null;
        if (!is_array($m)) { $zip->close(); return ['manifest' => null, 'errors' => array_merge($errors, ['فایل manifest.json یافت نشد یا معتبر نیست.']), 'warnings' => $warnings, 'plan' => $plan]; }

        $cur = app_version();
        if (($m['app'] ?? '') !== 'aradedu') $errors[] = 'این بسته متعلق به سامانه آموزش آراد نیست.';
        if (!preg_match('/^\d+\.\d+\.\d+$/', (string)($m['version'] ?? ''))) $errors[] = 'نسخه بسته معتبر نیست.';
        elseif (version_compare((string)$m['version'], $cur, '<=')) $errors[] = 'نسخه بسته (' . $m['version'] . ') باید از نسخه فعلی (' . $cur . ') بالاتر باشد.';
        if (!empty($m['min_version']) && version_compare($cur, (string)$m['min_version'], '<')) $errors[] = 'این بسته حداقل به نسخه ' . $m['min_version'] . ' نیاز دارد.';
        if (!empty($m['requires_php']) && version_compare(PHP_VERSION, (string)$m['requires_php'], '<')) $errors[] = 'این نسخه به PHP ' . $m['requires_php'] . ' یا بالاتر نیاز دارد.';
        foreach ((array)($m['requires_extensions'] ?? []) as $ext) if (!extension_loaded((string)$ext)) $errors[] = 'افزونه PHP «' . $ext . '» روی سرور فعال نیست.';

        // signature (Ed25519)
        $pub = (string)env('UPDATE_PUBLIC_KEY', '');
        $sig = (string)($m['signature'] ?? '');
        if ($sig !== '' && $pub !== '' && function_exists('sodium_crypto_sign_verify_detached')) {
            $ok = false;
            try { $ok = sodium_crypto_sign_verify_detached(base64_decode($sig, true) ?: '', self::canonicalManifest($m), base64_decode($pub, true) ?: ''); } catch (\Throwable) {}
            if (!$ok) $errors[] = 'امضای دیجیتال بسته معتبر نیست.';
        } elseif (setting('update_require_signature') === '1') {
            $errors[] = 'بسته فاقد امضای دیجیتال معتبر است (تنظیمات: امضا الزامی است).';
        } else {
            $warnings[] = $sig === '' ? 'بسته امضای دیجیتال ندارد؛ فقط Checksum فایل‌ها بررسی شد.' : 'کلید عمومی امضا (UPDATE_PUBLIC_KEY) تنظیم نشده؛ امضا بررسی نشد.';
        }

        $files = (array)($m['files'] ?? []);
        if (!$files) $errors[] = 'فهرست فایل‌های بسته خالی است.';
        foreach ($files as $path => $hash) {
            $path = (string)$path;
            if (!self::isAllowedPath($path)) { $errors[] = 'مسیر فایل مجاز نیست: ' . $path; continue; }
            $zname = 'files/' . $path;
            if (!isset($entries[$zname])) { $errors[] = 'فایل در بسته موجود نیست: ' . $path; continue; }
            $content = $zip->getFromIndex($entries[$zname]);
            if ($content === false || !hash_equals((string)$hash, hash('sha256', $content))) { $errors[] = 'Checksum نامعتبر: ' . $path; continue; }
            if (str_ends_with($path, '.php')) {
                try { token_get_all($content, TOKEN_PARSE); } catch (\Throwable $e) { $errors[] = 'خطای نحوی PHP در ' . $path . ': ' . $e->getMessage(); continue; }
            }
            $target = BASE_PATH . '/' . $path;
            if (!is_file($target)) $plan['new'][] = $path;
            elseif (hash_file('sha256', $target) !== $hash) $plan['changed'][] = $path;
            else $plan['unchanged']++;
            if (preg_match('~^database/migrations/([^/]+)\.php$~', $path, $mm)) $migs[] = $mm[1];
        }
        foreach ($entries as $name => $_) {
            if ($name === 'manifest.json' || str_ends_with($name, '/')) continue;
            if (str_starts_with($name, 'files/') && !isset($files[substr($name, 6)])) $errors[] = 'فایل خارج از manifest در بسته: ' . $name;
            elseif (!str_starts_with($name, 'files/')) $warnings[] = 'فایل اضافه نادیده گرفته می‌شود: ' . $name;
        }
        // deletions: explicit + files of the previous release that are gone in the new one
        $deleted = array_map('strval', (array)($m['deleted'] ?? []));
        $oldManifest = is_file(BASE_PATH . '/manifest.json') ? json_decode((string)file_get_contents(BASE_PATH . '/manifest.json'), true) : null;
        // a partial package carries only the changed files: nothing is deleted except what it lists explicitly
        if (empty($m['partial']) && is_array($oldManifest['files'] ?? null)) foreach (array_keys($oldManifest['files']) as $of) if (!isset($files[$of])) $deleted[] = $of;
        if (!empty($m['partial'])) {
            if (empty($m['min_version'])) $errors[] = 'بسته جزئی بدون حداقل نسخه معتبر نیست.';
            elseif (version_compare($cur, (string)$m['min_version'], '<')) $errors[] = 'این بسته فقط تغییرات نسبت به نسخه ' . $m['min_version'] . ' را دارد؛ ابتدا بروزرسانی‌های قبلی را به ترتیب نصب کنید.';
        }
        foreach (array_unique($deleted) as $d) {
            if (!self::isAllowedPath($d) || in_array($d, ['public_html/index.php', 'VERSION'], true)) { $warnings[] = 'حذف فایل نادیده گرفته شد: ' . $d; continue; }
            if (is_file(BASE_PATH . '/' . $d)) $plan['deleted'][] = $d;
        }
        // migrations that will run
        $done = DB::column("SELECT migration FROM migrations WHERE status = 'success'");
        $plan['migrations'] = array_values(array_diff(array_unique($migs ?? []), $done));
        sort($plan['migrations']);
        $zip->close();
        if (!is_writable(BASE_PATH . '/app') || !is_writable(BASE_PATH . '/public_html')) $errors[] = 'پوشه‌های app یا public_html قابل نوشتن نیستند (Permission).';
        $free = @disk_free_space(BASE_PATH);
        if ($free !== false && $free < 3 * $total + 50 * 1048576) $errors[] = 'فضای دیسک برای بروزرسانی کافی نیست.';
        return ['manifest' => $m, 'errors' => array_values(array_unique($errors)), 'warnings' => array_values(array_unique($warnings)), 'plan' => $plan];
    }

    public static function upload(array $file): int
    {
        if (($file['error'] ?? 4) !== UPLOAD_ERR_OK) throw new \App\Core\HttpException(422, \App\Core\Upload::errorMessage((int)($file['error'] ?? 4)));
        if (strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION)) !== 'zip') throw new \App\Core\HttpException(422, 'فقط فایل ZIP مجاز است.');
        $id = DB::insert('system_updates', ['package_name' => \App\Core\Upload::cleanName((string)$file['name']), 'from_version' => app_version(), 'status' => 'uploaded', 'started_by' => Auth::id(), 'created_at' => now()]);
        $dest = self::dir('packages') . '/' . $id . '.zip';
        $ok = PHP_SAPI === 'cli' ? copy($file['tmp_name'], $dest) : move_uploaded_file($file['tmp_name'], $dest);
        if (!$ok) throw new \RuntimeException('cannot store package');
        $v = self::validate($dest);
        DB::update('system_updates', [
            'package_sha256' => hash_file('sha256', $dest), 'to_version' => $v['manifest']['version'] ?? null,
            'status' => $v['errors'] ? 'fail' : 'validated', 'plan_json' => json_encode($v, JSON_UNESCAPED_UNICODE),
            'error' => $v['errors'] ? implode("\n", $v['errors']) : null,
        ], 'id = ?', [$id]);
        Audit::log('updates.upload', 'update', $id, $v['errors'] ? 'fail' : 'success', ['version' => $v['manifest']['version'] ?? null, 'errors' => count($v['errors'])]);
        return $id;
    }

    private static function log(int $id, string $line): void
    {
        DB::run('UPDATE system_updates SET log_text = CONCAT(COALESCE(log_text, \'\'), ?) WHERE id = ?', ['[' . date('H:i:s') . '] ' . $line . "\n", $id]);
    }

    /** Apply a validated update. Returns final status. */
    public static function apply(int $id, bool $withBackup): string
    {
        @set_time_limit(0);
        ignore_user_abort(true);
        $u = DB::find('system_updates', $id);
        if (!$u || $u['status'] !== 'validated') throw new \App\Core\HttpException(400, 'این بسته آماده نصب نیست.');
        $pkg = self::dir('packages') . '/' . $id . '.zip';
        if (!is_file($pkg) || hash_file('sha256', $pkg) !== $u['package_sha256']) throw new \App\Core\HttpException(400, 'فایل بسته تغییر کرده یا حذف شده است.');
        $v = self::validate($pkg); // re-validate right before applying
        if ($v['errors']) { DB::update('system_updates', ['status' => 'fail', 'error' => implode("\n", $v['errors'])], 'id = ?', [$id]); return 'fail'; }
        $m = $v['manifest'];
        $before = Health::run();
        DB::update('system_updates', ['status' => 'running', 'with_backup' => $withBackup ? 1 : 0, 'started_at' => now(), 'health_before' => json_encode($before, JSON_UNESCAPED_UNICODE), 'started_by' => Auth::id()], 'id = ?', [$id]);
        self::log($id, 'شروع بروزرسانی از ' . app_version() . ' به ' . $m['version']);
        if (in_array('error', array_column(array_filter($before, fn($c) => in_array($c['key'], ['database', 'storage'], true)), 'status'), true)) {
            DB::update('system_updates', ['status' => 'fail', 'error' => 'Health Check پیش از بروزرسانی ناموفق بود (دیتابیس/Storage).', 'finished_at' => now()], 'id = ?', [$id]);
            return 'fail';
        }
        $backup = null;
        if ($withBackup) {
            $backup = Backup::create('pre_update', 'پیش از بروزرسانی به نسخه ' . $m['version']);
            DB::update('system_updates', ['backup_id' => $backup['id']], 'id = ?', [$id]);
            self::log($id, 'پشتیبان تهیه شد: ' . $backup['filename']);
        } else {
            self::log($id, 'تهیه پشتیبان توسط مدیر کل غیرفعال شد.');
        }

        file_put_contents(STORAGE_PATH . '/maintenance.flag', 'update ' . $id);
        $snap = self::dir('rollback/' . $id);
        $newFiles = [];
        $zip = new \ZipArchive();
        $zip->open($pkg);
        try {
            // 1) snapshot every file that will be overwritten or deleted
            foreach (array_merge($v['plan']['changed'], $v['plan']['deleted'], ['VERSION', 'manifest.json']) as $p) {
                $src = BASE_PATH . '/' . $p;
                if (!is_file($src)) continue;
                $dst = $snap . '/files/' . $p;
                if (!is_dir(dirname($dst))) mkdir(dirname($dst), 0750, true);
                copy($src, $dst);
            }
            file_put_contents($snap . '/new.json', json_encode($v['plan']['new']));
            self::log($id, 'نسخه برگشت (snapshot) از ' . (count($v['plan']['changed']) + count($v['plan']['deleted'])) . ' فایل تهیه شد.');
            // 2) atomic replace (write temp file then rename)
            foreach ($m['files'] as $p => $hash) {
                $dest = BASE_PATH . '/' . $p;
                $content = $zip->getFromName('files/' . $p);
                if (is_file($dest) && hash_file('sha256', $dest) === $hash) continue;
                if (!is_dir(dirname($dest))) mkdir(dirname($dest), 0755, true);
                $tmp = $dest . '.upd' . $id;
                if (file_put_contents($tmp, $content) === false || !rename($tmp, $dest)) throw new \RuntimeException('نوشتن فایل ناموفق بود: ' . $p);
                @chmod($dest, 0644);
                if (!is_file($snap . '/files/' . $p) && in_array($p, $v['plan']['new'], true)) $newFiles[] = $p;
            }
            foreach ($v['plan']['deleted'] as $p) @unlink(BASE_PATH . '/' . $p);
            $mNoSig = $m; unset($mNoSig['signature']);
            if (!empty($m['partial'])) {
                // keep the full file list of the installation: previous manifest + files of this package - deleted
                $prev = is_file(BASE_PATH . '/manifest.json') ? (json_decode((string)file_get_contents(BASE_PATH . '/manifest.json'), true)['files'] ?? []) : [];
                $all = array_merge(is_array($prev) ? $prev : [], $m['files']);
                foreach ($v['plan']['deleted'] as $p) unset($all[$p]);
                ksort($all);
                $mNoSig['files'] = $all; unset($mNoSig['partial']);
            }
            file_put_contents(BASE_PATH . '/manifest.json', json_encode($mNoSig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            file_put_contents(BASE_PATH . '/VERSION', $m['version'] . "\n");
            if (function_exists('opcache_reset')) @opcache_reset();
            self::log($id, 'فایل‌ها جایگزین شدند: ' . count($v['plan']['new']) . ' جدید، ' . count($v['plan']['changed']) . ' تغییر، ' . count($v['plan']['deleted']) . ' حذف.');
        } catch (\Throwable $e) {
            $zip->close();
            self::restoreSnapshot($id);
            @unlink(STORAGE_PATH . '/maintenance.flag');
            DB::update('system_updates', ['status' => 'rolled_back', 'error' => $e->getMessage(), 'finished_at' => now()], 'id = ?', [$id]);
            self::log($id, 'خطا در جایگزینی فایل‌ها؛ بازگردانی انجام شد.');
            Logger::error('Update file stage failed: ' . $e->getMessage());
            return 'rolled_back';
        }
        $zip->close();

        // 3) migrations — run in a fresh PHP context through the new code when possible
        $res = Migrator::migrate();
        DB::update('system_updates', ['migrations_ran' => json_encode($res['ran'])], 'id = ?', [$id]);
        if ($res['failed']) {
            self::log($id, 'Migration ناموفق: ' . $res['failed'] . ' — ' . $res['error']);
            self::restoreSnapshot($id);
            if ($backup) {
                $keep = self::preserveRows();
                Backup::restoreDatabase($backup);
                self::restoreRows($keep);
                self::log($id, 'دیتابیس از پشتیبان بازیابی شد.');
            }
            @unlink(STORAGE_PATH . '/maintenance.flag');
            DB::update('system_updates', ['status' => 'rolled_back', 'failed_migration' => $res['failed'], 'error' => $res['error'], 'finished_at' => now()], 'id = ?', [$id]);
            return 'rolled_back';
        }
        self::log($id, count($res['ran']) . ' Migration اجرا شد.');

        // 4) post-update boot test through HTTP (the new code boots in a fresh process)
        $boot = self::bootProbe();
        if ($boot === false) {
            self::log($id, 'تست بوت پس از بروزرسانی ناموفق بود؛ بازگردانی فایل‌ها.');
            self::restoreSnapshot($id);
            @unlink(STORAGE_PATH . '/maintenance.flag');
            DB::update('system_updates', ['status' => 'rolled_back', 'error' => 'Application Boot پس از بروزرسانی ناموفق بود.', 'finished_at' => now()], 'id = ?', [$id]);
            return 'rolled_back';
        }
        self::log($id, $boot === true ? 'تست بوت برنامه (HTTP) موفق بود.' : 'تست بوت HTTP در این سرور امکان‌پذیر نبود؛ بررسی نحوی PHP پیش‌تر انجام شده است.');
        @unlink(STORAGE_PATH . '/maintenance.flag');
        DB::update('system_updates', ['status' => 'success', 'finished_at' => now(), 'health_after' => null], 'id = ?', [$id]);
        \App\Core\Settings::clear();
        Audit::log('updates.apply', 'update', $id, 'success', ['to' => $m['version'], 'migrations' => $res['ran']]);
        return 'success';
    }

    /** true = boot OK, false = boot failed, null = cannot probe from this server */
    public static function bootProbe(): ?bool
    {
        if (!extension_loaded('curl') || PHP_SAPI === 'cli') return null;
        $ch = curl_init(url('/api/v1/health'));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0, CURLOPT_HTTPHEADER => ['Accept: application/json', 'X-Update-Probe: 1']]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false || $code === 0) return null;
        $j = json_decode((string)$body, true);
        return $code === 200 && is_array($j) && !empty($j['ok']);
    }

    public static function restoreSnapshot(int $id): void
    {
        $snap = self::dir('rollback/' . $id);
        $filesDir = $snap . '/files';
        if (is_dir($filesDir)) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($filesDir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if (!$f->isFile()) continue;
                $rel = substr($f->getPathname(), strlen($filesDir) + 1);
                $dest = BASE_PATH . '/' . $rel;
                if (!is_dir(dirname($dest))) mkdir(dirname($dest), 0755, true);
                copy($f->getPathname(), $dest . '.rb');
                rename($dest . '.rb', $dest);
            }
        }
        $new = is_file($snap . '/new.json') ? (json_decode((string)file_get_contents($snap . '/new.json'), true) ?: []) : [];
        foreach ($new as $p) if (self::isAllowedPath($p) && $p !== 'public_html/index.php') @unlink(BASE_PATH . '/' . $p);
        if (function_exists('opcache_reset')) @opcache_reset();
    }

    /** Manual rollback of a completed update (files; optionally database from its backup) */
    public static function rollback(int $id, bool $restoreDb): void
    {
        $u = DB::find('system_updates', $id);
        if (!$u || !in_array($u['status'], ['success', 'fail'], true)) throw new \App\Core\HttpException(400, 'این بروزرسانی قابل بازگردانی نیست.');
        file_put_contents(STORAGE_PATH . '/maintenance.flag', 'rollback ' . $id);
        try {
            self::restoreSnapshot($id);
            if ($restoreDb && $u['backup_id'] && ($b = DB::find('backups', (int)$u['backup_id']))) {
                $keep = self::preserveRows();
                Backup::restoreDatabase($b);
                self::restoreRows($keep);
            }
        } finally {
            @unlink(STORAGE_PATH . '/maintenance.flag');
        }
        DB::update('system_updates', ['status' => 'rolled_back', 'finished_at' => now()], 'id = ?', [$id]);
        self::log($id, 'بازگردانی دستی توسط مدیر کل' . ($restoreDb ? ' (همراه با دیتابیس)' : ''));
        Audit::log('updates.rollback', 'update', $id, 'success', ['db' => $restoreDb]);
    }

    /** Keep backup/update registries across a database restore */
    public static function preserveRows(): array
    {
        return ['backups' => DB::all('SELECT * FROM backups'), 'system_updates' => DB::all('SELECT * FROM system_updates')];
    }

    public static function restoreRows(array $keep): void
    {
        foreach ($keep as $table => $rows) {
            foreach ($rows as $r) {
                $cols = array_keys($r);
                DB::run('REPLACE INTO `' . $table . '` (`' . implode('`,`', $cols) . '`) VALUES (' . DB::in($cols) . ')', array_values($r));
            }
        }
    }
}
