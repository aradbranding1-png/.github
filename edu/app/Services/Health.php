<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Core\Kernel;
use App\Core\Migrator;

/** System health checks (OK / Warning / Error). Used by the health page, installer and updater. */
final class Health
{
    public const REQUIRED_EXT = ['pdo_mysql' => true, 'mbstring' => true, 'json' => true, 'openssl' => true, 'fileinfo' => true, 'zip' => true, 'gd' => true, 'curl' => false, 'dom' => false, 'intl' => false, 'sodium' => false];
    public const STORAGE_DIRS = ['', 'uploads', 'avatars', 'backups', 'logs', 'cache', 'updates', 'sessions', 'tmp'];

    private static function c(string $key, string $label, string $status, string $msg): array
    {
        return compact('key', 'label', 'status', 'msg');
    }

    public static function requirements(): array
    {
        $out = [];
        $out[] = self::c('php', 'نسخه PHP', PHP_VERSION_ID >= 80100 ? 'ok' : 'error', PHP_VERSION . ' (حداقل 8.1، پیشنهادی 8.2 یا 8.3)');
        foreach (self::REQUIRED_EXT as $ext => $required) {
            $ok = extension_loaded($ext);
            $out[] = self::c('ext_' . $ext, 'افزونه ' . $ext, $ok ? 'ok' : ($required ? 'error' : 'warning'), $ok ? 'فعال' : ($required ? 'لازم است' : 'توصیه می‌شود'));
        }
        foreach (self::STORAGE_DIRS as $d) {
            $p = STORAGE_PATH . ($d ? '/' . $d : '');
            if (!is_dir($p)) @mkdir($p, 0750, true);
            $out[] = self::c('dir_' . ($d ?: 'storage'), 'قابلیت نوشتن storage/' . $d, is_writable($p) ? 'ok' : 'error', is_writable($p) ? 'قابل نوشتن' : 'سطح دسترسی را 750 یا 755 قرار دهید');
        }
        $out[] = self::c('env_writable', 'امکان ایجاد فایل .env', is_writable(BASE_PATH) || is_writable(BASE_PATH . '/.env') ? 'ok' : 'error', 'پوشه اصلی پروژه (خارج از public_html)');
        return $out;
    }

    public static function run(bool $deep = false): array
    {
        $out = [];
        // PHP & extensions
        $out[] = self::c('php', 'PHP', PHP_VERSION_ID >= 80100 ? 'ok' : 'error', 'نسخه ' . PHP_VERSION);
        $missing = []; $missingOpt = [];
        foreach (self::REQUIRED_EXT as $e => $req) if (!extension_loaded($e)) { if ($req) $missing[] = $e; else $missingOpt[] = $e; }
        $out[] = self::c('extensions', 'افزونه‌های PHP', $missing ? 'error' : ($missingOpt ? 'warning' : 'ok'), $missing ? 'غیرفعال: ' . implode(', ', $missing) : ($missingOpt ? 'اختیاری غیرفعال: ' . implode(', ', $missingOpt) : 'همه افزونه‌های لازم فعال است'));

        // Database
        try {
            $ver = (string)DB::value('SELECT VERSION()');
            $out[] = self::c('database', 'اتصال دیتابیس', 'ok', 'متصل — ' . $ver);
        } catch (\Throwable) {
            $out[] = self::c('database', 'اتصال دیتابیس', 'error', 'اتصال برقرار نیست');
            return $out;
        }

        // Migrations
        try {
            $st = Migrator::status();
            $pending = count(array_filter($st, fn($m) => $m['status'] === 'pending'));
            $failed = count(array_filter($st, fn($m) => $m['status'] === 'failed'));
            $out[] = self::c('migrations', 'Migration', $failed ? 'error' : ($pending ? 'warning' : 'ok'), $failed ? fa($failed) . ' مورد ناموفق' : ($pending ? fa($pending) . ' مورد اجرا نشده' : 'همه ' . fa(count($st)) . ' مورد اجرا شده'));
        } catch (\Throwable) {
            $out[] = self::c('migrations', 'Migration', 'error', 'خطا در خواندن وضعیت');
        }

        // Storage
        $bad = [];
        foreach (self::STORAGE_DIRS as $d) { $p = STORAGE_PATH . ($d ? '/' . $d : ''); if (!is_dir($p) || !is_writable($p)) $bad[] = 'storage/' . $d; }
        $out[] = self::c('storage', 'Storage و سطح دسترسی پوشه‌ها', $bad ? 'error' : 'ok', $bad ? 'غیرقابل نوشتن: ' . implode(', ', $bad) : 'همه پوشه‌ها قابل نوشتن هستند');

        // Disk
        $free = @disk_free_space(STORAGE_PATH);
        if ($free !== false) {
            $out[] = self::c('disk', 'فضای دیسک', $free < 200 * 1048576 ? 'error' : ($free < 1024 * 1048576 ? 'warning' : 'ok'), 'فضای آزاد: ' . human_size($free));
        }

        // Configuration
        $cfg = [];
        if (!env('APP_KEY')) $cfg[] = 'APP_KEY خالی است';
        if (is_production() && filter_var(env('APP_DEBUG', false), FILTER_VALIDATE_BOOLEAN)) $cfg[] = 'APP_DEBUG در محیط production روشن است';
        if (!str_starts_with((string)env('APP_URL', ''), 'https://')) $cfg[] = 'APP_URL با https شروع نمی‌شود';
        $out[] = self::c('config', 'پیکربندی (.env)', $cfg ? 'warning' : 'ok', $cfg ? implode('؛ ', $cfg) : 'پیکربندی صحیح است');

        // Web root hygiene
        $leaks = [];
        foreach (glob(BASE_PATH . '/public_html/{,.}*', GLOB_BRACE) ?: [] as $f) {
            $b = basename($f);
            if (in_array($b, ['.', '..', '.htaccess', '.well-known', 'index.php', 'assets', 'favicon.ico', 'robots.txt', 'manifest.webmanifest', 'sw.js', 'offline.html'], true)) continue;
            if (preg_match('/\.(php|sql|zip|gz|tar|rar|bak|old|backup|log|env)$|^\.env|^\.git|__MACOSX/i', $b)) $leaks[] = $b;
        }
        $out[] = self::c('webroot', 'پاکیزگی Web Root', $leaks ? 'error' : 'ok', $leaks ? 'فایل‌های حساس/اضافی در public_html: ' . implode(', ', $leaks) : 'فایل حساسی در public_html نیست');

        $envPerm = is_file(BASE_PATH . '/.env') ? (fileperms(BASE_PATH . '/.env') & 0777) : 0;
        $out[] = self::c('env_perm', 'سطح دسترسی فایل .env', ($envPerm & 0004) ? 'warning' : 'ok', 'مجوز فعلی: ' . decoct($envPerm) . (($envPerm & 0004) ? ' — پیشنهاد: 640' : ''));

        // Cron
        $last = setting('cron_last_run');
        $age = $last ? time() - strtotime((string)$last) : null;
        $out[] = self::c('cron', 'Cron (کارهای زمان‌بندی‌شده)', $age === null ? 'warning' : ($age > 7200 ? 'warning' : 'ok'), $age === null ? 'هنوز اجرا نشده — Cron را در DirectAdmin تنظیم کنید' : 'آخرین اجرا: ' . time_ago((string)$last));
        $out[] = self::c('queue', 'Queue', 'ok', 'این سامانه به Queue جداگانه نیاز ندارد؛ کارهای پس‌زمینه با Cron انجام می‌شود');

        // Session / cache
        $sp = STORAGE_PATH . '/sessions';
        $out[] = self::c('session', 'Session', is_writable($sp) ? 'ok' : 'error', is_writable($sp) ? 'ذخیره‌سازی نشست فعال است' : 'پوشه sessions قابل نوشتن نیست');
        $opc = function_exists('opcache_get_status') ? @opcache_get_status(false) : false;
        $out[] = self::c('cache', 'Cache', 'ok', 'OPcache: ' . ($opc && !empty($opc['opcache_enabled']) ? 'فعال' : 'غیرفعال (اختیاری)') . ' — کش تنظیمات: storage/cache');

        // Application boot & routes
        try {
            $routes = Kernel::router()->routes();
            $paths = array_column($routes, 'path');
            $need = ['/login', '/', '/learn', '/admin', '/admin/users', '/admin/roles', '/admin/system/updates', '/api/v1/health'];
            $miss = array_diff($need, $paths);
            $out[] = self::c('routes', 'Routes و بوت برنامه', $miss ? 'error' : 'ok', $miss ? 'مسیرهای ناموجود: ' . implode(', ', $miss) : fa(count($routes)) . ' مسیر بارگذاری شد');
        } catch (\Throwable $e) {
            $out[] = self::c('routes', 'Routes و بوت برنامه', 'error', 'بارگذاری مسیرها ناموفق بود');
        }

        // Authentication
        $root = DB::one("SELECT id, status FROM users WHERE is_root = 1 AND deleted_at IS NULL LIMIT 1");
        $out[] = self::c('auth', 'Authentication', $root && $root['status'] === 'active' ? 'ok' : 'error', $root ? 'مدیر کل فعال است' : 'مدیر کل یافت نشد');

        // SSO
        if (setting('sso_enabled') === '1') {
            $sso = SsoClient::configErrors();
            $status = $sso ? 'error' : 'ok';
            $msg = $sso ? implode('؛ ', $sso) : 'پیکربندی کامل است (' . (setting('sso_mode') === 'jwt' ? 'Signed Token' : 'OAuth2') . ')';
            if (!$sso && $deep) {
                $probe = SsoClient::probe();
                if ($probe !== true) { $status = 'warning'; $msg .= ' — ' . $probe; }
            }
            $out[] = self::c('sso', 'SSO با my.aradbranding.me', $status, $msg);
        } else {
            $out[] = self::c('sso', 'SSO با my.aradbranding.me', 'warning', 'غیرفعال — پس از دریافت API رسمی my از بخش تنظیمات SSO فعال شود');
        }

        // API
        $out[] = self::c('api', 'API داخلی (v1)', 'ok', 'فعال — ' . fa((int)DB::value('SELECT COUNT(*) FROM api_tokens WHERE revoked_at IS NULL')) . ' کلید فعال');

        // Maintenance
        if (is_file(STORAGE_PATH . '/maintenance.flag')) $out[] = self::c('maintenance', 'حالت تعمیر', 'warning', 'سامانه در حالت تعمیر است');

        return $out;
    }

    public static function summary(array $checks): string
    {
        $st = array_column($checks, 'status');
        return in_array('error', $st, true) ? 'error' : (in_array('warning', $st, true) ? 'warning' : 'ok');
    }
}
