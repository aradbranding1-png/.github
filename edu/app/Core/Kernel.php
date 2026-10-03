<?php
declare(strict_types=1);

namespace App\Core;

final class Kernel
{
    public static function securityHeaders(): void
    {
        if (headers_sent()) return;
        header('X-Frame-Options: SAMEORIGIN');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
        header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; media-src 'self' blob: https:; font-src 'self' data:; connect-src 'self'; frame-src 'self' https://www.aparat.com https://www.youtube.com; frame-ancestors 'self'; form-action 'self' https:; base-uri 'self'; object-src 'none'");
        if (Request::isHttps()) header('Strict-Transport-Security: max-age=15552000');
        header_remove('X-Powered-By');
    }

    public static function handle(): void
    {
        self::securityHeaders();
        $path = Request::path();
        $method = Request::method();

        if (!is_installed()) {
            Session::start();
            if ($path === '/__rewrite_test') { header('Content-Type: text/plain'); echo 'rewrite-ok'; return; }
            if (!str_starts_with($path, '/install')) {
                header('Location: ' . base_url() . '/index.php?r=/install');
                return;
            }
            $r = new Router();
            $r->any('/install', [\App\Controllers\InstallController::class, 'index'], ['auth' => false, 'csrf' => true]);
            $r->dispatch($method, $path);
            return;
        }

        // Public machine endpoints (no session)
        if (str_starts_with($path, '/api/')) {
            $router = self::router();
            $router->dispatch($method, $path);
            return;
        }

        Session::start();

        if (is_file(STORAGE_PATH . '/maintenance.flag')) {
            $allowed = str_starts_with($path, '/login') || str_starts_with($path, '/admin/system/updates') || $path === '/logout';
            if (!(Auth::check() && Gate::isRoot()) && !$allowed) throw new HttpException(503);
        }

        if ($u = Auth::user()) {
            if (empty($u['last_activity_at']) || strtotime((string)$u['last_activity_at']) < time() - 300) {
                DB::run('UPDATE users SET last_activity_at = NOW() WHERE id = ?', [(int)$u['id']]);
            }
            View::share('me', $u);
            // accounts created with a generated password (e.g. from Arad Contact) must choose their own first
            if ((int)($u['must_change_password'] ?? 0) === 1 && !Auth::isImpersonating() && !in_array($path, ['/password/change', '/logout'], true) && !str_starts_with($path, '/avatar/')) {
                if ($method === 'GET' && !Request::isAjax()) redirect('/password/change');
                throw new HttpException(403, 'ابتدا رمز عبور خود را تغییر دهید.');
            }
        }
        View::share('currentPath', $path);

        self::router()->dispatch($method, $path);
    }

    public static function router(): Router
    {
        $router = new Router();
        (require APP_PATH . '/routes.php')($router);
        return $router;
    }
}
