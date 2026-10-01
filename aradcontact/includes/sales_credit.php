<?php
/**
 * «فروشِ مشترک»: تفکیکِ عددِ فروشِ یک سفارش بینِ چند کارشناس — فقط برای نمایش در گزارش‌ها/نمودارهای فروش.
 * روی سهم عملکرد هیچ اثری ندارد (سهم عملکرد همچنان فقط طبقِ مالکیت/ثبت‌کننده‌ی اصلی محاسبه می‌شود).
 * جدول: sales_order_credit_splits — اگر برای سفارشی ردیفی نباشد، کلِ عدد به نامِ ثبت‌کننده‌ی سفارش است.
 */

function scr_ready(PDO $pdo): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    $flag = __DIR__ . '/../storage/.sales_credit_splits_v1';
    if (is_file($flag)) { scr_cleanup_tiny_v2($pdo); return $ok = true; }
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS sales_order_credit_splits (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            amount BIGINT NOT NULL DEFAULT 0,
            created_by INT UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id), KEY idx_scs_order (order_id), KEY idx_scs_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) {
        error_log('scr_ready: ' . $e->getMessage());
        return $ok = false;
    }
    if (!is_dir(dirname($flag))) @mkdir(dirname($flag), 0755, true);
    @file_put_contents($flag, (string) time());
    scr_cleanup_tiny_v2($pdo);
    return $ok = true;
}

/** سهمِ کمتر از این مبلغ (تومان) «بدونِ سهم» حساب می‌شود — قبلاً فرم صفر را نمی‌پذیرفت و برای «سهم ندارد» ۱ تومان وارد می‌شد */
const SCR_TINY_SHARE = 1000;

/**
 * یک‌بار: ردیف‌های تفکیک با مبلغِ ناچیز (مثلاً ۱ تومان) حذف می‌شوند و همان مبلغ به بزرگ‌ترین سهمِ همان سفارش اضافه می‌شود
 * (به کوچک‌ترین سهمِ باقی‌مانده؛ جمع همچنان = عددِ فروش). اگر فقط یک نفر بماند و همان ثبت‌کننده باشد، تفکیک کلاً حذف می‌شود. در تاریخچه‌ی سفارش ثبت می‌شود.
 */
function scr_cleanup_tiny_v2(PDO $pdo): void
{
    $flag = __DIR__ . '/../storage/.sales_credit_splits_tiny_v2';
    if (is_file($flag)) return;
    @file_put_contents($flag, (string) time());
    try {
        $orders = $pdo->query('SELECT DISTINCT order_id FROM sales_order_credit_splits WHERE amount < ' . SCR_TINY_SHARE)->fetchAll(PDO::FETCH_COLUMN) ?: [];
        foreach ($orders as $oid) {
            $oid = (int) $oid;
            $st = $pdo->prepare('SELECT s.id, s.user_id, s.amount, u.full_name FROM sales_order_credit_splits s LEFT JOIN users u ON u.id = s.user_id WHERE s.order_id = ? ORDER BY s.amount DESC, s.id');
            $st->execute([$oid]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $keep = array_values(array_filter($rows, static fn($r) => (int) $r['amount'] >= SCR_TINY_SHARE));
            $drop = array_values(array_filter($rows, static fn($r) => (int) $r['amount'] < SCR_TINY_SHARE));
            if (!$keep || !$drop) continue;
            $moved = array_sum(array_map(static fn($r) => (int) $r['amount'], $drop));
            $pdo->beginTransaction();
            // مبلغِ ناچیز معمولاً از سهمِ یکی کم شده تا جمع درست شود ← به کوچک‌ترین سهم برمی‌گردد (مثلاً ۵٬۲۷۹٬۹۹۹ + ۱ = ۵٬۲۸۰٬۰۰۰)
            usort($keep, static fn($a, $b) => (int) $a['amount'] <=> (int) $b['amount']);
            $pdo->prepare('UPDATE sales_order_credit_splits SET amount = amount + ? WHERE id = ?')->execute([$moved, (int) $keep[0]['id']]);
            foreach ($drop as $d) $pdo->prepare('DELETE FROM sales_order_credit_splits WHERE id = ?')->execute([(int) $d['id']]);
            $seller = (int) $pdo->query('SELECT seller_user_id FROM sales_orders WHERE id = ' . $oid)->fetchColumn();
            if (count($keep) === 1 && (int) $keep[0]['user_id'] === $seller) {
                $pdo->prepare('DELETE FROM sales_order_credit_splits WHERE order_id = ?')->execute([$oid]);
            }
            $pdo->commit();
            if (function_exists('orders_add_history')) {
                orders_add_history($pdo, $oid, null, 'note', null, null, 'فروشِ مشترک (اصلاحِ خودکار): سهمِ ناچیزِ ' . implode('، ', array_map(static fn($d) => $d['full_name'] . ' (' . number_format((int) $d['amount']) . ' تومان)', $drop))
                    . ' حذف شد — این افراد از این سفارش سهمی ندارند؛ مبلغ به ' . $keep[0]['full_name'] . ' اضافه شد.');
            }
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('scr_cleanup_tiny_v2: ' . $e->getMessage());
    }
}

// ═══════════════════════════════════════════════════════════════════════
//  «فروش» — یک تعریف برای همه‌ی گزارش‌ها (گزارش فروش، سفارش‌های من، فروشِ تیم، گزارشِ سرپرست، A4)
// ═══════════════════════════════════════════════════════════════════════
//  فروش = رویدادهای پولیِ تأییدشده، هر کدام در «روزِ تأییدِ مالی»ِ خودش:
//   ۱) تأییدِ سفارش ← مبلغِ تأییدشده‌ی سفارش (پیش‌پرداخت؛ اگر خالی: مبلغِ کلِ سفارش) در روزِ تأییدِ سفارش
//   ۲) هر پرداختِ بعدیِ تأییدشده (قسط / پرداختِ اضافه، kind = extra) در روزِ تأییدِ همان پرداخت
//  («اعتبار» و «انتقال از قراردادِ لغوشده» پولِ تازه نیستند و حساب نمی‌شوند؛ فیش‌های «پرونده‌ی اقساطِ قبلی» (is_legacy)
//   بدهیِ خدماتِ قبل از سامانه‌اند و فروشِ جدید حساب نمی‌شوند — مثلِ قبل؛ سهمِ عملکردشان جداست.)
//  همه‌ی مبلغ‌ها «خالص» = بدونِ مالیات، به نسبتِ همان سفارش: مبلغ × (کلِ سفارش − مالیات) ÷ کلِ سفارش.
//  فروشِ مشترک: سهمِ هر نفر به نسبتِ تفکیکِ مالی (sales_order_credit_splits).
//  صاحبِ فروش (بدونِ تفکیکِ مالی) = ثبت‌کننده‌ی سفارش **فقط اگر** طبقِ قوانینِ سهمِ عملکرد در همان سفارش جایگاهی (A/B/C/سرپرست)
//  گرفته باشد (ps_order_snapshots)؛ وگرنه فروش به نامِ «سازمان آراد برندینگ» (uid = 0) — همان چیزی که برگه‌ی سهمِ عملکردِ سفارش نشان می‌دهد.

/** خالصِ یک مبلغ برای سفارشِ $o (SQL) */
function sales_net_sql(string $amountExpr, string $o = 'o'): string
{
    return "COALESCE(ROUND(($amountExpr) * ($o.total_amount - $o.tax_amount) / NULLIF($o.total_amount, 0)), 0)";
}

function sales_payments_ready(PDO $pdo): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $pdo->query('SELECT decided_at, kind, status FROM sales_order_payments LIMIT 0');
        return $ok = true;
    } catch (Throwable $e) {
        return $ok = false;
    }
}

function sales_has_legacy_col(PDO $pdo): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $pdo->query('SELECT is_legacy FROM sales_orders LIMIT 0');
        return $ok = true;
    } catch (Throwable $e) {
        return $ok = false;
    }
}

/**
 * رویدادهای فروش به تفکیکِ نفر (برای FROM (...) x).
 * ستون‌ها: order_id, uid, at, kind ('order'|'payment'), payment_id, gross, net, shared
 * $orderWhere: شرط روی سفارش (alias o) و مشتری (alias c)؛ پارامترها: sales_user_events_params()
 */
function sales_user_events_sql(PDO $pdo, string $orderWhere = '1=1'): string
{
    $ev = "SELECT o.id order_id, o.decided_at at, 'order' kind, 0 payment_id, COALESCE(o.confirmed_amount, o.total_amount) gross,
                " . sales_net_sql('COALESCE(o.confirmed_amount, o.total_amount)') . " net
           FROM sales_orders o WHERE o.status = 'approved' AND o.decided_at BETWEEN ? AND ?";
    if (sales_payments_ready($pdo)) {
        $ev .= " UNION ALL
           SELECT o.id, p.decided_at, 'payment', p.id, p.amount, " . sales_net_sql('p.amount') . "
           FROM sales_order_payments p JOIN sales_orders o ON o.id = p.order_id
           WHERE o.status = 'approved' AND p.status = 'confirmed' AND p.kind = 'extra' AND p.decided_at BETWEEN ? AND ?"
           . (sales_has_legacy_col($pdo) ? ' AND COALESCE(o.is_legacy, 0) = 0' : '');
    }
    $credit = sales_credit_uid_sql($pdo);
    $snapJoin = sales_snapshots_ready($pdo) ? ' LEFT JOIN ps_order_snapshots snap ON snap.order_id = o.id' : '';
    if (scr_ready($pdo)) {
        return "SELECT ev.order_id, COALESCE(sp.user_id, $credit) uid, ev.at, ev.kind, ev.payment_id,
                    CASE WHEN sp.user_id IS NULL THEN ev.gross ELSE ROUND(sp.amount * ev.gross / NULLIF(t.tot, 0)) END gross,
                    CASE WHEN sp.user_id IS NULL THEN ev.net ELSE ROUND(sp.amount * ev.net / NULLIF(t.tot, 0)) END net,
                    CASE WHEN sp.user_id IS NULL THEN 0 ELSE 1 END shared
                FROM ($ev) ev
                JOIN sales_orders o ON o.id = ev.order_id
                LEFT JOIN customers c ON c.id = o.customer_id$snapJoin
                LEFT JOIN sales_order_credit_splits sp ON sp.order_id = o.id
                LEFT JOIN (SELECT order_id, SUM(amount) tot FROM sales_order_credit_splits GROUP BY order_id) t ON t.order_id = o.id
                WHERE ($orderWhere)";
    }
    return "SELECT ev.order_id, $credit uid, ev.at, ev.kind, ev.payment_id, ev.gross, ev.net, 0 shared
            FROM ($ev) ev JOIN sales_orders o ON o.id = ev.order_id LEFT JOIN customers c ON c.id = o.customer_id$snapJoin WHERE ($orderWhere)";
}

function sales_snapshots_ready(PDO $pdo): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $pdo->query('SELECT owners_json FROM ps_order_snapshots LIMIT 0');
        return $ok = true;
    } catch (Throwable $e) {
        return $ok = false;
    }
}

/** صاحبِ فروشِ سفارش (SQL، alias o و snap): ثبت‌کننده اگر در snapshotِ سهمِ عملکرد جایگاه دارد، وگرنه 0 = سازمان */
function sales_credit_uid_sql(PDO $pdo): string
{
    if (!sales_snapshots_ready($pdo)) return 'o.seller_user_id';
    return "CASE WHEN snap.owners_json IS NULL THEN o.seller_user_id
                 WHEN snap.owners_json LIKE CONCAT('%\"user_id\":', o.seller_user_id, ',%') THEN o.seller_user_id
                 ELSE 0 END";
}

/** پارامترهای sales_user_events_sql برای بازه‌ی [from, to] (تاریخِ میلادی؛ خالی = بدونِ محدودیت) + پارامترهای $orderWhere */
function sales_user_events_params(PDO $pdo, string $from, string $to, array $whereParams = []): array
{
    $f = ($from !== '' ? $from : '1000-01-01') . ' 00:00:00';
    $t = ($to !== '' ? $to : '9999-12-31') . ' 23:59:59';
    return array_merge([$f, $t], sales_payments_ready($pdo) ? [$f, $t] : [], $whereParams);
}

/** فروشِ خالصِ چند نفر در بازه: [uid => ['net' => …, 'gross' => …, 'cnt' => تعدادِ سفارش‌ها]] */
function sales_by_user(PDO $pdo, array $uids, string $from, string $to): array
{
    $out = [];
    $uids = array_values(array_unique(array_map('intval', $uids)));
    if (!$uids) return $out;
    $in = implode(',', $uids);
    try {
        $st = $pdo->prepare('SELECT x.uid, SUM(x.net) net, SUM(x.gross) gross, COUNT(DISTINCT CASE WHEN x.kind = \'order\' THEN x.order_id END) cnt
            FROM (' . sales_user_events_sql($pdo) . ") x WHERE x.uid IN ($in) GROUP BY x.uid");
        $st->execute(sales_user_events_params($pdo, $from, $to));
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) $out[(int) $r['uid']] = ['net' => (int) $r['net'], 'gross' => (int) $r['gross'], 'cnt' => (int) $r['cnt']];
    } catch (Throwable $e) {
        error_log('sales_by_user: ' . $e->getMessage());
    }
    return $out;
}

/** عددِ فروشِ سفارش (همان مبنای گزارش فروش) */
function scr_order_amount(array $order): int
{
    return ($order['confirmed_amount'] ?? null) !== null ? (int) $order['confirmed_amount'] : (int) ($order['total_amount'] ?? 0);
}

function scr_get(PDO $pdo, int $orderId): array
{
    if (!scr_ready($pdo)) return [];
    $st = $pdo->prepare('SELECT s.*, u.full_name, u.role FROM sales_order_credit_splits s LEFT JOIN users u ON u.id = s.user_id WHERE s.order_id = ? ORDER BY s.id');
    $st->execute([$orderId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** ردیف‌های فرم: split_user[] / split_amount[] → [[user_id, amount], …] (ردیف‌های خالی حذف می‌شوند) */
function scr_parse_post(array $post): array
{
    $users = (array) ($post['split_user'] ?? []);
    $amounts = (array) ($post['split_amount'] ?? []);
    $rows = [];
    foreach ($users as $i => $u) {
        $uid = (int) $u;
        $amt = (int) preg_replace('/[^0-9]/', '', normalize_digits((string) ($amounts[$i] ?? '')));
        if ($amt <= 0) continue; // مبلغِ صفر/خالی = این نفر از این فروش سهمی ندارد
        $rows[] = ['user_id' => $uid, 'amount' => $amt];
    }
    return $rows;
}

/**
 * ذخیره‌ی تفکیک. قواعد: هر نفر یک‌بار، مبلغِ هر نفر > ۰، جمعِ همه دقیقاً = عددِ فروشِ سفارش.
 * یک ردیف (یا خالی) = «فروشِ مشترک نیست» ← تفکیک پاک می‌شود و کلِ عدد به نامِ ثبت‌کننده می‌ماند.
 */
function scr_save(PDO $pdo, array $order, array $rows, int $userId): array
{
    if (!scr_ready($pdo)) return ['ok' => false, 'message' => 'ماژولِ فروشِ مشترک آماده نیست.'];
    $orderId = (int) $order['id'];
    $total = scr_order_amount($order);
    // یک نفر با کلِ مبلغ: اگر همان ثبت‌کننده است ← «مشترک نیست»؛ اگر کسِ دیگری است ← کلِ عددِ فروش به نامِ او
    if (!$rows || (count($rows) === 1 && (int) $rows[0]['user_id'] === (int) ($order['seller_user_id'] ?? 0))) {
        $had = (bool) scr_get($pdo, $orderId);
        $pdo->prepare('DELETE FROM sales_order_credit_splits WHERE order_id = ?')->execute([$orderId]);
        if ($had && function_exists('orders_add_history')) {
            orders_add_history($pdo, $orderId, $userId, 'note', null, null, 'تفکیکِ فروشِ مشترک حذف شد؛ کلِ عددِ فروش به نامِ ثبت‌کننده‌ی سفارش است.');
        }
        return ['ok' => true, 'message' => $had ? 'تفکیکِ فروش حذف شد.' : ''];
    }
    $seen = [];
    $sum = 0;
    $names = [];
    $chk = $pdo->prepare('SELECT full_name FROM users WHERE id = ? AND is_active = 1');
    foreach ($rows as $r) {
        if ($r['user_id'] <= 0) return ['ok' => false, 'message' => 'برای همه‌ی ردیف‌ها کارشناس را انتخاب کنید.'];
        if ($r['amount'] < SCR_TINY_SHARE) return ['ok' => false, 'message' => 'مبلغِ ' . number_format($r['amount']) . ' تومان برای یک کارشناس معنا ندارد؛ اگر این نفر سهمی ندارد، مبلغش را خالی یا صفر بگذارید.'];
        if (isset($seen[$r['user_id']])) return ['ok' => false, 'message' => 'یک کارشناس دوبار انتخاب شده است.'];
        $chk->execute([$r['user_id']]);
        $n = $chk->fetchColumn();
        if (!$n) return ['ok' => false, 'message' => 'کارشناسِ انتخاب‌شده پیدا نشد یا غیرفعال است.'];
        $seen[$r['user_id']] = true;
        $names[] = $n . ': ' . number_format($r['amount']);
        $sum += $r['amount'];
    }
    if ($sum !== $total) {
        return ['ok' => false, 'message' => 'جمعِ مبالغِ تفکیک (' . number_format($sum) . ' تومان) باید دقیقاً برابرِ عددِ فروشِ سفارش (' . number_format($total) . ' تومان) باشد.'];
    }
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM sales_order_credit_splits WHERE order_id = ?')->execute([$orderId]);
        $ins = $pdo->prepare('INSERT INTO sales_order_credit_splits (order_id, user_id, amount, created_by, created_at) VALUES (?,?,?,?,?)');
        $now = date('Y-m-d H:i:s');
        foreach ($rows as $r) $ins->execute([$orderId, $r['user_id'], $r['amount'], $userId, $now]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['ok' => false, 'message' => 'ذخیره‌ی تفکیک ناموفق بود.'];
    }
    if (function_exists('orders_add_history')) {
        orders_add_history($pdo, $orderId, $userId, 'note', null, null, 'فروشِ مشترک (فقط گزارشِ فروش، بدونِ اثر بر سهم عملکرد): ' . implode(' | ', $names) . ' تومان');
    }
    return ['ok' => true, 'message' => count($rows) === 1
        ? 'کلِ عددِ فروشِ این سفارش به نامِ ' . $names[0] . ' تومان ثبت شد (سهم عملکرد تغییری نکرد).'
        : 'تفکیکِ عددِ فروش بینِ ' . to_persian_digits((string) count($rows)) . ' کارشناس ذخیره شد (سهم عملکرد تغییری نکرد).'];
}

/** فهرستِ کارکنانِ قابلِ‌انتخاب */
function scr_staff(PDO $pdo): array
{
    static $c = null;
    if ($c !== null) return $c;
    try {
        $c = $pdo->query("SELECT id, full_name, role, mobile FROM users WHERE is_active = 1 AND role NOT IN ('super_admin') ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $c = [];
    }
    return $c;
}

/**
 * ویرایشگرِ تفکیک (داخلِ یک <form>): ردیف‌های «کارشناس + مبلغ» با جمعِ زنده.
 * $amountInputId: فیلدی که عددِ فروش از آن خوانده می‌شود (مثلاً مبلغِ تأییدی در فرمِ تصمیمِ مالی)
 */
function scr_editor_html(PDO $pdo, array $order, string $amountInputId = ''): string
{
    $splits = scr_get($pdo, (int) $order['id']);
    $rows = $splits ?: [['user_id' => (int) $order['seller_user_id'], 'amount' => 0]];
    $maxRows = 6;
    $staff = scr_staff($pdo);
    $total = scr_order_amount($order);
    $uid = 'scr' . (int) $order['id'];
    ob_start(); ?>
    <details class="border rounded-3 p-2 mb-2" style="background:#f5f3ff" id="<?= $uid ?>" <?= $splits ? 'open' : '' ?>>
      <summary class="small fw-bold" style="color:#6d28d9"><i class="fa-solid fa-people-group"></i> فروشِ مشترک؟ تفکیکِ عددِ فروش بینِ چند کارشناس <span class="fw-normal text-muted">(فقط گزارشِ فروش — سهم عملکرد تغییر نمی‌کند)</span></summary>
      <div class="small text-muted mt-2 mb-2">ردیفِ اول ثبت‌کننده‌ی سفارش است. هر کس سهمی ندارد (حتی ثبت‌کننده)، مبلغش را <b>خالی یا ۰</b> بگذارید — نه ۱ تومان. اگر کلِ فروش به نامِ کسِ دیگری است، فقط برای همان یک نفر کلِ مبلغ را بنویسید. جمعِ مبالغ باید دقیقاً برابرِ عددِ فروش باشد.</div>
      <?php for ($i = 0; $i < $maxRows; $i++): $r = $rows[$i] ?? null; ?>
        <div class="d-flex gap-2 mb-1 scr-row" <?= $r === null && $i > 1 ? 'style="display:none!important"' : '' ?>>
          <select name="split_user[]" class="form-select form-select-sm" data-search>
            <option value="">— کارشناس —</option>
            <?php foreach ($staff as $s): ?><option value="<?= (int) $s['id'] ?>" <?= $r && (int) $r['user_id'] === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['full_name'] . ' — ' . $s['role']) ?></option><?php endforeach; ?>
          </select>
          <input name="split_amount[]" class="form-control form-control-sm scr-amt" dir="ltr" style="max-width:170px" placeholder="مبلغ (تومان)" value="<?= $r && (int) $r['amount'] > 0 ? e(number_format((int) $r['amount'])) : '' ?>">
        </div>
      <?php endfor; ?>
      <div class="d-flex flex-wrap gap-2 align-items-center mt-1">
        <button type="button" class="btn btn-sm btn-outline-secondary scr-more"><i class="fa-solid fa-plus"></i> نفرِ بعدی</button>
        <span class="small">جمع: <b class="scr-sum" dir="ltr">0</b> از <b class="scr-total" dir="ltr"><?= e(number_format($total)) ?></b> تومان <span class="scr-state"></span></span>
      </div>
    </details>
    <script>
    (function () {
      var box = document.getElementById('<?= $uid ?>'); if (!box) return;
      var srcId = <?= json_encode($amountInputId) ?>, fixedTotal = <?= (int) $total ?>;
      function num(v) { return Number(String(v || '').replace(/[۰-۹]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'.indexOf(d); }).replace(/[^0-9]/g, '')) || 0; }
      function total() { var s = srcId && document.getElementById(srcId); return s ? num(s.value) : fixedTotal; }
      function upd() {
        var sum = 0, filled = 0;
        box.querySelectorAll('.scr-amt').forEach(function (i) { var v = num(i.value); sum += v; if (v) filled++; });
        var t = total();
        box.querySelector('.scr-sum').textContent = sum.toLocaleString('en-US');
        box.querySelector('.scr-total').textContent = t.toLocaleString('en-US');
        var st = box.querySelector('.scr-state');
        if (filled === 0) { st.innerHTML = '<span class="text-muted">— فروشِ مشترک نیست (کلِ عدد به نامِ ثبت‌کننده)</span>'; }
        else if (sum !== t) { st.innerHTML = '<span class="text-danger fw-bold">اختلاف: ' + (t - sum).toLocaleString('en-US') + '</span>'; }
        else if (filled === 1) { st.innerHTML = '<span class="text-success fw-bold">✓ کلِ عدد به نامِ همین یک نفر</span>'; }
        else if (sum === t) { st.innerHTML = '<span class="text-success fw-bold">✓ درست</span>'; }
        else { st.innerHTML = '<span class="text-danger fw-bold">اختلاف: ' + (t - sum).toLocaleString('en-US') + '</span>'; }
      }
      box.addEventListener('input', function (e) {
        if (e.target.classList.contains('scr-amt')) {
          var n = num(e.target.value); e.target.value = n ? n.toLocaleString('en-US') : '';
        }
        upd();
      });
      var src = srcId && document.getElementById(srcId); if (src) src.addEventListener('input', upd);
      box.querySelector('.scr-more').addEventListener('click', function () {
        var hidden = Array.prototype.find.call(box.querySelectorAll('.scr-row'), function (r) { return r.style.display === 'none' || r.getAttribute('style'); });
        if (hidden) { hidden.removeAttribute('style'); } else { alert('حداکثر ۶ نفر.'); }
      });
      upd();
    })();
    </script>
    <?php
    return (string) ob_get_clean();
}
