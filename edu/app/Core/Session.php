<?php
declare(strict_types=1);

namespace App\Core;

final class Session
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) return;
        $dir = STORAGE_PATH . '/sessions';
        if (is_dir($dir) && is_writable($dir)) session_save_path($dir);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.gc_maxlifetime', (string)((int)env('SESSION_LIFETIME', 120) * 60));
        ini_set('session.gc_probability', '1');
        ini_set('session.gc_divisor', '100');
        session_name(env('SESSION_NAME', 'aradedu_sid'));
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => base_path_uri() ?: '/',
            'secure' => Request::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();

        $idle = (int)env('SESSION_LIFETIME', 120) * 60;
        if (isset($_SESSION['_last']) && time() - $_SESSION['_last'] > $idle) {
            $_SESSION = [];
            session_regenerate_id(true);
        }
        $_SESSION['_last'] = time();
    }

    public static function regenerate(): void
    {
        session_regenerate_id(true);
        unset($_SESSION['_csrf']);
    }

    public static function destroy(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        @session_destroy();
    }
}
