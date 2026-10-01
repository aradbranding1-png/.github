<?php
/**
 * «اقساطِ قبل از سامانه» یک مشتری:
 * ثبتِ فیشِ قسط‌هایی که امروز از مشتریِ قدیمی گرفته می‌شود، بدونِ ثبتِ سفارش/خدمتِ جدید.
 * هر پرداخت در انتظارِ تأییدِ مالی می‌ماند و بعد از تأیید، سهمِ عملکردِ ثبت‌کننده محاسبه می‌شود.
 */
require_once __DIR__ . '/includes/auth.php';
$user = require_login();
$pdo = db();
require_once __DIR__ . '/includes/orders_functions.php';
require_once __DIR__ . '/includes/legacy_installments.php';

$cid = (int) ($_GET['id'] ?? $_POST['customer_id'] ?? 0);
$cst = $pdo->prepare('SELECT c.*, u.full_name AS owner_name FROM customers c JOIN users u ON u.id = c.owner_user_id WHERE c.id = ?');
$cst->execute([$cid]);
$customer = $cst->fetch(PDO::FETCH_ASSOC);
if (!$customer) {
    flash_set('danger', 'مشتری پیدا نشد.');
    redirect('customer_list.php');
}
if (!li_can_manage($pdo, $user, (int) $customer['owner_user_id'])) {
    perm_deny('اجازه‌ی ثبتِ قسط برای این مشتری را ندارید.', $user);
}
if (!li_ready($pdo)) {
    flash_set('danger', 'این بخش آماده نیست (ماژولِ سفارش/مالی فعال نیست).');
    redirect('customer_view.php?id=' . $cid);
}
$self = 'customer_legacy_installments.php?id=' . $cid;
$canDecide = user_can('finance_orders_decide', $user);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        flash_set('danger', 'نشست منقضی شده است؛ دوباره تلاش کنید.');
        redirect($self);
    }
    $action = (string) ($_POST['action'] ?? '');
    $orders = li_orders_of($pdo, $cid);
    $byId = [];
    foreach ($orders as $o) $byId[(int) $o['id']] = $o;

    if ($action === 'create') {
        $r = li_create($pdo, $cid, orders_money($_POST['total'] ?? ''), (string) ($_POST['note'] ?? ''), $user);
        flash_set($r['ok'] ? 'success' : 'danger', $r['message']);
        redirect($self);
    }
    if ($action === 'set_total' && isset($byId[(int) ($_POST['order_id'] ?? 0)])) {
        $r = li_set_total($pdo, $byId[(int) $_POST['order_id']], orders_money($_POST['total'] ?? ''), (int) $user['id']);
        flash_set($r['ok'] ? 'success' : 'danger', $r['message']);
        redirect($self);
    }
    if ($action === 'add_payment' && isset($byId[(int) ($_POST['order_id'] ?? 0)])) {
        $order = orders_get($pdo, (int) $_POST['order_id']);
        $r = li_add_payment($pdo, $order, [
            'amount'       => orders_money($_POST['amount'] ?? ''),
            'paid_at'      => trim((string) ($_POST['paid_at'] ?? '')) !== '' ? (to_gregorian(normalize_digits((string) $_POST['paid_at'])) ?: date('Y-m-d')) : date('Y-m-d'),
            'method'       => (string) ($_POST['method'] ?? ''),
            'ref'          => trim((string) ($_POST['ref'] ?? '')),
            'note'         => trim((string) ($_POST['note'] ?? '')),
            'auto_confirm' => !empty($_POST['auto_confirm']),
        ], orders_normalize_files($_FILES['receipts'] ?? null), $user);
        flash_set($r['ok'] ? 'success' : 'danger', $r['message']);
        redirect($self);
    }
    redirect($self);
}

$orders = li_orders_of($pdo, $cid);
$methods = orders_payment_methods();
$payStatuses = fin_payment_statuses();
$pageTitle = 'اقساطِ قبلیِ ' . $customer['full_name'];
require_once __DIR__ . '/includes/layout_top.php';
?>
<div class="mb-2 d-flex flex-wrap gap-2">
  <a href="customer_view.php?id=<?= $cid ?>" class="btn btn-sm btn-outline-secondary">→ برگشت به پرونده‌ی مشتری</a>
</div>

<div class="card p-3 mb-3" style="border-color:#fbbf24">
  <h5 class="fw-bold mb-1"><i class="fa-solid fa-hand-holding-dollar text-warning"></i> اقساطِ قبل از سامانه — <?= e((string) $customer['full_name']) ?></h5>
  <div class="small text-muted">
    این بخش فقط برای مشتری‌هایی است که <b>پیش از راه‌اندازیِ سامانه</b> خدمات گرفته‌اند و قسط‌بندی شده‌اند.
    فیشِ قسطی را که امروز از مشتری می‌گیرید این‌جا ثبت کنید؛ خدمتِ جدیدی به او داده نمی‌شود، ولی بعد از تأییدِ واحد مالی
    از بدهی‌اش کم می‌شود و <b>سهمِ عملکردِ ثبت‌کننده</b> هم مثلِ بقیه‌ی پرداخت‌ها محاسبه می‌شود.
  </div>
</div>

<?php if (!$orders): ?>
  <form method="post" class="card p-3 mb-3">
    <?= csrf_field() ?><input type="hidden" name="action" value="create"><input type="hidden" name="customer_id" value="<?= $cid ?>">
    <h6 class="fw-bold mb-2"><i class="fa-solid fa-folder-plus"></i> ساختِ پرونده‌ی اقساطِ قبلی</h6>
    <div class="row g-2">
      <div class="col-md-4"><label class="form-label small mb-1">مبلغِ کلِ بدهیِ قبلی (تومان)</label>
        <input name="total" class="form-control form-control-sm" dir="ltr" inputmode="numeric" required placeholder="مثلاً 50000000"></div>
      <div class="col-md-8"><label class="form-label small mb-1">توضیح (بابتِ چه قراردادی / چند قسط)</label>
        <input name="note" class="form-control form-control-sm" placeholder="مثلاً: باقی‌ماندهٔ قرارداد سال ۱۴۰۳ — ۶ قسط ماهانه"></div>
    </div>
    <div class="small text-muted mt-2">اگر مبلغِ دقیق را نمی‌دانید، مبلغِ تقریبی بزنید؛ بعداً قابلِ اصلاح است.</div>
    <button class="btn btn-sm btn-warning mt-2"><i class="fa-solid fa-plus"></i> ساختِ پرونده</button>
  </form>
<?php endif; ?>

<?php foreach ($orders as $o): $f = li_summary($pdo, $o); ?>
  <div class="card p-3 mb-3">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
      <h6 class="fw-bold mb-0"><i class="fa-solid fa-folder-open"></i> پرونده <bdi dir="ltr"><?= e(to_persian_digits((string) $o['order_number'])) ?></bdi></h6>
      <a href="order_view.php?id=<?= (int) $o['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="fa-solid fa-eye"></i> تأیید/جزئیاتِ مالی</a>
    </div>
    <?php if (!empty($o['legacy_note'])): ?><div class="small text-muted mb-2"><?= e((string) $o['legacy_note']) ?></div><?php endif; ?>
    <div class="row g-2 mb-2">
      <?php foreach ([['بدهیِ کل', number_format((int) $f['total']), ''], ['دریافت‌شده (تأییدشده)', number_format((int) $f['paid']), 'text-success'],
                      ['در انتظارِ تأییدِ مالی', number_format((int) $f['pending_paid']), 'text-warning'], ['مانده', number_format((int) $f['balance']), 'text-danger']] as [$l, $v, $c]): ?>
        <div class="col-6 col-md-3"><div class="border rounded-3 p-2 h-100"><div class="small text-muted"><?= $l ?></div><div class="fw-bold <?= $c ?>"><?= to_persian_digits($v) ?></div></div></div>
      <?php endforeach; ?>
    </div>

    <form method="post" enctype="multipart/form-data" class="border rounded-3 p-2 mb-2" style="background:#fdfbf5">
      <?= csrf_field() ?><input type="hidden" name="action" value="add_payment"><input type="hidden" name="customer_id" value="<?= $cid ?>"><input type="hidden" name="order_id" value="<?= (int) $o['id'] ?>">
      <div class="fw-bold small mb-2"><i class="fa-solid fa-receipt"></i> ثبتِ قسطِ دریافتی</div>
      <div class="row g-2">
        <div class="col-md-3"><label class="form-label small mb-1">مبلغِ دریافتی</label><input name="amount" class="form-control form-control-sm" dir="ltr" inputmode="numeric" required></div>
        <div class="col-md-3"><label class="form-label small mb-1">تاریخِ واریز</label><input name="paid_at" class="form-control form-control-sm jalali-date" dir="ltr" value="<?= e(today_jalali()) ?>"></div>
        <div class="col-md-3"><label class="form-label small mb-1">روشِ پرداخت</label>
          <select name="method" class="form-select form-select-sm"><?php foreach ($methods as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-3"><label class="form-label small mb-1">شماره پیگیری</label><input name="ref" class="form-control form-control-sm" dir="ltr"></div>
        <div class="col-md-6"><label class="form-label small mb-1">توضیح (اختیاری)</label><input name="note" class="form-control form-control-sm" placeholder="مثلاً: قسطِ چهارم"></div>
        <div class="col-md-6"><label class="form-label small mb-1">تصویرِ فیش</label><input type="file" name="receipts[]" class="form-control form-control-sm" accept="image/*,application/pdf" multiple></div>
      </div>
      <?php if ($canDecide): ?>
        <div class="form-check mt-2"><input class="form-check-input" type="checkbox" name="auto_confirm" id="ac<?= (int) $o['id'] ?>" value="1">
          <label class="form-check-label small" for="ac<?= (int) $o['id'] ?>">خودم (واحد مالی) همین حالا تأیید می‌کنم</label></div>
      <?php else: ?>
        <div class="small text-muted mt-2">بعد از ثبت، واحد مالی فیش را بررسی و تأیید می‌کند؛ سهمِ عملکرد بعد از تأیید محاسبه می‌شود.</div>
      <?php endif; ?>
      <button class="btn btn-sm btn-success mt-2"><i class="fa-solid fa-floppy-disk"></i> ثبتِ قسط</button>
    </form>

    <div class="table-responsive">
      <table class="table table-sm small align-middle mb-2">
        <thead class="table-light"><tr><th>تاریخِ واریز</th><th>مبلغ</th><th>روش</th><th>پیگیری</th><th>ثبت‌کننده</th><th>وضعیت</th><th>توضیح</th></tr></thead>
        <tbody>
        <?php foreach ($f['payments'] as $p): $ps = $payStatuses[$p['status']] ?? ['label' => $p['status'], 'color' => 'secondary', 'icon' => 'fa-circle']; ?>
          <tr>
            <td class="text-nowrap"><?= $p['paid_at'] ? to_jalali((string) $p['paid_at']) : '—' ?></td>
            <td class="fw-bold text-nowrap"><?= to_persian_digits(number_format((int) $p['amount'])) ?></td>
            <td><?= e($methods[$p['method']] ?? (string) $p['method']) ?></td>
            <td dir="ltr"><?= e((string) $p['ref']) ?></td>
            <td><?= e((string) ($p['recorder_name'] ?? '—')) ?></td>
            <td><span class="badge text-bg-<?= e($ps['color']) ?>"><?= e($ps['label']) ?></span></td>
            <td class="text-muted" style="font-size:11px"><?= e((string) $p['note']) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$f['payments']): ?><tr><td colspan="7" class="text-center text-muted py-3">هنوز قسطی ثبت نشده.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>

    <form method="post" class="d-flex flex-wrap gap-2 align-items-end border-top pt-2">
      <?= csrf_field() ?><input type="hidden" name="action" value="set_total"><input type="hidden" name="customer_id" value="<?= $cid ?>"><input type="hidden" name="order_id" value="<?= (int) $o['id'] ?>">
      <div><label class="form-label small mb-1">اصلاحِ مبلغِ کلِ بدهیِ قبلی</label>
        <input name="total" class="form-control form-control-sm" style="max-width:180px" dir="ltr" inputmode="numeric" value="<?= e(number_format((int) $o['total_amount'])) ?>"></div>
      <button class="btn btn-sm btn-outline-dark">ذخیره</button>
    </form>
  </div>
<?php endforeach; ?>

<?php if ($orders): ?>
  <form method="post" class="card p-3 mb-3">
    <?= csrf_field() ?><input type="hidden" name="action" value="create"><input type="hidden" name="customer_id" value="<?= $cid ?>">
    <div class="small text-muted mb-2">اگر این مشتری بیش از یک بدهیِ قدیمیِ جدا دارد، می‌توانید پرونده‌ی دیگری هم بسازید.</div>
    <div class="d-flex flex-wrap gap-2 align-items-end">
      <div><label class="form-label small mb-1">مبلغِ کلِ بدهی</label><input name="total" class="form-control form-control-sm" style="max-width:180px" dir="ltr" inputmode="numeric" required></div>
      <div class="flex-grow-1"><label class="form-label small mb-1">توضیح</label><input name="note" class="form-control form-control-sm"></div>
      <button class="btn btn-sm btn-outline-warning">پرونده‌ی جدید</button>
    </div>
  </form>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/layout_bottom.php'; ?>
