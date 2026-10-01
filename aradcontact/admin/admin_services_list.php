<?php
require_once __DIR__ . '/../includes/auth.php';
$admin = require_admin();
$pdo = db();
require_once __DIR__ . '/../includes/services_functions.php';
$descReady = services_desc_ready($pdo);
$ticketReady = services_ticket_ready($pdo);

// -----------------------------------------------------------------
// فهرستِ واحدهای آراد برندینگ (برای «واحدِ تیکت» هر خدمت)
// -----------------------------------------------------------------
require_once __DIR__ . '/../includes/aradbranding_ticket.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'fetch_departments') {
    if (!csrf_verify()) {
        flash_set('danger', 'نشست شما منقضی شده است، دوباره تلاش کنید.');
    } elseif (abt_ready($pdo)) {
        $r = abt_fetch_departments($pdo, abt_settings($pdo), (int) $admin['id']);
        $_SESSION['abt_departments'] = $r;
        flash_set($r['ok'] ? 'success' : 'danger', $r['message']);
    }
    redirect('admin_services_list.php' . ($_POST['redirect_qs'] ?? '') . '#abt-departments');
}
$deptResult = $_SESSION['abt_departments'] ?? null;
unset($_SESSION['abt_departments']);
if (!$deptResult && abt_ready($pdo) && ($__saved = abt_departments(abt_settings($pdo)))) {
    $deptResult = ['items' => array_map(static fn($id, $name) => ['id' => (string) $id, 'name' => (string) $name], array_keys($__saved), array_values($__saved)), 'raw' => ''];
}

// -----------------------------------------------------------------
// افزودن/ویرایشِ دستیِ یک خدمت
// -----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_service'])) {
    if (!csrf_verify()) {
        flash_set('danger', 'نشست شما منقضی شده است، دوباره تلاش کنید.');
    } else {
        $serviceId = (int) ($_POST['service_id'] ?? 0);
        $category = trim((string) ($_POST['category'] ?? ''));
        $title = trim((string) ($_POST['title'] ?? ''));
        $unit = trim((string) ($_POST['unit'] ?? ''));
        $unitPrice = (int) preg_replace('/\D/', '', (string) ($_POST['unit_price'] ?? '0'));
        $description = trim(str_replace("\r\n", "\n", (string) ($_POST['description'] ?? '')));
        $tSubject = mb_substr(trim((string) ($_POST['ticket_subject'] ?? '')), 0, 250);
        $tBody = trim(str_replace("\r\n", "\n", (string) ($_POST['ticket_body'] ?? '')));
        $tDept = mb_substr(trim((string) ($_POST['ticket_department'] ?? '')), 0, 100);

        if ($category === '' || $title === '' || $unit === '') {
            flash_set('danger', 'دسته، عنوان و واحد نمی‌توانند خالی باشند.');
        } else {
            try {
                if ($serviceId > 0) {
                    $oldStmt = $pdo->prepare('SELECT unit_price FROM services WHERE id = ? LIMIT 1');
                    $oldStmt->execute([$serviceId]);
                    $oldPrice = $oldStmt->fetchColumn();

                    $pdo->prepare('UPDATE services SET category = ?, title = ?, unit = ?, unit_price = ? WHERE id = ?')
                        ->execute([$category, $title, $unit, $unitPrice, $serviceId]);
                    if ($descReady) {
                        $pdo->prepare('UPDATE services SET description = ? WHERE id = ?')->execute([$description !== '' ? $description : null, $serviceId]);
                    }
                    if ($ticketReady) {
                        $pdo->prepare('UPDATE services SET ticket_subject = ?, ticket_body = ?, ticket_department = ? WHERE id = ?')
                            ->execute([$tSubject ?: null, $tBody ?: null, $tDept ?: null, $serviceId]);
                    }

                    if ($oldPrice !== false && (int) $oldPrice !== $unitPrice) {
                        $pdo->prepare('INSERT INTO service_price_history (service_id, old_price, new_price, changed_by) VALUES (?,?,?,?)')
                            ->execute([$serviceId, (int) $oldPrice, $unitPrice, (int) $admin['id']]);
                    }
                    flash_set('success', 'خدمت بروزرسانی شد.');
                } else {
                    $pdo->prepare('INSERT INTO services (category, title, unit, unit_price) VALUES (?,?,?,?)')
                        ->execute([$category, $title, $unit, $unitPrice]);
                    $newId = (int) $pdo->lastInsertId();
                    if ($descReady && $description !== '') {
                        $pdo->prepare('UPDATE services SET description = ? WHERE id = ?')->execute([$description, $newId]);
                    }
                    if ($ticketReady && ($tSubject !== '' || $tBody !== '' || $tDept !== '')) {
                        $pdo->prepare('UPDATE services SET ticket_subject = ?, ticket_body = ?, ticket_department = ? WHERE id = ?')
                            ->execute([$tSubject ?: null, $tBody ?: null, $tDept ?: null, $newId]);
                    }
                    $pdo->prepare('INSERT INTO service_price_history (service_id, old_price, new_price, changed_by) VALUES (?,NULL,?,?)')
                        ->execute([$newId, $unitPrice, (int) $admin['id']]);
                    flash_set('success', 'خدمت جدید ثبت شد.');
                }
            } catch (Throwable $e) {
                flash_set('danger', 'یک خدمت با همین دسته و عنوان از قبل وجود دارد.');
            }
        }
    }
    redirect('admin_services_list.php' . ($_POST['redirect_qs'] ?? ''));
}

// -----------------------------------------------------------------
// فعال/غیرفعال‌کردنِ یک خدمت (به‌جای حذف، تا سابقه‌ی پیش‌فاکتورهای قبلی خراب نشه)
// -----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_active'])) {
    if (csrf_verify()) {
        $serviceId = (int) ($_POST['toggle_active'] ?? 0);
        $pdo->prepare('UPDATE services SET is_active = 1 - is_active WHERE id = ?')->execute([$serviceId]);
        flash_set('success', 'وضعیتِ خدمت تغییر کرد.');
    }
    redirect('admin_services_list.php' . ($_POST['redirect_qs'] ?? ''));
}

// -----------------------------------------------------------------
// جستجو و لیست
// -----------------------------------------------------------------
$q = trim((string) ($_GET['q'] ?? ''));
$categoryFilter = trim((string) ($_GET['category'] ?? ''));

$where = [];
$params = [];
if ($q !== '') {
    $where[] = '(title LIKE ? OR category LIKE ?)';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
}
if ($categoryFilter !== '') {
    $where[] = 'category = ?';
    $params[] = $categoryFilter;
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$stmt = $pdo->prepare("SELECT * FROM services $whereSql ORDER BY category, title");
$stmt->execute($params);
$services = $stmt->fetchAll();

$categories = $pdo->query('SELECT DISTINCT category FROM services ORDER BY category')->fetchAll(PDO::FETCH_COLUMN);

$editService = null;
if (isset($_GET['edit'])) {
    $editStmt = $pdo->prepare('SELECT * FROM services WHERE id = ? LIMIT 1');
    $editStmt->execute([(int) $_GET['edit']]);
    $editService = $editStmt->fetch() ?: null;
}

$redirectQs = '?' . http_build_query(['q' => $q, 'category' => $categoryFilter]);

$pageTitle = 'لیست خدمات';
require_once __DIR__ . '/../includes/layout_top.php';
?>

<style>
.sl-page{ --sl-gold:#c9a24b; --sl-gold-2:#f1dfa8; --sl-ink:#1c1917; --sl-line:rgba(201,162,75,.28); }
.sl-page .btn-outline-secondary{ border-color:var(--sl-line); color:#57534e; }
.sl-page .btn-outline-secondary:hover{ background:#faf7ef; border-color:var(--sl-gold); color:var(--sl-ink); }

.sl-page .card{ border:1px solid var(--sl-line); border-radius:14px; box-shadow:0 4px 16px -14px rgba(28,25,23,.3); }
.sl-page .card h6{ font-weight:800; color:var(--sl-ink); }

.sl-page .form-control:focus, .sl-page .form-select:focus{
  border-color:var(--sl-gold); box-shadow:0 0 0 .2rem rgba(201,162,75,.18);
}
.sl-page .btn-primary{
  background:linear-gradient(135deg,var(--sl-gold-2),var(--sl-gold)); border:none; color:#241d0f; font-weight:700;
  box-shadow:0 6px 14px -8px rgba(201,162,75,.6);
}
.sl-page .btn-primary:hover{ filter:brightness(1.05); color:#241d0f; }
.sl-page .btn-outline-primary{ border-color:var(--sl-gold); color:#8a6d2c; }
.sl-page .btn-outline-primary:hover{ background:var(--sl-gold); color:#241d0f; border-color:var(--sl-gold); }

.sl-page table thead.table-light th{
  background:linear-gradient(135deg,#faf5e7,#f1e6c8); color:#5c4a1e; font-weight:700; border-bottom:1px solid var(--sl-line);
}
.sl-page tr.table-secondary{ background:#f7f5ef!important; opacity:.75; }
.sl-page .badge.bg-success-subtle{ background:#eaf6ee!important; color:#15803d!important; border:1px solid rgba(21,128,61,.2); }
.sl-page .badge.bg-secondary-subtle{ background:#f3f0e6!important; color:#78716c!important; border:1px solid var(--sl-line); }
/* جدولِ خدمات: در دسکتاپ کلِ جدول در یک قاب (بدونِ اسکرولِ افقی)؛ ستونِ عنوان باقیِ عرض را می‌گیرد و شرح کوتاه می‌شود */
.sl-page .sl-desc{ white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:100%; }
.sl-page .sl-title{ overflow-wrap:anywhere; }
.sl-page .sl-actions{ display:flex; gap:.25rem; justify-content:flex-end; }
@media (min-width: 992px){
  .sl-page .sl-table{ table-layout:fixed; width:100%; }
  .sl-page .sl-table .c-cat{ width:92px; }
  .sl-page .sl-table .c-unit{ width:64px; }
  .sl-page .sl-table .c-price{ width:112px; }
  .sl-page .sl-table .c-status{ width:74px; }
  .sl-page .sl-table .c-act{ width:86px; }
  .sl-page .sl-table td{ overflow:hidden; }
}
@media (max-width: 991.98px){
  .sl-page .sl-table{ min-width:640px; }
  .sl-page .sl-desc{ max-width:320px; }
}
</style>

<div class="sl-page">
<div class="d-flex flex-wrap gap-2 mb-3">
  <a href="admin_services_hub.php" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-arrow-right"></i> بازگشت به فهرست خدمات</a>
  <a href="admin_aradbranding_send.php" class="btn btn-sm btn-success"><i class="fa-solid fa-paper-plane"></i> ارسال تیکت‌ها</a>
</div>

<div class="row g-3">
  <div class="col-lg-8">
    <div class="card p-3 mb-3">
      <form method="get" class="d-flex gap-2 flex-wrap">
        <input type="text" name="q" class="form-control form-control-sm" style="max-width:240px;" value="<?= e($q) ?>" placeholder="جستجوی عنوان یا دسته">
        <select name="category" class="form-select form-select-sm" style="max-width:220px;">
          <option value="">همه‌ی دسته‌ها</option>
          <?php foreach ($categories as $cat): ?>
            <option value="<?= e($cat) ?>" <?= $categoryFilter === $cat ? 'selected' : '' ?>><?= e($cat) ?></option>
          <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-sm btn-primary"><i class="fa-solid fa-magnifying-glass"></i> جستجو</button>
      </form>
    </div>

    <div class="card p-3">
      <h6 class="mb-3">لیست خدمات (<?= to_persian_digits((string) count($services)) ?> مورد)</h6>
      <?php if (!$services): ?>
        <p class="text-muted small mb-0">موردی پیدا نشد.</p>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0 sl-table">
            <thead class="table-light"><tr><th class="c-cat">دسته</th><th class="c-title">عنوان</th><th class="c-unit">واحد</th><th class="c-price">قیمت واحد</th><th class="c-status">وضعیت</th><th class="c-act"></th></tr></thead>
            <tbody>
              <?php foreach ($services as $s): ?>
                <tr class="<?= $s['is_active'] ? '' : 'table-secondary' ?>">
                  <td><?= e($s['category']) ?></td>
                  <td class="sl-title"><?= e($s['title']) ?><?php if (!empty($s['ticket_body']) || !empty($s['ticket_department'])): ?> <i class="fa-solid fa-ticket text-primary small" title="تیکتِ اختصاصی<?= !empty($s['ticket_department']) ? ' — واحد: ' . e((string) $s['ticket_department']) : '' ?>"></i><?php endif; ?>
                    <?php if ($descReady): ?>
                      <?php if (trim((string) ($s['description'] ?? '')) !== ''): ?>
                        <div class="small text-muted sl-desc" title="<?= e((string) $s['description']) ?>"><i class="fa-solid fa-align-right text-success"></i> <?= e(mb_substr((string) $s['description'], 0, 90)) ?></div>
                      <?php else: ?>
                        <div class="small text-warning"><i class="fa-solid fa-circle-exclamation"></i> شرح خدمت ثبت نشده</div>
                      <?php endif; ?>
                    <?php endif; ?>
                  </td>
                  <td><?= e($s['unit']) ?></td>
                  <td><?= format_toman((int) $s['unit_price']) ?></td>
                  <td>
                    <?php if ($s['is_active']): ?>
                      <span class="badge bg-success-subtle text-success-emphasis">فعال</span>
                    <?php else: ?>
                      <span class="badge bg-secondary-subtle text-secondary-emphasis">غیرفعال</span>
                    <?php endif; ?>
                  </td>
                  <td><div class="sl-actions">
                    <a href="admin_services_list.php?<?= e(http_build_query(['q' => $q, 'category' => $categoryFilter, 'edit' => $s['id']])) ?>#service-form" class="btn btn-sm btn-outline-primary" title="ویرایش"><i class="fa-solid fa-pen"></i></a>
                    <form method="post" onsubmit="return confirm('وضعیتِ فعال/غیرفعالِ این خدمت عوض بشه؟');">
                      <?= csrf_field() ?>
                      <input type="hidden" name="toggle_active" value="<?= (int) $s['id'] ?>">
                      <input type="hidden" name="redirect_qs" value="<?= e($redirectQs) ?>">
                      <button type="submit" class="btn btn-sm btn-outline-secondary" title="فعال/غیرفعال کردن"><i class="fa-solid fa-toggle-on"></i></button>
                    </form>
                  </div></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="col-lg-4">
    <div class="card p-3" id="service-form">
      <h6 class="mb-3"><?= $editService ? 'ویرایشِ خدمت' : 'افزودنِ خدمتِ جدید' ?></h6>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="save_service" value="1">
        <input type="hidden" name="service_id" value="<?= $editService ? (int) $editService['id'] : 0 ?>">
        <input type="hidden" name="redirect_qs" value="<?= e($redirectQs) ?>">
        <div class="mb-2">
          <label class="form-label small">دسته خدمات</label>
          <input type="text" name="category" class="form-control form-control-sm" value="<?= e($editService['category'] ?? '') ?>" required>
        </div>
        <div class="mb-2">
          <label class="form-label small">عنوان خدمت</label>
          <input type="text" name="title" class="form-control form-control-sm" value="<?= e($editService['title'] ?? '') ?>" required>
        </div>
        <div class="mb-2">
          <label class="form-label small">واحد</label>
          <input type="text" name="unit" class="form-control form-control-sm" value="<?= e($editService['unit'] ?? '') ?>" required>
        </div>
        <div class="mb-3">
          <label class="form-label small">قیمت واحد (تومان)</label>
          <input type="text" name="unit_price" dir="ltr" class="form-control form-control-sm" value="<?= $editService ? (int) $editService['unit_price'] : '' ?>" required>
        </div>
        <?php if ($descReady): ?>
        <div class="mb-3">
          <label class="form-label small">شرح خدمت</label>
          <textarea name="description" class="form-control form-control-sm" rows="8" placeholder="این خدمت دقیقاً چیست، چه کاری برای مشتری انجام می‌شود، فرآیند اجرا، خروجی و محدوده‌ی کار شرکت."><?= e((string) ($editService['description'] ?? '')) ?></textarea>
          <div class="form-text">فقط ماهیت و محدوده‌ی خدمت را بنویسید. <b>تعداد، مقدار، دفعات، تعداد جلسات یا ماه</b> را اینجا ننویسید؛ این‌ها از پیش‌فاکتورِ هر مشتری خوانده می‌شوند.</div>
        </div>
        <?php endif; ?>
        <?php if ($ticketReady): $__hasTicket = !empty($editService['ticket_body']) || !empty($editService['ticket_department']); ?>
        <div class="border rounded-3 p-2 mb-3" style="background:#f8fbff;border-color:#bfdbfe !important">
          <div class="d-flex justify-content-between align-items-center">
            <div class="fw-bold small"><i class="fa-solid fa-ticket text-primary"></i> تیکتِ آراد برندینگ برای این خدمت</div>
            <button type="button" class="btn btn-sm btn-link p-0" data-bs-toggle="collapse" data-bs-target="#svcTicket"><?= $__hasTicket ? 'ویرایش' : 'تنظیم' ?></button>
          </div>
          <div class="small text-muted">بعد از تأییدِ مالی، برای هر خدمتِ فروخته‌شده یک تیکتِ جدا از همین «واحد» برای مشتری (تاجرِ صاحبِ تیکت) ثبت می‌شود.</div>
          <div class="collapse <?= $__hasTicket ? 'show' : '' ?> mt-2" id="svcTicket">
            <label class="form-label small mb-1">واحد (دپارتمان) در آراد برندینگ</label>
            <input type="text" name="ticket_department" class="form-control form-control-sm mb-2" value="<?= e((string) ($editService['ticket_department'] ?? '')) ?>" placeholder="نام یا شناسه‌ی واحد — مثلاً: آموزش / طراحی / ۳">
            <div class="form-text mt-n1 mb-2">شناسه‌ها در <a href="#abt-departments">فهرستِ واحدهای آراد برندینگ</a> (پایینِ همین کادر).</div>
            <label class="form-label small mb-1">موضوعِ تیکت</label>
            <input type="text" name="ticket_subject" id="svcTSubj" class="form-control form-control-sm mb-2" value="<?= e((string) ($editService['ticket_subject'] ?? '')) ?>" placeholder="مثلاً: فعال‌سازیِ «نام_خدمت» — سفارش «شماره_سفارش»">
            <label class="form-label small mb-1">متنِ پیام</label>
            <textarea name="ticket_body" id="svcTBody" class="form-control form-control-sm" rows="7" placeholder="«عنوان» «نام_مشتری» گرامی، خدمتِ «نام_خدمت» به مقدارِ «مقدار_و_واحد» برای شما ثبت شد…"><?= e((string) ($editService['ticket_body'] ?? '')) ?></textarea>
            <div class="small mt-1 svc-ph">متغیرها (کلیک = درج):
              <?php foreach (['نام_خدمت' => 'نام خدمت', 'مقدار' => 'مقدار (عدد)', 'واحد' => 'واحد (سالانه، صفحه، عدد…)', 'مقدار_و_واحد' => 'مثلاً «۵ صفحه» یا «۱ سالانه»', 'شرح_خدمت' => 'شرح خدمت',
                  'عنوان' => 'آقای/خانم', 'نام_مشتری' => 'نام مشتری', 'موبایل' => 'موبایل', 'شماره_سفارش' => 'شماره فاکتور', 'شماره_قرارداد' => 'شماره قرارداد', 'تاریخ_تایید' => 'تاریخ تأیید', 'کارشناس' => 'کارشناس'] as $__k => $__l): ?>
                <code title="<?= e($__l) ?>" data-ph="«<?= e($__k) ?>»" style="cursor:pointer">«<?= e($__k) ?>»</code>
              <?php endforeach; ?>
            </div>
            <div class="form-text">اگر خالی بماند، متنِ عمومیِ «تیکت آراد برندینگ» (پنل مدیریت) برای این خدمت استفاده می‌شود.</div>
          </div>
        </div>
        <script>
        (function () {
          var last = document.getElementById('svcTBody');
          ['svcTSubj', 'svcTBody'].forEach(function (id) { document.getElementById(id).addEventListener('focus', function (e) { last = e.target; }); });
          document.querySelectorAll('.svc-ph code').forEach(function (c) { c.addEventListener('click', function () {
            var t = last, v = c.dataset.ph, a = t.selectionStart ?? t.value.length, b = t.selectionEnd ?? t.value.length;
            t.value = t.value.slice(0, a) + v + t.value.slice(b); t.focus(); t.selectionStart = t.selectionEnd = a + v.length;
          }); });
        })();
        </script>
        <?php endif; ?>
        <button type="submit" class="btn btn-primary btn-sm w-100"><?= $editService ? 'ذخیره‌ی تغییرات' : 'ثبت خدمت' ?></button>
        <?php if ($editService): ?>
          <a href="admin_services_list.php?<?= e(http_build_query(['q' => $q, 'category' => $categoryFilter])) ?>" class="btn btn-outline-secondary btn-sm w-100 mt-2">انصراف از ویرایش</a>
        <?php endif; ?>
      </form>
    </div>

    <div class="card p-3 mt-3" id="abt-departments">
      <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div><h6 class="fw-bold mb-0"><i class="fa-solid fa-sitemap"></i> فهرستِ واحدهای آراد برندینگ</h6>
          <div class="small text-muted">شناسه‌ی هر واحد را در «واحدِ تیکت» خدمت (یا «واحدِ پیش‌فرض» در تنظیمات تیکت) وارد کنید.</div></div>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="fetch_departments"><input type="hidden" name="redirect_qs" value="<?= e($redirectQs) ?>">
          <button class="btn btn-sm btn-outline-primary"><i class="fa-solid fa-rotate"></i> دریافتِ فهرستِ واحدها</button></form>
      </div>
      <?php if ($deptResult): ?>
        <?php if (!empty($deptResult['items'])): ?>
          <div style="max-height:420px;overflow-y:auto" class="mt-3"><table class="table table-sm small mb-0"><thead class="table-light"><tr><th>شناسه (برای واردکردن)</th><th>نامِ واحد</th></tr></thead><tbody>
            <?php foreach ($deptResult['items'] as $d): ?><tr><td dir="ltr" class="fw-bold"><?= e($d['id']) ?></td><td><?= e($d['name']) ?></td></tr><?php endforeach; ?>
          </tbody></table></div>
        <?php endif; ?>
        <?php if (empty($deptResult['items']) && !empty($deptResult['raw'])): ?><pre class="small mt-3 mb-0 p-2 bg-light border rounded" dir="ltr" style="white-space:pre-wrap"><?= e((string) $deptResult['raw']) ?></pre><?php endif; ?>
      <?php else: ?>
        <div class="small text-muted mt-2">هنوز فهرستی دریافت نشده؛ اول اتصالِ API را در «تنظیمات تیکت» ذخیره و بعد «دریافتِ فهرستِ واحدها» را بزنید.</div>
      <?php endif; ?>
    </div>
  </div>
</div>
</div>

<?php require_once __DIR__ . '/../includes/layout_bottom.php'; ?>
