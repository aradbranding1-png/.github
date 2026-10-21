<?php

declare(strict_types=1);

use App\Core\Cache\Cache;
use App\Core\Cache\FileStore;
use App\Core\Config;
use App\Core\Console\Console;
use App\Core\Console\Scheduler;
use App\Core\Database\Migrator;
use App\Core\Database\PartitionManager;
use App\Core\Db\Connection;
use App\Core\Env;
use App\Core\Events\OutboxProcessor;
use App\Core\Health\HealthCheck;
use App\Core\Http\Middleware\MaintenanceMode;
use App\Core\Log\Logger;
use App\Core\Queue\DatabaseQueue;
use App\Core\Queue\Worker;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$c = require __DIR__ . '/app.php';
$config = $c->get(Config::class);
$console = new Console();

$migrator = static fn () => new Migrator($c->get(Connection::class), BASE_PATH . '/database/migrations');
$partitions = static fn () => new PartitionManager(
    $c->get(Connection::class),
    (array) $config->get('database.partitioned', []),
    (int) $config->get('database.partition_months_ahead', 3)
);

$console->register('keys:generate', 'ساخت APP_KEY، SIGNING_KEY، HEALTH_TOKEN و OPS_TOKEN در .env (--force برای جایگزینی، --only=HEALTH_TOKEN,OPS_TOKEN برای چرخش فقط توکن‌ها)', static function (array $a, array $o): int {
    $file = BASE_PATH . '/.env';
    if (!is_file($file)) {
        copy(BASE_PATH . '/.env.example', $file);
    }
    $all = ['APP_KEY', 'SIGNING_KEY', 'HEALTH_TOKEN', 'OPS_TOKEN'];
    $only = isset($o['only']) && is_string($o['only'])
        ? array_values(array_intersect($all, array_map('trim', explode(',', strtoupper($o['only'])))))
        : null;
    $values = [];
    foreach ($all as $key) {
        $forced = $only !== null ? in_array($key, $only, true) : isset($o['force']);
        if ($forced || strlen((string) Env::get($key, '')) < 40) {
            $values[$key] = 'base64:' . base64_encode(random_bytes(32));
        }
    }
    if ($values === []) {
        Console::line('کلیدها از قبل وجود دارند. برای جایگزینی --force بدهید.');
        return 0;
    }
    Env::write($file, $values);
    Console::line('ساخته شد: ' . implode(', ', array_keys($values)));
    return 0;
});

$console->register('migrate', 'اجرای Migrationهای جدید و ساخت Partitionها', static function () use ($migrator, $partitions): int {
    $ran = $migrator()->migrate(static fn (string $m) => Console::line($m));
    Console::line($ran === [] ? 'چیزی برای اجرا نبود.' : count($ran) . ' Migration اجرا شد.');
    foreach ($partitions()->ensure() as $p) {
        Console::line("Partition ساخته شد: {$p}");
    }
    return 0;
});

$console->register('migrate:status', 'وضعیت Migrationها', static function () use ($migrator): int {
    foreach ($migrator()->status() as $row) {
        Console::line(sprintf('  %-50s %s', $row['version'], $row['status']));
    }
    return 0;
});

$console->register('partitions:ensure', 'ساخت Partitionهای ماه‌های آینده', static function () use ($partitions): int {
    $created = $partitions()->ensure();
    Console::line($created === [] ? 'همه Partitionها موجودند.' : implode(PHP_EOL, $created));
    return 0;
});

$console->register('health', 'بررسی سلامت سامانه', static function () use ($c): int {
    $checks = (new HealthCheck($c))->run();
    foreach ($checks as $check) {
        Console::line(sprintf('[%s] %-15s %s', Console::status($check['status']), $check['name'], $check['detail']));
    }
    return HealthCheck::overall($checks) === 'fail' ? 1 : 0;
});

$console->register('cache:clear', 'پاک‌کردن کامل Cache و OPcache', static function () use ($c): int {
    $c->get(Cache::class)->store()->flush();
    Console::line('Cache پاک شد. (OPcache وب با ری‌استارت PHP-FPM یا /health پاک می‌شود)');
    return 0;
});

$console->register('maintenance:on', 'حالت نگه‌داری (--secret=... برای عبور ادمین)', static function (array $a, array $o): int {
    $secret = is_string($o['secret'] ?? null) ? $o['secret'] : bin2hex(random_bytes(12));
    file_put_contents(MaintenanceMode::FILE, json_encode(['secret' => $secret, 'retry' => 120, 'since' => time()]));
    Console::line("حالت نگه‌داری فعال شد. عبور ادمین: /__bypass/{$secret}");
    return 0;
});

$console->register('maintenance:off', 'خروج از حالت نگه‌داری', static function (): int {
    @unlink(MaintenanceMode::FILE);
    Console::line('سامانه دوباره در دسترس است.');
    return 0;
});

$console->register('queue:work', 'پردازش صف‌ها (--max-time=55 --queues=critical,letters,... --instance=N برای چند Worker هم‌زمان با Supervisor)', static function (array $a, array $o) use ($c, $config): int {
    $queues = isset($o['queues']) && is_string($o['queues'])
        ? explode(',', $o['queues'])
        : (array) $config->get('queue.queues');
    // Jobs are reserved with FOR UPDATE SKIP LOCKED, so several workers can share the same queues.
    // --instance only gives each one its own lock file (default 1 = the classic single cron worker).
    $instance = max(1, (int) ($o['instance'] ?? 1));
    $lock = fopen(BASE_PATH . '/storage/framework/worker-' . md5(implode(',', $queues)) . ($instance > 1 ? '-' . $instance : '') . '.lock', 'c+');
    if ($lock === false) {
        Console::error('فایل قفل Worker قابل ساخت نیست.');
        return 1;
    }
    if (!flock($lock, LOCK_EX | LOCK_NB)) {
        // The holder writes {pid, beat} regularly. A worker whose beat is older than 15 minutes is hung
        // (stuck job, dead DB connection): kill it, otherwise every later run would exit here forever.
        $info = json_decode((string) stream_get_contents($lock, 4096, 0), true);
        $pid = (int) ($info['pid'] ?? 0);
        $lastSeen = (int) ($info['beat'] ?? 0);
        $hung = $pid > 1 && $lastSeen > 0 && time() - $lastSeen > 900;
        if ($hung && function_exists('posix_kill') && $pid !== getmypid()) {
            $c->get(Logger::class)->error('Killing hung queue worker', ['pid' => $pid, 'idle_seconds' => time() - $lastSeen]);
            posix_kill($pid, 9);
            for ($i = 0; $i < 20 && !flock($lock, LOCK_EX | LOCK_NB); $i++) {
                usleep(250_000);
            }
        }
        if (!$hung || !isset($i) || $i >= 20) {
            Console::line('Worker دیگری در حال اجراست.');
            return 0;
        }
    }
    $lastBeat = 0;
    $beat = static function () use ($lock, &$lastBeat): void {
        if (time() - $lastBeat < 5) {
            return;
        }
        $lastBeat = time();
        ftruncate($lock, 0);
        rewind($lock);
        fwrite($lock, json_encode(['pid' => getmypid(), 'beat' => $lastBeat]));
        fflush($lock);
    };
    $beat();
    $outbox = new OutboxProcessor($c, $c->get(Connection::class), $c->get(Logger::class), (array) $config->get('events', []));
    $store = $c->get(Cache::class)->store();
    $lastOutbox = 0.0;
    $onIdle = static function () use ($outbox, $store, &$lastOutbox): int {
        if (microtime(true) - $lastOutbox < 2) {
            return 0;
        }
        $lastOutbox = microtime(true);
        // The outbox is not safe to process twice in parallel (counters would double):
        // only the worker holding this lock runs it; the others just process jobs.
        $lock = $store->lock('outbox-processor', 120);
        if ($lock === null) {
            return 0;
        }
        try {
            return $outbox->run(500, 4);
        } finally {
            $lock->release();
        }
    };
    $worker = new Worker($c, $c->get(DatabaseQueue::class), $c->get(Logger::class), $queues, $onIdle, $beat);
    $n = $worker->run((int) ($o['max-time'] ?? 55), (int) ($o['memory'] ?? 128));
    Console::line("{$n} Job پردازش شد.");
    return 0;
});

$console->register('queue:retry', 'بازگرداندن Jobهای ناموفق به صف (شناسه اختیاری)', static function (array $a) use ($c): int {
    $n = $c->get(DatabaseQueue::class)->retryFailed(isset($a[0]) ? (int) $a[0] : null);
    Console::line("{$n} Job به صف برگشت.");
    return 0;
});

$console->register('cron', 'اجرای کارهای زمان‌بندی‌شده (هر ۱۵ دقیقه)', static function () use ($c, $partitions): int {
    $lock = fopen(BASE_PATH . '/storage/framework/cron.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        Console::line('Cron قبلی هنوز در حال اجراست.');
        return 0;
    }
    file_put_contents(HealthCheck::CRON_STAMP, (string) time());
    $db = $c->get(Connection::class);
    $s = new Scheduler();

    $s->every(900, 'file-permissions', static function () {
        $changed = \App\Core\Security\FilePermissions::harden();
        return $changed === [] ? 'ok' : 'chmod: ' . implode(', ', $changed);
    });
    $s->every(900, 'queue-reclaim', static fn () => $c->get(DatabaseQueue::class)->reclaimStale(1200) . ' reclaimed');
    $s->every(86400, 'partitions', static fn () => count($partitions()->ensure()) . ' created');
    $s->every(3600, 'cache-prune', static function () use ($c) {
        $store = $c->get(Cache::class)->store();
        return $store instanceof FileStore ? $store->prune() . ' files' : 'skipped';
    });
    $s->every(900, 'cleanup', static function () use ($db, $c) {
        $lifetime = (int) $c->get(Config::class)->get('security.session_lifetime', 1_209_600);
        $n = 0;
        // Bounded batches keep locks short on big tables.
        for ($i = 0; $i < 20; $i++) {
            $d = $db->exec('DELETE FROM sessions WHERE last_activity < ? LIMIT 5000', [time() - $lifetime]);
            $n += $d;
            if ($d < 5000) {
                break;
            }
        }
        $n += $db->exec('DELETE FROM idempotency_keys WHERE created_at < NOW(3) - INTERVAL 7 DAY LIMIT 50000');
        $n += $db->exec('DELETE FROM failed_jobs WHERE failed_at < NOW(3) - INTERVAL 30 DAY LIMIT 10000');
        return "{$n} rows";
    });

    $s->every(900, 'payments', static fn () => $c->get(\App\Modules\Payments\PaymentService::class)->reconcile() . ' reconciled');
    $s->every(86400, 'wallet-audit', static function () use ($c) {
        $bad = $c->get(\App\Modules\Wallet\WalletService::class)->mismatches();
        if ($bad !== []) {
            $c->get(\App\Core\Log\Logger::class)->critical('Wallet balance differs from ledger', ['wallets' => $bad]);
        }
        return count($bad) . ' mismatches';
    });
    $s->every(900, 'proposals-expire', static fn () => $c->get(\App\Modules\Proposals\ProposalService::class)->expireDue() . ' expired');
    $s->every(86400, 'feed-popularity', static function () use ($db) {
        // Rebuild the 7-day view counts used by the "for you" ranking (partition-pruned scan).
        $db->exec('UPDATE proposal_feed SET views_7d = 0');
        return $db->exec(
            'UPDATE proposal_feed f JOIN (
                SELECT proposal_id, COUNT(*) AS n FROM proposal_views WHERE created_at > NOW(3) - INTERVAL 7 DAY GROUP BY proposal_id
             ) v ON v.proposal_id = f.proposal_id SET f.views_7d = v.n'
        ) . ' rows';
    });
    $s->every(3600, 'metrics', static function () use ($c) {
        $m = $c->get(\App\Modules\Admin\MetricsService::class);
        // Days are Tehran calendar days (1.19.17). Once, recompute the history that was counted in UTC days.
        $settings = $c->get(\App\Core\Settings\Settings::class);
        $tz = $m::timezone()->getName();
        if ($settings->get($m::TZ_SETTING) !== $tz) {
            $c->get(\App\Core\Db\Connection::class)->allowLongRunning();
            for ($i = 400; $i >= 2; $i--) {
                $m->computeDay($m::today(-$i));
            }
            $settings->set($m::TZ_SETTING, $tz, null);
        }
        $m->computeDay($m::today(-1));
        $m->computeDay($m::today());
        return 'today + yesterday';
    });
    $s->every(900, 'exports-cleanup', static function () {
        $n = 0;
        foreach (glob(BASE_PATH . '/storage/private/exports/*') ?: [] as $f) {
            if (filemtime($f) < time() - 7 * 86400 && @unlink($f)) {
                $n++;
            }
        }
        // Update-page exports are deleted right after download; this only catches interrupted ones.
        foreach (glob(BASE_PATH . '/storage/framework/export-*.zip') ?: [] as $f) {
            if (filemtime($f) < time() - 3600 && @unlink($f)) {
                $n++;
            }
        }
        return "{$n} old exports removed";
    });
    $s->every(86400, 'daily-digest', static function () use ($c) {
        if (!$c->get(\App\Core\Settings\Settings::class)->get('notifications.daily_digest', true)) {
            return 'off';
        }
        return $c->get(\App\Modules\Notifications\NotificationService::class)->dailyDigest() . ' notifications';
    });
    $s->every(86400, 'sitemap', static fn () => $c->get(\App\Modules\System\SitemapService::class)->generate() . ' urls');
    $s->every(86400, 'auto-backup', static function () use ($c) {
        $settings = $c->get(\App\Core\Settings\Settings::class);
        if (!$settings->get('backup.auto_daily', false)) {
            return 'off';
        }
        $backups = $c->get(\App\Modules\System\BackupService::class);
        $name = $backups->create('auto');
        return $name . ' · pruned ' . $backups->prune(max(1, (int) $settings->get('backup.retention_days', 7)));
    });
    $s->every(3600, 'grants-cleanup', static fn () => $db->exec('DELETE FROM page_view_grants WHERE expires_at < NOW(3) - INTERVAL 1 DAY LIMIT 20000') . ' rows');

    foreach ($s->runDue() as $task => $result) {
        Console::line(sprintf('  %-15s %s', $task, $result));
    }
    return 0;
});

$console->register('install', 'نصب از خط فرمان: کلیدها، Migration، Partition و قفل نصب', static function () use ($console): int {
    if (is_file(BASE_PATH . '/storage/installed.lock')) {
        Console::error('سامانه قبلاً نصب شده است.');
        return 1;
    }
    $console->run(['console', 'keys:generate']);
    Env::load(BASE_PATH . '/.env');
    $console->run(['console', 'migrate']);
    file_put_contents(BASE_PATH . '/storage/installed.lock', json_encode(['installed_at' => gmdate('c'), 'via' => 'cli']));
    Console::line('نصب کامل شد. سپس `php bin/console health` را اجرا کنید.');
    return 0;
});

$console->register('user:promote-admin', 'دادن نقش Super Admin به یک حساب ثبت‌شده: user:promote-admin EMAIL', static function (array $a) use ($c): int {
    $email = mb_strtolower(trim((string) ($a[0] ?? '')));
    if ($email === '') {
        Console::error('ایمیل را وارد کنید: php bin/console user:promote-admin you@example.com');
        return 1;
    }
    $db = $c->get(Connection::class);
    $id = $db->scalar('SELECT id FROM users WHERE email = ? AND deleted_at IS NULL', [$email]);
    if ($id === null) {
        Console::error('حسابی با این ایمیل پیدا نشد. ابتدا در /register عضو شوید.');
        return 1;
    }
    $db->exec("INSERT IGNORE INTO user_roles (user_id, role_id, created_at) SELECT ?, id, NOW(3) FROM roles WHERE slug = 'super_admin'", [(int) $id]);
    $c->get(\App\Core\Security\Audit::class)->log('cli.promote_admin', null, 'user', (int) $id);
    Console::line("نقش Super Admin به {$email} داده شد.");
    return 0;
});

$console->register('wallet:credit', 'افزایش Stars: wallet:credit EMAIL STARS "دلیل"', static function (array $a) use ($c): int {
    [$email, $stars, $note] = array_pad($a, 3, '');
    $stars = (int) $stars;
    if ($email === '' || $stars <= 0 || trim($note) === '') {
        Console::error('استفاده: php bin/console wallet:credit you@example.com 100 "دلیل افزایش"');
        return 1;
    }
    $id = $c->get(Connection::class)->scalar('SELECT id FROM users WHERE email = ? AND deleted_at IS NULL', [mb_strtolower($email)]);
    if ($id === null) {
        Console::error('حسابی با این ایمیل پیدا نشد.');
        return 1;
    }
    $c->get(\App\Modules\Wallet\WalletService::class)->credit((int) $id, $stars, \App\Modules\Wallet\WalletService::T_ADMIN_CREDIT, 'admin', null, null, null, null, $note);
    $c->get(\App\Core\Security\Audit::class)->log('cli.wallet_credit', null, 'user', (int) $id, 'success', null, ['stars' => $stars, 'note' => $note]);
    Console::line("{$stars} Star به {$email} اضافه شد.");
    return 0;
});

$console->register('metrics:backfill', 'محاسبه آمار روزهای گذشته: metrics:backfill [تعداد روز=90]', static function (array $a) use ($c): int {
    $days = max(1, min(3650, (int) ($a[0] ?? 90)));
    $m = $c->get(\App\Modules\Admin\MetricsService::class);
    for ($i = $days; $i >= 0; $i--) {
        $m->computeDay($m::today(-$i));
    }
    Console::line("{$days} روز محاسبه شد.");
    return 0;
});

$console->register('sitemap:generate', 'ساخت sitemap.xml و robots.txt', static function () use ($c): int {
    Console::line($c->get(\App\Modules\System\SitemapService::class)->generate() . ' نشانی در sitemap ثبت شد.');
    return 0;
});

$console->register('backup:create', 'ساخت نسخه پشتیبان دیتابیس در پوشه backups', static function () use ($c): int {
    Console::line('ساخته شد: backups/' . $c->get(\App\Modules\System\BackupService::class)->create('manual'));
    return 0;
});

$console->register('seed:demo', 'داده آزمایشی برای تست بار: seed:demo --users=10000 (فقط غیر production مگر با --force)', static function (array $a, array $o) use ($c, $config): int {
    if ($config->get('app.env') === 'production' && !isset($o['force'])) {
        Console::error('در محیط production اجرا نمی‌شود. برای سرور تست APP_ENV=staging بگذارید یا --force بدهید.');
        return 1;
    }
    $n = max(1, min(5_000_000, (int) ($o['users'] ?? 10000)));
    (new \App\Modules\System\DemoSeeder($c->get(Connection::class)))->run($n, static fn (string $m) => Console::line($m));
    return 0;
});

exit($console->run($argv));
