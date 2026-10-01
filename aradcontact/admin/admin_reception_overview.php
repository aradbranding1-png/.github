<?php
/**
 * داشبوردِ مدیریتیِ واحدِ استخدام (پذیرش) — کلِ مسیر در یک صفحه، از یک منبعِ داده (includes/reception_metrics.php):
 *   ورود شماره → اختصاص → تماس → تماسِ موفق → دعوت → جلسه‌ی آنلاین / حضوری → حاضر / غایب → پیگیری مجدد → تعیین تکلیف → ارجاع به سرپرست
 * هر عدد قابلِ کلیک است و افرادِ همان عدد را نشان می‌دهد؛ جلسه‌ها با «جلسه‌ی بدونِ آمار (احتمالاً جلسه‌رونده حاضر نبوده)».
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/reception_functions.php';
require_once __DIR__ . '/../includes/xlsx_writer.php';
$admin = require_login();
if (!perm_page_allowed($admin)) {
    perm_deny('', $admin);
}
$pdo = db();
$ready = rp_ready($pdo) && rx_ready($pdo);
if ($ready) {
    rp_sync($pdo, 3000);
}

$preset = (string) ($_GET['preset'] ?? 'today');
if (!isset(rm_presets()[$preset])) $preset = 'today';
[$from, $to, $rangeLabel] = rm_range($preset, (string) ($_GET['from'] ?? ''), (string) ($_GET['to'] ?? ''));
$agentId = (int) ($_GET['agent'] ?? 0);
$metric = (string) ($_GET['m'] ?? '');
$agents = $ready ? rp_agents($pdo) : [];

$ov = $ready ? rm_overview($pdo, $from, $to, $agentId) : ['total' => [], 'per_agent' => []];
$sessions = $ready ? rm_sessions($pdo, $from, $to, 0, $agentId) : [];
$ss = rm_session_summary($sessions);
$refs = $ready ? rm_referrals($pdo, $from, $to, $agentId) : [];
$T = $ov['total'];
$steps = rm_steps();

// ریزِ یک عدد (کلیک)
$drill = null;
if ($ready && $metric !== '') {
    $dAgent = (int) ($_GET['ag'] ?? $agentId);
    $drill = rm_people($pdo, $metric, $from, $to, $dAgent, ['session' => (string) ($_GET['sess'] ?? ''), 'sup' => (int) ($_GET['sup'] ?? 0)]);
    if ($metric === 'host_absent') {
        $drill = ['title' => 'جلسه‌های بدونِ آمار (احتمالاً جلسه‌رونده حاضر نبوده)', 'rows' => [], 'sessions' => array_values(array_filter($sessions, static fn($s) => $s['state'] === 'host_absent'))];
    }
    $drill['agent_name'] = $dAgent > 0 ? (rm_agent_names($pdo)[$dAgent] ?? '') : '';
}

// ─── خروجیِ اکسل: همه‌ی جدول‌های همین صفحه (+ ریزِ عددِ بازشده) ───
if ($ready && isset($_GET['export'])) {
    $sheets = [];
    $fRows = [];
    foreach ($steps as $k => $st) $fRows[] = [$st['label'], (int) ($T[$k] ?? 0)];
    $fRows[] = ['دعوت به میتینگ آنلاین', (int) $T['inv_online']];
    $fRows[] = ['دعوت به مصاحبه‌ی حضوری', (int) $T['inv_inperson']];
    $fRows[] = ['جلسه بدونِ ثبتِ حضور/غیاب', (int) $T['pending']];
    $fRows[] = ['نرخِ حضور (٪)', $T['rate'] ?? ''];
    $sheets[] = ['name' => 'قیف', 'header' => ['مرحله', 'تعداد'], 'rows' => $fRows, 'widths' => [34, 14]];
    $aRows = [];
    foreach ($ov['per_agent'] as $r) {
        $aRows[] = [$r['name'], $r['assigned'], $r['calls'], $r['logged'], $r['success'], $r['invited'], $r['met_online'], $r['met_inperson'], $r['present'], $r['absent'], $r['pending'], $r['recall'], $r['closed'], $r['joined'], $r['referred']];
    }
    $sheets[] = ['name' => 'کارشناسان', 'header' => ['کارشناس', 'اختصاص', 'تماس (کالیزر)', 'تماسِ ثبت‌شده', 'تماسِ موفق', 'دعوت', 'جلسه آنلاین', 'جلسه حضوری', 'حاضر', 'غایب', 'ثبت‌نشده', 'پیگیری مجدد', 'تعیین تکلیف', 'پیوست', 'ارجاع به سرپرست'],
        'rows' => $aRows, 'widths' => [24, 10, 12, 12, 10, 10, 11, 11, 9, 9, 10, 11, 11, 9, 13]];
    $sRows = [];
    foreach ($sessions as $s) {
        $sRows[] = [(int) str_replace('/', '', normalize_digits(to_jalali($s['d']))), $s['t'], $s['kind'] === 'online' ? 'آنلاین' : 'حضوری', $s['host_name'], $s['cap'] ?? '', $s['invited'], $s['att'], $s['ns'], $s['pending'],
            rm_session_states()[$s['state']]['label'], $s['checkin'] ? substr((string) $s['checkin'], 11, 5) : '', $s['referred']];
    }
    $sheets[] = ['name' => 'جلسه‌ها', 'header' => ['تاریخ', 'ساعت', 'نوع', 'جلسه‌رونده', 'ظرفیت', 'دعوت‌شده', 'حاضر', 'غایب', 'ثبت‌نشده', 'وضعیت', 'اعلامِ حضور', 'ارجاع به سرپرست'],
        'rows' => $sRows, 'widths' => [11, 8, 9, 22, 8, 10, 8, 8, 10, 40, 11, 13], 'text_cols' => [1]];
    $hRows = [];
    foreach ($ss['hosts'] as $h) {
        $d = $h['att'] + $h['ns'];
        $hRows[] = [$h['name'], $h['sessions'], $h['held'], $h['host_absent'], $h['invited'], $h['att'], $h['ns'], $h['pending'], $d ? round($h['att'] / $d * 100, 1) : '', $h['referred']];
    }
    $sheets[] = ['name' => 'جلسه‌رونده‌ها', 'header' => ['جلسه‌رونده', 'جلسه', 'برگزارشده', 'بدونِ آمار', 'دعوت‌شده', 'حاضر', 'غایب', 'ثبت‌نشده', 'نرخ حضور (٪)', 'ارجاع به سرپرست'],
        'rows' => $hRows, 'widths' => [24, 8, 10, 10, 10, 8, 8, 10, 12, 14]];
    $rRows = [];
    foreach ($refs as $r) $rRows[] = [$r['full_name'], !empty($r['team_id']) ? team_display_name($r['team_name'] ?? null, (int) $r['team_id']) : '—', (int) $r['n'], (int) $r['after_interview']];
    $sheets[] = ['name' => 'ارجاع به سرپرست', 'header' => ['سرپرست', 'تیم / واحد', 'تعداد ارجاع', 'بعد از مصاحبه‌ی حضوری'], 'rows' => $rRows, 'widths' => [24, 22, 12, 18]];
    if ($drill && !empty($drill['rows'])) {
        $dRows = array_map(static fn($r) => [(string) $r['name'], (string) $r['mobile'], (string) ($r['agent_name'] ?? ''), (string) $r['at'], (string) $r['detail']], $drill['rows']);
        $sheets[] = ['name' => 'ریز', 'header' => ['نام', 'موبایل', 'کارشناس', 'زمان', 'توضیح'], 'rows' => $dRows, 'widths' => [24, 14, 22, 18, 50], 'text_cols' => [1, 3]];
    }
    $sheets[] = ['name' => 'بازه', 'header' => ['مورد', 'مقدار'], 'rows' => [
        ['بازه', $rangeLabel . ': ' . to_jalali($from) . ' تا ' . to_jalali($to)],
        ['کارشناس', $agentId > 0 ? (rm_agent_names($pdo)[$agentId] ?? $agentId) : 'همه'],
        ['تاریخِ تهیه', to_jalali(date('Y-m-d')) . ' ' . date('H:i')],
    ], 'widths' => [16, 60]];
    xlsx_output('reception_overview_' . str_replace('/', '', normalize_digits(to_jalali($from))) . '_' . str_replace('/', '', normalize_digits(to_jalali($to))), $sheets);
    exit;
}

$link = static function (string $m, array $extra = []): string {
    return '?' . http_build_query(array_merge(array_diff_key($_GET, ['m' => 1, 'ag' => 1, 'sess' => 1, 'sup' => 1, 'export' => 1]), ['m' => $m], $extra)) . '#drill';
};
$num = static function ($n, string $m = '', array $extra = [], string $cls = '') use ($link): string {
    $t = to_persian_digits((string) (int) $n);
    return $m === '' || (int) $n === 0 ? '<span class="' . $cls . '">' . $t . '</span>'
        : '<a class="rov-n ' . $cls . '" href="' . e($link($m, $extra)) . '">' . $t . '</a>';
};
$pct = static fn(?float $p) => $p === null ? '—' : to_persian_digits((string) $p) . '٪';

$pageTitle = 'داشبورد استخدام';
require_once __DIR__ . '/../includes/layout_top.php';
?>
<style>
.rov{--l:#e7e2d3;--g:#c9a24b}
.rov .card{border:1px solid var(--l);border-radius:16px}
.rov .hero{background:linear-gradient(135deg,#0b0f1a 0%,#3d3220 55%,var(--g) 130%);border-radius:18px;padding:16px 20px;color:#f6efdd;margin-bottom:14px}
.rov .hero h5{color:#f6efdd;font-weight:800;margin:0}
.rov .chip{border:1px solid var(--l);border-radius:20px;padding:.25rem .8rem;font-size:.78rem;background:#fff;color:#1c1917;text-decoration:none;display:inline-block}
.rov .chip.active{background:linear-gradient(135deg,#f1dfa8,var(--g));border-color:transparent;font-weight:700}
.rov .flow{display:flex;flex-wrap:wrap;gap:6px;align-items:stretch}
.rov .step{flex:1 1 120px;border:1px solid var(--l);border-radius:14px;background:#fff;padding:10px;text-align:center;position:relative}
.rov .step .v{font-size:1.4rem;font-weight:800}
.rov .step .l{font-size:.75rem;color:#78716c}
.rov .step .c{font-size:.7rem;color:#a8a29e}
.rov a.rov-n{text-decoration:none;border-bottom:1px dashed currentColor;color:inherit}
.rov a.rov-n:hover{color:#b45309}
.rov table td,.rov table th{font-size:.8rem;vertical-align:middle;white-space:nowrap}
</style>
<div class="rov">
  <div class="hero d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div><h5><i class="fa-solid fa-gauge-high"></i> داشبوردِ استخدام — کلِ مسیر در یک صفحه</h5>
      <div class="small mt-1">همه‌ی عددها از یک منبع و با یک تعریف؛ روی هر عدد بزنید تا افرادِ همان عدد را ببینید.</div></div>
    <div class="d-flex gap-2 flex-wrap">
      <a href="admin_reception_reports.php" class="btn btn-sm btn-outline-light">گزارش آماری</a>
      <a href="admin_reception_funnel.php" class="btn btn-sm btn-outline-light">قیف و واگذاری</a>
      <a href="../reception_supervisor_meetings.php" class="btn btn-sm btn-outline-light">ثبت حضور جلسات</a>
      <a href="../reception_inperson.php" class="btn btn-sm btn-outline-light">مصاحبه‌های حضوری</a>
      <a href="admin_reception_excel.php" class="btn btn-sm btn-outline-light">ورود شماره</a>
    </div>
  </div>
  <?php if (!$ready): ?>
    <div class="alert alert-warning">ماژولِ پذیرش هنوز روی سرور فعال نشده است.</div>
  <?php else: ?>

  <form method="get" class="card p-3 mb-3">
    <div class="d-flex gap-2 flex-wrap mb-2">
      <?php foreach (rm_presets() as $pk => $pl): $qq = array_diff_key($_GET, ['m' => 1, 'ag' => 1, 'sess' => 1, 'sup' => 1, 'export' => 1]); $qq['preset'] = $pk; ?>
        <a class="chip <?= $preset === $pk ? 'active' : '' ?>" href="?<?= e(http_build_query($qq)) ?>"><?= $pl ?></a>
      <?php endforeach; ?>
      <span class="small text-muted align-self-center">بازه: <?= to_jalali($from) ?> تا <?= to_jalali($to) ?></span>
    </div>
    <div class="row g-2 align-items-end">
      <input type="hidden" name="preset" value="<?= e($preset) ?>">
      <?php if ($preset === 'custom'): ?>
        <div class="col-md-2"><label class="form-label small mb-1">از</label><input name="from" class="form-control form-control-sm jalali-date" autocomplete="off" value="<?= e((string) ($_GET['from'] ?? '')) ?>"></div>
        <div class="col-md-2"><label class="form-label small mb-1">تا</label><input name="to" class="form-control form-control-sm jalali-date" autocomplete="off" value="<?= e((string) ($_GET['to'] ?? '')) ?>"></div>
      <?php endif; ?>
      <div class="col-md-3"><label class="form-label small mb-1">کارشناس</label>
        <select name="agent" class="form-select form-select-sm"><option value="0">همه‌ی کارشناسان</option>
          <?php foreach ($agents as $a): ?><option value="<?= (int) $a['id'] ?>" <?= $agentId === (int) $a['id'] ? 'selected' : '' ?>><?= e($a['full_name']) ?></option><?php endforeach; ?></select></div>
      <div class="col-md-auto d-flex gap-2">
        <button class="btn btn-sm btn-dark">نمایش</button>
        <button name="export" value="1" class="btn btn-sm btn-outline-success"><i class="fa-solid fa-file-excel"></i> خروجی اکسل</button>
      </div>
    </div>
  </form>

  <?php if ($drill): ?>
    <div class="card p-3 mb-3" id="drill" style="border:2px solid #22c55e">
      <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
        <div class="fw-bold"><i class="fa-solid fa-list text-success"></i> <?= e($drill['title']) ?><?= $drill['agent_name'] !== '' ? ' — ' . e($drill['agent_name']) : '' ?>
          <span class="badge text-bg-light border"><?= to_persian_digits((string) (isset($drill['sessions']) ? count($drill['sessions']) : count($drill['rows']))) ?></span></div>
        <div class="d-flex gap-2">
          <a class="btn btn-sm btn-outline-success" href="?<?= e(http_build_query(array_merge($_GET, ['export' => 1]))) ?>"><i class="fa-solid fa-file-excel"></i> اکسلِ همین لیست + کلِ صفحه</a>
          <a class="btn btn-sm btn-outline-secondary" href="?<?= e(http_build_query(array_diff_key($_GET, ['m' => 1, 'ag' => 1, 'sess' => 1, 'sup' => 1]))) ?>">بستن</a>
        </div>
      </div>
      <div class="table-responsive" style="max-height:460px;overflow:auto"><table class="table table-sm mb-0">
        <?php if (isset($drill['sessions'])): ?>
          <thead class="table-light"><tr><th>تاریخ</th><th>ساعت</th><th>نوع</th><th>جلسه‌رونده</th><th>دعوت‌شده</th></tr></thead><tbody>
          <?php foreach ($drill['sessions'] as $s): ?><tr><td><?= to_jalali($s['d']) ?></td><td dir="ltr"><?= e($s['t']) ?></td><td><?= $s['kind'] === 'online' ? 'آنلاین' : 'حضوری' ?></td><td><?= e($s['host_name']) ?></td>
            <td><?= $num($s['invited'], 'session', ['sess' => $s['key']]) ?></td></tr><?php endforeach; ?>
          <?php if (!$drill['sessions']): ?><tr><td colspan="5" class="text-center text-muted py-3">موردی نیست.</td></tr><?php endif; ?>
        <?php else: ?>
          <thead class="table-light"><tr><th>#</th><th>نام</th><th>موبایل</th><th>کارشناس</th><th>زمان</th><th>توضیح</th></tr></thead><tbody>
          <?php foreach ($drill['rows'] as $i => $r): ?><tr><td><?= to_persian_digits((string) ($i + 1)) ?></td>
            <td><?php if ((int) $r['id'] > 0): ?><a href="../reception_applicant.php?id=<?= (int) $r['id'] ?>" target="_blank"><?= e((string) $r['name']) ?></a><?php else: ?><?= e((string) $r['name']) ?><?php endif; ?></td>
            <td dir="ltr"><?= e((string) $r['mobile']) ?></td><td><?= e((string) ($r['agent_name'] ?? '—')) ?></td>
            <td><?= to_jalali(substr((string) $r['at'], 0, 10)) ?> <span class="text-muted" dir="ltr"><?= e(substr((string) $r['at'], 11, 5)) ?></span></td><td class="text-wrap"><?= e((string) $r['detail']) ?></td></tr><?php endforeach; ?>
          <?php if (!$drill['rows']): ?><tr><td colspan="6" class="text-center text-muted py-3">موردی نیست.</td></tr><?php endif; ?>
        <?php endif; ?>
        </tbody></table></div>
    </div>
  <?php endif; ?>

  <!-- ۱) کلِ مسیر -->
  <div class="card p-3 mb-3">
    <div class="fw-bold mb-2"><i class="fa-solid fa-diagram-next text-warning"></i> مسیرِ کامل — <?= e($rangeLabel) ?></div>
    <div class="flow">
      <?php foreach ($steps as $k => $st):
        $sub = ['invited' => 'آنلاین ' . to_persian_digits((string) $T['inv_online']) . ' · حضوری ' . to_persian_digits((string) $T['inv_inperson']),
                'present' => 'نرخِ حضور ' . $pct($T['rate']), 'absent' => 'ثبت‌نشده ' . to_persian_digits((string) $T['pending']),
                'closed' => 'پیوست ' . to_persian_digits((string) $T['joined'])][$k] ?? ''; ?>
        <div class="step"><div class="l"><i class="fa-solid <?= e($st['icon']) ?>"></i> <?= e($st['label']) ?></div>
          <div class="v"><?= $num($T[$k] ?? 0, $k) ?></div><?php if ($sub !== ''): ?><div class="c"><?= $sub ?></div><?php endif; ?></div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- ۲) جلسات: برگزارشده، ظرفیت، دعوت، حاضر، غایب، نرخ؛ آنلاین / حضوری -->
  <div class="card p-3 mb-3">
    <div class="fw-bold mb-2"><i class="fa-solid fa-calendar-check text-success"></i> جلسات و حضور (بر اساسِ تاریخِ جلسه)</div>
    <div class="table-responsive"><table class="table table-sm align-middle mb-0">
      <thead class="table-light"><tr><th></th><th>جلسه</th><th>برگزارشده</th><th class="text-danger">بدونِ آمار<br><span class="fw-normal">(جلسه‌رونده نیامده؟)</span></th><th>ظرفیت</th><th>دعوت‌شده</th><th>حاضر</th><th>غایب</th><th>ثبت‌نشده</th><th>نرخِ حضور</th></tr></thead><tbody>
      <?php foreach (['all' => 'همه', 'online' => 'آنلاین', 'inperson' => 'حضوری'] as $k => $l): $x = $ss[$k]; ?>
        <tr class="<?= $k === 'all' ? 'fw-bold' : '' ?>"><td><?= $l ?></td><td><?= to_persian_digits((string) $x['sessions']) ?></td><td><?= to_persian_digits((string) $x['held']) ?><?= $x['checked'] ? ' <span class="text-warning small">(+' . to_persian_digits((string) $x['checked']) . ' بدونِ ثبتِ افراد)</span>' : '' ?></td>
          <td><?= $num($x['host_absent'], $k === 'all' ? 'host_absent' : '', [], 'text-danger fw-bold') ?></td>
          <td><?= $k === 'inperson' ? '—' : to_persian_digits((string) $x['cap']) ?></td>
          <td><?= $num($x['invited'], $k === 'online' ? 'met_online' : ($k === 'inperson' ? 'met_inperson' : '')) ?></td>
          <td class="text-success"><?= $num($x['att'], $k === 'all' ? 'present' : '') ?></td><td class="text-danger"><?= $num($x['ns'], $k === 'all' ? 'absent' : '') ?></td>
          <td class="text-warning"><?= $num($x['pending'], $k === 'all' ? 'pending' : '') ?></td><td><?= $pct($x['rate']) ?></td></tr>
      <?php endforeach; ?>
      </tbody></table></div>
    <div class="small text-muted mt-2">«بدونِ آمار» = وقتِ جلسه (+ <?= to_persian_digits((string) (rp_settings($pdo)['await_minutes'] ?? 60)) ?> دقیقه) گذشته، هیچ حاضر/غایبی ثبت نشده و جلسه‌رونده هم «اعلامِ حضور» نکرده ← احتمالاً جلسه‌رونده حاضر نبوده.</div>
  </div>

  <!-- ۳) به تفکیکِ کارشناس -->
  <div class="card p-3 mb-3">
    <div class="fw-bold mb-2"><i class="fa-solid fa-users text-primary"></i> به تفکیکِ کارشناس <span class="small text-muted fw-normal">— روی هر عدد بزنید</span></div>
    <div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0">
      <thead class="table-light"><tr><th>کارشناس</th><th>اختصاص</th><th>تماس (کالیزر)</th><th>تماسِ ثبت‌شده</th><th>موفق</th><th>دعوت</th><th>آنلاین</th><th>حضوری</th><th>حاضر</th><th>غایب</th><th>ثبت‌نشده</th><th>پیگیری مجدد</th><th>تعیین تکلیف</th><th>ارجاع</th></tr></thead><tbody>
      <?php foreach ($ov['per_agent'] as $r): $ag = ['ag' => $r['id']]; ?>
        <tr><td class="fw-semibold"><?= e($r['name']) ?></td>
          <?php foreach (['assigned', 'calls', 'logged', 'success', 'invited', 'met_online', 'met_inperson', 'present', 'absent', 'pending', 'recall', 'closed', 'referred'] as $k): ?>
            <td class="<?= $k === 'present' ? 'text-success fw-bold' : ($k === 'absent' ? 'text-danger' : ($k === 'pending' ? 'text-warning' : '')) ?>"><?= $num($r[$k], $k, $ag) ?></td>
          <?php endforeach; ?></tr>
      <?php endforeach; ?>
      <?php if (!$ov['per_agent']): ?><tr><td colspan="14" class="text-center text-muted py-3">در این بازه فعالیتی ثبت نشده.</td></tr><?php endif; ?>
      </tbody>
      <?php if ($ov['per_agent']): ?><tfoot class="table-light fw-bold"><tr><td>جمع</td>
        <?php foreach (['assigned', 'calls', 'logged', 'success', 'invited', 'met_online', 'met_inperson', 'present', 'absent', 'pending', 'recall', 'closed', 'referred'] as $k): ?><td><?= $num($T[$k] ?? 0, $k) ?></td><?php endforeach; ?></tr></tfoot><?php endif; ?>
    </table></div>
  </div>

  <div class="row g-3 mb-3">
    <!-- ۴) به تفکیکِ جلسه‌رونده -->
    <div class="col-xl-7"><div class="card p-3 h-100">
      <div class="fw-bold mb-2"><i class="fa-solid fa-chalkboard-user text-warning"></i> به تفکیکِ جلسه‌رونده (برگزارکننده)</div>
      <div class="table-responsive"><table class="table table-sm align-middle mb-0">
        <thead class="table-light"><tr><th>جلسه‌رونده</th><th>جلسه</th><th>برگزارشده</th><th class="text-danger">بدونِ آمار</th><th>دعوت‌شده</th><th>حاضر</th><th>غایب</th><th>ثبت‌نشده</th><th>نرخ</th><th>ارجاع</th></tr></thead><tbody>
        <?php foreach ($ss['hosts'] as $h): $dd = $h['att'] + $h['ns']; ?>
          <tr><td><?= e($h['name']) ?></td><td><?= to_persian_digits((string) $h['sessions']) ?></td><td><?= to_persian_digits((string) $h['held']) ?></td>
            <td class="<?= $h['host_absent'] ? 'text-danger fw-bold' : '' ?>"><?= to_persian_digits((string) $h['host_absent']) ?></td><td><?= to_persian_digits((string) $h['invited']) ?></td>
            <td class="text-success"><?= to_persian_digits((string) $h['att']) ?></td><td class="text-danger"><?= to_persian_digits((string) $h['ns']) ?></td><td class="text-warning"><?= to_persian_digits((string) $h['pending']) ?></td>
            <td><?= $dd ? to_persian_digits((string) round($h['att'] / $dd * 100, 1)) . '٪' : '—' ?></td><td><?= to_persian_digits((string) $h['referred']) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$ss['hosts']): ?><tr><td colspan="10" class="text-center text-muted py-3">جلسه‌ای در این بازه نیست.</td></tr><?php endif; ?>
        </tbody></table></div>
    </div></div>
    <!-- ۵) ارجاع به سرپرست / واحد -->
    <div class="col-xl-5"><div class="card p-3 h-100">
      <div class="fw-bold mb-2"><i class="fa-solid fa-user-tie text-success"></i> ارجاع به سرپرست / واحد</div>
      <div class="table-responsive"><table class="table table-sm align-middle mb-0">
        <thead class="table-light"><tr><th>سرپرست</th><th>تیم / واحد</th><th>ارجاع</th><th>بعد از مصاحبه‌ی حضوری</th></tr></thead><tbody>
        <?php foreach ($refs as $sid => $r): ?>
          <tr><td><?= e((string) $r['full_name']) ?></td><td class="small"><?= !empty($r['team_id']) ? e(team_display_name($r['team_name'] ?? null, (int) $r['team_id'])) : '—' ?></td>
            <td class="fw-bold"><?= $num($r['n'], 'ref_sup', ['sup' => $sid]) ?></td><td><?= to_persian_digits((string) $r['after_interview']) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$refs): ?><tr><td colspan="4" class="text-center text-muted py-3">ارجاعی در این بازه نیست.</td></tr><?php endif; ?>
        </tbody></table></div>
    </div></div>
  </div>

  <!-- ۶) فهرستِ جلسه‌ها -->
  <details class="card p-3 mb-3" <?= $ss['all']['host_absent'] ? 'open' : '' ?>>
    <summary class="fw-bold"><i class="fa-solid fa-list-check"></i> همه‌ی جلسه‌های این بازه (<?= to_persian_digits((string) count($sessions)) ?>)</summary>
    <div class="table-responsive mt-2" style="max-height:480px;overflow:auto"><table class="table table-sm align-middle mb-0">
      <thead class="table-light"><tr><th>تاریخ</th><th>ساعت</th><th>نوع</th><th>جلسه‌رونده</th><th>ظرفیت</th><th>دعوت‌شده</th><th>حاضر</th><th>غایب</th><th>ثبت‌نشده</th><th>وضعیت</th></tr></thead><tbody>
      <?php foreach ($sessions as $s): $stt = rm_session_states()[$s['state']]; ?>
        <tr><td><?= to_jalali($s['d']) ?></td><td dir="ltr"><?= e($s['t']) ?></td><td><?= $s['kind'] === 'online' ? 'آنلاین' : 'حضوری' ?></td><td><?= e($s['host_name']) ?></td>
          <td><?= $s['cap'] ? to_persian_digits((string) $s['cap']) : '—' ?></td><td><?= $num($s['invited'], 'session', ['sess' => $s['key']]) ?></td>
          <td class="text-success"><?= to_persian_digits((string) $s['att']) ?></td><td class="text-danger"><?= to_persian_digits((string) $s['ns']) ?></td><td class="text-warning"><?= to_persian_digits((string) $s['pending']) ?></td>
          <td><span class="badge text-bg-<?= e($stt['color']) ?>"><?= e($stt['label']) ?></span><?= $s['checkin'] ? ' <span class="small text-success">اعلامِ حضور ' . e(substr((string) $s['checkin'], 11, 5)) . '</span>' : '' ?></td></tr>
      <?php endforeach; ?>
      </tbody></table></div>
  </details>

  <details class="card p-3 mb-3">
    <summary class="small fw-bold">تعریفِ هر عدد (منبعِ واحد)</summary>
    <ul class="small mt-2 mb-0" style="line-height:2">
      <li><b>شماره‌ی واردشده:</b> متقاضیانی که در بازه وارد بانک شده‌اند. <b>اختصاص:</b> زمانِ واگذاری به کارشناس در بازه.</li>
      <li><b>تماس (کالیزر):</b> تماس‌های واقعیِ تلفنِ کارشناس از فایلِ کالیزر. <b>تماسِ ثبت‌شده:</b> تماس‌هایی که کارشناس در پرونده‌ی متقاضی ثبت کرده؛ <b>موفق</b> = نتیجه‌ی «موفق».</li>
      <li><b>دعوت:</b> رزروِ میتینگِ آنلاین یا ثبتِ مصاحبه‌ی حضوری که در بازه ثبت شده (افرادِ یکتا). <b>جلسه / حاضر / غایب:</b> بر اساسِ تاریخِ خودِ جلسه.</li>
      <li><b>پیگیری مجدد:</b> افرادی که برایشان پیگیریِ بعدی تعیین شد. <b>تعیین تکلیف:</b> پرونده‌هایی که بسته شدند (به‌جز بایگانیِ خودکار). <b>ارجاع:</b> ارجاع به سرپرست در بازه.</li>
    </ul>
  </details>
  <?php endif; ?>
</div>
<?php require_once __DIR__ . '/../includes/layout_bottom.php'; ?>
