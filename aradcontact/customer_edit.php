<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/spreadsheet_reader.php';
$user = require_login();

$id = (int) ($_GET['id'] ?? 0);
$stmt = db()->prepare('SELECT * FROM customers WHERE id = ? LIMIT 1');
$stmt->execute([$id]);
$customer = $stmt->fetch();

if (!$customer) {
    http_response_code(404);
    die('مشتری یافت نشد.');
}
if ($customer['owner_user_id'] != $user['id'] && !can_manage_service_requests($user) && !leader_supervises_owner(db(), $user, (int) $customer['owner_user_id'])) {
    http_response_code(403);
    die('شما اجازه ویرایش این مشتری را ندارید.');
}

// اگه از صفحه‌ی «بررسی سابقه‌ی یک شماره» (مدیر کل یا نقشی با مجوزِ customer_name_lock) یک اسمِ ثابت برای شماره‌ی این مشتری قفل
// کرده باشه، اینجا (و برای همه، حتی مدیر کل) فیلدِ اسم غیرقابل‌ویرایش می‌شه — طبق قانون
// سیستم، اسمِ قفل‌شده فقط از همون صفحه قابل تغییره.
$nameLockRow = null;
try {
    $custNormPhone = normalize_phone_for_match((string) $customer['mobile']);
    $custNormPhone2 = !empty($customer['mobile_2']) ? normalize_phone_for_match((string) $customer['mobile_2']) : null;
    if ($custNormPhone !== null || $custNormPhone2 !== null) {
        $lockStmt = db()->prepare('SELECT full_name FROM customer_name_locks WHERE phone_normalized IN (?, ?) LIMIT 1');
        $lockStmt->execute([$custNormPhone ?? '', $custNormPhone2 ?? '']);
        $nameLockRow = $lockStmt->fetch() ?: null;
    }
} catch (Throwable $e) {
    // اگر مایگریشنِ جدول customer_name_locks هنوز اجرا نشده باشد
    $nameLockRow = null;
}
$fullNameLocked = $nameLockRow !== null;

// وضعیتِ قفل‌شده (مثلاً «شاکی») را فقط ادمین یا ادمین کل می‌تواند از این فرم تغییر دهد —
// role دقیقاً 'admin' هم ادمین معمولی و هم ادمین کل را پوشش می‌دهد.
$statusLocked = !empty($customer['status_locked']);
$statusFieldLocked = $statusLocked && $user['role'] !== 'admin';

// آدرس صفحه‌ای که کاربر از آن به اینجا آمده (مثلا لیست «سررسید امروز» در داشبورد)؛ بعد از
// ذخیره، به‌جای رفتن همیشگی به لیست کامل، به همان‌جا برمی‌گردیم تا بشود مشتری بعدی را ادامه داد.
$fromRaw = $_POST['from'] ?? $_GET['from'] ?? '';
$fromAllowed = ['customer_list.php', 'dashboard.php', 'customer_referrals.php'];
$returnTo = 'customer_list.php';
if ($fromRaw !== '') {
    $fromPath = explode('?', $fromRaw, 2)[0];
    if (in_array($fromPath, $fromAllowed, true)) {
        $returnTo = $fromRaw;
    }
}

// وضعیت‌ها بر اساس واحدِ صاحبِ مشتری (نه لزوما کاربر جاری، در صورتی که ادمین ویرایش کند)
$ownerStmt = db()->prepare('SELECT role FROM users WHERE id = ?');
$ownerStmt->execute([$customer['owner_user_id']]);
$ownerRole = $ownerStmt->fetchColumn() ?: 'A';
$statusOptions = status_options_with_current(status_options_for_role($ownerRole), $customer['status']);
$socialOptions = social_network_options();
$contactTypeOptions = contact_type_options();

$errors = [];
$existingMessengers = customer_messengers_by_slot(db(), $id);
// ستونِ customers.landline_phone ممکنه هنوز روی این سایت ساخته نشده باشه (تا وقتی
// migration_landline_phone.sql اجرا بشه) — قبل از هر استفاده‌ای چک می‌کنیم.
$landlineReady = customer_landline_ready(db());
$old = [
    'full_name'            => $customer['full_name'],
    'mobile'               => $customer['mobile'],
    'mobile_2'             => $customer['mobile_2'] ?? '',
    'landline_phone'       => $customer['landline_phone'] ?? '',
    'initial_contact_date' => to_jalali($customer['initial_contact_date']),
    'next_followup_date'   => to_jalali($customer['next_followup_date']),
    'status'               => $customer['status'],
    'contact_type'         => $customer['contact_type'] ?? 'customer',
    'city'                 => $customer['city'] ?? '',
    'job'                  => $customer['job'] ?? '',
    'description'          => $customer['description'] ?? '',
];
// تاریخِ ارتباطِ اولیه قفل است؛ فقط ادمینِ کل می‌تواند تغییرش دهد
$initialDateEditable = is_super_admin($user);
$old['mobile_messengers']   = $existingMessengers['mobile'];
$old['mobile_2_messengers'] = $existingMessengers['mobile_2'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $errors[] = 'نشست شما منقضی شده است، صفحه را رفرش کرده و دوباره تلاش کنید.';
    }
    foreach ($old as $k => $v) {
        if (is_array($v)) {
            continue;
        }
        if (isset($_POST[$k])) {
            $old[$k] = normalize_digits(trim($_POST[$k]));
        }
    }
    $old['mobile_messengers']   = array_values(array_intersect((array) ($_POST['mobile_messengers'] ?? []), array_keys($socialOptions)));
    $old['mobile_2_messengers'] = array_values(array_intersect((array) ($_POST['mobile_2_messengers'] ?? []), array_keys($socialOptions)));

    // اسمِ قفل‌شده رو حتی اگه فیلدش دستکاری/فعال بشه، اینجا دوباره روی مقدارِ قفل‌شده برمی‌گردونیم
    if ($fullNameLocked) {
        $old['full_name'] = $nameLockRow['full_name'];
    }
    // همین‌طور وضعیتِ قفل‌شده — کسی جز ادمین/ادمین کل نمی‌تونه از این فرم تغییرش بده
    if ($statusFieldLocked) {
        $old['status'] = $customer['status'];
    }
    // تاریخِ ارتباطِ اولیه: برای غیرِ ادمینِ کل همیشه همان مقدارِ ثبت‌شده (حتی اگر فیلد دستکاری شود)
    if (!$initialDateEditable) {
        $old['initial_contact_date'] = to_jalali($customer['initial_contact_date']);
    }

    if (mb_strlen($old['full_name']) < 2) {
        $errors[] = 'نام و نام خانوادگی مشتری الزامی است.';
    }
    if (!is_valid_iran_mobile($old['mobile'])) {
        $errors[] = 'شماره موبایل معتبر نیست.';
    }
    if ($old['mobile_2'] !== '' && !is_valid_iran_mobile($old['mobile_2'])) {
        $errors[] = 'شماره موبایل دوم معتبر نیست.';
    }
    // اختیاریه؛ فقط وقتی مقداری وارد شده اعتبارسنجی می‌کنیم. اگه مایگریشنِ ستون هنوز اجرا
    // نشده، این فیلد رو کلاً نادیده می‌گیریم (نه خطا، نه ذخیره) تا فرم بدون توضیح خراب نشه.
    if (!$landlineReady) {
        $old['landline_phone'] = '';
    } elseif ($old['landline_phone'] !== '' && normalize_landline_for_match($old['landline_phone']) === null) {
        $errors[] = 'شماره تلفن ثابت معتبر نیست.';
    }
    if (count($old['mobile_messengers']) > MAX_MESSENGERS_PER_MOBILE) {
        $errors[] = 'حداکثر ' . to_persian_digits((string) MAX_MESSENGERS_PER_MOBILE) . ' پیام‌رسان برای شماره موبایل اول قابل انتخاب است.';
    }
    if (count($old['mobile_2_messengers']) > MAX_MESSENGERS_PER_MOBILE) {
        $errors[] = 'حداکثر ' . to_persian_digits((string) MAX_MESSENGERS_PER_MOBILE) . ' پیام‌رسان برای شماره موبایل دوم قابل انتخاب است.';
    }
    $initialGregorian = $initialDateEditable ? to_gregorian($old['initial_contact_date']) : $customer['initial_contact_date'];
    if (!$initialGregorian) {
        $errors[] = 'تاریخ ارتباط اولیه معتبر نیست.';
    }
    // «تاریخ پیگیری بعدی» در این صفحه نیست: سررسید فقط با ثبتِ پیگیری در صفحه‌ی جزئیاتِ مشتری تعیین می‌شود.
    // این‌جا همان تاریخِ فعلی حفظ می‌شود؛ فقط اگر وضعیت به «بدونِ پیگیری» (انصرافی/نامرتبط/شاکی) برود خالی می‌شود.
    $noFollowup = status_needs_no_followup($old['status']);
    $nextGregorian = $noFollowup ? null : ($customer['next_followup_date'] ?: null);
    if (!in_array($old['status'], $statusOptions, true)) {
        $errors[] = 'وضعیت انتخابی معتبر نیست.';
    }
    if (!isset($contactTypeOptions[$old['contact_type']])) {
        $errors[] = 'نوع مخاطب انتخابی معتبر نیست.';
    }
    // فقط ادمین/ادمین‌کل/نظارت/رابط‌مالی اجازه دارند نوع مخاطب را از «همکار» به چیز دیگری تغییر دهند —
    // وگرنه یک کارشناس می‌توانست خودش این را عوض کند تا زمان تماس با همکارش هم جزو گزارش «تماس با مشتری» حساب شود.
    if (in_array($customer['contact_type'], ['colleague', 'family'], true) && $old['contact_type'] !== $customer['contact_type'] && !can_change_locked_contact_type($user)) {
        $errors[] = 'نوعِ مخاطبِ «' . contact_type_label($customer['contact_type']) . '» قفل است؛ فقط ادمین، نظارت یا دارنده‌ی مجوز می‌تواند آن را تغییر دهد.';
    }

    if (!$errors) {
        $pdo = db();
        try {
            $pdo->beginTransaction();
            // ستونِ landline_phone رو فقط وقتی توی UPDATE می‌آریم که واقعاً روی این سایت
            // ساخته شده باشه؛ وگرنه کوئری با خطای «ستون ناموجود» شکست می‌خورد.
            $landlineSql = $landlineReady ? ', landline_phone = ?' : '';
            $stmt = $pdo->prepare('UPDATE customers SET
                full_name = ?, mobile = ?, mobile_2 = ?, initial_contact_date = ?, next_followup_date = ?,
                status = ?, contact_type = ?, city = ?, job = ?, description = ?' . $landlineSql . '
                WHERE id = ?');
            $params = [
                $old['full_name'],
                $old['mobile'],
                $old['mobile_2'] !== '' ? $old['mobile_2'] : null,
                $initialGregorian,
                $nextGregorian,
                $old['status'],
                $old['contact_type'],
                $old['city'] !== '' ? $old['city'] : null,
                $old['job'] !== '' ? $old['job'] : null,
                $old['description'] !== '' ? $old['description'] : null,
            ];
            if ($landlineReady) {
                $params[] = $old['landline_phone'] !== '' ? $old['landline_phone'] : null;
            }
            $params[] = $id;
            $stmt->execute($params);
            record_meeting_flag_if_needed($pdo, $id, $old['status']);
            sync_customer_phone_normalized($pdo, $id, $old['mobile'], $old['mobile_2'] !== '' ? $old['mobile_2'] : null);
            sync_colleague_contact_type($pdo, $old['mobile'], $old['mobile_2'] !== '' ? $old['mobile_2'] : null);
            sync_customer_phone_entries($pdo, $id, $old['mobile'], $old['mobile_2'] !== '' ? $old['mobile_2'] : null);
            try {
                $log = $pdo->prepare('INSERT INTO customer_activity_logs (customer_id, user_id, activity_type, description) VALUES (?,?,?,?)');
                $log->execute([$id, $user['id'], 'edit', 'اطلاعات مشتری ویرایش شد.']);
            } catch (Exception $e) {}

            save_customer_messengers($pdo, $id, 'mobile', $old['mobile_messengers']);
            save_customer_messengers($pdo, $id, 'mobile_2', $old['mobile_2_messengers']);
            $pdo->commit();
            flash_set('success', 'اطلاعات مشتری با موفقیت بروزرسانی شد.');
            redirect($returnTo);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            // اگر ستون next_followup_date در دیتابیس هنوز NOT NULL باشد (یعنی مایگریشن
            // «migration_nullable_followup_date.sql» روی این سایت اجرا نشده)، ثبت وضعیت
            // انصرافی/نامرتبط (که فیلد تاریخ پیگیری را خالی می‌گذارد) با خطا مواجه می‌شود.
            $rawMsg = $e->getMessage();
            if ($noFollowup && (stripos($rawMsg, "next_followup_date' cannot be null") !== false || stripos($rawMsg, '1048') !== false)) {
                $errors[] = 'خطا در ذخیره: دیتابیس سایت هنوز اجازه خالی گذاشتن «تاریخ پیگیری بعدی» را نمی‌دهد. لطفاً از پنل مدیریت وارد «بروزرسانی سیستم» شوید و فایل migration_nullable_followup_date.sql را (طبق راهنمای بخش دیتابیس) روی سایت اجرا کنید، سپس دوباره تلاش کنید.';
            } else {
                $errors[] = 'خطا در ذخیره اطلاعات. دوباره تلاش کنید.';
            }
        }
    }
}

$pageTitle = 'ویرایش مشتری';
require_once __DIR__ . '/includes/layout_top.php';
?>
<style>
.cf-page{--cf-line:#e7e2d3;--cf-ink:#1c1917;--cf-muted:#78716c;--cf-gold:#c9a24b;--cf-gold-2:#f1dfa8;}

.cf-page .card{
  border:1px solid var(--cf-line);border-radius:22px;
  box-shadow:0 4px 24px -16px rgba(28,25,23,.3);
}

/* ---------- header ---------- */
.cf-form-head{display:flex;align-items:center;gap:.9rem;margin-bottom:1.4rem;padding-bottom:1.2rem;border-bottom:1px solid var(--cf-line);}
.cf-form-icon{
  width:52px;height:52px;border-radius:16px;flex-shrink:0;display:inline-flex;align-items:center;justify-content:center;
  font-size:1.3rem;color:#fff;
  background:linear-gradient(135deg,#0b0f1a 0%,#3d3220 55%,var(--cf-gold) 130%);
  box-shadow:0 8px 18px -8px rgba(201,162,75,.55);
}
.cf-form-head h5{margin:0;font-weight:800;color:var(--cf-ink);font-size:1.18rem;}
.cf-form-sub{font-size:.8rem;color:var(--cf-muted);margin-top:.25rem;}

/* ---------- form sections ---------- */
.cf-page .form-section{
  background:#fdfcf9;border:1px solid var(--cf-line);border-radius:16px;
  padding:1.15rem 1.25rem 1.35rem;margin-bottom:1.1rem;
}
.cf-page .form-section-title{
  display:flex;align-items:center;gap:.55rem;font-weight:800;font-size:.86rem;color:var(--cf-ink);
  margin-bottom:1.05rem;padding-bottom:.65rem;border-bottom:1px dashed var(--cf-line);
}
.cf-page .form-section-title i{
  width:28px;height:28px;border-radius:9px;flex-shrink:0;display:inline-flex;align-items:center;justify-content:center;
  background:linear-gradient(135deg,var(--cf-gold-2),var(--cf-gold));color:#241d0a;font-size:.8rem;
}

/* ---------- fields ---------- */
.cf-page .form-label{font-size:.8rem;font-weight:700;color:#57534e;margin-bottom:.4rem;display:flex;align-items:center;gap:.35rem;}
.cf-page .form-label::before{content:'';width:5px;height:5px;border-radius:50%;background:var(--cf-gold);display:inline-block;flex-shrink:0;}
.cf-page .form-control, .cf-page .form-select{
  border:1px solid var(--cf-line);border-radius:12px;padding:.6rem .85rem;font-size:.86rem;background:#fff;
  transition:.15s ease;box-shadow:none;
}
.cf-page .form-control:focus, .cf-page .form-select:focus{
  border-color:var(--cf-gold);background:#fff;box-shadow:0 0 0 4px rgba(201,162,75,.15);
}
.cf-page textarea.form-control{border-radius:14px;}
.cf-page .form-control:disabled, .cf-page .form-select:disabled{background:#f5f4f0;color:#a8a29e;}
.cf-page .form-text{font-size:.75rem;color:var(--cf-muted);}

/* ---------- locked-field notices ---------- */
.cf-page .cf-locked-note{
  display:flex;align-items:center;gap:.4rem;font-size:.75rem;color:#92400e;background:#fffbeb;
  border:1px solid #fde68a;border-radius:10px;padding:.4rem .6rem;margin-top:.45rem;
}
.cf-page .cf-locked-note i{color:#b45309;}

/* ---------- contact picker ---------- */
.cf-page .contact-picker-row{background:#faf9f5;border:1px dashed var(--cf-line);border-radius:14px;padding:1rem 1.1rem;}
.cf-page .contact-picker-btn{border-radius:12px;font-weight:700;padding:.55rem 1.15rem;font-size:.85rem;}

/* ---------- messenger checkbox groups ---------- */
.cf-page .form-check{
  background:#fff;border:1px solid var(--cf-line);border-radius:10px;padding:.4rem .6rem .4rem 2.1rem;
  font-size:.8rem;transition:.15s ease;
}
.cf-page .form-check-input:checked ~ .form-check-label{color:var(--cf-ink);font-weight:700;}
.cf-page .form-check-input:checked{background-color:var(--cf-gold);border-color:var(--cf-gold);}

/* ---------- alerts ---------- */
.cf-page .alert{border-radius:16px;border:1px solid transparent;}

/* ---------- action buttons ---------- */
.cf-page .cf-btn-gold{
  border:none;border-radius:14px;padding:.75rem 1.6rem;font-weight:700;font-size:.92rem;color:#241d0a;
  background:linear-gradient(135deg,var(--cf-gold-2),var(--cf-gold));box-shadow:0 8px 18px -8px rgba(201,162,75,.7);
  display:inline-flex;align-items:center;gap:.5rem;transition:.15s ease;
}
.cf-page .cf-btn-gold:hover{transform:translateY(-2px);box-shadow:0 10px 22px -8px rgba(201,162,75,.8);color:#241d0a;}
.cf-page .btn-outline-secondary{border-radius:14px;font-weight:700;padding:.75rem 1.4rem;}
</style>

<div class="cf-page">
<div class="row justify-content-center">
  <div class="col-lg-8">
    <div class="card p-4">
      <div class="cf-form-head">
        <span class="cf-form-icon"><i class="fa-solid fa-user-pen"></i></span>
        <div>
          <h5>ویرایش اطلاعات مشتری</h5>
          <div class="cf-form-sub"><?= e($customer['full_name']) ?></div>
        </div>
      </div>

      <?php if (followup_date_migration_pending(db())): ?>
        <?php render_followup_migration_warning($user['role'] === 'admin'); ?>
      <?php endif; ?>

      <?php foreach ($errors as $err): ?>
        <div class="alert alert-danger py-2"><?= e($err) ?></div>
      <?php endforeach; ?>

      <form method="post" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="from" value="<?= e($returnTo) ?>">

        <div class="form-section">
          <div class="form-section-title"><i class="fa-solid fa-address-card"></i> اطلاعات تماس</div>
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">نام و نام خانوادگی <span class="text-danger">*</span></label>
              <input type="text" name="full_name" class="form-control" value="<?= e($old['full_name']) ?>" <?= $fullNameLocked ? 'disabled' : 'required' ?>>
              <?php if ($fullNameLocked): ?>
                <input type="hidden" name="full_name" value="<?= e($old['full_name']) ?>">
                <div class="cf-locked-note"><i class="fa-solid fa-lock"></i> این اسم به‌عنوانِ «نام ثابتِ» این شماره قفل شده و فقط از «بررسی سابقه‌ی یک شماره (مدیریتی)» قابلِ تغییر است.</div>
              <?php endif; ?>
            </div>
            <div class="col-md-6">
              <label class="form-label">شماره موبایل <span class="text-danger">*</span></label>
              <input type="text" name="mobile" class="form-control" dir="ltr" value="<?= e($old['mobile']) ?>" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">شماره موبایل دوم <span class="text-muted small">(اختیاری)</span></label>
              <input type="text" name="mobile_2" class="form-control" dir="ltr" placeholder="09121234567" value="<?= e($old['mobile_2']) ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">تلفن ثابت <span class="text-muted small">(اختیاری)</span></label>
              <?php if ($landlineReady): ?>
                <input type="text" name="landline_phone" class="form-control" dir="ltr" placeholder="02166310288" value="<?= e($old['landline_phone']) ?>">
                <div class="form-text">خطِ ثابتِ اداری/منزلِ مشتری — اگر تماسی از این شماره در اکسلِ کالیزر/نواتل وارد شود، خودکار به همین پرونده وصل می‌شود.</div>
              <?php else: ?>
                <input type="text" class="form-control" disabled placeholder="بعد از بروزرسانی سیستم فعال می‌شود">
              <?php endif; ?>
            </div>
            <?php render_messenger_checkboxes('mobile', $old['mobile_messengers'], 'پیام‌رسان‌های شماره موبایل اول'); ?>
            <?php render_messenger_checkboxes('mobile_2', $old['mobile_2_messengers'], 'پیام‌رسان‌های شماره موبایل دوم'); ?>
          </div>
        </div>

        <div class="form-section">
          <div class="form-section-title"><i class="fa-solid fa-list-check"></i> وضعیت و پیگیری</div>
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">وضعیت</label>
              <select name="status" id="status_select" class="form-select" <?= $statusFieldLocked ? 'disabled' : '' ?>>
                <?php foreach ($statusOptions as $st): ?>
                  <option value="<?= e($st) ?>" <?= $old['status'] === $st ? 'selected' : '' ?>><?= e($st) ?></option>
                <?php endforeach; ?>
              </select>
              <?php if ($statusFieldLocked): ?>
                <input type="hidden" name="status" value="<?= e($old['status']) ?>">
                <div class="cf-locked-note"><i class="fa-solid fa-lock"></i> این وضعیت توسط مدیر قفل شده است.</div>
              <?php endif; ?>
            </div>
            <div class="col-md-6">
              <label class="form-label">نوع مخاطب</label>
              <?php $contactTypeLocked = (in_array($customer['contact_type'], ['colleague', 'family'], true) && !can_change_locked_contact_type($user)); ?>
              <select name="contact_type" class="form-select" <?= $contactTypeLocked ? 'disabled' : '' ?>>
                <?php foreach ($contactTypeOptions as $val => $label): ?>
                  <option value="<?= e($val) ?>" <?= $old['contact_type'] === $val ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
              </select>
              <?php if ($contactTypeLocked): ?>
                <input type="hidden" name="contact_type" value="<?= e($old['contact_type']) ?>">
                <div class="cf-locked-note"><i class="fa-solid fa-lock"></i> این مخاطب به‌عنوان «<?= e(contact_type_label($customer['contact_type'])) ?>» ثبت شده و قفل است؛ فقط ادمین/نظارت یا دارنده‌ی مجوز می‌تواند تغییرش دهد.</div>
              <?php else: ?>
                <div class="form-text">اگر این مخاطب در واقع مشتری نیست (مثلا خانواده یا همکار است که به اشتباه از فایل تماس ثبت شده)، نوع آن را تغییر دهید تا در لیست «پیگیری مشتریان» نمایش داده نشود.</div>
              <?php endif; ?>
            </div>
            <div class="col-md-6">
              <label class="form-label">تاریخ ارتباط اولیه <span class="text-danger">*</span></label>
              <?php if ($initialDateEditable): ?>
                <input type="text" name="initial_contact_date" class="form-control jalali-date" autocomplete="off" dir="ltr" value="<?= e($old['initial_contact_date']) ?>" required>
              <?php else: ?>
                <input type="text" class="form-control" dir="ltr" value="<?= e(to_persian_digits($old['initial_contact_date'])) ?>" disabled>
                <div class="cf-locked-note"><i class="fa-solid fa-lock"></i> قفل است؛ فقط ادمینِ کل می‌تواند تغییرش دهد.</div>
              <?php endif; ?>
            </div>
            <div class="col-md-6">
              <label class="form-label">پیگیری بعدی</label>
              <div class="form-control-plaintext small text-muted"><i class="fa-solid fa-circle-info"></i> سررسیدِ پیگیری فقط با ثبتِ پیگیری در <a href="customer_view.php?id=<?= $id ?>">صفحه‌ی جزئیاتِ مشتری</a> تعیین می‌شود.</div>
            </div>
          </div>
        </div>

        <div class="form-section mb-4">
          <div class="form-section-title"><i class="fa-solid fa-circle-info"></i> اطلاعات تکمیلی</div>
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">شهر</label>
              <input type="text" name="city" class="form-control" value="<?= e($old['city']) ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">شغل</label>
              <input type="text" name="job" class="form-control" value="<?= e($old['job']) ?>">
            </div>
            <div class="col-12">
              <label class="form-label">توضیحات</label>
              <textarea name="description" class="form-control" rows="3"><?= e($old['description']) ?></textarea>
            </div>
          </div>
        </div>

        <div class="d-flex gap-2">
          <button type="submit" class="cf-btn-gold"><i class="fa-solid fa-floppy-disk"></i> ذخیره تغییرات</button>
          <a href="customer_view.php?id=<?= $id ?>&amp;from=<?= urlencode($returnTo) ?>" class="btn btn-outline-secondary">انصراف</a>
        </div>
      </form>
    </div>
  </div>
</div>
</div>

<?php require_once __DIR__ . '/includes/layout_bottom.php'; ?>
