<?php
/**
 * «ورودِ واریزی‌های قبل از سامانه» از اکسل — includes/sales_import.php
 *   ۱) آپلودِ اکسل (همان الگوی گزارشِ فروشِ قدیمی)
 *   ۲) پیش‌نمایش به تفکیکِ واریزی (شماره کار) با خطا/هشدار + انتخابِ «سهم عملکرد محاسبه شود؟» (یکجا یا تک‌تک)
 *   ۳) ثبت ← سفارشِ تأییدشده + پیش‌پرداختِ تأییدشده در تاریخِ واریز + فروشِ مشترک با همان درصدها
 * برای این سفارش‌ها تیکتِ آراد برندینگ و استارز ساخته/ارسال نمی‌شود.
 */
require_once __DIR__ . '/../includes/auth.php';
$admin = require_login();
$pdo = db();
require_once __DIR__ . '/../includes/sales_import.php';

$canImport = (function_exists('is_super_admin') && is_super_admin($admin)) || user_can('finance_orders_decide', $admin);
if (!$canImport) {
    http_response_code(403);
    exit('دسترسی ندارید.');
}
if (!simp_ready($pdo) || !scr_ready($pdo)) {
    flash_set('danger', 'جدول‌های لازم ساخته نشدند.');
    redirect('admin_dashboard.php');
}

$dir = __DIR__ . '/../storage/sales_import';
if (!is_dir($dir)) @mkdir($dir, 0775, true);
// فایل‌های موقتِ قدیمی (بیش از یک روز)
foreach (glob($dir . '/*.json') ?: [] as $__f) if (time() - (int) @filemtime($__f) > 86400) @unlink($__f);

$token = preg_replace('/[^a-f0-9]/', '', (string) ($_GET['t'] ?? $_POST['t'] ?? ''));
$tokFile = $token !== '' ? $dir . '/' . $token . '.json' : '';
$stored = $tokFile !== '' && is_file($tokFile) ? json_decode((string) file_get_contents($tokFile), true) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        flash_set('danger', 'نشست منقضی شده است؛ دوباره تلاش کنید.');
        redirect('admin_sales_import.php');
    }
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'upload') {
        $f = $_FILES['xl'] ?? null;
        if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            flash_set('danger', 'فایلی انتخاب نشد یا آپلود ناموفق بود.');
            redirect('admin_sales_import.php');
        }
        $err = null;
        $rows = read_uploaded_spreadsheet($f['tmp_name'], (string) $f['name'], $err);
        if ($rows === null) {
            flash_set('danger', $err ?: 'خواندنِ فایل ممکن نشد.');
            redirect('admin_sales_import.php');
        }
        $chk = simp_parse($pdo, $rows);
        if (!$chk['ok']) {
            flash_set('danger', $chk['message']);
            redirect('admin_sales_import.php');
        }
        $token = bin2hex(random_bytes(12));
        file_put_contents($dir . '/' . $token . '.json', json_encode(['name' => (string) $f['name'], 'rows' => $rows, 'by' => (int) $admin['id']], JSON_UNESCAPED_UNICODE));
        redirect('admin_sales_import.php?t=' . $token);
    }
    if ($action === 'import') {
        if (!$stored) {
            flash_set('danger', 'فایلِ آپلودشده پیدا نشد (منقضی شده)؛ دوباره آپلود کنید.');
            redirect('admin_sales_import.php');
        }
        $parsed = simp_parse($pdo, $stored['rows']);
        $sel = array_flip(array_map('strval', (array) ($_POST['sel'] ?? [])));
        $mode = (string) ($_POST['perf_mode'] ?? 'manual');
        $perfSel = array_flip(array_map('strval', (array) ($_POST['perf'] ?? [])));
        $ok = 0;
        $perfCnt = 0;
        $fail = [];
        @set_time_limit(0);
        foreach ($parsed['groups'] as $g) {
            if ($g['ref'] === '' || !isset($sel[$g['ref']]) || !empty($g['exists'])) continue;
            $perf = $mode === 'all' ? true : ($mode === 'none' ? false : isset($perfSel[$g['ref']]));
            $r = simp_import_group($pdo, $g, $perf, (int) $admin['id']);
            if ($r['ok']) {
                $ok++;
                if ($perf) $perfCnt++;
            } else {
                $fail[] = to_persian_digits($g['ref']) . ': ' . $r['message'];
            }
        }
        if ($ok) flash_set('success', to_persian_digits((string) $ok) . ' واریزی ثبت شد' . ($perfCnt ? ' (سهم عملکردِ ' . to_persian_digits((string) $perfCnt) . ' مورد طبقِ قانونِ فعلی محاسبه شد)' : '') . '. در «گزارش فروش» به تاریخِ واریز دیده می‌شوند.');
        if ($fail) flash_set('danger', 'ثبت نشد: ' . implode(' | ', array_slice($fail, 0, 15)) . (count($fail) > 15 ? ' …' : ''));
        if (!$ok && !$fail) flash_set('warning', 'هیچ واریزی‌ای انتخاب نشده بود.');
        redirect('admin_sales_import.php?t=' . $token);
    }
    if ($action === 'discard') {
        if ($tokFile !== '' && is_file($tokFile)) @unlink($tokFile);
        redirect('admin_sales_import.php');
    }
    redirect('admin_sales_import.php');
}

$parsed = $stored ? simp_parse($pdo, $stored['rows']) : null;
if ($stored && !$parsed['ok']) {
    flash_set('danger', $parsed['message']);
    $parsed = null;
}
$groups = $parsed['groups'] ?? [];
$sum = ['n' => 0, 'ready' => 0, 'err' => 0, 'done' => 0, 'amount' => 0];
foreach ($groups as $g) {
    $sum['n']++;
    if (!empty($g['exists'])) $sum['done']++;
    elseif ($g['errors']) $sum['err']++;
    else { $sum['ready']++; $sum['amount'] += (int) ($g['amount'] ?? 0); }
}
// واریزی‌های واردشده‌ی قبلی
$imported = [];
try {
    $imported = $pdo->query("SELECT o.id, o.order_number, o.total_amount, o.payment_date, o.import_perf, c.full_name cname, u.full_name sname
        FROM sales_orders o LEFT JOIN customers c ON c.id = o.customer_id LEFT JOIN users u ON u.id = o.seller_user_id
        WHERE o.import_ref IS NOT NULL ORDER BY o.payment_date DESC, o.id DESC LIMIT 300")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $importedCnt = (int) $pdo->query('SELECT COUNT(*) FROM sales_orders WHERE import_ref IS NOT NULL')->fetchColumn();
} catch (Throwable $e) {
    $importedCnt = 0;
}
$money = static fn($n) => to_persian_digits(number_format((int) $n));

$pageTitle = 'ورودِ واریزی‌های قبل از سامانه';
require_once __DIR__ . '/../includes/layout_top.php';
?>
<style>
.simp .card{ border-radius:14px; border:1px solid rgba(201,162,75,.28); }
.simp table td, .simp table th{ vertical-align:middle; font-size:13px; }
.simp tr.err td{ background:#fff5f5; }
.simp tr.done td{ background:#f6f6f4; color:#78716c; }
.simp .agents span{ display:inline-block; border:1px solid #e7e2d3; border-radius:12px; padding:1px 8px; margin:1px; font-size:12px; white-space:nowrap; }
.simp .perf-col{ display:none; }
.simp.mode-manual .perf-col{ display:table-cell; }
</style>
<div class="simp mode-manual" id="simp">
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <div>
      <h4 class="fw-bold mb-0"><i class="fa-solid fa-file-import"></i> ورودِ واریزی‌های قبل از سامانه (اکسل)</h4>
      <div class="text-muted small">واریزی‌ها با سهم‌ها و درصدها به‌عنوانِ سفارشِ تأییدشده ثبت می‌شوند و در «گزارش فروش» (به تاریخِ واریز) می‌آیند. برای این‌ها <b>تیکت ارسال نمی‌شود</b>.</div>
    </div>
    <div class="d-flex gap-2">
      <a href="admin_orders.php?view=report" class="btn btn-sm btn-outline-dark"><i class="fa-solid fa-chart-column"></i> گزارش فروش</a>
      <a href="admin_dashboard.php" class="btn btn-sm btn-outline-secondary">→ پنل مدیریت</a>
    </div>
  </div>

<?php if (!$parsed): ?>
  <div class="card p-3 mb-3">
    <form method="post" enctype="multipart/form-data" class="row g-2 align-items-end">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="upload">
      <div class="col-md-6">
        <label class="form-label fw-bold">فایلِ اکسل (xlsx)</label>
        <input type="file" name="xl" accept=".xlsx,.xlsm,.csv" class="form-control" required>
      </div>
      <div class="col-md-3"><button class="btn btn-dark w-100"><i class="fa-solid fa-upload"></i> بارگذاری و پیش‌نمایش</button></div>
    </form>
    <div class="small text-muted mt-3 lh-lg">
      الگو: هر ردیف یک سهم‌گیر است و ردیف‌هایی با «شماره کار»ِ یکسان یک واریزی‌اند. ستون‌های لازم:
      «شماره کار»، «تاجر»، «شماره همراه تاجر»، «مبلغ»، «تاریخ» (مثلِ ۱۴۰۵۰۷۰۱)، «دستی» (درصدِ سهم)، «نام و نام خانوادگی» و «کد پسنلی» (کدِ آرادِ کارشناس).
      ستون‌های «شهر»، «کد ملی تاجر»، «طرح»، «مشتری جدید»، «حساب»، «پرداخت» و «پورسانت» هم در توضیحِ سفارش ثبت می‌شوند.
      <br>اولین ردیفِ هر واریزی ثبت‌کننده‌ی فروش است؛ اگر چند نفر سهم دارند، «فروشِ مشترک» با همان درصدها ثبت می‌شود.
      مشتری با شماره همراه پیدا می‌شود و اگر نبود ساخته می‌شود. ورودِ دوباره‌ی همان شماره کار تکراری ثبت نمی‌شود.
    </div>
  </div>
<?php else: ?>
  <div class="row g-2 mb-3">
    <?php foreach ([['واریزی در فایل', 'dark', $sum['n']], ['آماده‌ی ثبت', 'success', $sum['ready']], ['دارای خطا', 'danger', $sum['err']], ['قبلاً ثبت شده', 'secondary', $sum['done']]] as [$__l, $__c, $__n]): ?>
      <div class="col-6 col-md-3"><div class="card p-2 text-center border-<?= $__c ?>"><div class="small text-muted"><?= $__l ?></div><div class="fs-4 fw-bold text-<?= $__c ?>"><?= to_persian_digits((string) $__n) ?></div></div></div>
    <?php endforeach; ?>
  </div>
  <form method="post" class="card p-3 mb-3" id="simpForm">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="import">
    <input type="hidden" name="t" value="<?= e($token) ?>">
    <div class="d-flex flex-wrap gap-3 align-items-center mb-2">
      <div class="fw-bold">فایل: <?= e((string) ($stored['name'] ?? '')) ?></div>
      <div class="ms-auto d-flex flex-wrap gap-3 align-items-center border rounded-3 px-3 py-2" style="background:#fffbeb">
        <span class="fw-bold"><i class="fa-solid fa-trophy"></i> سهم عملکردِ کارشناسان طبقِ قانونِ فعلی محاسبه شود؟</span>
        <label><input type="radio" name="perf_mode" value="all"> بله، برای همه</label>
        <label><input type="radio" name="perf_mode" value="none"> خیر، برای هیچ‌کدام</label>
        <label><input type="radio" name="perf_mode" value="manual" checked> دستی (تک‌تک)</label>
      </div>
    </div>
    <div class="table-responsive">
      <table class="table table-sm table-bordered mb-2">
        <thead class="table-light"><tr>
          <th><input type="checkbox" id="selAll" checked title="همه"></th>
          <th>شماره کار</th><th>تاریخِ واریز</th><th>تاجر</th><th>مبلغ (تومان)</th><th>سهم‌گیرندگان</th><th>مشتری</th><th>وضعیت</th>
          <th class="perf-col text-center">سهم عملکرد<br><input type="checkbox" id="perfAll" title="همه"></th>
        </tr></thead>
        <tbody>
        <?php foreach ($groups as $g): $__ok = !$g['errors'] && empty($g['exists']); ?>
          <tr class="<?= !empty($g['exists']) ? 'done' : ($g['errors'] ? 'err' : '') ?>">
            <td><?php if ($__ok): ?><input type="checkbox" class="sel" name="sel[]" value="<?= e($g['ref']) ?>" checked><?php endif; ?></td>
            <td dir="ltr" class="text-nowrap"><?= e(to_persian_digits($g['ref'] ?: '—')) ?></td>
            <td class="text-nowrap"><?= !empty($g['date']) ? to_jalali($g['date']) : e(to_persian_digits((string) ($g['date_raw'] ?? ''))) ?></td>
            <td><?= e((string) ($g['name'] ?? '')) ?><div class="text-muted" dir="ltr" style="font-size:11px"><?= e(to_persian_digits((string) ($g['mobile'] ?? ''))) ?></div>
              <?php if (($g['plan'] ?? '') !== ''): ?><div class="text-muted" style="font-size:11px"><?= e($g['plan']) ?><?= ($g['kind'] ?? '') !== '' ? ' — ' . e($g['kind']) : '' ?></div><?php endif; ?></td>
            <td class="text-nowrap fw-bold"><?= $money($g['amount'] ?? 0) ?></td>
            <td class="agents"><?php foreach ($g['agents'] as $a): ?><span class="<?= $a['user_id'] ? '' : 'text-danger' ?>"><?= e($a['name']) ?> <b><?= to_persian_digits((string) round($a['pct'], 2)) ?>٪</b><?php if (count($g['agents']) > 1 && isset($a['amount'])): ?> <span class="border-0 p-0 text-muted"><?= $money($a['amount']) ?></span><?php endif; ?></span><?php endforeach; ?></td>
            <td class="small"><?php if (!empty($g['customer'])): ?><a href="../customer_view.php?id=<?= (int) $g['customer']['id'] ?>" target="_blank">موجود: <?= e((string) $g['customer']['full_name']) ?></a><?php elseif ($g['ref'] !== ''): ?><span class="text-primary">ساخته می‌شود</span><?php endif; ?></td>
            <td class="small">
              <?php if (!empty($g['exists'])): ?><a href="../order_view.php?id=<?= (int) $g['exists'] ?>" target="_blank">ثبت شده (سفارش #<?= to_persian_digits((string) $g['exists']) ?>)</a>
              <?php elseif ($g['errors']): ?><div class="text-danger"><?= implode('<br>', array_map('e', array_unique($g['errors']))) ?></div>
              <?php else: ?><span class="text-success">آماده</span><?php endif; ?>
              <?php if ($g['warnings'] && empty($g['exists'])): ?><div class="text-warning-emphasis"><?= implode('<br>', array_map('e', $g['warnings'])) ?></div><?php endif; ?>
            </td>
            <td class="perf-col text-center"><?php if ($__ok): ?><input type="checkbox" class="perf" name="perf[]" value="<?= e($g['ref']) ?>"><?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="d-flex flex-wrap gap-2 align-items-center">
      <button class="btn btn-success" <?= $sum['ready'] ? '' : 'disabled' ?> onclick="return confirm('واریزی‌های انتخاب‌شده ثبت شوند؟')"><i class="fa-solid fa-check"></i> ثبتِ واریزی‌های انتخاب‌شده</button>
      <span class="text-muted small">جمعِ واریزی‌های آماده: <b><?= $money($sum['amount']) ?></b> تومان</span>
    </div>
  </form>
  <form method="post" class="mb-3">
    <?= csrf_field() ?><input type="hidden" name="action" value="discard"><input type="hidden" name="t" value="<?= e($token) ?>">
    <button class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-upload"></i> بارگذاریِ فایلِ دیگر</button>
  </form>
<?php endif; ?>

  <div class="card p-3">
    <h6 class="fw-bold"><i class="fa-solid fa-clock-rotate-left"></i> واریزی‌های واردشده از اکسل <span class="badge text-bg-dark"><?= to_persian_digits((string) $importedCnt) ?></span></h6>
    <?php if (!$imported): ?><div class="text-muted small">هنوز چیزی وارد نشده است.</div><?php else: ?>
    <div class="table-responsive" style="max-height:420px">
      <table class="table table-sm table-hover mb-0">
        <thead class="table-light"><tr><th>سفارش</th><th>تاریخِ واریز</th><th>مشتری</th><th>ثبت‌کننده</th><th>مبلغ</th><th>سهم عملکرد</th></tr></thead>
        <tbody>
        <?php foreach ($imported as $o): ?>
          <tr>
            <td><a href="../order_view.php?id=<?= (int) $o['id'] ?>" dir="ltr"><?= e(to_persian_digits((string) $o['order_number'])) ?></a></td>
            <td><?= $o['payment_date'] ? to_jalali((string) $o['payment_date']) : '—' ?></td>
            <td><?= e((string) $o['cname']) ?></td>
            <td><?= e((string) $o['sname']) ?></td>
            <td class="text-nowrap"><?= $money($o['total_amount']) ?></td>
            <td><?= (int) $o['import_perf'] === 1 ? '<span class="badge text-bg-success">محاسبه شد</span>' : '<span class="badge text-bg-secondary">ندارد</span>' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>
<script>
(function () {
  const box = document.getElementById('simp');
  const sync = () => {
    const m = (document.querySelector('input[name=perf_mode]:checked') || {}).value || 'manual';
    box.classList.toggle('mode-manual', m === 'manual');
  };
  document.querySelectorAll('input[name=perf_mode]').forEach(r => r.addEventListener('change', sync));
  sync();
  const all = (id, cls) => { const a = document.getElementById(id); if (a) a.addEventListener('change', () => document.querySelectorAll('input.' + cls).forEach(c => c.checked = a.checked)); };
  all('selAll', 'sel');
  all('perfAll', 'perf');
})();
</script>
<?php require_once __DIR__ . '/../includes/layout_bottom.php'; ?>
