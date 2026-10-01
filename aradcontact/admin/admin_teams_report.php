<?php
require_once __DIR__ . '/../includes/auth.php';
$admin = require_admin();
$pdo = db();

// =====================================================================
// انتخاب بازه‌ی زمانی — امروز (پیش‌فرض)، دیروز، یا یک بازه‌ی دلخواه
// =====================================================================
$preset = $_GET['preset'] ?? 'today';
$todayG = date('Y-m-d');
$dateParseError = null;

switch ($preset) {
    case 'yesterday':
        $rangeFrom = $rangeTo = date('Y-m-d', strtotime('-1 day'));
        $rangeLabel = 'دیروز';
        break;
    case 'custom':
        $rangeFromJalali = trim((string) ($_GET['from'] ?? ''));
        $rangeToJalali   = trim((string) ($_GET['to'] ?? ''));
        $rangeFrom = $rangeFromJalali !== '' ? to_gregorian(normalize_digits($rangeFromJalali)) : null;
        $rangeTo   = $rangeToJalali !== ''   ? to_gregorian(normalize_digits($rangeToJalali))   : null;
        if ($rangeFrom === null || $rangeTo === null) {
            $dateParseError = 'تاریخ واردشده قابل تشخیص نبود، لطفاً از خودِ تقویم (با کلیک روی فیلد) انتخاب کنید.';
            $rangeFrom = $rangeTo = $todayG;
            $preset = 'today';
        }
        $rangeLabel = 'بازه‌ی دلخواه';
        break;
    case 'today':
    default:
        $rangeFrom = $rangeTo = $todayG;
        $preset = 'today';
        $rangeLabel = 'امروز';
        break;
}
if ($rangeFrom > $rangeTo) {
    [$rangeFrom, $rangeTo] = [$rangeTo, $rangeFrom];
}

function __teams_report_range_link(string $preset, ?string $from = null, ?string $to = null): string
{
    $params = ['preset' => $preset];
    if ($preset === 'custom') {
        $params['from'] = $from;
        $params['to'] = $to;
    }
    return 'admin_teams_report.php?' . http_build_query($params);
}

// همون بازه‌ی زمانی، برای لینکِ اسمِ سرپرست به گزارشِ اختصاصیِ تیمش
function __team_leader_link(int $teamId, string $preset, string $from, string $to): string
{
    $params = ['team_id' => $teamId, 'preset' => $preset];
    if ($preset === 'custom') {
        $params['from'] = to_jalali($from);
        $params['to'] = to_jalali($to);
    }
    return 'admin_team_leader_report.php?' . http_build_query($params);
}

// =====================================================================
// لیست تیم‌ها + آمار تماس/جلسه‌ی هرکدوم برای بازه‌ی انتخابی
// تعریفِ «جدید» / «پیگیری» دقیقاً هماهنگ با بقیه‌ی گزارش‌های تماسِ سیستمه:
// جدید = اولین تماسِ ثبت‌شده برای آن مشتری (followup_number = 1)، پیگیری = تماس‌های بعدی.
// =====================================================================
$teams = $pdo->query("SELECT t.*, u.full_name AS leader_name FROM teams t LEFT JOIN users u ON u.id = t.leader_user_id WHERE t.leader_user_id IS NOT NULL ORDER BY t.id ASC")->fetchAll();

$jobGroupReady = users_job_group_ready($pdo);

$statStmt = $pdo->prepare("SELECT
    (SELECT COUNT(*) FROM users mu WHERE mu.team_id = ? AND mu.role IN ('A','B','C') AND mu.is_active = 1) AS member_count,
    (SELECT COUNT(*) FROM users mu WHERE mu.team_id = ? AND mu.is_active = 1 AND mu.job_group = 'توسعه') AS dev_count,
    (SELECT COUNT(*) FROM users mu WHERE mu.team_id = ? AND mu.is_active = 1 AND mu.job_group = 'عملیات') AS ops_count,
    (SELECT COUNT(*) FROM users mu WHERE mu.team_id = ? AND mu.is_active = 1 AND mu.job_group = 'ستادی') AS staff_count,
    (SELECT COALESCE(SUM(f.call_duration_seconds), 0) FROM followups f JOIN customers c ON c.id = f.customer_id JOIN users u ON u.id = f.created_by
        WHERE u.team_id = ? AND f.source IN ('call_import','novatel_import') AND f.call_duration_seconds > 10
              AND c.contact_type = 'customer' AND f.followup_date BETWEEN ? AND ?) AS total_duration,
    (SELECT COUNT(*) FROM followups f JOIN customers c ON c.id = f.customer_id JOIN users u ON u.id = f.created_by
        WHERE u.team_id = ? AND f.source IN ('call_import','novatel_import') AND f.call_duration_seconds > 10
              AND c.contact_type = 'customer' AND f.followup_date BETWEEN ? AND ?) AS total_calls,
    (SELECT COUNT(*) FROM followups f JOIN customers c ON c.id = f.customer_id JOIN users u ON u.id = f.created_by
        WHERE u.team_id = ? AND f.source IN ('call_import','novatel_import') AND f.call_duration_seconds > 10
              AND c.contact_type = 'customer' AND f.followup_number = 1 AND f.followup_date BETWEEN ? AND ?) AS new_calls,
    (SELECT COUNT(*) FROM followups f JOIN customers c ON c.id = f.customer_id JOIN users u ON u.id = f.created_by
        WHERE u.team_id = ? AND f.source IN ('call_import','novatel_import') AND f.call_duration_seconds > 10
              AND c.contact_type = 'customer' AND f.followup_number > 1 AND f.followup_date BETWEEN ? AND ?) AS old_calls,
    (SELECT COUNT(*) FROM followups f JOIN users u ON u.id = f.created_by
        WHERE u.team_id = ? AND f.status_after = 'جلسه برگزار شد' AND f.followup_date BETWEEN ? AND ?) AS meetings
");

foreach ($teams as &$team) {
    $tid = (int) $team['id'];
    $statStmt->execute([
        $tid,
        $tid,
        $tid,
        $tid,
        $tid, $rangeFrom, $rangeTo,
        $tid, $rangeFrom, $rangeTo,
        $tid, $rangeFrom, $rangeTo,
        $tid, $rangeFrom, $rangeTo,
        $tid, $rangeFrom, $rangeTo,
    ]);
    $team['stats'] = $statStmt->fetch();
}
unset($team);

// «جدید» = شماره با همین آپلود وارد سامانه شده؛ «پیگیری» = از قبل در سامانه بوده
$__nf = calls_new_followup_counts($pdo, $rangeFrom, $rangeTo, 10);
$__teamOf = [];
foreach ($pdo->query('SELECT id, team_id FROM users WHERE team_id IS NOT NULL')->fetchAll() as $__u) {
    $__teamOf[(int) $__u['id']] = (int) $__u['team_id'];
}
$__byTeam = [];
foreach ($__nf['by_user'] as $__uid => $__c) {
    $__t = $__teamOf[(int) $__uid] ?? 0;
    if (!$__t) continue;
    $__byTeam[$__t]['new'] = ($__byTeam[$__t]['new'] ?? 0) + $__c['new'];
    $__byTeam[$__t]['followup'] = ($__byTeam[$__t]['followup'] ?? 0) + $__c['followup'];
}
foreach ($teams as &$team) {
    $team['stats']['new_calls'] = $__byTeam[(int) $team['id']]['new'] ?? 0;
    $team['stats']['old_calls'] = $__byTeam[(int) $team['id']]['followup'] ?? 0;
}
unset($team);

// بیشترین مدت مکالمه بالاتر
usort($teams, fn($a, $b) => $b['stats']['total_duration'] <=> $a['stats']['total_duration']);

// =====================================================================
// ترکیب اعضای هر تیم به تفکیک واحد (A/B/C) — برای جدولِ دوم
// =====================================================================
$allStaff = $pdo->query("SELECT id, full_name, role, team_id FROM users WHERE role IN ('A','B','C') AND is_active = 1 ORDER BY full_name")->fetchAll();
$membersByTeam = [];
foreach ($allStaff as $u) {
    if ($u['team_id']) {
        $membersByTeam[$u['team_id']][$u['role']][] = $u['full_name'];
    }
}

function __team_name_only(array $team): string
{
    return $team['name'] !== null && $team['name'] !== '' ? $team['name'] : 'تیم ' . to_persian_digits((string) $team['id']);
}

// =====================================================================
// مجموع کل ستون‌های جدول اول
// =====================================================================
$totals = [
    'member_count'   => 0,
    'dev_count'      => 0,
    'ops_count'      => 0,
    'staff_count'    => 0,
    'total_duration' => 0, // ثانیه
    'total_calls'    => 0,
    'new_calls'      => 0,
    'old_calls'      => 0,
    'meetings'       => 0,
];
$sumRoundedMinutes = 0; // جمع دقیق دقیقه‌های گردشده‌ی هر ردیف (تا با ستون نمایش‌داده‌شده هماهنگ باشه)
foreach ($teams as $t) {
    foreach ($totals as $key => $_) {
        $totals[$key] += (int) ($t['stats'][$key] ?? 0);
    }
    $sumRoundedMinutes += (int) round(((int) $t['stats']['total_duration']) / 60);
}

$pageTitle = 'گزارش تیم‌ها';
require_once __DIR__ . '/../includes/layout_top.php';
?>

<style>
.tr-page{--tr-line:#e7e2d3;--tr-ink:#1c1917;--tr-muted:#78716c;--tr-gold:#c9a24b;--tr-gold-2:#f1dfa8;}

/* ---------- hero header ---------- */
.tr-page .tr-hero{
  position:relative;overflow:hidden;border-radius:20px;padding:1.7rem 1.8rem;margin-bottom:1.3rem;
  background:linear-gradient(135deg,#0b0f1a 0%,#3d3220 55%,var(--tr-gold) 130%);
  box-shadow:0 18px 40px -22px rgba(11,15,26,.55);
}
.tr-page .tr-hero::before{
  content:'';position:absolute;inset:0;pointer-events:none;
  background:radial-gradient(circle at 88% 12%,rgba(241,223,168,.35),transparent 55%);
}
.tr-page .tr-hero-row{position:relative;display:flex;align-items:center;gap:1rem;flex-wrap:wrap;justify-content:space-between}
.tr-page .tr-hero-title-wrap{display:flex;align-items:center;gap:.9rem}
.tr-page .tr-hero-icon{
  width:52px;height:52px;border-radius:14px;display:flex;align-items:center;justify-content:center;flex-shrink:0;
  background:linear-gradient(135deg,var(--tr-gold-2),var(--tr-gold));color:#241708;font-size:1.25rem;
  box-shadow:0 10px 22px -10px rgba(201,162,75,.7);
}
.tr-page .tr-hero-title{color:#f8f3e3;font-weight:800;font-size:1.15rem;margin:0}
.tr-page .tr-hero-sub{color:#d8cba6;font-size:.82rem;margin:.25rem 0 0}
.tr-page .tr-back{
  display:inline-flex;align-items:center;gap:.4rem;border-radius:999px;padding:.42rem 1rem;font-size:.82rem;font-weight:700;
  color:#f1dfa8;border:1px solid rgba(241,223,168,.45);background:rgba(255,255,255,.06);text-decoration:none;transition:.15s;
}
.tr-page .tr-back:hover{background:rgba(241,223,168,.15);color:#fff}

/* ---------- preset chips ---------- */
.tr-page .tr-preset-btn{
  border-radius:999px;padding:.4rem 1.1rem;font-size:.82rem;font-weight:700;border:1px solid var(--tr-line);
  color:var(--tr-ink);background:#fff;text-decoration:none;transition:.15s;
}
.tr-page .tr-preset-btn.active,.tr-page .tr-preset-btn:hover{
  border-color:transparent;color:#241708;background:linear-gradient(135deg,var(--tr-gold-2),var(--tr-gold));
}
.tr-page .form-control:focus,.tr-page .form-select:focus{border-color:var(--tr-gold);box-shadow:0 0 0 .2rem rgba(201,162,75,.18)}
.tr-page .btn-outline-secondary{border-color:var(--tr-line);color:var(--tr-ink);border-radius:10px;font-weight:700}

/* ---------- cards ---------- */
.tr-page .card{border:1px solid var(--tr-line);border-radius:16px;box-shadow:0 4px 16px -14px rgba(28,25,23,.3)}
.tr-page .card h5,.tr-page .card h6{display:flex;align-items:center;gap:.5rem;font-weight:800;color:var(--tr-ink)}
.tr-page .card h5 i,.tr-page .card h6 i{color:var(--tr-gold)}

/* ---------- table ---------- */
.tr-page .table thead.table-light th{background:var(--tr-gold-2);color:#4a3712;border-color:var(--tr-gold)}
.tr-page .tr-leader-link{color:var(--tr-ink);font-weight:700;text-decoration:none;border-bottom:1px dashed var(--tr-gold)}
.tr-page .tr-leader-link:hover{color:#8a6a1e}

/* ---------- total row ---------- */
.tr-page tfoot .tr-total-row td{
  background:linear-gradient(135deg,var(--tr-gold-2),var(--tr-gold));
  color:#241708;font-weight:800;border-color:var(--tr-gold);
}
</style>

<div class="calls-report-page tr-page">
<div class="tr-hero">
  <div class="tr-hero-row">
    <div class="tr-hero-title-wrap">
      <span class="tr-hero-icon"><i class="fa-solid fa-people-group"></i></span>
      <div>
        <p class="tr-hero-title">گزارش تیم‌ها</p>
        <p class="tr-hero-sub">بازه: <?= e($rangeLabel) ?></p>
      </div>
    </div>
    <a href="admin_reports.php" class="tr-back"><i class="fa-solid fa-arrow-right"></i> بازگشت به گزارش‌های مدیریتی</a>
  </div>
</div>

<div class="card p-3 mb-4">
  <?php if ($dateParseError): ?>
    <div class="alert alert-warning small py-2 mb-3"><i class="fa-solid fa-triangle-exclamation"></i> <?= $dateParseError ?></div>
  <?php endif; ?>
  <div class="d-flex gap-2 flex-wrap mb-3">
    <a href="<?= e(__teams_report_range_link('today')) ?>" class="tr-preset-btn <?= $preset === 'today' ? 'active' : '' ?>">امروز</a>
    <a href="<?= e(__teams_report_range_link('yesterday')) ?>" class="tr-preset-btn <?= $preset === 'yesterday' ? 'active' : '' ?>">دیروز</a>
  </div>
  <form method="get" class="d-flex gap-2 flex-wrap align-items-end">
    <input type="hidden" name="preset" value="custom">
    <div>
      <label class="form-label small mb-1">از تاریخ</label>
      <input type="text" name="from" class="form-control form-control-sm jalali-date" dir="ltr" autocomplete="off" value="<?= e($preset === 'custom' ? to_jalali($rangeFrom) : '') ?>" placeholder="۱۴۰۵/۰۱/۰۱">
    </div>
    <div>
      <label class="form-label small mb-1">تا تاریخ</label>
      <input type="text" name="to" class="form-control form-control-sm jalali-date" dir="ltr" autocomplete="off" value="<?= e($preset === 'custom' ? to_jalali($rangeTo) : '') ?>" placeholder="۱۴۰۵/۰۶/۱۹">
    </div>
    <button type="submit" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-magnifying-glass"></i> اعمال بازه</button>
  </form>
</div>

<?php if (!$teams): ?>
  <div class="card p-5 text-center">
    <div class="text-muted mb-2"><i class="fa-solid fa-people-group fa-2x"></i></div>
    <h6>هنوز تیمی ساخته نشده</h6>
    <div class="text-muted small">از «مدیریت تیم‌ها» می‌تونید تیم بسازید.</div>
  </div>
<?php else: ?>

<div class="card p-3 mb-4">
  <h6 class="mb-3">خلاصه‌ی تماس و جلسات هر تیم — <?= e($rangeLabel) ?></h6>
  <p class="text-muted small mb-3"><i class="fa-solid fa-circle-info"></i> روی اسمِ سرپرست کلیک کنید تا گزارشِ اختصاصیِ همون تیم — آمار تک‌تکِ اعضا و ارجاع‌هایی که خودِ سرپرست انجام داده — باز بشه.</p>
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th>شماره تیم</th><th>سرپرست</th>
          <?php if ($jobGroupReady): ?>
            <th>اعضای توسعه</th><th>اعضای عملیات</th><th>اعضای ستادی</th>
          <?php else: ?>
            <th>تعداد عضو</th>
          <?php endif; ?>
          <th>مدت تماس <span class="small text-muted">(دقیقه)</span></th><th>کل تماس</th><th>تماس جدید</th><th>پیگیری</th><th>جلسه برگزارشده</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($teams as $team): $s = $team['stats']; ?>
          <tr>
            <td><?= e(__team_name_only($team)) ?></td>
            <td>
              <?php if ($team['leader_name']): ?>
                <a href="<?= e(__team_leader_link((int) $team['id'], $preset, $rangeFrom, $rangeTo)) ?>" class="tr-leader-link"><?= e($team['leader_name']) ?></a>
              <?php else: ?>
                <span class="text-muted">—</span>
              <?php endif; ?>
            </td>
            <?php if ($jobGroupReady): ?>
              <td><?= to_persian_digits((string) (int) $s['dev_count']) ?></td>
              <td><?= to_persian_digits((string) (int) $s['ops_count']) ?></td>
              <td><?= to_persian_digits((string) (int) $s['staff_count']) ?></td>
            <?php else: ?>
              <td><?= to_persian_digits((string) (int) $s['member_count']) ?></td>
            <?php endif; ?>
            <td><?= to_persian_digits((string) (int) round($s['total_duration'] / 60)) ?></td>
            <td><?= to_persian_digits((string) (int) $s['total_calls']) ?></td>
            <td><?= to_persian_digits((string) (int) $s['new_calls']) ?></td>
            <td><?= to_persian_digits((string) (int) $s['old_calls']) ?></td>
            <td><?= to_persian_digits((string) (int) $s['meetings']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr class="tr-total-row">
          <td colspan="2" class="text-center">جمع کل</td>
          <?php if ($jobGroupReady): ?>
            <td><?= to_persian_digits((string) $totals['dev_count']) ?></td>
            <td><?= to_persian_digits((string) $totals['ops_count']) ?></td>
            <td><?= to_persian_digits((string) $totals['staff_count']) ?></td>
          <?php else: ?>
            <td><?= to_persian_digits((string) $totals['member_count']) ?></td>
          <?php endif; ?>
          <td><?= to_persian_digits((string) $sumRoundedMinutes) ?></td>
          <td><?= to_persian_digits((string) $totals['total_calls']) ?></td>
          <td><?= to_persian_digits((string) $totals['new_calls']) ?></td>
          <td><?= to_persian_digits((string) $totals['old_calls']) ?></td>
          <td><?= to_persian_digits((string) $totals['meetings']) ?></td>
        </tr>
      </tfoot>
    </table>
  </div>
</div>

<div class="card p-3 mb-4">
  <h6 class="mb-3">ترکیب اعضای هر تیم به تفکیک واحد</h6>
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
      <thead class="table-light">
        <tr><th>شماره تیم</th><th>سرپرست</th><th>اعضای واحد A</th><th>اعضای واحد B</th><th>اعضای واحد C</th></tr>
      </thead>
      <tbody>
        <?php foreach ($teams as $team): $mem = $membersByTeam[$team['id']] ?? []; ?>
          <tr>
            <td><?= e(__team_name_only($team)) ?></td>
            <td>
              <?php if ($team['leader_name']): ?>
                <a href="<?= e(__team_leader_link((int) $team['id'], $preset, $rangeFrom, $rangeTo)) ?>" class="tr-leader-link"><?= e($team['leader_name']) ?></a>
              <?php else: ?>
                <span class="text-muted">—</span>
              <?php endif; ?>
            </td>
            <td><?= !empty($mem['A']) ? e(implode('، ', $mem['A'])) : '<span class="text-muted">—</span>' ?></td>
            <td><?= !empty($mem['B']) ? e(implode('، ', $mem['B'])) : '<span class="text-muted">—</span>' ?></td>
            <td><?= !empty($mem['C']) ? e(implode('، ', $mem['C'])) : '<span class="text-muted">—</span>' ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php endif; ?>

</div>

<?php require_once __DIR__ . '/../includes/layout_bottom.php'; ?>