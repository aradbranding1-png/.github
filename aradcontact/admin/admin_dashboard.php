<?php
/**
 * پنل مدیریت — نقطه‌ی ورودِ همه‌ی بخش‌های مدیریتی.
 * هر کارت فقط وقتی نمایش داده می‌شود که کاربر مجوزِ همان بخش را داشته باشد؛
 * این بخش‌ها دیگر در سایدبار تکرار نمی‌شوند.
 */
require_once __DIR__ . '/../includes/auth.php';
$user = require_admin();
$pdo  = db();

$automationOk = false;
if (is_file(__DIR__ . '/../includes/automation_functions.php')) {
    require_once __DIR__ . '/../includes/automation_functions.php';
    $automationOk = automation_ready($pdo);
}
$autoCan = static function (string $code) use ($pdo, $user, $automationOk): bool {
    return $automationOk && automation_user_has_permission($pdo, $user, $code);
};

// شمارنده‌ها (بج روی کارت‌ها)
$pendingOrders = 0;
$pendingPaymentsCnt = 0;
$recvSumDash = ['customers' => 0, 'overdue' => 0, 'unscheduled' => 0];
if (user_can('finance_orders_view', $user) && is_file(__DIR__ . '/../includes/orders_functions.php')) {
    require_once __DIR__ . '/../includes/orders_functions.php';
    if (orders_ready($pdo)) {
        $pendingOrders = orders_count_by_status($pdo, 'pending');
        $pendingPaymentsCnt = count(fin_pending_payments($pdo, 500));
        $recvSumDash = fin_receivables_summary(fin_receivables($pdo));
    }
}
$abtPendingCnt = 0; // تیکت‌های آراد برندینگِ ارسال‌نشده (در صف/ناموفق) — همان فهرستِ صفحه‌ی «ارسال تیکت‌ها» (فقط سفارش‌های تأییدشده)
if (user_can('finance_orders_decide', $user) || user_can('finance_settings', $user)) {
    try { $abtPendingCnt = (int) $pdo->query("SELECT COUNT(DISTINCT t.customer_id) FROM aradbranding_tickets t JOIN sales_orders o ON o.id = t.order_id WHERE t.status IN ('queued','failed') AND o.status = 'approved'")->fetchColumn(); } catch (Throwable $e) {}
}
$pendingUsers = 0;
if (user_can('admin_users_approve', $user)) {
    try { $pendingUsers = (int) $pdo->query('SELECT COUNT(*) FROM users WHERE is_approved = 0')->fetchColumn(); } catch (Throwable $e) {}
}

$sections = [
    [
        'title' => 'کاربران و نقش‌ها', 'icon' => 'fa-users-gear',
        'cards' => [
            ['perm' => 'admin_users_view', 'href' => 'admin_users.php', 'icon' => 'fa-users-gear', 'label' => 'مدیریت کاربران', 'badge' => $pendingUsers, 'g' => '#16305c,#0b1a33'],
            ['perm' => 'admin_users_create', 'href' => 'admin_user_create.php', 'icon' => 'fa-user-plus', 'label' => 'ایجاد کاربر', 'g' => '#1c4a5c,#0d2833'],
            ['perm' => 'admin_roles', 'href' => 'admin_roles.php', 'icon' => 'fa-user-shield', 'label' => 'نقش‌ها و دسترسی‌ها', 'g' => '#40305c,#211733'],
            ['perm' => 'admin_employees', 'href' => 'admin_employees.php', 'icon' => 'fa-id-badge', 'label' => 'کارمندان', 'g' => '#4a3220,#241708'],
            ['perm' => 'admin_teams_manage', 'href' => 'admin_teams_manage.php', 'icon' => 'fa-people-group', 'label' => 'مدیریت تیم‌ها', 'g' => '#1c4a5c,#0d2833'],
            ['perm' => 'admin_users_view', 'href' => 'admin_online_users.php', 'icon' => 'fa-signal', 'label' => 'کاربران آنلاین', 'g' => '#164a30,#0b2b1c'],
            ['perm' => 'admin_customers', 'href' => 'admin_customers.php', 'icon' => 'fa-address-card', 'label' => 'مدیریت مشتریان', 'g' => '#3a1f5c,#211033'],
        ],
    ],
    [
        'title' => 'مالی و سفارشات', 'icon' => 'fa-sack-dollar',
        'cards' => [
            ['perm' => 'finance_orders_view', 'href' => 'admin_orders.php', 'icon' => 'fa-file-invoice-dollar', 'label' => 'سفارشات و بررسی مالی', 'badge' => $pendingOrders, 'g' => '#0f4a2c,#07291a'],
            ['perm' => 'finance_orders_view', 'href' => 'admin_orders.php?view=receivables', 'icon' => 'fa-hand-holding-dollar', 'label' => 'مطالبات، بدهکاران و اقساط', 'badge' => (int) $recvSumDash['customers'], 'g' => '#5c1420,#33090f'],
            ['perm' => 'finance_orders_view', 'href' => 'admin_orders.php?view=payments', 'icon' => 'fa-money-bill-transfer', 'label' => 'پرداخت‌های در انتظار تأیید', 'badge' => $pendingPaymentsCnt, 'g' => '#5c4a14,#332a0b'],
            ['perm' => 'finance_orders_view', 'href' => 'admin_orders.php?view=report', 'icon' => 'fa-chart-column', 'label' => 'گزارش فروش', 'g' => '#164a30,#0b2b1c'],
            ['perm' => 'finance_settings', 'href' => 'admin_financial_settings.php', 'icon' => 'fa-percent', 'label' => 'تنظیمات مالی (مالیات)', 'g' => '#5c4a14,#332a0b'],
            ['perm' => 'finance_settings', 'href' => 'admin_invoice_settings.php', 'icon' => 'fa-file-pdf', 'label' => 'تنظیمات فاکتور و PDF', 'g' => '#4a3410,#2b1e09'],
            ['perm' => 'finance_settings', 'href' => 'admin_contract_template.php', 'icon' => 'fa-file-contract', 'label' => 'قالب قرارداد', 'g' => '#3b2a4a,#1f1528'],
            ['perm' => 'finance_orders_decide', 'href' => 'admin_aradbranding_send.php', 'icon' => 'fa-paper-plane', 'label' => 'ارسال تیکت‌ها', 'badge' => $abtPendingCnt, 'g' => '#1e4a5f,#0f2833'],
            ['perm' => 'finance_settings', 'href' => 'admin_aradbranding_ticket.php', 'icon' => 'fa-sliders', 'label' => 'تنظیمات تیکت', 'g' => '#1e3a5f,#0f1f33'],
            ['perm' => 'perf_view_all', 'href' => 'admin_perf.php', 'icon' => 'fa-trophy', 'label' => 'سهم عملکرد کارشناسان', 'g' => '#6b4e16,#3a2a0b'],
            ['perm' => 'admin_system_update', 'href' => 'admin_error_log.php', 'icon' => 'fa-bug', 'label' => 'گزارش خطاهای سیستم', 'g' => '#5c1f1f,#331010'],
        ],
    ],
    [
        'title' => 'گزارش‌های مدیریتی', 'icon' => 'fa-chart-line',
        'cards' => [
            ['perm' => 'reports_view', 'href' => 'admin_reports.php', 'icon' => 'fa-chart-line', 'label' => 'گزارش‌های مدیریتی', 'g' => '#0b3a4a,#06212b'],
            ['perm' => 'reports_view', 'href' => 'admin_teams_report.php', 'icon' => 'fa-people-line', 'label' => 'گزارش تیم‌ها', 'g' => '#1a3a4a,#0d222b'],
            ['perm' => 'reports_view', 'href' => 'admin_staff_report.php', 'icon' => 'fa-chart-bar', 'label' => 'گزارش نیروها', 'g' => '#232b5c,#131933'],
            ['perm' => 'reports_view', 'href' => 'admin_meeting_bookings_report.php', 'icon' => 'fa-calendar-days', 'label' => 'گزارش رزرو جلسات', 'g' => '#0f4a4a,#082b2b'],
        ],
    ],
    [
        'title' => 'پذیرش کارشناس', 'icon' => 'fa-user-plus',
        'cards' => [
            ['perm' => 'admin_reception_hub', 'href' => 'admin_reception_hub.php', 'icon' => 'fa-user-plus', 'label' => 'پنل مدیریت پذیرش', 'g' => '#1a3a4a,#0d222b'],
            ['perm' => 'admin_reception_reports', 'href' => 'admin_reception_funnel.php', 'icon' => 'fa-filter', 'label' => 'قیف پذیرش نیرو', 'g' => '#5b3a0b,#2b1c06'],
            ['perm' => 'admin_reception_reports', 'href' => 'admin_reception_reports.php', 'icon' => 'fa-chart-column', 'label' => 'آمار پذیرش', 'g' => '#0b3a4a,#06212b'],
            ['perm' => 'admin_reception_inperson', 'href' => '../reception_inperson.php', 'icon' => 'fa-building-user', 'label' => 'مصاحبه‌های حضوری', 'g' => '#5c3d1a,#33210c'],
            ['perm' => 'admin_reception_candidates', 'href' => 'admin_reception_applicants_bank.php', 'icon' => 'fa-database', 'label' => 'بانک متقاضیان', 'g' => '#3a1a4a,#220d2b'],
        ],
    ],
    [
        'title' => 'خدمات', 'icon' => 'fa-briefcase',
        'cards' => [
            ['perm' => 'admin_services_hub', 'href' => 'admin_services_hub.php', 'icon' => 'fa-list-check', 'label' => 'فهرست خدمات', 'g' => '#0b3a4a,#06212b'],
            ['perm' => 'admin_traders_services_import', 'href' => 'admin_traders_services_import.php', 'icon' => 'fa-bag-shopping', 'label' => 'خدمات تاجران', 'g' => '#5c4a14,#332a0b'],
        ],
    ],
    [
        'title' => 'مدیریت اتوماسیون', 'icon' => 'fa-file-signature',
        'cards' => [
            ['check' => $autoCan('org_structure_manage'), 'href' => 'admin_automation_org.php', 'icon' => 'fa-sitemap', 'label' => 'ساختار سازمانی', 'g' => '#232b5c,#131933'],
            ['check' => $autoCan('org_members_manage'), 'href' => 'admin_automation_org_members.php', 'icon' => 'fa-users-viewfinder', 'label' => 'اعضای سازمان', 'g' => '#1c4a5c,#0d2833'],
            ['check' => $autoCan('permission_levels_manage'), 'href' => 'admin_automation_permissions.php', 'icon' => 'fa-layer-group', 'label' => 'سطوح اجازه اتوماسیون', 'g' => '#40305c,#211733'],
            ['check' => $autoCan('user_permission_level_manage'), 'href' => 'admin_automation_user_permissions.php', 'icon' => 'fa-user-lock', 'label' => 'تخصیص سطح به کاربران', 'g' => '#4a1f3d,#2b1122'],
        ],
    ],
    [
        'title' => 'ابزارهای داده', 'icon' => 'fa-screwdriver-wrench',
        'cards' => [
            ['perm' => 'admin_phone_conflicts', 'href' => 'admin_phone_conflicts.php', 'icon' => 'fa-triangle-exclamation', 'label' => 'تداخل شماره‌ها', 'g' => '#5c1420,#33090f'],
            ['perm' => 'admin_phone_diagnostic', 'href' => 'admin_phone_diagnostic.php', 'icon' => 'fa-magnifying-glass', 'label' => 'بررسی سابقه‌ی یک شماره', 'g' => '#3a1f5c,#211033'],
            ['perm' => 'admin_redistribute_followups', 'href' => 'admin_redistribute_followups.php', 'icon' => 'fa-calendar-days', 'label' => 'توزیع سررسیدهای پیگیری', 'g' => '#0f4a4a,#082b2b'],
            ['perm' => 'admin_advisor_transfer_import', 'href' => 'admin_advisor_transfer_import.php', 'icon' => 'fa-user-tag', 'label' => 'تعیین کارشناس اول', 'g' => '#164a30,#0b2b1c'],
            ['perm' => 'admin_bulk_referral', 'href' => 'admin_bulk_referral.php', 'icon' => 'fa-people-arrows', 'label' => 'ارجاع دسته‌جمعی', 'g' => '#232b5c,#131933'],
            ['perm' => 'admin_acquaintances_manage', 'href' => 'admin_acquaintances_manage.php', 'icon' => 'fa-address-book', 'label' => 'مدیریت آشنایان', 'g' => '#4a1f3d,#2b1122'],
            ['perm' => 'admin_complainants_import', 'href' => 'admin_complainants_import.php', 'icon' => 'fa-user-slash', 'label' => 'مشتریان شاکی', 'g' => '#4a0e18,#2b0810'],
            ['perm' => 'admin_fix_invalid_statuses', 'href' => 'admin_fix_invalid_statuses.php', 'icon' => 'fa-broom', 'label' => 'اصلاح وضعیت‌های نامعتبر', 'g' => '#5c2e10,#331a09'],
            ['perm' => 'admin_fix_future_dates', 'href' => 'admin_fix_future_dates.php', 'icon' => 'fa-wrench', 'label' => 'اصلاح تاریخ‌های آینده', 'g' => '#5c3d1a,#33210c'],
            ['perm' => 'admin_call_conferences', 'href' => 'admin_call_conferences.php', 'icon' => 'fa-people-group', 'label' => 'تشخیص کنفرانس تماس', 'g' => '#3a1a4a,#220d2b'],
            ['perm' => 'admin_call_conferences', 'href' => 'admin_fix_conference_calls.php', 'icon' => 'fa-users-rectangle', 'label' => 'اصلاح تماس‌های کنفرانسی قدیمی', 'g' => '#5c1a3a,#330d21'],
            ['perm' => 'admin_clear_call_import', 'href' => 'admin_clear_call_import.php', 'icon' => 'fa-eraser', 'label' => 'پاکسازی گزارش تماس', 'g' => '#33424a,#1a2226'],
            ['perm' => 'admin_ai_name_cleanup', 'href' => 'admin_ai_name_cleanup.php', 'icon' => 'fa-wand-magic-sparkles', 'label' => 'پاکسازی نام‌ها با AI', 'g' => '#4a1a5c,#2b0d33'],
            ['perm' => 'admin_colleague_mismatch_check', 'href' => 'admin_colleague_mismatch_check.php', 'icon' => 'fa-user-check', 'label' => 'مغایرت شماره همکار', 'g' => '#1a3a4a,#0d222b'],
            ['perm' => 'admin_cities_manage', 'href' => 'admin_cities_manage.php', 'icon' => 'fa-map', 'label' => 'استان‌ها و شهرها', 'g' => '#2f4a1a,#182b0d'],
        ],
    ],
    [
        'title' => 'سیستم', 'icon' => 'fa-server',
        'cards' => [
            ['perm' => 'admin_system_update', 'href' => 'admin_system_update.php', 'icon' => 'fa-cloud-arrow-up', 'label' => 'بروزرسانی سیستم', 'g' => '#2b3138,#14181c'],
            ['perm' => 'admin_api_keys', 'href' => 'admin_api_keys.php', 'icon' => 'fa-key', 'label' => 'کلیدهای API', 'g' => '#4a3410,#2b1e09'],
            ['perm' => 'admin_api_keys', 'href' => 'admin_external_apis.php', 'icon' => 'fa-plug', 'label' => 'اتصال‌های بیرونی', 'g' => '#33424a,#1a2226'],
            ['perm' => 'admin_system_tools', 'href' => 'admin_migration_status.php', 'icon' => 'fa-database', 'label' => 'وضعیت مایگریشن‌ها', 'g' => '#2b3138,#14181c'],
            ['perm' => 'admin_system_tools', 'href' => 'admin_vapid_setup.php', 'icon' => 'fa-bell', 'label' => 'اعلان‌های Push', 'g' => '#40305c,#211733'],
            ['perm' => 'admin_system_tools', 'href' => 'admin_leader_diagnostic.php', 'icon' => 'fa-stethoscope', 'label' => 'عیب‌یابی سرپرست‌ها', 'g' => '#164a30,#0b2b1c'],
        ],
    ],
];

$visible = [];
foreach ($sections as $sec) {
    $cards = [];
    foreach ($sec['cards'] as $c) {
        $ok = array_key_exists('check', $c) ? (bool) $c['check'] : user_can($c['perm'], $user);
        if ($ok) $cards[] = $c;
    }
    if ($cards) {
        $sec['cards'] = $cards;
        $visible[] = $sec;
    }
}

$showStats = user_can('admin_dashboard', $user) || user_can('admin_users_view', $user);
$unitStaffCounts = ['A' => 0, 'B' => 0, 'C' => 0];
$leaderCount = 0;
if ($showStats) {
    foreach ($pdo->query("SELECT role, COUNT(*) cnt FROM users WHERE role IN ('A','B','C') AND is_active = 1 GROUP BY role")->fetchAll() as $r) {
        $unitStaffCounts[$r['role']] = (int) $r['cnt'];
    }
    $leaderCount = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'leader' AND is_active = 1")->fetchColumn();
}

$pageTitle = 'پنل مدیریت';
require_once __DIR__ . '/../includes/layout_top.php';
?>
<style>
.ad-page{--ad-line:#e7e2d3;--ad-ink:#1c1917;--ad-muted:#78716c;--ad-gold:#c9a24b;--ad-gold-2:#f1dfa8}
.ad-page .ad-hero{background:linear-gradient(135deg,#0b0f1a 0%,#3d3220 55%,var(--ad-gold) 130%);border-radius:18px;padding:18px 22px;color:#f6efdd;margin-bottom:18px;display:flex;align-items:center;gap:12px}
.ad-page .ad-hero .ic{width:46px;height:46px;border-radius:12px;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,var(--ad-gold-2),var(--ad-gold));color:#241708;font-size:1.1rem}
.ad-page .ad-hero h5{margin:0;font-weight:800;color:#f6efdd}
.ad-page .ad-hero p{margin:.2rem 0 0;font-size:.78rem;color:#e7ddc4}
.ad-page .ad-sec{margin-bottom:18px}
.ad-page .ad-sec-h{display:flex;align-items:center;gap:.5rem;font-weight:800;color:var(--ad-ink);font-size:.92rem;margin:0 0 10px}
.ad-page .ad-sec-h i{color:var(--ad-gold)}
.ad-page .ad-sec-h::after{content:'';flex:1;height:1px;background:var(--ad-line);margin-inline-start:.5rem}
.ad-page .admin-action-card{position:relative;border:1px solid var(--ad-line);border-radius:16px;box-shadow:0 4px 16px -14px rgba(28,25,23,.3);transition:.15s ease;background:#fff;height:100%}
.ad-page .admin-action-card:hover{box-shadow:0 10px 22px -14px rgba(28,25,23,.35);transform:translateY(-3px);border-color:var(--ad-gold-2)}
.ad-page .admin-action-card .label{font-weight:700;color:var(--ad-ink);font-size:.82rem}
.ad-page .admin-action-icon{box-shadow:inset 0 0 0 1px rgba(201,162,75,.35),0 6px 14px -8px rgba(0,0,0,.5)}
.ad-page .ad-badge{position:absolute;top:8px;left:8px;background:#b91c1c;color:#fff;font-size:.66rem;font-weight:800;border-radius:999px;padding:1px 7px}
.ad-page .stat-card-clickable{border:1px solid var(--ad-line);border-radius:16px;box-shadow:0 4px 16px -14px rgba(28,25,23,.3)}
.ad-page .empty-notice{padding:14px 16px;border:1px dashed #d8cfa8;border-radius:12px;background:#fffdf4;color:#7b6f4a;font-size:12px;text-align:center}
</style>

<div class="ad-page">
  <div class="ad-hero">
    <span class="ic"><i class="fa-solid fa-shield-halved"></i></span>
    <div>
      <h5>پنل مدیریت</h5>
      <p>همه‌ی بخش‌های مدیریتی این‌جا هستند؛ فقط بخش‌هایی که نقشِ شما مجوزشان را دارد نمایش داده می‌شوند.</p>
    </div>
  </div>

  <?php if ($showStats): ?>
  <div class="row g-3 mb-4">
    <?php foreach ([['A', 'واحد A', 'text-primary', $unitStaffCounts['A'], 'کارشناس'], ['B', 'واحد B', 'text-success', $unitStaffCounts['B'], 'کارشناس'], ['C', 'واحد C', 'text-warning', $unitStaffCounts['C'], 'کارشناس'], ['leader', 'سرپرست‌ها', 'text-info', $leaderCount, 'سرپرست']] as $st): ?>
      <div class="col-6 col-md-3">
        <a href="<?= user_can('admin_users_view', $user) ? 'admin_users.php?role=' . e($st[0]) : '#' ?>" class="text-decoration-none">
          <div class="card p-3 text-center h-100 stat-card-clickable">
            <div class="text-muted small"><?= e($st[1]) ?></div>
            <div class="fs-3 fw-bold <?= $st[2] ?>"><?= to_persian_digits((string) $st[3]) ?></div>
            <div class="small text-muted"><?= e($st[4]) ?></div>
          </div>
        </a>
      </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php if (!$visible): ?>
    <div class="empty-notice"><i class="fa-solid fa-circle-info"></i> شما به هیچ‌کدام از بخش‌های پنل مدیریت دسترسی ندارید.</div>
  <?php endif; ?>

  <?php foreach ($visible as $sec): ?>
    <div class="ad-sec">
      <div class="ad-sec-h"><i class="fa-solid <?= e($sec['icon']) ?>"></i> <?= e($sec['title']) ?></div>
      <div class="row g-2">
        <?php foreach ($sec['cards'] as $c): ?>
          <div class="col-6 col-md-4 col-xl-2">
            <a href="<?= e($c['href']) ?>" class="admin-action-card">
              <?php if (!empty($c['badge'])): ?><span class="ad-badge"><?= to_persian_digits((string) $c['badge']) ?></span><?php endif; ?>
              <span class="admin-action-icon" style="background:linear-gradient(135deg,<?= e($c['g']) ?>);color:#e8c874;"><i class="fa-solid <?= e($c['icon']) ?>"></i></span>
              <span class="label"><?= e($c['label']) ?></span>
            </a>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<?php require_once __DIR__ . '/../includes/layout_bottom.php'; ?>
