<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\DB;
use App\Core\Env;
use App\Core\Migrator;
use App\Core\Request;
use App\Core\Validator;
use App\Core\Audit;
use App\Services\Health;

/**
 * Web installer. Only reachable while the system is NOT installed (no .env + installed.lock).
 * After installation the route is not registered at all (404).
 */
final class InstallController
{
    public function index(): string
    {
        if (is_installed()) throw new \App\Core\HttpException(404);
        $checks = Health::requirements();
        $blocking = array_filter($checks, fn($c) => $c['status'] === 'error');
        $errors = [];
        $done = false;

        if (Request::isPost()) {
            $data = Request::all();
            $errors = Validator::check($data, [
                'app_url' => 'required|url|max:200',
                'db_host' => 'required|max:100', 'db_port' => 'required|int', 'db_name' => 'required|max:64', 'db_user' => 'required|max:64',
                'first_name' => 'required|max:100', 'last_name' => 'required|max:100', 'mobile' => 'required|mobile',
                'email' => 'email|max:190', 'password' => 'required|min:8|confirmed', 'site_name' => 'required|max:150',
            ], ['app_url' => 'آدرس سامانه', 'db_host' => 'میزبان دیتابیس', 'db_port' => 'پورت', 'db_name' => 'نام دیتابیس', 'db_user' => 'نام کاربری دیتابیس',
                'first_name' => 'نام', 'last_name' => 'نام خانوادگی', 'mobile' => 'موبایل', 'email' => 'ایمیل', 'password' => 'رمز عبور', 'site_name' => 'نام سامانه']);
            if (!$errors && !Auth::passwordStrongEnough((string)$data['password'])) $errors['password'] = 'رمز عبور باید حداقل ۸ کاراکتر و شامل حرف و عدد باشد.';
            if ($blocking) $errors['req'] = 'پیش‌نیازهای سرور کامل نیست.';

            if (!$errors) {
                try {
                    $pdo = DB::connect(['host' => $data['db_host'], 'port' => $data['db_port'], 'name' => $data['db_name'], 'user' => $data['db_user'], 'pass' => (string)($_POST['db_pass'] ?? '')]);
                    DB::setPdo($pdo);
                } catch (\Throwable $e) {
                    $errors['db'] = 'اتصال به دیتابیس برقرار نشد. نام دیتابیس، کاربر و رمز را بررسی کنید.';
                }
            }
            if (!$errors) {
                $env = [
                    'APP_NAME' => 'AradEdu', 'APP_ENV' => 'production', 'APP_DEBUG' => 'false',
                    'APP_URL' => rtrim((string)$data['app_url'], '/'), 'APP_KEY' => bin2hex(random_bytes(32)),
                    'APP_TIMEZONE' => 'Asia/Tehran', 'APP_PRETTY_URLS' => Request::bool('pretty') ? 'true' : 'false',
                    'DB_HOST' => $data['db_host'], 'DB_PORT' => $data['db_port'], 'DB_DATABASE' => $data['db_name'],
                    'DB_USERNAME' => $data['db_user'], 'DB_PASSWORD' => (string)($_POST['db_pass'] ?? ''),
                    'SESSION_NAME' => 'aradedu_sid', 'SESSION_LIFETIME' => '120', 'TRUSTED_PROXIES' => '',
                    'SSO_CLIENT_ID' => '', 'SSO_CLIENT_SECRET' => '', 'SSO_SHARED_SECRET' => '', 'MY_API_KEY' => '',
                    'UPDATE_PUBLIC_KEY' => '', 'CRON_KEY' => bin2hex(random_bytes(16)),
                ];
                if (!Env::write(BASE_PATH . '/.env', $env)) {
                    $errors['env'] = 'امکان نوشتن فایل تنظیمات وجود ندارد. سطح دسترسی پوشه اصلی پروژه را بررسی کنید.';
                } else {
                    foreach ($env as $k => $v) Env::set($k, (string)$v);
                    $res = Migrator::migrate();
                    if ($res['failed']) {
                        $errors['migrate'] = 'ایجاد جداول ناموفق بود (' . e($res['failed']) . '). جزئیات در لاگ سامانه ثبت شد.';
                        @unlink(BASE_PATH . '/.env');
                    } else {
                        $this->createRoot($data);
                        \App\Core\Settings::set(['site_name' => $data['site_name']]);
                        file_put_contents(STORAGE_PATH . '/installed.lock', date('c') . ' v' . app_version());
                        Audit::log('system.install', 'system', null, 'success', ['version' => app_version()]);
                        $done = true;
                    }
                }
            }
            if ($errors) keep_old($data);
        }

        return view('install/index', [
            'checks' => $checks, 'blocking' => $blocking, 'errors' => $errors, 'done' => $done,
            'suggestUrl' => base_url(),
        ], 'layouts/bare');
    }

    private function createRoot(array $d): void
    {
        $existing = DB::value('SELECT id FROM users WHERE is_root = 1 LIMIT 1');
        if ($existing) return;
        $id = DB::insert('users', [
            'uuid' => uuid4(), 'first_name' => $d['first_name'], 'last_name' => $d['last_name'],
            'mobile' => normalize_input((string)$d['mobile']), 'email' => ($d['email'] ?? '') !== '' ? $d['email'] : null,
            'password_hash' => Auth::hashPassword((string)$d['password']), 'segment' => 'employee',
            'status' => 'active', 'is_root' => 1, 'created_at' => now(),
        ]);
        $rid = (int)DB::value("SELECT id FROM roles WHERE slug = 'root_admin'");
        DB::insert('user_roles', ['user_id' => $id, 'role_id' => $rid, 'assigned_at' => now()]);
    }
}
