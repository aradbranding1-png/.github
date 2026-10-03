<?php
declare(strict_types=1);

namespace App\Core;

final class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['_csrf'])) $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        return $_SESSION['_csrf'];
    }

    public static function verify(): void
    {
        $t = $_POST['_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!is_string($t) || empty($_SESSION['_csrf']) || !hash_equals($_SESSION['_csrf'], $t)) {
            throw new HttpException(419);
        }
    }
}
