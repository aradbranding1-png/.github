<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/reception_functions.php';
$admin = require_login();

/*
 * دسترسی مدیریتی کامل به بخش «پذیرش کارشناس»
 *
 * این دسترسی از همان «دسترسی ویژه به درخواست‌های خدمات» در مدیریت کاربران
 * با سطح «ادمین پذیرش کارشناس» تأمین می‌شود.
 *
 * بنابراین:
 * - ادمین اصلی سایت دسترسی دارد.
 * - کاربری که service_access_role = reception_admin دارد نیز دسترسی کامل دارد.
 *
 * تمام آیتم‌های این صفحه تحت همین سطح دسترسی قرار دارند.
 */
$isReceptionAdmin = perm_page_allowed($admin);

if (!$isReceptionAdmin) {
    http_response_code(403);
    die('دسترسی به این بخش ندارید.');
}

$pdo = db();

$moduleReady = reception_module_ready($pdo);

$totalApplicants   = 0;
$totalInQueue      = 0;
$totalReferred     = 0;
$totalAccepted     = 0;
$totalOnline       = 0;
$totalInperson     = 0;

if ($moduleReady) {
    try {
        $totalApplicants = (int) $pdo->query('SELECT COUNT(*) FROM reception_applicants')->fetchColumn();
        $totalInQueue    = (int) $pdo->query("SELECT COUNT(*) FROM reception_applicants WHERE assigned_agent_id IS NULL")->fetchColumn();
        $totalReferred   = (int) $pdo->query("SELECT COUNT(*) FROM reception_applicants WHERE status IN ('referred_to_supervisor','introduced_to_supervisor')")->fetchColumn();
        $totalAccepted   = (int) $pdo->query("SELECT COUNT(*) FROM reception_applicants WHERE status = 'accepted'")->fetchColumn();
        $mtAll = reception_meeting_type_summary($pdo, '2000-01-01 00:00:00', date('Y-m-d 23:59:59'));
        $totalOnline = $mtAll['online'];
        $totalInperson = $mtAll['inperson'];
    } catch (Throwable $e) {
        // در صورتِ خطا، شمارنده‌ها صفر باقی می‌مانند.
    }
}

$pageTitle = 'پذیرش کارشناس';
require_once __DIR__ . '/../includes/layout_top.php';
?>

<style>
.rh-page{ --rh-gold:#c9a24b; --rh-gold-2:#f1dfa8; --rh-ink:#1c1917; --rh-line:rgba(201,162,75,.28); }
.rh-page .btn-outline-secondary{ border-color:var(--rh-line); color:#57534e; }
.rh-page .btn-outline-secondary:hover{ background:#faf7ef; border-color:var(--rh-gold); color:var(--rh-ink); }

.rh-page .rh-hero{
  background:linear-gradient(135deg,#0b0f1a 0%,#3d3220 55%,var(--rh-gold) 130%);
  border-radius:16px; padding:20px 22px; color:#f6efdd; position:relative; overflow:hidden;
  box-shadow:0 10px 30px -18px rgba(28,25,23,.55);
}
.rh-page .rh-hero::after{
  content:''; position:absolute; inset:0;
  background:radial-gradient(600px 160px at 85% -20%, rgba(241,223,168,.25), transparent 60%);
  pointer-events:none;
}
.rh-page .rh-hero-icon{
  width:46px; height:46px; border-radius:12px; display:inline-flex; align-items:center; justify-content:center;
  background:linear-gradient(135deg,var(--rh-gold-2),var(--rh-gold)); color:#241d0f; font-size:1.15rem;
  box-shadow:0 6px 16px -8px rgba(201,162,75,.7);
}
.rh-page .rh-hero h5{ color:#f6efdd; font-weight:800; }
.rh-page .rh-hero p{ color:#e7ddc4; }

.rh-page .rh-stat{
  border:1px solid var(--rh-line); border-radius:14px; background:#fff;
  box-shadow:0 4px 16px -14px rgba(28,25,23,.3);
  transition:box-shadow .15s ease, transform .15s ease;
}
.rh-page .rh-stat:hover{ transform:translateY(-2px); box-shadow:0 10px 22px -14px rgba(28,25,23,.35); }
.rh-page .rh-stat .fs-3{ background:linear-gradient(135deg,#8a6d2c,var(--rh-gold)); -webkit-background-clip:text; background-clip:text; color:transparent; font-weight:800; }

.rh-page .rh-action-card{
  border:1px solid var(--rh-line); border-radius:14px;
  box-shadow:0 4px 16px -14px rgba(28,25,23,.3);
  transition:box-shadow .15s ease, transform .15s ease;
}
.rh-page .rh-action-card:hover{ transform:translateY(-2px); box-shadow:0 10px 24px -16px rgba(28,25,23,.4); }
.rh-page .admin-action-icon{
  background:linear-gradient(135deg,#0b0f1a 0%,#3d3220 55%,var(--rh-gold) 130%)!important;
  color:var(--rh-gold-2)!important;
}
.rh-page .rh-action-card h6{ font-weight:800; color:var(--rh-ink); }
.rh-page .btn-primary{
  background:linear-gradient(135deg,var(--rh-gold-2),var(--rh-gold)); border:none; color:#241d0f; font-weight:700;
  box-shadow:0 6px 14px -8px rgba(201,162,75,.6);
}
.rh-page .btn-primary:hover{ filter:brightness(1.05); color:#241d0f; }
.rh-page .rh-warn{
  border:1px dashed var(--rh-gold); background:#fdf8ec; border-radius:12px; padding:14px 16px; color:#6b4f12;
}
.rh-page .rh-warn code{ color:#8a5a00; }
</style>

<div class="rh-page">
<a href="admin_dashboard.php" class="btn btn-sm btn-outline-secondary mb-3"><i class="fa-solid fa-arrow-right"></i> بازگشت</a>

<div class="rh-hero mb-4">
  <div class="d-flex align-items-center gap-2 mb-2 position-relative">
    <span class="rh-hero-icon"><i class="fa-solid fa-user-plus"></i></span>
    <h5 class="mb-0">پذیرش کارشناس</h5>
  </div>
  <p class="small mb-0 position-relative">مدیریتِ کاملِ ورودیِ متقاضیانِ کارشناس توسعه تجارت — از ثبتِ اکسل تا تماس، پیگیری، تعیینِ سرپرست و گزارش‌های آماری.</p>
</div>

<?php if (!$moduleReady): ?>
<div class="rh-warn mb-4">
  <i class="fa-solid fa-triangle-exclamation me-1"></i>
  جدول‌های این ماژول هنوز روی سرور ایجاد نشده‌اند. لطفاً فایلِ بروزرسانیِ ارسال‌شده (شاملِ <code>database/migration_reception_module.sql</code>) را از طریقِ صفحه‌ی «بروزرسانی سیستم» بارگذاری کنید تا بخش‌های زیر فعال شوند.
</div>
<?php endif; ?>

<div class="row g-2 mb-4 text-center">
  <div class="col-6 col-md-3">
    <div class="rh-stat p-3"><div class="fs-3"><?= to_persian_digits((string) $totalApplicants) ?></div><div class="text-muted small">کلِ متقاضیان</div></div>
  </div>
  <div class="col-6 col-md-3">
    <div class="rh-stat p-3"><div class="fs-3"><?= to_persian_digits((string) $totalInQueue) ?></div><div class="text-muted small">در صفِ انتظار</div></div>
  </div>
  <div class="col-6 col-md-3">
    <div class="rh-stat p-3"><div class="fs-3"><?= to_persian_digits((string) $totalReferred) ?></div><div class="text-muted small">ارجاع‌شده به سرپرست</div></div>
  </div>
  <div class="col-6 col-md-3">
    <div class="rh-stat p-3"><div class="fs-3"><?= to_persian_digits((string) $totalAccepted) ?></div><div class="text-muted small">پذیرش‌شده</div></div>
  </div>
  <div class="col-6 col-md-3">
    <div class="rh-stat p-3"><div class="fs-3 text-info"><?= to_persian_digits((string) $totalOnline) ?></div><div class="text-muted small">دعوت به میتینگ آنلاین</div></div>
  </div>
  <div class="col-6 col-md-3">
    <div class="rh-stat p-3"><div class="fs-3 text-warning"><?= to_persian_digits((string) $totalInperson) ?></div><div class="text-muted small">مصاحبه حضوری</div></div>
  </div>
</div>

<?php
$hubCards = [
    ['admin_reception_reports', 'admin_reception_overview.php', 'fa-gauge-high', 'داشبورد استخدام (کلِ مسیر)', 'از ورود شماره تا تعیین تکلیف در یک صفحه با یک منبعِ آمار: تماس، دعوت، جلسات، حاضر/غایب، جلسه‌های بدونِ آمار، ارجاع به سرپرست؛ روی هر عدد بزنید.'],
    ['admin_reception_import', 'admin_reception_excel.php', 'fa-file-excel', 'اکسل ورودی', 'ثبتِ ورودیِ افرادِ متقاضیِ فعالیت در بخشِ کارشناس توسعه تجارت.'],
    ['admin_reception_candidates', 'admin_reception_applicants_bank.php', 'fa-database', 'بانک متقاضیان', 'فهرستِ کاملِ متقاضیان با جستجو، وضعیت، کارشناس و سرپرست.'],
    ['admin_reception_inperson', '../reception_inperson.php', 'fa-building-user', 'مصاحبه‌های حضوری', 'پیدا کردنِ متقاضیانی که برای مصاحبه‌ی حضوری ثبت شده‌اند + نتیجه‌ی حضور.'],
    ['admin_reception_reports', '../reception_meetings.php', 'fa-video', 'میتینگ‌های آنلاین', 'همه‌ی تایم‌هایی که برای میتینگ آنلاین با سرپرست رزرو شده‌اند.'],
    ['admin_reception_reports', 'admin_reception_funnel.php', 'fa-filter', 'قیف پذیرش (تماس تا حضور)', 'ریزشِ هر مرحله، نرخِ حضور، عملکردِ نیروها در جلو بردنِ افراد، پرونده‌های رهاشده و واگذاریِ مجدد.'],
    ['admin_reception_reports', '../reception_pipeline.php', 'fa-route', 'مسیر پیگیری متقاضیان', 'باکس‌های مرحله‌ای و کارهای عقب‌افتاده‌ی همه‌ی نیروها.'],
    ['admin_reception_reports', '../reception_supervisor_meetings.php', 'fa-user-check', 'ثبت حضور جلسات', 'حاضر/غایبِ جلساتِ امروز برای هر برگزارکننده.'],
    ['admin_reception_reports', 'admin_reception_reports.php', 'fa-chart-column', 'گزارش آماری', 'شاخص‌ها، آنلاین در برابر حضوری، و عملکردِ هر کارشناس.'],
    ['reception_agent_panel', '../reception_dashboard.php', 'fa-headset', 'پنلِ کارشناس پذیرش', 'درخواستِ متقاضی، تماس، پیگیری و ثبتِ جلسه.'],
    ['admin_reception_staff', 'admin_reception_staff.php', 'fa-users-gear', 'کارکنان پذیرش', 'جستجو، عضویتِ جدید، ویرایش و فعال/معلق‌کردنِ نیروها.'],
    ['admin_reception_staff', 'admin_reception_user_create.php', 'fa-user-plus', 'افزودنِ نیروی پذیرش', 'ساختِ دستیِ کاربر با نقشِ «کارشناس پذیرش».'],
    ['admin_reception_staff', 'admin_reception_agents_excel.php', 'fa-file-excel', 'افزودنِ گروهیِ نیرو', 'ثبتِ گروهیِ نیروهای پذیرش از اکسل.'],
    ['admin_reception_supervisors', 'admin_reception_supervisors.php', 'fa-user-tie', 'سرپرست‌ها', 'لینکِ میتینگ، تایم‌های خالی و وضعیتِ سرپرست‌ها.'],
    ['admin_reception_settings', 'admin_reception_settings.php', 'fa-sliders', 'تنظیمات پذیرش', 'وضعیت‌ها و شبکه‌های اجتماعیِ فعال.'],
];
?>
<div class="row g-3">
  <?php foreach ($hubCards as $hc): if (!user_can($hc[0], $admin)) continue; ?>
  <div class="col-md-6 col-lg-4">
    <div class="rh-action-card p-3 h-100 d-flex flex-column">
      <div class="d-flex align-items-center gap-2 mb-2">
        <span class="admin-action-icon"><i class="fa-solid <?= e($hc[2]) ?>"></i></span>
        <h6 class="mb-0 fw-bold"><?= e($hc[3]) ?></h6>
      </div>
      <div class="text-muted small mb-3 flex-grow-1"><?= e($hc[4]) ?></div>
      <a href="<?= e($hc[1]) ?>" class="btn btn-primary btn-sm align-self-start"><i class="fa-solid fa-arrow-left"></i> مشاهده</a>
    </div>
  </div>
  <?php endforeach; ?>
</div>
</div>

<?php require_once __DIR__ . '/../includes/layout_bottom.php'; ?>
