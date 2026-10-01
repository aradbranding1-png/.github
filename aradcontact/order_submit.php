<?php
/**
 * تبدیلِ پیش‌فاکتور به فاکتور و ثبتِ سفارش (با فیشِ واریزی) برای واحد مالی.
 *   ?quote_id=…  → ثبتِ سفارشِ جدید از پیش‌فاکتورِ قفل‌شده
 *   ?order_id=…  → اصلاح و ارسالِ دوباره‌ی سفارشِ ردشده
 */
require_once __DIR__ . '/includes/auth.php';
$user = require_login();
$pdo = db();
require_once __DIR__ . '/includes/services_functions.php';
require_once __DIR__ . '/includes/orders_functions.php';
require_once __DIR__ . '/includes/payment_duplicates.php';
require_once __DIR__ . '/includes/performance_functions.php';
require_once __DIR__ . '/includes/consent_functions.php';

if (!services_module_ready($pdo) || !orders_ready($pdo)) {
    require_once __DIR__ . '/includes/layout_top.php';
    echo services_module_not_ready_html();
    require_once __DIR__ . '/includes/layout_bottom.php';
    exit;
}

$quoteId = (int) ($_GET['quote_id'] ?? $_POST['quote_id'] ?? 0);
$orderId = (int) ($_GET['order_id'] ?? $_POST['order_id'] ?? 0);
$order = null;

if ($orderId > 0) {
    $order = orders_get($pdo, $orderId);
    if (!$order) {
        perm_deny('سفارش پیدا نشد.', $user);
    }
    $quoteId = (int) $order['quote_id'];
}

$st = $pdo->prepare('SELECT q.*, c.full_name AS customer_name, c.mobile AS customer_mobile, c.owner_user_id
                     FROM quotes q JOIN customers c ON c.id = q.customer_id WHERE q.id = ? LIMIT 1');
$st->execute([$quoteId]);
$quote = $st->fetch(PDO::FETCH_ASSOC);
if (!$quote) {
    perm_deny('پیش‌فاکتور پیدا نشد.', $user);
}
$canManage = ((int) $quote['owner_user_id'] === (int) $user['id'])
    || (int) $quote['created_by'] === (int) $user['id']
    || can_manage_service_requests($user)
    || leader_supervises_owner($pdo, $user, (int) $quote['owner_user_id'])
    || is_super_admin($user);
if (!$canManage || ($order && (int) $order['seller_user_id'] !== (int) $user['id'] && !is_super_admin($user) && !can_manage_service_requests($user) && !leader_supervises_owner($pdo, $user, (int) $order['seller_user_id']))) {
    perm_deny('فقط کارشناسِ همین مشتری (یا سرپرستش) می‌تواند برای این پیش‌فاکتور سفارش ثبت کند.', $user);
}

if (!$order) {
    $existing = orders_active_for_quote($pdo, $quoteId);
    if ($existing) {
        flash_set('info', 'برای این پیش‌فاکتور قبلاً سفارش ثبت شده است.');
        redirect('order_view.php?id=' . (int) $existing['id']);
    }
    if ($quote['status'] !== 'locked') {
        flash_set('warning', 'اول پیش‌فاکتور را قفل کنید تا مبالغ ثابت شوند، بعد «تبدیل به فاکتور و ثبت سفارش» را بزنید.');
        redirect('quote_edit.php?id=' . $quoteId);
    }
} elseif ($order['status'] !== 'rejected') {
    flash_set('info', 'فقط سفارشِ ردشده قابل اصلاح و ارسالِ دوباره است.');
    redirect('order_view.php?id=' . $orderId);
}

orders_ensure_settle_cols($pdo);
$methods = orders_payment_methods(true);
$settleTypes = orders_settle_types();
$errors = [];
$dupWarn = [];   // واریزیِ مشابهی که کارشناسِ دیگری قبلاً ثبت کرده
$kyc = kyc_get($pdo, (int) $quote['customer_id']);
$existingInst = $order ? fin_installments($pdo, (int) $order['id']) : [];
$instRows = [];
$chqRows = [];
foreach ($existingInst as $i) {
    if (($i['kind'] ?? 'installment') === 'cheque') {
        $chqRows[] = ['amount' => (int) $i['amount'], 'due' => to_jalali($i['due_date']), 'no' => (string) ($i['cheque_no'] ?? ''), 'bank' => (string) ($i['bank'] ?? '')];
    } else {
        $instRows[] = ['amount' => (int) $i['amount'], 'due' => to_jalali($i['due_date']), 'note' => (string) ($i['note'] ?? '')];
    }
}
// فاکتورِ قبلیِ همین پیش‌فاکتور که بعد از ردِ مالی برای اصلاح لغو شد → اطلاعاتِ پرداخت و فیش‌هایش منتقل می‌شود
$prevOrder = null;
$prevFiles = [];
if (!$order) {
    $pq = $pdo->prepare("SELECT * FROM sales_orders WHERE quote_id = ? AND status = 'cancelled' ORDER BY id DESC LIMIT 1");
    $pq->execute([$quoteId]);
    $prevOrder = $pq->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($prevOrder) {
        try {
            $pf = $pdo->prepare("SELECT f.* FROM sales_order_files f WHERE f.order_id = ?
                AND (f.payment_id IS NULL OR f.payment_id IN (SELECT id FROM sales_order_payments WHERE order_id = ? AND kind = 'initial')) ORDER BY f.id");
            $pf->execute([(int) $prevOrder['id'], (int) $prevOrder['id']]);
            $prevFiles = $pf->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
        catch (Throwable $e) { $pf = $pdo->prepare('SELECT * FROM sales_order_files WHERE order_id = ? ORDER BY id'); $pf->execute([(int) $prevOrder['id']]); $prevFiles = $pf->fetchAll(PDO::FETCH_ASSOC) ?: []; }
    }
}
$__src = $order ?: $prevOrder;
$old = [
    // مبلغِ پرداخت‌شده را کارشناس خودش وارد می‌کند (پیش‌فرض خالی؛ نه مبلغِ فاکتور)
    'paid_amount'    => $__src ? (string) $__src['paid_amount'] : '',
    'payment_method' => $__src && isset($methods[$__src['payment_method']]) ? $__src['payment_method'] : ($__src ? '' : 'card_to_card'),
    'payment_date'   => $__src && $__src['payment_date'] ? to_jalali($__src['payment_date']) : today_jalali(),
    'payment_ref'    => $__src['payment_ref'] ?? '',
    'payer_name'     => $__src['payer_name'] ?? $quote['customer_name'],
    'seller_note'    => $__src['seller_note'] ?? '',
    'barter_desc'    => $__src['barter_desc'] ?? '',
    'settle_type'    => $order ? (string) ($order['settle_type'] ?? ($existingInst ? (($existingInst[0]['kind'] ?? '') === 'cheque' ? 'cheque' : 'installment') : 'full')) : '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $errors[] = 'نشست منقضی شده است؛ دوباره تلاش کنید.';
    }
    foreach ($old as $k => $v) {
        $old[$k] = trim((string) ($_POST[$k] ?? ''));
    }
    $paid = orders_money($old['paid_amount']);
    $method = isset($methods[$old['payment_method']]) ? $old['payment_method'] : '';
    $settleType = isset($settleTypes[$old['settle_type']]) ? $old['settle_type'] : '';
    $payDate = $old['payment_date'] !== '' ? to_gregorian($old['payment_date']) : null;
    $files = orders_normalize_files($_FILES['receipts'] ?? null);
    $isBarter = $method === 'barter';

    if ($paid <= 0) $errors[] = $isBarter ? 'ارزشِ تهاتر (معادلِ تومانی) را وارد کنید.' : 'مبلغِ پرداخت‌شده را وارد کنید.';
    if ($method === '') $errors[] = 'روشِ پرداخت را انتخاب کنید.';
    if ($isBarter && mb_strlen(trim($old['barter_desc'])) < 3) $errors[] = 'مشخص کنید تهاتر با چه چیزی بوده (مثلاً: خودرو پژو ۲۰۶ مدل ۹۸، ۲ سکه‌ی تمام).';
    if ($settleType === '') $errors[] = 'شیوه‌ی تسویه (تسویه‌ی کامل / اقساطی / چکی) را انتخاب کنید.';
    if ($old['payment_date'] !== '' && !$payDate) $errors[] = 'تاریخِ پرداخت معتبر نیست.';
    $existingFiles = $order ? orders_files($pdo, (int) $order['id']) : [];
    if (!$files && !$existingFiles && !$prevFiles && !$isBarter) {
        $errors[] = 'تصویرِ فیشِ واریزی (یا رسیدِ پرداخت) را بارگذاری کنید.';
    }
    $errors = array_merge($errors, orders_validate_files($files));

    // ─── مدارکِ مشتری: فقط مواردی که هنوز ثبت نشده الزامی است ───
    $kycData = [];
    foreach (['national_id', 'id_type', 'postal_code', 'address', 'father_name', 'title'] as $kf) {
        if (isset($_POST[$kf])) $kycData[$kf] = (string) $_POST[$kf];
    }
    $cardFile = $_FILES['national_card'] ?? null;
    $cardSent = $cardFile && (int) ($cardFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    if (!$kyc['has_card'] && !$cardSent) $errors[] = 'تصویرِ کارت ملیِ مشتری (برای اتباع: کارتِ اقامت یا پاسپورت) را بارگذاری کنید (فقط یک‌بار لازم است).';
    if (!$kyc['has_national_id'] && trim($kycData['national_id'] ?? '') === '') $errors[] = 'کد ملیِ مشتری را وارد کنید (برای اتباع: کد فراگیر اتباع یا شماره پاسپورت).';
    if (!$kyc['has_address'] && trim($kycData['address'] ?? '') === '') $errors[] = 'آدرسِ مشتری را وارد کنید.';
    if (!$kyc['has_postal'] && trim($kycData['postal_code'] ?? '') === '') $errors[] = 'کد پستیِ مشتری را وارد کنید.';
    if (!$kyc['has_title'] && !in_array($kycData['title'] ?? '', ['آقای', 'خانم'], true)) $errors[] = 'عنوانِ مشتری (آقای/خانم) را انتخاب کنید.';
    if (!$kyc['has_father'] && trim($kycData['father_name'] ?? '') === '') $errors[] = 'نام پدرِ مشتری را وارد کنید.';

    // ─── شیوه‌ی تسویه‌ی مانده: تسویه‌ی کامل / اقساطی / چکی ───
    $total = (int) $quote['total_amount'];
    $remaining = max(0, $total - $paid);
    $instParsed = $settleType === 'installment' ? fin_parse_installment_post($_POST) : [];
    $chqParsed = $settleType === 'cheque' ? fin_parse_cheque_post($_POST) : [];
    $instRows = array_map(static fn($r) => ['amount' => $r['amount'], 'due' => $r['due_raw'], 'note' => $r['note']], fin_parse_installment_post($_POST));
    $chqRows = array_map(static fn($r) => ['amount' => $r['amount'], 'due' => $r['due_raw'], 'no' => $r['cheque_no'], 'bank' => $r['bank']], fin_parse_cheque_post($_POST));
    $scheduleRows = $settleType === 'cheque' ? $chqParsed : $instParsed;
    $what = $settleType === 'cheque' ? 'چک' : 'قسط';
    if ($settleType === 'full') {
        if ($remaining > 0) {
            $errors[] = 'با «تسویه‌ی کامل» مبلغِ پرداختی باید حداقل برابرِ مبلغِ فاکتور باشد؛ ' . number_format($remaining) . ' تومان مانده. اگر مانده دارد «اقساطی» یا «چکی» را انتخاب کنید.';
        }
    } elseif ($settleType !== '') {
        foreach ($scheduleRows as $r) {
            if ($r['due_date'] === 'bad' || $r['due_date'] === '') { $errors[] = 'تاریخِ سررسیدِ همه‌ی ' . $what . '‌ها را درست وارد کنید.'; break; }
            if ($r['amount'] <= 0) { $errors[] = 'مبلغِ همه‌ی ' . $what . '‌ها باید بزرگ‌تر از صفر باشد.'; break; }
            if ($settleType === 'cheque' && trim((string) $r['cheque_no']) === '') { $errors[] = 'شماره‌ی همه‌ی چک‌ها را وارد کنید.'; break; }
        }
        $schSum = array_sum(array_column($scheduleRows, 'amount'));
        if ($remaining <= 0) {
            $errors[] = 'مشتری کلِ مبلغِ فاکتور را پرداخت کرده؛ شیوه‌ی تسویه را «تسویه‌ی کامل» بگذارید.';
        } elseif (!$scheduleRows) {
            $errors[] = 'برای مانده (' . number_format($remaining) . ' تومان) ' . ($settleType === 'cheque' ? 'چک‌ها را با مبلغ، شماره و تاریخِ سررسید' : 'اقساط را با مبلغ و تاریخِ سررسید') . ' وارد کنید.';
        } elseif ($schSum !== $remaining) {
            $errors[] = 'جمعِ ' . $what . '‌ها (' . number_format($schSum) . ' تومان) باید دقیقاً برابر با مانده‌ی فاکتور (' . number_format($remaining) . ' تومان) باشد.';
        }
    }

    // ─── مالکیتِ مشتری (سمتِ سرور): A/C فقط صاحبِ همان جایگاه، B فقط Bهای ثبت‌شده ───
    if (!$errors && ($ownErr = ps_validate_order_owner($pdo, (int) $quote['customer_id'], $user))) {
        $errors[] = $ownErr;
    }

    // ─── واریزیِ تکراری: همین واریزی را کارشناسِ دیگری قبلاً برای همین مشتری ثبت کرده؟ ───
    if (!$errors && !$isBarter && pdup_ready($pdo)) {
        $probe = [
            'id' => $order ? (int) $order['id'] : 0, 'customer_id' => (int) $quote['customer_id'], 'paid_amount' => $paid,
            'payment_ref' => $old['payment_ref'], 'payer_name' => $old['payer_name'], 'submitted_at' => date('Y-m-d H:i:s'),
        ];
        $tmpHashes = [];
        foreach ($files as $f) {
            $h = @sha1_file((string) $f['tmp_name']);
            if ($h) $tmpHashes[] = $h;
        }
        $dupWarn = pdup_candidates($pdo, $probe, $tmpHashes);
        if ($dupWarn && empty($_POST['dup_confirm'])) {
            $errors[] = 'این واریزی احتمالاً قبلاً توسطِ کارشناسِ دیگری برای همین مشتری ثبت شده (جزئیات در کادرِ قرمز). اگر مطمئنید واریزیِ جداست، تیکِ تأیید را بزنید و دوباره ارسال کنید.';
        }
    }

    if (!$errors && ($kycData || $cardSent)) {
        $kErr = kyc_save($pdo, (int) $quote['customer_id'], $kycData, $cardSent ? $cardFile : null, (int) $user['id']);
        if ($kErr) {
            $errors = array_merge($errors, $kErr);
        } else {
            $kyc = kyc_get($pdo, (int) $quote['customer_id']);
        }
    }

    if (!$errors) {
        $payment = [
            'paid_amount' => $paid, 'payment_method' => $method, 'payment_date' => $payDate,
            'payment_ref' => mb_substr($old['payment_ref'], 0, 100), 'payer_name' => mb_substr($old['payer_name'], 0, 150),
            'seller_note' => $old['seller_note'] . ($dupWarn ? "\n[کارشناس تأیید کرد این واریزی با سفارش‌های مشابهِ دیگر تکراری نیست.]" : ''),
            'installments' => $settleType === 'full' ? [] : $scheduleRows,
            'settle_type' => $settleType, 'barter_desc' => $isBarter ? mb_substr(trim($old['barter_desc']), 0, 500) : '',
        ];
        if (!$order) {
            $res = orders_create_from_quote($pdo, $quote, $user, $payment, $files);
            if (!empty($res['ok']) && !empty($res['id']) && $prevFiles) {
                // فیش‌های فاکتورِ لغوشده‌ی قبلی (همان فایل‌ها) به فاکتورِ جدید وصل می‌شوند
                try {
                    $ip = $pdo->prepare("SELECT id FROM sales_order_payments WHERE order_id = ? AND kind = 'initial' LIMIT 1");
                    $ip->execute([(int) $res['id']]);
                    $newPid = (int) $ip->fetchColumn() ?: null;
                    foreach ($prevFiles as $pfRow) {
                        try {
                            $pdo->prepare('INSERT INTO sales_order_files (order_id, file_path, original_name, mime, size_bytes, uploaded_by, payment_id) VALUES (?,?,?,?,?,?,?)')
                                ->execute([(int) $res['id'], $pfRow['file_path'], $pfRow['original_name'], $pfRow['mime'], (int) $pfRow['size_bytes'], $pfRow['uploaded_by'], $newPid]);
                        } catch (Throwable $e) {
                            $pdo->prepare('INSERT INTO sales_order_files (order_id, file_path, original_name, mime, size_bytes, uploaded_by) VALUES (?,?,?,?,?,?)')
                                ->execute([(int) $res['id'], $pfRow['file_path'], $pfRow['original_name'], $pfRow['mime'], (int) $pfRow['size_bytes'], $pfRow['uploaded_by']]);
                        }
                    }
                    orders_add_history($pdo, (int) $res['id'], (int) $user['id'], 'receipt_added', null, null,
                        to_persian_digits((string) count($prevFiles)) . ' فیش از فاکتورِ قبلیِ لغوشده (' . $prevOrder['order_number'] . ') منتقل شد.');
                } catch (Throwable $e) {
                    error_log('carry receipts: ' . $e->getMessage());
                }
            }
            if (!empty($res['ok']) && !empty($res['id'])) {
                // snapshot: مالکانِ A/B/C، تیم/سرپرست، درصدِ سهمِ پایه و مالیات در لحظه‌ی ثبتِ سفارش (دیگر تغییر نمی‌کند)
                try { ps_order_snapshot($pdo, (int) $res['id'], $user, 'order'); } catch (Throwable $e) { error_log('ps snapshot: ' . $e->getMessage()); }
            }
            if ($res['ok']) {
                // اطلاع به واحد مالی (پیامِ گفتگو)
                try {
                    $fin = $pdo->query("SELECT id FROM users WHERE is_active = 1 AND service_access_role = 'financial_liaison'")->fetchAll(PDO::FETCH_COLUMN) ?: [];
                    foreach ($fin as $fid) {
                        orders_notify($pdo, (int) $user['id'], (int) $fid, 'سفارشِ جدید برای بررسی: ' . $quote['customer_name'] . ' — مبلغ فاکتور ' . number_format((int) $quote['total_amount']) . ' تومان. (پنل مدیریت ← سفارشات و بررسی مالی)');
                    }
                } catch (Throwable $e) {
                }
                // اسکرین‌شاتِ پیامِ رضایتِ مشتری (اختیاری؛ اگر الان نیست، بعداً در صفحه‌ی سفارش — تا آن موقع نارنجی می‌ماند)
                $consentMsg = '';
                if (!empty($_FILES['consent_screenshot']['name']) && (int) ($_FILES['consent_screenshot']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    $newOrder = orders_get($pdo, (int) $res['id']);
                    if ($newOrder) { $cr = consent_store($pdo, $newOrder, $_FILES['consent_screenshot'], (int) $user['id']); $consentMsg = ' ' . $cr['message']; }
                } else {
                    $consentMsg = ' یادتان باشد اسکرین‌شاتِ پیامِ رضایتِ مشتری را هم در صفحه‌ی سفارش بارگذاری کنید.';
                }
                flash_set('success', $res['message'] . $consentMsg);
                redirect('order_view.php?id=' . (int) $res['id']);
            }
            $errors[] = $res['message'];
        } else {
            try {
                $pdo->prepare("UPDATE sales_orders SET paid_amount = ?, payment_method = ?, payment_date = ?, payment_ref = ?, payer_name = ?, seller_note = ?,
                               status = 'pending', submitted_at = NOW(), decided_at = NULL WHERE id = ?")
                    ->execute([$paid, $method, $payDate, $payment['payment_ref'] ?: null, $payment['payer_name'] ?: null, $payment['seller_note'] ?: null, (int) $order['id']]);
                $pdo->prepare('UPDATE sales_orders SET settle_type = ?, barter_desc = ? WHERE id = ?')
                    ->execute([$settleType, $payment['barter_desc'] !== '' ? $payment['barter_desc'] : null, (int) $order['id']]);
                $pdo->prepare("UPDATE sales_order_payments SET amount = ?, paid_at = ?, method = ?, ref = ?, status = 'pending', decided_by = NULL, decided_at = NULL WHERE order_id = ? AND kind = 'initial'")
                    ->execute([$paid, $payDate, $method, $payment['payment_ref'] ?: null, (int) $order['id']]);
                $ip = $pdo->prepare("SELECT id FROM sales_order_payments WHERE order_id = ? AND kind = 'initial' LIMIT 1");
                $ip->execute([(int) $order['id']]);
                orders_store_files($pdo, (int) $order['id'], $files, (int) $user['id'], ((int) $ip->fetchColumn()) ?: null);
                fin_set_installments($pdo, $order, $payment['installments'], (int) $user['id']);
                orders_add_history($pdo, (int) $order['id'], (int) $user['id'], 'resubmitted', 'rejected', 'pending', 'اصلاح و ارسالِ دوباره برای بررسیِ مالی.');
                if (!empty($order['finance_user_id'])) {
                    orders_notify($pdo, (int) $user['id'], (int) $order['finance_user_id'], 'سفارش ' . $order['order_number'] . ' اصلاح و دوباره برای بررسی ارسال شد.');
                }
                flash_set('success', 'سفارش اصلاح و دوباره برای واحد مالی ارسال شد.');
                redirect('order_view.php?id=' . (int) $order['id']);
            } catch (Throwable $e) {
                $errors[] = 'خطا در ذخیره: ' . $e->getMessage();
            }
        }
    }
}

$itemsSt = $pdo->prepare('SELECT * FROM quote_items WHERE quote_id = ? ORDER BY id');
$itemsSt->execute([$quoteId]);
$items = $itemsSt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$pageTitle = $order ? 'اصلاح سفارش ' . $order['order_number'] : 'ثبت سفارش';
require_once __DIR__ . '/includes/layout_top.php';
?>
<style>
.os{--l:#e7e2d3;--g:#c9a24b;--g2:#f1dfa8}
.os .hero{background:linear-gradient(135deg,#052e1c 0%,#14532d 55%,#22c55e 140%);border-radius:18px;padding:18px 22px;color:#ecfdf5;margin-bottom:16px}
.os .hero h5{margin:0;font-weight:800;color:#ecfdf5}.os .hero p{margin:.3rem 0 0;font-size:.8rem;color:#d1fae5}
.os .card{border:1px solid var(--l);border-radius:16px;box-shadow:0 4px 16px -14px rgba(28,25,23,.3)}
.os .steps{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}
.os .step{background:rgba(255,255,255,.14);border-radius:999px;padding:3px 12px;font-size:.74rem}
.os .step.on{background:#ecfdf5;color:#14532d;font-weight:800}
.os .drop{border:2px dashed #cbd5c0;border-radius:14px;padding:18px;text-align:center;background:#fbfdf9;cursor:pointer}
.os .drop:hover{border-color:#22c55e}
.os .thumbs{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}
.os .thumbs div{width:84px;height:84px;border-radius:10px;border:1px solid var(--l);overflow:hidden;display:flex;align-items:center;justify-content:center;font-size:.7rem;background:#fff}
.os .thumbs img{width:100%;height:100%;object-fit:cover}
.os .btn-green{background:linear-gradient(135deg,#86efac,#16a34a);border:none;color:#052e1c;font-weight:800}
.os .sum td{padding:.3rem .2rem}
.os .kyc-box{border:1px solid #e7e2d3;border-radius:12px;padding:.8rem .9rem;background:#fafaf9}
.os .kyc-box.need{border-color:#f59e0b;background:#fffbeb}
.os .kyc-box.ok{border-color:#86efac;background:#f0fdf4}
.os .inst-box{border:1px solid #fcd34d;background:#fffdf5;border-radius:12px;padding:.8rem .9rem}
.os .inst-row{display:grid;grid-template-columns:28px 1fr 1fr 1.2fr 34px;gap:6px;align-items:center;margin-bottom:6px}
.os .inst-row .n{font-weight:800;color:#8a6a1e;text-align:center}
.os .chq-row,.os .chq-head{display:grid;grid-template-columns:28px 1fr 1fr 1fr 1fr 34px;gap:6px;align-items:center;margin-bottom:6px}
@media(max-width:575px){.os .chq-row,.os .chq-head{grid-template-columns:24px 1fr 1fr 1fr 30px}.os .chq-row .nt,.os .chq-head .nt{display:none}}
@media(max-width:575px){.os .inst-row{grid-template-columns:24px 1fr 1fr 30px}.os .inst-row .nt{display:none}}
</style>

<div class="os">
  <a href="<?= $order ? 'order_view.php?id=' . (int) $order['id'] : 'quote_edit.php?id=' . $quoteId ?>" class="btn btn-sm btn-outline-secondary mb-3"><i class="fa-solid fa-arrow-right"></i> بازگشت</a>

  <div class="hero">
    <h5><i class="fa-solid fa-file-invoice-dollar"></i> <?= $order ? 'اصلاح و ارسالِ دوباره‌ی سفارش ' . e(to_persian_digits($order['order_number'])) : 'تبدیل به فاکتور و ثبت سفارش' ?></h5>
    <p>مشتری: <b><?= e($quote['customer_name']) ?></b> — <span dir="ltr"><?= e($quote['customer_mobile']) ?></span> | پیش‌فاکتور <?= e(to_persian_digits($quote['quote_number'])) ?></p>
    <div class="steps">
      <span class="step">۱. پیش‌فاکتور</span><span class="step">۲. قفل</span><span class="step on">۳. فاکتور + فیش</span><span class="step">۴. بررسیِ مالی</span><span class="step">۵. ثبتِ سفارش</span>
    </div>
  </div>

  <?php if ($order && $order['finance_note']): ?>
    <div class="alert alert-danger"><b>دلیلِ ردِ واحد مالی:</b> <?= nl2br(e($order['finance_note'])) ?></div>
  <?php endif; ?>
  <?php foreach ($errors as $er): ?><div class="alert alert-danger py-2"><?= e($er) ?></div><?php endforeach; ?>

  <div class="row g-3">
    <div class="col-lg-5">
      <div class="card p-3 h-100">
        <h6 class="fw-bold mb-3"><i class="fa-solid fa-list-ul text-success"></i> خدماتِ فاکتور</h6>
        <div class="table-responsive">
          <table class="table table-sm mb-2">
            <thead class="table-light"><tr><th>خدمت</th><th>مقدار</th><th class="text-end">مبلغ</th></tr></thead>
            <tbody>
            <?php foreach ($items as $it): ?>
              <tr><td class="small"><?= e($it['title_snapshot']) ?></td><td class="small"><?= to_persian_digits((string) (float) $it['quantity']) ?> <?= e($it['unit_snapshot']) ?></td><td class="small text-end"><?= format_toman((int) $it['amount']) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <table class="w-100 small sum">
          <tr><td>جمع خدمات</td><td class="text-end"><?= format_toman((int) $quote['subtotal']) ?></td></tr>
          <tr><td>تخفیف</td><td class="text-end text-danger">- <?= format_toman((int) $quote['discount_amount']) ?></td></tr>
          <tr><td>خدمات رایگان</td><td class="text-end text-danger">- <?= format_toman((int) $quote['free_amount']) ?></td></tr>
          <tr><td>مالیات (<?= to_persian_digits((string) (float) $quote['tax_percent']) ?>٪)</td><td class="text-end">+ <?= format_toman((int) $quote['tax_amount']) ?></td></tr>
          <tr class="border-top"><td class="fw-bold pt-2">مبلغ نهایی فاکتور</td><td class="text-end fw-bold fs-6 pt-2 text-success"><?= format_toman((int) $quote['total_amount']) ?></td></tr>
        </table>
      </div>
    </div>

    <div class="col-lg-7">
      <form method="post" enctype="multipart/form-data" class="card p-3">
        <?= csrf_field() ?>
        <input type="hidden" name="quote_id" value="<?= $quoteId ?>">
        <?php if ($order): ?><input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>"><?php endif; ?>
        <?php $kycMissing = kyc_missing_labels($kyc); ?>
        <div class="kyc-box mb-3 <?= $kycMissing ? 'need' : 'ok' ?>">
          <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div class="fw-bold"><i class="fa-solid fa-id-card"></i> مدارکِ مشتری
              <?php if (!$kycMissing): ?><span class="badge text-bg-success ms-1"><i class="fa-solid fa-check"></i> کامل — نیازی به ارسالِ دوباره نیست</span>
              <?php else: ?><span class="badge text-bg-warning ms-1">لازم: <?= e(implode('، ', $kycMissing)) ?></span><?php endif; ?>
            </div>
            <?php if (!$kycMissing): ?><button type="button" class="btn btn-sm btn-link p-0" data-bs-toggle="collapse" data-bs-target="#kycFields">اصلاحِ مدارک</button><?php endif; ?>
          </div>
          <?php if (!$kycMissing): ?>
            <div class="small text-muted mt-1"><?= e((string) $kyc['title']) ?> — فرزندِ <?= e((string) $kyc['father_name']) ?> — <?= e($kyc['id_label']) ?> <span dir="ltr"><?= e((string) $kyc['national_id']) ?></span> — کدپستی <span dir="ltr"><?= e((string) $kyc['postal_code']) ?></span> — <?= e(mb_strimwidth((string) $kyc['address'], 0, 70, '…')) ?></div>
          <?php endif; ?>
          <div class="collapse <?= $kycMissing ? 'show' : '' ?> mt-2" id="kycFields">
            <div class="row g-2">
              <?php if (!$kyc['has_card'] || !$kycMissing): ?>
              <div class="col-12">
                <label class="form-label small mb-1"><?= e(kyc_card_label($kyc)) ?>ِ مشتری <span class="text-muted">(اتباع: کارتِ اقامت / پاسپورت)</span> <?= !$kyc['has_card'] ? '*' : '(برای جایگزینی)' ?></label>
                <input type="file" name="national_card" class="form-control form-control-sm" accept="image/jpeg,image/png,image/webp,application/pdf">
              </div>
              <?php endif; ?>
              <?php if (!$kyc['has_national_id'] || !$kycMissing): ?>
              <div class="col-md-6"><?= kyc_id_fields_html($kyc, (string) ($_POST['national_id'] ?? $kyc['national_id'] ?? ''), !$kyc['has_national_id'], 'form-label small mb-1') ?></div>
              <?php endif; ?>
              <?php if (!$kyc['has_postal'] || !$kycMissing): ?>
              <div class="col-md-6"><label class="form-label small mb-1">کد پستی <?= !$kyc['has_postal'] ? '*' : '' ?></label>
                <input name="postal_code" class="form-control" dir="ltr" inputmode="numeric" maxlength="12" value="<?= e((string) ($_POST['postal_code'] ?? $kyc['postal_code'] ?? '')) ?>"></div>
              <?php endif; ?>
              <?php if (!$kyc['has_title'] || !$kycMissing): ?>
              <div class="col-md-4"><label class="form-label small mb-1">عنوان <?= !$kyc['has_title'] ? '*' : '' ?></label>
                <select name="title" class="form-select"><option value="">انتخاب کنید</option>
                  <?php foreach (['آقای', 'خانم'] as $__t): ?><option value="<?= $__t ?>" <?= (string) ($_POST['title'] ?? $kyc['title'] ?? '') === $__t ? 'selected' : '' ?>><?= $__t ?></option><?php endforeach; ?>
                </select></div>
              <?php endif; ?>
              <?php if (!$kyc['has_father'] || !$kycMissing): ?>
              <div class="col-md-8"><label class="form-label small mb-1">نام پدر <?= !$kyc['has_father'] ? '*' : '' ?></label>
                <input name="father_name" class="form-control" maxlength="100" value="<?= e((string) ($_POST['father_name'] ?? $kyc['father_name'] ?? '')) ?>"></div>
              <?php endif; ?>
              <?php if (!$kyc['has_address'] || !$kycMissing): ?>
              <div class="col-12"><label class="form-label small mb-1">آدرس کامل <?= !$kyc['has_address'] ? '*' : '' ?></label>
                <textarea name="address" class="form-control" rows="2" placeholder="استان، شهر، خیابان، کوچه، پلاک، واحد"><?= e((string) ($_POST['address'] ?? $kyc['address'] ?? '')) ?></textarea></div>
              <?php endif; ?>
            </div>
            <div class="form-text">این اطلاعات در پرونده‌ی مشتری ذخیره می‌شود و برای سفارش‌های بعدی دوباره پرسیده نمی‌شود.</div>
          </div>
        </div>

        <?php if ($dupWarn): ?>
          <div class="alert alert-danger">
            <div class="fw-bold mb-1"><i class="fa-solid fa-clone"></i> این واریزی قبلاً ثبت شده؟</div>
            <div class="small mb-2">برای همین مشتری، سفارشِ مشابهی قبلاً ثبت شده. اگر همان واریزی است، سفارشِ تکراری ثبت نکنید و با آن کارشناس هماهنگ کنید.</div>
            <?php foreach ($dupWarn as $dw): $o2 = $dw['order']; ?>
              <div class="small border-top pt-1 mt-1">
                <b dir="ltr"><?= e(to_persian_digits((string) $o2['order_number'])) ?></b> — کارشناس: <b><?= e((string) ($o2['seller_name'] ?? '—')) ?></b>
                — <?= format_toman((int) $o2['paid_amount']) ?> — <?= to_jalali((string) ($o2['submitted_at'] ?? $o2['created_at'])) ?>
                <span class="badge <?= $dw['level'] === 'certain' ? 'text-bg-danger' : 'text-bg-warning' ?>"><?= $dw['level'] === 'certain' ? 'قطعی' : 'محتمل' ?></span>
                <div class="text-muted"><?= e(implode('؛ ', $dw['reasons'])) ?></div>
              </div>
            <?php endforeach; ?>
            <div class="form-check mt-2">
              <input class="form-check-input" type="checkbox" name="dup_confirm" value="1" id="dup_confirm">
              <label class="form-check-label small fw-bold" for="dup_confirm">مطمئنم این یک واریزیِ جداست و تکراری نیست.</label>
            </div>
            <div class="small text-muted mt-1">فیش‌ها باید دوباره انتخاب شوند.</div>
          </div>
        <?php endif; ?>

        <h6 class="fw-bold mb-3"><i class="fa-solid fa-money-check-dollar text-success"></i> اطلاعاتِ پرداخت</h6>
        <div class="row g-2">
          <div class="col-md-6">
            <label class="form-label small mb-1">روشِ پرداخت *</label>
            <select name="payment_method" id="payMethod" class="form-select" required>
              <?php if ($old['payment_method'] === ''): ?><option value="">انتخاب کنید</option><?php endif; ?>
              <?php foreach ($methods as $k => $v): ?><option value="<?= e($k) ?>" <?= $old['payment_method'] === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label small mb-1" id="paidLabel">مبلغِ پرداخت‌شده (تومان) *</label>
            <input name="paid_amount" id="paidAmount" class="form-control" dir="ltr" required inputmode="numeric" placeholder="مبلغی که مشتری واقعاً پرداخت کرده" value="<?= e($old['paid_amount'] !== '' ? number_format(orders_money($old['paid_amount'])) : '') ?>">
            <div class="form-text barter-only" hidden>ارزشِ کالا/دارایی‌ای که تهاتر شده را به تومان وارد کنید؛ همین مبلغ به‌عنوانِ پرداخت‌شده حساب می‌شود.</div>
            <div class="form-text" id="paidHint"></div>
          </div>
          <div class="col-12" id="barterBox" hidden>
            <div class="border rounded-3 p-2" style="background:#fdf4ff;border-color:#e9d5ff !important">
              <label class="form-label small mb-1"><i class="fa-solid fa-right-left"></i> تهاتر با چه چیزی بوده؟ *</label>
              <input name="barter_desc" class="form-control" maxlength="500" placeholder="مثلاً: خودروی پژو ۲۰۶ مدل ۱۳۹۸ / ۲ سکه‌ی تمام / ۵۰۰ دلار" value="<?= e($old['barter_desc']) ?>">
            </div>
          </div>
          <div class="col-md-4 pay-cash">
            <label class="form-label small mb-1">تاریخِ پرداخت</label>
            <input name="payment_date" class="form-control jalali-date" autocomplete="off" value="<?= e($old['payment_date']) ?>">
          </div>
          <div class="col-md-4 pay-cash">
            <label class="form-label small mb-1">شماره پیگیری / مرجع</label>
            <input name="payment_ref" class="form-control" dir="ltr" value="<?= e($old['payment_ref']) ?>">
          </div>
          <div class="col-md-4 pay-cash">
            <label class="form-label small mb-1">نامِ واریزکننده</label>
            <input name="payer_name" class="form-control" value="<?= e($old['payer_name']) ?>">
          </div>
          <div class="col-12">
            <label class="form-label small mb-1">شیوه‌ی تسویه‌ی فاکتور *</label>
            <div class="d-flex gap-2 flex-wrap" id="settleBox">
              <?php foreach ($settleTypes as $k => $v): ?>
                <input type="radio" class="btn-check" name="settle_type" id="settle_<?= e($k) ?>" value="<?= e($k) ?>" <?= $old['settle_type'] === $k ? 'checked' : '' ?> required>
                <label class="btn btn-sm btn-outline-success" for="settle_<?= e($k) ?>"><?= e($v) ?></label>
              <?php endforeach; ?>
            </div>
            <div class="form-text" id="settleHint"></div>
          </div>
          <div class="col-12">
            <label class="form-label small mb-1"><span class="cash-only">تصویرِ فیشِ واریزی <?= $order ? '(فایلِ جدید اضافه می‌شود)' : ($prevFiles ? '<span class="text-success">(' . to_persian_digits((string) count($prevFiles)) . ' فیش از فاکتورِ ردشده‌ی قبلی خودکار منتقل می‌شود؛ فایلِ جدید اختیاری است)</span>' : '*') ?></span><span class="barter-only" hidden>سندِ تهاتر (اختیاری)</span></label>
            <label class="drop w-100">
              <i class="fa-solid fa-cloud-arrow-up fs-3 text-success"></i>
              <div class="small mt-1">برای انتخابِ تصویر یا PDF کلیک کنید (حداکثر ۶ فایل، هر کدام تا ۸ مگابایت)</div>
              <input type="file" name="receipts[]" id="receipts" accept="image/jpeg,image/png,image/webp,application/pdf" multiple class="d-none">
            </label>
            <div class="thumbs" id="thumbs"></div>
          </div>
          <div class="col-12" id="instWrap" hidden>
            <div class="inst-box">
              <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
                <div class="fw-bold"><i class="fa-solid fa-calendar-days text-warning"></i> اقساطِ مانده: <span id="instRemain" class="text-danger"></span></div>
                <div class="small" id="instSumHint"></div>
              </div>
              <div class="row g-2 align-items-end mb-2">
                <div class="col-4 col-md-3"><label class="form-label small mb-1">تعداد قسط</label><input type="number" min="1" max="36" id="instCount" class="form-control form-control-sm" value="3"></div>
                <div class="col-8 col-md-4"><label class="form-label small mb-1">سررسیدِ قسطِ اول</label><input id="instFirst" class="form-control form-control-sm jalali-date" autocomplete="off" placeholder="۱۴۰۵/۰۸/۰۱"></div>
                <div class="col-6 col-md-3"><label class="form-label small mb-1">فاصله</label>
                  <select id="instGap" class="form-select form-select-sm"><option value="1">ماهانه</option><option value="2">دو ماه</option><option value="3">سه ماه</option><option value="0.5">دو هفته</option></select></div>
                <div class="col-6 col-md-2"><button type="button" class="btn btn-sm btn-outline-primary w-100" id="instBuild"><i class="fa-solid fa-wand-magic-sparkles"></i> ساخت</button></div>
              </div>
              <div id="instRows"></div>
              <button type="button" class="btn btn-sm btn-link px-0" id="instAdd"><i class="fa-solid fa-plus"></i> افزودنِ قسط</button>
            </div>
          </div>
          <div class="col-12" id="chqWrap" hidden>
            <div class="inst-box" style="border-color:#93c5fd;background:#f8fbff">
              <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
                <div class="fw-bold"><i class="fa-solid fa-money-check text-primary"></i> چک‌های مانده: <span id="chqRemain" class="text-danger"></span></div>
                <div class="small" id="chqSumHint"></div>
              </div>
              <div class="chq-head small text-muted"><span></span><span>مبلغ</span><span>سررسید</span><span>شماره چک</span><span class="nt">بانک</span><span></span></div>
              <div id="chqRows"></div>
              <button type="button" class="btn btn-sm btn-link px-0" id="chqAdd"><i class="fa-solid fa-plus"></i> افزودنِ چک</button>
            </div>
          </div>
          <div class="col-12">
            <div class="border rounded-3 p-2" style="background:#fff7ed;border-color:#fdba74 !important">
              <div class="fw-bold small mb-1"><i class="fa-solid fa-file-signature text-warning"></i> پیامِ رضایتِ پرداختِ مشتری</div>
              <div class="small text-muted mb-2">متنِ آماده (با نام، کد ملی و مبلغِ همین فرم) را کپی و برای مشتری بفرستید. اسکرین‌شاتِ ارسالِ پیام را همین‌جا یا بعداً در صفحه‌ی سفارش بارگذاری کنید؛ تا بارگذاری نشود، سفارش برای شما نارنجی می‌ماند و مالی بعد از تأییدِ آن سهمِ عملکرد را پرداخت می‌کند.</div>
              <div class="d-flex flex-wrap gap-2 align-items-center">
                <?= consent_copy_button((string) $quote['customer_name'], $kyc['has_national_id'] ? (string) $kyc['national_id'] : '', 0, 'paidAmount', $kyc['has_national_id'] ? '' : 'national_id', 'btn btn-sm btn-outline-success', (string) ($kyc['id_label'] ?? 'کد ملی')) ?>
                <input type="file" name="consent_screenshot" class="form-control form-control-sm" style="max-width:300px" accept="image/jpeg,image/png,image/webp,application/pdf" title="اسکرین‌شاتِ پیامِ رضایت (اختیاری — بعداً هم می‌شود)">
              </div>
            </div>
          </div>
          <div class="col-12">
            <label class="form-label small mb-1">توضیح برای واحد مالی</label>
            <textarea name="seller_note" class="form-control" rows="2" placeholder="مثلاً: باقی‌مانده تا پایان ماه واریز می‌شود"><?= e($old['seller_note']) ?></textarea>
          </div>
        </div>
        <div class="d-flex justify-content-between align-items-center mt-3 flex-wrap gap-2">
          <div class="small text-muted"><i class="fa-solid fa-circle-info"></i> بعد از ارسال، سفارش در صفِ «در انتظار بررسیِ مالی» قرار می‌گیرد.</div>
          <button class="btn btn-green px-4"><i class="fa-solid fa-paper-plane"></i> <?= $order ? 'ارسالِ دوباره برای مالی' : 'صدور فاکتور و ارسال برای مالی' ?></button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
(function () {
  var total = <?= (int) $quote['total_amount'] ?>;
  var inp = document.getElementById('paidAmount'), hint = document.getElementById('paidHint');
  var fa = function (s) { return String(s).replace(/\d/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[d]; }); };
  function num(v) { v = String(v).replace(/[۰-۹]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'.indexOf(d); }).replace(/\D/g, ''); return v ? parseInt(v, 10) : 0; }
  function sync() {
    var n = num(inp.value);
    inp.value = n ? n.toLocaleString('en-US') : '';
    if (!n) { hint.textContent = ''; return; }
    if (n === total) { hint.innerHTML = '<span class="text-success">برابر با مبلغِ فاکتور</span>'; }
    else if (n < total) { hint.innerHTML = '<span class="text-warning">' + fa((total - n).toLocaleString('en-US')) + ' تومان کمتر از فاکتور (پرداختِ ناقص)</span>'; }
    else { hint.innerHTML = '<span class="text-info">' + fa((n - total).toLocaleString('en-US')) + ' تومان بیشتر از فاکتور</span>'; }
  }
  inp.addEventListener('input', function () { sync(); instSync(); }); sync();

  // ─── اقساط ───
  var wrap = document.getElementById('instWrap'), rowsBox = document.getElementById('instRows');
  var remainEl = document.getElementById('instRemain'), sumHint = document.getElementById('instSumHint');
  var initialRows = <?= json_encode(array_values($instRows), JSON_UNESCAPED_UNICODE) ?>;
  function en(s) { return String(s || '').replace(/[۰-۹]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'.indexOf(d); }); }
  function remaining() { return Math.max(0, total - num(inp.value)); }
  function jAdd(j, months) {
    var p = en(j).split(/[\/\-]/).map(Number); if (p.length !== 3 || !p[0]) return '';
    var y = p[0], m = p[1], d = p[2];
    if (months === 0.5) { d += 14; var dim = m <= 6 ? 31 : (m < 12 ? 30 : 29); if (d > dim) { d -= dim; m++; if (m > 12) { m = 1; y++; } } }
    else { m += months; while (m > 12) { m -= 12; y++; } var mx = m <= 6 ? 31 : (m < 12 ? 30 : 29); if (d > mx) d = mx; }
    return fa(y + '/' + String(m).padStart(2, '0') + '/' + String(d).padStart(2, '0'));
  }
  function addRow(amount, due, note) {
    var r = document.createElement('div'); r.className = 'inst-row';
    r.innerHTML = '<span class="n"></span>' +
      '<input name="inst_amount[]" class="form-control form-control-sm ia" dir="ltr" placeholder="مبلغ">' +
      '<input name="inst_due[]" class="form-control form-control-sm id jalali-date" autocomplete="off" placeholder="سررسید">' +
      '<input name="inst_note[]" class="form-control form-control-sm nt" placeholder="توضیح (اختیاری)">' +
      '<button type="button" class="btn btn-sm btn-outline-danger px-2"><i class="fa-solid fa-xmark"></i></button>';
    r.querySelector('.ia').value = amount ? Number(amount).toLocaleString('en-US') : '';
    r.querySelector('.id').value = due || '';
    r.querySelector('.nt').value = note || '';
    r.querySelector('.ia').addEventListener('input', function () { var n = num(this.value); this.value = n ? n.toLocaleString('en-US') : ''; instSync(); });
    r.querySelector('button').addEventListener('click', function () { r.remove(); instSync(); });
    rowsBox.appendChild(r);
    if (window.jQuery && jQuery.fn.pDatepicker) { try { jQuery(r.querySelector('.id')).pDatepicker({ format: 'YYYY/MM/DD', autoClose: true, initialValue: false, persianDigit: true }); } catch (e) {} }
  }
  function settleVal() { var c = document.querySelector('input[name=settle_type]:checked'); return c ? c.value : ''; }
  function instSync() {
    var rem = remaining(), st = settleVal();
    var showInst = st === 'installment';
    wrap.hidden = !showInst;
    rowsBox.querySelectorAll('.ia, .id, .nt').forEach(function (x) { x.disabled = !showInst; });
    chqSync();
    var sh = document.getElementById('settleHint');
    if (!num(inp.value)) sh.innerHTML = '';
    else if (rem <= 0) sh.innerHTML = st === 'full' ? '<span class="text-success">کلِ فاکتور پرداخت شده.</span>' : '<span class="text-warning">کلِ فاکتور پرداخت شده؛ «تسویه‌ی کامل» را انتخاب کنید.</span>';
    else sh.innerHTML = st === 'full' ? '<span class="text-danger">' + fa(rem.toLocaleString('en-US')) + ' تومان مانده؛ با مانده «اقساطی» یا «چکی» را انتخاب کنید.</span>'
      : (st ? '<span class="text-muted">مانده‌ی ' + fa(rem.toLocaleString('en-US')) + ' تومان را در کادرِ پایین تقسیم کنید.</span>' : '<span class="text-muted">مانده: ' + fa(rem.toLocaleString('en-US')) + ' تومان</span>');
    remainEl.textContent = fa(rem.toLocaleString('en-US')) + ' تومان';
    var sum = 0, i = 0;
    rowsBox.querySelectorAll('.inst-row').forEach(function (r) { i++; r.querySelector('.n').textContent = fa(i); sum += num(r.querySelector('.ia').value); });
    if (!i) { sumHint.innerHTML = '<span class="text-danger">هنوز قسطی تعیین نشده</span>'; return; }
    sumHint.innerHTML = sum === rem ? '<span class="text-success"><i class="fa-solid fa-check"></i> جمعِ اقساط برابر با مانده است</span>'
      : '<span class="text-danger">جمعِ اقساط ' + fa(sum.toLocaleString('en-US')) + ' — ' + (sum < rem ? 'کمتر' : 'بیشتر') + ' از مانده</span>';
  }
  document.getElementById('instBuild').addEventListener('click', function () {
    var cnt = Math.max(1, Math.min(36, parseInt(document.getElementById('instCount').value, 10) || 1));
    var first = document.getElementById('instFirst').value.trim();
    var gap = parseFloat(document.getElementById('instGap').value);
    if (!first) { alert('سررسیدِ قسطِ اول را انتخاب کنید.'); return; }
    var rem = remaining(), base = Math.floor(rem / cnt / 1000) * 1000, last = rem - base * (cnt - 1);
    rowsBox.innerHTML = '';
    var due = first;
    for (var i = 0; i < cnt; i++) { addRow(i === cnt - 1 ? last : base, due, ''); due = gap === 0.5 ? jAdd(due, 0.5) : jAdd(due, gap); }
    instSync();
  });
  document.getElementById('instAdd').addEventListener('click', function () { addRow(0, '', ''); instSync(); });
  initialRows.forEach(function (r) { addRow(r.amount, r.due, r.note); });

  // ─── چک‌ها ───
  var chqWrap = document.getElementById('chqWrap'), chqBox = document.getElementById('chqRows');
  var chqRemain = document.getElementById('chqRemain'), chqHint = document.getElementById('chqSumHint');
  function addChq(amount, due, no, bank) {
    var r = document.createElement('div'); r.className = 'chq-row';
    r.innerHTML = '<span class="n inst-n" style="font-weight:800;color:#1d4ed8;text-align:center"></span>' +
      '<input name="chq_amount[]" class="form-control form-control-sm ca" dir="ltr" placeholder="مبلغ">' +
      '<input name="chq_due[]" class="form-control form-control-sm cd jalali-date" autocomplete="off" placeholder="سررسید">' +
      '<input name="chq_no[]" class="form-control form-control-sm cn" dir="ltr" placeholder="شماره چک">' +
      '<input name="chq_bank[]" class="form-control form-control-sm cb nt" placeholder="بانک (اختیاری)">' +
      '<button type="button" class="btn btn-sm btn-outline-danger px-2"><i class="fa-solid fa-xmark"></i></button>';
    r.querySelector('.ca').value = amount ? Number(amount).toLocaleString('en-US') : '';
    r.querySelector('.cd').value = due || '';
    r.querySelector('.cn').value = no || '';
    r.querySelector('.cb').value = bank || '';
    r.querySelector('.ca').addEventListener('input', function () { var n = num(this.value); this.value = n ? n.toLocaleString('en-US') : ''; chqSync(); });
    r.querySelector('button').addEventListener('click', function () { r.remove(); chqSync(); });
    chqBox.appendChild(r);
    if (window.jQuery && jQuery.fn.pDatepicker) { try { jQuery(r.querySelector('.cd')).pDatepicker({ format: 'YYYY/MM/DD', autoClose: true, initialValue: false, persianDigit: true }); } catch (e) {} }
  }
  function chqSync() {
    var rem = remaining(), show = settleVal() === 'cheque';
    chqWrap.hidden = !show;
    chqBox.querySelectorAll('input').forEach(function (x) { x.disabled = !show; });
    if (!show) return;
    if (!chqBox.children.length) addChq(0, '', '', '');
    chqRemain.textContent = fa(rem.toLocaleString('en-US')) + ' تومان';
    var sum = 0, i = 0;
    chqBox.querySelectorAll('.chq-row').forEach(function (r) { i++; r.querySelector('.n').textContent = fa(i); sum += num(r.querySelector('.ca').value); });
    chqHint.innerHTML = sum === rem ? '<span class="text-success"><i class="fa-solid fa-check"></i> جمعِ چک‌ها برابر با مانده است</span>'
      : '<span class="text-danger">جمعِ چک‌ها ' + fa(sum.toLocaleString('en-US')) + ' — ' + (sum < rem ? 'کمتر' : 'بیشتر') + ' از مانده</span>';
  }
  document.getElementById('chqAdd').addEventListener('click', function () { addChq(0, '', '', ''); chqSync(); });
  <?= json_encode(array_values($chqRows), JSON_UNESCAPED_UNICODE) ?>.forEach(function (r) { addChq(r.amount, r.due, r.no, r.bank); });
  document.querySelectorAll('input[name=settle_type]').forEach(function (x) { x.addEventListener('change', instSync); });

  // ─── تهاتر ───
  var pm = document.getElementById('payMethod');
  function methodSync() {
    var barter = pm.value === 'barter';
    document.getElementById('barterBox').hidden = !barter;
    document.getElementById('paidLabel').textContent = barter ? 'ارزشِ تهاتر (معادلِ تومانی) *' : 'مبلغِ پرداخت‌شده (تومان) *';
    document.getElementById('paidAmount').placeholder = barter ? 'مثلاً ۳۰,۰۰۰,۰۰۰' : 'مبلغی که مشتری واقعاً پرداخت کرده';
    document.querySelectorAll('.pay-cash, .cash-only').forEach(function (el) { el.hidden = barter; });
    document.querySelectorAll('.barter-only').forEach(function (el) { el.hidden = !barter; });
  }
  pm.addEventListener('change', methodSync); methodSync();
  instSync();

  var f = document.getElementById('receipts'), th = document.getElementById('thumbs');
  f.addEventListener('change', function () {
    th.innerHTML = '';
    Array.prototype.forEach.call(f.files, function (file) {
      var d = document.createElement('div');
      if (file.type.indexOf('image/') === 0) {
        var img = document.createElement('img'); img.src = URL.createObjectURL(file); d.appendChild(img);
      } else { d.innerHTML = '<span><i class="fa-solid fa-file-pdf fs-4 text-danger"></i><br>PDF</span>'; }
      th.appendChild(d);
    });
  });
})();
</script>
<?php require_once __DIR__ . '/includes/layout_bottom.php'; ?>
