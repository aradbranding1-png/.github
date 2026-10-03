<?php
declare(strict_types=1);

/*
 * سامانه آموزش آراد برندینگ — Bootstrap
 * این فایل خارج از Web Root قرار دارد و فقط از طریق public_html/index.php، cli.php و cron.php بارگذاری می‌شود.
 */

define('BASE_PATH', dirname(__DIR__));
define('APP_PATH', BASE_PATH . '/app');
define('STORAGE_PATH', BASE_PATH . '/storage');
define('APP_START', microtime(true));

if (PHP_VERSION_ID < 80100) {
    http_response_code(500);
    exit('PHP 8.1 or newer is required.');
}

spl_autoload_register(function (string $class): void {
    if (strncmp($class, 'App\\', 4) !== 0) return;
    $file = APP_PATH . '/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) require $file;
});

require APP_PATH . '/helpers.php';

App\Core\Env::load(BASE_PATH . '/.env');

date_default_timezone_set(env('APP_TIMEZONE', 'Asia/Tehran'));
mb_internal_encoding('UTF-8');
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

App\Core\ErrorHandler::register();
