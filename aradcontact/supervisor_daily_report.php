<?php
/**
 * گزارشِ روزانه‌ی A4ِ سرپرست — یک صفحه برای هر سرپرست (چاپ / PDF) — عملکردِ «کلِ تیمِ» تحتِ مدیریتِ سرپرست
 *   ?report_date=۱۴۰۵/۰۷/۰۳ (یا ?day=) ← A1/B1/C1 همان روز؛ A2/B2/C2 روند از اولِ ماه تا همان روز
 *   ?print=1 ← نسخه‌ی چاپی (فقط صفحه‌های A4)؛ ?print=1&all=1 ← همه‌ی سرپرستان، هر کدام یک صفحه
 */
require_once __DIR__ . '/includes/auth.php';
$user = require_login();
$pdo = db();
require_once __DIR__ . '/includes/customer_credit.php';
require_once __DIR__ . '/includes/performance_functions.php';
require_once __DIR__ . '/includes/supervisor_report.php';
require_once __DIR__ . '/includes/team_sales.php';
require_once __DIR__ . '/includes/supervisor_daily.php';

$isAll = is_super_admin($user) || user_can('supervisor_report_all', $user);
$isLeader = ($user['role'] ?? '') === 'leader';
if (!$isAll && !($isLeader && user_can('supervisor_report_view', $user))) perm_deny('دسترسی به «گزارش سرپرست» ندارید.', $user);
sd_ready($pdo);
try { sd_snapshot_all($pdo); } catch (Throwable $e) {}

$leaders = sup_leaders($pdo);
if (!$isAll) $leaders = array_values(array_filter($leaders, static fn($L) => (int) $L['id'] === (int) $user['id']));
$leaderId = $isAll ? (int) ($_GET['leader'] ?? ($_POST['leader'] ?? 0)) : (int) $user['id'];
if ($leaderId <= 0 && $leaders) $leaderId = (int) $leaders[0]['id'];
$leader = null;
foreach ($leaders as $L) if ((int) $L['id'] === $leaderId) $leader = $L;

// report_date: تاریخِ گزارش (متغیر؛ پیش‌فرض امروز)
$dayJ = trim((string) ($_GET['report_date'] ?? ($_POST['report_date'] ?? ($_GET['day'] ?? ($_POST['day'] ?? '')))));
$day = $dayJ !== '' ? (to_gregorian(normalize_digits($dayJ)) ?: date('Y-m-d')) : date('Y-m-d');
if ($day > date('Y-m-d')) $day = date('Y-m-d');
$dayJ = to_jalali($day);
$selfUrl = static fn(array $extra = []): string => 'supervisor_daily_report.php?' . http_build_query(array_merge(['report_date' => $dayJ, 'leader' => $leaderId], $extra));
$canSalary = $isAll || ($isLeader && $leader && (int) $leader['id'] === (int) $user['id']);

// ─── ثبت‌ها ───
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = (string) ($_POST['action'] ?? '');
    if (!csrf_verify()) { flash_set('danger', 'نشست منقضی شده؛ دوباره تلاش کنید.'); redirect($selfUrl()); }
    $toInt = static fn($v): ?int => ($t = preg_replace('/\D+/', '', normalize_digits((string) $v))) === '' ? null : (int) $t;
    $toNum = static function ($v): ?float {
        $t = str_replace(['٫', ','], ['.', ''], normalize_digits(trim((string) $v)));
        return $t === '' || !is_numeric($t) ? null : max(0, (float) $t);
    };
    if ($act === 'save_metric_values' && $leader) {
        $teamId = (int) $leader['team_id'];
        $st = $pdo->prepare('INSERT INTO team_metric_values (team_id, metric_id, report_date, value, entered_by) VALUES (?,?,?,?,?)
            ON DUPLICATE KEY UPDATE value = VALUES(value), entered_by = VALUES(entered_by)');
        $del = $pdo->prepare('DELETE FROM team_metric_values WHERE team_id = ? AND report_date = ? AND metric_id = ?');
        foreach (sd_metrics($pdo, $teamId) as $m) {
            $v = $toNum($_POST['val'][(int) $m['id']] ?? '');
            if ($v === null) { $del->execute([$teamId, $day, (int) $m['id']]); continue; }
            $st->execute([$teamId, (int) $m['id'], $day, $v, (int) $user['id']]);
        }
        flash_set('success', 'شاخص‌های روزِ ' . to_persian_digits($dayJ) . ' ذخیره شد.');
        redirect($selfUrl() . '#sdr-metrics');
    }
    if ($act === 'save_salaries' && $canSalary && $leader) {
        // مدیر: همه‌ی افرادِ تیم؛ سرپرست: اعضای تیمِ خودش (نه حقوقِ خودش)
        $allowed = array_flip(sd_team_ids($pdo, (int) $leader['team_id'], (int) $leader['id']));
        if (!$isAll) unset($allowed[(int) $user['id']]);
        $st = $pdo->prepare('UPDATE users SET custom_fixed_salary = ? WHERE id = ?');
        foreach ((array) ($_POST['salary'] ?? []) as $uid => $v) {
            if (!isset($allowed[(int) $uid])) continue;
            $st->execute([$toInt($v), (int) $uid]);
        }
        flash_set('success', 'حقوقِ ثابتِ نیروها ذخیره شد.');
        redirect($selfUrl() . '#sdr-salary');
    }
    if ($act === 'save_salary_defaults' && $isAll) {
        foreach (['leader', 'onsite', 'remote'] as $k) {
            $v = $toInt($_POST['def'][$k] ?? '');
            if ($v !== null) sd_setting_set($pdo, 'salary_' . $k, (string) $v, (int) $user['id']);
        }
        flash_set('success', 'حقوقِ پیش‌فرضِ انواعِ نیرو ذخیره شد.');
        redirect($selfUrl() . '#sdr-salary');
    }
    if ($act === 'save_metrics_config' && $isAll && $leader) {
        $teamId = (int) $leader['team_id'];
        $src = sd_metric_sources();
        $up = $pdo->prepare('UPDATE team_metrics SET metric_name = ?, metric_key = ?, source = ?, sort_order = ?, is_active = ? WHERE id = ? AND team_id = ?');
        $err = 0;
        foreach (sd_metrics($pdo, $teamId, false) as $m) {
            $id = (int) $m['id'];
            $name = trim((string) ($_POST['name'][$id] ?? $m['metric_name']));
            $key = preg_replace('/[^a-z0-9_]/', '', strtolower(trim((string) ($_POST['key'][$id] ?? $m['metric_key']))));
            $sv = (string) ($_POST['source'][$id] ?? $m['source']);
            try {
                $up->execute([$name !== '' ? mb_substr($name, 0, 150) : $m['metric_name'], $key !== '' ? substr($key, 0, 60) : $m['metric_key'], isset($src[$sv]) ? $sv : 'manual',
                    (int) ($_POST['sort'][$id] ?? $m['sort_order']), empty($_POST['active'][$id]) ? 0 : 1, $id, $teamId]);
            } catch (PDOException $e) { $err++; }
        }
        $newName = trim((string) ($_POST['new_name'] ?? ''));
        if ($newName !== '') {
            $sv = (string) ($_POST['new_source'] ?? 'manual');
            $next = (int) $pdo->query('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM team_metrics WHERE team_id = ' . $teamId)->fetchColumn();
            $key = preg_replace('/[^a-z0-9_]/', '', strtolower(trim((string) ($_POST['new_key'] ?? ''))));
            if ($key === '') $key = 'm' . ((string) (int) $pdo->query('SELECT COUNT(*) + 1 FROM team_metrics WHERE team_id = ' . $teamId)->fetchColumn()) . '_' . substr(md5($newName . microtime()), 0, 4);
            try {
                $pdo->prepare('INSERT INTO team_metrics (team_id, metric_name, metric_key, source, sort_order) VALUES (?,?,?,?,?)')
                    ->execute([$teamId, mb_substr($newName, 0, 150), substr($key, 0, 60), isset($src[$sv]) ? $sv : 'manual', $next]);
            } catch (PDOException $e) { $err++; }
        }
        flash_set($err ? 'warning' : 'success', $err ? 'ذخیره شد، ولی کلیدِ (key) تکراری پذیرفته نشد.' : 'شاخص‌های تیم ذخیره شد.');
        redirect($selfUrl() . '#sdr-mconf');
    }
    redirect($selfUrl());
}

// ─── نسخه‌ی چاپی: فقط صفحه‌های A4 ───
if (!empty($_GET['print'])) {
    $targets = (!empty($_GET['all']) && $isAll) ? $leaders : ($leader ? [$leader] : []);
    $sheets = '';
    foreach ($targets as $L) $sheets .= sd_render_sheet(sd_build($pdo, $L, $day));
    $title = count($targets) === 1 ? 'گزارش ' . $targets[0]['full_name'] . ' ' . to_jalali($day) : 'گزارش سرپرستان ' . to_jalali($day);
    header('Content-Type: text/html; charset=utf-8');
    ?><!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e(str_replace('/', '-', $title)) ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/Vazirmatn-font-face.css">
<style>
<?= sd_sheet_css() ?>
html,body{margin:0;padding:0}
@media screen{body{background:#9a9893}.sdr-sheet{margin:8mm auto;box-shadow:0 6px 24px rgba(0,0,0,.25)}
  .sdr-bar{position:sticky;top:0;z-index:5;display:flex;gap:8px;justify-content:center;padding:8px;background:#1d1c1a;font-family:Vazirmatn,Tahoma,sans-serif}
  .sdr-bar button,.sdr-bar a{font:inherit;font-size:13px;border:0;border-radius:8px;padding:6px 14px;background:#f1dfa8;color:#241d0a;cursor:pointer;text-decoration:none}}
@media print{.sdr-bar{display:none}body{background:#fff}}
</style>
</head>
<body>
<div class="sdr-bar"><button type="button" onclick="window.print()">چاپ / ذخیره به‌صورتِ PDF</button><a href="<?= e($selfUrl()) ?>">بازگشت</a></div>
<?= $sheets ?: '<p style="text-align:center;font-family:Tahoma">سرپرستی برای گزارش پیدا نشد.</p>' ?>
<script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 500); });</script>
</body>
</html>
<?php
    exit;
}

$R = $leader ? sd_build($pdo, $leader, $day) : null;
$pageTitle = 'گزارش A4 روزانه‌ی سرپرست';
require_once __DIR__ . '/includes/layout_top.php';
$fa = static fn($n): string => to_persian_digits(number_format((int) $n));
?>
<style>
<?= sd_sheet_css() ?>
.sdr-preview{background:#9a9893;border-radius:16px;padding:12px;overflow:auto}
.sdr-preview .sdr-sheet{zoom:.62;margin:0 auto;box-shadow:0 6px 24px rgba(0,0,0,.25)}
@media (max-width:768px){.sdr-preview .sdr-sheet{zoom:.42}}
</style>

<div class="container-fluid py-3">
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
    <h5 class="fw-bold mb-0"><i class="fa-solid fa-file-pdf text-danger"></i> گزارش A4 روزانه‌ی سرپرست</h5>
    <a href="supervisor_report.php" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-arrow-right"></i> گزارش سرپرست</a>
  </div>

  <form method="get" class="card p-3 mb-3">
    <div class="row g-2 align-items-end">
      <div class="col-6 col-md-2"><label class="form-label small mb-1">تاریخ گزارش</label>
        <input name="report_date" class="form-control form-control-sm jalali-date" autocomplete="off" value="<?= e($dayJ) ?>"></div>
      <?php if ($isAll): ?>
      <div class="col-6 col-md-3"><label class="form-label small mb-1">سرپرست</label>
        <select name="leader" class="form-select form-select-sm">
          <?php foreach ($leaders as $L): ?><option value="<?= (int) $L['id'] ?>" <?= (int) $L['id'] === $leaderId ? 'selected' : '' ?>><?= e($L['full_name'] . ' — ' . team_display_name($L['team_name'] ?? null, (int) $L['team_id'])) ?></option><?php endforeach; ?>
        </select></div>
      <?php endif; ?>
      <div class="col-12 col-md d-flex flex-wrap gap-2">
        <button class="btn btn-sm btn-dark">نمایش</button>
        <?php if ($leader): ?><a target="_blank" href="<?= e($selfUrl(['print' => 1])) ?>" class="btn btn-sm btn-danger"><i class="fa-solid fa-print"></i> چاپ / PDF همین سرپرست</a><?php endif; ?>
        <?php if ($isAll && count($leaders) > 1): ?><a target="_blank" href="<?= e($selfUrl(['print' => 1, 'all' => 1])) ?>" class="btn btn-sm btn-outline-danger"><i class="fa-solid fa-copy"></i> همه‌ی سرپرستان (<?= to_persian_digits((string) count($leaders)) ?> صفحه)</a><?php endif; ?>
      </div>
    </div>
    <div class="small text-muted mt-2">گزارش = عملکردِ کلِ تیمِ تحتِ مدیریتِ سرپرست (همه‌ی نیروها: حضوری، غیرحضوری، دورکار + خودِ سرپرست). بخش‌های راست (A1، B1، C1) همان تاریخ؛ نمودارهای چپ (A2، B2، C2) از اولِ ماه تا همان تاریخ. برای PDF در پنجره‌ی چاپ «Save as PDF» را انتخاب کنید (کاغذ A4، حاشیه: هیچ/None).</div>
  </form>

  <?php if (!$R): ?>
    <div class="alert alert-warning">سرپرستی پیدا نشد.</div>
  <?php else: $p = $R['p_rule']; $sr = $R['staff_rule']; $teamTitle = team_display_name($leader['team_name'] ?? null, (int) $leader['team_id']); ?>
  <div class="row g-3">
    <div class="col-xl-7">
      <div class="sdr-preview"><?= sd_render_sheet($R) ?></div>
    </div>
    <div class="col-xl-5">
      <div class="card p-3 mb-3 small">
        <div class="fw-bold mb-2"><i class="fa-solid fa-circle-info text-primary"></i> جزئیاتِ دو قانون (فقط روی صفحه؛ در خروجی فقط ✓ / ✕)</div>
        <div class="mb-2"><b>قانون تعداد <?= $sr['ok'] ? '✓' : '✕' ?></b> — توسعه‌ی لازم = ⌈عملیات ÷ ۸⌉ + ⌈ستادی ÷ ۲⌉ = <?= $fa($sr['need_dev']) ?>؛ توسعه‌ی فعلی <?= $fa($R['hc_day']['dev']) ?>
          <?php if ($R['hc_day']['unknown']): ?><div class="text-warning"><?= $fa($R['hc_day']['unknown']) ?> نیرو گروهِ شغلی ندارد و حساب نشده (در «گزارش سرپرست» تعیین کنید).</div><?php endif; ?></div>
        <div><b>قانون پ <?= $p['ok'] ? '✓' : '✕' ?></b> — دوره: اولِ ماه تا <?= e(to_persian_digits($dayJ)) ?> (<?= $fa($p['work_days']) ?> روز، بدونِ جمعه)<br>
          مجموعِ حقوقِ ثابتِ ماهانه‌ی تیم: <?= $fa($p['monthly']) ?> ← حقوقِ روزانه‌ی تیم (÷۲۴): <?= $fa($p['daily_salary']) ?> ← هدفِ پولِ روزانه (×۱۰): <?= $fa($p['daily_target']) ?><br>
          هدفِ دوره (× <?= $fa($p['work_days']) ?> روز): <b><?= $fa($p['target']) ?></b> — پ کلِ دوره: <b><?= $fa($p['actual']) ?></b> تومان
          <?php if ($p['assumed']): ?><div class="text-warning mt-1"><?= $fa($p['assumed']) ?> نفر «حضوری/دورکار» ندارند و حضوری فرض شده‌اند (در ویرایشِ کاربر تعیین کنید).</div><?php endif; ?></div>
      </div>

      <form method="post" class="card p-3 mb-3" id="sdr-metrics">
        <?= csrf_field() ?><input type="hidden" name="action" value="save_metric_values"><input type="hidden" name="leader" value="<?= (int) $leaderId ?>"><input type="hidden" name="report_date" value="<?= e($dayJ) ?>">
        <div class="fw-bold mb-1"><i class="fa-solid fa-list-check text-success"></i> شاخص‌های اختصاصیِ <?= e($teamTitle) ?> — <?= e(to_persian_digits($dayJ)) ?></div>
        <?php if (!$R['metrics']): ?>
          <div class="small text-muted">برای این تیم هنوز شاخصی تعریف نشده<?= $isAll ? '؛ از کادرِ «تنظیمِ شاخص‌های تیم» اضافه کنید.' : '؛ مدیر باید شاخص‌ها را تعریف کند.' ?></div>
        <?php else: ?>
        <div class="small text-muted mb-2">خالی = عددِ خودکار (اگر شاخص منبعِ خودکار دارد). عددی که بنویسید جایگزینِ خودکار می‌شود.</div>
        <?php foreach ($R['metrics'] as $m): $mid = (int) $m['id']; $man = $R['mv_manual'][$mid][$day] ?? null; $au = (float) ($R['mv_auto'][$mid][$day] ?? 0); ?>
          <div class="d-flex align-items-center gap-2 mb-1">
            <label class="small flex-grow-1"><?= e($m['metric_name']) ?>
              <span class="text-muted" style="font-size:11px"><?= $m['source'] === 'manual' ? '(دستی)' : '(خودکار: ' . sd_num($au) . ')' ?></span></label>
            <input name="val[<?= $mid ?>]" class="form-control form-control-sm text-center" style="width:90px" inputmode="decimal" value="<?= $man !== null ? e(rtrim(rtrim(number_format($man, 2, '.', ''), '0'), '.')) : '' ?>" placeholder="<?= $m['source'] === 'manual' ? '۰' : e(sd_num($au)) ?>">
          </div>
        <?php endforeach; ?>
        <button class="btn btn-sm btn-success mt-2">ذخیره‌ی شاخص‌های روز</button>
        <?php endif; ?>
      </form>

      <?php if ($canSalary): $def = sd_default_salaries($pdo); ?>
      <div class="card p-3 mb-3" id="sdr-salary">
        <div class="fw-bold mb-1"><i class="fa-solid fa-money-bill-wave text-warning"></i> حقوقِ ثابتِ ماهانه‌ی تیم (برای قانون پ)</div>
        <div class="small text-muted mb-2">اولویت: «حقوقِ سفارشی» اگر وارد شده، وگرنه حقوقِ پیش‌فرضِ نوعِ نیرو. خالی گذاشتنِ حقوقِ سفارشی = پیش‌فرض. به تومان.</div>
        <?php if ($isAll): ?>
        <form method="post" class="row g-1 align-items-end mb-2 pb-2 border-bottom">
          <?= csrf_field() ?><input type="hidden" name="action" value="save_salary_defaults"><input type="hidden" name="leader" value="<?= (int) $leaderId ?>"><input type="hidden" name="report_date" value="<?= e($dayJ) ?>">
          <?php foreach (['leader', 'onsite', 'remote'] as $k): ?>
            <div class="col-4"><label class="small text-muted mb-0">پیش‌فرضِ <?= e(sd_salary_type_label($k)) ?></label><input name="def[<?= $k ?>]" class="form-control form-control-sm text-center" inputmode="numeric" value="<?= e((string) $def[$k]) ?>"></div>
          <?php endforeach; ?>
          <div class="col-12"><button class="btn btn-sm btn-outline-warning mt-1">ذخیره‌ی پیش‌فرض‌ها (برای همه‌ی تیم‌ها)</button></div>
        </form>
        <?php endif; ?>
        <form method="post">
          <?= csrf_field() ?><input type="hidden" name="action" value="save_salaries"><input type="hidden" name="leader" value="<?= (int) $leaderId ?>"><input type="hidden" name="report_date" value="<?= e($dayJ) ?>">
          <div class="table-responsive"><table class="table table-sm small align-middle mb-1">
            <thead><tr><th>نیرو</th><th>نوع</th><th class="text-end">پیش‌فرض</th><th>حقوقِ سفارشی</th><th class="text-end">ملاکِ محاسبه</th></tr></thead><tbody>
            <?php foreach ($p['salaries'] as $uid => $sv): $self = !$isAll && (int) $uid === (int) $user['id']; ?>
              <tr><td><?= e($sv['name']) ?></td>
                <td><?= e(sd_salary_type_label($sv['type'])) ?><?= $sv['type_assumed'] ? ' <span class="text-warning" title="حضوری/دورکار ثبت نشده">(فرض)</span>' : '' ?></td>
                <td class="text-end"><?= $fa($sv['default']) ?></td>
                <td><input name="salary[<?= (int) $uid ?>]" class="form-control form-control-sm text-center" style="min-width:110px" inputmode="numeric" value="<?= $sv['custom'] !== null ? e((string) $sv['custom']) : '' ?>" <?= $self ? 'disabled title="حقوقِ خودِ سرپرست را مدیر تعیین می‌کند"' : '' ?>></td>
                <td class="text-end fw-bold"><?= $fa($sv['amount']) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot><tr class="fw-bold"><td colspan="4">جمعِ ماهانه</td><td class="text-end"><?= $fa($p['monthly']) ?></td></tr></tfoot></table></div>
          <button class="btn btn-sm btn-warning">ذخیره‌ی حقوق‌های سفارشی</button>
        </form>
      </div>
      <?php endif; ?>

      <?php if ($isAll): ?>
      <form method="post" class="card p-3 mb-3" id="sdr-mconf">
        <?= csrf_field() ?><input type="hidden" name="action" value="save_metrics_config"><input type="hidden" name="leader" value="<?= (int) $leaderId ?>"><input type="hidden" name="report_date" value="<?= e($dayJ) ?>">
        <div class="fw-bold mb-1"><i class="fa-solid fa-sliders"></i> تنظیمِ شاخص‌های <?= e($teamTitle) ?> (C1 / C2)</div>
        <div class="small text-muted mb-2">هر تیم فهرستِ خودش را دارد. شاخصِ جدید خودکار در C1، C2، گزارشِ روزانه و روندی می‌آید. ترتیب با عددِ ستونِ اول؛ غیرفعال = نمایش داده نمی‌شود (داده‌اش می‌ماند).</div>
        <?php foreach (sd_metrics($pdo, (int) $leader['team_id'], false) as $m): $id = (int) $m['id']; ?>
          <div class="d-flex flex-wrap align-items-center gap-1 mb-1">
            <input name="sort[<?= $id ?>]" class="form-control form-control-sm text-center" style="width:52px" value="<?= (int) $m['sort_order'] ?>" title="ترتیب">
            <input name="name[<?= $id ?>]" class="form-control form-control-sm" style="width:190px" value="<?= e($m['metric_name']) ?>" title="نامِ شاخص">
            <input name="key[<?= $id ?>]" class="form-control form-control-sm" style="width:80px" dir="ltr" value="<?= e($m['metric_key']) ?>" title="کلید (metric_key)">
            <select name="source[<?= $id ?>]" class="form-select form-select-sm" style="width:auto;max-width:220px">
              <?php foreach (sd_metric_sources() as $k => $lbl): ?><option value="<?= e($k) ?>" <?= $m['source'] === $k ? 'selected' : '' ?>><?= e($lbl) ?></option><?php endforeach; ?>
            </select>
            <label class="small"><input type="checkbox" name="active[<?= $id ?>]" value="1" <?= (int) $m['is_active'] ? 'checked' : '' ?>> فعال</label>
          </div>
        <?php endforeach; ?>
        <div class="d-flex flex-wrap align-items-center gap-1 mt-2">
          <input name="new_name" class="form-control form-control-sm" style="width:246px" placeholder="شاخصِ جدید… (مثلاً میتینگ VIP)">
          <input name="new_key" class="form-control form-control-sm" style="width:80px" dir="ltr" placeholder="key">
          <select name="new_source" class="form-select form-select-sm" style="width:auto;max-width:220px"><?php foreach (sd_metric_sources() as $k => $lbl): ?><option value="<?= e($k) ?>"><?= e($lbl) ?></option><?php endforeach; ?></select>
        </div>
        <button class="btn btn-sm btn-outline-dark mt-2">ذخیره‌ی شاخص‌های تیم</button>
      </form>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/layout_bottom.php'; ?>
