<?php
/** سهم عملکرد — پنل مدیریت (مدلِ نهایی: مالکیتِ A/B/C، سرپرستِ تیم‌ها، سهمِ پایه‌ی نسخه‌دار) */
require_once __DIR__ . '/../includes/auth.php';
$user = require_login();
$pdo = db();
require_once __DIR__ . '/../includes/services_functions.php';
require_once __DIR__ . '/../includes/orders_functions.php';
require_once __DIR__ . '/../includes/customer_credit.php';
require_once __DIR__ . '/../includes/performance_functions.php';
require_once __DIR__ . '/../includes/xlsx_writer.php';
require_once __DIR__ . '/../includes/spreadsheet_reader.php';
if (!perf_can('view_all', $user) && !perf_can('rules', $user) && !perf_can('payouts', $user)) perm_deny('دسترسی به «سهم عملکرد» ندارید.', $user);
if (!perf_ready($pdo)) die('جدول‌های سهم عملکرد ساخته نشدند.');
$uid = (int) $user['id'];
$synced = ps_sync_all($pdo, $uid); // شبکه‌ی ایمنی: پرداخت‌های تأییدشده‌ی بدونِ محاسبه / محاسبه‌های بی‌اعتبار
$tab = (string) ($_GET['tab'] ?? 'dash');
$money = static fn($n): string => number_format((int) $n);
$jToG = static fn(string $j): ?string => trim($j) !== '' ? to_gregorian(normalize_digits(trim($j))) : null;
$staff = $pdo->query("SELECT id, full_name, role, mobile FROM users WHERE is_active = 1 AND role IN ('A','B','C','leader') ORDER BY role, full_name")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$unitName = ['A' => 'A', 'B' => 'B', 'C' => 'C', 'D' => 'D (سرپرست)', 'O' => 'گرد کردن'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) { flash_set('danger', 'نشست منقضی شده.'); redirect('admin_perf.php'); }
    $a = (string) ($_POST['action'] ?? '');
    if ($a === 'base_version' && perf_can('rules', $user)) {
        $d = $jToG((string) ($_POST['from_date'] ?? ''));
        $t = preg_match('/^\d{1,2}:\d{2}$/', trim(normalize_digits((string) ($_POST['from_time'] ?? '')))) ? trim(normalize_digits((string) $_POST['from_time'])) : '00:00';
        $r = $d ? ps_base_version_add($pdo, (float) normalize_digits((string) $_POST['percent']), $d . ' ' . $t . ':00', trim((string) ($_POST['note'] ?? '')), $uid) : ['ok' => false, 'message' => 'تاریخِ شروع را وارد کنید.'];
        flash_set($r['ok'] ? 'success' : 'danger', $r['message']);
        redirect('admin_perf.php?tab=base');
    }
    if ($a === 'edit_owners' && perf_can('calc_edit', $user)) {
        $oid = (int) ($_POST['order_id'] ?? 0);
        $r = ps_edit_order_owners($pdo, $oid, [
            'A' => (int) ($_POST['A'] ?? 0), 'B' => (int) ($_POST['B'] ?? 0),
            'C' => (int) ($_POST['C'] ?? 0),
            'LA' => (int) ($_POST['LA'] ?? 0), 'LB' => (int) ($_POST['LB'] ?? 0), 'LC' => (int) ($_POST['LC'] ?? 0),
        ], $uid, (string) ($_POST['reason'] ?? ''));
        flash_set($r['ok'] ? 'success' : 'danger', $r['message']);
        redirect('admin_perf.php?tab=order&order=' . $oid);
    }
    if ($a === 'payout' && perf_can('payouts', $user)) {
        $r = perf_payout_add($pdo, ['user_id' => (int) $_POST['user_id'], 'kind' => (string) $_POST['kind'], 'amount' => orders_money($_POST['amount'] ?? ''),
            'paid_at' => $jToG((string) ($_POST['paid_at'] ?? '')) ?? '', 'period_month' => trim((string) ($_POST['period_month'] ?? '')),
            'method' => trim((string) ($_POST['method'] ?? '')), 'ref' => trim((string) ($_POST['ref'] ?? '')), 'note' => trim((string) ($_POST['note'] ?? ''))], $uid);
        flash_set($r['ok'] ? 'success' : 'danger', $r['message']);
        redirect('admin_perf.php?tab=payouts');
    }
    /* ---------- ثبتِ گروهیِ پرداخت‌ها / حقوق از اکسل ---------- */
    if ($a === 'payout_import_preview' && perf_can('payouts', $user)) {
        $f = $_FILES['file'] ?? null;
        if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
            flash_set('danger', 'فایلِ اکسل انتخاب نشده یا آپلود ناموفق بود.');
            redirect('admin_perf.php?tab=payouts#bulk-import');
        }
        $err = null;
        $sheet = read_uploaded_spreadsheet($f['tmp_name'], (string) $f['name'], $err);
        if ($sheet === null) {
            flash_set('danger', $err ?: 'خواندنِ فایل ممکن نشد.');
            redirect('admin_perf.php?tab=payouts#bulk-import');
        }
        $parsed = perf_payout_import_parse($pdo, $sheet);
        if (!$parsed['rows']) {
            flash_set('warning', 'در فایل هیچ ردیفی پیدا نشد.');
            redirect('admin_perf.php?tab=payouts#bulk-import');
        }
        $_SESSION['perf_payout_import'] = ['token' => bin2hex(random_bytes(12)), 'file' => mb_substr((string) $f['name'], 0, 120),
            'rows' => $parsed['rows'], 'header_found' => $parsed['header_found'], 'at' => time(), 'by' => $uid];
        redirect('admin_perf.php?tab=payouts&import=1#bulk-import');
    }
    if ($a === 'payout_import_cancel') {
        unset($_SESSION['perf_payout_import']);
        flash_set('info', 'ثبتِ گروهی لغو شد؛ چیزی ذخیره نشد.');
        redirect('admin_perf.php?tab=payouts');
    }
    if ($a === 'payout_import_confirm' && perf_can('payouts', $user)) {
        $imp = $_SESSION['perf_payout_import'] ?? null;
        if (!$imp || !hash_equals((string) $imp['token'], (string) ($_POST['token'] ?? '')) || (int) $imp['by'] !== $uid) {
            flash_set('danger', 'پیش‌نمایشِ این فایل منقضی شده؛ دوباره آپلود کنید.');
            redirect('admin_perf.php?tab=payouts#bulk-import');
        }
        $pick = array_flip(array_map('intval', (array) ($_POST['lines'] ?? [])));
        $sel = array_values(array_filter($imp['rows'], static fn($r) => !empty($r['ok']) && isset($pick[(int) $r['line']])));
        if (!$sel) {
            flash_set('warning', 'هیچ ردیفی برای ثبت انتخاب نشده.');
            redirect('admin_perf.php?tab=payouts&import=1#bulk-import');
        }
        $r = perf_payout_import_save($pdo, $sel, $uid, 'ثبت گروهی از اکسل: ' . $imp['file']);
        if ($r['ok']) unset($_SESSION['perf_payout_import']);
        flash_set($r['ok'] ? 'success' : 'danger', $r['message']);
        redirect('admin_perf.php?tab=payouts' . ($r['ok'] ? '' : '&import=1#bulk-import'));
    }
    if ($a === 'payout_void' && perf_can('payouts', $user)) {
        $r = perf_payout_void($pdo, (int) $_POST['id'], $uid, (string) ($_POST['reason'] ?? ''));
        flash_set($r['ok'] ? 'success' : 'danger', $r['message']);
        redirect('admin_perf.php?tab=payouts');
    }
    redirect('admin_perf.php');
}

[$from, $to, $rl] = perf_range((string) ($_GET['preset'] ?? 'month'), (string) ($_GET['from'] ?? ''), (string) ($_GET['to'] ?? ''));
if ($tab === 'export_summary') {
    // همان ردیف‌ها و ستون‌های جدولِ «داشبورد و طلب‌ها»
    $earned = perf_earned($pdo, null, $from, $to);
    $paid = perf_paid($pdo, null, $from, $to);
    $bal = perf_balances($pdo);
    $rows = [];
    $tot = [0, 0, 0, 0, 0];
    foreach ($earned as $e) {
        $id = (int) $e['user_id'];
        $vals = [(int) $e['payments'], (int) $e['earned'], (int) ($paid[$id]['commission'] ?? 0), (int) ($paid[$id]['salary'] ?? 0), (int) ($bal[$id]['balance'] ?? 0)];
        foreach ($vals as $k => $v) $tot[$k] += $v;
        $rows[] = array_merge([(string) $e['full_name'], $e['role'] === 'leader' ? 'D' : (string) $e['role']], $vals);
    }
    $fj = str_replace('/', '', normalize_digits(to_jalali($from)));
    $tj = str_replace('/', '', normalize_digits(to_jalali($to)));
    xlsx_output('performance_summary_' . $fj . '_' . $tj, [[
        'name' => 'داشبورد و طلب‌ها',
        'header' => ['کارشناس', 'نقش', 'پرداخت‌ها', 'سهمِ این بازه', 'پرداختِ سهم (بازه)', 'حقوق (بازه)', 'مانده‌ی طلبِ کل'],
        'rows' => $rows,
        'footer' => $rows ? [array_merge(['جمع', ''], $tot)] : [],
        'widths' => [28, 8, 12, 18, 20, 16, 20],
    ]]);
    exit;
}
if ($tab === 'payout_template') {
    if (!perf_can('payouts', $user)) perm_deny('دسترسی ندارید.', $user);
    $cols = perf_payout_import_columns();
    $sample = [];
    foreach (array_slice($staff, 0, 2) as $i => $s) {
        $sample[] = [(string) $s['full_name'], (string) ($s['mobile'] ?? ''), $i === 0 ? 'سهم عملکرد' : 'حقوق', $i === 0 ? 15000000 : 30000000,
            normalize_digits(today_jalali()), $i === 0 ? '' : substr(normalize_digits(today_jalali()), 0, 7), 'کارت به کارت', '123456', 'نمونه — این ردیف را پاک کنید'];
    }
    if (!$sample) {
        $sample[] = ['علی محمدی', '09120000000', 'سهم عملکرد', 15000000, normalize_digits(today_jalali()), '', 'کارت به کارت', '123456', 'نمونه — این ردیف را پاک کنید'];
    }
    $staffRows = array_map(static fn($s) => [(string) $s['full_name'], (string) ($s['mobile'] ?? ''), $s['role'] === 'leader' ? 'سرپرست (D)' : (string) $s['role']], $staff);
    xlsx_output('payouts_import_template', [
        ['name' => 'پرداخت‌ها', 'header' => array_map(static fn($c) => $c[0], array_values($cols)), 'rows' => $sample,
         'widths' => [24, 16, 22, 16, 14, 16, 16, 18, 34], 'text_cols' => [1, 4, 5, 7]],
        ['name' => 'فهرست کارشناسان', 'header' => ['نام کارشناس', 'موبایل', 'نقش'], 'rows' => $staffRows, 'widths' => [28, 16, 14], 'text_cols' => [1]],
        ['name' => 'راهنما', 'header' => ['ستون', 'توضیح'], 'widths' => [24, 90], 'rows' => [
            ['نام کارشناس', 'نامِ کارشناس؛ اگر موبایل درست باشد، تطبیق با موبایل انجام می‌شود.'],
            ['موبایل', 'موبایلِ کارشناس (مثل 09121234567). مبنای اصلیِ پیدا کردنِ کارشناس است.'],
            ['نوع', '«سهم عملکرد» یا «حقوق»'],
            ['مبلغ (تومان)', 'فقط عدد، به تومان (مثل 15000000)'],
            ['تاریخ پرداخت', 'تاریخِ شمسی، مثل 1405/07/05'],
            ['بابت ماه حقوق', 'برای حقوق: سال/ماه مثل 1405/07 یا «مهر 1405». برای سهم عملکرد می‌تواند خالی باشد.'],
            ['روش پرداخت', 'مثل کارت به کارت، واریز به حساب، نقدی'],
            ['شماره پیگیری', 'شماره پیگیری / رهگیریِ بانکی'],
            ['توضیحات', 'اختیاری'],
            ['نکته', 'پیش از ثبت، پیش‌نمایشِ همه‌ی ردیف‌ها با وضعیتِ تطبیق نشان داده می‌شود؛ ردیف‌های تکراری/خطادار ثبت نمی‌شوند مگر تأییدشان کنید.'],
        ]],
    ]);
    exit;
}
if ($tab === 'export') {
    $st = $pdo->prepare("SELECT l.*, c.paid_amount, c.net_amount, c.tax_amount, c.tax_percent, c.base_percent, c.pool, o.order_number, cu.full_name AS customer, u.full_name
        FROM ps_lines l JOIN ps_payment_calcs c ON c.id = l.calc_id JOIN sales_orders o ON o.id = l.order_id LEFT JOIN customers cu ON cu.id = o.customer_id
        LEFT JOIN users u ON u.id = l.user_id WHERE l.voided = 0 AND l.pay_date BETWEEN ? AND ? ORDER BY l.pay_date, l.payment_id, l.id");
    $st->execute([$from, $to]);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="performance_share_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['تاریخ واریز', 'فاکتور', 'مشتری', 'مبلغ پرداختی', 'درصد مالیات', 'مالیات', 'خالص خدمات', 'درصد سهم پایه', 'Performance Pool', 'بخش', 'جایگاه', 'دریافت‌کننده', 'تیم', 'مبلغ', 'دلیل']);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
        [$gy, $gm, $gd] = array_map('intval', explode('-', $r['pay_date']));
        $j = gregorian_to_jalali_arr($gy, $gm, $gd);
        fputcsv($out, [sprintf('%04d%02d%02d', $j[0], $j[1], $j[2]), $r['order_number'], $r['customer'], $r['paid_amount'], (float) $r['tax_percent'], $r['tax_amount'], $r['net_amount'],
            (float) $r['base_percent'], $r['pool'], $r['unit'], ps_slot_label((string) $r['slot']), $r['user_id'] ? $r['full_name'] : PS_ORG_LABEL, $r['team_id'], $r['amount'], ps_label_fix(str_replace('→', '←', (string) $r['reason']))]);
    }
    fclose($out);
    exit;
}

$pageTitle = 'سهم عملکرد';
require_once __DIR__ . '/../includes/layout_top.php';
$tabs = ['dash' => ['داشبورد و طلب‌ها', 'fa-gauge'], 'orders' => ['محاسبه‌ی هر پرداخت', 'fa-receipt'], 'payouts' => ['پرداخت‌ها و حقوق', 'fa-money-bill-wave'],
    'base' => ['سهمِ پایه (نسخه‌ها)', 'fa-percent'], 'audit' => ['تاریخچه‌ی تغییرات', 'fa-clock-rotate-left']];
$rangeForm = static function (string $tab) use ($rl, $from, $to): string {
    $h = '<form class="d-flex gap-2 flex-wrap align-items-end mb-3"><input type="hidden" name="tab" value="' . $tab . '"><select name="preset" class="form-select form-select-sm" style="width:auto">';
    foreach (['today' => 'امروز', 'yesterday' => 'دیروز', 'week' => 'این هفته', 'month' => 'این ماه', 'last_month' => 'ماهِ قبل', 'all' => 'کلِ زمان', 'custom' => 'دلخواه'] as $k => $l) {
        $h .= '<option value="' . $k . '"' . (($_GET['preset'] ?? 'month') === $k ? ' selected' : '') . '>' . $l . '</option>';
    }
    return $h . '</select><input name="from" class="form-control form-control-sm jalali-date" style="width:120px" placeholder="از" value="' . e((string) ($_GET['from'] ?? '')) . '">'
        . '<input name="to" class="form-control form-control-sm jalali-date" style="width:120px" placeholder="تا" value="' . e((string) ($_GET['to'] ?? '')) . '">'
        . '<button class="btn btn-sm btn-dark">نمایش</button><span class="small text-muted">' . e($rl) . ': ' . to_jalali($from) . ' تا ' . to_jalali($to) . ' (تاریخِ واریز)</span></form>';
};
?>
<style>.pf .card{border-radius:14px;border:1px solid #e7e2d3}.pf .stat{padding:12px;border:1px solid #eee;border-radius:12px;background:#fff;height:100%}.pf .stat .v{font-weight:800}.pf .nav-pills .nav-link{border-radius:10px;color:#44403c}.pf .nav-pills .nav-link.active{background:#1c1917;color:#fff}</style>
<div class="pf">
  <h4 class="fw-bold mb-1"><i class="fa-solid fa-trophy text-warning"></i> سهم عملکرد</h4>
  <div class="small text-muted mb-3">هر پرداختِ تأییدشده: پرداخت ÷ (۱ + مالیات) = خالص ← × سهمِ پایه = Pool ← A/B/C/D هر کدام ۲۵٪ ← تقسیمِ داخلی ← جایگاهِ خالی = <?= e(PS_ORG_LABEL) ?>.
    <?= $synced ? ' <span class="badge text-bg-info">' . to_persian_digits((string) $synced) . ' سفارش همگام شد</span>' : '' ?></div>
  <ul class="nav nav-pills gap-1 mb-3 flex-wrap"><?php foreach ($tabs as $k => [$l, $ic]): ?><li class="nav-item"><a class="nav-link py-1 px-3 small <?= $tab === $k ? 'active' : '' ?>" href="?tab=<?= $k ?>"><i class="fa-solid <?= $ic ?>"></i> <?= $l ?></a></li><?php endforeach; ?>
    <?php if ($tab === 'order'): ?><li class="nav-item"><span class="nav-link py-1 px-3 small active"><i class="fa-solid fa-magnifying-glass"></i> جزئیاتِ سفارش</span></li><?php endif; ?>
    <?php if ($tab === 'user'): ?><li class="nav-item"><span class="nav-link py-1 px-3 small active"><i class="fa-solid fa-user"></i> ریزِ سهمِ کارشناس</span></li><?php endif; ?></ul>

<?php if ($tab === 'dash'):
    echo $rangeForm('dash');
    $st = $pdo->prepare('SELECT COUNT(*) n, COALESCE(SUM(paid_amount),0) paid, COALESCE(SUM(net_amount),0) net, COALESCE(SUM(pool),0) pool, COALESCE(SUM(distributed),0) dist, COALESCE(SUM(org_total),0) org FROM ps_payment_calcs WHERE voided_at IS NULL AND pay_date BETWEEN ? AND ?');
    $st->execute([$from, $to]);
    $T = $st->fetch(PDO::FETCH_ASSOC);
    $st = $pdo->prepare('SELECT unit, SUM(CASE WHEN user_id IS NULL THEN amount ELSE 0 END) org, SUM(CASE WHEN user_id IS NOT NULL THEN amount ELSE 0 END) ppl FROM ps_lines WHERE voided = 0 AND pay_date BETWEEN ? AND ? GROUP BY unit');
    $st->execute([$from, $to]);
    $U = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) $U[$r['unit']] = $r;
    $earned = perf_earned($pdo, null, $from, $to);
    $paid = perf_paid($pdo, null, $from, $to);
    $bal = perf_balances($pdo);
?>
  <div class="row g-2 mb-3">
    <?php foreach ([['پرداخت‌های محاسبه‌شده', to_persian_digits((string) $T['n'])], ['مبلغِ پرداختی', $money($T['paid'])], ['خالصِ خدمات', $money($T['net'])], ['Performance Pool', $money($T['pool'])],
        ['سهمِ کارشناسان', $money($T['dist'])], [PS_ORG_LABEL, $money($T['org'])]] as [$l, $v]): ?>
      <div class="col-6 col-md-4 col-xl-2"><div class="stat"><div class="small text-muted"><?= e($l) ?></div><div class="v"><?= $v ?></div></div></div>
    <?php endforeach; ?>
    <?php foreach (['A', 'B', 'C', 'D'] as $u): ?>
      <div class="col-6 col-md-3"><div class="stat"><div class="small text-muted">سهمِ <?= $unitName[$u] ?></div><div class="v"><?= $money($U[$u]['ppl'] ?? 0) ?></div><div class="small text-muted">به سازمان: <?= $money($U[$u]['org'] ?? 0) ?></div></div></div>
    <?php endforeach; ?>
  </div>
  <div class="d-flex justify-content-end gap-2 mb-2">
    <a class="btn btn-sm btn-success" href="?<?= e(http_build_query(['tab' => 'export_summary'] + $_GET)) ?>" title="دقیقاً همین جدولِ پایین (هر کارشناس یک ردیف)"><i class="fa-solid fa-file-excel"></i> اکسلِ همین جدول</a>
    <a class="btn btn-sm btn-outline-success" href="?<?= e(http_build_query(['tab' => 'export'] + $_GET)) ?>"><i class="fa-solid fa-file-excel"></i> اکسلِ ریزِ سهم‌ها</a>
  </div>
  <div class="card p-0 mb-3"><div class="table-responsive"><table class="table table-sm small mb-0">
    <thead class="table-light"><tr><th>کارشناس</th><th>نقش</th><th>پرداخت‌ها</th><th>سهمِ این بازه</th><th>پرداختِ سهم (بازه)</th><th>حقوق (بازه)</th><th title="جمعِ سهم‌های قطعی − پرداخت‌شده؛ مبلغی که شرکت به کارشناس بدهکار است">مانده‌ی طلبِ کارشناس از شرکت</th></tr></thead><tbody>
    <?php foreach ($earned as $e): $id = (int) $e['user_id']; ?>
      <tr><td><a href="?<?= e(http_build_query(array_merge($_GET, ['tab' => 'user', 'user' => $id]))) ?>" class="text-decoration-none fw-bold" title="ریزِ سهم‌های این کارشناس"><?= e($e['full_name']) ?> <i class="fa-solid fa-magnifying-glass small"></i></a></td><td><?= e($e['role'] === 'leader' ? 'D' : $e['role']) ?></td><td><?= to_persian_digits((string) $e['payments']) ?></td><td class="fw-bold"><?= $money($e['earned']) ?></td>
        <td class="text-success"><?= $money($paid[$id]['commission'] ?? 0) ?></td><td><?= $money($paid[$id]['salary'] ?? 0) ?></td><td class="fw-bold <?= ($bal[$id]['balance'] ?? 0) > 0 ? 'text-danger' : '' ?>"><?= $money($bal[$id]['balance'] ?? 0) ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$earned): ?><tr><td colspan="7" class="text-center text-muted py-3">در این بازه سهمی محاسبه نشده.</td></tr><?php endif; ?>
    </tbody></table></div></div>

<?php elseif ($tab === 'user'):
    // ریزِ سهم‌های یک کارشناس (همه‌ی خطوطِ قطعی، با سفارش، جایگاه و دلیل) — برای اینکه معلوم شود «مانده‌ی طلب» از کجا آمده
    $uid = (int) ($_GET['user'] ?? 0);
    $uRow = ps_user_row($pdo, $uid);
    $all = !empty($_GET['all']);
    $st = $pdo->prepare('SELECT l.*, o.order_number, o.customer_id, c.full_name AS customer_name FROM ps_lines l
        LEFT JOIN sales_orders o ON o.id = l.order_id LEFT JOIN customers c ON c.id = o.customer_id
        WHERE l.voided = 0 AND l.user_id = ?' . ($all ? '' : ' AND l.pay_date BETWEEN ? AND ?') . ' ORDER BY l.pay_date DESC, l.id DESC');
    $st->execute($all ? [$uid] : [$uid, $from, $to]);
    $uLines = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $uBal = perf_balances($pdo, $uid)[$uid] ?? ['earned' => 0, 'paid' => 0, 'balance' => 0];
    $sumShown = array_sum(array_map(static fn($x) => (int) $x['amount'], $uLines));
?>
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
    <h6 class="fw-bold mb-0"><i class="fa-solid fa-user"></i> ریزِ سهم‌های <?= e((string) ($uRow['full_name'] ?? ('#' . $uid))) ?> <span class="badge text-bg-light border"><?= e((string) ($uRow['role'] ?? '')) ?></span></h6>
    <div class="d-flex gap-2">
      <a class="btn btn-sm <?= $all ? 'btn-outline-secondary' : 'btn-dark' ?>" href="?<?= e(http_build_query(array_merge($_GET, ['all' => 0]))) ?>">همین بازه</a>
      <a class="btn btn-sm <?= $all ? 'btn-dark' : 'btn-outline-secondary' ?>" href="?<?= e(http_build_query(array_merge($_GET, ['all' => 1]))) ?>">از ابتدا (همه)</a>
      <a class="btn btn-sm btn-outline-secondary" href="?tab=dash">← داشبورد</a>
    </div>
  </div>
  <div class="row g-2 mb-3">
    <div class="col-6 col-md-3"><div class="card p-2"><div class="small text-muted">جمعِ سهم‌های قطعیِ کل</div><div class="fw-bold"><?= $money($uBal['earned']) ?></div></div></div>
    <div class="col-6 col-md-3"><div class="card p-2"><div class="small text-muted">پرداخت‌شده به او (کل)</div><div class="fw-bold text-success"><?= $money($uBal['paid']) ?></div></div></div>
    <div class="col-6 col-md-3"><div class="card p-2"><div class="small text-muted">مانده‌ی طلبِ او از شرکت</div><div class="fw-bold text-danger"><?= $money($uBal['balance']) ?></div></div></div>
    <div class="col-6 col-md-3"><div class="card p-2"><div class="small text-muted">جمعِ ردیف‌های زیر</div><div class="fw-bold"><?= $money($sumShown) ?></div></div></div>
  </div>
  <div class="small text-muted mb-2">«مانده‌ی طلب» = جمعِ سهم‌های قطعی − مبالغی که بابتِ سهم به او پرداخت شده؛ یعنی شرکت این مبلغ را به کارشناس بدهکار است (نه برعکس). سهم‌های باطل‌شده در این جمع نیستند.</div>
  <div class="card p-0 mb-3"><div class="table-responsive"><table class="table table-sm small align-middle mb-0">
    <thead class="table-light"><tr><th>تاریخِ پرداخت</th><th>سفارش</th><th>مشتری</th><th>جایگاه</th><th>مبلغ</th><th>دلیل</th></tr></thead><tbody>
    <?php foreach ($uLines as $l): ?>
      <tr><td class="text-nowrap"><?= to_jalali((string) $l['pay_date']) ?></td>
        <td><a href="?tab=order&amp;order=<?= (int) $l['order_id'] ?>"><?= e(to_persian_digits((string) $l['order_number'])) ?></a></td>
        <td><?php if ($l['customer_id']): ?><a href="../customer_profile.php?id=<?= (int) $l['customer_id'] ?>" target="_blank"><?= e((string) $l['customer_name']) ?></a><?php endif; ?></td>
        <td><b><?= e(ps_slot_label((string) $l['slot'])) ?></b></td>
        <td class="fw-bold"><?= $money($l['amount']) ?></td>
        <td class="text-muted"><?= e(ps_label_fix(str_replace('→', '←', (string) $l['reason']))) ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$uLines): ?><tr><td colspan="6" class="text-center text-muted py-3">سهمِ قطعی‌ای نیست.</td></tr><?php endif; ?>
    </tbody></table></div></div>

<?php elseif ($tab === 'orders'):
    echo $rangeForm('orders');
    $q = trim(normalize_digits((string) ($_GET['q'] ?? '')));
    $qSql = ''; $qP = [];
    if ($q !== '') { $qSql = ' AND (o.order_number LIKE ? OR cu.full_name LIKE ? OR cu.mobile LIKE ?)'; $qP = ['%' . $q . '%', '%' . $q . '%', '%' . $q . '%']; }
    $st = $pdo->prepare('SELECT c.*, o.order_number, o.customer_id, cu.full_name AS customer, r.id AS rec_id, r.full_name AS rec_name, r.role AS rec_role,
            (SELECT COALESCE(SUM(l.amount),0) FROM ps_lines l WHERE l.calc_id = c.id AND l.user_id = r.id) AS rec_share
        FROM ps_payment_calcs c JOIN sales_orders o ON o.id = c.order_id LEFT JOIN customers cu ON cu.id = o.customer_id
        LEFT JOIN sales_order_payments p ON p.id = c.payment_id LEFT JOIN users r ON r.id = COALESCE(p.recorded_by, o.seller_user_id)
        WHERE c.pay_date BETWEEN ? AND ?' . $qSql . ' ORDER BY c.pay_date DESC, c.id DESC LIMIT 500');
    $st->execute(array_merge([$from, $to], $qP));
?>
  <form class="d-flex gap-2 mb-2"><input type="hidden" name="tab" value="orders"><?php foreach (['preset', 'from', 'to'] as $k): if (isset($_GET[$k])): ?><input type="hidden" name="<?= $k ?>" value="<?= e((string) $_GET[$k]) ?>"><?php endif; endforeach; ?>
    <input name="q" class="form-control form-control-sm" style="max-width:320px" placeholder="جستجو: شماره فاکتور، نام یا موبایلِ مشتری" value="<?= e($q) ?>"><button class="btn btn-sm btn-outline-dark">جستجو</button></form>
  <div class="small text-muted mb-2">روی نامِ مشتری بزنید تا پروفایلِ ۳۶۰ او باز شود. روی شماره‌ی فاکتور بزنید تا تعیین‌تکلیفِ هر پرداخت (دلیلِ هر مبلغ) را ببینید<?= perf_can('calc_edit', $user) ? ' و در صورتِ نیاز افرادِ دریافت‌کننده را ویرایش کنید' : '' ?>. ردیف‌های خط‌خورده محاسبه‌های باطل‌شده‌اند (در سابقه می‌مانند).</div>
  <div class="card p-0"><div class="table-responsive"><table class="table table-sm small mb-0">
    <thead class="table-light"><tr><th>تاریخِ واریز</th><th>فاکتور</th><th>مشتری</th><th>ثبت‌کننده‌ی فیش / سهمش</th><th>پرداختی</th><th>مالیات</th><th>خالص</th><th>سهمِ پایه</th><th>Pool</th><th>کارشناسان</th><th>سازمان</th><th>وضعیت</th></tr></thead><tbody>
    <?php foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $c): ?>
      <tr class="<?= $c['voided_at'] ? 'text-muted text-decoration-line-through' : '' ?>"><td><?= to_jalali($c['pay_date']) ?></td>
        <td><a href="?tab=order&amp;order=<?= (int) $c['order_id'] ?>"><?= e(to_persian_digits((string) $c['order_number'])) ?></a></td>
        <td><?php if (!empty($c['customer_id'])): ?><a href="../customer_profile.php?id=<?= (int) $c['customer_id'] ?>" target="_blank" title="پروفایل ۳۶۰ مشتری"><?= e((string) $c['customer']) ?> <i class="fa-solid fa-up-right-from-square small text-muted"></i></a><?php else: ?><?= e((string) $c['customer']) ?><?php endif; ?></td>
        <td class="text-nowrap"><?= e((string) ($c['rec_name'] ?? '—')) ?> <span class="text-muted">(<?= e(($c['rec_role'] ?? '') === 'leader' ? 'سرپرست' : (in_array($c['rec_role'] ?? '', ['A', 'B', 'C'], true) ? $c['rec_role'] : 'مدیر/مالی')) ?>)</span>
          <div class="<?= (int) $c['rec_share'] > 0 ? 'text-success fw-bold' : 'text-danger' ?>"><?= (int) $c['rec_share'] > 0 ? $money($c['rec_share']) : (in_array($c['rec_role'] ?? '', ['A', 'B', 'C', 'leader'], true) ? 'بدونِ سهم!' : 'بدونِ جایگاه') ?></div></td>
        <td><?= $money($c['paid_amount']) ?></td><td><?= number_format((float) $c['tax_amount'], 2) ?> <span class="text-muted">(<?= (float) $c['tax_percent'] ?>٪)</span></td>
        <td><?= number_format((float) $c['net_amount'], 2) ?></td><td><?= (float) $c['base_percent'] ?>٪</td><td class="fw-bold"><?= $money($c['pool']) ?></td>
        <td><?= $money($c['distributed']) ?></td><td><?= $money($c['org_total']) ?></td><td><?= $c['voided_at'] ? '<span class="badge text-bg-secondary" title="' . e((string) $c['void_reason']) . '">باطل</span>' : '<span class="badge text-bg-success">قطعی</span>' ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div></div>

<?php elseif ($tab === 'order'):
    $oid = (int) ($_GET['order'] ?? 0);
    $pbBase = '../';
    require __DIR__ . '/../includes/perf_order_breakdown.php';
    if (perf_can('calc_edit', $user) && $oid):
        $sn = $pdo->prepare('SELECT owners_json FROM ps_order_snapshots WHERE order_id = ?');
        $sn->execute([$oid]);
        $ow = json_decode((string) ($sn->fetchColumn() ?: '{}'), true) ?: [];
        $bSel = 0;
        foreach ((array) ($ow['B'] ?? []) as $b) { if (!$bSel || (int) ($b['position'] ?? 1) === 1) $bSel = (int) $b['user_id']; }
        $byRole = ['A' => [], 'B' => [], 'C' => [], 'leader' => []];
        foreach ($pdo->query("SELECT id, full_name, role, team_id, mobile FROM users WHERE is_active = 1 AND role IN ('A','B','C','leader') ORDER BY full_name") as $u) $byRole[$u['role']][] = $u;
        $opt = static function (array $list, int $sel, string $empty) {
            $h = '<option value="0">' . e($empty) . '</option>';
            foreach ($list as $u) $h .= '<option value="' . (int) $u['id'] . '"' . ((int) $u['id'] === $sel ? ' selected' : '') . '>' . e(person_pick_label((string) $u['full_name'], $u['mobile'] ?? null, $u['team_id'] ? 'تیم ' . $u['team_id'] : 'بدون تیم')) . '</option>';
            return $h;
        };
?>
  <div class="card p-3 mb-3" style="border-color:#fca5a5">
    <h6 class="fw-bold mb-1"><i class="fa-solid fa-pen-to-square text-danger"></i> ویرایشِ دریافت‌کنندگانِ سهمِ این سفارش</h6>
    <div class="small text-muted mb-2">مثلاً وقتی چند کارشناس یک فیش را ثبت کرده‌اند یا مالکیت اشتباه بوده. با ذخیره، محاسبه‌های فعلی <b>باطل</b> (در سابقه می‌مانند) و همه‌ی پرداخت‌های تأییدشده‌ی این سفارش با افرادِ جدید دوباره حساب می‌شوند. درصدِ سهمِ پایه و مالیات تغییر نمی‌کند. «خالی» = سهمِ آن جایگاه به سازمان. افرادِ انتخاب‌شده در «مالکیتِ مشتری» (پروفایلِ ۳۶۰) هم جایگزین می‌شوند.</div>
    <form method="post" class="row g-2" onsubmit="return confirm('سهمِ این سفارش با افرادِ جدید دوباره محاسبه شود؟');"><?= csrf_field() ?>
      <input type="hidden" name="action" value="edit_owners"><input type="hidden" name="order_id" value="<?= $oid ?>">
      <div class="col-md-4"><label class="form-label small mb-1">A</label><select name="A" class="form-select form-select-sm" data-search><?= $opt($byRole['A'], (int) ($ow['A']['user_id'] ?? 0), '— خالی —') ?></select></div>
      <div class="col-md-4"><label class="form-label small mb-1">B</label><select name="B" class="form-select form-select-sm" data-search><?= $opt($byRole['B'], $bSel, '— خالی —') ?></select></div>
      <div class="col-md-4"><label class="form-label small mb-1">C</label><select name="C" class="form-select form-select-sm" data-search><?= $opt($byRole['C'], (int) ($ow['C']['user_id'] ?? 0), '— خالی —') ?></select></div>
      <?php $bRow = null; foreach ((array) ($ow['B'] ?? []) as $b) { if ((int) $b['user_id'] === $bSel) $bRow = $b; } ?>
      <div class="col-md-4"><label class="form-label small mb-1">سرپرستِ A (سهمِ D(A))</label><select name="LA" class="form-select form-select-sm" data-search><?= $opt($byRole['leader'], (int) ($ow['A']['leader_id'] ?? 0), '— سرپرستِ تیمِ A (خودکار) —') ?></select></div>
      <div class="col-md-4"><label class="form-label small mb-1">سرپرستِ B (سهمِ D(B))</label><select name="LB" class="form-select form-select-sm" data-search><?= $opt($byRole['leader'], (int) ($bRow['leader_id'] ?? 0), '— سرپرستِ تیمِ B (خودکار) —') ?></select></div>
      <div class="col-md-4"><label class="form-label small mb-1">سرپرستِ C (سهمِ D(C))</label><select name="LC" class="form-select form-select-sm" data-search><?= $opt($byRole['leader'], (int) ($ow['C']['leader_id'] ?? 0), '— سرپرستِ تیمِ C (خودکار) —') ?></select></div>
      <div class="col-12 form-text mt-0">سهمِ D سه قسمت است: هر جایگاه یک قسمت برای سرپرستِ همان جایگاه. اگر جایگاه خالی باشد، قسمتِ آن به سازمان می‌رسد (سرپرستش هم حساب نمی‌شود). سرپرست فقط از سهمِ اعضای تیمش سهم می‌برد، نه از ثبتِ فیش.</div>
      <div class="col-md-9"><input name="reason" class="form-control form-control-sm" required minlength="5" placeholder="دلیلِ ویرایش (الزامی) — مثلاً: فیش را کارشناسِ دیگری ثبت کرده بود"></div>
      <div class="col-md-3"><button class="btn btn-sm btn-danger w-100">ذخیره و محاسبه‌ی مجدد</button></div>
    </form>
  </div>
<?php endif; ?>

<?php elseif ($tab === 'payouts'):
    $list = $pdo->query('SELECT p.*, u.full_name, c.full_name AS creator FROM perf_payouts p JOIN users u ON u.id = p.user_id LEFT JOIN users c ON c.id = p.created_by ORDER BY p.paid_at DESC, p.id DESC LIMIT 300')->fetchAll(PDO::FETCH_ASSOC) ?: [];
?>
  <?php if (perf_can('payouts', $user)): ?>
  <form method="post" class="card p-3 mb-3 row g-2 flex-row"><?= csrf_field() ?><input type="hidden" name="action" value="payout">
    <div class="col-md-3"><label class="form-label small mb-1">کارشناس</label><select name="user_id" class="form-select form-select-sm" required data-search><option value="">انتخاب</option><?php foreach ($staff as $s): ?><option value="<?= (int) $s['id'] ?>"><?= e(person_pick_label((string) $s['full_name'], $s['mobile'] ?? null, $s['role'] === 'leader' ? 'D' : $s['role'])) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-2"><label class="form-label small mb-1">نوع</label><select name="kind" class="form-select form-select-sm"><option value="commission">سهمِ عملکرد</option><option value="salary">حقوق</option></select></div>
    <div class="col-md-2"><label class="form-label small mb-1">مبلغ</label><input name="amount" class="form-control form-control-sm" dir="ltr" required></div>
    <div class="col-md-2"><label class="form-label small mb-1">تاریخ</label><input name="paid_at" class="form-control form-control-sm jalali-date" value="<?= e(today_jalali()) ?>"></div>
    <div class="col-md-3"><label class="form-label small mb-1">بابتِ ماه (حقوق)</label><input name="period_month" class="form-control form-control-sm" placeholder="۱۴۰۵/۰۷"></div>
    <div class="col-md-3"><input name="method" class="form-control form-control-sm" placeholder="روش"></div><div class="col-md-3"><input name="ref" class="form-control form-control-sm" dir="ltr" placeholder="شماره پیگیری"></div>
    <div class="col-md-4"><input name="note" class="form-control form-control-sm" placeholder="توضیح"></div><div class="col-md-2"><button class="btn btn-sm btn-success w-100">ثبت</button></div>
  </form>
  <?php require __DIR__ . '/../includes/perf_payout_import_panel.php'; ?>
  <?php endif; ?>
  <div class="card p-0"><div class="table-responsive"><table class="table table-sm small mb-0"><thead class="table-light"><tr><th>تاریخ</th><th>کارشناس</th><th>نوع</th><th>مبلغ</th><th>بابت</th><th>روش/پیگیری</th><th>توضیح</th><th>ثبت</th><th></th></tr></thead><tbody>
    <?php foreach ($list as $p): ?>
      <tr class="<?= $p['voided_at'] ? 'text-decoration-line-through text-muted' : '' ?>"><td><?= to_jalali($p['paid_at']) ?></td><td><?= e($p['full_name']) ?></td><td><?= $p['kind'] === 'salary' ? 'حقوق' : 'سهمِ عملکرد' ?></td><td class="fw-bold"><?= $money($p['amount']) ?></td>
        <td><?= e((string) $p['period_month']) ?></td><td><?= e(trim($p['method'] . ' ' . $p['ref'])) ?></td><td><?= e((string) $p['note']) ?></td><td><?= e((string) $p['creator']) ?></td>
        <td><?php if (!$p['voided_at'] && perf_can('payouts', $user)): ?><form method="post" onsubmit="var r=prompt('دلیلِ ابطال:'); if(!r) return false; this.reason.value=r; return true;"><?= csrf_field() ?><input type="hidden" name="action" value="payout_void"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>"><input type="hidden" name="reason"><button class="btn btn-sm btn-outline-danger py-0">ابطال</button></form><?php endif; ?></td></tr>
    <?php endforeach; ?>
  </tbody></table></div></div>

<?php elseif ($tab === 'base'):
    $vers = $pdo->query('SELECT v.*, u.full_name, (SELECT COUNT(*) FROM ps_order_snapshots s WHERE s.base_version_id = v.id) AS orders_cnt FROM ps_base_versions v LEFT JOIN users u ON u.id = v.created_by ORDER BY v.effective_from DESC, v.id DESC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $cur = ps_base_version_at($pdo, date('Y-m-d H:i:s'));
?>
  <div class="alert alert-info small">سهمِ پایه‌ی فعلی: <b><?= (float) $cur['percent'] ?>٪</b>. هر تغییر یک <b>نسخه‌ی جدید</b> برای آینده می‌سازد (ویرایشِ نسخه‌ی قدیمی ممکن نیست). هر سفارش با نسخه‌ی معتبر در <b>لحظه‌ی ثبتِ سفارش</b> snapshot می‌شود و بعداً هرگز تغییر نمی‌کند.
    مالیات از <b>درصدِ مالیاتِ خودِ فاکتور</b> خوانده و در snapshot ذخیره می‌شود (تنظیمِ درصدِ مالیات در «تنظیمات مالی»؛ تغییرِ آن فقط روی پیش‌فاکتورهای بعدی اثر دارد).</div>
  <?php if (perf_can('rules', $user)): ?>
  <form method="post" class="card p-3 mb-3 d-flex flex-row flex-wrap gap-2 align-items-end"><?= csrf_field() ?><input type="hidden" name="action" value="base_version">
    <div><label class="form-label small mb-1">درصدِ جدید</label><input name="percent" class="form-control form-control-sm" style="width:90px" dir="ltr" required></div>
    <div><label class="form-label small mb-1">شروعِ اعتبار (تاریخ)</label><input name="from_date" class="form-control form-control-sm jalali-date" style="width:130px" value="<?= e(today_jalali()) ?>" required></div>
    <div><label class="form-label small mb-1">ساعت</label><input name="from_time" class="form-control form-control-sm" style="width:80px" dir="ltr" value="<?= date('H:i', time() + 300) ?>"></div>
    <div class="flex-grow-1"><label class="form-label small mb-1">توضیح</label><input name="note" class="form-control form-control-sm"></div>
    <button class="btn btn-sm btn-primary" onclick="return confirm('نسخه‌ی جدیدِ سهمِ پایه ثبت شود؟ سفارش‌های قبلی تغییر نمی‌کنند.')">ثبتِ نسخه‌ی جدید</button>
  </form>
  <?php endif; ?>
  <div class="card p-0"><table class="table table-sm small mb-0"><thead class="table-light"><tr><th>نسخه</th><th>درصد</th><th>شروعِ اعتبار</th><th>سفارش‌های محاسبه‌شده با این نسخه</th><th>ثبت‌کننده</th><th>توضیح</th></tr></thead><tbody>
    <?php foreach ($vers as $v): ?><tr><td>#<?= (int) $v['id'] ?></td><td class="fw-bold"><?= (float) $v['percent'] ?>٪</td><td><?= to_jalali(substr($v['effective_from'], 0, 10)) ?> <?= e(substr($v['effective_from'], 11, 5)) ?></td><td><?= to_persian_digits((string) $v['orders_cnt']) ?></td><td><?= e((string) ($v['full_name'] ?? 'سیستم')) ?></td><td><?= e((string) $v['note']) ?></td></tr><?php endforeach; ?>
  </tbody></table></div>

<?php elseif ($tab === 'audit'):
    $list = $pdo->query('SELECT a.*, u.full_name FROM perf_audit a LEFT JOIN users u ON u.id = a.user_id ORDER BY a.id DESC LIMIT 300')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $al = ['calc' => 'محاسبه‌ی پرداخت', 'calc_void' => 'ابطالِ محاسبه', 'order_snapshot' => 'snapshotِ سفارش', 'base_share_version' => 'نسخه‌ی سهمِ پایه', 'owner_add' => 'ثبتِ جایگاه', 'owner_remove' => 'حذفِ جایگاه',
        'box_enter' => 'ورود به Box', 'box_claim' => 'دریافت از Box', 'c_from_payment' => 'C از دریافتِ پول', 'manual_share_edit' => 'ویرایشِ دستیِ سهم', 'box_cancel' => 'خروج از Box', 'payout_add' => 'ثبتِ پرداخت', 'payout_void' => 'ابطالِ پرداخت'];
?>
  <div class="card p-0"><div class="table-responsive"><table class="table table-sm small mb-0"><thead class="table-light"><tr><th>زمان</th><th>کاربر</th><th>IP</th><th>عملیات</th><th>مورد</th><th>دلیل</th><th>قبل ← بعد</th></tr></thead><tbody>
    <?php foreach ($list as $a): ?><tr><td class="text-nowrap"><?= to_jalali(substr($a['created_at'], 0, 10)) ?> <?= e(substr($a['created_at'], 11, 5)) ?></td><td><?= e((string) ($a['full_name'] ?? 'سیستم')) ?></td><td dir="ltr"><?= e((string) ($a['ip'] ?? '')) ?></td>
      <td><?= e($al[$a['action']] ?? $a['action']) ?></td><td dir="ltr"><?= e($a['entity'] . ' #' . $a['entity_id']) ?></td><td><?= e((string) $a['reason']) ?></td>
      <td><details><summary class="text-primary">نمایش</summary><div dir="ltr" style="max-width:520px;white-space:pre-wrap;font-size:11px"><?= e((string) $a['old_json']) ?> ← <?= e((string) $a['new_json']) ?></div></details></td></tr><?php endforeach; ?>
  </tbody></table></div></div>
<?php endif; ?>
</div>
<?php require_once __DIR__ . '/../includes/layout_bottom.php'; ?>
