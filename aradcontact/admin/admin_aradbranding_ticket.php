<?php
/**
 * «تنظیمات تیکت»: اتصال به آراد برندینگ (aradbranding.me)، واحدها و قالبِ متنِ تیکت.
 * فهرست و ارسالِ تیکت‌ها ← admin_aradbranding_send.php
 */
require_once __DIR__ . '/../includes/auth.php';
$admin = require_admin();
$pdo = db();
require_once __DIR__ . '/../includes/services_functions.php';
require_once __DIR__ . '/../includes/orders_functions.php';
require_once __DIR__ . '/../includes/contracts_functions.php';
require_once __DIR__ . '/../includes/aradbranding_ticket.php';

if (!abt_ready($pdo)) {
    flash_set('danger', 'جدول‌های تیکتِ آراد برندینگ ساخته نشدند.');
    redirect('admin_dashboard.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        flash_set('danger', 'نشست منقضی شده است؛ دوباره تلاش کنید.');
        redirect('admin_aradbranding_ticket.php');
    }
    if (($_POST['action'] ?? '') === 'fetch_departments') {
        $r = abt_fetch_departments($pdo, abt_settings($pdo), (int) $admin['id']);
        $_SESSION['abt_departments'] = $r;
        flash_set($r['ok'] ? 'success' : 'danger', $r['message']);
        redirect('admin_aradbranding_ticket.php#abt-departments');
    }
    // ─── سامانه‌ی CRM ───
    if (in_array($_POST['action'] ?? '', ['save_crm', 'test_crm'], true)) {
        require_once __DIR__ . '/../includes/crm_provision.php';
        $map = [];
        foreach ((array) ($_POST['crm_map'] ?? []) as $sid => $code) {
            $code = (string) $code;
            if ($code === '') continue;
            if ($code === '-' || isset(CRM_PLANS[$code])) $map[(string) (int) $sid] = $code;
        }
        $data = [
            'crm_enabled'   => !empty($_POST['crm_enabled']) ? '1' : '0',
            'crm_base_url'  => rtrim(trim((string) ($_POST['crm_base_url'] ?? '')), '/') ?: 'https://crm.aradbranding.me',
            'crm_login_url' => trim((string) ($_POST['crm_login_url'] ?? '')) ?: 'https://crm.aradbranding.me/login.php',
            'crm_map_json'  => json_encode($map, JSON_UNESCAPED_UNICODE),
        ];
        $tok = trim((string) ($_POST['crm_token'] ?? ''));
        if ($tok !== '') $data['crm_token'] = $tok;
        abt_settings_save($pdo, $data, (int) $admin['id']);
        if ($_POST['action'] === 'test_crm') {
            $r = crm_test($pdo);
            flash_set($r['ok'] ? 'success' : 'danger', 'تنظیمات ذخیره شد. ' . $r['message']);
        } else {
            flash_set('success', 'تنظیماتِ سامانه‌ی CRM ذخیره شد.');
        }
        redirect('admin_aradbranding_ticket.php#abt-crm');
    }
    // ─── سامانه‌ی آموزش ───
    if (in_array($_POST['action'] ?? '', ['save_edu', 'test_edu'], true)) {
        require_once __DIR__ . '/../includes/edu_provision.php';
        {   // «تستِ اتصال» هم اول تنظیماتِ همین فرم را ذخیره می‌کند
            $map = [];
            foreach ((array) ($_POST['edu_map'] ?? []) as $sid => $code) {
                $code = (string) $code;
                if ($code === '' ) continue;              // خالی = تشخیصِ خودکار از روی عنوان
                if ($code === '-' || isset(EDU_CODES[$code])) $map[(string) (int) $sid] = $code;
            }
            $data = [
                'edu_enabled'  => !empty($_POST['edu_enabled']) ? '1' : '0',
                'edu_base_url' => rtrim(trim((string) ($_POST['edu_base_url'] ?? '')), '/') ?: 'https://edu.aradbranding.me',
                'edu_map_json' => json_encode($map, JSON_UNESCAPED_UNICODE),
            ];
            $tok = trim((string) ($_POST['edu_token'] ?? ''));
            if ($tok !== '') $data['edu_token'] = $tok;   // خالی = بدونِ تغییر
            abt_settings_save($pdo, $data, (int) $admin['id']);
        }
        if ($_POST['action'] === 'test_edu') {
            $r = edu_test($pdo);
            flash_set($r['ok'] ? 'success' : 'danger', 'تنظیمات ذخیره شد. ' . $r['message']);
        } else {
            flash_set('success', 'تنظیماتِ سامانه‌ی آموزش ذخیره شد.');
        }
        redirect('admin_aradbranding_ticket.php#abt-edu');
    }
    // ─── تنظیماتِ تیکتِ «اسنادِ قرارداد» ───
    if (($_POST['action'] ?? '') === 'save_contract_ticket') {
        $days = (int) normalize_digits((string) ($_POST['ctr_link_days'] ?? '30'));
        $body = str_replace(["\r\n", "\r"], "\n", trim((string) ($_POST['ctr_body_tpl'] ?? '')));
        abt_settings_save($pdo, [
            'ctr_department'    => mb_substr(trim((string) ($_POST['ctr_department'] ?? '')), 0, 100) ?: 'قرارداد',
            'ctr_subject_tpl'   => mb_substr(trim((string) ($_POST['ctr_subject_tpl'] ?? '')), 0, 250),
            'ctr_body_tpl'      => mb_strlen($body) < 20 ? '' : $body,
            'ctr_link_days'     => (string) ($days >= 1 && $days <= 365 ? $days : 30),
            'field_attachments' => mb_substr(preg_replace('/[^A-Za-z0-9_\-\[\]]/', '', (string) ($_POST['field_attachments'] ?? '')), 0, 60),
        ], (int) $admin['id']);
        flash_set('success', 'تنظیماتِ تیکتِ قرارداد ذخیره شد.');
        redirect('admin_aradbranding_ticket.php#abt-contract');
    }
    $cur = abt_settings($pdo);
    $in = static fn(string $k, int $max = 500): string => mb_substr(trim((string) ($_POST[$k] ?? '')), 0, $max);
    $data = [
        'enabled'        => isset($_POST['enabled']) ? '1' : '0',
        'auto_send'      => isset($_POST['auto_send']) ? '1' : '0',
        'verify_ssl'     => isset($_POST['verify_ssl']) ? '1' : '0',
        'api_url'        => $in('api_url'),
        'body_format'    => ($_POST['body_format'] ?? 'json') === 'form' ? 'form' : 'json',
        'auth_style'     => in_array($_POST['auth_style'] ?? '', ['none', 'header', 'bearer', 'query', 'body'], true) ? (string) $_POST['auth_style'] : 'header',
        'auth_name'      => $in('auth_name', 100),
        'field_phone'    => $in('field_phone', 60),
        'field_name'     => $in('field_name', 60),
        'field_national' => $in('field_national', 60),
        'field_subject'  => $in('field_subject', 60),
        'field_message'  => $in('field_message', 60),
        'field_ref'      => $in('field_ref', 60),
        'field_external' => $in('field_external', 60),
        'field_department' => $in('field_department', 60),
        'default_department' => $in('default_department', 100),
        'extra_json'     => trim((string) ($_POST['extra_json'] ?? '')),
        'subject_tpl'    => $in('subject_tpl', 250),
        'body_tpl'       => str_replace(["\r\n", "\r"], "\n", trim((string) ($_POST['body_tpl'] ?? ''))),
    ];
    // کلید فقط وقتی عوض می‌شود که چیزی وارد شده باشد (خالی = کلیدِ قبلی بماند)
    if (trim((string) ($_POST['auth_key'] ?? '')) !== '') {
        $data['auth_key'] = trim((string) $_POST['auth_key']);
    }
    $errors = [];
    if ($data['api_url'] !== '' && !preg_match('#^https?://#i', $data['api_url'])) {
        $errors[] = 'آدرس API باید با http:// یا https:// شروع شود.';
    }
    if ($data['extra_json'] !== '' && !is_array(json_decode($data['extra_json'], true))) {
        $errors[] = '«فیلدهای ثابت» باید JSON معتبر باشد، مثلاً {"department": 3}.';
    }
    if ($data['enabled'] === '1' && $data['api_url'] === '') {
        $errors[] = 'برای فعال‌کردنِ اتصال، آدرس API لازم است.';
    }
    if ($data['subject_tpl'] === '') $data['subject_tpl'] = abt_default_subject();
    if (mb_strlen($data['body_tpl']) < 20) $data['body_tpl'] = abt_default_body();
    if ($errors) {
        flash_set('danger', implode(' ', $errors));
    } else {
        abt_settings_save($pdo, $data, (int) $admin['id']);
        flash_set('success', 'تنظیماتِ تیکتِ آراد برندینگ ذخیره شد.');
    }
    redirect('admin_aradbranding_ticket.php');
}

$s = abt_settings($pdo);
$ready = abt_connection_ready($s);
$deptResult = $_SESSION['abt_departments'] ?? null;
unset($_SESSION['abt_departments']);
if (!$deptResult && ($__saved = abt_departments($s))) {
    $deptResult = ['items' => array_map(static fn($id, $name) => ['id' => (string) $id, 'name' => (string) $name], array_keys($__saved), array_values($__saved)), 'raw' => ''];
}
// پیش‌نمایش با داده‌ی نمونه
$sampleVars = [
    'عنوان' => 'آقای', 'نام_مشتری' => 'نمونه نمونه‌زاده', 'موبایل' => '۰۹۱۲۰۰۰۰۰۰۰', 'شماره_سفارش' => 'INV-۱۴۰۵-۰۰۴۵',
    'شماره_پیش_فاکتور' => 'Q-۱۴۰۵-۰۰۴۵', 'شماره_قرارداد' => '۱۴۰۵-۰۰۱۲', 'تاریخ_تایید' => to_jalali(date('Y-m-d')),
    'فهرست_خدمات' => "۱. طراحی لوگو\n۲. تولید محتوا — ۱۲ عدد\n۳. سئوی سایت", 'تعداد_خدمات' => '۳',
    'مبلغ_فاکتور' => '۲۵۰,۰۰۰,۰۰۰', 'مبلغ_پرداختی' => '۱۰۰,۰۰۰,۰۰۰', 'مانده_بدهی' => '۱۵۰,۰۰۰,۰۰۰', 'کارشناس' => 'کارشناس نمونه',
    'نام_خدمت' => 'طراحی سایت', 'مقدار' => '۵', 'واحد' => 'صفحه', 'مقدار_و_واحد' => '۵ صفحه', 'شرح_خدمت' => 'طراحی و پیاده‌سازیِ صفحات سایت',
];

$pageTitle = 'تنظیمات تیکت';
require_once __DIR__ . '/../includes/layout_top.php';
?>
<style>
.abt-page .card{ border-radius:14px; border:1px solid rgba(201,162,75,.28); }
.abt-page textarea.tpl{ font-family: Vazirmatn, Tahoma, sans-serif; font-size:13.5px; line-height:2; min-height:340px; direction:rtl; }
.abt-page .ph-list code{ cursor:pointer; background:#f5f3ee; color:#57534e; border-radius:6px; padding:2px 6px; display:inline-block; margin:2px; font-size:12px; }
.abt-page .preview{ background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:12px; white-space:pre-wrap; font-size:13px; line-height:2; max-height:420px; overflow:auto; }
.abt-page .chip{ display:inline-block; padding:3px 10px; border-radius:20px; border:1px solid #e7e2d3; font-size:12px; text-decoration:none; color:#44403c; }
.abt-page .chip.active{ background:#1c1917; color:#fff; border-color:#1c1917; }
</style>
<div class="abt-page">
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <div>
      <h4 class="fw-bold mb-0"><i class="fa-solid fa-sliders"></i> تنظیمات تیکت (آراد برندینگ)</h4>
      <div class="text-muted small">بعد از «تأیید و ثبتِ سفارش» توسطِ واحد مالی، یک تیکت با متنِ آماده برای مشتری در سامانه‌ی aradbranding.me ثبت می‌شود تا مشتری همان‌جا پاسخ دهد.</div>
    </div>
    <div class="d-flex gap-2">
      <span class="badge <?= $ready ? 'text-bg-success' : 'text-bg-warning' ?> align-self-center"><?= $ready ? 'اتصال فعال' : 'اتصال غیرفعال / تنظیم‌نشده' ?></span>
      <a href="admin_aradbranding_send.php" class="btn btn-sm btn-success"><i class="fa-solid fa-paper-plane"></i> ارسال تیکت‌ها</a>
      <a href="admin_dashboard.php" class="btn btn-sm btn-outline-secondary">→ پنل مدیریت</a>
    </div>
  </div>

  <form method="post">
    <?= csrf_field() ?>
    <div class="row g-3">
      <div class="col-xl-5">
        <div class="card p-3 mb-3">
          <h6 class="fw-bold mb-3"><i class="fa-solid fa-plug"></i> اتصال به API</h6>
          <div class="form-check form-switch mb-1"><input class="form-check-input" type="checkbox" name="enabled" id="en" <?= $s['enabled'] === '1' ? 'checked' : '' ?>><label class="form-check-label" for="en">اتصال فعال باشد</label></div>
          <div class="form-check form-switch mb-3"><input class="form-check-input" type="checkbox" name="auto_send" id="as" <?= $s['auto_send'] === '1' ? 'checked' : '' ?>><label class="form-check-label" for="as">با «تأیید و ثبتِ سفارش» خودکار ارسال شود <span class="text-muted small">(خاموش = فقط آماده می‌شود و مالی دستی «ارسال» می‌زند)</span></label></div>
          <label class="form-label small">آدرسِ API ساختِ تیکت (POST)</label>
          <input name="api_url" class="form-control form-control-sm mb-2" dir="ltr" value="<?= e($s['api_url']) ?>" placeholder="https://aradbranding.me/api/ticket/create">
          <div class="row g-2 mb-2">
            <div class="col-6"><label class="form-label small">قالبِ بدنه</label>
              <select name="body_format" class="form-select form-select-sm"><option value="json" <?= $s['body_format'] === 'json' ? 'selected' : '' ?>>JSON</option><option value="form" <?= $s['body_format'] === 'form' ? 'selected' : '' ?>>Form (x-www-form-urlencoded)</option></select></div>
            <div class="col-6"><label class="form-label small">احراز هویت</label>
              <select name="auth_style" class="form-select form-select-sm">
                <?php foreach (['header' => 'هدر (X-Api-Key و …)', 'bearer' => 'Authorization: Bearer', 'query' => 'پارامترِ آدرس (?api_key=)', 'body' => 'فیلدی در بدنه', 'none' => 'بدون کلید'] as $k => $l): ?>
                  <option value="<?= $k ?>" <?= $s['auth_style'] === $k ? 'selected' : '' ?>><?= $l ?></option>
                <?php endforeach; ?>
              </select></div>
            <div class="col-6"><label class="form-label small">نامِ هدر / پارامترِ کلید</label><input name="auth_name" class="form-control form-control-sm" dir="ltr" value="<?= e($s['auth_name']) ?>"></div>
            <div class="col-6"><label class="form-label small">کلید / توکن</label><input name="auth_key" type="password" class="form-control form-control-sm" dir="ltr" autocomplete="new-password" placeholder="<?= $s['auth_key'] !== '' ? '•••••• (خالی = بدون تغییر)' : '' ?>"></div>
          </div>
          <div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="verify_ssl" id="vs" <?= $s['verify_ssl'] === '1' ? 'checked' : '' ?>><label class="form-check-label small" for="vs">بررسیِ گواهی SSL</label></div>

          <h6 class="fw-bold small mb-2">نامِ فیلدها در درخواست <span class="text-muted fw-normal">(خالی = ارسال نشود)</span></h6>
          <div class="row g-2 mb-2">
            <?php foreach (['field_phone' => 'تاجرِ صاحبِ تیکت (موبایل)', 'field_name' => 'نام تاجر', 'field_national' => 'کد ملی', 'field_subject' => 'موضوع', 'field_message' => 'متن پیام', 'field_department' => 'واحد (دپارتمان)', 'field_ref' => 'شماره فاکتور/سفارش', 'field_external' => 'شناسه‌ی یکتای تیکت (ضدِ تکرار)'] as $k => $l): ?>
              <div class="col-6"><label class="form-label small mb-0"><?= $l ?></label><input name="<?= $k ?>" class="form-control form-control-sm" dir="ltr" value="<?= e($s[$k]) ?>"></div>
            <?php endforeach; ?>
          </div>
          <label class="form-label small mb-0">واحدِ پیش‌فرض (برای خدمتی که واحدش در «لیست خدمات» تعیین نشده)</label>
          <input name="default_department" class="form-control form-control-sm mb-2" value="<?= e($s['default_department']) ?>" placeholder="مثلاً: فروش / پشتیبانی / ۱">
          <label class="form-label small mb-0">فیلدهای ثابت (JSON) — مثلاً اولویت</label>
          <input name="extra_json" class="form-control form-control-sm" dir="ltr" value="<?= e($s['extra_json']) ?>" placeholder='{"department": 3, "priority": "medium"}'>
          <div class="small text-muted mt-2">
            پاسخِ موفق = کدِ HTTP ۲xx (و <code>success/ok</code> نباشد false). شماره و لینکِ تیکت از فیلدهای رایج مثل <code>ticket_id</code>/<code>id</code> و <code>ticket_url</code>/<code>url</code> خوانده و در سفارش نمایش داده می‌شود.
          </div>
        </div>
      </div>

      <div class="col-xl-7">
        <div class="card p-3 mb-3">
          <h6 class="fw-bold mb-1"><i class="fa-solid fa-pen-nib"></i> متنِ عمومیِ تیکت (پیش‌فرض)</h6>
          <div class="small text-muted mb-3">برای <b>هر خدمت</b> یک تیکتِ جدا ارسال می‌شود. موضوع، متن و واحدِ اختصاصیِ هر خدمت را در <a href="admin_services_list.php">لیست خدمات</a> (ویرایشِ خدمت ← «تیکتِ آراد برندینگ») تعیین کنید؛ این متن فقط برای خدمت‌هایی است که متنِ اختصاصی ندارند.</div>
          <label class="form-label small">عنوانِ تیکت</label>
          <input name="subject_tpl" id="subj" class="form-control form-control-sm mb-2" value="<?= e($s['subject_tpl']) ?>">
          <label class="form-label small">متنِ تیکت</label>
          <textarea name="body_tpl" id="tpl" class="form-control tpl"><?= e($s['body_tpl']) ?></textarea>
          <div class="ph-list small mt-2">متغیرها (کلیک = درج):
            <?php foreach (abt_placeholders() as $k => $l): ?><code title="<?= e($l) ?>" data-ph="«<?= e($k) ?>»">«<?= e($k) ?>»</code><?php endforeach; ?>
          </div>
          <div class="small text-muted mt-1">متنِ هر سفارش پیش از ارسال در صفحه‌ی همان سفارش هم قابلِ ویرایش است.</div>
          <div class="d-flex gap-2 mt-3">
            <button class="btn btn-primary btn-sm"><i class="fa-solid fa-floppy-disk"></i> ذخیره‌ی تنظیمات</button>
            <button type="button" class="btn btn-outline-secondary btn-sm" id="reset-tpl">متنِ پیش‌فرض</button>
            <button type="button" class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#abtPreviewModal"><i class="fa-regular fa-eye"></i> پیش‌نمایش</button>
          </div>
        </div>
      </div>
    </div>
  </form>

  <?php
    require_once __DIR__ . '/../includes/edu_provision.php';
    $__edu = edu_settings($pdo);
    $__svcRows = [];
    try { $__svcRows = $pdo->query('SELECT id, title FROM services ORDER BY title')->fetchAll(PDO::FETCH_ASSOC) ?: []; } catch (Throwable $e) {}
    $__eduRows = [];
    foreach ($__svcRows as $__sv) {
        $__auto = edu_code_for(['map' => []], null, (string) $__sv['title']);
        $__set = $__edu['map'][(string) $__sv['id']] ?? '';
        if ($__auto !== null || $__set !== '') $__eduRows[] = $__sv + ['auto' => $__auto, 'set' => $__set];
    }
  ?>
  <form method="post" class="card p-3 mb-3" id="abt-edu" style="border-top:3px solid #0891b2">
    <?= csrf_field() ?>
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-1">
      <h6 class="fw-bold mb-0"><i class="fa-solid fa-graduation-cap" style="color:#0891b2"></i> سامانه‌ی آموزش (edu.aradbranding.me)</h6>
      <span class="badge <?= $__edu['enabled'] && $__edu['token'] !== '' ? 'text-bg-success' : 'text-bg-secondary' ?>"><?= $__edu['enabled'] && $__edu['token'] !== '' ? 'فعال' : 'غیرفعال' ?></span>
    </div>
    <div class="small text-muted mb-3">پیش از ارسالِ تیکتِ خدماتِ آموزشی، خرید در سامانه‌ی آموزش اعمال می‌شود (ساختِ کاربر «تاجر» + شارژِ خدمت) و نام کاربری/رمز در تیکت می‌رود. اگر اعمال ناموفق باشد، تیکت ارسال نمی‌شود و در «ارسال تیکت‌ها» با خطا می‌ماند.</div>
    <div class="row g-2">
      <div class="col-md-2 d-flex align-items-end">
        <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="edu_enabled" value="1" id="eduOn" <?= $__edu['enabled'] ? 'checked' : '' ?>><label class="form-check-label small" for="eduOn">فعال باشد</label></div>
      </div>
      <div class="col-md-5">
        <label class="form-label small mb-1">آدرسِ سامانه</label>
        <input name="edu_base_url" class="form-control form-control-sm" dir="ltr" value="<?= e($__edu['base_url']) ?>">
      </div>
      <div class="col-md-5">
        <label class="form-label small mb-1">توکن</label>
        <input name="edu_token" type="password" class="form-control form-control-sm" dir="ltr" autocomplete="new-password" placeholder="<?= $__edu['token'] !== '' ? '•••••• (خالی = بدونِ تغییر)' : 'توکنِ سامانه‌ی آموزش' ?>">
      </div>
    </div>
    <div class="mt-3 small fw-bold">خدماتِ آموزشی ← کد در سامانه‌ی آموزش</div>
    <div class="table-responsive"><table class="table table-sm small align-middle mb-2">
      <thead class="table-light"><tr><th>خدمت در آراد کانتکت</th><th style="width:260px">کد در سامانه‌ی آموزش</th></tr></thead><tbody>
      <?php foreach ($__eduRows as $__r): ?>
        <tr><td><?= e((string) $__r['title']) ?></td><td>
          <select name="edu_map[<?= (int) $__r['id'] ?>]" class="form-select form-select-sm">
            <option value="">خودکار<?= $__r['auto'] ? ' (' . e($__r['auto']) . ')' : '' ?></option>
            <?php foreach (EDU_CODES as $__c => $__t): ?><option value="<?= e($__c) ?>" <?= $__r['set'] === $__c ? 'selected' : '' ?>><?= e($__c . ' — ' . $__t) ?></option><?php endforeach; ?>
            <option value="-" <?= $__r['set'] === '-' ? 'selected' : '' ?>>— آموزشی نیست —</option>
          </select></td></tr>
      <?php endforeach; ?>
      <?php if (!$__eduRows): ?><tr><td colspan="2" class="text-muted">هیچ خدمتی با عنوانِ خدماتِ آموزشی پیدا نشد.</td></tr><?php endif; ?>
      </tbody></table></div>
    <div class="small text-muted mb-2">متغیرها برای متنِ تیکتِ این خدمات (در «لیست خدمات»): «نام_کاربری» «رمز_عبور» «آدرس_ورود» «خدمات_اعمال_شده». اگر متن این متغیرها را نداشته باشد، اطلاعاتِ ورود خودکار به انتهای تیکت اضافه می‌شود.</div>
    <div class="d-flex gap-2">
      <button name="action" value="save_edu" class="btn btn-sm btn-primary"><i class="fa-solid fa-floppy-disk"></i> ذخیره</button>
      <button name="action" value="test_edu" class="btn btn-sm btn-outline-dark"><i class="fa-solid fa-plug"></i> تستِ اتصال</button>
    </div>
  </form>

  <?php
    require_once __DIR__ . '/../includes/crm_provision.php';
    $__crm = crm_settings($pdo);
    $__crmRows = [];
    foreach ($__svcRows as $__sv) {
        $__auto = crm_plan_for(['map' => []], null, (string) $__sv['title']);
        $__set = $__crm['map'][(string) $__sv['id']] ?? '';
        if ($__auto !== null || $__set !== '') $__crmRows[] = $__sv + ['auto' => $__auto, 'set' => $__set];
    }
  ?>
  <form method="post" class="card p-3 mb-3" id="abt-crm" style="border-top:3px solid #16a34a">
    <?= csrf_field() ?>
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-1">
      <h6 class="fw-bold mb-0"><i class="fa-solid fa-address-book" style="color:#16a34a"></i> سامانه‌ی CRM (crm.aradbranding.me)</h6>
      <span class="badge <?= $__crm['enabled'] && $__crm['token'] !== '' ? 'text-bg-success' : 'text-bg-secondary' ?>"><?= $__crm['enabled'] && $__crm['token'] !== '' ? 'فعال' : 'غیرفعال' ?></span>
    </div>
    <div class="small text-muted mb-3">پیش از ارسالِ تیکتِ خدمتِ CRM، برای مشتری «شرکت» با اشتراکِ خریداری‌شده ساخته (یا تمدید) می‌شود؛ نام شرکت = نامِ مالک. نام کاربری/رمز و لینکِ ورود در تیکت می‌رود. اگر ناموفق باشد، تیکت ارسال نمی‌شود و با خطا می‌ماند.</div>
    <div class="row g-2">
      <div class="col-md-2 d-flex align-items-end">
        <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="crm_enabled" value="1" id="crmOn" <?= $__crm['enabled'] ? 'checked' : '' ?>><label class="form-check-label small" for="crmOn">فعال باشد</label></div>
      </div>
      <div class="col-md-3"><label class="form-label small mb-1">آدرسِ سامانه</label><input name="crm_base_url" class="form-control form-control-sm" dir="ltr" value="<?= e($__crm['base_url']) ?>"></div>
      <div class="col-md-3"><label class="form-label small mb-1">لینکِ ورودِ مشتری</label><input name="crm_login_url" class="form-control form-control-sm" dir="ltr" value="<?= e($__crm['login_url']) ?>"></div>
      <div class="col-md-4"><label class="form-label small mb-1">توکن</label><input name="crm_token" type="password" class="form-control form-control-sm" dir="ltr" autocomplete="new-password" placeholder="<?= $__crm['token'] !== '' ? '•••••• (خالی = بدونِ تغییر)' : 'توکنِ سامانه‌ی CRM' ?>"></div>
    </div>
    <div class="mt-3 small fw-bold">خدماتِ CRM ← اشتراک</div>
    <div class="table-responsive"><table class="table table-sm small align-middle mb-2">
      <thead class="table-light"><tr><th>خدمت در آراد کانتکت</th><th style="width:260px">اشتراک در CRM</th></tr></thead><tbody>
      <?php foreach ($__crmRows as $__r): ?>
        <tr><td><?= e((string) $__r['title']) ?></td><td>
          <select name="crm_map[<?= (int) $__r['id'] ?>]" class="form-select form-select-sm">
            <option value="">خودکار<?= $__r['auto'] ? ' (' . e(CRM_PLANS[$__r['auto']]) . ')' : '' ?></option>
            <?php foreach (CRM_PLANS as $__c => $__t): ?><option value="<?= e($__c) ?>" <?= $__r['set'] === $__c ? 'selected' : '' ?>><?= e($__t) ?></option><?php endforeach; ?>
            <option value="-" <?= $__r['set'] === '-' ? 'selected' : '' ?>>— CRM نیست —</option>
          </select></td></tr>
      <?php endforeach; ?>
      <?php if (!$__crmRows): ?><tr><td colspan="2" class="text-muted">خدمتی با عنوانِ CRM پیدا نشد.</td></tr><?php endif; ?>
      </tbody></table></div>
    <div class="small text-muted mb-2">متغیرها برای متنِ تیکتِ این خدمات: «نام_کاربری» «رمز_عبور» «آدرس_ورود» «نام_شرکت» «اشتراک» «تاریخ_انقضا». اگر متن این متغیرها را نداشته باشد، اطلاعاتِ ورود خودکار به انتهای تیکت اضافه می‌شود.</div>
    <div class="d-flex gap-2">
      <button name="action" value="save_crm" class="btn btn-sm btn-primary"><i class="fa-solid fa-floppy-disk"></i> ذخیره</button>
      <button name="action" value="test_crm" class="btn btn-sm btn-outline-dark"><i class="fa-solid fa-plug"></i> تستِ اتصال</button>
    </div>
  </form>

  <div class="modal fade" id="abtPreviewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
      <div class="modal-content" style="border-radius:16px">
        <div class="modal-header">
          <h6 class="modal-title fw-bold"><i class="fa-regular fa-eye"></i> پیش‌نمایشِ تیکت <span class="text-muted fw-normal small">(داده‌ی نمونه، متنِ ذخیره‌شده)</span></h6>
          <button type="button" class="btn-close ms-0 me-auto" data-bs-dismiss="modal" aria-label="بستن"></button>
        </div>
        <div class="modal-body">
          <div class="fw-bold small mb-2"><?= e(abt_render($s['subject_tpl'], $sampleVars)) ?></div>
          <div class="preview" style="max-height:none"><?= e(abt_render($s['body_tpl'], $sampleVars)) ?></div>
          <div class="small text-muted mt-2">تغییراتی که هنوز ذخیره نکرده‌اید در این پیش‌نمایش دیده نمی‌شوند.</div>
        </div>
      </div>
    </div>
  </div>

  <?php $__ctrBody = trim((string) $s['ctr_body_tpl']) !== '' ? (string) $s['ctr_body_tpl'] : abt_default_contract_body(); ?>
  <div class="row g-3 mb-3 align-items-stretch">
  <div class="col-xl-7">
  <form method="post" class="card p-3 h-100" id="abt-contract" style="border-top:3px solid #7c3aed">
    <?= csrf_field() ?><input type="hidden" name="action" value="save_contract_ticket">
    <h6 class="fw-bold mb-1"><i class="fa-solid fa-file-signature" style="color:#7c3aed"></i> تیکتِ اسنادِ قرارداد</h6>
    <div class="small text-muted mb-3">از صفحه‌ی هر قرارداد ← «ارسال برای مشتری» ← «تیکت در آراد برندینگ». قرارداد، فاکتور و شرح خدمات به‌صورتِ <b>لینکِ امن</b> داخلِ متنِ تیکت می‌روند؛ مشتری با بازکردنِ لینک سند را می‌بیند و «دانلود / ذخیره PDF» می‌زند.</div>
    <div class="row g-2">
      <div class="col-md-4">
        <label class="form-label small mb-1">واحد (دپارتمان) در آراد برندینگ</label>
        <input name="ctr_department" class="form-control form-control-sm" value="<?= e((string) $s['ctr_department']) ?>" placeholder="مثلاً ۳۸ یا قرارداد">
        <div class="form-text">شناسه (مثلاً <b>۳۸</b>) یا نامِ دقیقِ واحد از فهرستِ واحدها.</div>
      </div>
      <div class="col-md-3">
        <label class="form-label small mb-1">اعتبارِ لینکِ اسناد (روز)</label>
        <input name="ctr_link_days" type="number" min="1" max="365" class="form-control form-control-sm" value="<?= e((string) $s['ctr_link_days']) ?>">
      </div>
      <div class="col-md-5">
        <label class="form-label small mb-1">نامِ فیلدِ «پیوست‌ها» در API <span class="text-muted">(اختیاری)</span></label>
        <input name="field_attachments" class="form-control form-control-sm" dir="ltr" value="<?= e((string) $s['field_attachments']) ?>" placeholder="مثلاً attachments — خالی = ارسال نشود">
        <div class="form-text">اگر API آراد برندینگ پیوست بپذیرد، فایلِ PDFِ اسناد به شکلِ <code dir="ltr">[{"title","url"}]</code> در این فیلد فرستاده می‌شود.
          <?php $__testUrl = (function_exists('ctr_site_url') ? ctr_site_url() : '/') . 'doc_pdf.php/test.pdf'; ?>
          <div class="mt-1">آدرسِ فایلِ آزمایشی (برای بررسی توسطِ برنامه‌نویسِ آراد برندینگ): <a href="<?= e($__testUrl) ?>" target="_blank" dir="ltr"><?= e($__testUrl) ?></a></div></div>
      </div>
      <div class="col-12">
        <label class="form-label small mb-1">موضوعِ تیکت</label>
        <input name="ctr_subject_tpl" class="form-control form-control-sm" value="<?= e((string) $s['ctr_subject_tpl']) ?>">
      </div>
      <div class="col-12">
        <label class="form-label small mb-1">متنِ تیکت</label>
        <textarea name="ctr_body_tpl" class="form-control form-control-sm" rows="10" style="line-height:2"><?= e($__ctrBody) ?></textarea>
        <div class="form-text">متغیرها: «عنوان» «نام_مشتری» «موبایل» «شماره_قرارداد» «لینک_اسناد» (لینکِ هر سند در یک خط) «فهرست_اسناد» «تاریخ_اعتبار» — اگر خالی ذخیره کنید، متنِ پیش‌فرض استفاده می‌شود.</div>
      </div>
    </div>
    <div class="mt-2"><button class="btn btn-sm btn-primary"><i class="fa-solid fa-floppy-disk"></i> ذخیره‌ی تنظیماتِ تیکتِ قرارداد</button></div>
  </form>
  </div>

  <div class="col-xl-5">
  <div class="card p-3 h-100" id="abt-departments">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
      <div><h6 class="fw-bold mb-0"><i class="fa-solid fa-sitemap"></i> فهرستِ واحدهای آراد برندینگ</h6>
        <div class="small text-muted">شناسه‌ی هر واحد را در «لیست خدمات» (واحدِ تیکت) یا «واحدِ پیش‌فرض» وارد کنید. اول تنظیمات را ذخیره کنید.</div></div>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="fetch_departments">
        <button class="btn btn-sm btn-outline-primary"><i class="fa-solid fa-rotate"></i> دریافتِ فهرستِ واحدها</button></form>
    </div>
    <?php if ($deptResult): ?>
      <?php if (!empty($deptResult['items'])): ?>
        <div style="max-height:520px;overflow-y:auto" class="mt-3"><table class="table table-sm small mb-0"><thead class="table-light"><tr><th>شناسه (برای واردکردن)</th><th>نامِ واحد</th></tr></thead><tbody>
          <?php foreach ($deptResult['items'] as $d): ?><tr><td dir="ltr" class="fw-bold"><?= e($d['id']) ?></td><td><?= e($d['name']) ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
      <?php endif; ?>
      <?php if (empty($deptResult['items']) && !empty($deptResult['raw'])): ?><pre class="small mt-3 mb-0 p-2 bg-light border rounded" dir="ltr" style="white-space:pre-wrap"><?= e((string) $deptResult['raw']) ?></pre><?php endif; ?>
    <?php endif; ?>
  </div>
  </div>
  </div>

</div>
<script>
(function () {
  const tpl = document.getElementById('tpl');
  let last = tpl;
  document.getElementById('subj').addEventListener('focus', e => last = e.target);
  tpl.addEventListener('focus', e => last = e.target);
  document.querySelectorAll('.ph-list code').forEach(c => c.addEventListener('click', () => {
    const t = last, v = c.dataset.ph, a = t.selectionStart ?? t.value.length, b = t.selectionEnd ?? t.value.length;
    t.value = t.value.slice(0, a) + v + t.value.slice(b);
    t.focus(); t.selectionStart = t.selectionEnd = a + v.length;
  }));
  const def = <?= json_encode(abt_default_body(), JSON_UNESCAPED_UNICODE) ?>;
  document.getElementById('reset-tpl').addEventListener('click', () => {
    if (confirm('متنِ پیش‌فرض در ویرایشگر قرار بگیرد؟ (تا ذخیره نکنید چیزی تغییر نمی‌کند)')) tpl.value = def;
  });
})();
</script>
<?php require_once __DIR__ . '/../includes/layout_bottom.php'; ?>
