<?php
require_once __DIR__ . '/../includes/auth.php';
$admin = require_admin();
$pdo = db();

// نیروهای پذیرش: «تماس با متقاضی» از «مدت تماس» (با مشتری) جداست — همان تعریفِ «گزارش‌های تماس»
if (is_file(__DIR__ . '/../includes/reception_functions.php')) require_once __DIR__ . '/../includes/reception_functions.php';
$rxCc = function_exists('rx_cc_ready') && rx_cc_ready($pdo);
$rxNot = $rxCc ? ' AND ' . rx_cc_not_applicant_sql('f') : '';

// =====================================================================
// گزارش یک تیم به همراه سرپرستش — از روی «گزارش تیم‌ها» با کلیک روی اسم سرپرست باز می‌شود.
// نشون می‌ده: آمار تماس/جلسه‌ی تک‌تک اعضای تیم، و ارجاع‌هایی که خودِ سرپرست انجام داده
// (به همراه اینکه نیروی گیرنده‌ی ارجاع واقعاً تماس گرفته یا نه).
// =====================================================================

$teamId = (int) ($_GET['team_id'] ?? 0);
$teamStmt = $pdo->prepare('SELECT t.*, u.full_name AS leader_name, u.id AS leader_user_id
                            FROM teams t LEFT JOIN users u ON u.id = t.leader_user_id
                            WHERE t.id = ? LIMIT 1');
$teamStmt->execute([$teamId]);
$team = $teamStmt->fetch();

if (!$team) {
    flash_set('danger', 'تیمِ موردنظر پیدا نشد.');
    redirect('admin_teams_report.php');
}

// =====================================================================
// انتخاب بازه‌ی زمانی — امروز (پیش‌فرض)، دیروز، یا یک بازه‌ی دلخواه (دقیقاً هماهنگ با گزارش تیم‌ها)
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

function __team_leader_report_range_link(int $teamId, string $preset, ?string $from = null, ?string $to = null): string
{
    $params = ['team_id' => $teamId, 'preset' => $preset];
    if ($preset === 'custom') {
        $params['from'] = $from;
        $params['to'] = $to;
    }
    return 'admin_team_leader_report.php?' . http_build_query($params);
}

// =====================================================================
// آمار تماس/جلسه‌ی تک‌تک اعضای تیم برای بازه‌ی انتخابی
// =====================================================================
$members = $pdo->prepare("SELECT id, full_name, role FROM users WHERE team_id = ? AND role IN ('A','B','C') AND is_active = 1 ORDER BY full_name");
$members->execute([$teamId]);
$members = $members->fetchAll();

$memberCallStmt = $pdo->prepare("SELECT
    COALESCE(SUM(f.call_duration_seconds), 0) AS total_duration,
    COUNT(*) AS total_calls,
    SUM(CASE WHEN f.followup_number = 1 THEN 1 ELSE 0 END) AS new_calls,
    SUM(CASE WHEN f.followup_number > 1 THEN 1 ELSE 0 END) AS old_calls
    FROM followups f JOIN customers c ON c.id = f.customer_id
    WHERE f.created_by = ? AND f.source IN ('call_import','novatel_import') AND f.call_duration_seconds > 10
          AND c.contact_type = 'customer' AND f.followup_date BETWEEN ? AND ?{$rxNot}");

$memberMeetingStmt = $pdo->prepare("SELECT COUNT(*) FROM followups
    WHERE created_by = ? AND status_after = 'جلسه برگزار شد' AND followup_date BETWEEN ? AND ?");

foreach ($members as &$m) {
    $memberCallStmt->execute([$m['id'], $rangeFrom, $rangeTo]);
    $m['stats'] = $memberCallStmt->fetch();
    $memberMeetingStmt->execute([$m['id'], $rangeFrom, $rangeTo]);
    $m['meetings'] = (int) $memberMeetingStmt->fetchColumn();
}
// «جدید» = شماره با همین آپلود وارد سامانه شده؛ «پیگیری» = از قبل در سامانه بوده
$__nf = calls_new_followup_counts($pdo, $rangeFrom, $rangeTo, 10);
if ($rxCc) $__nf = rx_cc_adjust_new_followup($pdo, $__nf, $rangeFrom, $rangeTo);
// دقیقه‌ی «تماس با متقاضی» (نیروهای پذیرش) — ستونِ جدا
$rxApplicant = $rxCc ? rx_cc_stats($pdo, $rangeFrom, $rangeTo) : [];
foreach ($members as &$__m) {
    $__c = $__nf['by_user'][(int) $__m['id']] ?? ['new' => 0, 'followup' => 0];
    $__m['stats']['new_calls'] = $__c['new'];
    $__m['stats']['old_calls'] = $__c['followup'];
}
unset($__m);
unset($m);

$teamTotals = [
    'total_duration' => array_sum(array_column(array_column($members, 'stats'), 'total_duration')),
    'total_calls'    => array_sum(array_column(array_column($members, 'stats'), 'total_calls')),
    'new_calls'      => array_sum(array_column(array_column($members, 'stats'), 'new_calls')),
    'old_calls'      => array_sum(array_column(array_column($members, 'stats'), 'old_calls')),
    'meetings'       => array_sum(array_column($members, 'meetings')),
];

// =====================================================================
// ارجاع‌هایی که خودِ این سرپرست انجام داده — و اینکه نیروی گیرنده واقعاً تماس گرفته یا نه
// =====================================================================
$referralTableAvailable = false;
try {
    $chk = $pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_referrals'");
    $referralTableAvailable = (bool) $chk->fetchColumn();
} catch (Throwable $e) {
    $referralTableAvailable = false;
}

$referrals = [];
if ($referralTableAvailable && $team['leader_user_id']) {
    $refStmt = $pdo->prepare("SELECT r.*, c.full_name AS customer_name, c.mobile, c.mobile_2,
                                      from_u.full_name AS from_user_name, to_u.full_name AS to_user_name
                               FROM customer_referrals r
                               JOIN customers c ON c.id = r.customer_id
                               JOIN users from_u ON from_u.id = r.from_user_id
                               JOIN users to_u ON to_u.id = r.to_user_id
                               WHERE r.referred_by = ? AND DATE(r.created_at) BETWEEN ? AND ?
                               ORDER BY r.created_at DESC");
    $refStmt->execute([$team['leader_user_id'], $rangeFrom, $rangeTo]);
    $referrals = $refStmt->fetchAll();

    $calledStmt = $pdo->prepare("SELECT COUNT(*) FROM followups
        WHERE customer_id = ? AND created_by = ? AND source IN ('call_import','novatel_import')
              AND call_duration_seconds > 10 AND followup_date >= ?");
    foreach ($referrals as &$r) {
        $calledStmt->execute([$r['customer_id'], $r['to_user_id'], date('Y-m-d', strtotime($r['created_at']))]);
        $r['called'] = (int) $calledStmt->fetchColumn() > 0;
    }
    unset($r);
}

// شاخصِ ارجاع، به تفکیکِ نیروی گیرنده — برای اضافه‌شدن به انتهای جدولِ «آمار تک‌تک اعضا»
$referralStatsByMember = [];
foreach ($referrals as $r) {
    $toId = (int) $r['to_user_id'];
    if (!isset($referralStatsByMember[$toId])) {
        $referralStatsByMember[$toId] = ['received' => 0, 'called' => 0, 'not_called' => 0];
    }
    $referralStatsByMember[$toId]['received']++;
    if ($r['called']) {
        $referralStatsByMember[$toId]['called']++;
    } else {
        $referralStatsByMember[$toId]['not_called']++;
    }
}

$teamNameDisplay = $team['name'] !== null && $team['name'] !== '' ? $team['name'] : 'تیم ' . to_persian_digits((string) $team['id']);

$pageTitle = 'گزارش تیم و سرپرست';
require_once __DIR__ . '/../includes/layout_top.php';
?>
<div class="alert alert-light border d-flex justify-content-between align-items-center flex-wrap gap-2 py-2">
  <span class="small"><i class="fa-solid fa-user-tie text-warning"></i> گزارشِ سرپرست: فعالیتِ هر سرپرست، پوششِ تماس با نیروها، حضوری/دورکار، گروهِ شغلیِ نامشخص و راندمانِ نیروها.</span>
  <a href="../supervisor_report.php<?= !empty($leaderId) ? '?leader=' . (int) $leaderId : '' ?>" class="btn btn-sm btn-warning">گزارش سرپرست</a>
</div>
<?php
?>

<style>
.tlr-page{--tlr-line:#e7e2d3;--tlr-ink:#1c1917;--tlr-muted:#78716c;--tlr-gold:#c9a24b;--tlr-gold-2:#f1dfa8;}

/* ---------- hero header ---------- */
.tlr-page .tlr-hero{
  position:relative;overflow:hidden;border-radius:20px;padding:1.7rem 1.8rem;margin-bottom:1.3rem;
  background:linear-gradient(135deg,#0b0f1a 0%,#3d3220 55%,var(--tlr-gold) 130%);
  box-shadow:0 18px 40px -22px rgba(11,15,26,.55);
}
.tlr-page .tlr-hero::before{
  content:'';position:absolute;inset:0;pointer-events:none;
  background:radial-gradient(circle at 88% 12%,rgba(241,223,168,.35),transparent 55%);
}
.tlr-page .tlr-hero-row{position:relative;display:flex;align-items:center;gap:1rem;flex-wrap:wrap;justify-content:space-between}
.tlr-page .tlr-hero-title-wrap{display:flex;align-items:center;gap:.9rem}
.tlr-page .tlr-hero-icon{
  width:52px;height:52px;border-radius:14px;display:flex;align-items:center;justify-content:center;flex-shrink:0;
  background:linear-gradient(135deg,var(--tlr-gold-2),var(--tlr-gold));color:#241708;font-size:1.25rem;
  box-shadow:0 10px 22px -10px rgba(201,162,75,.7);
}
.tlr-page .tlr-hero-title{color:#f8f3e3;font-weight:800;font-size:1.15rem;margin:0}
.tlr-page .tlr-hero-sub{color:#d8cba6;font-size:.82rem;margin:.25rem 0 0}
.tlr-page .tlr-back{
  display:inline-flex;align-items:center;gap:.4rem;border-radius:999px;padding:.42rem 1rem;font-size:.82rem;font-weight:700;
  color:#f1dfa8;border:1px solid rgba(241,223,168,.45);background:rgba(255,255,255,.06);text-decoration:none;transition:.15s;
}
.tlr-page .tlr-back:hover{background:rgba(241,223,168,.15);color:#fff}

/* ---------- preset chips ---------- */
.tlr-page .tlr-preset-btn{
  border-radius:999px;padding:.4rem 1.1rem;font-size:.82rem;font-weight:700;border:1px solid var(--tlr-line);
  color:var(--tlr-ink);background:#fff;text-decoration:none;transition:.15s;
}
.tlr-page .tlr-preset-btn.active,.tlr-page .tlr-preset-btn:hover{
  border-color:transparent;color:#241708;background:linear-gradient(135deg,var(--tlr-gold-2),var(--tlr-gold));
}
.tlr-page .form-control:focus,.tlr-page .form-select:focus{border-color:var(--tlr-gold);box-shadow:0 0 0 .2rem rgba(201,162,75,.18)}
.tlr-page .btn-outline-secondary{border-color:var(--tlr-line);color:var(--tlr-ink);border-radius:10px;font-weight:700}

/* ---------- cards ---------- */
.tlr-page .card{border:1px solid var(--tlr-line);border-radius:16px;box-shadow:0 4px 16px -14px rgba(28,25,23,.3)}
.tlr-page .card h5,.tlr-page .card h6{display:flex;align-items:center;gap:.5rem;font-weight:800;color:var(--tlr-ink)}
.tlr-page .card h5 i,.tlr-page .card h6 i{color:var(--tlr-gold)}

/* ---------- glance boxes ---------- */
.tlr-page .tlr-glance{display:flex;gap:.7rem;flex-wrap:wrap;margin-bottom:.2rem}
.tlr-page .tlr-glance-box{flex:1;min-width:120px;border:1px solid var(--tlr-line);border-radius:14px;padding:.8rem 1rem;text-align:center;background:#fdfcf9}
.tlr-page .tlr-glance-num{font-weight:800;font-size:1.3rem;background:linear-gradient(135deg,#8a6a1e,var(--tlr-gold));-webkit-background-clip:text;background-clip:text;color:transparent}
.tlr-page .tlr-glance-label{color:var(--tlr-muted);font-size:.76rem;margin-top:.2rem}

/* ---------- table ---------- */
.tlr-page .table thead.table-light th,.tlr-page table thead th{background:var(--tlr-gold-2);color:#4a3712;border-color:var(--tlr-gold)}

/* ---------- referral cards ---------- */
.tlr-page .tlr-ref-row{border:1px solid var(--tlr-line);border-radius:14px;padding:.9rem 1.1rem;margin-bottom:.7rem;background:#fdfcf9}
.tlr-page .tlr-called-yes{
  display:inline-flex;align-items:center;gap:.35rem;border-radius:999px;padding:.25rem .75rem;font-size:.76rem;font-weight:700;
  background:linear-gradient(135deg,#bfe8c9,#4e9d63);color:#0f3d1c;
}
.tlr-page .tlr-called-no{
  display:inline-flex;align-items:center;gap:.35rem;border-radius:999px;padding:.25rem .75rem;font-size:.76rem;font-weight:700;
  background:linear-gradient(135deg,#f3b8ba,#c94a4f);color:#4a0f11;
}
</style>

<div class="calls-report-page tlr-page">
<div class="tlr-hero">
  <div class="tlr-hero-row">
    <div class="tlr-hero-title-wrap">
      <span class="tlr-hero-icon"><i class="fa-solid fa-people-group"></i></span>
      <div>
        <p class="tlr-hero-title"><?= e($teamNameDisplay) ?> — گزارش تیم و سرپرست</p>
        <p class="tlr-hero-sub">سرپرست: <?= $team['leader_name'] ? e($team['leader_name']) : 'بدون سرپرست' ?> — بازه: <?= e($rangeLabel) ?></p>
      </div>
    </div>
    <a href="admin_teams_report.php" class="tlr-back"><i class="fa-solid fa-arrow-right"></i> بازگشت به گزارش تیم‌ها</a>
  </div>
</div>

<div class="card p-3 mb-4">
  <?php if ($dateParseError): ?>
    <div class="alert alert-warning small py-2 mb-3"><i class="fa-solid fa-triangle-exclamation"></i> <?= $dateParseError ?></div>
  <?php endif; ?>
  <div class="d-flex gap-2 flex-wrap mb-3">
    <a href="<?= e(__team_leader_report_range_link($teamId, 'today')) ?>" class="tlr-preset-btn <?= $preset === 'today' ? 'active' : '' ?>">امروز</a>
    <a href="<?= e(__team_leader_report_range_link($teamId, 'yesterday')) ?>" class="tlr-preset-btn <?= $preset === 'yesterday' ? 'active' : '' ?>">دیروز</a>
  </div>
  <form method="get" class="d-flex gap-2 flex-wrap align-items-end">
    <input type="hidden" name="team_id" value="<?= (int) $teamId ?>">
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

<div class="card p-3 mb-4">
  <h6 class="mb-3"><i class="fa-solid fa-chart-simple"></i> جمعِ کل تیم — <?= e($rangeLabel) ?></h6>
  <div class="tlr-glance">
    <div class="tlr-glance-box"><div class="tlr-glance-num"><?= to_persian_digits((string) (int) round($teamTotals['total_duration'] / 60)) ?></div><div class="tlr-glance-label">دقیقه مکالمه</div></div>
    <div class="tlr-glance-box"><div class="tlr-glance-num"><?= to_persian_digits((string) (int) $teamTotals['total_calls']) ?></div><div class="tlr-glance-label">کل تماس</div></div>
    <div class="tlr-glance-box"><div class="tlr-glance-num"><?= to_persian_digits((string) (int) $teamTotals['new_calls']) ?></div><div class="tlr-glance-label">تماس جدید</div></div>
    <div class="tlr-glance-box"><div class="tlr-glance-num"><?= to_persian_digits((string) (int) $teamTotals['old_calls']) ?></div><div class="tlr-glance-label">پیگیری</div></div>
    <div class="tlr-glance-box"><div class="tlr-glance-num"><?= to_persian_digits((string) (int) $teamTotals['meetings']) ?></div><div class="tlr-glance-label">جلسه برگزارشده</div></div>
    <div class="tlr-glance-box"><div class="tlr-glance-num"><?= to_persian_digits((string) count($referrals)) ?></div><div class="tlr-glance-label">ارجاعِ سرپرست</div></div>
  </div>
</div>

<div class="card p-3 mb-4">
  <h6 class="mb-3"><i class="fa-solid fa-users"></i> آمار تک‌تک اعضای تیم — <?= e($rangeLabel) ?></h6>
  <?php if (!$members): ?>
    <p class="text-muted small mb-0">هنوز عضوی به این تیم اضافه نشده.</p>
  <?php else: ?>
    <div class="table-responsive">
      <?php $__rxCol = false; foreach (($members ?? []) as $__mm) if (!empty($rxApplicant[(int) $__mm['id']]['n'])) { $__rxCol = true; break; } ?>
      <table class="table table-sm align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th>نام</th><th>واحد</th><th>مدت تماس <span class="small text-muted">(دقیقه)</span></th><?php if ($__rxCol): ?><th>تماس با متقاضی <span class="small text-muted">(دقیقه)</span></th><?php endif; ?><th>کل تماس</th><th>تماس جدید</th><th>پیگیری</th><th>جلسه برگزارشده</th>
            <th>تعداد ارجاع گرفته</th><th>تعداد تماس گرفته</th><th>تعداد تماس نگرفته</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($members as $m): $s = $m['stats']; $rs = $referralStatsByMember[(int) $m['id']] ?? ['received' => 0, 'called' => 0, 'not_called' => 0]; ?>
            <tr>
              <td><?= e($m['full_name']) ?></td>
              <td><?= e($m['role']) ?></td>
              <td><?= to_persian_digits((string) (int) round($s['total_duration'] / 60)) ?></td>
              <?php if ($__rxCol): ?><td><?= to_persian_digits((string) (int) round(($rxApplicant[(int) $m['id']]['seconds'] ?? 0) / 60)) ?></td><?php endif; ?>
              <td><?= to_persian_digits((string) (int) $s['total_calls']) ?></td>
              <td><?= to_persian_digits((string) (int) $s['new_calls']) ?></td>
              <td><?= to_persian_digits((string) (int) $s['old_calls']) ?></td>
              <td><?= to_persian_digits((string) (int) $m['meetings']) ?></td>
              <td><?= to_persian_digits((string) (int) $rs['received']) ?></td>
              <td><?= $rs['called'] > 0 ? '<span class="tlr-called-yes">' . to_persian_digits((string) $rs['called']) . '</span>' : to_persian_digits('0') ?></td>
              <td><?= $rs['not_called'] > 0 ? '<span class="tlr-called-no">' . to_persian_digits((string) $rs['not_called']) . '</span>' : to_persian_digits('0') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<div class="card p-3 mb-4">
  <h6 class="mb-3"><i class="fa-solid fa-share-from-square"></i> ارجاع‌هایی که این سرپرست انجام داده — <?= e($rangeLabel) ?> (<?= to_persian_digits((string) count($referrals)) ?> مورد)</h6>
  <?php if (!$referralTableAvailable): ?>
    <div class="alert alert-warning small mb-0">
      <i class="fa-solid fa-database"></i> قابلیت ارجاع مشتریان هنوز روی دیتابیس فعال نشده است.
    </div>
  <?php elseif (!$team['leader_user_id']): ?>
    <p class="text-muted small mb-0">این تیم فعلاً سرپرستی نداره، پس ارجاعی هم به‌عنوانِ سرپرست ثبت نشده.</p>
  <?php elseif (!$referrals): ?>
    <p class="text-muted small mb-0">در این بازه، این سرپرست ارجاعی ثبت نکرده.</p>
  <?php else: ?>
    <div class="table-responsive">
      <table class="table table-sm align-middle mb-0">
        <thead class="table-light">
          <tr><th>مشتری</th><th>شماره</th><th>از</th><th>به</th><th>تاریخ ارجاع</th><th>وضعیت تماس</th></tr>
        </thead>
        <tbody>
          <?php foreach ($referrals as $r): ?>
            <tr>
              <td><a href="../customer_view.php?id=<?= (int) $r['customer_id'] ?>" class="fw-bold text-decoration-none" target="_blank"><?= e($r['customer_name']) ?></a></td>
              <td dir="ltr"><?= e($r['mobile']) ?></td>
              <td><?= e($r['from_user_name']) ?></td>
              <td><?= e($r['to_user_name']) ?></td>
              <td><?= to_jalali(date('Y-m-d', strtotime($r['created_at']))) ?></td>
              <td>
                <span class="<?= $r['called'] ? 'tlr-called-yes' : 'tlr-called-no' ?>">
                  <i class="fa-solid <?= $r['called'] ? 'fa-phone-volume' : 'fa-phone-slash' ?>"></i>
                  <?= $r['called'] ? 'تماس گرفته شده' : 'هنوز تماس نگرفته' ?>
                </span>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

</div>

<?php require_once __DIR__ . '/../includes/layout_bottom.php'; ?>
