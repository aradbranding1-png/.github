<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/reception_functions.php';
$user = require_login();
$pdo = db();

if (!user_can('reception_agent_panel', $user)) {
    perm_deny('', $user);
}

$moduleReady = reception_module_ready($pdo);
$assignMessage = null;
$assignError = null;

if ($moduleReady && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'request_applicant') {
    if (!csrf_verify()) {
        $assignError = 'نشست شما منقضی شده است، صفحه را رفرش کرده و دوباره تلاش کنید.';
    } else {
        $res = reception_assign_next_applicant($pdo, (int) $user['id'], (string) ($_POST['intake_box'] ?? ''));
        if ($res['ok']) {
            redirect('reception_applicant.php?id=' . $res['applicant_id']);
        } else {
            $assignError = $res['message'];
        }
    }
}

$kpi = [
    'received' => 0, 'called' => 0, 'success_calls' => 0, 'cancelled' => 0,
    'no_answer' => 0, 'followup' => 0, 'referred' => 0, 'accepted' => 0,
    'rejected' => 0, 'remaining_queue' => 0, 'online_meetings' => 0, 'inperson_meetings' => 0,
];
$upcomingInperson = [];
$myApplicants = [];
$dailyPerf = [];
$todayCallSeconds = 0;
$todayCallCount = 0;

if ($moduleReady) {
    try {
        $myId = (int) $user['id'];

        // تعریف واحد آمار روزانه: همه شاخص‌های این پنل برای امروز محاسبه می‌شوند.
        $todayDate = date('Y-m-d');
        $tomorrowDate = date('Y-m-d', strtotime('+1 day'));
        $todayStart = $todayDate . ' 00:00:00';
        $tomorrowStart = $tomorrowDate . ' 00:00:00';

        // ۱) دریافت‌شده: متقاضیانی که امروز به این کارشناس واگذار شده‌اند.
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM reception_applicants WHERE assigned_agent_id = ? AND assigned_at >= ? AND assigned_at < ?');
        $stmt->execute([$myId, $todayStart, $tomorrowStart]);
        $kpi['received'] = (int) $stmt->fetchColumn();

        // ۲) کل تماس‌ها: فقط ورودی واقعی کالیزر، نه reception_calls.
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM followups WHERE created_by = ? AND source = 'call_import' AND followup_date >= ? AND followup_date < ?");
        $stmt->execute([$myId, $todayDate, $tomorrowDate]);
        $kpi['called'] = (int) $stmt->fetchColumn();

        // ۳) نتایج تماس‌های ثبت‌شده در پرونده‌ها؛ این منبع با گزارش ادمین یکسان است.
        foreach (['success' => 'success_calls', 'cancelled' => 'cancelled', 'no_answer' => 'no_answer', 'followup' => 'followup'] as $code => $key) {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM reception_calls WHERE agent_user_id = ? AND result = ? AND started_at >= ? AND started_at < ?');
            $stmt->execute([$myId, $code, $todayStart, $tomorrowStart]);
            $kpi[$key] = (int) $stmt->fetchColumn();
        }

        // ۴) وضعیت‌های عملیاتی: فقط تغییر وضعیتی که امروز توسط همین کارشناس ثبت شده است.
        if (reception_table_exists($pdo, 'reception_status_history')) {
            foreach (['referred_to_supervisor' => 'referred', 'accepted' => 'accepted', 'rejected' => 'rejected'] as $code => $key) {
                $stmt = $pdo->prepare('SELECT COUNT(*) FROM reception_status_history WHERE changed_by = ? AND new_status = ? AND created_at >= ? AND created_at < ?');
                $stmt->execute([$myId, $code, $todayStart, $tomorrowStart]);
                $kpi[$key] = (int) $stmt->fetchColumn();
            }
        }

        // جلسات امروز: میتینگ آنلاین (رزرو تایم سرپرست) در برابر مصاحبه‌ی حضوری
        $mtToday = reception_meeting_type_summary($pdo, $todayStart, $todayDate . ' 23:59:59', $myId);
        $kpi['online_meetings'] = $mtToday['online'];
        $kpi['inperson_meetings'] = $mtToday['inperson'];
        if (reception_inperson_table_ready($pdo)) {
            $st = $pdo->prepare("SELECT ii.*, ra.first_name, ra.last_name, ra.mobile FROM reception_inperson_interviews ii
                JOIN reception_applicants ra ON ra.id = ii.applicant_id
                WHERE ii.agent_user_id = ? AND ii.status = 'scheduled' AND ii.interview_date >= ?
                ORDER BY ii.interview_date ASC, ii.interview_time ASC LIMIT 5");
            $st->execute([$myId, $todayDate]);
            $upcomingInperson = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

        // صف انتظار، آمار روزانه نیست و همیشه تعداد فعلی متقاضیان بدون کارشناس است.
        $kpi['remaining_queue'] = (int) $pdo->query('SELECT COUNT(*) FROM reception_applicants WHERE assigned_agent_id IS NULL')->fetchColumn();

        // فهرست کامل متقاضیانِ این کارشناس با جستجو و صفحه‌بندی ۱۰تایی.
        $applicantSearch = trim((string) ($_GET['q'] ?? ''));
        $applicantPage = max(1, (int) ($_GET['page'] ?? 1));
        $applicantPerPage = 10;
        $applicantWhere = 'WHERE assigned_agent_id = ?';
        $applicantParams = [$myId];
        if ($applicantSearch !== '') {
            $applicantWhere .= " AND (first_name LIKE ? OR last_name LIKE ? OR CONCAT(first_name, ' ', last_name) LIKE ? OR mobile LIKE ?)";
            $searchLike = '%' . $applicantSearch . '%';
            $applicantParams[] = $searchLike;
            $applicantParams[] = $searchLike;
            $applicantParams[] = $searchLike;
            $applicantParams[] = $searchLike;
        }
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM reception_applicants $applicantWhere");
        $countStmt->execute($applicantParams);
        $applicantTotal = (int) $countStmt->fetchColumn();
        $applicantTotalPages = max(1, (int) ceil($applicantTotal / $applicantPerPage));
        if ($applicantPage > $applicantTotalPages) $applicantPage = $applicantTotalPages;
        $applicantOffset = ($applicantPage - 1) * $applicantPerPage;
        $applicantSql = "SELECT * FROM reception_applicants $applicantWhere ORDER BY last_activity_at DESC, created_at DESC LIMIT $applicantPerPage OFFSET $applicantOffset";
        $stmt = $pdo->prepare($applicantSql);
        $stmt->execute($applicantParams);
        $myApplicants = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // مجموع زمان مکالمات امروز: دقیقاً از ورودی کالیزر.
        $stmt = $pdo->prepare("SELECT COUNT(*) AS call_count, COALESCE(SUM(COALESCE(call_duration_seconds, 0)), 0) AS total_seconds
            FROM followups
            WHERE created_by = ? AND source = 'call_import' AND followup_date >= ? AND followup_date < ?");
        $stmt->execute([$myId, $todayDate, $tomorrowDate]);
        $todayCallSummary = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $todayCallCount = (int) ($todayCallSummary['call_count'] ?? 0);
        $todayCallSeconds = (int) ($todayCallSummary['total_seconds'] ?? 0);

        // نمودار روزهای اخیر نیز از همان منبع کالیزر استفاده می‌کند تا با آمار تماس هماهنگ باشد.
        $dailyPerf = [];
        $chartFromDate = date('Y-m-d', strtotime('-13 days'));
        $stmt = $pdo->prepare("SELECT followup_date d, COUNT(*) c FROM followups WHERE created_by = ? AND source = 'call_import' AND followup_date >= ? AND followup_date < ? GROUP BY followup_date ORDER BY followup_date ASC");
        $stmt->execute([$myId, $chartFromDate, $tomorrowDate]);
        $dailyPerf = $stmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
    } catch (Throwable $e) {
        // مقادیر پیش‌فرض باقی می‌مانند.
    }
}
$pageTitle = 'پنلِ پذیرش کارشناس';
require_once __DIR__ . '/includes/layout_top.php';
?>

<style>
.rdb-page{--rdb-line:#e7e2d3;--rdb-ink:#1c1917;--rdb-muted:#78716c;--rdb-gold:#c9a24b;--rdb-gold-2:#f1dfa8;}
.rdb-page .rdb-hero{
  background:linear-gradient(135deg,#0b0f1a 0%,#3d3220 55%,var(--rdb-gold) 130%);
  border-radius:16px; padding:20px 22px; color:#f6efdd; position:relative; overflow:hidden;
  box-shadow:0 10px 30px -18px rgba(28,25,23,.55);
}
.rdb-page .rdb-hero::after{content:'';position:absolute;inset:0;background:radial-gradient(600px 160px at 85% -20%, rgba(241,223,168,.25), transparent 60%);pointer-events:none}
.rdb-page .rdb-hero-icon{width:46px;height:46px;border-radius:12px;display:inline-flex;align-items:center;justify-content:center;background:linear-gradient(135deg,var(--rdb-gold-2),var(--rdb-gold));color:#241d0f;font-size:1.15rem;box-shadow:0 6px 16px -8px rgba(201,162,75,.7)}
.rdb-page .rdb-hero h5{color:#f6efdd;font-weight:800}
.rdb-page .rdb-hero p{color:#e7ddc4}
.rdb-page .glance-box{border:1px solid var(--rdb-line);border-radius:14px;background:#fff;box-shadow:0 4px 14px -12px rgba(28,25,23,.3)}
.rdb-page .glance-num{font-weight:800;font-size:1.4rem;background:linear-gradient(135deg,#8a6d2c,var(--rdb-gold));-webkit-background-clip:text;background-clip:text;color:transparent}
.rdb-page .card{border:1px solid var(--rdb-line);border-radius:16px;box-shadow:0 4px 16px -14px rgba(28,25,23,.3)}
.rdb-page .btn-primary{background:linear-gradient(135deg,var(--rdb-gold-2),var(--rdb-gold));border:none;color:#241708;font-weight:700;box-shadow:0 8px 18px -10px rgba(201,162,75,.6)}
.rdb-page .btn-primary:hover{filter:brightness(.97);color:#241708}
.rdb-page .rdb-request-btn{font-size:1.05rem;padding:.9rem 1.4rem;border-radius:14px}
.rdb-page table thead th{background:linear-gradient(135deg,#0b0f1a 0%,#3d3220 55%,var(--rdb-gold) 130%);color:#f6efdd;border-color:transparent}
.rdb-page .badge-status{padding:.35em .7em;border-radius:20px;font-weight:600;font-size:.75rem}
.rdb-page .today-stat-box{border:1px solid var(--rdb-line);border-radius:14px;background:linear-gradient(180deg,#fff,#fcfaf5);height:100%;}
.rdb-page .today-stat-icon{width:34px;height:34px;margin:0 auto 7px;border-radius:10px;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,var(--rdb-gold-2),var(--rdb-gold));color:#241708;font-size:.9rem}
.rdb-page .today-stat-num{font-weight:800;font-size:1.1rem;color:#6f5520;line-height:1.8}
.rdb-page .pagination .page-link{color:#7a5e24;border-color:var(--rdb-line);border-radius:8px;margin:0 2px}.rdb-page .pagination .page-item.active .page-link{background:linear-gradient(135deg,var(--rdb-gold-2),var(--rdb-gold));border-color:var(--rdb-gold);color:#241708;font-weight:800}.rdb-page .pagination .page-link:hover{background:#faf7ef;color:#5d461b}
</style>

<div class="rdb-page">

<div class="rdb-hero mb-4">
  <div class="d-flex align-items-center gap-2 mb-2 position-relative">
    <span class="rdb-hero-icon"><i class="fa-solid fa-headset"></i></span>
    <h5 class="mb-0">پنلِ پذیرش کارشناس</h5>
  </div>
  <p class="small mb-0 position-relative">خوش آمدید، <?= e($user['full_name']) ?>. از اینجا می‌توانید متقاضیِ جدید درخواست کنید، تماس بگیرید و پیگیری‌ها را ثبت کنید.</p>
</div>

<?php if (!$moduleReady): ?>
  <div class="alert alert-warning py-2">ماژولِ پذیرش هنوز روی سرور فعال نشده است.</div>
<?php else: ?>

<?php if ($assignError): ?><div class="alert alert-danger py-2"><?= e($assignError) ?></div><?php endif; ?>

<?php $__rq = function_exists('rx_queue_counts') ? rx_queue_counts($pdo, (int) $user['id']) : null; ?>
<div class="card p-4 mb-4 text-center">
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="request_applicant">
    <?php if ($__rq): ?>
      <div class="d-flex justify-content-center gap-2 flex-wrap mb-3" role="group" aria-label="باکس">
        <?php foreach (['' => 'همه'] + rx_intake_boxes() as $__bk => $__bl):
          $__n = $__bk === '' ? $__rq['mine'] + $__rq['shared'] : ($__rq['mine_by_box'][$__bk] ?? 0) + ($__rq['shared_by_box'][$__bk] ?? 0); ?>
          <input type="radio" class="btn-check" name="intake_box" id="ib<?= e($__bk ?: 'all') ?>" value="<?= e($__bk) ?>" <?= $__bk === '' ? 'checked' : '' ?>>
          <label class="btn btn-sm btn-outline-secondary" for="ib<?= e($__bk ?: 'all') ?>"><?= e($__bl) ?> <span class="badge text-bg-light border"><?= to_persian_digits((string) $__n) ?></span></label>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <button type="submit" class="btn btn-primary rdb-request-btn"><i class="fa-solid fa-user-plus"></i> درخواست پذیرنده</button>
  </form>
  <?php if ($__rq && $__rq['mine'] > 0): ?>
    <div class="alert alert-warning py-2 small mt-3 mb-0"><i class="fa-solid fa-star"></i> <b><?= to_persian_digits((string) $__rq['mine']) ?> شماره‌ی اختصاصیِ شما</b> در صف است — با «درخواست پذیرنده» <b>اول همین‌ها</b> به شما داده می‌شوند، بعد شماره‌های عمومی.</div>
  <?php endif; ?>
  <div class="text-muted small mt-2"><?= to_persian_digits((string) $kpi['remaining_queue']) ?> متقاضی در صفِ عمومی (بدونِ کارشناس) باقی مانده است.</div>
</div>

<?php
$rpc = null;
if (rp_ready($pdo)) {
    rp_sync($pdo, 1500);
    $rpc = rp_counts($pdo, [(int) $user['id']]);
}
?>
<?php if ($rpc): ?>
<a href="reception_pipeline.php" class="card p-3 mb-4 text-decoration-none" style="border:2px solid #f1dfa8;background:linear-gradient(135deg,#fffdf6,#fff8e6)">
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
    <div class="fw-bold text-dark"><i class="fa-solid fa-route text-warning"></i> امروزِ من — مسیرِ پیگیری</div>
    <span class="btn btn-sm btn-primary"><?= $rpc['due_now'] > 0 ? 'شروعِ پیگیری (' . to_persian_digits((string) $rpc['due_now']) . ' کار)' : 'مشاهده‌ی مسیر' ?> <i class="fa-solid fa-chevron-left"></i></span>
  </div>
  <div class="row g-2 text-center">
    <div class="col-6 col-md"><div class="glance-box p-2"><div class="fw-bold fs-5 text-danger"><?= to_persian_digits((string) $rpc['overdue']) ?></div><div class="small text-muted">عقب‌افتاده</div></div></div>
    <div class="col-6 col-md"><div class="glance-box p-2"><div class="fw-bold fs-5 text-warning"><?= to_persian_digits((string) $rpc['today']) ?></div><div class="small text-muted">پیگیریِ امروز</div></div></div>
    <div class="col-6 col-md"><div class="glance-box p-2"><div class="fw-bold fs-5 text-success"><?= to_persian_digits((string) $rpc['reminders']) ?></div><div class="small text-muted">یادآوریِ جلسه</div></div></div>
    <div class="col-6 col-md"><div class="glance-box p-2"><div class="fw-bold fs-5 text-primary"><?= to_persian_digits((string) $rpc['meetings_today']) ?></div><div class="small text-muted">جلساتِ امروز</div></div></div>
    <div class="col-6 col-md"><div class="glance-box p-2"><div class="fw-bold fs-5" style="color:#7c3aed"><?= to_persian_digits((string) $rpc['boxes']['awaiting']) ?></div><div class="small text-muted">منتظرِ نتیجه‌ی جلسه</div></div></div>
    <div class="col-6 col-md"><div class="glance-box p-2"><div class="fw-bold fs-5 text-danger"><?= to_persian_digits((string) $rpc['boxes']['no_show']) ?></div><div class="small text-muted">عدمِ حضور</div></div></div>
  </div>
</a>
<?php endif; ?>

<div class="row g-2 mb-4 text-center">
  <?php
  $glances = [
      'received' => 'دریافت‌شده', 'called' => 'تماس‌گرفته‌شده', 'success_calls' => 'تماسِ موفق',
      'cancelled' => 'انصرافی', 'no_answer' => 'عدمِ پاسخ', 'followup' => 'در حالِ پیگیری',
      'referred' => 'ارجاع به سرپرست', 'accepted' => 'پذیرش‌شده', 'rejected' => 'ردشده',
      'online_meetings' => 'میتینگ آنلاین', 'inperson_meetings' => 'مصاحبه حضوری',
  ];
  foreach ($glances as $k => $label):
  ?>
  <div class="col-6 col-md-2">
    <div class="glance-box p-3"><div class="glance-num"><?= to_persian_digits((string) $kpi[$k]) ?></div><div class="text-muted small"><?= $label ?></div></div>
  </div>
  <?php endforeach; ?>
</div>

<?php
$hoursToday = intdiv($todayCallSeconds, 3600);
$minutesToday = intdiv($todayCallSeconds % 3600, 60);
$secondsToday = $todayCallSeconds % 60;
$avgCallSeconds = $todayCallCount > 0 ? (int) round($todayCallSeconds / $todayCallCount) : 0;
$avgMinutes = intdiv($avgCallSeconds, 60);
$avgSeconds = $avgCallSeconds % 60;
?>
<div class="card p-3 mb-4" style="border:1px solid #eadfca;border-radius:18px;background:linear-gradient(135deg,#fffdf8,#faf1dc);box-shadow:0 8px 25px -20px rgba(0,0,0,.35);">
  <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
    <div>
      <h6 class="fw-bold mb-1">📅 جلسات پذیرش</h6>
      <div class="small text-muted">میتینگ‌های آنلاین با سرپرست و مصاحبه‌های حضوری — هر دو جداگانه در آمار شمرده می‌شوند.</div>
    </div>
    <div class="d-flex gap-2 flex-wrap">
      <a href="reception_meetings.php" class="btn btn-primary btn-sm"><i class="fa-solid fa-video"></i> میتینگ‌های آنلاین</a>
      <a href="reception_inperson.php?preset=upcoming" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-building-user"></i> مصاحبه‌های حضوری من</a>
    </div>
  </div>
  <?php if ($upcomingInperson): ?>
    <div class="mt-3 small">
      <div class="fw-bold mb-1">مصاحبه‌های حضوریِ پیشِ رو:</div>
      <?php foreach ($upcomingInperson as $ui): ?>
        <a href="reception_applicant.php?id=<?= (int) $ui['applicant_id'] ?>" class="d-inline-block border rounded-pill px-2 py-1 me-1 mb-1 text-decoration-none text-dark bg-white">
          <i class="fa-solid fa-calendar-day text-warning"></i> <?= to_jalali($ui['interview_date']) ?><?= $ui['interview_time'] ? ' ' . e(substr((string) $ui['interview_time'], 0, 5)) : '' ?> — <?= e(trim($ui['first_name'] . ' ' . $ui['last_name'])) ?>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<div class="card p-3 mb-4">
  <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
    <h6 class="fw-bold mb-0">آمارِ امروز</h6>
    <span class="small text-muted">فقط عملکردِ ثبت‌شده در امروز</span>
  </div>
  <div class="row g-2 text-center">
    <div class="col-6 col-md-3">
      <div class="today-stat-box p-3"><div class="today-stat-icon"><i class="fa-solid fa-user-plus"></i></div><div class="today-stat-num"><?= to_persian_digits((string) $kpi['received']) ?></div><div class="text-muted small">متقاضیِ دریافت‌شده</div></div>
    </div>
    <div class="col-6 col-md-3">
      <div class="today-stat-box p-3"><div class="today-stat-icon"><i class="fa-solid fa-phone"></i></div><div class="today-stat-num"><?= to_persian_digits((string) $todayCallCount) ?></div><div class="text-muted small">تعداد تماس</div></div>
    </div>
    <div class="col-6 col-md-3">
      <div class="today-stat-box p-3"><div class="today-stat-icon"><i class="fa-solid fa-clock"></i></div><div class="today-stat-num">
        <?php if ($hoursToday > 0): ?><?= to_persian_digits((string) $hoursToday) ?> ساعت و <?php endif; ?><?= to_persian_digits((string) $minutesToday) ?> دقیقه
      </div><div class="text-muted small">مجموع زمان مکالمات</div></div>
    </div>
    <div class="col-6 col-md-3">
      <div class="today-stat-box p-3"><div class="today-stat-icon"><i class="fa-solid fa-stopwatch"></i></div><div class="today-stat-num"><?= to_persian_digits((string) $avgMinutes) ?>:<?= to_persian_digits(str_pad((string) $avgSeconds, 2, '0', STR_PAD_LEFT)) ?></div><div class="text-muted small">میانگین زمان هر تماس</div></div>
    </div>
  </div>
</div>

<div class="card p-3">
  <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
    <h6 class="fw-bold mb-0">متقاضیانِ من</h6>
    <span class="small text-muted"><?= to_persian_digits((string) $applicantTotal) ?> متقاضی</span>
  </div>

  <form method="get" class="mb-3">
    <div class="row g-2 align-items-center">
      <div class="col-md-9">
        <div class="input-group">
          <span class="input-group-text bg-white"><i class="fa-solid fa-magnifying-glass text-muted"></i></span>
          <input type="text" name="q" value="<?= e($applicantSearch) ?>" class="form-control" placeholder="جستجو بر اساس نام یا شماره موبایل..." autocomplete="off">
        </div>
      </div>
      <div class="col-md-3 d-flex gap-2">
        <button type="submit" class="btn btn-primary flex-grow-1"><i class="fa-solid fa-search"></i> جستجو</button>
        <?php if ($applicantSearch !== ''): ?><a href="reception_dashboard.php" class="btn btn-outline-secondary" title="حذف جستجو"><i class="fa-solid fa-xmark"></i></a><?php endif; ?>
      </div>
    </div>
  </form>

  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
      <thead><tr><th>نام</th><th>موبایل</th><th>وضعیت</th><th>آخرین فعالیت</th><th>عملیات</th></tr></thead>
      <tbody>
        <?php if (!$myApplicants): ?>
          <tr><td colspan="5" class="text-center text-muted py-4"><?= $applicantSearch !== '' ? 'موردی با این جستجو پیدا نشد.' : 'هنوز متقاضی‌ای به شما اختصاص نیافته است.' ?></td></tr>
        <?php endif; ?>
        <?php foreach ($myApplicants as $ap): ?>
        <tr>
          <td><?= e(trim($ap['first_name'] . ' ' . $ap['last_name'])) ?></td>
          <td dir="ltr"><?= e($ap['mobile']) ?></td>
          <td><span class="badge badge-status bg-<?= e(reception_status_color($pdo, $ap['status'])) ?>"><?= e(reception_status_label($pdo, $ap['status'])) ?></span></td>
          <td class="small text-muted"><?= $ap['last_activity_at'] ? to_jalali($ap['last_activity_at']) : '—' ?></td>
          <td><a href="reception_applicant.php?id=<?= (int) $ap['id'] ?>" class="btn btn-sm btn-outline-secondary">مشاهده</a></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php if ($applicantTotalPages > 1): ?>
  <?php
    $pageStart = max(1, $applicantPage - 2);
    $pageEnd = min($applicantTotalPages, $applicantPage + 2);
  ?>
  <nav class="mt-3" aria-label="صفحه‌بندی متقاضیان">
    <ul class="pagination pagination-sm justify-content-center flex-wrap mb-0" dir="rtl">
      <?php if ($applicantPage > 1): ?>
        <li class="page-item"><a class="page-link" href="?<?= http_build_query(['q' => $applicantSearch, 'page' => $applicantPage - 1]) ?>">قبلی</a></li>
      <?php endif; ?>
      <?php for ($pg = $pageStart; $pg <= $pageEnd; $pg++): ?>
        <li class="page-item <?= $pg === $applicantPage ? 'active' : '' ?>"><a class="page-link" href="?<?= http_build_query(['q' => $applicantSearch, 'page' => $pg]) ?>"><?= to_persian_digits((string) $pg) ?></a></li>
      <?php endfor; ?>
      <?php if ($applicantPage < $applicantTotalPages): ?>
        <li class="page-item"><a class="page-link" href="?<?= http_build_query(['q' => $applicantSearch, 'page' => $applicantPage + 1]) ?>">بعدی</a></li>
      <?php endif; ?>
    </ul>
    <div class="text-center small text-muted mt-2">صفحه <?= to_persian_digits((string) $applicantPage) ?> از <?= to_persian_digits((string) $applicantTotalPages) ?></div>
  </nav>
  <?php endif; ?>
</div>


<?php endif; ?>

</div>

<?php require_once __DIR__ . '/includes/layout_bottom.php'; ?>
