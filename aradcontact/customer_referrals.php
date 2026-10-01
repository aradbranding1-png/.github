<?php
require_once __DIR__ . '/includes/auth.php';
$user = require_login();
$pdo = db();

$referralTableAvailable = false;
try {
    $chk = $pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_referrals'");
    $referralTableAvailable = (bool) $chk->fetchColumn();
} catch (Throwable $e) {
    $referralTableAvailable = false;
}

$isAdmin = $user['role'] === 'admin';
$search = trim((string) ($_GET['q'] ?? ''));
$direction = trim((string) ($_GET['direction'] ?? ($isAdmin ? 'all' : 'outbound')));
$validDirections = $isAdmin ? ['outbound', 'inbound', 'all'] : ['outbound', 'inbound'];
if (!in_array($direction, $validDirections, true)) {
    $direction = $isAdmin ? 'all' : 'outbound';
}

$where = [];
$params = [];

if ($isAdmin) {
    if ($direction === 'outbound') {
        $where[] = 'r.from_user_id = ?';
        $params[] = (int) $user['id'];
    } elseif ($direction === 'inbound') {
        $where[] = 'r.to_user_id = ?';
        $params[] = (int) $user['id'];
    }
    // direction=all یعنی همه ارجاع‌های سیستم، بدون فیلتر اضافه
} else {
    if ($direction === 'outbound') {
        $where[] = 'r.from_user_id = ?';
        $params[] = (int) $user['id'];
    } else {
        $where[] = 'r.to_user_id = ?';
        $params[] = (int) $user['id'];
    }
}

if ($search !== '') {
    $where[] = '(c.full_name LIKE ? OR c.mobile LIKE ? OR c.mobile_2 LIKE ? OR from_u.full_name LIKE ? OR to_u.full_name LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like, $like);
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$PER_PAGE = 25;
$page = max(1, (int) ($_GET['page'] ?? 1));

$totalCount = 0;
if ($referralTableAvailable) {
    $countSql = "SELECT COUNT(*) FROM customer_referrals r
                 JOIN customers c ON c.id = r.customer_id
                 JOIN users from_u ON from_u.id = r.from_user_id
                 JOIN users to_u ON to_u.id = r.to_user_id
                 $whereSql";
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($params);
    $totalCount = (int) $countStmt->fetchColumn();
}
$totalPages = max(1, (int) ceil($totalCount / $PER_PAGE));
$page = min($page, $totalPages);
$offset = ($page - 1) * $PER_PAGE;

$sql = "SELECT r.*, c.full_name AS customer_name, c.mobile, c.mobile_2, c.status AS customer_status,
               from_u.full_name AS from_user_name,
               to_u.full_name AS to_user_name,
               by_u.full_name AS referred_by_name
        FROM customer_referrals r
        JOIN customers c ON c.id = r.customer_id
        JOIN users from_u ON from_u.id = r.from_user_id
        JOIN users to_u ON to_u.id = r.to_user_id
        JOIN users by_u ON by_u.id = r.referred_by
        $whereSql
        ORDER BY r.created_at DESC, r.id DESC
        LIMIT $PER_PAGE OFFSET $offset";
$referrals = [];
if ($referralTableAvailable) {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $referrals = $stmt->fetchAll();
}

function __referral_page_link(int $p): string
{
    $params = $_GET;
    $params['page'] = $p;
    return 'customer_referrals.php?' . http_build_query($params);
}

// وقتی کارشناس وارد لیست «دریافتی‌ها»ی خودش می‌شود، یعنی دیدشان — پس خوانده‌شده علامت بزن
if ($referralTableAvailable && $direction === 'inbound' && !$isAdmin) {
    try {
        $pdo->prepare('UPDATE customer_referrals SET is_read = 1 WHERE to_user_id = ? AND is_read = 0')->execute([$user['id']]);
    } catch (Throwable $e) {
        // اگر مایگریشن is_read هنوز اجرا نشده باشد، این نباید مانع نمایش صفحه شود.
    }
}

$pageTitle = 'تاریخچه ارجاع مشتریان';
$bodyClass = 'referral-history-page';
require_once __DIR__ . '/includes/layout_top.php';
?>

<style>
.cr-page{--cr-line:#e7e2d3;--cr-ink:#1c1917;--cr-muted:#78716c;--cr-gold:#c9a24b;--cr-gold-2:#f1dfa8;}

/* ---------- header card ---------- */
.cr-page .cr-head-card{
  border:1px solid var(--cr-line);border-radius:22px;box-shadow:0 4px 24px -16px rgba(28,25,23,.3);
}
.cr-page .cr-head-top h5{
  display:flex;align-items:center;gap:.55rem;font-weight:800;color:var(--cr-ink);margin:0;font-size:1.12rem;
}
.cr-page .cr-head-top h5 i{
  width:36px;height:36px;border-radius:11px;display:inline-flex;align-items:center;justify-content:center;
  background:linear-gradient(135deg,#0b0f1a 0%,#3d3220 55%,var(--cr-gold) 130%);color:#fff;font-size:.9rem;
}
.cr-page .cr-head-top .text-muted{color:var(--cr-muted) !important;font-size:.78rem}
.cr-page .cr-head-top .btn-outline-secondary{border-radius:12px;font-weight:700}

/* ---------- filter row ---------- */
.cr-page #referralFilter{background:#faf9f5;border:1px dashed var(--cr-line);border-radius:14px;padding:.9rem 1rem;}
.cr-page #referralFilter .form-label{font-size:.74rem;font-weight:700;color:#57534e}
.cr-page #referralFilter .form-control,
.cr-page #referralFilter .form-select{
  border:1px solid var(--cr-line);border-radius:10px;background:#fff;
}
.cr-page #referralFilter .form-control:focus,
.cr-page #referralFilter .form-select:focus{border-color:var(--cr-gold);box-shadow:0 0 0 4px rgba(201,162,75,.15)}
.cr-page #referralFilter .btn-primary{
  border:none;border-radius:10px;font-weight:700;background:linear-gradient(135deg,var(--cr-gold-2),var(--cr-gold));color:#241d0a;
  box-shadow:0 6px 14px -6px rgba(201,162,75,.6);
}
.cr-page #referralFilter .badge{
  border-radius:999px !important;font-weight:700;background:linear-gradient(135deg,var(--cr-gold-2),var(--cr-gold)) !important;
  color:#241d0a !important;border-color:transparent !important;
}

/* ---------- results card ---------- */
.cr-page .cr-results-card{
  border:1px solid var(--cr-line);border-radius:22px;box-shadow:0 4px 24px -16px rgba(28,25,23,.3);
}
.cr-page .cr-results-card .alert{border-radius:16px}

/* ---------- table ---------- */
.cr-page .referral-history-table{font-size:.85rem}
.cr-page .referral-history-table thead th{
  background:#faf9f5;color:#78716c;font-weight:700;font-size:.74rem;border-bottom:1px solid var(--cr-line);white-space:nowrap;
}
.cr-page .referral-history-table tbody td{border-bottom:1px solid #f3f1ea;vertical-align:middle}
.cr-page .referral-history-table tbody tr:hover{background:#faf8f2}
.cr-page .referral-history-table a.fw-bold{color:var(--cr-ink)}
.cr-page .referral-history-table a.fw-bold:hover{color:#8a6a1e}
.cr-page .badge-status{border-radius:999px;padding:.35rem .8rem;font-weight:700;font-size:.72rem}
.cr-page .referral-history-table .btn-outline-primary{
  border-radius:10px;width:34px;height:34px;padding:0;display:inline-flex;align-items:center;justify-content:center;
}
.cr-page .referral-history-table .btn-outline-primary:hover{
  background:linear-gradient(135deg,var(--cr-gold-2),var(--cr-gold));border-color:transparent;color:#241d0a;
}

/* ---------- empty state ---------- */
.cr-page .cr-empty{text-align:center;padding:3rem 1rem;color:#a8a29e}
.cr-page .cr-empty i{font-size:2rem;color:#d6d3d1;margin-bottom:.6rem;display:block}

/* ---------- pagination ---------- */
.cr-page .pagination .page-link{
  border:1px solid var(--cr-line);color:var(--cr-ink);border-radius:10px !important;margin:0 .15rem;font-weight:600;
}
.cr-page .pagination .page-item.active .page-link{
  background:linear-gradient(135deg,var(--cr-gold-2),var(--cr-gold));border-color:transparent;color:#241d0a;
}
.cr-page .pagination .page-item.disabled .page-link{color:#d6d3d1;background:#faf9f5}

/* ---------- mobile ---------- */
@media (max-width:767.98px){
  .cr-page .cr-head-card, .cr-page .cr-results-card{border-radius:16px}
  .cr-page #referralFilter .row > div{margin-bottom:.4rem}
  .cr-page .referral-history-table{font-size:.8rem}
}
</style>

<div class="cr-page">

<div class="card cr-head-card p-4 mb-3 compact-text">
  <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap mb-3 cr-head-top">
    <div>
      <h5 class="mb-1"><i class="fa-solid fa-share-from-square"></i> تاریخچه ارجاع مشتریان</h5>
      <div class="text-muted small">مشتری‌هایی که از شما به کارشناس دیگری منتقل شده‌اند، حتی بعد از خروج از باکس شما، اینجا قابل پیگیری هستند.</div>
    </div>
    <a href="customer_list.php" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-arrow-right"></i> بازگشت به پیگیری مشتریان</a>
  </div>

  <form method="get" id="referralFilter">
  <div class="row g-2 align-items-end">
    <div class="col-md-3">
      <label class="form-label small">نمایش</label>
      <select name="direction" class="form-select" autocomplete="off" onchange="this.form.submit()">
        <?php if ($isAdmin): ?>
          <option value="all" <?= $direction === 'all' ? 'selected' : '' ?>>همه ارجاع‌های سیستم</option>
        <?php endif; ?>
        <option value="outbound" <?= $direction === 'outbound' ? 'selected' : '' ?>>ارجاع‌های من</option>
        <option value="inbound" <?= $direction === 'inbound' ? 'selected' : '' ?>>ارجاع‌های دریافتی من</option>
      </select>
    </div>
    <div class="col-md-6 d-flex gap-2">
        <input type="text" name="q" class="form-control" value="<?= e($search) ?>" placeholder="جستجو بر اساس نام مشتری، شماره یا نام کارشناس">
        <button class="btn btn-primary px-4"><i class="fa-solid fa-magnifying-glass"></i> جستجو</button>
    </div>
    <div class="col-md-3 text-md-end">
      <span class="badge p-2">تعداد: <?= to_persian_digits((string) $totalCount) ?><?= $totalPages > 1 ? ' — صفحه ' . to_persian_digits((string) $page) . ' از ' . to_persian_digits((string) $totalPages) : '' ?></span>
    </div>
  </div>
  </form>
</div>

<div class="card cr-results-card p-3 compact-text">
  <?php if (!$referralTableAvailable): ?>
    <div class="alert alert-warning mb-0">
      <i class="fa-solid fa-database"></i> قابلیت تاریخچه ارجاع هنوز روی دیتابیس فعال نشده است. از «بروزرسانی سیستم» مایگریشن مربوط به ارجاع مشتریان را اجرا کنید.
    </div>
  <?php elseif (!$referrals): ?>
    <div class="cr-empty">
      <i class="fa-regular fa-folder-open"></i>
      <div>موردی برای نمایش پیدا نشد.</div>
    </div>
  <?php else: ?>
  <div class="table-responsive referral-history-table-wrap">
    <table class="table table-hover align-middle mb-0 referral-history-table">
      <?php
        // در نمایش موبایلی، وقتی داریم «ارجاع‌های من» رو می‌بینیم (outbound)، ستون «ارجاع‌دهنده»
        // همیشه خودِ کاربره (تکراری/بی‌فایده)، پس به‌جاش «ارجاع‌گیرنده» نشون داده می‌شه که واقعاً جدیده.
        $showFromOnMobile = ($direction !== 'outbound');
      ?>
      <thead class="table-light">
        <tr>
          <th>مشتری</th>
          <th class="<?= $showFromOnMobile ? '' : 'd-none d-md-table-cell' ?>">ارجاع‌دهنده</th>
          <th class="<?= $showFromOnMobile ? 'd-none d-md-table-cell' : '' ?>">ارجاع‌گیرنده</th>
          <th class="d-none d-md-table-cell">تاریخ ارجاع</th>
          <th class="d-none d-md-table-cell">وضعیت فعلی</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($referrals as $r): ?>
        <tr>
          <td class="text-start">
            <a href="customer_view.php?id=<?= (int) $r['customer_id'] ?>&amp;from=customer_referrals.php" class="fw-bold text-decoration-none"><?= e($r['customer_name']) ?></a>
          </td>
          <td class="<?= $showFromOnMobile ? '' : 'd-none d-md-table-cell' ?> text-start"><?= e($r['from_user_name']) ?></td>
          <td class="<?= $showFromOnMobile ? 'd-none d-md-table-cell' : '' ?> text-start"><?= e($r['to_user_name']) ?></td>
          <td class="d-none d-md-table-cell text-start">
            <?= to_jalali(substr((string) $r['created_at'], 0, 10)) ?>
          </td>
          <td class="d-none d-md-table-cell text-start"><span class="badge badge-status <?= status_badge_class($r['customer_status']) ?>"><?= e($r['customer_status']) ?></span></td>
          <td class="text-center"><a href="customer_view.php?id=<?= (int) $r['customer_id'] ?>&amp;from=customer_referrals.php" class="btn btn-sm btn-outline-primary" title="مشاهده پرونده"><i class="fa-solid fa-eye"></i></a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($totalPages > 1): ?>
  <nav class="mt-3">
    <ul class="pagination pagination-sm justify-content-center mb-0">
      <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
        <a class="page-link" href="<?= e(__referral_page_link(max(1, $page - 1))) ?>">قبلی</a>
      </li>
      <?php
      $rangeStart = max(1, $page - 2);
      $rangeEnd   = min($totalPages, $page + 2);
      if ($rangeStart > 1): ?>
        <li class="page-item"><a class="page-link" href="<?= e(__referral_page_link(1)) ?>">۱</a></li>
        <?php if ($rangeStart > 2): ?><li class="page-item disabled"><span class="page-link">...</span></li><?php endif; ?>
      <?php endif; ?>
      <?php for ($p = $rangeStart; $p <= $rangeEnd; $p++): ?>
        <li class="page-item <?= $p === $page ? 'active' : '' ?>">
          <a class="page-link" href="<?= e(__referral_page_link($p)) ?>"><?= to_persian_digits((string) $p) ?></a>
        </li>
      <?php endfor; ?>
      <?php if ($rangeEnd < $totalPages): ?>
        <?php if ($rangeEnd < $totalPages - 1): ?><li class="page-item disabled"><span class="page-link">...</span></li><?php endif; ?>
        <li class="page-item"><a class="page-link" href="<?= e(__referral_page_link($totalPages)) ?>"><?= to_persian_digits((string) $totalPages) ?></a></li>
      <?php endif; ?>
      <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
        <a class="page-link" href="<?= e(__referral_page_link(min($totalPages, $page + 1))) ?>">بعدی</a>
      </li>
    </ul>
  </nav>
  <?php endif; ?>
  <?php endif; ?>
</div>

</div>

<?php require_once __DIR__ . '/includes/layout_bottom.php'; ?>
