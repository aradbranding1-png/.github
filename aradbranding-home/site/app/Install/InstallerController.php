<?php

declare(strict_types=1);

namespace App\Install;

use App\Core\Container;
use App\Core\Database\Migrator;
use App\Core\Database\PartitionManager;
use App\Core\Db\Connection;
use App\Core\Env;
use App\Core\Health\HealthCheck;
use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Log\Logger;
use PDO;
use Throwable;

/**
 * Web installer for hosts without SSH.
 * Proof of server ownership: a random key is written to storage/install.key
 * (outside the web root); the installer asks for it before doing anything.
 * After success, storage/installed.lock disables the installer permanently.
 */
final class InstallerController
{
    private const LOCK = BASE_PATH . '/storage/installed.lock';
    private const KEY = BASE_PATH . '/storage/install.key';

    public function __construct(private Container $c)
    {
    }

    public function show(Request $request): Response
    {
        $this->guard();
        $this->ensureKey();
        return $this->render($request, 'form', [
            'checks' => $this->requirements(),
            'old' => ['db_host' => '127.0.0.1', 'db_port' => '3306', 'app_url' => 'https://aradbranding.app'],
            'errors' => [],
        ]);
    }

    public function install(Request $request): Response
    {
        $this->guard();
        $in = array_map(static fn ($v) => is_string($v) ? trim($v) : '', $request->all());
        $errors = [];

        if (!hash_equals(trim((string) @file_get_contents(self::KEY)), (string) ($in['install_key'] ?? ''))) {
            $errors['install_key'] = 'کلید نصب درست نیست.';
        }
        foreach (['db_host', 'db_name', 'db_user', 'app_url'] as $field) {
            if (($in[$field] ?? '') === '') {
                $errors[$field] = 'این فیلد الزامی است.';
            }
        }
        if (!preg_match('~^https://[a-z0-9.-]+$~i', $in['app_url'] ?? '')) {
            $errors['app_url'] = 'آدرس باید با https:// شروع شود و مسیر نداشته باشد.';
        }
        $port = (int) ($in['db_port'] ?? 3306);

        if ($errors === []) {
            try {
                $pdo = new PDO(
                    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $in['db_host'], $port, $in['db_name']),
                    $in['db_user'],
                    $in['db_pass'] ?? '',
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
                );
                $version = (string) $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
                if (!self::supportedServer($version)) {
                    $errors['db_host'] = "نسخه دیتابیس ({$version}) پشتیبانی نمی‌شود. MySQL 8.0+ یا MariaDB 10.6+ لازم است.";
                }
                $pdo = null;
            } catch (Throwable) {
                $errors['db_host'] = 'اتصال به دیتابیس برقرار نشد. اطلاعات را بررسی کنید.';
            }
        }

        if ($errors !== []) {
            unset($in['db_pass'], $in['install_key']);
            return $this->render($request, 'form', ['checks' => $this->requirements(), 'old' => $in, 'errors' => $errors], 422);
        }

        $url = rtrim($in['app_url'], '/');
        $host = (string) parse_url($url, PHP_URL_HOST);
        $env = [
            'APP_ENV' => 'production',
            'APP_DEBUG' => 'false',
            'APP_URL' => $url,
            'ASSET_URL' => 'https://static.' . $host,
            'MEDIA_URL' => 'https://media.' . $host,
            'APP_KEY' => 'base64:' . base64_encode(random_bytes(32)),
            'SIGNING_KEY' => 'base64:' . base64_encode(random_bytes(32)),
            'HEALTH_TOKEN' => 'base64:' . base64_encode(random_bytes(32)),
            'OPS_TOKEN' => 'base64:' . base64_encode(random_bytes(32)),
            'PAYMENT_TEST_GATEWAY' => 'false',
            'DB_HOST' => $in['db_host'],
            'DB_PORT' => (string) $port,
            'DB_DATABASE' => $in['db_name'],
            'DB_USERNAME' => $in['db_user'],
            'DB_PASSWORD' => $in['db_pass'] ?? '',
        ];

        try {
            if (!is_file(BASE_PATH . '/.env')) {
                copy(BASE_PATH . '/.env.example', BASE_PATH . '/.env');
            }
            Env::write(BASE_PATH . '/.env', $env);

            $db = new Connection([
                'host' => $env['DB_HOST'], 'port' => $port, 'database' => $env['DB_DATABASE'],
                'username' => $env['DB_USERNAME'], 'password' => $env['DB_PASSWORD'], 'slow_query_ms' => 0,
            ], $this->c->get(Logger::class));
            $ran = (new Migrator($db, BASE_PATH . '/database/migrations'))->migrate();
            $parts = (new PartitionManager($db, ['activity_events', 'audit_logs']))->ensure();

            file_put_contents(self::LOCK, json_encode(['installed_at' => gmdate('c'), 'via' => 'web', 'ip' => $request->ip()]));
            @unlink(self::KEY);
        } catch (Throwable $e) {
            $this->c->get(Logger::class)->error('Install failed', ['error' => $e->getMessage()]);
            return $this->render($request, 'form', [
                'checks' => $this->requirements(),
                'old' => array_diff_key($in, ['db_pass' => 1, 'install_key' => 1]),
                'errors' => ['general' => 'نصب کامل نشد. جزئیات در storage/logs ثبت شد.'],
            ], 500);
        }

        return $this->render($request, 'done', [
            'migrations' => count($ran),
            'partitions' => count($parts),
            'health_token' => $env['HEALTH_TOKEN'],
            'ops_token' => $env['OPS_TOKEN'],
        ]);
    }

    private function guard(): void
    {
        if (is_file(self::LOCK)) {
            throw new HttpException(404);
        }
    }

    private function ensureKey(): void
    {
        if (!is_file(self::KEY)) {
            file_put_contents(self::KEY, bin2hex(random_bytes(16)) . "\n");
            @chmod(self::KEY, 0600);
        }
    }

    /** @return list<array{name: string, status: string, detail: string}> */
    private function requirements(): array
    {
        return (new HealthCheck($this->c))->basic();
    }

    private static function supportedServer(string $version): bool
    {
        if (preg_match('/(\d+)\.(\d+)\.\d+-MariaDB/i', $version, $m)) {
            return version_compare($m[1] . '.' . $m[2], '10.6', '>=');
        }
        return version_compare((string) preg_replace('/[^0-9.].*$/', '', $version), '8.0.0', '>=');
    }

    /** @param array<string, mixed> $data */
    private function render(Request $request, string $view, array $data, int $status = 200): Response
    {
        $nonce = (string) $request->attribute('csp_nonce');
        $e = static fn (mixed $s): string => htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        extract($data, EXTR_SKIP);
        ob_start();
        require __DIR__ . '/views/layout-top.php';
        require __DIR__ . '/views/' . $view . '.php';
        require __DIR__ . '/views/layout-bottom.php';
        return Response::html((string) ob_get_clean(), $status)->withHeader('Cache-Control', 'no-store');
    }
}
