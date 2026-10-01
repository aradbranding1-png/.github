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
    if (is_file($flag)) return $ok = true;
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
    return $ok = true;
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
        if ($uid <= 0 && $amt <= 0) continue;
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
    if (count($rows) <= 1) {
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
        if ($r['amount'] <= 0) return ['ok' => false, 'message' => 'مبلغِ هر کارشناس باید بیشتر از صفر باشد.'];
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
    return ['ok' => true, 'message' => 'تفکیکِ عددِ فروش بینِ ' . to_persian_digits((string) count($rows)) . ' کارشناس ذخیره شد (سهم عملکرد تغییری نکرد).'];
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
      <div class="small text-muted mt-2 mb-2">ردیفِ اول ثبت‌کننده‌ی سفارش است. اگر فروش مشترک نیست، فقط همان یک ردیف را نگه دارید (یا خالی بگذارید). جمعِ مبالغ باید دقیقاً برابرِ عددِ فروش باشد.</div>
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
        if (filled <= 1) { st.innerHTML = '<span class="text-muted">— فروشِ مشترک نیست</span>'; }
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
