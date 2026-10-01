<?php
/**
 * مدارکِ مشتری:
 *   GET  ?customer_id=…  → نمایشِ امنِ تصویرِ کارت ملی
 *   POST                 → ذخیره/ویرایشِ کارت ملی، کد ملی، آدرس و کد پستی (از پرونده‌ی مشتری)
 */
require_once __DIR__ . '/includes/auth.php';
$user = require_login();
$pdo = db();
require_once __DIR__ . '/includes/orders_functions.php';

$customerId = (int) ($_GET['customer_id'] ?? $_POST['customer_id'] ?? 0);
if ($customerId <= 0 || !orders_ready($pdo) || !finance_schema_ready($pdo)) {
    http_response_code(404);
    exit('پیدا نشد.');
}
if (!kyc_can_view($pdo, $user, $customerId)) {
    perm_deny('اجازه‌ی دسترسی به مدارکِ این مشتری را ندارید.', $user);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $back = 'customer_view.php?id=' . $customerId . '#customer-kyc';
    if (!csrf_verify()) {
        flash_set('danger', 'نشست منقضی شده است؛ دوباره تلاش کنید.');
        redirect($back);
    }
    $errors = [];
    // فرمِ کاملِ مدارک (پرونده‌ی مشتری): عنوان و نام پدر اجباری است
    if (isset($_POST['father_name']) || isset($_POST['title'])) {
        if (!in_array((string) ($_POST['title'] ?? ''), ['آقای', 'خانم'], true)) $errors[] = 'عنوانِ مشتری (آقای/خانم) را انتخاب کنید.';
        if (trim((string) ($_POST['father_name'] ?? '')) === '') $errors[] = 'نام پدر الزامی است.';
    }
    if (!$errors) {
        $errors = kyc_save($pdo, $customerId, [
            'national_id' => (string) ($_POST['national_id'] ?? ''),
            'id_type'     => (string) ($_POST['id_type'] ?? ''),
            'postal_code' => (string) ($_POST['postal_code'] ?? ''),
            'address'     => (string) ($_POST['address'] ?? ''),
            'father_name' => (string) ($_POST['father_name'] ?? ''),
            'title'       => (string) ($_POST['title'] ?? ''),
        ], $_FILES['national_card'] ?? null, (int) $user['id']);
    }
    if (isset($_POST['verify_card']) && user_can('finance_orders_decide', $user)) {
        $pdo->prepare('UPDATE customer_kyc SET card_verified_by = ?, card_verified_at = NOW() WHERE customer_id = ?')->execute([(int) $user['id'], $customerId]);
    }
    flash_set($errors ? 'danger' : 'success', $errors ? implode(' ', $errors) : 'مدارک و اطلاعاتِ مشتری ذخیره شد.');
    $ret = (string) ($_POST['return'] ?? '');
    redirect(preg_match('#^[a-z_]+\.php(\?[^\s"\'<>]*)?$#i', $ret) ? $ret : $back);
}

$k = kyc_get($pdo, $customerId);
if (!$k['has_card']) {
    http_response_code(404);
    exit('کارت ملی بارگذاری نشده است.');
}
$base = realpath(kyc_docs_dir());
$path = realpath($base . '/' . $k['card_path']);
if (!$path || strpos($path, $base) !== 0 || !is_file($path)) {
    http_response_code(404);
    exit('فایل پیدا نشد.');
}
header('Content-Type: ' . ($k['card_mime'] ?: 'application/octet-stream'));
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=600');
header("Content-Disposition: inline; filename*=UTF-8''" . rawurlencode((string) ($k['card_original_name'] ?: basename($path))));
readfile($path);
