<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/reception_functions.php';
$admin = require_login();
if (!perm_page_allowed($admin)) {
    http_response_code(403);
    die('دسترسی به این بخش ندارید.');
}
$pdo   = db();

$moduleReady = reception_module_ready($pdo);
$meetType = (string) ($_GET['meet'] ?? '');
$__slotsOk = $moduleReady && reception_meeting_slots_ready($pdo);
$__ipOk = $moduleReady && reception_inperson_table_ready($pdo);

$q      = trim((string) ($_GET['q'] ?? ''));
$status = trim((string) ($_GET['status'] ?? ''));
$page   = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 25;

$rows = [];
$totalRows = 0;
$statuses = [];
$totalPages = 1;

if ($moduleReady) {
    $statuses = reception_load_statuses($pdo, false);

    $where = [];
    $params = [];
    if ($q !== '') {
        $qNorm = reception_normalize_mobile($q);
        $where[] = '(ra.first_name LIKE ? OR ra.last_name LIKE ? OR CONCAT(ra.first_name, " ", ra.last_name) LIKE ? OR ra.mobile LIKE ? OR ra.mobile_normalized LIKE ?)';
        $like = '%' . $q . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = '%' . $qNorm . '%';
    }
    if ($status !== '') {
        $where[] = 'ra.status = ?';
        $params[] = $status;
    }
    // باکسِ پذیرش و شهر/نوع آگهی (reception_extras)
    $rxOn = function_exists('rx_ready') && rx_ready($pdo);
    $boxF = $rxOn ? (string) ($_GET['box'] ?? '') : '';
    $freeF = !empty($_GET['free']);
    if ($boxF === 'none') { $where[] = "(ra.intake_box IS NULL OR ra.intake_box = '')"; }
    elseif ($boxF !== '' && isset(rx_intake_boxes()[$boxF])) { $where[] = 'ra.intake_box = ?'; $params[] = $boxF; }
    if ($freeF) { $where[] = 'ra.assigned_agent_id IS NULL'; }
    if ($rxOn && $q !== '') { // جستجو در شهر و نوع آگهی هم
        $where[0] = '(' . substr($where[0], 1, -1) . ' OR ra.city LIKE ? OR ra.ad_type LIKE ? OR ra.lead_source LIKE ?)';
        array_splice($params, 5, 0, ['%' . $q . '%', '%' . $q . '%', '%' . $q . '%']);
    }
    if ($meetType === 'online' && $__slotsOk) {
        $where[] = "EXISTS (SELECT 1 FROM reception_meeting_bookings b WHERE b.applicant_id = ra.id AND b.status = 'booked')";
    } elseif ($meetType === 'inperson' && $__ipOk) {
        $where[] = "EXISTS (SELECT 1 FROM reception_inperson_interviews ii WHERE ii.applicant_id = ra.id AND ii.status <> 'cancelled')";
    }
    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    try {
        // ۱) شمارش کل رکوردها برای محاسبه‌ی دقیق صفحات
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM reception_applicants ra $whereSql");
        $countStmt->execute($params);
        $totalRows = (int) $countStmt->fetchColumn();

        // ۲) محاسبه‌ی تعداد صفحات و مهار کردن صفحه‌ی خارج از محدوده
        $totalPages = $totalRows > 0 ? (int) ceil($totalRows / $perPage) : 1;
        if ($page > $totalPages) {
            $page = $totalPages;
        }
        if ($page < 1) {
            $page = 1;
        }
        $offset = ($page - 1) * $perPage;

        // ۳) واکشی رکوردهای همان صفحه
        $sql = "SELECT ra.*, au.full_name AS agent_name, su.full_name AS supervisor_name,
                       (SELECT MAX(f.created_at) FROM reception_followups f WHERE f.applicant_id = ra.id) AS last_followup_at,
                       (SELECT COUNT(*) FROM reception_calls c WHERE c.applicant_id = ra.id) AS call_count_real,
                       " . ($__slotsOk ? "(SELECT COUNT(*) FROM reception_meeting_bookings b WHERE b.applicant_id = ra.id AND b.status = 'booked')" : '0') . " AS online_cnt,
                       " . ($__ipOk ? "(SELECT COUNT(*) FROM reception_inperson_interviews ii WHERE ii.applicant_id = ra.id AND ii.status <> 'cancelled')" : '0') . " AS inperson_cnt
                FROM reception_applicants ra
                LEFT JOIN users au ON au.id = ra.assigned_agent_id
                LEFT JOIN users su ON su.id = ra.supervisor_user_id
                $whereSql
                ORDER BY ra.created_at DESC
                LIMIT $perPage OFFSET $offset";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $rows = [];
        $totalRows = 0;
        $totalPages = 1;
        $offset = 0;
    }
}

$pageTitle = 'بانک متقاضیان';
require_once __DIR__ . '/../includes/layout_top.php';
?>

<style>
.rab-page{--rab-line:#e7e2d3;--rab-ink:#1c1917;--rab-muted:#78716c;--rab-gold:#c9a24b;--rab-gold-2:#f1dfa8;}
.rab-page .admin-page-header h5{display:flex;align-items:center;gap:.55rem;font-weight:800;color:var(--rab-ink)}
.rab-page .admin-page-header h5 i{
  width:34px;height:34px;border-radius:10px;display:inline-flex;align-items:center;justify-content:center;
  background:linear-gradient(135deg,#0b0f1a 0%,#3d3220 55%,var(--rab-gold) 130%);color:#fff;font-size:.85rem;
}
.rab-page .card{border:1px solid var(--rab-line);border-radius:16px;box-shadow:0 4px 16px -14px rgba(28,25,23,.3)}
.rab-page .btn-primary{
  background:linear-gradient(135deg,var(--rab-gold-2),var(--rab-gold));border:none;color:#241708;font-weight:700;
}
.rab-page .btn-primary:hover{filter:brightness(.97);color:#241708}
.rab-page .btn-outline-secondary{border-color:var(--rab-line);color:var(--rab-ink)}
.rab-page table thead th{
  background:linear-gradient(135deg,#0b0f1a 0%,#3d3220 55%,var(--rab-gold) 130%);color:#f6efdd;border-color:transparent;white-space:nowrap;
}
.rab-page table td{vertical-align:middle;white-space:nowrap;}
.rab-page .badge-status{padding:.35em .7em;border-radius:20px;font-weight:600;font-size:.75rem}
.rab-page .rab-op-btn{width:30px;height:30px;border-radius:8px;display:inline-flex;align-items:center;justify-content:center;border:1px solid var(--rab-line);color:var(--rab-ink);}
.rab-page .rab-op-btn:hover{background:#faf7ef;border-color:var(--rab-gold);}
.rab-page .pagination .page-link{color:var(--rab-ink);border-color:var(--rab-line);}
.rab-page .pagination .page-item.active .page-link{
  background:linear-gradient(135deg,var(--rab-gold-2),var(--rab-gold));
  border-color:transparent;color:#241708;font-weight:700;
}
.rab-page .pagination .page-item.disabled .page-link{color:#b9b2a3;background:#faf8f2;}
</style>

<div class="rab-page">

<div class="admin-page-header d-flex justify-content-between align-items-center mb-3">
  <h5 class="mb-0"><i class="fa-solid fa-database"></i> بانک متقاضیان</h5>
  <a href="admin_reception_hub.php" class="btn btn-sm btn-outline-secondary">بازگشت به پذیرش کارشناس</a>
</div>

<?php if (!$moduleReady): ?>
  <div class="alert alert-warning py-2">جدول‌های ماژولِ پذیرش کارشناس هنوز روی سرور ایجاد نشده‌اند؛ ابتدا بروزرسانیِ سیستم را اجرا کنید.</div>
<?php else: ?>

<?php if ($rxOn): $__bc = []; $__bf = [];
  foreach ($pdo->query("SELECT COALESCE(NULLIF(intake_box,''),'none') b, COUNT(*) n, SUM(assigned_agent_id IS NULL) f FROM reception_applicants GROUP BY b")->fetchAll(PDO::FETCH_ASSOC) ?: [] as $__r) { $__bc[$__r['b']] = (int) $__r['n']; $__bf[$__r['b']] = (int) $__r['f']; } ?>
<div class="row g-2 mb-3">
  <?php foreach (['none' => 'عمومی (بدونِ باکس)'] + rx_intake_boxes() as $__k => $__l): $__qq = array_merge($_GET, ['box' => $__k, 'free' => 1, 'page' => 1]); ?>
    <div class="col-6 col-md-3"><a class="card p-2 text-center text-decoration-none h-100 <?= $boxF === $__k ? 'border-primary' : '' ?>" href="?<?= e(http_build_query($__qq)) ?>">
      <div class="small text-muted">باکس <?= e($__l) ?></div>
      <div class="fs-5 fw-bold <?= ($__bf[$__k] ?? 0) < 20 ? 'text-danger' : 'text-success' ?>"><?= to_persian_digits((string) ($__bf[$__k] ?? 0)) ?></div>
      <div class="small text-muted">شماره‌ی آزاد در صف (از <?= to_persian_digits((string) ($__bc[$__k] ?? 0)) ?>)<?= ($__bf[$__k] ?? 0) < 20 ? ' — <b class="text-danger">نیاز به شارژ</b>' : '' ?></div>
    </a></div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<div class="card p-3 mb-3">
  <form method="get" class="row g-2 align-items-end">
    <?php if ($rxOn): ?>
    <div class="col-md-2">
      <label class="form-label small text-muted mb-1">باکس</label>
      <select name="box" class="form-select"><option value="">همه</option><option value="none" <?= $boxF === 'none' ? 'selected' : '' ?>>عمومی</option>
        <?php foreach (rx_intake_boxes() as $__k => $__l): ?><option value="<?= e($__k) ?>" <?= $boxF === $__k ? 'selected' : '' ?>><?= e($__l) ?></option><?php endforeach; ?></select>
      <div class="form-check small mt-1"><input class="form-check-input" type="checkbox" name="free" value="1" id="bkFree" <?= $freeF ? 'checked' : '' ?>><label class="form-check-label" for="bkFree">فقط آزاد (بدونِ کارشناس)</label></div>
    </div>
    <?php endif; ?>
    <div class="col-md-4">
      <label class="form-label small text-muted mb-1">جستجو (نام / نام خانوادگی / موبایل)</label>
      <input type="text" name="q" class="form-control" value="<?= e($q) ?>" placeholder="مثلاً: احمدی یا 0912...">
    </div>
    <div class="col-md-2">
      <label class="form-label small text-muted mb-1">نوع جلسه</label>
      <select name="meet" class="form-select">
        <option value="">همه</option>
        <option value="online" <?= $meetType === 'online' ? 'selected' : '' ?>>میتینگ آنلاین</option>
        <option value="inperson" <?= $meetType === 'inperson' ? 'selected' : '' ?>>مصاحبه حضوری</option>
      </select>
    </div>
    <div class="col-md-3">
      <label class="form-label small text-muted mb-1">وضعیت</label>
      <select name="status" class="form-select">
        <option value="">همه‌ی وضعیت‌ها</option>
        <?php foreach ($statuses as $st): ?>
          <option value="<?= e($st['code']) ?>" <?= $status === $st['code'] ? 'selected' : '' ?>><?= e($st['label']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3 d-flex gap-2">
      <button type="submit" class="btn btn-primary flex-grow-1"><i class="fa-solid fa-magnifying-glass"></i> جستجو</button>
      <a href="admin_reception_applicants_bank.php" class="btn btn-outline-secondary">پاک‌کردن</a>
    </div>
  </form>
</div>

<div class="card p-3">
  <div class="d-flex justify-content-between align-items-center mb-2">
    <div class="text-muted small"><?= to_persian_digits((string) $totalRows) ?> متقاضی یافت شد.</div>
  </div>
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
      <thead>
        <tr>
          <th>ردیف</th><th>نام و نام خانوادگی</th><th>موبایل</th><th>تاریخ ورود</th><th>منبع ورود</th><?php if ($rxOn): ?><th>باکس / شهر / آگهی</th><?php endif; ?>
          <th>کارشناس پذیرش</th><th>وضعیت</th><th>سرپرست</th><th>تاریخ ارجاع</th>
          <th>نوع جلسه</th><th>آخرین پیگیری</th><th>تعداد تماس</th><th>آخرین فعالیت</th><th>عملیات</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
        <tr><td colspan="<?= $rxOn ? 15 : 14 ?>" class="text-center text-muted py-4">موردی یافت نشد.</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $i => $r): ?>
        <tr>
          <td><?= to_persian_digits((string) ($offset + $i + 1)) ?></td>
          <td><?= e(trim($r['first_name'] . ' ' . $r['last_name'])) ?></td>
          <td dir="ltr"><?= e($r['mobile']) ?></td>
          <td class="small text-muted"><?= to_jalali($r['created_at']) ?></td>
          <td class="small"><?= e($r['source']) ?><?= !empty($r['lead_source']) ? '<div class="text-muted">' . e((string) $r['lead_source']) . '</div>' : '' ?></td>
          <?php if ($rxOn): ?><td class="small"><span class="badge text-bg-light border"><?= e(rx_intake_box_label($r['intake_box'] ?? null)) ?></span>
            <?= !empty($r['city']) ? '<div>' . e((string) $r['city']) . '</div>' : '' ?><?= !empty($r['ad_type']) ? '<div class="text-muted">' . e((string) $r['ad_type']) . '</div>' : '' ?></td><?php endif; ?>
          <td class="small"><?= e($r['agent_name'] ?? '—') ?></td>
          <td><span class="badge badge-status bg-<?= e(reception_status_color($pdo, $r['status'])) ?>"><?= e(reception_status_label($pdo, $r['status'])) ?></span></td>
          <td class="small"><?= e($r['supervisor_name'] ?? '—') ?></td>
          <td class="small text-muted"><?= $r['referred_at'] ? to_jalali($r['referred_at']) : '—' ?></td>
          <td class="small text-nowrap">
            <?php if ((int) ($r['online_cnt'] ?? 0) > 0): ?><span class="badge text-bg-info">آنلاین</span><?php endif; ?>
            <?php if ((int) ($r['inperson_cnt'] ?? 0) > 0): ?><span class="badge text-bg-warning">حضوری</span><?php endif; ?>
            <?php if (!(int) ($r['online_cnt'] ?? 0) && !(int) ($r['inperson_cnt'] ?? 0)): ?><span class="text-muted">—</span><?php endif; ?>
          </td>
          <td class="small text-muted"><?= $r['last_followup_at'] ? to_jalali($r['last_followup_at']) : '—' ?></td>
          <td><?= to_persian_digits((string) $r['call_count_real']) ?></td>
          <td class="small text-muted"><?= $r['last_activity_at'] ? to_jalali($r['last_activity_at']) : '—' ?></td>
          <td>
            <a href="../reception_applicant.php?id=<?= (int) $r['id'] ?>" class="rab-op-btn" title="مشاهده"><i class="fa-solid fa-eye"></i></a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php if ($totalPages > 1): ?>
  <?php
    // پنجره‌ی صفحه‌بندی: حداکثر ۵ شماره اطراف صفحه‌ی فعلی + اولین و آخرین.
    $startPage = max(1, $page - 2);
    $endPage   = min($totalPages, $page + 2);

    // تابع ساخت لینک با حفظ فیلترهای فعلی.
    $pageUrl = static function (int $p) use ($q, $status): string {
        return '?' . http_build_query(['q' => $q, 'status' => $status, 'meet' => $meetType, 'page' => $p]);
    };
  ?>
  <nav class="mt-3" dir="rtl">
    <ul class="pagination pagination-sm justify-content-center mb-0 flex-wrap">

      <?php // دکمه‌ی «قبلی» ?>
      <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
        <a class="page-link" href="<?= $page <= 1 ? '#' : e($pageUrl($page - 1)) ?>">قبلی</a>
      </li>

      <?php // صفحه‌ی اول + سه‌نقطه ?>
      <?php if ($startPage > 1): ?>
        <li class="page-item <?= $page === 1 ? 'active' : '' ?>">
          <a class="page-link" href="<?= e($pageUrl(1)) ?>"><?= to_persian_digits('1') ?></a>
        </li>
        <?php if ($startPage > 2): ?>
          <li class="page-item disabled"><span class="page-link">…</span></li>
        <?php endif; ?>
      <?php endif; ?>

      <?php // شماره‌های میانی ?>
      <?php for ($p = $startPage; $p <= $endPage; $p++): ?>
        <li class="page-item <?= $p === $page ? 'active' : '' ?>">
          <a class="page-link" href="<?= e($pageUrl($p)) ?>"><?= to_persian_digits((string) $p) ?></a>
        </li>
      <?php endfor; ?>

      <?php // سه‌نقطه + صفحه‌ی آخر ?>
      <?php if ($endPage < $totalPages): ?>
        <?php if ($endPage < $totalPages - 1): ?>
          <li class="page-item disabled"><span class="page-link">…</span></li>
        <?php endif; ?>
        <li class="page-item <?= $page === $totalPages ? 'active' : '' ?>">
          <a class="page-link" href="<?= e($pageUrl($totalPages)) ?>"><?= to_persian_digits((string) $totalPages) ?></a>
        </li>
      <?php endif; ?>

      <?php // دکمه‌ی «بعدی» ?>
      <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
        <a class="page-link" href="<?= $page >= $totalPages ? '#' : e($pageUrl($page + 1)) ?>">بعدی</a>
      </li>

    </ul>
  </nav>
  <div class="text-center text-muted small mt-2">
    صفحه <?= to_persian_digits((string) $page) ?> از <?= to_persian_digits((string) $totalPages) ?>
    — مجموع <?= to_persian_digits((string) $totalRows) ?> متقاضی
  </div>
  <?php endif; ?>
</div>

<?php endif; ?>

</div>

<?php require_once __DIR__ . '/../includes/layout_bottom.php'; ?>