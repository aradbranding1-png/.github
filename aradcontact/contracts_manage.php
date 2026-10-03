<?php
/**
 * مدیریت قراردادها — همه‌ی سفارش‌های تأییدشده با وضعیتِ قرارداد و ارسالِ تیکت:
 *   بدونِ قرارداد ← ساختِ قرارداد | پیش‌نویس/تأییدشده ← تکمیل و صدور | صادرشده و ارسال‌نشده ← ارسال با تیکت
 *   | ارسال‌شده (با لینکِ مشاهده‌ی تیکت) | تیکتِ ناموفق/در صف/حذف‌شده.
 * دسترسی: مجوزِ «مدیریتِ قراردادها» (contracts_dashboard) — ادمین کل، ادمین، واحدِ قرارداد و واحدِ مالی.
 */
require_once __DIR__ . '/includes/auth.php';
$user = require_login();
$pdo = db();
require_once __DIR__ . '/includes/services_functions.php';
require_once __DIR__ . '/includes/orders_functions.php';
require_once __DIR__ . '/includes/contracts_functions.php';
require_once __DIR__ . '/includes/aradbranding_ticket.php';

if (!(is_super_admin($user) || user_can('contracts_dashboard', $user))) {
    perm_deny('اجازه‌ی دیدنِ «مدیریت قراردادها» را ندارید.', $user);
}
if (!ctr_ready($pdo) || !orders_ready($pdo)) {
    exit('ماژولِ قرارداد/سفارش آماده نیست.');
}
$canCreate = ctr_can_approve($user);
$abtOk = abt_ready($pdo);

// ─── فیلترها ───
$f = (string) ($_GET['f'] ?? 'todo');
$q = trim((string) ($_GET['q'] ?? ''));
$fromJ = trim((string) ($_GET['from'] ?? ''));
$toJ = trim((string) ($_GET['to'] ?? ''));
$fromG = $fromJ !== '' ? to_gregorian(normalize_digits($fromJ)) : null;
$toG = $toJ !== '' ? to_gregorian(normalize_digits($toJ)) : null;
$perPage = in_array((int) ($_GET['per'] ?? 0), [25, 50, 100, 200], true) ? (int) $_GET['per'] : 50;
$pageNo = max(1, (int) ($_GET['p'] ?? 1));

$states = [
    'no_contract' => ['label' => 'قرارداد ساخته نشده', 'color' => 'danger', 'icon' => 'fa-file-circle-plus', 'hint' => 'برای این سفارش‌ها هنوز قرارداد ساخته نشده است.'],
    'draft'       => ['label' => 'پیش‌نویس / صادرنشده', 'color' => 'warning', 'icon' => 'fa-pen', 'hint' => 'قرارداد ساخته شده ولی هنوز صادر نشده؛ تکمیل، تأیید و «صدور» لازم است.'],
    'not_sent'    => ['label' => 'صادرشده — ارسال‌نشده', 'color' => 'primary', 'icon' => 'fa-paper-plane', 'hint' => 'قرارداد صادر شده ولی هنوز با تیکت برای مشتری نرفته است.'],
    'failed'      => ['label' => 'تیکتِ ناموفق / در صف', 'color' => 'danger', 'icon' => 'fa-triangle-exclamation', 'hint' => 'ارسالِ تیکت انجام نشده یا خطا داده؛ دوباره ارسال کنید.'],
    'sent'        => ['label' => 'ارسال‌شده با تیکت', 'color' => 'success', 'icon' => 'fa-circle-check', 'hint' => 'تیکتِ قرارداد در آراد برندینگ ثبت شده است.'],
];
$todoStates = ['no_contract', 'draft', 'not_sent', 'failed'];

// ─── داده‌ها ───
$hasLegacy = false;
try { $pdo->query('SELECT is_legacy FROM sales_orders LIMIT 0'); $hasLegacy = true; } catch (Throwable $e) {}
$where = ["o.status = 'approved'"];
$params = [];
if ($hasLegacy) $where[] = 'COALESCE(o.is_legacy, 0) = 0';
if ($fromG) { $where[] = 'o.decided_at >= ?'; $params[] = $fromG . ' 00:00:00'; }
if ($toG) { $where[] = 'o.decided_at <= ?'; $params[] = $toG . ' 23:59:59'; }
if ($q !== '') {
    $qn = normalize_digits($q);
    $where[] = '(c.full_name LIKE ? OR c.mobile LIKE ? OR o.order_number LIKE ? OR ct.contract_number LIKE ?)';
    array_push($params, '%' . $q . '%', '%' . $qn . '%', '%' . $qn . '%', '%' . $qn . '%');
}
$sql = "SELECT o.id, o.order_number, o.customer_id, o.quote_id, o.decided_at, o.total_amount,
               c.full_name, c.mobile, s.full_name AS seller_name,
               ct.id AS contract_id, ct.contract_number, ct.status AS contract_status, ct.issued_at
        FROM sales_orders o
        JOIN customers c ON c.id = o.customer_id
        LEFT JOIN users s ON s.id = o.seller_user_id
        LEFT JOIN contracts ct ON ct.id = (SELECT MAX(x.id) FROM contracts x WHERE x.quote_id = o.quote_id AND x.status <> 'cancelled')
        WHERE " . implode(' AND ', $where) . "
        ORDER BY o.decided_at DESC, o.id DESC";
$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

// تیکت‌های قرارداد (یک کوئری): کلید = مشتری + «قرارداد ‹شماره›»
$tickets = [];
if ($abtOk && $rows) {
    $custIds = implode(',', array_unique(array_map(static fn($r) => (int) $r['customer_id'], $rows)));
    foreach ($pdo->query("SELECT id, customer_id, service_title, status, external_id, external_url, last_error, sent_at, updated_at
            FROM aradbranding_tickets WHERE item_id IS NULL AND customer_id IN ($custIds) AND service_title LIKE 'قرارداد %' ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC) ?: [] as $t) {
        $tickets[(int) $t['customer_id'] . '|' . $t['service_title']][] = $t;
    }
}
// سابقه‌ی ارسال (همه‌ی راه‌ها) و «مشاهده‌شده توسطِ مشتری»
$sendInfo = [];
$ctrIds = array_values(array_filter(array_map(static fn($r) => (int) $r['contract_id'], $rows)));
if ($ctrIds) {
    foreach ($pdo->query('SELECT contract_id, COUNT(*) n, MAX(created_at) last_at, SUM(status = \'viewed\') viewed FROM contract_sends WHERE contract_id IN (' . implode(',', $ctrIds) . ') GROUP BY contract_id')->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
        $sendInfo[(int) $r['contract_id']] = $r;
    }
}

$counts = array_fill_keys(array_keys($states), 0);
$list = [];
foreach ($rows as $r) {
    $r['ticket'] = null;
    if (!$r['contract_id']) {
        $r['state'] = 'no_contract';
    } elseif ($r['contract_status'] !== 'issued') {
        $r['state'] = 'draft';
    } else {
        $key = (int) $r['customer_id'] . '|' . mb_substr('قرارداد ' . to_persian_digits((string) $r['contract_number']), 0, 250);
        $ts = $tickets[$key] ?? [];
        $sent = array_values(array_filter($ts, static fn($t) => in_array($t['status'], ['sent', 'manual'], true)));
        $pending = array_values(array_filter($ts, static fn($t) => in_array($t['status'], ['queued', 'failed'], true)));
        if ($sent) { $r['state'] = 'sent'; $r['ticket'] = $sent[0]; }
        elseif ($pending) { $r['state'] = 'failed'; $r['ticket'] = $pending[0]; }
        else { $r['state'] = 'not_sent'; $r['ticket'] = $ts[0] ?? null; } // حذف‌شده هم «ارسال‌نشده» است
    }
    $counts[$r['state']]++;
    if ($f === 'todo' ? in_array($r['state'], $todoStates, true) : ($f === 'all' || $f === $r['state'])) $list[] = $r;
}
$total = count($list);
$pages = max(1, (int) ceil($total / $perPage));
$pageNo = min($pageNo, $pages);
$list = array_slice($list, ($pageNo - 1) * $perPage, $perPage);
$todoCount = array_sum(array_intersect_key($counts, array_flip($todoStates)));
$qs = static fn(array $set) => '?' . e(http_build_query(array_merge(['f' => $f, 'q' => $q, 'from' => $fromJ, 'to' => $toJ, 'per' => $perPage], $set)));

$pageTitle = 'مدیریت قراردادها';
require_once __DIR__ . '/includes/layout_top.php';
?>
<style>
.cm .card{ border-radius:14px; border:1px solid rgba(201,162,75,.28); }
.cm a.stat{ display:block; text-decoration:none; height:100%; }
.cm a.stat.active{ border-width:2px !important; }
.cm .chip{ display:inline-block; padding:3px 10px; border-radius:20px; border:1px solid #e7e2d3; font-size:12px; text-decoration:none; color:#44403c; }
.cm .chip.active{ background:#1c1917; color:#fff; border-color:#1c1917; }
</style>
<div class="cm">
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <div>
      <h4 class="fw-bold mb-0"><i class="fa-solid fa-file-signature"></i> مدیریت قراردادها</h4>
      <div class="text-muted small">همه‌ی سفارش‌های تأییدشده: قراردادشان ساخته، صادر و با تیکت برای مشتری ارسال شده یا نه.</div>
    </div>
    <?php if (user_can('finance_settings', $user)): ?><a href="admin/admin_aradbranding_ticket.php" class="btn btn-sm btn-outline-dark"><i class="fa-solid fa-sliders"></i> تنظیمات تیکت (متنِ تیکتِ قرارداد)</a><?php endif; ?>
  </div>

  <div class="row g-2 mb-3">
    <div class="col-6 col-md">
      <a href="<?= $qs(['f' => 'todo', 'p' => 1]) ?>" class="card p-3 stat border-dark <?= $f === 'todo' ? 'active' : '' ?>">
        <div class="small text-muted">کارهای مانده</div>
        <div class="fs-3 fw-bold text-dark"><?= to_persian_digits((string) $todoCount) ?></div>
      </a>
    </div>
    <?php foreach ($states as $k => $m): ?>
      <div class="col-6 col-md">
        <a href="<?= $qs(['f' => $k, 'p' => 1]) ?>" class="card p-3 stat border-<?= $m['color'] ?> <?= $f === $k ? 'active' : '' ?>" title="<?= e($m['hint']) ?>">
          <div class="small text-muted"><i class="fa-solid <?= $m['icon'] ?>"></i> <?= e($m['label']) ?></div>
          <div class="fs-3 fw-bold text-<?= $m['color'] ?>"><?= to_persian_digits((string) $counts[$k]) ?></div>
        </a>
      </div>
    <?php endforeach; ?>
    <div class="col-6 col-md">
      <a href="<?= $qs(['f' => 'all', 'p' => 1]) ?>" class="card p-3 stat <?= $f === 'all' ? 'active border-dark' : '' ?>">
        <div class="small text-muted">همه‌ی سفارش‌های تأییدشده</div>
        <div class="fs-3 fw-bold"><?= to_persian_digits((string) count($rows)) ?></div>
      </a>
    </div>
  </div>

  <form method="get" class="d-flex flex-wrap gap-2 align-items-end mb-2">
    <input type="hidden" name="f" value="<?= e($f) ?>"><input type="hidden" name="per" value="<?= $perPage ?>">
    <div><label class="form-label small mb-0">جستجو</label><input name="q" value="<?= e($q) ?>" class="form-control form-control-sm" style="width:260px" placeholder="نام/موبایلِ مشتری، شماره سفارش یا قرارداد"></div>
    <div><label class="form-label small mb-0">تأییدِ سفارش از</label><input name="from" value="<?= e($fromJ) ?>" class="form-control form-control-sm jalali-date" style="width:120px" autocomplete="off"></div>
    <div><label class="form-label small mb-0">تا</label><input name="to" value="<?= e($toJ) ?>" class="form-control form-control-sm jalali-date" style="width:120px" autocomplete="off"></div>
    <button class="btn btn-sm btn-outline-dark">اعمال</button>
    <?php if ($q !== '' || $fromJ !== '' || $toJ !== ''): ?><a href="contracts_manage.php?f=<?= e($f) ?>" class="btn btn-sm btn-link">پاک کردن</a><?php endif; ?>
  </form>
  <?php if (isset($states[$f])): ?><div class="small text-muted mb-2"><i class="fa-solid fa-circle-info"></i> <?= e($states[$f]['hint']) ?></div><?php endif; ?>

  <div class="card p-0"><div class="table-responsive"><table class="table table-sm align-middle small mb-0">
    <thead class="table-light"><tr><th>سفارش</th><th>مشتری</th><th>کارشناس</th><th>تأییدِ سفارش</th><th>قرارداد</th><th>تیکتِ قرارداد</th><th>ارسال/مشاهده</th><th></th></tr></thead>
    <tbody>
    <?php if (!$list): ?><tr><td colspan="8" class="text-center text-muted py-4">موردی نیست.</td></tr><?php endif; ?>
    <?php foreach ($list as $r):
      $m = $states[$r['state']];
      $t = $r['ticket'];
      $si = $r['contract_id'] ? ($sendInfo[(int) $r['contract_id']] ?? null) : null;
      $cst = ctr_statuses()[$r['contract_status'] ?? ''] ?? null; ?>
      <tr>
        <td><a href="order_view.php?id=<?= (int) $r['id'] ?>" target="_blank" class="text-decoration-none"><bdi dir="ltr"><?= e(to_persian_digits((string) $r['order_number'])) ?></bdi></a>
          <div class="text-muted"><?= e(number_format((int) $r['total_amount'])) ?> تومان</div></td>
        <td><a href="customer_view.php?id=<?= (int) $r['customer_id'] ?>" target="_blank" class="fw-bold text-decoration-none"><?= e((string) $r['full_name']) ?></a>
          <div class="text-muted" dir="ltr" style="text-align:right"><?= e((string) $r['mobile']) ?></div></td>
        <td><?= e((string) ($r['seller_name'] ?? '—')) ?></td>
        <td class="text-nowrap"><?= $r['decided_at'] ? to_jalali(substr((string) $r['decided_at'], 0, 10)) : '—' ?></td>
        <td>
          <?php if ($r['contract_id']): ?>
            <a href="contract_view.php?id=<?= (int) $r['contract_id'] ?>" target="_blank" class="text-decoration-none"><bdi dir="ltr"><?= e(to_persian_digits((string) $r['contract_number'])) ?></bdi></a>
            <?php if ($cst): ?><span class="badge text-bg-<?= $cst['color'] ?>"><?= e($cst['label']) ?></span><?php endif; ?>
          <?php else: ?><span class="text-muted">—</span><?php endif; ?>
          <div><span class="badge text-bg-<?= $m['color'] ?>"><i class="fa-solid <?= $m['icon'] ?>"></i> <?= e($m['label']) ?></span></div>
        </td>
        <td>
          <?php if ($t):
            $tm = abt_statuses()[$t['status']] ?? ['label' => $t['status'], 'color' => 'secondary'];
            $tl = abt_ticket_link($t); ?>
            <span class="badge text-bg-<?= $tm['color'] ?>"><?= e($tm['label']) ?></span>
            <?php if ($tl): ?><a href="<?= e($tl) ?>" target="_blank" rel="noopener" class="ms-1"><i class="fa-solid fa-arrow-up-right-from-square"></i> مشاهده‌ی تیکت <?= e((string) $t['external_id']) ?></a><?php endif; ?>
            <?php if ($t['status'] === 'failed' && $t['last_error']): ?><div class="text-danger" style="font-size:11px"><?= e(mb_strimwidth((string) $t['last_error'], 0, 110, '…')) ?></div><?php endif; ?>
            <?php if (!empty($t['sent_at'])): ?><div class="text-muted"><?= to_jalali(substr((string) $t['sent_at'], 0, 10)) ?></div><?php endif; ?>
          <?php else: ?><span class="text-muted">—</span><?php endif; ?>
        </td>
        <td class="text-nowrap">
          <?php if ($si): ?>
            <?= to_persian_digits((string) (int) $si['n']) ?> ارسال
            <div class="text-muted"><?= to_jalali(substr((string) $si['last_at'], 0, 10)) ?></div>
            <?php if ((int) $si['viewed'] > 0): ?><span class="badge text-bg-primary">مشتری دید</span><?php endif; ?>
          <?php else: ?><span class="text-muted">—</span><?php endif; ?>
        </td>
        <td class="text-nowrap">
          <?php if ($r['state'] === 'no_contract'): ?>
            <?php if ($canCreate): ?>
              <form method="post" action="contract_view.php" class="d-inline" onsubmit="return confirm('برای سفارشِ <?= e((string) $r['order_number']) ?> قرارداد ساخته شود؟');">
                <?= csrf_field() ?><input type="hidden" name="action" value="create"><input type="hidden" name="quote_id" value="<?= (int) $r['quote_id'] ?>">
                <button class="btn btn-sm btn-danger"><i class="fa-solid fa-file-circle-plus"></i> ساختِ قرارداد</button>
              </form>
            <?php endif; ?>
          <?php elseif ($r['state'] === 'draft'): ?>
            <a href="contract_view.php?id=<?= (int) $r['contract_id'] ?>#edit-panel" target="_blank" class="btn btn-sm btn-warning"><i class="fa-solid fa-pen"></i> تکمیل و صدور</a>
          <?php elseif ($r['state'] === 'sent'): ?>
            <a href="contract_view.php?id=<?= (int) $r['contract_id'] ?>#send-log" target="_blank" class="btn btn-sm btn-outline-success"><i class="fa-solid fa-eye"></i> قرارداد</a>
          <?php else: ?>
            <a href="contract_view.php?id=<?= (int) $r['contract_id'] ?>#send-panel" target="_blank" class="btn btn-sm btn-primary"><i class="fa-solid fa-paper-plane"></i> ارسال با تیکت</a>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody></table></div></div>

  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-3">
    <div class="small text-muted"><?= to_persian_digits((string) $total) ?> مورد — نمایش در هر صفحه:
      <?php foreach ([25, 50, 100, 200] as $pp): ?><a class="chip <?= $pp === $perPage ? 'active' : '' ?>" href="<?= $qs(['per' => $pp, 'p' => 1]) ?>"><?= to_persian_digits((string) $pp) ?></a> <?php endforeach; ?>
    </div>
    <?php if ($pages > 1): ?>
      <nav><ul class="pagination pagination-sm mb-0 flex-wrap">
        <?php for ($i = max(1, $pageNo - 4); $i <= min($pages, $pageNo + 4); $i++): ?>
          <li class="page-item <?= $i === $pageNo ? 'active' : '' ?>"><a class="page-link" href="<?= $qs(['p' => $i]) ?>"><?= to_persian_digits((string) $i) ?></a></li>
        <?php endfor; ?>
      </ul></nav>
    <?php endif; ?>
  </div>
</div>
<?php require_once __DIR__ . '/includes/layout_bottom.php'; ?>
