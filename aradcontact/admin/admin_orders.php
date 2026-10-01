<?php
/**
 * واحد مالی — دریافت و بررسیِ سفارش‌ها (تأیید / رد / در انتظار) + گزارشِ فروش.
 */
require_once __DIR__ . '/../includes/auth.php';
$user = require_admin();
$pdo = db();
require_once __DIR__ . '/../includes/services_functions.php';
require_once __DIR__ . '/../includes/orders_functions.php';
require_once __DIR__ . '/../includes/payment_duplicates.php';

$ready = services_module_ready($pdo) && orders_ready($pdo);
$statuses = orders_statuses();
$methods = orders_payment_methods();
$view = in_array($_GET['view'] ?? '', ['report', 'receivables', 'payments'], true) ? $_GET['view'] : 'list';
$status = (string) ($_GET['status'] ?? ($view === 'list' ? 'pending' : ''));
if ($status !== 'all' && !isset($statuses[$status])) {
    $status = $view === 'list' ? 'pending' : 'all';
}
$q = trim((string) ($_GET['q'] ?? ''));
$sellerId = (int) ($_GET['seller_id'] ?? 0);
$method = isset($methods[$_GET['method'] ?? '']) ? (string) $_GET['method'] : '';
$preset = (string) ($_GET['preset'] ?? ($view === 'report' ? 'this_month' : 'all'));
switch ($preset) {
    case 'today': $from = $to = date('Y-m-d'); break;
    case 'this_week': $from = date('Y-m-d', strtotime('-' . ((int) date('N') % 7) . ' days')); $to = date('Y-m-d'); break;
    case 'this_month': $from = date('Y-m-01'); $to = date('Y-m-d'); break;
    case 'last_month': $from = date('Y-m-01', strtotime('first day of last month')); $to = date('Y-m-t', strtotime('last day of last month')); break;
    case 'custom':
        $from = (string) (to_gregorian((string) ($_GET['from'] ?? '')) ?? date('Y-m-01'));
        $to = (string) (to_gregorian((string) ($_GET['to'] ?? '')) ?? date('Y-m-d'));
        break;
    default: $preset = 'all'; $from = ''; $to = '';
}

$rows = [];
$counts = array_fill_keys(array_keys($statuses), 0);
$sums = array_fill_keys(array_keys($statuses), 0);
$sellers = [];
$report = ['by_seller' => [], 'by_service' => [], 'by_day' => []];

if ($ready) {
    $where = ['1=1'];
    $params = [];
    if ($from !== '') { $where[] = 'o.created_at >= ?'; $params[] = $from . ' 00:00:00'; }
    if ($to !== '')   { $where[] = 'o.created_at <= ?'; $params[] = $to . ' 23:59:59'; }
    $__whereNS = $where; $__paramsNS = $params; // بدونِ فیلترِ کارشناس (برای فروشِ مشترک در گزارش)
    if ($sellerId > 0) { $where[] = 'o.seller_user_id = ?'; $params[] = $sellerId; }
    if ($method !== '') { $where[] = 'o.payment_method = ?'; $params[] = $method; }
    if ($method !== '') { $__whereNS[] = 'o.payment_method = ?'; $__paramsNS[] = $method; }
    if ($q !== '') {
        $where[] = '(c.full_name LIKE ? OR c.mobile LIKE ? OR o.order_number LIKE ? OR o.payment_ref LIKE ?)';
        $like = '%' . normalize_digits($q) . '%';
        array_push($params, '%' . $q . '%', $like, $like, $like);
        $__whereNS[] = '(c.full_name LIKE ? OR c.mobile LIKE ? OR o.order_number LIKE ? OR o.payment_ref LIKE ?)';
        array_push($__paramsNS, '%' . $q . '%', $like, $like, $like);
    }
    $base = 'FROM sales_orders o LEFT JOIN customers c ON c.id = o.customer_id LEFT JOIN users s ON s.id = o.seller_user_id WHERE ' . implode(' AND ', $where);

    $st = $pdo->prepare("SELECT o.status, COUNT(*) cnt, COALESCE(SUM(CASE WHEN o.status='approved' THEN COALESCE(o.confirmed_amount,o.total_amount) ELSE o.total_amount END),0) amt $base GROUP BY o.status");
    $st->execute($params);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (isset($counts[$r['status']])) {
            $counts[$r['status']] = (int) $r['cnt'];
            $sums[$r['status']] = (int) $r['amt'];
        }
    }

    $sellers = $pdo->query('SELECT DISTINCT u.id, u.full_name, u.mobile FROM sales_orders o JOIN users u ON u.id = o.seller_user_id ORDER BY u.full_name')->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $listSql = "SELECT o.*, c.full_name AS customer_name, c.mobile AS customer_mobile, s.full_name AS seller_name, s.role AS seller_role,
                (SELECT COUNT(*) FROM sales_order_files f WHERE f.order_id = o.id) AS files_cnt,
                (SELECT GROUP_CONCAT(i.title SEPARATOR '، ') FROM sales_order_items i WHERE i.order_id = o.id) AS items_txt
                $base";
    $listParams = $params;
    if ($status !== 'all') {
        $listSql .= ' AND o.status = ?';
        $listParams[] = $status;
    }

    if (isset($_GET['export'])) {
        // خروجیِ اکسل: هر خدمتِ فروخته‌شده یک سطرِ جدا (نه چند خدمت پشتِ هم در یک خانه)
        $st = $pdo->prepare($listSql . ' ORDER BY o.id DESC LIMIT 20000');
        $st->execute($listParams);
        $orderRows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $itemsBy = [];
        $hasDept = function_exists('services_ticket_ready') && services_ticket_ready($pdo);
        foreach (array_chunk(array_map('intval', array_column($orderRows, 'id')), 1000) as $chunk) {
            if (!$chunk) continue;
            $in = implode(',', $chunk);
            $isql = 'SELECT i.order_id, i.title, i.unit, i.quantity, i.unit_price, i.amount, sv.category'
                . ($hasDept ? ', sv.ticket_department' : ", NULL AS ticket_department")
                . " FROM sales_order_items i LEFT JOIN services sv ON sv.id = i.service_id WHERE i.order_id IN ($in) ORDER BY i.order_id, i.id";
            foreach ($pdo->query($isql) as $it) {
                $itemsBy[(int) $it['order_id']][] = $it;
            }
        }
        // تاریخِ شمسی بدونِ «/» — مثلاً 14050707 (ارقامِ انگلیسی تا اکسل عدد بشناسد و مرتب/فیلتر کند)
        $jcompact = static function (?string $g): string {
            if (empty($g) || str_starts_with((string) $g, '0000')) return '';
            [$gy, $gm, $gd] = array_map('intval', explode('-', substr((string) $g, 0, 10)));
            [$jy, $jm, $jd] = gregorian_to_jalali_arr($gy, $gm, $gd);
            return sprintf('%04d%02d%02d', $jy, $jm, $jd);
        };
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="sales_' . date('Ymd_His') . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['شماره فاکتور', 'تاریخ ثبت', 'مشتری', 'موبایل', 'کارشناس', 'خدمت', 'دسته خدمت', 'دپارتمان', 'تعداد', 'واحد',
            'قیمت واحد', 'مبلغ این خدمت', 'مبلغ کل فاکتور', 'پرداختی', 'تأییدشده', 'روش پرداخت', 'شماره پیگیری', 'وضعیت', 'توضیح مالی', 'فروش مشترک (تفکیک)']);
        $__splitTxt = [];
        try {
            require_once __DIR__ . '/../includes/sales_credit.php';
            if (scr_ready($pdo) && $orderRows) {
                $__ids = implode(',', array_map(static fn($x) => (int) $x['id'], $orderRows));
                foreach ($pdo->query("SELECT sp.order_id, u.full_name, sp.amount FROM sales_order_credit_splits sp LEFT JOIN users u ON u.id = sp.user_id WHERE sp.order_id IN ($__ids) ORDER BY sp.id") as $__x) {
                    $__splitTxt[(int) $__x['order_id']][] = $__x['full_name'] . ': ' . $__x['amount'];
                }
            }
        } catch (Throwable $e) {}
        foreach ($orderRows as $r) {
            $items = $itemsBy[(int) $r['id']] ?? [['title' => '', 'unit' => '', 'quantity' => '', 'unit_price' => '', 'amount' => '', 'category' => '', 'ticket_department' => '']];
            foreach ($items as $n => $it) {
                $first = $n === 0; // مبالغِ کلِ فاکتور فقط در سطرِ اول (تا جمعِ ستون در اکسل دوبار حساب نشود)
                $qty = $it['quantity'] === '' ? '' : rtrim(rtrim(number_format((float) $it['quantity'], 2, '.', ''), '0'), '.');
                fputcsv($out, [
                    $r['order_number'], $jcompact($r['created_at']), $r['customer_name'], $r['customer_mobile'], $r['seller_name'],
                    $it['title'], (string) ($it['category'] ?? ''), (string) ($it['ticket_department'] ?? ''), $qty, (string) ($it['unit'] ?? ''),
                    $it['unit_price'], $it['amount'],
                    $first ? $r['total_amount'] : '', $first ? $r['paid_amount'] : '', $first ? $r['confirmed_amount'] : '',
                    $methods[$r['payment_method']] ?? $r['payment_method'], $r['payment_ref'],
                    $statuses[$r['status']]['label'] ?? $r['status'], $first ? $r['finance_note'] : '',
                    $first ? implode(' | ', $__splitTxt[(int) $r['id']] ?? []) : '',
                ]);
            }
        }
        fclose($out);
        exit;
    }

    if ($view === 'list') {
        $st = $pdo->prepare($listSql . ' ORDER BY ' . ($status === 'pending' ? 'o.submitted_at ASC' : 'o.id DESC') . ' LIMIT 300');
        $st->execute($listParams);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } else {
        $ap = array_merge($params, []);
        // فروش به تفکیکِ کارشناس — «فروشِ مشترک»: اگر مالی عددِ فروشِ سفارشی را بینِ چند نفر تفکیک کرده، هر نفر سهمِ خودش را می‌گیرد
        // (فقط نمایشی؛ سهم عملکرد جداست). اگر عددِ سفارش بعداً عوض شده باشد، تفکیک به همان نسبت اعمال می‌شود.
        $__splitReady = false;
        try { require_once __DIR__ . '/../includes/sales_credit.php'; $__splitReady = scr_ready($pdo); } catch (Throwable $e) {}
        if ($__splitReady) {
            $__bNS = 'FROM sales_orders o LEFT JOIN customers c ON c.id = o.customer_id WHERE ' . implode(' AND ', $__whereNS) . " AND o.status = 'approved'";
            $__sql = "SELECT u.full_name, u.role, COUNT(DISTINCT x.order_id) cnt, SUM(x.amt) amt, SUM(x.shared) shared_cnt FROM (
                    SELECT o.id order_id, o.seller_user_id uid, COALESCE(o.confirmed_amount,o.total_amount) amt, 0 shared $__bNS
                        AND NOT EXISTS (SELECT 1 FROM sales_order_credit_splits sp0 WHERE sp0.order_id = o.id)
                    UNION ALL
                    SELECT o.id, sp.user_id, ROUND(sp.amount * COALESCE(o.confirmed_amount,o.total_amount) / NULLIF(t.tot, 0)), 1
                        FROM sales_order_credit_splits sp
                        JOIN (SELECT order_id, SUM(amount) tot FROM sales_order_credit_splits GROUP BY order_id) t ON t.order_id = sp.order_id
                        JOIN sales_orders o ON o.id = sp.order_id LEFT JOIN customers c ON c.id = o.customer_id
                        WHERE " . implode(' AND ', $__whereNS) . " AND o.status = 'approved'
                ) x LEFT JOIN users u ON u.id = x.uid" . ($sellerId > 0 ? ' WHERE x.uid = ?' : '') . "
                GROUP BY x.uid, u.full_name, u.role ORDER BY amt DESC";
            $st = $pdo->prepare($__sql);
            $st->execute(array_merge($__paramsNS, $__paramsNS, $sellerId > 0 ? [$sellerId] : []));
        } else {
            $st = $pdo->prepare("SELECT s.full_name, s.role, COUNT(*) cnt, SUM(COALESCE(o.confirmed_amount,o.total_amount)) amt, 0 shared_cnt $base AND o.status = 'approved' GROUP BY o.seller_user_id, s.full_name, s.role ORDER BY amt DESC");
            $st->execute($ap);
        }
        $report['by_seller'] = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $st = $pdo->prepare("SELECT i.title, SUM(i.quantity) qty, SUM(i.amount) amt, COUNT(DISTINCT o.id) orders_cnt FROM sales_order_items i JOIN sales_orders o ON o.id = i.order_id LEFT JOIN customers c ON c.id = o.customer_id LEFT JOIN users s ON s.id = o.seller_user_id WHERE " . implode(' AND ', $where) . " AND o.status = 'approved' GROUP BY i.title ORDER BY amt DESC LIMIT 20");
        $st->execute($ap);
        $report['by_service'] = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $st = $pdo->prepare("SELECT DATE(o.decided_at) d, SUM(COALESCE(o.confirmed_amount,o.total_amount)) amt $base AND o.status = 'approved' GROUP BY DATE(o.decided_at) ORDER BY d");
        $st->execute($ap);
        $report['by_day'] = $st->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
    }
}

$canDecide = user_can('finance_orders_decide', $user);

/** انتخابِ کارشناس با جستجو (نام + موبایل) — به‌جای لیستِ کشوییِ طولانی */
$sellerPicker = static function (array $sellers, int $sellerId, string $uid): string {
    $selLabel = '';
    $opts = '';
    foreach ($sellers as $sl) {
        $lbl = person_pick_label((string) $sl['full_name'], $sl['mobile'] ?? null);
        if ((int) $sl['id'] === $sellerId) $selLabel = $lbl;
        $opts .= '<option data-id="' . (int) $sl['id'] . '" value="' . e($lbl) . '"></option>';
    }
    return '<input type="text" class="form-control form-control-sm seller-pick" list="sellerList_' . $uid . '" data-target="sellerId_' . $uid . '"'
        . ' placeholder="نام یا موبایلِ کارشناس — خالی = همه" autocomplete="off" value="' . e($selLabel) . '">'
        . '<datalist id="sellerList_' . $uid . '">' . $opts . '</datalist>'
        . '<input type="hidden" name="seller_id" id="sellerId_' . $uid . '" value="' . ($sellerId > 0 ? $sellerId : 0) . '">';
};

// ─── مطالبات، اقساط و پرداخت‌های در انتظارِ تأیید ───
$recv = [];
$recvSum = ['balance' => 0, 'overdue' => 0, 'due_week' => 0, 'unscheduled' => 0, 'orders' => 0, 'customers' => 0];
$pendingPayments = [];
if ($ready) {
    $recv = fin_receivables($pdo, $sellerId > 0 ? ['only_seller_ids' => [$sellerId]] : []);
    $recvSum = fin_receivables_summary($recv);
    $pendingPayments = fin_pending_payments($pdo);
    if ($view === 'receivables' && isset($_GET['export_debtors'])) {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="debtors_' . date('Ymd_His') . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['مشتری', 'موبایل', 'تعداد فاکتور', 'جمع فاکتور', 'پرداخت‌شده', 'مانده', 'معوق', 'قسط بعدی', 'مبلغ قسط بعدی', 'کارشناس']);
        foreach (fin_group_by_customer($recv) as $d) {
            fputcsv($out, [$d['customer_name'], $d['customer_mobile'], $d['orders'], $d['total'], $d['paid'], $d['balance'], $d['overdue'],
                $d['next_due'] ? to_jalali($d['next_due']['date']) : '', $d['next_due']['amount'] ?? '', implode('، ', array_keys($d['sellers']))]);
        }
        fclose($out);
        exit;
    }
}
$pageTitle = 'سفارشات و بررسی مالی';
require_once __DIR__ . '/../includes/layout_top.php';
?>
<style>
.fo{--l:#e7e2d3}
.fo .hero{background:linear-gradient(135deg,#052e1c 0%,#14532d 55%,#22c55e 140%);border-radius:18px;padding:18px 22px;color:#ecfdf5;margin-bottom:16px;display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;align-items:center}
.fo .hero h5{margin:0;font-weight:800;color:#ecfdf5}.fo .hero p{margin:.3rem 0 0;font-size:.8rem;color:#d1fae5}
.fo .kpi{border:1px solid var(--l);border-radius:14px;background:#fff;padding:12px;display:block;text-decoration:none;color:inherit;height:100%}
.fo .kpi.active{outline:2px solid #22c55e}
.fo .kpi .n{font-weight:800;font-size:1.35rem}
.fo .kpi .a{font-size:.75rem;color:#57534e}
.fo .card{border:1px solid var(--l);border-radius:16px;box-shadow:0 4px 16px -14px rgba(28,25,23,.3)}
.fo .chip{border:1px solid var(--l);border-radius:20px;padding:.25rem .8rem;font-size:.78rem;color:#1c1917;background:#fff;text-decoration:none;display:inline-block}
.fo .chip.active{background:linear-gradient(135deg,#bbf7d0,#22c55e);border-color:transparent;font-weight:700}
.fo table td,.fo table th{font-size:.8rem;vertical-align:middle}
.fo .nav-pills .nav-link{border-radius:999px;font-size:.85rem}
.fo .nav-pills .nav-link.active{background:#16a34a}
</style>

<div class="fo">
  <div class="hero">
    <div>
      <h5><i class="fa-solid fa-file-invoice-dollar"></i> سفارشات و بررسی مالی</h5>
      <p>سفارش‌هایی که کارشناسان از پرونده‌ی مشتری ثبت کرده‌اند؛ فیش را بررسی و سفارش را تأیید، رد یا در انتظار قرار دهید.</p>
    </div>
    <div class="d-flex gap-2">
      <a href="admin_dashboard.php" class="btn btn-sm btn-outline-light"><i class="fa-solid fa-arrow-right"></i> پنل مدیریت</a>
    </div>
  </div>

  <?php if (!$ready): ?>
    <?= services_module_not_ready_html() ?>
  <?php else: ?>

  <ul class="nav nav-pills gap-2 mb-3">
    <li class="nav-item"><a class="nav-link <?= $view === 'list' ? 'active' : '' ?>" href="admin_orders.php"><i class="fa-solid fa-inbox"></i> صفِ بررسی و سفارش‌ها</a></li>
    <li class="nav-item"><a class="nav-link <?= $view === 'receivables' ? 'active' : '' ?>" href="admin_orders.php?view=receivables"><i class="fa-solid fa-hand-holding-dollar"></i> مطالبات و اقساط
      <?php if ($recvSum['overdue'] + $recvSum['unscheduled'] > 0): ?><span class="badge text-bg-danger ms-1"><?= format_toman($recvSum['overdue'] + $recvSum['unscheduled']) ?> معوق</span><?php endif; ?></a></li>
    <li class="nav-item"><a class="nav-link <?= $view === 'payments' ? 'active' : '' ?>" href="admin_orders.php?view=payments"><i class="fa-solid fa-money-bill-transfer"></i> پرداخت‌های در انتظارِ تأیید
      <?php if ($pendingPayments): ?><span class="badge text-bg-warning ms-1"><?= to_persian_digits((string) count($pendingPayments)) ?></span><?php endif; ?></a></li>
    <li class="nav-item"><a class="nav-link <?= $view === 'report' ? 'active' : '' ?>" href="admin_orders.php?view=report"><i class="fa-solid fa-chart-column"></i> گزارش فروش</a></li>
  </ul>

  <?php if ($view === 'receivables'): ?>
    <div class="card p-3 mb-3">
      <form method="get" class="d-flex gap-2 flex-wrap align-items-end">
        <input type="hidden" name="view" value="receivables">
        <div style="min-width:260px"><label class="form-label small mb-1">کارشناس</label><?= $sellerPicker($sellers, $sellerId, 'recv') ?></div>
        <button class="btn btn-sm btn-success">اعمال</button>
        <a class="btn btn-sm btn-outline-success" href="?<?= e(http_build_query(array_merge($_GET, ['view' => 'receivables', 'export_debtors' => 1]))) ?>"><i class="fa-solid fa-file-csv"></i> خروجیِ اکسلِ بدهکاران</a>
      </form>
    </div>
    <?php $recvBase = '../'; $recvShowSeller = true; require __DIR__ . '/../includes/receivables_view.php'; ?>
  <?php elseif ($view === 'payments'): ?>
    <div class="card p-0">
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead class="table-light"><tr><th>ثبت</th><th>مشتری</th><th>فاکتور</th><th>مبلغ</th><th>تاریخ پرداخت</th><th>روش / پیگیری</th><th>ثبت‌کننده</th><th>فیش</th><th></th></tr></thead>
          <tbody>
          <?php if (!$pendingPayments): ?><tr><td colspan="9" class="text-center text-muted py-4">پرداختی در انتظارِ تأیید نیست.</td></tr><?php endif; ?>
          <?php foreach ($pendingPayments as $p): ?>
            <tr>
              <td class="small text-nowrap"><?= to_jalali($p['created_at']) ?></td>
              <td class="fw-semibold"><?= e((string) $p['customer_name']) ?></td>
              <td class="text-nowrap"><?= e(to_persian_digits((string) $p['order_number'])) ?></td>
              <td class="text-nowrap fw-bold"><?= format_toman((int) $p['amount']) ?>
                <?php
                  $__pd = pdup_ready($pdo) ? pdup_payment_candidates($pdo, ['customer_id' => (int) $p['customer_id'], 'amount' => (int) $p['amount'],
                      'ref' => (string) ($p['ref'] ?? ''), 'payment_id' => (int) $p['id'], 'created_at' => (string) $p['created_at']], pdup_payment_hashes($pdo, (int) $p['id'])) : [];
                  if ($__pd): $__pc = $__pd[0]['level'] === 'certain';
                ?>
                  <div><span class="badge <?= $__pc ? 'text-bg-danger' : 'text-bg-warning' ?>" title="<?= e(implode(' | ', array_map(static fn($d) => 'فاکتور ' . $d['payment']['order_number'] . ' — ' . number_format((int) $d['payment']['amount']) . ' — ثبت: ' . ($d['payment']['recorder_name'] ?? '—'), $__pd))) ?>"><i class="fa-solid fa-clone"></i> <?= $__pc ? 'واریزیِ تکراری' : 'احتمالِ تکراری' ?></span></div>
                <?php endif; ?></td>
              <td class="text-nowrap"><?= $p['paid_at'] ? to_jalali($p['paid_at']) : '—' ?></td>
              <td class="small"><?= e($methods[$p['method']] ?? (string) $p['method']) ?><?= $p['ref'] ? '<div dir="ltr" class="text-muted">' . e($p['ref']) . '</div>' : '' ?></td>
              <td class="small"><?= e((string) ($p['recorder_name'] ?? '—')) ?></td>
              <td><span class="badge <?= (int) $p['files_cnt'] > 0 ? 'text-bg-success' : 'text-bg-danger' ?>"><i class="fa-solid fa-receipt"></i> <?= to_persian_digits((string) $p['files_cnt']) ?></span></td>
              <td><a class="btn btn-sm btn-success text-nowrap" href="../order_view.php?id=<?= (int) $p['order_id'] ?>#finance"><i class="fa-solid fa-scale-balanced"></i> بررسی</a></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php else: ?>

  <div class="row g-2 mb-3">
    <?php $qs = $_GET; unset($qs['status']); ?>
    <?php foreach ($statuses as $k => $m): $q2 = $qs; $q2['status'] = $k; ?>
      <div class="col-6 col-md-3">
        <a class="kpi <?= $status === $k ? 'active' : '' ?>" href="?<?= e(http_build_query($q2)) ?>">
          <div class="d-flex justify-content-between"><span class="small text-muted"><i class="fa-solid <?= e($m['icon']) ?>"></i> <?= e($m['label']) ?></span><span class="n text-<?= e($m['color']) ?>"><?= to_persian_digits((string) $counts[$k]) ?></span></div>
          <div class="a"><?= format_toman($sums[$k]) ?></div>
        </a>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="card p-3 mb-3">
    <form method="get" class="row g-2 align-items-end">
      <?php if ($view === 'report'): ?><input type="hidden" name="view" value="report"><?php endif; ?>
      <div class="col-12 d-flex gap-2 flex-wrap">
        <?php foreach (['all' => 'همه‌ی تاریخ‌ها', 'today' => 'امروز', 'this_week' => 'این هفته', 'this_month' => 'این ماه', 'last_month' => 'ماه گذشته', 'custom' => 'بازه دلخواه'] as $pk => $pl): $q3 = $_GET; $q3['preset'] = $pk; ?>
          <a class="chip <?= $preset === $pk ? 'active' : '' ?>" href="?<?= e(http_build_query($q3)) ?>"><?= $pl ?></a>
        <?php endforeach; ?>
      </div>
      <input type="hidden" name="preset" value="<?= e($preset) ?>">
      <?php if ($preset === 'custom'): ?>
        <div class="col-md-2"><label class="form-label small mb-1">از</label><input name="from" class="form-control form-control-sm jalali-date" autocomplete="off" value="<?= e((string) ($_GET['from'] ?? '')) ?>"></div>
        <div class="col-md-2"><label class="form-label small mb-1">تا</label><input name="to" class="form-control form-control-sm jalali-date" autocomplete="off" value="<?= e((string) ($_GET['to'] ?? '')) ?>"></div>
      <?php endif; ?>
      <div class="col-md-3"><label class="form-label small mb-1">جستجو</label><input name="q" value="<?= e($q) ?>" class="form-control form-control-sm" placeholder="مشتری، موبایل، شماره فاکتور، پیگیری"></div>
      <div class="col-md-2"><label class="form-label small mb-1">وضعیت</label>
        <select name="status" class="form-select form-select-sm">
          <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>همه</option>
          <?php foreach ($statuses as $sk => $sm): ?><option value="<?= e($sk) ?>" <?= $status === $sk ? 'selected' : '' ?>><?= e($sm['label']) ?></option><?php endforeach; ?>
        </select></div>
      <div class="col-md-3"><label class="form-label small mb-1">کارشناس</label><?= $sellerPicker($sellers, $sellerId, 'main') ?></div>
      <div class="col-md-2"><label class="form-label small mb-1">روش پرداخت</label>
        <select name="method" class="form-select form-select-sm"><option value="">همه</option>
          <?php foreach ($methods as $mk => $ml): ?><option value="<?= e($mk) ?>" <?= $method === $mk ? 'selected' : '' ?>><?= e($ml) ?></option><?php endforeach; ?>
        </select></div>
      <div class="col-md-auto d-flex gap-2">
        <button class="btn btn-sm btn-success">اعمال</button>
        <a class="btn btn-sm btn-outline-success" href="?<?= e(http_build_query(array_merge($_GET, ['export' => 1, 'status' => $status]))) ?>"><i class="fa-solid fa-file-csv"></i> خروجی اکسل</a>
      </div>
    </form>
  </div>

  <?php if ($view === 'list'): ?>
    <div class="d-flex gap-2 mb-2 small">
      <a class="chip <?= $status === 'all' ? 'active' : '' ?>" href="?<?= e(http_build_query(array_merge($qs, ['status' => 'all']))) ?>">همه‌ی وضعیت‌ها</a>
    </div>
    <div class="card p-0">
      <div class="table-responsive">
        <table class="table table-hover mb-0">
          <thead class="table-light"><tr><th>شماره</th><th>ثبت / ارسال</th><th>مشتری</th><th>خدمات</th><th>کارشناس</th><th>مبلغ فاکتور</th><th>پرداختی</th><th>روش</th><th>فیش</th><th>وضعیت</th><th></th></tr></thead>
          <tbody>
          <?php if (!$rows): ?><tr><td colspan="11" class="text-center text-muted py-4"><?= $status === 'pending' ? 'سفارشی در صفِ بررسی نیست 🎉' : 'سفارشی پیدا نشد.' ?></td></tr><?php endif; ?>
          <?php foreach ($rows as $r): $diff = (int) $r['paid_amount'] - (int) $r['total_amount']; ?>
            <tr>
              <td class="fw-semibold text-nowrap"><?= e(to_persian_digits($r['order_number'])) ?></td>
              <td class="small text-nowrap"><?= to_jalali($r['submitted_at'] ?? $r['created_at']) ?><div class="text-muted"><?= e(substr((string) ($r['submitted_at'] ?? $r['created_at']), 11, 5)) ?></div></td>
              <td><?= e($r['customer_name'] ?? '—') ?><div class="small text-muted" dir="ltr"><?= e((string) $r['customer_mobile']) ?></div></td>
              <td class="small" style="max-width:220px"><?= e(mb_strimwidth((string) $r['items_txt'], 0, 90, '…')) ?></td>
              <td class="small"><?= e($r['seller_name'] ?? '—') ?><div class="text-muted"><?= e(role_label((string) $r['seller_role'])) ?></div></td>
              <td class="text-nowrap"><?= format_toman((int) $r['total_amount']) ?></td>
              <td class="text-nowrap"><?= format_toman((int) $r['paid_amount']) ?><?php if ($diff < 0): ?><div class="small text-warning">کسری <?= format_toman(abs($diff)) ?></div><?php endif; ?></td>
              <td class="small"><?= e($methods[$r['payment_method']] ?? (string) $r['payment_method']) ?><?php if ($r['payment_ref']): ?><div class="text-muted" dir="ltr"><?= e($r['payment_ref']) ?></div><?php endif; ?></td>
              <td><span class="badge <?= (int) $r['files_cnt'] > 0 ? 'text-bg-success' : 'text-bg-danger' ?>"><i class="fa-solid fa-receipt"></i> <?= to_persian_digits((string) $r['files_cnt']) ?></span></td>
              <td><?= orders_status_badge((string) $r['status']) ?>
                <?php
                  // هشدارِ واریزیِ تکراری (فقط برای سفارش‌های در انتظار — همان‌هایی که مالی باید تصمیم بگیرد)
                  $__dups = ($r['status'] === 'pending' && pdup_ready($pdo)) ? pdup_candidates($pdo, $r) : [];
                  if ($__dups):
                      $__cert = $__dups[0]['level'] === 'certain';
                      $__with = [];
                      foreach ($__dups as $__d) $__with[] = to_persian_digits((string) $__d['order']['order_number']) . ' (' . ($__d['order']['seller_name'] ?? '—') . ')';
                ?>
                  <div class="mt-1"><a href="../order_view.php?id=<?= (int) $r['id'] ?>#dup-check" class="badge <?= $__cert ? 'text-bg-danger' : 'text-bg-warning' ?> text-decoration-none" title="<?= e('مشابهِ: ' . implode('، ', $__with)) ?>"><i class="fa-solid fa-clone"></i> <?= $__cert ? 'واریزیِ تکراری' : 'احتمالِ تکراری' ?></a></div>
                <?php endif; ?>
              </td>
              <td><a href="../order_view.php?id=<?= (int) $r['id'] ?>" class="btn btn-sm <?= $r['status'] === 'pending' && $canDecide ? 'btn-success' : 'btn-outline-primary' ?> text-nowrap"><?= $r['status'] === 'pending' && $canDecide ? '<i class="fa-solid fa-scale-balanced"></i> بررسی' : '<i class="fa-solid fa-eye"></i>' ?></a></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php else: ?>
    <div class="row g-3">
      <div class="col-lg-12">
        <div class="card p-3"><h6 class="fw-bold mb-2">فروشِ تأییدشده به تفکیکِ روز</h6><canvas id="chDay" height="90"></canvas></div>
      </div>
      <div class="col-lg-6">
        <div class="card p-3 h-100">
          <h6 class="fw-bold mb-2">فروش به تفکیکِ کارشناس</h6>
          <table class="table table-sm mb-0"><thead class="table-light"><tr><th>کارشناس</th><th>واحد</th><th>تعداد</th><th class="text-end">مبلغ</th></tr></thead><tbody>
            <?php if (!$report['by_seller']): ?><tr><td colspan="4" class="text-center text-muted py-3">فروشِ تأییدشده‌ای در این بازه نیست.</td></tr><?php endif; ?>
            <?php foreach ($report['by_seller'] as $r): ?><tr><td><?= e((string) $r['full_name']) ?><?php if ((int) ($r['shared_cnt'] ?? 0) > 0): ?> <span class="badge text-bg-light border" style="color:#6d28d9" title="سفارش‌هایی که عددِ فروششان با کارشناسانِ دیگر تفکیک شده"><i class="fa-solid fa-people-group"></i> <?= to_persian_digits((string) (int) $r['shared_cnt']) ?> مشترک</span><?php endif; ?></td><td class="small"><?= e(role_label((string) $r['role'])) ?></td><td><?= to_persian_digits((string) $r['cnt']) ?></td><td class="text-end"><?= format_toman((int) $r['amt']) ?></td></tr><?php endforeach; ?>
          </tbody></table>
        </div>
      </div>
      <div class="col-lg-6">
        <div class="card p-3 h-100">
          <h6 class="fw-bold mb-2">پرفروش‌ترین خدمات</h6>
          <table class="table table-sm mb-0"><thead class="table-light"><tr><th>خدمت</th><th>تعداد سفارش</th><th>مقدار</th><th class="text-end">مبلغ</th></tr></thead><tbody>
            <?php if (!$report['by_service']): ?><tr><td colspan="4" class="text-center text-muted py-3">—</td></tr><?php endif; ?>
            <?php foreach ($report['by_service'] as $r): ?><tr><td class="small"><?= e($r['title']) ?></td><td><?= to_persian_digits((string) $r['orders_cnt']) ?></td><td><?= to_persian_digits((string) (float) $r['qty']) ?></td><td class="text-end"><?= format_toman((int) $r['amt']) ?></td></tr><?php endforeach; ?>
          </tbody></table>
        </div>
      </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
    <script>
    new Chart(document.getElementById('chDay'), {
      type: 'bar',
      data: { labels: <?= json_encode(array_map('to_jalali', array_map('strval', array_keys($report['by_day']))), JSON_UNESCAPED_UNICODE) ?>,
        datasets: [{ label: 'تومان', data: <?= json_encode(array_map('intval', array_values($report['by_day']))) ?>, backgroundColor: '#22c55e' }] },
      options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
    });
    </script>
  <?php endif; ?>
  <?php endif; ?>
  <?php endif; ?>
</div>
<script>
// انتخابِ کارشناس با جستجو: متنِ انتخاب‌شده → شناسه‌ی کارشناس (خالی = همه)
document.querySelectorAll('.seller-pick').forEach(function (inp) {
  var hidden = document.getElementById(inp.dataset.target), list = document.getElementById(inp.getAttribute('list'));
  function sync() {
    var v = inp.value.trim(), id = 0;
    if (v !== '') {
      for (var i = 0; i < list.options.length; i++) { if (list.options[i].value === v) { id = list.options[i].getAttribute('data-id'); break; } }
    }
    hidden.value = id;
    inp.classList.toggle('is-invalid', v !== '' && !id);
  }
  inp.addEventListener('input', sync); inp.addEventListener('change', sync);
  if (inp.form) inp.form.addEventListener('submit', sync);
});
</script>
<?php require_once __DIR__ . '/../includes/layout_bottom.php'; ?>
