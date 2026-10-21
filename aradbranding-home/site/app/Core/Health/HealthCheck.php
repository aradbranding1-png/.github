<?php

declare(strict_types=1);

namespace App\Core\Health;

use App\Core\Cache\Cache;
use App\Core\Container;
use App\Core\Database\Migrator;
use App\Core\Db\Connection;
use Throwable;

/** Each check returns ['name', 'status' => ok|warn|fail, 'detail']. */
final class HealthCheck
{
    public const CRON_STAMP = BASE_PATH . '/storage/framework/cron.last';

    private const REQUIRED_EXT = ['pdo_mysql', 'mbstring', 'openssl', 'json', 'fileinfo', 'sodium', 'curl', 'zip', 'intl'];

    public function __construct(private Container $c)
    {
    }

    /** @return list<array{name: string, status: string, detail: string}> */
    public function run(): array
    {
        $checks = [
            $this->php(),
            $this->extensions(),
            $this->imageLibrary(),
            $this->opcache(),
            $this->storage(),
            $this->disk(),
            $this->cache(),
            $this->sessions(),
        ];
        $db = $this->database();
        $checks[] = $db;
        if ($db['status'] !== 'fail') {
            $checks[] = $this->migrations();
            $checks[] = $this->queue();
        }
        $checks[] = $this->cron();
        $checks[] = $this->env();
        $checks[] = $this->secrets();
        return $checks;
    }

    /** Checks that need no database — used by the installer before .env exists. */
    public function basic(): array
    {
        return [$this->php(), $this->extensions(), $this->imageLibrary(), $this->opcache(), $this->storage(), $this->disk()];
    }

    /** @param list<array{status: string}> $checks */
    public static function overall(array $checks): string
    {
        $statuses = array_column($checks, 'status');
        return in_array('fail', $statuses, true) ? 'fail' : (in_array('warn', $statuses, true) ? 'warn' : 'ok');
    }

    private function php(): array
    {
        $ok = version_compare(PHP_VERSION, '8.3.0', '>=');
        return self::r('PHP', $ok ? 'ok' : (version_compare(PHP_VERSION, '8.2.0', '>=') ? 'warn' : 'fail'), PHP_VERSION . ' (حداقل توصیه‌شده 8.3)');
    }

    private function extensions(): array
    {
        $missing = array_values(array_filter(self::REQUIRED_EXT, static fn (string $e): bool => !extension_loaded($e)));
        return self::r('Extensions', $missing === [] ? 'ok' : 'fail', $missing === [] ? 'همه نصب‌اند' : 'نصب نشده: ' . implode(', ', $missing));
    }

    private function imageLibrary(): array
    {
        if (extension_loaded('imagick')) {
            return self::r('Image library', 'ok', 'Imagick');
        }
        if (extension_loaded('gd')) {
            $info = gd_info();
            $webp = !empty($info['WebP Support']);
            $avif = !empty($info['AVIF Support']);
            return self::r('Image library', $webp ? 'ok' : 'warn', 'GD, WebP: ' . ($webp ? 'yes' : 'no') . ', AVIF: ' . ($avif ? 'yes' : 'no'));
        }
        return self::r('Image library', 'fail', 'نه GD و نه Imagick نصب است');
    }

    private function opcache(): array
    {
        $enabled = function_exists('opcache_get_status') && (bool) (@opcache_get_status(false)['opcache_enabled'] ?? false);
        if (PHP_SAPI === 'cli') {
            return self::r('OPcache', 'ok', 'CLI: بررسی روی وب انجام شود');
        }
        return self::r('OPcache', $enabled ? 'ok' : 'warn', $enabled ? 'فعال' : 'غیرفعال؛ کارایی به‌شدت کم می‌شود');
    }

    private function storage(): array
    {
        $bad = [];
        foreach (['storage/cache', 'storage/logs', 'storage/private', 'storage/framework', 'backups', 'public_html/media'] as $dir) {
            if (!is_dir(BASE_PATH . '/' . $dir) || !is_writable(BASE_PATH . '/' . $dir)) {
                $bad[] = $dir;
            }
        }
        $publicEnv = is_file(BASE_PATH . '/public_html/.env');
        if ($publicEnv) {
            return self::r('Storage', 'fail', 'فایل .env داخل public_html است!');
        }
        return self::r('Storage', $bad === [] ? 'ok' : 'fail', $bad === [] ? 'قابل نوشتن' : 'غیرقابل نوشتن: ' . implode(', ', $bad));
    }

    private function disk(): array
    {
        $free = @disk_free_space(BASE_PATH);
        if ($free === false) {
            return self::r('Disk', 'warn', 'قابل اندازه‌گیری نیست');
        }
        $gb = $free / 1024 ** 3;
        return self::r('Disk', $gb < 1 ? 'fail' : ($gb < 5 ? 'warn' : 'ok'), sprintf('%.1f GB آزاد', $gb));
    }

    private function cache(): array
    {
        try {
            $cache = $this->c->get(Cache::class);
            $token = bin2hex(random_bytes(4));
            $cache->put('health:probe', $token, 30);
            $ok = $cache->get('health:probe') === $token;
            $store = $cache->store() instanceof \App\Core\Cache\RedisStore ? 'redis' : 'file';
            $wanted = strtolower((string) \App\Core\Env::get('CACHE_DRIVER', 'file')) === 'redis' ? 'redis' : 'file';
            if ($ok && $wanted !== $store) {
                return self::r('Cache', 'warn', "CACHE_DRIVER={$wanted} ولی Redis در دسترس نیست؛ موقتاً روی file");
            }
            return self::r('Cache', $ok ? 'ok' : 'fail', $ok ? "خواندن و نوشتن سالم ({$store})" : 'مقدار نوشته‌شده خوانده نشد');
        } catch (Throwable $e) {
            return self::r('Cache', 'fail', $e->getMessage());
        }
    }

    private function sessions(): array
    {
        $driver = \App\Core\Session\SessionStore::driver();
        if ($driver !== 'redis') {
            return self::r('Sessions', 'ok', 'MySQL (برای چند صد کاربر هم‌زمان SESSION_DRIVER=redis توصیه می‌شود)');
        }
        $h = $this->c->get(\App\Core\Session\SessionStore::class)->redis();
        return $h !== null && $h->ping()
            ? self::r('Sessions', 'ok', 'Redis')
            : self::r('Sessions', 'warn', 'SESSION_DRIVER=redis ولی Redis در دسترس نیست؛ موقتاً روی MySQL');
    }

    private function database(): array
    {
        try {
            $db = $this->c->get(Connection::class);
            $start = hrtime(true);
            $db->scalar('SELECT 1');
            $ms = (hrtime(true) - $start) / 1e6;
            return self::r('Database', $ms > 100 ? 'warn' : 'ok', $db->serverVersion() . sprintf(' · %.1f ms', $ms));
        } catch (Throwable $e) {
            return self::r('Database', 'fail', 'اتصال برقرار نشد');
        }
    }

    private function migrations(): array
    {
        try {
            $pending = (new Migrator($this->c->get(Connection::class), BASE_PATH . '/database/migrations'))->pendingCount();
            return self::r('Migrations', $pending === 0 ? 'ok' : 'warn', $pending === 0 ? 'به‌روز' : "{$pending} مورد اجرا نشده");
        } catch (Throwable $e) {
            return self::r('Migrations', 'fail', $e->getMessage());
        }
    }

    private function queue(): array
    {
        try {
            $db = $this->c->get(Connection::class);
            $failed = (int) $db->scalar('SELECT COUNT(*) FROM failed_jobs WHERE failed_at > NOW(3) - INTERVAL 1 DAY');
            $oldest = $db->scalar('SELECT TIMESTAMPDIFF(SECOND, MIN(available_at), NOW(3)) FROM jobs WHERE reserved_at IS NULL');
            $lag = $oldest === null ? 0 : max(0, (int) $oldest);
            $status = $failed > 0 || $lag > 300 ? 'warn' : 'ok';
            return self::r('Queue', $status, "تأخیر {$lag} ثانیه · {$failed} Job ناموفق در ۲۴ ساعت");
        } catch (Throwable $e) {
            return self::r('Queue', 'fail', $e->getMessage());
        }
    }

    private function cron(): array
    {
        $last = is_file(self::CRON_STAMP) ? (int) file_get_contents(self::CRON_STAMP) : 0;
        if ($last === 0) {
            return self::r('Cron', 'warn', 'هنوز اجرا نشده');
        }
        $ago = time() - $last;
        return self::r('Cron', $ago > 1200 ? 'fail' : 'ok', 'آخرین اجرا ' . intdiv($ago, 60) . ' دقیقه پیش');
    }

    private function env(): array
    {
        $key = (string) \App\Core\Env::get('APP_KEY', '');
        $debug = (bool) \App\Core\Env::get('APP_DEBUG', false);
        $env = (string) \App\Core\Env::get('APP_ENV', 'production');
        if (strlen($key) < 40) {
            return self::r('Environment', 'fail', 'APP_KEY تنظیم نشده');
        }
        if ($env === 'production' && $debug) {
            return self::r('Environment', 'fail', 'APP_DEBUG در Production روشن است');
        }
        return self::r('Environment', 'ok', $env);
    }

    /** File permissions of secrets, token separation and the test payment gateway. */
    private function secrets(): array
    {
        $problems = [];
        $exposed = \App\Core\Security\FilePermissions::exposed();
        if ($exposed !== []) {
            $problems[] = 'دسترسی فایل‌های حساس باید 600 باشد: ' . implode(', ', $exposed);
        }
        $health = (string) \App\Core\Env::get('HEALTH_TOKEN', '');
        $ops = (string) \App\Core\Env::get('OPS_TOKEN', '');
        if ($ops !== '' && $health !== '' && hash_equals($health, $ops)) {
            $problems[] = 'OPS_TOKEN نباید با HEALTH_TOKEN یکی باشد (مسیرهای /ops/* خاموش‌اند)';
        }
        if (\App\Modules\Payments\Gateways\TestGateway::misconfigured()) {
            $problems[] = 'PAYMENT_TEST_GATEWAY=true در Production (نادیده گرفته می‌شود؛ آن را false کنید)';
        }
        return $problems === []
            ? self::r('Secrets', 'ok', 'دسترسی‌ها و توکن‌ها درست است')
            : self::r('Secrets', 'warn', implode(' · ', $problems));
    }

    /** @return array{name: string, status: string, detail: string} */
    private static function r(string $name, string $status, string $detail): array
    {
        return ['name' => $name, 'status' => $status, 'detail' => $detail];
    }
}
