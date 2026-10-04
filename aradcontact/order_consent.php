<?php
/**
 * اسکرین‌شاتِ «پیامِ رضایتِ پرداخت»ِ یک سفارش:
 *   GET  ?order_id=…              → نمایشِ امنِ فایل (هر کسی که اجازه‌ی دیدنِ سفارش را دارد)
 *   POST action=upload            → بارگذاری/جایگزینی (کارشناسِ سفارش، سرپرستش، مالی)
 *   POST action=approve|reject    → تأیید/ردِ مالی
 */
require_once __DIR__ . '/includes/auth.php';
$user = require_login();
$pdo = db();
require_once __DIR__ . '/includes/services_functions.php';
require_once __DIR__ . '/includes/consent_functions.php';

$orderId = (int) ($_GET['order_id'] ?? $_POST['order_id'] ?? 0);
$order = ($orderId > 0 && orders_ready($pdo)) ? orders_get($pdo, $orderId) : null;
if (!$order || !orders_can_view($pdo, $user, $order) || !consent_ready($pdo)) {
    http_response_code(403);
    exit('دسترسی ندارید.');
}
$back = 'order_view.php?id=' . $orderId . '#order-consent';
// از صفِ «پیام‌های رضایت» در پنلِ مالی ← برگشت به همان صف
if ((string) ($_POST['back'] ?? '') === 'consents') $back = 'admin/admin_orders.php?view=consents';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) { flash_set('danger', 'نشست منقضی شده است؛ دوباره تلاش کنید.'); redirect($back); }
    $a = (string) ($_POST['action'] ?? '');
    if ($a === 'upload') {
        if (!consent_can_upload($pdo, $user, $order)) { flash_set('danger', 'اجازه‌ی این کار را ندارید.'); redirect($back); }
        $r = consent_store($pdo, $order, $_FILES['consent'] ?? [], (int) $user['id']);
    } elseif (in_array($a, ['approve', 'reject'], true)) {
        if (!is_super_admin($user) && !user_can('finance_orders_decide', $user)) { flash_set('danger', 'فقط واحد مالی می‌تواند تأیید یا رد کند.'); redirect($back); }
        $r = consent_decide($pdo, $order, $a === 'approve', (int) $user['id'], trim((string) ($_POST['note'] ?? '')));
    } else {
        $r = ['ok' => false, 'message' => 'درخواست نامعتبر است.'];
    }
    flash_set($r['ok'] ? 'success' : 'danger', $r['message']);
    redirect($back);
}

$c = consent_get($pdo, $orderId);
$base = realpath(orders_upload_dir());
$path = !empty($c['file_path']) ? realpath($base . '/' . $c['file_path']) : false;
if (!$path || !$base || strpos($path, $base) !== 0 || !is_file($path)) {
    http_response_code(404);
    exit('فایل پیدا نشد.');
}
header('Content-Type: ' . ((string) ($c['mime'] ?? '') ?: 'application/octet-stream'));
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=600');
header("Content-Disposition: inline; filename*=UTF-8''" . rawurlencode((string) ($c['original_name'] ?: basename($path))));
readfile($path);
