<?php
/**
 * ═══════════════════════════════════════════════════════════════════════
 *  اقساطِ «قبل از سامانه» (بدهیِ قدیمیِ مشتری)
 * ═══════════════════════════════════════════════════════════════════════
 *  مشتری‌هایی که پیش از راه‌اندازیِ این سامانه خدمات گرفته‌اند و قسط‌بندی شده‌اند،
 *  در سیستم سفارشی ندارند؛ پس کارشناس نمی‌تواند فیشِ قسطی را که امروز می‌گیرد ثبت کند
 *  و سهمِ عملکردش را بگیرد. برای همین، برای هر مشتری یک «پرونده‌ی اقساطِ قبلی» ساخته
 *  می‌شود: یک سفارشِ تأییدشده‌ی نشان‌دار (is_legacy = 1) بدونِ خدمتِ جدید، که:
 *    - فقط ظرفِ ثبتِ فیش‌های قسط است (هیچ خدمتی به مشتری داده نمی‌شود)،
 *    - مسیرِ تأییدِ مالی، مانده‌ی بدهی، اقساط و سهمِ عملکرد دقیقاً مثلِ بقیه‌ی سفارش‌ها کار می‌کند.
 */

require_once __DIR__ . '/orders_functions.php';
require_once __DIR__ . '/finance_functions.php';

if (!defined('LI_SCHEMA_FLAG')) {
    define('LI_SCHEMA_FLAG', __DIR__ . '/../storage/.legacy_installments_v1');
}

function li_ready(PDO $pdo): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    if (!orders_ready($pdo) || !finance_schema_ready($pdo)) {
        return $ready = false;
    }
    if (is_file(LI_SCHEMA_FLAG)) {
        return $ready = true;
    }
    try {
        try {
            $pdo->query('SELECT is_legacy FROM sales_orders LIMIT 1');
        } catch (Throwable $e) {
            $pdo->exec('ALTER TABLE sales_orders ADD COLUMN is_legacy TINYINT(1) NOT NULL DEFAULT 0');
        }
        try {
            $pdo->query('SELECT legacy_note FROM sales_orders LIMIT 1');
        } catch (Throwable $e) {
            $pdo->exec('ALTER TABLE sales_orders ADD COLUMN legacy_note VARCHAR(500) DEFAULT NULL');
        }
        $pdo->query('SELECT is_legacy, legacy_note FROM sales_orders LIMIT 1');
    } catch (Throwable $e) {
        error_log('li_ready: ' . $e->getMessage());
        return $ready = false;
    }
    if (!is_dir(dirname(LI_SCHEMA_FLAG))) {
        @mkdir(dirname(LI_SCHEMA_FLAG), 0755, true);
    }
    @file_put_contents(LI_SCHEMA_FLAG, (string) time());
    return $ready = true;
}

/** کارشناسِ مالکِ مشتری، سرپرستش، واحد خدمات و مالی می‌توانند قسطِ قبلی ثبت کنند */
function li_can_manage(PDO $pdo, array $user, int $ownerId): bool
{
    return (int) $user['id'] === $ownerId
        || (function_exists('is_super_admin') && is_super_admin($user))
        || user_can('finance_orders_decide', $user) || user_can('finance_orders_view', $user)
        || (function_exists('can_manage_service_requests') && can_manage_service_requests($user))
        || (function_exists('leader_supervises_owner') && leader_supervises_owner($pdo, $user, $ownerId));
}

/** پرونده‌های اقساطِ قبلیِ یک مشتری */
function li_orders_of(PDO $pdo, int $customerId): array
{
    if (!li_ready($pdo)) {
        return [];
    }
    $st = $pdo->prepare("SELECT o.*, u.full_name AS seller_name FROM sales_orders o LEFT JOIN users u ON u.id = o.seller_user_id
        WHERE o.customer_id = ? AND o.is_legacy = 1 AND o.status <> 'cancelled' ORDER BY o.id DESC");
    $st->execute([$customerId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** خلاصه‌ی مالیِ هر پرونده (مبلغ کل، پرداخت‌شده، در انتظارِ تأیید، مانده) */
function li_summary(PDO $pdo, array $order): array
{
    $pays = fin_payments($pdo, (int) $order['id']);
    $inst = fin_installments($pdo, (int) $order['id']);
    $f = fin_compute($order, $pays, $inst);
    $f['payments'] = $pays;
    return $f;
}

function li_next_number(PDO $pdo): string
{
    [$jy, $jm, $jd] = gregorian_to_jalali_arr((int) date('Y'), (int) date('m'), (int) date('d'));
    $prefix = sprintf('LG%04d%02d%02d-', $jy, $jm, $jd);
    $st = $pdo->prepare('SELECT COUNT(*) FROM sales_orders WHERE order_number LIKE ?');
    $st->execute([$prefix . '%']);
    $seq = (int) $st->fetchColumn() + 1;
    $chk = $pdo->prepare('SELECT COUNT(*) FROM sales_orders WHERE order_number = ?');
    do {
        $num = $prefix . str_pad((string) $seq, 3, '0', STR_PAD_LEFT);
        $chk->execute([$num]);
        $seq++;
    } while ((int) $chk->fetchColumn() > 0);
    return $num;
}

/**
 * ساختِ «پرونده‌ی اقساطِ قبلی» برای یک مشتری.
 * @param int $total مبلغِ کلِ بدهیِ قبلی (تومان)
 */
function li_create(PDO $pdo, int $customerId, int $total, string $note, array $user): array
{
    if (!li_ready($pdo)) {
        return ['ok' => false, 'message' => 'این بخش آماده نیست (ساختِ ستون‌های لازم ناموفق بود).'];
    }
    if ($total <= 0) {
        return ['ok' => false, 'message' => 'مبلغِ کلِ بدهیِ قبلی را وارد کنید.'];
    }
    $c = $pdo->prepare('SELECT id, owner_user_id FROM customers WHERE id = ?');
    $c->execute([$customerId]);
    $cust = $c->fetch(PDO::FETCH_ASSOC);
    if (!$cust) {
        return ['ok' => false, 'message' => 'مشتری پیدا نشد.'];
    }
    $now = date('Y-m-d H:i:s');
    try {
        $pdo->prepare("INSERT INTO sales_orders (order_number, quote_id, customer_id, seller_user_id, customer_owner_id, subtotal, discount_percent, discount_amount,
                free_amount, tax_percent, tax_amount, total_amount, paid_amount, payment_method, payment_date, seller_note, status, finance_user_id, confirmed_amount,
                decided_at, submitted_at, created_at, updated_at, is_legacy, legacy_note)
            VALUES (?,0,?,?,?,?,0,0,0,0,0,?,0,NULL,NULL,?,'approved',?,0,?,?,?,?,1,?)")
            ->execute([li_next_number($pdo), $customerId, (int) $user['id'], (int) $cust['owner_user_id'], $total, $total,
                'پرونده‌ی اقساطِ قبل از سامانه (بدونِ خدمتِ جدید)', (int) $user['id'], $now, $now, $now, $now,
                mb_substr(trim($note), 0, 500) ?: null]);
        $id = (int) $pdo->lastInsertId();
    } catch (Throwable $e) {
        error_log('li_create: ' . $e->getMessage());
        return ['ok' => false, 'message' => 'ساختِ پرونده‌ی اقساطِ قبلی ناموفق بود.'];
    }
    orders_add_history($pdo, $id, (int) $user['id'], 'note', null, 'approved',
        'پرونده‌ی اقساطِ قبل از سامانه ساخته شد — مبلغِ کلِ بدهی: ' . number_format($total) . ' تومان' . (trim($note) !== '' ? ' — ' . trim($note) : ''));
    return ['ok' => true, 'id' => $id, 'message' => 'پرونده‌ی اقساطِ قبلی ساخته شد؛ حالا فیشِ قسط را ثبت کنید.'];
}

/** اصلاحِ مبلغِ کلِ بدهیِ قبلی */
function li_set_total(PDO $pdo, array $order, int $total, int $userId): array
{
    if ($total <= 0) {
        return ['ok' => false, 'message' => 'مبلغ معتبر نیست.'];
    }
    $f = li_summary($pdo, $order);
    if ($total < (int) $f['paid']) {
        return ['ok' => false, 'message' => 'مبلغِ کل نمی‌تواند از جمعِ پرداخت‌های تأییدشده (' . number_format((int) $f['paid']) . ' تومان) کمتر باشد.'];
    }
    $pdo->prepare('UPDATE sales_orders SET total_amount = ?, subtotal = ?, updated_at = ? WHERE id = ? AND is_legacy = 1')
        ->execute([$total, $total, date('Y-m-d H:i:s'), (int) $order['id']]);
    orders_add_history($pdo, (int) $order['id'], $userId, 'note', null, null,
        'مبلغِ کلِ بدهیِ قبلی اصلاح شد: ' . number_format((int) $order['total_amount']) . ' ← ' . number_format($total) . ' تومان');
    return ['ok' => true, 'message' => 'مبلغِ کلِ بدهیِ قبلی به‌روز شد.'];
}

/**
 * ثبتِ فیشِ قسطِ دریافتی روی پرونده‌ی اقساطِ قبلی.
 * پرداخت مثلِ بقیه‌ی پرداخت‌ها در انتظارِ تأییدِ مالی می‌ماند (مگر خودِ مالی ثبت کند) و
 * بعد از تأیید، سهمِ عملکردِ ثبت‌کننده خودکار محاسبه می‌شود.
 */
function li_add_payment(PDO $pdo, array $order, array $data, array $files, array $user): array
{
    $amount = (int) ($data['amount'] ?? 0);
    if ($amount <= 0) {
        return ['ok' => false, 'message' => 'مبلغِ قسطِ دریافتی را وارد کنید.'];
    }
    $autoConfirm = user_can('finance_orders_decide', $user) && !empty($data['auto_confirm']);
    $res = fin_add_payment($pdo, $order, [
        'amount'  => $amount,
        'paid_at' => $data['paid_at'] ?? date('Y-m-d'),
        'method'  => (string) ($data['method'] ?? ''),
        'ref'     => (string) ($data['ref'] ?? ''),
        'note'    => trim('قسطِ بدهیِ قبل از سامانه. ' . (string) ($data['note'] ?? '')),
    ], $files, $user, $autoConfirm);
    if (!$res['ok']) {
        return $res;
    }
    // اگر جمعِ دریافتی‌ها از مبلغِ ثبت‌شده‌ی بدهی بیشتر شد، مبلغِ کل هم بالا می‌رود تا مانده منفی نشود
    $f = li_summary($pdo, orders_get($pdo, (int) $order['id']) ?: $order);
    $sum = (int) $f['paid'] + (int) $f['pending_paid'];
    if ($sum > (int) $order['total_amount']) {
        $pdo->prepare('UPDATE sales_orders SET total_amount = ?, subtotal = ?, updated_at = ? WHERE id = ?')
            ->execute([$sum, $sum, date('Y-m-d H:i:s'), (int) $order['id']]);
    }
    // سهم عملکرد: تا وقتی مالی تأیید نکرده چیزی محاسبه نمی‌شود؛ این فقط snapshot و همگام‌سازی است
    try {
        require_once __DIR__ . '/performance_functions.php';
        if (function_exists('perf_ready') && perf_ready($pdo)) {
            ps_order_snapshot($pdo, (int) $order['id'], $user, 'legacy_installment');
            ps_sync_order($pdo, (int) $order['id'], (int) $user['id']);
        }
    } catch (Throwable $e) {
        error_log('li_add_payment perf: ' . $e->getMessage());
    }
    $res['message'] = $autoConfirm
        ? 'قسط ثبت و تأیید شد؛ سهمِ عملکردش محاسبه می‌شود.'
        : 'قسط ثبت شد و بعد از تأییدِ واحد مالی، از بدهیِ مشتری کم و سهمِ عملکردش محاسبه می‌شود.';
    return $res;
}

/**
 * سررسیدِ «اقساطِ بعدی» برای پرونده‌ی اقساطِ قبلی (همراهِ ثبتِ فیش).
 * برنامه‌ی اقساط در سامانه به ترتیبِ سررسید با پرداخت‌ها پر می‌شود؛ پس اول بخشی از برنامه که با پولِ دریافت‌شده
 * (تأییدشده + در انتظار، شاملِ همین فیش) پوشش داده شده نگه داشته می‌شود و بعد قسط‌های بعدی اضافه می‌شوند.
 * @param array $next خروجیِ fin_parse_installment_post (amount, due_date به میلادی)
 */
function li_set_next_installments(PDO $pdo, int $orderId, array $next, int $userId): array
{
    $order = orders_get($pdo, $orderId);
    if (!$order) return ['ok' => false, 'message' => 'پرونده پیدا نشد.'];
    $f = li_summary($pdo, $order);
    $covered = (int) $f['paid'] + (int) $f['pending_paid'];
    $remaining = max(0, (int) $order['total_amount'] - $covered);
    $sumNext = array_sum(array_map(static fn($r) => (int) $r['amount'], $next));
    if ($sumNext > $remaining) {
        return ['ok' => false, 'message' => 'سررسیدها ذخیره نشد: جمعِ اقساطِ بعدی (' . number_format($sumNext) . ') از مانده‌ی بدهی (' . number_format($remaining) . ' تومان) بیشتر است؛ اگر بدهی بیشتر است اول «مبلغِ کلِ بدهی» را اصلاح کنید.'];
    }
    $rows = [];
    $acc = 0;
    $existing = fin_installments($pdo, $orderId);
    usort($existing, static fn($a, $b) => strcmp((string) $a['due_date'], (string) $b['due_date']));
    foreach ($existing as $e) {
        if ($acc >= $covered) break;
        $take = min((int) $e['amount'], $covered - $acc);
        $rows[] = ['amount' => $take, 'due_date' => (string) $e['due_date'], 'note' => (string) ($e['note'] ?? '')];
        $acc += $take;
    }
    if ($acc < $covered) {
        $rows[] = ['amount' => $covered - $acc, 'due_date' => date('Y-m-d'), 'note' => 'دریافت‌شده تا امروز'];
    }
    foreach ($next as $r) {
        $rows[] = ['amount' => (int) $r['amount'], 'due_date' => (string) $r['due_date'], 'note' => (string) ($r['note'] ?? '')];
    }
    $res = fin_set_installments($pdo, $order, $rows, $userId);
    return $res['ok']
        ? ['ok' => true, 'message' => 'سررسیدِ ' . to_persian_digits((string) count($next)) . ' قسطِ بعدی ثبت شد.']
        : ['ok' => false, 'message' => 'سررسیدها ذخیره نشد: ' . $res['message']];
}
