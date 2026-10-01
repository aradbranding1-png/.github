<?php
/** سفارش‌های من — وضعیتِ سفارش‌هایی که کارشناس (یا تیمِ سرپرست) ثبت کرده است. */
require_once __DIR__ . '/includes/auth.php';
$user = require_login();
$pdo = db();
require_once __DIR__ . '/includes/services_functions.php';
require_once __DIR__ . '/includes/orders_functions.php';
require_once __DIR__ . '/includes/consent_functions.php';

$ready = services_module_ready($pdo) && orders_ready($pdo);
$statuses = orders_statuses();
$status = isset($statuses[$_GET['status'] ?? '']) ? (string) $_GET['status'] : '';
$scope = (string) ($_GET['scope'] ?? 'mine');
$q = trim((string) ($_GET['q'] ?? ''));

$team = ($user['role'] ?? '') === 'leader' ? team_led_by($pdo, (int) $user['id']) : null;
if (!$team) {
    $scope = 'mine';
}
$tab = ($_GET['tab'] ?? '') === 'debts' ? 'debts' : 'orders';

// ─── مطالبات و اقساطِ مشتریانِ من (یا تیمم) ───
$recv = [];
$recvSum = ['balance' => 0, 'overdue' => 0, 'due_week' => 0, 'unscheduled' => 0, 'orders' => 0, 'customers' => 0];
if ($ready) {
    $ids = [(int) $user['id']];
    if ($scope === 'team' && $team) {
        $t = $pdo->prepare('SELECT id FROM users WHERE team_id = ?');
        $t->execute([(int) $team['id']]);
        $ids = array_merge($ids, array_map('intval', $t->fetchAll(PDO::FETCH_COLUMN) ?: []));
    }
    $recv = fin_receivables($pdo, ['seller_ids' => $ids]);
    $recvSum = fin_receivables_summary($recv);
}

$rows = [];
$counts = array_fill_keys(array_keys($statuses), 0);
$approvedMonth = 0;
if ($ready) {
    $where = [];
    $params = [];
    if ($scope === 'team') {
        $where[] = '(o.seller_user_id = ? OR s.team_id = ?)';
        $params[] = (int) $user['id'];
        $params[] = (int) $team['id'];
    } else {
        $where[] = 'o.seller_user_id = ?';
        $params[] = (int) $user['id'];
    }
    if ($q !== '') {
        $where[] = '(c.full_name LIKE ? OR c.mobile LIKE ? OR o.order_number LIKE ?)';
        $like = '%' . normalize_digits($q) . '%';
        array_push($params, '%' . $q . '%', $like, $like);
    }
    $base = 'FROM sales_orders o LEFT JOIN customers c ON c.id = o.customer_id LEFT JOIN users s ON s.id = o.seller_user_id WHERE ' . implode(' AND ', $where);

    $st = $pdo->prepare("SELECT o.status, COUNT(*) $base GROUP BY o.status");
    $st->execute($params);
    foreach ($st->fetchAll(PDO::FETCH_KEY_PAIR) ?: [] as $k => $c) {
        if (isset($counts[$k])) $counts[$k] = (int) $c;
    }
    $st = $pdo->prepare("SELECT COALESCE(SUM(COALESCE(o.confirmed_amount, o.total_amount)),0) $base AND o.status = 'approved' AND o.decided_at >= ?");
    $st->execute(array_merge($params, [date('Y-m-01 00:00:00')]));
    $approvedMonth = (int) $st->fetchColumn();

    $sql = "SELECT o.*, c.full_name AS customer_name, c.mobile AS customer_mobile, s.full_name AS seller_name $base";
    if ($status !== '') {
        $sql .= ' AND o.status = ?';
        $params[] = $status;
    }
    $sql .= ' ORDER BY o.id DESC LIMIT 300';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
// وضعیتِ «پیامِ رضایتِ پرداخت» همه‌ی ردیف‌ها با یک کوئری (نارنجی = باید اسکرین‌شات بفرستید)
$consentStatus = [];
if (!empty($rows) && consent_ready($pdo)) {
    try {
        $ids = implode(',', array_map(static fn($r) => (int) $r['id'], $rows));
        foreach ($pdo->query("SELECT order_id, status, file_path FROM sales_order_consents WHERE order_id IN ($ids)") as $c) {
            $consentStatus[(int) $c['order_id']] = empty($c['file_path']) ? 'missing' : (string) $c['status'];
        }
    } catch (Throwable $e) {}
}

$pageTitle = 'سفارش‌های من';
require_once __DIR__ . '/includes/layout_top.php';
?>
<style>
.mo{--l:#e7e2d3}
.mo .hero{background:linear-gradient(135deg,#052e1c 0%,#14532d 55%,#22c55e 140%);border-radius:18px;padding:18px 22px;color:#ecfdf5;margin-bottom:16px;display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;align-items:center}
.mo .hero h5{margin:0;font-weight:800;color:#ecfdf5}.mo .hero p{margin:.3rem 0 0;font-size:.8rem;color:#d1fae5}
.mo .kpi{border:1px solid var(--l);border-radius:14px;background:#fff;padding:12px;text-align:center;display:block;text-decoration:none;color:inherit;height:100%}
.mo .kpi.active{outline:2px solid #22c55e}
.mo .kpi .n{font-weight:800;font-size:1.35rem}
.mo .card{border:1px solid var(--l);border-radius:16px;box-shadow:0 4px 16px -14px rgba(28,25,23,.3)}
.mo table td,.mo table th{font-size:.82rem;vertical-align:middle}
</style>
<div class="mo">
  <div class="hero">
    <div>
      <h5><i class="fa-solid fa-cart-shopping"></i> سفارش‌های من</h5>
      <p>برای ثبتِ سفارشِ جدید: پرونده‌ی مشتری ← پیش‌فاکتور ← قفل ← «تبدیل به فاکتور و ثبت سفارش».</p>
    </div>
    <div class="text-end"><div class="small">فروشِ تأییدشده‌ی این ماه</div><div class="fs-5 fw-bold"><?= format_toman($approvedMonth) ?></div></div>
  </div>

  <?php if (!$ready): ?>
    <?= services_module_not_ready_html() ?>
  <?php else: ?>
  <?php $tq = $_GET; ?>
  <ul class="nav nav-pills gap-2 mb-3">
    <li class="nav-item"><a class="nav-link <?= $tab === 'orders' ? 'active' : '' ?>" href="?<?= e(http_build_query(array_merge($tq, ['tab' => 'orders']))) ?>"><i class="fa-solid fa-cart-shopping"></i> سفارش‌ها</a></li>
    <li class="nav-item"><a class="nav-link <?= $tab === 'debts' ? 'active' : '' ?>" href="?<?= e(http_build_query(array_merge($tq, ['tab' => 'debts']))) ?>"><i class="fa-solid fa-hand-holding-dollar"></i> مطالبات و اقساط
      <?php if ($recvSum['overdue'] + $recvSum['unscheduled'] > 0): ?><span class="badge text-bg-danger ms-1">معوق</span><?php endif; ?></a></li>
  </ul>
  <?php if ($tab === 'debts'): ?>
    <?php require __DIR__ . '/includes/receivables_view.php'; ?>
  <?php else: ?>
  <div class="row g-2 mb-3">
    <?php $qs = $_GET; unset($qs['status']); ?>
    <div class="col-6 col-md"><a class="kpi <?= $status === '' ? 'active' : '' ?>" href="?<?= e(http_build_query($qs)) ?>"><div class="n"><?= to_persian_digits((string) array_sum($counts)) ?></div><div class="small text-muted">همه</div></a></div>
    <?php foreach ($statuses as $k => $m): $q2 = $qs; $q2['status'] = $k; ?>
      <div class="col-6 col-md"><a class="kpi <?= $status === $k ? 'active' : '' ?>" href="?<?= e(http_build_query($q2)) ?>"><div class="n text-<?= e($m['color']) ?>"><?= to_persian_digits((string) $counts[$k]) ?></div><div class="small text-muted"><?= e($m['label']) ?></div></a></div>
    <?php endforeach; ?>
  </div>

  <div class="card p-3 mb-3">
    <form class="d-flex gap-2 flex-wrap" method="get">
      <?php if ($status !== ''): ?><input type="hidden" name="status" value="<?= e($status) ?>"><?php endif; ?>
      <input type="hidden" name="tab" value="orders">
      <input name="q" class="form-control form-control-sm" style="max-width:280px" value="<?= e($q) ?>" placeholder="نام مشتری، موبایل یا شماره فاکتور">
      <?php if ($team): ?>
        <select name="scope" class="form-select form-select-sm" style="max-width:180px">
          <option value="mine" <?= $scope === 'mine' ? 'selected' : '' ?>>فقط سفارش‌های خودم</option>
          <option value="team" <?= $scope === 'team' ? 'selected' : '' ?>>سفارش‌های تیمِ من</option>
        </select>
      <?php endif; ?>
      <button class="btn btn-sm btn-success">جستجو</button>
    </form>
  </div>

  <div class="card p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead class="table-light"><tr><th>شماره فاکتور</th><th>مشتری</th><th>مبلغ فاکتور</th><th>پرداختی</th><?php if ($scope === 'team'): ?><th>کارشناس</th><?php endif; ?><th>ثبت</th><th>وضعیت</th><th></th></tr></thead>
        <tbody>
        <?php if (!$rows): ?><tr><td colspan="8" class="text-center text-muted py-4">سفارشی پیدا نشد.</td></tr><?php endif; ?>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td class="fw-semibold text-nowrap"><?= e(to_persian_digits($r['order_number'])) ?></td>
            <td><?= e($r['customer_name'] ?? '—') ?><div class="small text-muted" dir="ltr"><?= e((string) $r['customer_mobile']) ?></div></td>
            <td class="text-nowrap"><?= format_toman((int) $r['total_amount']) ?></td>
            <td class="text-nowrap"><?= format_toman((int) $r['paid_amount']) ?></td>
            <?php if ($scope === 'team'): ?><td class="small"><?= e($r['seller_name'] ?? '—') ?></td><?php endif; ?>
            <td class="small text-nowrap"><?= to_jalali($r['created_at']) ?></td>
            <td><?= orders_status_badge((string) $r['status']) ?><?php if ($r['status'] !== 'cancelled') { $__cs = $consentStatus[(int) $r['id']] ?? 'missing'; [$__cl, $__cc, $__ci] = consent_status_meta($__cs); echo ' <a href="order_view.php?id=' . (int) $r['id'] . '#order-consent" class="badge text-bg-' . $__cc . ' text-decoration-none" title="' . e($__cl) . '"><i class="fa-solid ' . $__ci . '"></i> پیامِ رضایت</a>'; } ?><?php if ($r['status'] === 'rejected' && $r['finance_note']): ?><div class="small text-danger mt-1"><?= e(mb_strimwidth((string) $r['finance_note'], 0, 60, '…')) ?></div><?php endif; ?></td>
            <td class="text-nowrap">
              <a href="order_view.php?id=<?= (int) $r['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="fa-solid fa-eye"></i></a>
              <?php if ($r['status'] === 'rejected' && (int) $r['seller_user_id'] === (int) $user['id']): ?><a href="order_submit.php?order_id=<?= (int) $r['id'] ?>" class="btn btn-sm btn-outline-danger" title="اصلاح و ارسال دوباره"><i class="fa-solid fa-pen"></i></a><?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>
  <?php endif; ?>
</div>
<?php require_once __DIR__ . '/includes/layout_bottom.php'; ?>
