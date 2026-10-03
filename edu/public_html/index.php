<?php
/**
 * سامانه آموزش آراد برندینگ — Front Controller
 * تنها فایل PHP قابل دسترس از وب. تمام کد، تنظیمات، لاگ‌ها و فایل‌ها خارج از public_html هستند.
 */
declare(strict_types=1);

$bootstrap = dirname(__DIR__) . '/app/bootstrap.php';
if (!is_file($bootstrap)) {
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><div style="font-family:tahoma;direction:rtl;text-align:center;padding:60px">سامانه به درستی مستقر نشده است. لطفاً با مدیر سامانه تماس بگیرید.</div>';
    exit;
}
require $bootstrap;

App\Core\Kernel::handle();
