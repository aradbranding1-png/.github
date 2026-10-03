<?php
/**
 * هدر مشترک صفحات (بعد از require auth.php در هر صفحه include می‌شود)
 * متغیر اختیاری $pageTitle قبل از include قابل تنظیم است.
 */
$__user = current_user();
$pageTitle = $pageTitle ?? APP_DISPLAY_NAME;

// ماژولِ «اتوماسیون»: فقط وقتی که فایلِ توابعش (و جدول‌هایِ دیتابیسش، بعد از اجرایِ
// database/migration_automation_v1.sql از طریقِ «بروزرسانی سیستم») روی سایت هست، گزینه‌ی
// سایدبار نمایش داده می‌شه — تا قبل از نصبِ کامل، بقیه‌ی صفحات سایت بدونِ خطا کار کنن.
$__automationReady = false;
$__automationUnread = 0;
if ($__user && file_exists(__DIR__ . '/automation_functions.php')) {
    require_once __DIR__ . '/automation_functions.php';
    if (automation_ready(db())) {
        $__automationReady = true;
        $__automationUnread = automation_unread_count(db(), (int) $__user['id']);
    }
}
// مسیر پایه به‌صورت نسبی محاسبه می‌شود (نه بر اساس دامنه ثابت)
// تا سیستم روی هر دامنه یا زیرپوشه‌ای که آپلود شود، بدون نیاز به تنظیم دستی کار کند.
$__inAdmin = (strpos($_SERVER['SCRIPT_NAME'] ?? '', '/admin/') !== false);
$__base = $__inAdmin ? '../' : '';
$__current = basename($_SERVER['SCRIPT_NAME'] ?? '');

// ─────────────────────────────────────────────────────────────────────
// سایدبار: هر گزینه فقط با مجوزِ خودش نمایش داده می‌شود (از «نقش‌ها و دسترسی‌ها»).
// هر بخشی که داخلِ «پنل مدیریت» کارت دارد (کاربران، نقش‌ها، گزارش‌های مدیریتی، پذیرش،
// خدمات، مالی و ...) دیگر در سایدبار تکرار نمی‌شود و فقط از خودِ پنل مدیریت باز می‌شود.
// ─────────────────────────────────────────────────────────────────────
$__can = static function (string $perm) use ($__user): bool {
    return $__user && function_exists('user_can') && user_can($perm, $__user);
};
// ادمینِ کل و ادمین‌ها بخش‌های عملیاتیِ نقش‌های دیگر (پذیرش، سفارش‌های فروشنده) را در سایدبار
// نمی‌بینند؛ از پنلِ مدیریت (هابِ هر بخش) واردشان می‌شوند. این گزینه‌ها فقط برای کسانی است
// که واقعاً در همان نقش کار می‌کنند.
$__isAdminUser = $__user && ((function_exists('is_super_admin') && is_super_admin($__user)) || ($__user['role'] ?? '') === 'admin');
// برای ادمین، «گزارش‌های من» به گزارش‌های مدیریتی می‌رود؛ روی این صفحه‌ها همان گزینه فعال بماند نه «پنل مدیریت»
$__adminReportPages = ['admin_reports.php', 'admin_reports_calls.php', 'admin_reports_calls_detail.php', 'admin_reports_funnel.php',
    'admin_reports_geo.php', 'admin_reports_meetings.php', 'admin_reports_referrals.php', 'admin_reports_staff_growth.php',
    'admin_meeting_bookings_report.php', 'admin_staff_report.php', 'admin_staff_stat_detail.php', 'admin_teams_report.php',
    'admin_today_status_detail.php', 'admin_team_leader_report.php'];
$__onAdminReport = $__isAdminUser && $__inAdmin && in_array($__current, $__adminReportPages, true);
$__navItems = [];
if ($__can('dashboard_view')) {
    $__navItems[] = ['href' => $__base . 'dashboard.php', 'icon' => 'fa-gauge', 'label' => 'داشبورد', 'match' => ['dashboard.php']];
}
if ($__can('customer_list_view')) {
    $__navItems[] = ['href' => $__base . 'customer_list.php', 'icon' => 'fa-list-check', 'label' => 'پیگیری مشتریان',
        'match' => ['customer_list.php', 'customer_view.php', 'customer_edit.php', 'customer_merge.php', 'customer_refer.php', 'customer_new.php', 'service_requests.php', 'service_requests_import.php', 'phone_history.php', 'customer_referrals.php', 'quote_edit.php', 'order_submit.php', 'customer_profile.php']];
}
// مدیریت کاربران: فقط برای ادمینِ کل در سایدبار (بقیه از پنلِ مدیریت)
$__isSuperUser = $__user && function_exists('is_super_admin') && is_super_admin($__user);
$__userMgmtPages = ['admin_users.php', 'admin_user_edit.php', 'admin_user_create.php', 'admin_staff_view.php'];
$__onUserMgmt = $__isSuperUser && $__inAdmin && in_array($__current, $__userMgmtPages, true);
// گزارش فروش: برای ادمینِ کل، ادمین‌ها و مسئولِ مالی مستقیم در سایدبار
$__isFinanceUser = $__user && in_array('financial_liaison', [(string) ($__user['role'] ?? ''), (string) ($__user['service_access_role'] ?? '')], true);
$__salesReportInMenu = ($__isAdminUser || $__isFinanceUser) && $__can('finance_orders_view');
if ($__salesReportInMenu) {
    $__navItems[] = ['href' => $__base . 'admin/admin_orders.php?view=report', 'icon' => 'fa-chart-column', 'label' => 'گزارش فروش',
        'match' => ($__current === 'admin_orders.php' && ($_GET['view'] ?? '') === 'report') ? ['admin_orders.php'] : []];
}
// مدیریت قراردادها: ادمین کل، ادمین، واحدِ قرارداد و واحدِ مالی (مجوزِ contracts_dashboard)
if ($__user && ($__isSuperUser || $__can('contracts_dashboard'))) {
    $__navItems[] = ['href' => $__base . 'contracts_manage.php', 'icon' => 'fa-file-signature', 'label' => 'مدیریت قراردادها', 'match' => ['contracts_manage.php']];
}
if ($__can('supervisor_report_all') || (($__user['role'] ?? '') === 'leader' && $__can('supervisor_report_view'))) {
    $__navItems[] = ['href' => $__base . 'supervisor_report.php', 'icon' => 'fa-user-tie', 'label' => 'گزارش سرپرست', 'match' => ['supervisor_report.php']];
}
if (in_array((string) ($__user['role'] ?? ''), ['A', 'B', 'C'], true) && $__can('box_' . strtolower((string) $__user['role']) . '_access') || $__can('box_manage')) {
    $__navItems[] = ['href' => $__base . 'box.php', 'icon' => 'fa-box-open', 'label' => 'Box مشتریان', 'match' => ['box.php']];
}
if ($__can('perf_view_all') || $__can('perf_payouts_manage')) {
    $__navItems[] = ['href' => $__base . 'admin/admin_perf.php', 'icon' => 'fa-trophy', 'label' => 'سهم عملکرد', 'match' => ['admin_perf.php']];
} elseif ($__can('perf_view_own')) {
    $__navItems[] = ['href' => $__base . 'my_performance.php', 'icon' => 'fa-trophy', 'label' => 'سهم عملکرد من', 'match' => ['my_performance.php']];
}
if ($__can('orders_view_own') && !$__isAdminUser) {
    $__navItems[] = ['href' => $__base . 'my_orders.php', 'icon' => 'fa-cart-shopping', 'label' => 'سفارش‌های من', 'match' => ['my_orders.php', 'order_view.php']];
}
if ($__can('reception_agent_panel') && !$__isAdminUser) {
    $__navItems[] = ['href' => $__base . 'reception_dashboard.php', 'icon' => 'fa-headset', 'label' => 'پنل پذیرش',
        'match' => ['reception_dashboard.php', 'reception_applicant.php', 'reception_meetings.php', 'reception_inperson.php']];
    // مسیرِ پیگیری + تعدادِ کارهایی که همین الان باید انجام شوند
    $__rpDue = 0;
    if (is_file(__DIR__ . '/../storage/.reception_pipeline_v1')) {
        try {
            $__rpSt = db()->prepare("SELECT COUNT(*) FROM reception_pipeline p JOIN reception_applicants ra ON ra.id = p.applicant_id
                WHERE ra.assigned_agent_id = ? AND p.stage <> 'closed' AND p.next_action_at <= ?");
            $__rpSt->execute([(int) ($__user['id'] ?? 0), date('Y-m-d H:i:s')]);
            $__rpDue = (int) $__rpSt->fetchColumn();
        } catch (Throwable $e) {
        }
    }
    $__navItems[] = ['href' => $__base . 'reception_pipeline.php', 'icon' => 'fa-route', 'label' => 'مسیر پیگیری',
        'match' => ['reception_pipeline.php'], 'badge' => $__rpDue];
    $__navItems[] = ['href' => $__base . 'reception_leaderboard.php', 'icon' => 'fa-trophy', 'label' => 'رقابت نیروها',
        'match' => ['reception_leaderboard.php']];
}
if ($__can('personal_reports_view')) {
    $__navItems[] = ['href' => $__base . 'reports.php', 'icon' => 'fa-chart-pie', 'label' => 'گزارش‌های من',
        'match' => [$__onAdminReport ? $__current : 'reports.php', 'reports.php', 'funnel_report.php', 'referral_report.php', 'geo_report.php', 'meeting_report.php', 'team_report.php', 'teams_report.php', 'staff_report.php', 'staff_detail_report.php', 'my_calls_detail.php', 'my_meeting_bookings_report.php']];
}
if ($__can('imports_view')) {
    $__navItems[] = ['href' => $__base . 'imports.php', 'icon' => 'fa-file-import', 'label' => 'ورودی‌ها',
        'match' => ['imports.php', 'call_log_import.php', 'manual_log_import.php', 'vcf_import.php', 'admin_novatel_import.php', 'service_leads_import.php']];
}
if ($__can('chat_view')) {
    $__navItems[] = ['href' => $__base . 'chat.php', 'icon' => 'fa-comments', 'label' => 'گفتگو', 'match' => ['chat.php']];
}
if ($__can('meetings_hub_view')) {
    $__navItems[] = ['href' => $__base . 'meetings_hub.php', 'icon' => 'fa-calendar-check', 'label' => 'جلسات',
        'match' => ['meetings_hub.php', 'meeting_verifications.php', 'meeting_bookings_a.php', 'meeting_bookings_bc.php', 'calendar_book.php', 'calendar_manage.php', 'meeting_booking_history_view.php', 'reception_supervisor_meetings.php']];
}
if ($__automationReady && automation_user_has_permission(db(), $__user, 'letter_view')) {
    $__navItems[] = ['href' => $__base . 'automation_dashboard.php', 'icon' => 'fa-file-signature', 'label' => 'اتوماسیون',
        'match' => ['automation_dashboard.php', 'automation_compose.php', 'automation_letter_view.php', 'automation_letters_list.php', 'automation_reports.php', 'automation_audit_log.php']];
}
$__financePending = 0;
if ($__user && function_exists('user_can') && user_can('finance_orders_decide', $__user) && is_file(__DIR__ . '/../storage/.orders_schema_v1')) {
    try {
        $__financePending = (int) db()->query("SELECT COUNT(*) FROM sales_orders WHERE status = 'pending'")->fetchColumn();
        if (is_file(__DIR__ . '/../storage/.finance_schema_v2')) {
            $__financePending += (int) db()->query("SELECT COUNT(*) FROM sales_order_payments p JOIN sales_orders o ON o.id = p.order_id WHERE p.status = 'pending' AND p.kind <> 'initial' AND o.status = 'approved'")->fetchColumn();
        }
    } catch (Throwable $e) {
        $__financePending = 0;
    }
}
// مدیریت کاربران — درست بالای «پنل مدیریت»
if ($__isSuperUser && $__can('admin_users_view')) {
    $__navItems[] = ['href' => $__base . 'admin/admin_users.php', 'icon' => 'fa-users-gear', 'label' => 'مدیریت کاربران',
        'match' => $__onUserMgmt ? [$__current] : []];
}
if ($__user && function_exists('user_can_any') && (is_super_admin($__user) || user_can_any(perm_admin_panel_keys(), $__user))) {
    $__navItems[] = ['href' => $__base . 'admin/admin_dashboard.php', 'icon' => 'fa-shield-halved', 'label' => 'پنل مدیریت',
        'match' => ($__inAdmin && $__current !== 'admin_novatel_import.php' && !$__onAdminReport && !$__onUserMgmt && !($__salesReportInMenu && $__current === 'admin_orders.php' && ($_GET['view'] ?? '') === 'report')) ? [$__current] : ['admin_dashboard.php']];
}
// «راهنما» همیشه آخرین گزینه‌ی سایدبار است
if ($__can('help_view')) {
    $__navItems[] = ['href' => $__base . 'help.php', 'icon' => 'fa-circle-question', 'label' => 'راهنما', 'match' => ['help.php', 'status_guide.php']];
}

function __nav_render(array $items, string $current): void
{
    global $__automationUnread, $__financePending;
    foreach ($items as $it) {
        $active = in_array($current, $it['match'], true);
        echo '<a class="sidebar-link' . ($active ? ' active' : '') . '" href="' . e($it['href']) . '">';
        echo '<i class="fa-solid ' . e($it['icon']) . '"></i><span>' . e($it['label']) . '</span>';
        if (!empty($it['badge'])) {
            echo '<span class="nav-chat-badge">' . to_persian_digits((string) (int) $it['badge']) . '</span>';
        }
        if (str_ends_with($it['href'], 'chat.php')) {
            echo '<span id="navChatBadge" class="nav-chat-badge" style="display:none"></span>';
        }
        if (str_ends_with($it['href'], 'meetings_hub.php')) {
            echo '<span id="navMeetingVerifBadge" class="nav-chat-badge" style="display:none"></span>';
            echo '<span id="navMeetingBookedBadge" class="nav-chat-badge" style="display:none"></span>';
        }
        if (str_ends_with($it['href'], 'admin/admin_dashboard.php') && $__financePending > 0) {
            echo '<span class="nav-chat-badge" title="سفارش در انتظار بررسی مالی">' . to_persian_digits((string) $__financePending) . '</span>';
        }
        if (str_ends_with($it['href'], 'automation_dashboard.php') && $__automationUnread > 0) {
            echo '<span class="nav-chat-badge">' . to_persian_digits((string) $__automationUnread) . '</span>';
        }
        echo '</a>';
    }
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
<title><?= e($pageTitle) ?> | <?= e(APP_DISPLAY_NAME) ?></title>
<link rel="icon" type="image/svg+xml" href="<?= e($__base) ?>assets/favicon.svg">
<link rel="alternate icon" href="<?= e($__base) ?>assets/favicon.svg">
<link rel="apple-touch-icon" href="<?= e($__base) ?>assets/img/icon-180.png">

<!-- تنظیمات PWA: نصب روی صفحه اصلی گوشی از طریق مرورگر (بدون نیاز به App Store/Google Play) -->
<link rel="manifest" href="<?= e($__base) ?>manifest.json">
<meta name="theme-color" content="#241d0a">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="<?= e(APP_DISPLAY_NAME) ?>">

<link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
<link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
<link rel="dns-prefetch" href="https://cdn.jsdelivr.net">
<link rel="dns-prefetch" href="https://cdnjs.cloudflare.com">

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/Vazirmatn-font-face.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/persian-datepicker@1.2.0/dist/css/persian-datepicker.min.css">
<?php $__styleVer = @filemtime(__DIR__ . '/../assets/css/style.css') ?: time(); ?>
<link rel="stylesheet" href="<?= e($__base) ?>assets/css/style.css?v=<?= $__styleVer ?>">
<?php $__uiVer = @filemtime(__DIR__ . '/../assets/css/ui-polish.css') ?: time(); ?>
<link rel="stylesheet" href="<?= e($__base) ?>assets/css/ui-polish.css?v=<?= $__uiVer ?>">
<script>
  // محدودیت‌های واقعیِ آپلودِ سرور — نوارِ پیشرفتِ آپلود قبل از ارسال با این‌ها مقایسه می‌کند
  window.AradUploadLimits = <?= json_encode([
      'maxFile'  => arad_ini_bytes((string) ini_get('upload_max_filesize')),
      'maxPost'  => arad_ini_bytes((string) ini_get('post_max_size')),
      'maxFiles' => (int) ini_get('max_file_uploads'),
  ]) ?>;
</script>
<script>
if ('serviceWorker' in navigator) {
  window.addEventListener('load', function () {
    navigator.serviceWorker.register('<?= e($__base) ?>sw.js').catch(function () {});
  });
}
</script>
<?php if ($__user): ?>
<script>
  window.AradBase = <?= json_encode($__base, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
  window.AradCsrfToken = <?= json_encode(csrf_token(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
  try { localStorage.setItem('arad_csrf_token', window.AradCsrfToken); } catch (e) {}
</script>
<script src="<?= e($__base) ?>assets/js/offline.js" defer></script>
<?php endif; ?>
<?php if ($__user): ?>
<script>
(function () {
    var heartbeatUrl = <?= json_encode($__base . 'heartbeat.php', JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    var csrfToken = <?= json_encode(csrf_token(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    var sending = false;
    function heartbeat() {
        if (sending || document.visibilityState === 'hidden') return;
        sending = true;
        var body = new URLSearchParams();
        body.set('csrf_token', csrfToken);
        fetch(heartbeatUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
            body: body.toString(),
            keepalive: true
        }).catch(function () {}).finally(function () { sending = false; });
    }
    heartbeat();
    setInterval(heartbeat, 60000);
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') heartbeat();
    });
})();
</script>
<?php endif; ?>
</head>
<body<?= isset($bodyClass) ? ' class="' . e($bodyClass) . '"' : '' ?>>

<div id="pageLoadingOverlay" class="page-loading-overlay">
  <div class="page-loading-box">
    <div class="page-loading-spinner"></div>
    <div class="page-loading-text">در حال بارگذاری...</div>
  </div>
</div>

<?php if ($__user): ?>
<div class="app-shell">

  <!-- سایدبار دسکتاپ -->
  <aside class="sidebar d-none d-lg-flex">
    <a class="sidebar-brand" href="<?= e($__base) ?>dashboard.php">
      <span class="sidebar-brand-icon"><img src="<?= e($__base) ?>assets/img/icon-180.png" alt="<?= e(APP_DISPLAY_NAME) ?>"></span>
      <span><?= e(APP_DISPLAY_NAME) ?></span>
    </a>
    <nav class="sidebar-nav">
      <?php __nav_render($__navItems, $__current); ?>
    </nav>
    <?php if (is_impersonating()): ?>
      <div class="impersonation-banner">
        <div class="impersonation-banner-title"><i class="fa-solid fa-user-secret"></i> مشاهده به‌عنوان کاربر</div>
        <div class="impersonation-banner-name"><?= e($__user['full_name']) ?></div>
        <form method="post" action="<?= e($__base) ?>admin/admin_impersonate_return.php">
          <?= csrf_field() ?>
          <button type="submit" class="btn btn-sm btn-light w-100"><i class="fa-solid fa-arrow-right-from-bracket"></i> بازگشت به حساب خودم</button>
        </form>
      </div>
    <?php endif; ?>
    <div class="sidebar-user">
      <a href="<?= e($__base) ?>profile.php" class="sidebar-user-card">
        <?= avatar_markup($__user, $__base, 'sidebar-avatar') ?>
        <div class="min-w-0">
          <div class="sidebar-user-name"><?= e($__user['full_name']) ?></div>
          <div class="sidebar-user-role"><?= e(role_label($__user['role'])) ?></div>
        </div>
      </a>
      <a href="<?= e($__base) ?>logout.php" class="sidebar-logout js-arad-logout"><i class="fa-solid fa-right-from-bracket"></i> خروج</a>
    </div>
  </aside>

  <!-- منوی موبایل (Offcanvas) -->
  <div class="offcanvas offcanvas-start sidebar-offcanvas" tabindex="-1" id="mobileSidebar">
    <div class="offcanvas-header">
      <a class="sidebar-brand mb-0" href="<?= e($__base) ?>dashboard.php">
        <span class="sidebar-brand-icon"><img src="<?= e($__base) ?>assets/img/icon-180.png" alt="<?= e(APP_DISPLAY_NAME) ?>"></span>
        <span><?= e(APP_DISPLAY_NAME) ?></span>
      </a>
      <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas"></button>
    </div>
    <div class="offcanvas-body d-flex flex-column">
      <nav class="sidebar-nav">
        <?php __nav_render($__navItems, $__current); ?>
      </nav>
      <?php if (is_impersonating()): ?>
        <div class="impersonation-banner mb-3">
          <div class="impersonation-banner-title"><i class="fa-solid fa-user-secret"></i> مشاهده به‌عنوان کاربر</div>
          <div class="impersonation-banner-name"><?= e($__user['full_name']) ?></div>
          <form method="post" action="<?= e($__base) ?>admin/admin_impersonate_return.php">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-sm btn-light w-100"><i class="fa-solid fa-arrow-right-from-bracket"></i> بازگشت به حساب خودم</button>
          </form>
        </div>
      <?php endif; ?>
      <div class="sidebar-user mt-auto">
        <a href="<?= e($__base) ?>profile.php" class="sidebar-user-card">
          <?= avatar_markup($__user, $__base, 'sidebar-avatar') ?>
          <div class="min-w-0">
            <div class="sidebar-user-name"><?= e($__user['full_name']) ?></div>
            <div class="sidebar-user-role"><?= e(role_label($__user['role'])) ?></div>
          </div>
        </a>
        <a href="<?= e($__base) ?>logout.php" class="sidebar-logout js-arad-logout"><i class="fa-solid fa-right-from-bracket"></i> خروج</a>
      </div>
    </div>
  </div>

  <div class="main-content">
    <header class="topbar">
      <div class="d-flex align-items-center gap-2">
        <button class="btn btn-icon d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#mobileSidebar">
          <i class="fa-solid fa-bars"></i>
        </button>
        <h1 class="topbar-title"><?= e($pageTitle) ?></h1>
      </div>
      <div class="d-flex align-items-center gap-2">
        <span id="aradOfflineBadge" class="offline-status-chip d-none d-sm-inline-flex" role="status" onclick="window.AradOffline && window.AradOffline.trySync()" title="برای همگام‌سازیِ فوری کلیک کنید"></span>
        <span class="today-chip d-none d-sm-inline-flex"><i class="fa-regular fa-calendar"></i> <?= today_jalali() ?></span>
        <div class="dropdown">
          <button class="btn topbar-user-btn dropdown-toggle" type="button" data-bs-toggle="dropdown">
            <?= avatar_markup($__user, $__base, 'sidebar-avatar sidebar-avatar-sm') ?>
            <span class="d-none d-md-inline"><?= e($__user['full_name']) ?></span>
          </button>
          <ul class="dropdown-menu dropdown-menu-end">
            <li><span class="dropdown-item-text text-muted small"><?= e(role_label($__user['role'])) ?></span></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item" href="<?= e($__base) ?>profile.php"><i class="fa-solid fa-user-gear"></i> پروفایل من</a></li>
            <li><a class="dropdown-item js-arad-logout" href="<?= e($__base) ?>logout.php"><i class="fa-solid fa-right-from-bracket"></i> خروج</a></li>
          </ul>
        </div>
      </div>
    </header>

    <div class="content-inner">
    <?php foreach (flash_get() as $f): ?>
      <div class="alert alert-<?= e($f['type']) ?> alert-dismissible fade show" role="alert">
        <?= e($f['message']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
      </div>
    <?php endforeach; ?>
<?php else: ?>
    <div class="content-inner content-inner-guest">
    <?php foreach (flash_get() as $f): ?>
      <div class="alert alert-<?= e($f['type']) ?> alert-dismissible fade show" role="alert">
        <?= e($f['message']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
      </div>
    <?php endforeach; ?>
<?php endif; ?>