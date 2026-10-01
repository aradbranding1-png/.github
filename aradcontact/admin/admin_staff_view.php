<?php
require_once __DIR__ . '/../includes/auth.php';
$admin = require_admin();
$pdo   = db();

$staffId = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
$stmt->execute([$staffId]);
$staff = $stmt->fetch();
if (!$staff || !actor_can_see_user(current_user() ?? [], $staff)) {
    http_response_code(404);
    die('کاربر یافت نشد.');
}

$isSuperAdmin = is_super_admin($admin);

// حذف مشتری (تکی یا دسته‌جمعی) — فقط ادمین کل، فقط از بین مشتریان همین کارشناس
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isSuperAdmin) {
    $backTo = 'admin_staff_view.php?id=' . $staffId . (($qs = trim((string) ($_GET['page'] ?? ''))) !== '' ? '&page=' . (int) $qs : '');
    if (!csrf_verify()) {
        flash_set('danger', 'نشست شما منقضی شده است، دوباره تلاش کنید.');
        redirect($backTo);
    }
    if (isset($_POST['delete_customer_ids'])) {
        $ids = array_values(array_filter(array_map('intval', (array) $_POST['delete_customer_ids'])));
        if ($ids) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $params = array_merge($ids, [$staffId]);
            $delStmt = $pdo->prepare("DELETE FROM customers WHERE id IN ($placeholders) AND owner_user_id = ?");
            $delStmt->execute($params);
            flash_set('success', to_persian_digits((string) $delStmt->rowCount()) . ' مشتری حذف شد.');
        }
        redirect($backTo);
    }
    if (isset($_POST['delete_all_customers'])) {
        $delAllStmt = $pdo->prepare('DELETE FROM customers WHERE owner_user_id = ?');
        $delAllStmt->execute([$staffId]);
        flash_set('success', to_persian_digits((string) $delAllStmt->rowCount()) . ' مشتری (همه مشتریان این کارشناس) حذف شد.');
        redirect('admin_staff_view.php?id=' . $staffId);
    }
}

// بازگرداندن مشتریان ارجاع‌گرفته‌شده به ارجاع‌دهنده‌ی اصلی — برای وقتی کارشناس ترک می‌کند و
// مشتری‌هایی که فقط به‌طور موقت (از طریق ارجاع) دستش بوده، نباید برای همیشه در باکسش بمانند.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && can_manage_service_requests($admin) && isset($_POST['return_referred_customers'])) {
    if (!csrf_verify()) {
        flash_set('danger', 'نشست شما منقضی شده است، دوباره تلاش کنید.');
        redirect('admin_staff_view.php?id=' . $staffId);
    }
    // برای هر مشتری که الان دستِ این کارشناس است، آخرین ارجاعی که به همین کارشناس خورده رو پیدا کن
    // (یعنی همونی که الان مالکیتش رو تعیین کرده)، و اگه واقعاً از طریق ارجاع دریافتش کرده
    // (نه اینکه خودش از اول ثبتش کرده بود)، به همون ارجاع‌دهنده‌ی اصلی برش‌گردون.
    $stmt = $pdo->prepare("
        SELECT c.id AS customer_id, r.from_user_id
        FROM customers c
        JOIN customer_referrals r ON r.customer_id = c.id AND r.to_user_id = c.owner_user_id
        WHERE c.owner_user_id = ?
        AND r.id = (SELECT MAX(r2.id) FROM customer_referrals r2 WHERE r2.customer_id = c.id)
    ");
    $stmt->execute([$staffId]);
    $toReturn = $stmt->fetchAll();

    $returnedCount = 0;
    foreach ($toReturn as $row) {
        try {
            admin_reassign_customer_silently($pdo, (int) $row['customer_id'], (int) $row['from_user_id'], (int) $admin['id']);
            $returnedCount++;
        } catch (Throwable $e) {
            // اگر یک مورد به هر دلیلی خطا داد، بقیه رو متوقف نکن
        }
    }
    flash_set('success', to_persian_digits((string) $returnedCount) . ' مشتری به ارجاع‌دهنده‌ی اصلی‌شان بازگردانده شد.');
    redirect('admin_staff_view.php?id=' . $staffId);
}

$statusOptions    = status_options_for_role($staff['role']);
$successStatuses  = success_statuses_for_role($staff['role']);

$stmt = $pdo->prepare('SELECT COUNT(*) c FROM customers WHERE owner_user_id = ?');
$stmt->execute([$staffId]);
$total = (int) $stmt->fetch()['c'];

$stmt = $pdo->prepare('SELECT status, COUNT(*) c FROM customers WHERE owner_user_id = ? GROUP BY status');
$stmt->execute([$staffId]);
$statusCounts = [];
foreach ($stmt->fetchAll() as $row) {
    $statusCounts[$row['status']] = (int) $row['c'];
}

// چند تا از مشتری‌های الانِ این کارشناس، در واقع از طریق ارجاع بهش رسیده‌اند (نه ثبت خودش)؟
$referredCustomerCount = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM customers c
        JOIN customer_referrals r ON r.customer_id = c.id AND r.to_user_id = c.owner_user_id
        WHERE c.owner_user_id = ?
        AND r.id = (SELECT MAX(r2.id) FROM customer_referrals r2 WHERE r2.customer_id = c.id)
    ");
    $stmt->execute([$staffId]);
    $referredCustomerCount = (int) $stmt->fetchColumn();
} catch (Throwable $e) {
    $referredCustomerCount = 0;
}

$stmt = $pdo->prepare('SELECT COUNT(*) c FROM followups WHERE created_by = ?');
$stmt->execute([$staffId]);
$totalFollowups = (int) $stmt->fetch()['c'];
$avgFollowups = $total > 0 ? round($totalFollowups / $total, 1) : 0;

$successCount = 0;
foreach ($successStatuses as $s) {
    $successCount += $statusCounts[$s] ?? 0;
}
$successRate = $total > 0 ? round($successCount * 100 / $total, 1) : 0;

$stmt = $pdo->prepare('SELECT COUNT(*) c FROM customers WHERE owner_user_id = ? AND next_followup_date < CURDATE()');
$stmt->execute([$staffId]);
$overdue = (int) $stmt->fetch()['c'];
$stmt = $pdo->prepare('SELECT COUNT(*) c FROM customers WHERE owner_user_id = ? AND next_followup_date = CURDATE()');
$stmt->execute([$staffId]);
$dueToday = (int) $stmt->fetch()['c'];

// لیست مشتریان با صفحه‌بندی؛ محدودیت قدیمی ۲۰۰ رکورد حذف شد تا مدیر بتواند همه مشتریان کارشناس را ببیند.
$customerPage = max(1, (int) ($_GET['page'] ?? 1));
$customersPerPage = 50;
$countCustomersStmt = $pdo->prepare('SELECT COUNT(*) FROM customers WHERE owner_user_id = ?');
$countCustomersStmt->execute([$staffId]);
$totalCustomersRows = (int) $countCustomersStmt->fetchColumn();
$totalCustomerPages = max(1, (int) ceil($totalCustomersRows / $customersPerPage));
if ($customerPage > $totalCustomerPages) {
    $customerPage = $totalCustomerPages;
}
$customerOffset = ($customerPage - 1) * $customersPerPage;

$stmt = $pdo->prepare("SELECT * FROM customers
    WHERE owner_user_id = ?
    ORDER BY (next_followup_date IS NULL), next_followup_date ASC, id ASC
    LIMIT $customersPerPage OFFSET $customerOffset");
$stmt->execute([$staffId]);
$customers = $stmt->fetchAll();

$pageTitle = 'جزئیات کارشناس - ' . $staff['full_name'];

// مدت مکالمه به تفکیک نوع مخاطب برای همین کارشناس — دقیقاً همان چیزی که خودش در «گزارش‌های من» می‌بیند
$staffContactTypeRanges = [
    'daily'   => [date('Y-m-d'), date('Y-m-d')],
    'monthly' => [date('Y-m-01'), date('Y-m-d')],
    'yearly'  => [date('Y-01-01'), date('Y-m-d')],
];
$staffCallByContactType = [];
foreach ($staffContactTypeRanges as $rk => [$from, $to]) {
    $staffCallByContactType[$rk] = fetch_call_duration_by_contact_type($pdo, (int) $staff['id'], $from, $to);
}
require_once __DIR__ . '/../includes/layout_top.php';
?>

<div class="admin-staff-view">
<div class="d-flex justify-content-between align-items-center mb-4">
  <h5 class="mb-0"><i class="fa-solid fa-id-badge text-primary"></i> <?= e($staff['full_name']) ?>
    <span class="badge bg-secondary"><?= e(role_label($staff['role'])) ?></span>
  </h5>
  <a href="admin_dashboard.php" class="btn btn-sm btn-outline-secondary">بازگشت</a>
</div>

<div class="row g-3 mb-4">
  <div class="col-6 col-md-3"><div class="stat-card bg-grad-blue"><div class="stat-number"><?= $total ?></div><div class="stat-label">کل مشتریان</div></div></div>
  <div class="col-6 col-md-3"><div class="stat-card bg-grad-teal"><div class="stat-number"><?= $totalFollowups ?></div><div class="stat-label">کل پیگیری‌ها</div></div></div>
  <div class="col-6 col-md-3"><div class="stat-card bg-grad-green"><div class="stat-number"><?= $successRate ?>%</div><div class="stat-label">نرخ موفقیت</div></div></div>
  <div class="col-6 col-md-3"><div class="stat-card bg-grad-orange"><div class="stat-number"><?= $avgFollowups ?></div><div class="stat-label">میانگین پیگیری</div></div></div>
</div>

<?php if ($referredCustomerCount > 0 && can_manage_service_requests($admin)): ?>
<div class="alert alert-warning d-flex justify-content-between align-items-center flex-wrap gap-2 compact-text mb-4">
  <div>
    <i class="fa-solid fa-share-from-square"></i>
    <?= to_persian_digits((string) $referredCustomerCount) ?> مشتری از این <?= $referredCustomerCount ?> نفر، از طریق ارجاع به این کارشناس رسیده (نه ثبت خودش). اگه این کارشناس داره از شرکت می‌ره یا نمی‌خواید این مشتری‌ها دستش بمونه،
    می‌تونید همه‌شون رو یکجا به ارجاع‌دهنده‌ی اصلی‌شان برگردونید.
  </div>
  <form method="post" onsubmit="return confirm('این عمل، همه‌ی مشتریان ارجاع‌گرفته‌شده‌ی این کارشناس رو به ارجاع‌دهنده‌ی اصلی‌شون برمی‌گردونه. مطمئنید؟');">
    <?= csrf_field() ?>
    <input type="hidden" name="return_referred_customers" value="1">
    <button type="submit" class="btn btn-sm btn-warning text-nowrap"><i class="fa-solid fa-rotate-left"></i> بازگرداندن به ارجاع‌دهنده‌ی اصلی</button>
  </form>
</div>
<?php endif; ?>

<div class="row g-3 mb-4">
  <div class="col-md-6">
    <div class="card p-3">
      <h6 class="mb-3">تفکیک بر اساس وضعیت</h6>
      <?php foreach ($statusOptions as $st): $c = $statusCounts[$st] ?? 0; $pct = $total>0 ? round($c*100/$total) : 0; ?>
        <div class="d-flex justify-content-between mb-2">
          <span><?= e($st) ?></span>
          <span class="badge bg-primary"><?= $c ?> (<?= $pct ?>٪)</span>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="col-md-6">
    <div class="card p-3">
      <h6 class="mb-3">سررسیدها</h6>
      <p class="mb-1">امروز: <b class="text-warning"><?= $dueToday ?></b></p>
      <p class="mb-0">عقب‌افتاده: <b class="text-danger"><?= $overdue ?></b></p>
    </div>
  </div>
</div>

<div class="card p-3 mb-3">
  <div class="daily-section-header">
    <h6 class="mb-0 fw-bold"><i class="fa-solid fa-users-viewfinder text-primary"></i> مدت مکالمه به تفکیک نوع مخاطب</h6>
  </div>
  <ul class="nav nav-tabs mb-3" role="tablist">
    <?php foreach (['daily' => 'روزانه', 'monthly' => 'ماهانه', 'yearly' => 'سالانه'] as $rk => $rl): ?>
      <li class="nav-item" role="presentation">
        <button class="nav-link <?= $rk === 'daily' ? 'active' : '' ?>" data-bs-toggle="tab" data-bs-target="#staffContactType<?= $rk ?>" type="button"><?= e($rl) ?></button>
      </li>
    <?php endforeach; ?>
  </ul>
  <div class="tab-content">
    <?php foreach (['daily', 'monthly', 'yearly'] as $rk): $ct = $staffCallByContactType[$rk]; ?>
      <div class="tab-pane fade <?= $rk === 'daily' ? 'show active' : '' ?>" id="staffContactType<?= $rk ?>" role="tabpanel">
        <div class="row g-2 text-center">
          <div class="col-4">
            <div class="glance-box">
              <div class="glance-num"><?= format_duration_seconds($ct['customer']['duration']) ?></div>
              <div class="glance-label">با مشتری (<?= to_persian_digits((string) $ct['customer']['count']) ?> تماس)</div>
            </div>
          </div>
          <div class="col-4">
            <div class="glance-box">
              <div class="glance-num"><?= format_duration_seconds($ct['colleague']['duration']) ?></div>
              <div class="glance-label">با همکار (<?= to_persian_digits((string) $ct['colleague']['count']) ?> تماس)</div>
            </div>
          </div>
          <div class="col-4">
            <div class="glance-box">
              <div class="glance-num"><?= format_duration_seconds($ct['family']['duration']) ?></div>
              <div class="glance-label">با خانواده (<?= to_persian_digits((string) $ct['family']['count']) ?> تماس)</div>
            </div>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<div class="card p-3">
  <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h6 class="mb-0">لیست مشتریان این کارشناس</h6>
    <?php if ($isSuperAdmin && $totalCustomersRows > 0): ?>
      <form method="post" onsubmit="return confirm('همه <?= to_persian_digits((string) $totalCustomersRows) ?> مشتری این کارشناس (نه فقط همین صفحه) برای همیشه حذف شوند؟ این عمل قابل بازگشت نیست.');">
        <?= csrf_field() ?>
        <input type="hidden" name="delete_all_customers" value="1">
        <button type="submit" class="btn btn-sm btn-outline-danger">
          <i class="fa-solid fa-trash-can"></i> حذف همه <?= to_persian_digits((string) $totalCustomersRows) ?> مشتری این کارشناس
        </button>
      </form>
    <?php endif; ?>
  </div>
  <?php if ($isSuperAdmin): ?>
    <form method="post" id="staffBulkDeleteForm" onsubmit="return confirm('مشتری(های) انتخاب‌شده برای همیشه حذف می‌شوند. ادامه می‌دهید؟');">
      <?= csrf_field() ?>
      <div class="d-flex align-items-center gap-2 mb-2">
        <button type="submit" class="btn btn-sm btn-outline-danger" id="staffBulkDeleteBtn" disabled>
          <i class="fa-solid fa-trash"></i> حذف انتخاب‌شده‌ها
        </button>
        <span class="text-muted small" id="staffBulkSelectedCount"></span>
      </div>
  <?php endif; ?>
  <div class="table-responsive">
    <table class="table table-hover table-compact align-middle mb-0">
      <thead><tr>
        <?php if ($isSuperAdmin): ?><th style="width:2rem"><input type="checkbox" id="staffSelectAllRows" class="form-check-input"></th><?php endif; ?>
        <th>نام</th><th>موبایل</th><th>وضعیت</th><th>سررسید بعدی</th><th>تعداد پیگیری</th><th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($customers as $c):
          $today = date('Y-m-d');
          $rowClass = $c['next_followup_date'] === null ? '' : ($c['next_followup_date'] < $today ? 'table-row-overdue' : ($c['next_followup_date'] === $today ? 'table-row-today' : ''));
      ?>
        <tr class="<?= $rowClass ?>">
          <?php if ($isSuperAdmin): ?>
            <td><input type="checkbox" name="delete_customer_ids[]" value="<?= (int) $c['id'] ?>" class="form-check-input staff-row-checkbox"></td>
          <?php endif; ?>
          <td><?= e($c['full_name']) ?></td>
          <td dir="ltr"><?= e($c['mobile']) ?></td>
          <td><span class="badge badge-status <?= status_badge_class($c['status']) ?>"><?= e($c['status']) ?></span></td>
          <td><?= to_jalali($c['next_followup_date']) ?></td>
          <td><?= to_persian_digits((string) (int) $c['followup_count']) ?></td>
          <td class="d-flex gap-1">
            <a href="../customer_view.php?id=<?= (int)$c['id'] ?>" class="btn btn-sm btn-primary" title="مشاهده"><i class="fa-solid fa-eye"></i></a>
            <?php if ($isSuperAdmin): ?>
              <button type="submit" form="staffSingleDeleteForm_<?= (int) $c['id'] ?>" class="btn btn-sm btn-outline-danger" title="حذف این مشتری"><i class="fa-solid fa-trash"></i></button>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($isSuperAdmin): ?>
    </form>
    <?php foreach ($customers as $c): ?>
      <form method="post" id="staffSingleDeleteForm_<?= (int) $c['id'] ?>" style="display:none" onsubmit="return confirm('مشتری «<?= e(addslashes($c['full_name'])) ?>» برای همیشه حذف شود؟');">
        <?= csrf_field() ?>
        <input type="hidden" name="delete_customer_ids[]" value="<?= (int) $c['id'] ?>">
      </form>
    <?php endforeach; ?>
  <?php endif; ?>

  <?php if ($totalCustomerPages > 1): ?>
    <nav class="mt-3" aria-label="صفحه‌بندی مشتریان کارشناس">
      <ul class="pagination pagination-sm justify-content-center flex-wrap mb-0">
        <?php if ($customerPage > 1): ?>
          <li class="page-item"><a class="page-link" href="?id=<?= $staffId ?>&page=<?= $customerPage-1 ?>">قبلی</a></li>
        <?php endif; ?>
        <?php
          $cpStart = max(1, $customerPage - 2);
          $cpEnd = min($totalCustomerPages, $customerPage + 2);
          for ($cp = $cpStart; $cp <= $cpEnd; $cp++):
        ?>
          <li class="page-item <?= $cp === $customerPage ? 'active' : '' ?>">
            <a class="page-link" href="?id=<?= $staffId ?>&page=<?= $cp ?>"><?= to_persian_digits((string)$cp) ?></a>
          </li>
        <?php endfor; ?>
        <?php if ($customerPage < $totalCustomerPages): ?>
          <li class="page-item"><a class="page-link" href="?id=<?= $staffId ?>&page=<?= $customerPage+1 ?>">بعدی</a></li>
        <?php endif; ?>
      </ul>
    </nav>
    <div class="text-muted small text-center mt-2">
      صفحه <?= to_persian_digits((string)$customerPage) ?> از <?= to_persian_digits((string)$totalCustomerPages) ?>
      · مجموع <?= to_persian_digits((string)$totalCustomersRows) ?> مشتری
    </div>
  <?php endif; ?>
</div>

</div>

<?php if ($isSuperAdmin): ?>
<script>
(function () {
  var selectAll = document.getElementById('staffSelectAllRows');
  var checkboxes = document.querySelectorAll('.staff-row-checkbox');
  var deleteBtn = document.getElementById('staffBulkDeleteBtn');
  var countLabel = document.getElementById('staffBulkSelectedCount');

  function updateState() {
    var checkedCount = document.querySelectorAll('.staff-row-checkbox:checked').length;
    if (deleteBtn) deleteBtn.disabled = checkedCount === 0;
    if (countLabel) countLabel.textContent = checkedCount > 0 ? checkedCount + ' مورد انتخاب شده' : '';
  }

  if (selectAll) {
    selectAll.addEventListener('change', function () {
      checkboxes.forEach(function (cb) { cb.checked = selectAll.checked; });
      updateState();
    });
  }
  checkboxes.forEach(function (cb) { cb.addEventListener('change', updateState); });
  updateState();
})();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/layout_bottom.php'; ?>
