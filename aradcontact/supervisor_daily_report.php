<?php
/**
 * گزارشِ روزانه‌ی A4ِ سرپرست — یک صفحه برای هر سرپرست (چاپ / PDF)
 *   A1/B1/C1: همان روزِ انتخاب‌شده — A2/B2/C2: روند از اولِ ماه تا همان روز (نمودارِ واقعی)
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

$dayJ = trim((string) ($_GET['day'] ?? ($_POST['day'] ?? '')));
$day = $dayJ !== '' ? (to_gregorian(normalize_digits($dayJ)) ?: date('Y-m-d')) : date('Y-m-d');
if ($day > date('Y-m-d')) $day = date('Y-m-d');
$dayJ = to_jalali($day);
$selfUrl = static fn(array $extra = []): string => 'supervisor_daily_report.php?' . http_build_query(array_merge(['day' => $dayJ, 'leader' => $leaderId], $extra));

// ─── ثبت‌ها ───
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = (string) ($_POST['action'] ?? '');
    if (!csrf_verify()) { flash_set('danger', 'نشست منقضی شده؛ دوباره تلاش کنید.'); redirect($selfUrl()); }
    $toInt = static fn($v): ?int => ($t = preg_replace('/\D+/', '', normalize_digits((string) $v))) === '' ? null : (int) $t;
    if ($act === 'save_services' && $leader) {
        $teamId = (int) $leader['team_id'];
        $st = $pdo->prepare('INSERT INTO sup_service_daily (team_id, day, service_id, cnt, entered_by) VALUES (?,?,?,?,?)
            ON DUPLICATE KEY UPDATE cnt = VALUES(cnt), entered_by = VALUES(entered_by)');
        $del = $pdo->prepare('DELETE FROM sup_service_daily WHERE team_id = ? AND day = ? AND service_id = ?');
        $n = 0;
        foreach (sd_service_types($pdo) as $t) {
            $v = $toInt($_POST['cnt'][(int) $t['id']] ?? '');
            if ($v === null) { $del->execute([$teamId, $day, (int) $t['id']]); continue; }
            $st->execute([$teamId, $day, (int) $t['id'], $v, (int) $user['id']]);
            $n++;
        }
        flash_set('success', 'خدماتِ روزِ ' . to_persian_digits($dayJ) . ' ذخیره شد' . ($n ? '' : ' (همه خودکار)') . '.');
        redirect($selfUrl() . '#sdr-services');
    }
    if ($act === 'save_salaries' && $isAll && $leader) {
        $allowed = array_flip(sd_team_ids($pdo, (int) $leader['team_id'], (int) $leader['id']));
        $st = $pdo->prepare('UPDATE users SET monthly_salary = ? WHERE id = ?');
        foreach ((array) ($_POST['salary'] ?? []) as $uid => $v) {
            if (!isset($allowed[(int) $uid])) continue;
            $st->execute([$toInt($v), (int) $uid]);
        }
        flash_set('success', 'حقوقِ ثابتِ نیروها ذخیره شد.');
        redirect($selfUrl() . '#sdr-salary');
    }
    if ($act === 'save_types' && $isAll) {
        $src = sd_service_sources();
        $up = $pdo->prepare('UPDATE sup_service_types SET title = ?, source = ?, sort_order = ?, is_active = ? WHERE id = ?');
        foreach (sd_service_types($pdo, false) as $t) {
            $id = (int) $t['id'];
            $title = trim((string) ($_POST['title'][$id] ?? $t['title']));
            $s = (string) ($_POST['source'][$id] ?? $t['source']);
            $up->execute([$title !== '' ? mb_substr($title, 0, 120) : $t['title'], isset($src[$s]) ? $s : 'manual', (int) ($_POST['sort'][$id] ?? $t['sort_order']), empty($_POST['active'][$id]) ? 0 : 1, $id]);
        }
        $newTitle = trim((string) ($_POST['new_title'] ?? ''));
        if ($newTitle !== '') {
            $s = (string) ($_POST['new_source'] ?? 'manual');
            $pdo->prepare('INSERT INTO sup_service_types (title, source, sort_order) VALUES (?,?,?)')
                ->execute([mb_substr($newTitle, 0, 120), isset($src[$s]) ? $s : 'manual', (int) $pdo->query('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM sup_service_types')->fetchColumn()]);
        }
        flash_set('success', 'فهرستِ خدمات ذخیره شد.');
        redirect($selfUrl() . '#sdr-types');
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
      <div class="col-6 col-md-2"><label class="form-label small mb-1">روزِ گزارش</label>
        <input name="day" class="form-control form-control-sm jalali-date" autocomplete="off" value="<?= e($dayJ) ?>"></div>
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
    <div class="small text-muted mt-2">بخش‌های راست (نیروی انسانی، فروش، خدمات) فقط همان روز؛ نمودارهای چپ از اولِ ماه تا همان روز. برای PDF در پنجره‌ی چاپ «Save as PDF» را انتخاب کنید (کاغذ A4، حاشیه: هیچ/None).</div>
  </form>

  <?php if (!$R): ?>
    <div class="alert alert-warning">سرپرستی پیدا نشد.</div>
  <?php else: $p = $R['p_rule']; $sr = $R['staff_rule']; ?>
  <div class="row g-3">
    <div class="col-xl-7">
      <div class="sdr-preview"><?= sd_render_sheet($R) ?></div>
    </div>
    <div class="col-xl-5">
      <div class="card p-3 mb-3 small">
        <div class="fw-bold mb-2"><i class="fa-solid fa-circle-info text-primary"></i> جزئیاتِ دو قانون (فقط روی صفحه؛ در چاپ فقط ✓ / ✕)</div>
        <div class="mb-2"><b>قانون تعداد <?= $sr['ok'] ? '✓' : '✕' ?></b> — توسعه‌ی لازم = ⌈عملیات ÷ ۸⌉ + ⌈ستادی ÷ ۲⌉ = <?= $fa($sr['need_dev']) ?>؛ توسعه‌ی فعلی <?= $fa($R['hc_day']['dev']) ?>
          <?php if ($R['hc_day']['unknown']): ?><div class="text-warning"><?= $fa($R['hc_day']['unknown']) ?> نیرو گروهِ شغلی ندارد و حساب نشده (در «گزارش سرپرست» تعیین کنید).</div><?php endif; ?></div>
        <div><b>قانون پ <?= $p['ok'] === null ? '—' : ($p['ok'] ? '✓' : '✕') ?></b> — بازه: اولِ ماه تا <?= e(to_persian_digits($dayJ)) ?> (<?= $fa($p['work_days']) ?> روزِ کاری، بدونِ جمعه)<br>
          مجموعِ حقوقِ ثابتِ ماهانه‌ی تیم: <?= $fa($p['monthly']) ?> تومان ← هدفِ روزانه (÷۲۴ ×۱۰): <?= $fa($p['daily_target']) ?> ← هدفِ بازه: <b><?= $fa($p['target']) ?></b><br>
          آورده‌ی بازه (پ کل): <b><?= $fa($p['actual']) ?></b> تومان
          <?php if ($p['ok'] === null): ?><div class="text-danger mt-1">حقوقِ ثابتِ هیچ‌کدام از اعضای تیم ثبت نشده؛ تا ثبت نشود قانون پ «—» نشان داده می‌شود.</div>
          <?php elseif ($p['missing']): ?><div class="text-warning mt-1"><?= $fa($p['missing']) ?> نفر از تیم حقوقِ ثابت ندارند و در هدف حساب نشده‌اند.</div><?php endif; ?></div>
      </div>

      <form method="post" class="card p-3 mb-3" id="sdr-services">
        <?= csrf_field() ?><input type="hidden" name="action" value="save_services"><input type="hidden" name="leader" value="<?= (int) $leaderId ?>"><input type="hidden" name="day" value="<?= e($dayJ) ?>">
        <div class="fw-bold mb-1"><i class="fa-solid fa-list-check text-success"></i> خدماتِ روزِ <?= e(to_persian_digits($dayJ)) ?></div>
        <div class="small text-muted mb-2">خالی = عددِ خودکار (اگر خدمت منبعِ خودکار دارد). عددی که بنویسید جایگزینِ خودکار می‌شود.</div>
        <?php foreach ($R['types'] as $t): $sid = (int) $t['id']; $man = $R['svc_manual'][$sid][$day] ?? null; $au = (int) ($R['svc_auto'][$sid][$day] ?? 0); ?>
          <div class="d-flex align-items-center gap-2 mb-1">
            <label class="small flex-grow-1"><?= e($t['title']) ?>
              <span class="text-muted" style="font-size:11px"><?= $t['source'] === 'manual' ? '(دستی)' : '(خودکار: ' . $fa($au) . ')' ?></span></label>
            <input name="cnt[<?= $sid ?>]" class="form-control form-control-sm text-center" style="width:90px" inputmode="numeric" value="<?= $man !== null ? e((string) $man) : '' ?>" placeholder="<?= $t['source'] === 'manual' ? '۰' : e(to_persian_digits((string) $au)) ?>">
          </div>
        <?php endforeach; ?>
        <button class="btn btn-sm btn-success mt-2">ذخیره‌ی خدماتِ روز</button>
      </form>

      <?php if ($isAll): $sal = sd_salaries($pdo, $R['ids']); $names = [];
        foreach ($pdo->query('SELECT id, full_name, role FROM users WHERE id IN (' . implode(',', array_map('intval', $R['ids']) ?: [0]) . ') ORDER BY role = \'leader\' DESC, full_name')->fetchAll(PDO::FETCH_ASSOC) ?: [] as $u) $names[(int) $u['id']] = $u; ?>
      <form method="post" class="card p-3 mb-3" id="sdr-salary">
        <?= csrf_field() ?><input type="hidden" name="action" value="save_salaries"><input type="hidden" name="leader" value="<?= (int) $leaderId ?>"><input type="hidden" name="day" value="<?= e($dayJ) ?>">
        <div class="fw-bold mb-1"><i class="fa-solid fa-money-bill-wave text-warning"></i> حقوقِ ثابتِ ماهانه‌ی تیم (برای قانون پ)</div>
        <div class="small text-muted mb-2">به تومان. اگر خالی بماند، آخرین «حقوق»ِ ثبت‌شده در پرداخت‌ها استفاده می‌شود.</div>
        <?php foreach ($names as $uid => $u): $s = $sal[$uid] ?? ['amount' => 0, 'source' => null]; ?>
          <div class="d-flex align-items-center gap-2 mb-1">
            <label class="small flex-grow-1"><?= e($u['full_name']) ?> <span class="text-muted" style="font-size:11px"><?= e(role_label((string) $u['role'])) ?></span>
              <?php if ($s['source'] === 'payout'): ?><span class="text-info" style="font-size:11px">(از پرداخت‌ها: <?= $fa($s['amount']) ?>)</span><?php elseif ($s['source'] === null): ?><span class="text-danger" style="font-size:11px">(ثبت نشده)</span><?php endif; ?></label>
            <input name="salary[<?= (int) $uid ?>]" class="form-control form-control-sm text-center" style="width:140px" inputmode="numeric" value="<?= $s['source'] === 'field' ? e((string) $s['amount']) : '' ?>">
          </div>
        <?php endforeach; ?>
        <button class="btn btn-sm btn-warning mt-2">ذخیره‌ی حقوق‌ها</button>
      </form>

      <form method="post" class="card p-3 mb-3" id="sdr-types">
        <?= csrf_field() ?><input type="hidden" name="action" value="save_types"><input type="hidden" name="leader" value="<?= (int) $leaderId ?>"><input type="hidden" name="day" value="<?= e($dayJ) ?>">
        <div class="fw-bold mb-2"><i class="fa-solid fa-sliders"></i> فهرستِ خدمات (برای همه‌ی سرپرست‌ها)</div>
        <?php foreach (sd_service_types($pdo, false) as $t): $id = (int) $t['id']; ?>
          <div class="d-flex flex-wrap align-items-center gap-1 mb-1">
            <input name="sort[<?= $id ?>]" class="form-control form-control-sm text-center" style="width:56px" value="<?= (int) $t['sort_order'] ?>" title="ترتیب">
            <input name="title[<?= $id ?>]" class="form-control form-control-sm" style="width:170px" value="<?= e($t['title']) ?>">
            <select name="source[<?= $id ?>]" class="form-select form-select-sm" style="width:auto;max-width:260px">
              <?php foreach (sd_service_sources() as $k => $lbl): ?><option value="<?= e($k) ?>" <?= $t['source'] === $k ? 'selected' : '' ?>><?= e($lbl) ?></option><?php endforeach; ?>
            </select>
            <label class="small"><input type="checkbox" name="active[<?= $id ?>]" value="1" <?= (int) $t['is_active'] ? 'checked' : '' ?>> فعال</label>
          </div>
        <?php endforeach; ?>
        <div class="d-flex flex-wrap align-items-center gap-1 mt-2">
          <input name="new_title" class="form-control form-control-sm" style="width:226px" placeholder="خدمتِ جدید…">
          <select name="new_source" class="form-select form-select-sm" style="width:auto;max-width:260px"><?php foreach (sd_service_sources() as $k => $lbl): ?><option value="<?= e($k) ?>"><?= e($lbl) ?></option><?php endforeach; ?></select>
        </div>
        <div class="small text-muted mt-1">نمودارِ C2 حداکثر ۸ رنگِ متمایز دارد؛ بیش از ۸ خدمتِ فعال پیشنهاد نمی‌شود.</div>
        <button class="btn btn-sm btn-outline-dark mt-2">ذخیره‌ی فهرست</button>
      </form>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/layout_bottom.php'; ?>
