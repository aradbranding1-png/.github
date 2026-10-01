<?php
require_once __DIR__ . '/../includes/auth.php';
$admin = require_login();
if (!perm_page_allowed($admin)) {
    http_response_code(403);
    die('دسترسی به این بخش ندارید.');
}
$pdo = db();

$typeLabels = ['customer' => 'مشتری', 'colleague' => 'همکار', 'family' => 'خانواده'];
$type = $_GET['type'] ?? 'customer';
if (!isset($typeLabels[$type])) {
    $type = 'customer';
}

$metricLabels = ['new' => 'جدید', 'followup' => 'پیگیری', 'connected' => 'برقرارشده', 'missed' => 'بی‌پاسخ'];
$metric = $_GET['metric'] ?? '';
if (!isset($metricLabels[$metric])) {
    $metric = '';
}

$userId = null;
$staffName = null;
if (isset($_GET['user_id']) && $_GET['user_id'] !== '') {
    $uid = (int) $_GET['user_id'];
    $stmtU = $pdo->prepare('SELECT id, full_name FROM users WHERE id = ? LIMIT 1');
    $stmtU->execute([$uid]);
    $u = $stmtU->fetch();
    if ($u) {
        $userId = (int) $u['id'];
        $staffName = $u['full_name'];
    }
}

$fromJalali = trim((string) ($_GET['from'] ?? ''));
$toJalali   = trim((string) ($_GET['to'] ?? ''));
$rangeFrom = $fromJalali !== '' ? to_gregorian(normalize_digits($fromJalali)) : null;
$rangeTo   = $toJalali !== '' ? to_gregorian(normalize_digits($toJalali)) : null;
if ($rangeFrom === null || $rangeTo === null) {
    $rangeFrom = $rangeTo = date('Y-m-d');
}
if ($rangeFrom > $rangeTo) {
    [$rangeFrom, $rangeTo] = [$rangeTo, $rangeFrom];
}
$presetLabels = ['today' => 'امروز', 'yesterday' => 'دیروز', 'week' => 'این هفته', 'month' => 'این ماه', 'custom' => 'بازه‌ی دلخواه'];
$rangeLabel = $presetLabels[$_GET['preset'] ?? ''] ?? 'بازه‌ی دلخواه';

$sql = "SELECT f.id, f.followup_date, f.event_time, f.call_duration_seconds, f.description, f.followup_number,
               c.full_name AS contact_name, c.mobile,
               u.full_name AS owner_name,
               NULL AS call_class
        FROM followups f
        JOIN customers c ON c.id = f.customer_id
        JOIN users u ON u.id = f.created_by
        WHERE f.source IN ('call_import', 'novatel_import') AND f.followup_date BETWEEN ? AND ?
              AND c.contact_type = ?";
$params = [$rangeFrom, $rangeTo, $type];

if ($userId !== null) {
    $sql .= " AND f.created_by = ?";
    $params[] = $userId;
}

switch ($metric) {
    case 'new':
    case 'followup':
        // تفکیکِ جدید/پیگیری در PHP با همان قاعده‌ی مشترکِ همه‌ی گزارش‌ها انجام می‌شود (پایین‌تر)
        $sql .= " AND f.call_duration_seconds > 10";
        break;
    case 'connected':
        $sql .= " AND f.call_duration_seconds > 0";
        break;
    case 'missed':
        $sql .= " AND f.call_duration_seconds <= 0";
        break;
    default:
        $sql .= " AND f.call_duration_seconds > 0";
        break;
}
$sql .= " ORDER BY f.followup_date DESC, f.event_time DESC, f.call_duration_seconds DESC LIMIT " . (in_array($metric, ['new', 'followup'], true) ? 5000 : 1000);

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// «جدید» = شماره با همین آپلود وارد سامانه شده؛ «پیگیری» = از قبل در سامانه بوده
$__cls = calls_new_followup_counts($pdo, $rangeFrom, $rangeTo, 0)['class'];
foreach ($rows as &$__r) {
    $__r['call_class'] = (int) $__r['call_duration_seconds'] > 0 ? ($__cls[(int) $__r['id']] ?? null) : null;
}
unset($__r);
if (in_array($metric, ['new', 'followup'], true)) {
    $rows = array_slice(array_values(array_filter($rows, static fn($r) => ($r['call_class'] ?? '') === $metric)), 0, 1000);
}

$totalDuration = 0;
foreach ($rows as $r) {
    $totalDuration += (int) $r['call_duration_seconds'];
}

$pageTitle = 'تماس‌های ' . ($metric !== '' ? $metricLabels[$metric] . ' — ' : '') . 'با ' . $typeLabels[$type] . ($staffName !== null ? ' — ' . $staffName : '');
require_once __DIR__ . '/../includes/layout_top.php';
?>

<style>
.rcd-page{--rcd-line:#e7e2d3;--rcd-ink:#1c1917;--rcd-muted:#78716c;--rcd-gold:#c9a24b;--rcd-gold-2:#f1dfa8;}

.rcd-page .rcd-back{
  display:inline-flex;align-items:center;gap:.45rem;border:1px solid var(--rcd-line);border-radius:11px;
  padding:.4rem .9rem;font-size:.82rem;font-weight:700;color:var(--rcd-muted);background:#fff;text-decoration:none;
  margin-bottom:1rem;transition:.15s ease;
}
.rcd-page .rcd-back:hover{border-color:var(--rcd-gold);color:#8a6a1e;background:#fdfaf1}

.rcd-page .card{border:1px solid var(--rcd-line);border-radius:18px;box-shadow:0 6px 22px -18px rgba(28,25,23,.35)}
.rcd-page .card h6{font-weight:800;color:var(--rcd-ink);font-size:.94rem}
.rcd-page .card h6 i{color:var(--rcd-gold)}

.rcd-page .rcd-count-badge{
  background:linear-gradient(135deg,var(--rcd-gold-2),var(--rcd-gold));color:#241d0a;font-weight:800;
  border-radius:999px;padding:.2rem .7rem;font-size:.76rem;
}
.rcd-page .rcd-duration-badge{
  background:#f1ece0;color:#5c4a1e;font-weight:700;border-radius:999px;padding:.2rem .7rem;font-size:.76rem;
}
.rcd-page .rcd-note{color:var(--rcd-muted);font-size:.8rem}

.rcd-page .alert-info{
  border:1px solid #cfe3ee;background:#eef6fa;color:#1d4e63;border-radius:14px;font-size:.82rem;
}
.rcd-page .alert-info i{color:#2e7fa8}

/* ---------- table ---------- */
.rcd-page table{font-size:.85rem}
.rcd-page table thead.table-light th{
  background:linear-gradient(135deg,#faf5e7,#f1e6c8);color:#5c4a1e;font-weight:700;border-bottom:1px solid var(--rcd-line);
  white-space:nowrap;
}
.rcd-page table tbody td{border-bottom:1px solid #f3f1ea;vertical-align:middle}
.rcd-page table tbody tr:hover{background:#faf8f2}

.rcd-page .badge.bg-primary-subtle{
  background:linear-gradient(135deg,#8fd9e0,#2c8f9c) !important;color:#062a2e !important;border-radius:999px;font-weight:700;
}
.rcd-page .badge.bg-warning-subtle{
  background:linear-gradient(135deg,var(--rcd-gold-2),var(--rcd-gold)) !important;color:#241d0a !important;border-radius:999px;font-weight:700;
}
.rcd-page .badge.bg-light{background:#f7f5ef !important;color:var(--rcd-ink) !important;border:1px solid var(--rcd-line) !important;border-radius:999px;font-weight:600}

.rcd-page .btn-outline-secondary{border:1px solid var(--rcd-line);border-radius:10px;color:var(--rcd-muted);font-weight:700}
.rcd-page .btn-outline-secondary:hover{background:#faf9f5;border-color:var(--rcd-gold-2);color:#8a6a1e}

@media (max-width:767.98px){
  .rcd-page .card{border-radius:15px}
}
</style>

<div class="rcd-page">
<a href="admin_reports_calls.php" class="rcd-back"><i class="fa-solid fa-arrow-right"></i> بازگشت به گزارش‌های تماس</a>

<div class="card p-3 mb-3">
  <div class="d-flex align-items-center gap-2 flex-wrap mb-1">
    <h6 class="mb-0">
      <i class="fa-solid fa-phone"></i>
      تماس‌های <?php if ($metric !== ''): ?><?= e($metricLabels[$metric]) ?> — <?php endif; ?>با <?= e($typeLabels[$type]) ?>
      <?php if ($staffName !== null): ?> — <?= e($staffName) ?><?php endif; ?>
      — <?= e($rangeLabel) ?>
    </h6>
    <span class="rcd-count-badge"><?= to_persian_digits((string) count($rows)) ?> تماس</span>
    <span class="rcd-duration-badge"><?= format_duration_seconds($totalDuration) ?> مجموع</span>
  </div>
  <p class="rcd-note mb-0">
    <?= to_jalali($rangeFrom) ?><?= $rangeFrom !== $rangeTo ? ' تا ' . to_jalali($rangeTo) : '' ?>
    — نمایش حداکثر ۱۰۰۰ ردیف اخیر
  </p>
</div>

<?php if ($type === 'colleague'): ?>
  <div class="alert alert-info compact-text">
    <i class="fa-solid fa-circle-info"></i>
    اگه دو تا همکار با هم صحبت کرده باشن و هردو فایل کالیزرشون رو آپلود کرده باشن، این مکالمه اینجا
    به‌صورت <b>دو ردیف جدا</b> دیده می‌شه (یک‌بار از دید هرکدوم) — چون هرکدوم مستقل ثبت شدن.
  </div>
<?php endif; ?>

<div class="card p-3">
  <?php if (!$rows): ?>
    <p class="rcd-note mb-0">هیچ تماسی در این بازه با این مشخصات پیدا نشد.</p>
  <?php else: ?>
    <div class="table-responsive">
      <table class="table table-sm align-middle mb-0">
        <thead class="table-light"><tr><th>کارشناس</th><th>نام مشتری</th><th>شماره</th><th>وضعیت</th><th>مدت مکالمه</th><th>ساعت</th><th>تاریخ</th></tr></thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td><?= e($r['owner_name']) ?></td>
              <td><?= e($r['contact_name']) ?></td>
              <td dir="ltr" style="text-align:right"><?= e($r['mobile']) ?></td>
              <td>
                <?php if ($r['description'] === 'برقراری تماس' || (int) $r['call_duration_seconds'] > 0): ?>
                  <?php if (($r['call_class'] ?? '') === 'new'): ?>
                    <span class="badge bg-primary-subtle text-primary-emphasis">برقرار (جدید)</span>
                  <?php else: ?>
                    <span class="badge bg-warning-subtle text-warning-emphasis">برقرار (پیگیری)</span>
                  <?php endif; ?>
                <?php elseif ($r['description'] === 'بی پاسخ'): ?>
                  <span class="badge bg-warning-subtle text-warning-emphasis">بی‌پاسخ</span>
                <?php else: ?>
                  <span class="badge bg-light text-dark border"><?= e((string) $r['description']) ?></span>
                <?php endif; ?>
              </td>
              <td><?= (int) $r['call_duration_seconds'] < 60 ? to_persian_digits((string) (int) $r['call_duration_seconds']) . ' ثانیه' : format_duration_minutes_only((int) $r['call_duration_seconds']) ?></td>
              <td dir="ltr" style="text-align:center"><?= !empty($r['event_time']) ? e(to_persian_digits(substr((string) $r['event_time'], 0, 8))) : '-' ?></td>
              <td><?= to_jalali($r['followup_date']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
</div>

<?php require_once __DIR__ . '/../includes/layout_bottom.php'; ?>
