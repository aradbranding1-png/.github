<?php
/**
 * سامانه آموزش آراد — ابزار خط فرمان (فقط CLI)
 *
 *   php cli.php migrate                         اجرای Migrationهای معلق
 *   php cli.php migrate:status                  وضعیت Migrationها
 *   php cli.php health                          Health Check
 *   php cli.php backup [db|pre_update|full]     تهیه پشتیبان
 *   php cli.php restore <backup-id>             بازیابی دیتابیس از پشتیبان
 *   php cli.php root:password <new-password>    بازنشانی رمز مدیر کل (بازیابی دسترسی)
 *   php cli.php update:apply <package.zip> [--no-backup]
 *   php cli.php update:rollback <update-id> [--db]
 *   php cli.php maintenance:on | maintenance:off
 *   php cli.php cache:clear
 *   php cli.php cron [-v]
 *   php cli.php keys:generate                   ساخت کلید امضای بسته‌های بروزرسانی (Ed25519)
 *   php cli.php package:release <version>       ساخت ZIP نصب کامل (dist/)
 *   php cli.php package:update <version> [--min=1.0.0] [--since=x.y.z (only changed files)] [--key=/path/private.key] [--changelog="..."]
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/app/bootstrap.php';

use App\Core\DB;
use App\Core\Migrator;
use App\Services\Backup;
use App\Services\Health;
use App\Services\Updater;

$cmd = $argv[1] ?? 'help';
$args = array_slice($argv, 2);
$opt = function (string $name, ?string $default = null) use ($args): ?string {
    foreach ($args as $a) if (str_starts_with($a, "--$name=")) return substr($a, strlen($name) + 3);
    return in_array("--$name", $args, true) ? '1' : $default;
};
$out = fn(string $s) => fwrite(STDOUT, $s . PHP_EOL);
$needInstalled = function () use ($out) { if (!is_installed()) { $out('System is not installed (.env / storage/installed.lock missing).'); exit(1); } };

/** Files that make up a release (code only — never .env, storage content, dumps or VCS data) */
function release_files(): array
{
    $files = [];
    foreach (['app', 'database', 'public_html', 'docs'] as $d) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(BASE_PATH . '/' . $d, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (!$f->isFile()) continue;
            $rel = str_replace('\\', '/', substr($f->getPathname(), strlen(BASE_PATH) + 1));
            if (preg_match('~(^|/)\.(git|DS_Store)|__MACOSX|\.(bak|old|backup|sql|log|zip|gz|tar|rar|tmp|swp)$|(^|/)\.env$~i', $rel)) continue;
            $files[] = $rel;
        }
    }
    foreach (['VERSION', 'cli.php', 'cron.php', '.htaccess', 'README.md', '.env.example'] as $f) if (is_file(BASE_PATH . '/' . $f)) $files[] = $f;
    sort($files);
    return $files;
}

switch ($cmd) {
    case 'migrate':
        $needInstalled();
        $r = Migrator::migrate();
        $out($r['failed'] ? "FAILED: {$r['failed']} — {$r['error']}" : 'Ran ' . count($r['ran']) . ' migration(s). ' . implode(', ', $r['ran']));
        exit($r['failed'] ? 1 : 0);

    case 'migrate:status':
        $needInstalled();
        foreach (Migrator::status() as $m) $out(str_pad((string)$m['order'], 4) . str_pad($m['status'], 10) . $m['migration']);
        break;

    case 'health':
        $needInstalled();
        foreach (Health::run() as $c) $out(str_pad(strtoupper($c['status']), 9) . str_pad($c['key'], 14) . $c['msg']);
        break;

    case 'backup':
        $needInstalled();
        $b = Backup::create($args[0] ?? 'db', 'CLI');
        $out('Backup created: storage/backups/' . $b['filename'] . ' (' . $b['size'] . ' bytes)');
        break;

    case 'restore':
        $needInstalled();
        $b = DB::find('backups', (int)($args[0] ?? 0));
        if (!$b) { $out('Backup not found'); exit(1); }
        $keep = Updater::preserveRows();
        $n = Backup::restoreDatabase($b);
        Updater::restoreRows($keep);
        $out("Restored $n statements from {$b['filename']}");
        break;

    case 'root:password':
        $needInstalled();
        $pw = (string)($args[0] ?? '');
        if (!App\Core\Auth::passwordStrongEnough($pw)) { $out('Password must be 8+ chars with letters and digits.'); exit(1); }
        $n = DB::update('users', ['password_hash' => App\Core\Auth::hashPassword($pw), 'status' => 'active'], 'is_root = 1');
        App\Core\Audit::log('security.root_password_reset', 'user', null, 'success', ['via' => 'cli']);
        $out($n ? 'Root password updated.' : 'No root user found.');
        break;

    case 'update:apply':
        $needInstalled();
        $zip = (string)($args[0] ?? '');
        if (!is_file($zip)) { $out('Package not found'); exit(1); }
        $id = Updater::upload(['name' => basename($zip), 'tmp_name' => $zip, 'error' => UPLOAD_ERR_OK, 'size' => filesize($zip)]);
        $u = DB::find('system_updates', $id);
        if ($u['status'] !== 'validated') { $out("Validation failed:\n" . $u['error']); exit(1); }
        $status = Updater::apply($id, $opt('no-backup') === null);
        $u = DB::find('system_updates', $id);
        $out("Update #$id: $status\n" . $u['log_text']);
        exit($status === 'success' ? 0 : 1);

    case 'update:rollback':
        $needInstalled();
        Updater::rollback((int)($args[0] ?? 0), $opt('db') !== null);
        $out('Rolled back.');
        break;

    case 'maintenance:on':
        file_put_contents(STORAGE_PATH . '/maintenance.flag', 'cli');
        $out('Maintenance mode ON');
        break;

    case 'maintenance:off':
        @unlink(STORAGE_PATH . '/maintenance.flag');
        $out('Maintenance mode OFF');
        break;

    case 'cache:clear':
        foreach (glob(STORAGE_PATH . '/cache/*.php') ?: [] as $f) @unlink($f);
        if (function_exists('opcache_reset')) @opcache_reset();
        $out('Cache cleared.');
        break;

    case 'cron':
        $needInstalled();
        App\Services\Cron::run(in_array('-v', $args, true));
        break;

    case 'keys:generate':
        $kp = sodium_crypto_sign_keypair();
        $out('UPDATE_PUBLIC_KEY=' . base64_encode(sodium_crypto_sign_publickey($kp)));
        $out('PRIVATE KEY (keep secret, never upload to the server):');
        $out(base64_encode(sodium_crypto_sign_secretkey($kp)));
        break;

    case 'package:release':
    case 'package:update':
        $version = (string)($args[0] ?? '');
        if (!preg_match('/^\d+\.\d+\.\d+$/', $version)) { $out('Usage: php cli.php ' . $cmd . ' <x.y.z>'); exit(1); }
        file_put_contents(BASE_PATH . '/VERSION', $version . "\n");
        $files = release_files();
        $hashes = [];
        foreach ($files as $f) $hashes[$f] = hash_file('sha256', BASE_PATH . '/' . $f);
        // --since=x.y.z : partial package with only the files changed since that release (requires x.y.z installed)
        $since = $cmd === 'package:update' ? $opt('since') : null;
        $deletedFiles = [];
        @mkdir(BASE_PATH . '/dist', 0755, true);
        // full file list of every release is kept, so partial packages can be chained release after release
        file_put_contents(BASE_PATH . "/dist/files-$version.json", json_encode($hashes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        if ($since) {
            $prevFiles = null;
            if (is_file(BASE_PATH . "/dist/files-$since.json")) $prevFiles = json_decode((string)file_get_contents(BASE_PATH . "/dist/files-$since.json"), true);
            elseif (is_file(BASE_PATH . "/dist/aradedu-update-$since.zip")) {
                $pz = new ZipArchive(); $pz->open(BASE_PATH . "/dist/aradedu-update-$since.zip");
                $pm = json_decode((string)$pz->getFromName('manifest.json'), true); $pz->close();
                if (empty($pm['partial'])) $prevFiles = $pm['files'] ?? null;
            }
            if (!is_array($prevFiles)) { $out("Full file list of $since not found (dist/files-$since.json)."); exit(1); }
            $deletedFiles = array_values(array_diff(array_keys($prevFiles), $files));
            $files = array_values(array_filter($files, fn($f) => ($prevFiles[$f] ?? null) !== $hashes[$f] || $f === 'VERSION'));
            $hashes = array_intersect_key($hashes, array_flip($files));
        }
        $manifest = ['app' => 'aradedu', 'version' => $version, 'min_version' => $opt('min', '1.0.0'), 'requires_php' => '8.1.0',
            'requires_extensions' => ['pdo_mysql', 'mbstring', 'json', 'openssl', 'fileinfo', 'zip', 'gd'], 'released_at' => date('c'),
            'changelog' => array_values(array_filter(array_map('trim', explode('|', (string)$opt('changelog', ''))))), 'files' => $hashes, 'deleted' => $deletedFiles];
        if ($since) { $manifest['partial'] = true; $manifest['min_version'] = $since; }
        if ($key = $opt('key')) {
            $sk = base64_decode(trim((string)file_get_contents($key)), true);
            $manifest['signature'] = base64_encode(sodium_crypto_sign_detached(Updater::canonicalManifest($manifest), $sk));
        }
        @mkdir(BASE_PATH . '/dist', 0755, true);
        if ($cmd === 'package:update') {
            $zipPath = BASE_PATH . "/dist/aradedu-update-$version" . ($since ? '-partial' : '') . '.zip';
            @unlink($zipPath);
            $z = new ZipArchive();
            $z->open($zipPath, ZipArchive::CREATE);
            $z->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            foreach ($files as $f) $z->addFile(BASE_PATH . '/' . $f, 'files/' . $f);
            $z->close();
        } else {
            $m = $manifest; unset($m['signature']);
            file_put_contents(BASE_PATH . '/manifest.json', json_encode($m, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $zipPath = BASE_PATH . "/dist/aradedu-$version.zip";
            @unlink($zipPath);
            $z = new ZipArchive();
            $z->open($zipPath, ZipArchive::CREATE);
            foreach (array_merge($files, ['manifest.json']) as $f) $z->addFile(BASE_PATH . '/' . $f, $f);
            foreach (['', 'uploads', 'avatars', 'backups', 'logs', 'cache', 'updates', 'sessions', 'tmp'] as $d) {
                $z->addFromString('storage/' . ($d ? $d . '/' : '') . '.gitkeep', '');
            }
            $z->addFile(BASE_PATH . '/storage/.htaccess', 'storage/.htaccess');
            $z->close();
        }
        $out('Built ' . str_replace(BASE_PATH . '/', '', $zipPath) . ' (' . count($files) . ' files, ' . filesize($zipPath) . ' bytes)');
        break;

    default:
        $src = (string)file_get_contents(__FILE__);
        preg_match('~/\*\*(.*?)\*/~s', $src, $m);
        $out(trim($m[1] ?? 'see source'));
}
