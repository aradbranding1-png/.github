<?php
require_once __DIR__ . '/../includes/auth.php';
$admin = require_admin();
$pdo   = db();

$id = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
$stmt->execute([$id]);
$target = $stmt->fetch();
if (!$target || !actor_can_see_user($admin, $target)) {
    http_response_code(404);
    die('کاربر یافت نشد.');
}
// فقط ادمینِ کل حسابِ ادمین‌ها را تغییر می‌دهد
if (!actor_can_manage_user($admin, $target)) {
    perm_deny('فقط ادمینِ کل می‌تواند حسابِ ادمین‌ها را ویرایش کند.', $admin);
}

$stmt = $pdo->prepare('SELECT COUNT(*) c FROM customers WHERE owner_user_id = ?');
$stmt->execute([$id]);
$customerCount = (int) $stmt->fetch()['c'];

$errorsInfo = [];
$errorsPass = [];
$aradReady = users_arad_code_ready($pdo);
$jobGroupReady = users_job_group_ready($pdo);

$workLocationReady = false;
try {
    $chk = $pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'work_location'");
    $workLocationReady = (bool) $chk->fetchColumn();
} catch (Throwable $e) {
    $workLocationReady = false;
}

// ===================== خواندن نقش‌ها از جدول access_roles =====================
$allRolesForSelect = [];
$serviceRolesForSelect = []; // role_key => name (نقش‌های ویژه)
$extraRolesForSelect = [];   // id => name (نقش‌های مکمل)
$systemRolesForSelect = [];
$customRolesForSelect = [];
$validRoleSlugs = [];
try {
    $__allRoleRows = $pdo->query('SELECT * FROM access_roles ORDER BY is_system DESC, id ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($__allRoleRows as $__r) {
        $__kind = perm_role_row_kind($__r);
        if ($__kind === 'service' && (string) $__r['role_key'] !== '') $serviceRolesForSelect[(string) $__r['role_key']] = (string) $__r['name'];
        if ($__kind === 'custom' && (string) $__r['role_key'] !== 'super_admin') $extraRolesForSelect[(int) $__r['id']] = (string) $__r['name'];
    }
    // نقشِ اصلی: فقط نقش‌هایی که نوعشان «اصلی» است (ویژه و مکمل جداگانه انتخاب می‌شوند)
    $allRolesForSelect = array_values(array_filter($__allRoleRows, static fn($r) => (string) ($r['role_key'] ?? '') !== '' && perm_role_row_kind($r) === 'base'));
    foreach ($allRolesForSelect as $r) {
        $validRoleSlugs[] = (string)$r['role_key'];
    }
    $systemRolesForSelect = array_values(array_filter($allRolesForSelect, fn($r) => !empty($r['is_system'])));
    $customRolesForSelect = array_values(array_filter($allRolesForSelect, fn($r) => empty($r['is_system'])));
} catch (Throwable $e) {
    $allRolesForSelect = [];
    $systemRolesForSelect = [];
    $customRolesForSelect = [];
    $validRoleSlugs = [];
}
// اگر جدول access_roles خالی بود، حداقل مقادیر پیش‌فرض قبلی را نگه می‌داریم تا فرم خراب نشود.
if (!$validRoleSlugs) {
    $validRoleSlugs = ['A', 'B', 'C', 'admin', 'leader', 'nonsales'];
    $systemRolesForSelect = [
        ['name' => 'واحد A', 'role_key' => 'A', 'is_system' => 1],
        ['name' => 'واحد B', 'role_key' => 'B', 'is_system' => 1],
        ['name' => 'واحد C', 'role_key' => 'C', 'is_system' => 1],
        ['name' => 'ادمین', 'role_key' => 'admin', 'is_system' => 1],
        ['name' => 'سرپرست', 'role_key' => 'leader', 'is_system' => 1],
        ['name' => 'ستادی', 'role_key' => 'nonsales', 'is_system' => 1],
    ];
    $customRolesForSelect = [];
}
// نقشِ «ادمین» فقط برای ادمینِ کل در لیست است
$systemRolesForSelect = array_values(array_filter($systemRolesForSelect, static fn($r) => actor_can_assign_role($admin, (string) $r['role_key']) || (string) $r['role_key'] === (string) $target['role']));
$customRolesForSelect = array_values(array_filter($customRolesForSelect, static fn($r) => actor_can_assign_role($admin, (string) $r['role_key'])));
// نقشِ مکملِ فعلیِ کاربر
$currentExtraRoleId = 0;
try {
    $__xs = $pdo->prepare('SELECT role_id FROM user_access_roles WHERE user_id = ? LIMIT 1');
    $__xs->execute([$id]);
    $currentExtraRoleId = (int) $__xs->fetchColumn();
} catch (Throwable $e) {
}
// اگر نقشِ ویژه‌ی فعلی در فهرست نبود (قدیمی)، حفظ و نمایش داده شود
$__curService = (string) ($target['service_access_role'] ?? '');
if ($__curService !== '' && !isset($serviceRolesForSelect[$__curService])) {
    $serviceRolesForSelect[$__curService] = function_exists('perm_role_label') ? perm_role_label($__curService) : $__curService;
}
// =============================================================================

$leaders = $pdo->query("SELECT id, full_name FROM users WHERE role = 'leader' ORDER BY full_name ASC")->fetchAll();
$currentLeaderId = null;
if (!empty($target['team_id'])) {
    $curLeaderStmt = $pdo->prepare('SELECT leader_user_id FROM teams WHERE id = ? LIMIT 1');
    $curLeaderStmt->execute([(int) $target['team_id']]);
    $curLeaderVal = $curLeaderStmt->fetchColumn();
    $currentLeaderId = $curLeaderVal !== false ? (int) $curLeaderVal : null;
}

// ===================== ویرایش نام/موبایل/نقش =====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_info') {
    if (!csrf_verify()) {
        $errorsInfo[] = 'نشست منقضی شده است، دوباره تلاش کنید.';
    }
    $fullName = trim($_POST['full_name'] ?? '');
    $mobile   = normalize_digits(trim($_POST['mobile'] ?? ''));
    $newRole  = $_POST['role'] ?? $target['role'];
    $__canChangeRole = user_can('admin_users_role', $admin);
    if (!$__canChangeRole) {
        // بدونِ مجوزِ «تغییر نقش کاربر»، نقش‌ها دست‌نخورده می‌مانند
        $newRole = $target['role'];
    }
    $aradCode = $aradReady ? normalize_digits(trim((string) ($_POST['arad_code'] ?? ''))) : null;
    $jobGroupInput = $jobGroupReady ? trim((string) ($_POST['job_group'] ?? '')) : '';
    $workLocationInput = $workLocationReady ? trim((string) ($_POST['work_location'] ?? '')) : '';
    if ($workLocationReady && !in_array($workLocationInput, ['onsite', 'remote'], true)) {
        $workLocationInput = 'onsite';
    }
    $leaderIdInput = trim((string) ($_POST['leader_id'] ?? ''));
    $newTeamId = null;
    if ($leaderIdInput !== '') {
        $teamLookup = $pdo->prepare('SELECT id FROM teams WHERE leader_user_id = ? LIMIT 1');
        $teamLookup->execute([(int) $leaderIdInput]);
        $teamLookupVal = $teamLookup->fetchColumn();
        if ($teamLookupVal === false) {
            $errorsInfo[] = 'این سرپرست هنوز تیمی ندارد؛ از «مدیریت تیم‌ها» ابتدا برایش تیم بسازید.';
        } else {
            $newTeamId = (int) $teamLookupVal;
        }
    }

    if (mb_strlen($fullName) < 3) {
        $errorsInfo[] = 'نام و نام خانوادگی را کامل وارد کنید.';
    }
    if (!is_valid_iran_mobile($mobile)) {
        $errorsInfo[] = 'شماره موبایل معتبر نیست.';
    }
    // اعتبارسنجی نقش بر اساس role_keyهای موجود در جدول access_roles
    if (!in_array($newRole, $validRoleSlugs, true)) {
        $errorsInfo[] = 'نقش انتخابی معتبر نیست.';
    }
    if ($newRole !== $target['role'] && !actor_can_assign_role($admin, (string) $newRole)) {
        $errorsInfo[] = 'فقط ادمینِ کل می‌تواند نقشِ ادمین بدهد.';
    }
    if ($id === (int) $admin['id'] && $newRole !== 'admin') {
        $errorsInfo[] = 'نمی‌توانید نقش حساب خودتان را از ادمین خارج کنید.';
    }
    if ($jobGroupReady && $jobGroupInput !== '' && normalize_job_group($jobGroupInput) === null) {
        $errorsInfo[] = 'گروه شغلیِ انتخابی معتبر نیست.';
    }
    if (!$errorsInfo) {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE mobile = ? AND id != ? LIMIT 1');
        $stmt->execute([$mobile, $id]);
        if ($stmt->fetch()) {
            $errorsInfo[] = 'این شماره موبایل قبلا توسط کاربر دیگری ثبت شده است.';
        }
    }
    if (!$errorsInfo && $aradReady && $aradCode !== '') {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE arad_code = ? AND id != ? LIMIT 1');
        $stmt->execute([$aradCode, $id]);
        if ($stmt->fetch()) {
            $errorsInfo[] = 'این آراد کد قبلا برای کاربر دیگری ثبت شده است.';
        }
    }
    if (!$errorsInfo) {
        if ($workLocationReady) {
            $stmt = $pdo->prepare('UPDATE users SET full_name = ?, mobile = ?, role = ?, team_id = ?, work_location = ? WHERE id = ?');
            $stmt->execute([$fullName, $mobile, $newRole, $newTeamId, $workLocationInput, $id]);
        } else {
            $stmt = $pdo->prepare('UPDATE users SET full_name = ?, mobile = ?, role = ?, team_id = ? WHERE id = ?');
            $stmt->execute([$fullName, $mobile, $newRole, $newTeamId, $id]);
        }

        // «وضعیت حضور» در لیستِ کاربران همین مقدار است (هر دو ستون با هم)
        if ($workLocationReady) users_set_work_location($pdo, $id, $workLocationInput);
        if ($aradReady && $aradCode !== '') {
            $pdo->prepare('UPDATE users SET arad_code = ? WHERE id = ?')->execute([$aradCode, $id]);
        }
        if ($jobGroupReady && $jobGroupInput !== '') {
            $pdo->prepare('UPDATE users SET job_group = ? WHERE id = ?')->execute([normalize_job_group($jobGroupInput), $id]);
        }

        if (is_super_admin($admin) && $id !== (int) $admin['id']) {
            $makeSuperAdmin = ($newRole === 'admin' && isset($_POST['is_super_admin'])) ? 1 : 0;
            $pdo->prepare('UPDATE users SET is_super_admin = ? WHERE id = ?')->execute([$makeSuperAdmin, $id]);
        }

        // دسترسی ویژه «درخواست‌های خدمات» (بخش پذیرش/نظارت/رابط مالی)
        $serviceAccessRole = trim((string) ($_POST['service_access_role'] ?? ''));
        if (!isset($serviceRolesForSelect[$serviceAccessRole])) {
            $serviceAccessRole = null;
        }
        if ($__canChangeRole) {
            $pdo->prepare('UPDATE users SET service_access_role = ? WHERE id = ?')->execute([$serviceAccessRole, $id]);
            // نقشِ مکمل (فقط مجوزهایش اضافه می‌شود؛ هر کاربر حداکثر یکی)
            if (isset($_POST['extra_role_id'])) {
                $extraId = (int) $_POST['extra_role_id'];
                try {
                    if ($extraId > 0 && isset($extraRolesForSelect[$extraId])) {
                        $pdo->prepare('INSERT INTO user_access_roles (user_id, role_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE role_id = VALUES(role_id), assigned_at = CURRENT_TIMESTAMP')
                            ->execute([$id, $extraId]);
                    } elseif ($extraId === 0) {
                        $pdo->prepare('DELETE FROM user_access_roles WHERE user_id = ?')->execute([$id]);
                    }
                } catch (Throwable $e) {
                    error_log('user edit extra role: ' . $e->getMessage());
                }
            }
        }

        flash_set('success', 'اطلاعات کاربر بروزرسانی شد.');
        redirect('admin_user_edit.php?id=' . $id);
    }
}

// ===================== تنظیم رمز عبور جدید =====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reset_password') {
    if (!csrf_verify()) {
        $errorsPass[] = 'نشست منقضی شده است، دوباره تلاش کنید.';
    }
    $newPass = $_POST['new_password'] ?? '';
    $confirm = $_POST['new_password_confirm'] ?? '';

    if (mb_strlen($newPass) < 6) {
        $errorsPass[] = 'رمز عبور جدید باید حداقل ۶ کاراکتر باشد.';
    }
    if ($newPass !== $confirm) {
        $errorsPass[] = 'رمز عبور جدید و تکرار آن یکسان نیستند.';
    }
    if (!$errorsPass) {
        $stmt = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $stmt->execute([password_hash($newPass, PASSWORD_BCRYPT), $id]);
        invalidate_all_remember_tokens($pdo, $id);
        flash_set('success', 'رمز عبور کاربر با موفقیت تغییر یافت.');
        redirect('admin_user_edit.php?id=' . $id);
    }
}

// ===================== تعلیق / فعال‌سازی حساب =====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_active') {
    if (!csrf_verify()) {
        flash_set('danger', 'نشست منقضی شده است، دوباره تلاش کنید.');
        redirect('admin_user_edit.php?id=' . $id);
    }
    if ($id === (int) $admin['id']) {
        flash_set('danger', 'نمی‌توانید حساب خودتان را تعلیق کنید.');
        redirect('admin_user_edit.php?id=' . $id);
    }
    if ($target['is_active']) {
        $pdo->prepare('UPDATE users SET is_active = 0 WHERE id = ?')->execute([$id]);
        invalidate_all_remember_tokens($pdo, $id);
        flash_set('success', 'کاربر تعلیق شد.');
    } else {
        $pdo->prepare('UPDATE users SET is_active = 1 WHERE id = ?')->execute([$id]);
        flash_set('success', 'کاربر فعال شد.');
    }
    redirect('admin_user_edit.php?id=' . $id);
}

// ===================== حذف عکس پروفایل =====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'remove_avatar') {
    if (csrf_verify() && !empty($target['profile_image'])) {
        $oldPath = __DIR__ . '/../' . $target['profile_image'];
        if (is_file($oldPath)) {
            @unlink($oldPath);
        }
        $pdo->prepare('UPDATE users SET profile_image = NULL WHERE id = ?')->execute([$id]);
        flash_set('success', 'عکس پروفایل حذف شد.');
    }
    redirect('admin_user_edit.php?id=' . $id);
}

// ===================== حذف کامل حساب کاربر =====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_user') {
    if (!csrf_verify()) {
        flash_set('danger', 'نشست منقضی شده است.');
        redirect('admin_user_edit.php?id=' . $id);
    }
    if (!is_super_admin($admin)) {
        flash_set('danger', 'فقط ادمین کل اجازه حذف کامل کاربر را دارد. می‌توانید به‌جای آن کاربر را تعلیق کنید.');
        redirect('admin_user_edit.php?id=' . $id);
    }
    if ($id === (int) $admin['id']) {
        flash_set('danger', 'نمی‌توانید حساب خودتان را حذف کنید.');
        redirect('admin_user_edit.php?id=' . $id);
    }
    if ($customerCount > 0) {
        flash_set('danger', 'این کاربر ' . $customerCount . ' مشتری ثبت‌شده دارد و قابل حذف نیست. ابتدا کاربر را غیرفعال کنید یا مشتریان را جابه‌جا کنید.');
        redirect('admin_user_edit.php?id=' . $id);
    }
    if (!empty($target['profile_image'])) {
        $oldPath = __DIR__ . '/../' . $target['profile_image'];
        if (is_file($oldPath)) {
            @unlink($oldPath);
        }
    }
    $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
    flash_set('success', 'کاربر با موفقیت حذف شد.');
    redirect('admin_users.php');
}

// بازخوانی آخرین اطلاعات بعد از تغییرات
$stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
$stmt->execute([$id]);
$target = $stmt->fetch();

$pageTitle = 'ویرایش کاربر - ' . $target['full_name'];
require_once __DIR__ . '/../includes/layout_top.php';
?>

<style>
.ue-page{--ue-line:#e7e2d3;--ue-ink:#1c1917;--ue-muted:#78716c;--ue-gold:#c9a24b;--ue-gold-2:#f1dfa8;}
.ue-page .ue-topbar{display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;background:linear-gradient(120deg,#0b0f1a 0%,#241d0a 55%,#3d3220 130%);border-radius:20px;padding:1.1rem 1.4rem;margin-bottom:1.2rem;position:relative;overflow:hidden;box-shadow:0 10px 26px -16px rgba(28,25,23,.5);}
.ue-page .ue-topbar::before{content:'';position:absolute;inset:0;pointer-events:none;background:radial-gradient(circle at 95% -20%, rgba(201,162,75,.28), transparent 55%),radial-gradient(circle at 0% 130%, rgba(201,162,75,.14), transparent 45%);}
.ue-page .ue-topbar h5{position:relative;z-index:1;margin:0;font-weight:800;color:#fff;font-size:1.1rem;display:flex;align-items:center;gap:.6rem;}
.ue-page .ue-topbar h5 i{width:36px;height:36px;border-radius:11px;display:inline-flex;align-items:center;justify-content:center;background:linear-gradient(135deg,var(--ue-gold-2),var(--ue-gold));color:#241d0a;font-size:.9rem;}
.ue-page .ue-topbar .btn-outline-secondary{position:relative;z-index:1;border-radius:12px;font-weight:700;color:#f5f5f4;border-color:rgba(255,255,255,.25);background:rgba(255,255,255,.06);}
.ue-page .ue-topbar .btn-outline-secondary:hover{background:rgba(255,255,255,.14);color:#fff;border-color:rgba(255,255,255,.4)}
.ue-page .admin-profile-card,.ue-page .admin-form-card,.ue-page .admin-danger-card{border:1px solid var(--ue-line) !important;border-radius:18px !important;box-shadow:0 4px 20px -16px rgba(28,25,23,.3);}
.ue-page .admin-form-card h6,.ue-page .admin-danger-card h6{display:flex;align-items:center;gap:.5rem;font-weight:800;color:var(--ue-ink)}
.ue-page .admin-form-card h6 i{color:var(--ue-gold)}
.ue-page .admin-profile-card h6{font-weight:800;color:var(--ue-ink)}
.ue-page .form-label{font-weight:700;color:#57534e}
.ue-page .admin-form-card .form-control,.ue-page .admin-form-card .form-select{border-color:var(--ue-line);border-radius:10px}
.ue-page .admin-form-card .form-control:focus,.ue-page .admin-form-card .form-select:focus{border-color:var(--ue-gold);box-shadow:0 0 0 3px rgba(201,162,75,.15)}
.ue-page .btn-primary{background:linear-gradient(135deg,var(--ue-gold-2),var(--ue-gold));border:none;color:#241708;font-weight:700;box-shadow:0 8px 18px -10px rgba(201,162,75,.6);}
.ue-page .btn-primary:hover{filter:brightness(.97);color:#241708}
.ue-page .btn-outline-warning{border-color:#c98a3a;color:#8a5a1e}
.ue-page .btn-outline-warning:hover{background:#c98a3a;border-color:#c98a3a;color:#fff}
.ue-page .btn-outline-success{border-color:#3f8f5e;color:#2f6d47}
.ue-page .btn-outline-success:hover{background:#3f8f5e;border-color:#3f8f5e;color:#fff}
.ue-page .btn-outline-danger{border-color:#a1303c;color:#a1303c}
.ue-page .btn-outline-danger:hover{background:#a1303c;border-color:#a1303c;color:#fff}
.ue-page .admin-profile-avatar{box-shadow:0 6px 16px -8px rgba(201,162,75,.5)}
.ue-page .admin-profile-table th{color:var(--ue-muted);font-weight:600}
.ue-page .admin-profile-table .badge.bg-success{background:linear-gradient(135deg,#bfe8c9,#4e9d63) !important;color:#0f3d1c !important}
.ue-page .admin-profile-table .badge.bg-warning{background:linear-gradient(135deg,var(--ue-gold-2),var(--ue-gold)) !important;color:#4a3712 !important}
.ue-page .admin-profile-table .badge.bg-primary{background:linear-gradient(135deg,#0b0f1a,#3d3220) !important;color:#f1dfa8 !important}
.ue-page .admin-danger-card{background:#fffaf8 !important;border-color:#f0d3d6 !important}
.ue-page .admin-danger-card h6{color:#a1303c}
.ue-page .alert{border-radius:14px}
.ue-page .alert-warning code{background:rgba(0,0,0,.08);padding:.1rem .35rem;border-radius:5px}
</style>

<div class="ue-page">

<div class="ue-topbar">
  <h5><i class="fa-solid fa-user-pen"></i> ویرایش کاربر: <?= e($target['full_name']) ?></h5>
  <a href="admin_users.php" class="btn btn-sm btn-outline-secondary">بازگشت به مدیریت کاربران</a>
</div>

<div class="row g-3 admin-edit-grid">
  <div class="col-lg-4">
    <div class="card admin-profile-card p-3 text-center">
      <div class="profile-avatar-lg admin-profile-avatar mx-auto mb-2">
        <?= avatar_markup($target, $__base, 'profile-avatar-circle') ?>
      </div>
      <h6 class="mb-0"><?= e($target['full_name']) ?></h6>
      <div class="text-muted small mb-3"><?= e(role_label($target['role'])) ?></div>

      <?php if (!empty($target['profile_image'])): ?>
        <form method="post" onsubmit="return confirm('عکس پروفایل این کاربر حذف شود؟');">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="remove_avatar">
          <button type="submit" class="btn btn-sm btn-outline-danger w-100"><i class="fa-solid fa-trash"></i> حذف عکس پروفایل</button>
        </form>
      <?php endif; ?>

      <?php if ($id !== (int) $admin['id']): ?>
        <form method="post" class="mt-2" onsubmit="return confirm('<?= $target['is_active'] ? 'این کاربر تعلیق شود؟ تا زمانی که دوباره فعال نشود، در آمار و لیست‌های عملکرد نمایش داده نمی‌شود.' : 'این کاربر فعال شود؟' ?>');">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="toggle_active">
          <?php if ($target['is_active']): ?>
            <button type="submit" class="btn btn-sm btn-outline-warning w-100"><i class="fa-solid fa-pause"></i> تعلیق کردن</button>
          <?php else: ?>
            <button type="submit" class="btn btn-sm btn-outline-success w-100"><i class="fa-solid fa-play"></i> فعال کردن</button>
          <?php endif; ?>
        </form>
      <?php endif; ?>

      <hr class="my-3">
      <table class="table table-sm admin-profile-table text-start mb-0">
        <tr><th class="text-muted">وضعیت عضویت</th><td><?= $target['is_approved'] ? '<span class="badge bg-success">تایید شده</span>' : '<span class="badge bg-warning text-dark">در انتظار تایید</span>' ?></td></tr>
        <tr><th class="text-muted">وضعیت حساب</th><td><?= $target['is_active'] ? '<span class="badge bg-success">فعال</span>' : '<span class="badge bg-warning text-dark">تعلیق شده</span>' ?></td></tr>
        <?php if (is_super_admin($admin) && !empty($target['is_super_admin'])): ?>
        <tr><th class="text-muted">دسترسی ویژه</th><td><span class="badge bg-primary">ادمین کل</span></td></tr>
        <?php endif; ?>
        <tr><th class="text-muted">تاریخ عضویت</th><td><?= to_jalali(substr($target['created_at'], 0, 10)) ?></td></tr>
        <?php if (!empty($target['arad_code'])): ?>
        <tr><th class="text-muted">آراد کد</th><td dir="ltr"><?= e($target['arad_code']) ?></td></tr>
        <?php endif; ?>
        <?php if (!empty($target['job_group'])): ?>
        <tr><th class="text-muted">گروه شغلی</th><td><?= e($target['job_group']) ?></td></tr>
        <?php endif; ?>
        <?php if ($workLocationReady && !empty($target['work_location'])): ?>
        <tr><th class="text-muted">محل فعالیت</th><td><?= $target['work_location'] === 'remote' ? 'دورکار' : 'حضوری' ?></td></tr>
        <?php endif; ?>
        <?php
          $currentLeaderName = null;
          if ($currentLeaderId !== null) {
              foreach ($leaders as $l) {
                  if ((int) $l['id'] === $currentLeaderId) { $currentLeaderName = $l['full_name']; break; }
              }
          }
        ?>
        <tr><th class="text-muted">سرپرست</th><td><?= $currentLeaderName ? e($currentLeaderName) : '<span class="text-muted">فاقد سرپرست</span>' ?></td></tr>
        <?php if (in_array($target['role'], ['A', 'B', 'C'], true)): ?>
        <tr><th class="text-muted">تعداد مشتریان</th><td><?= $customerCount ?> <a href="admin_staff_view.php?id=<?= $id ?>" class="small">(مشاهده جزئیات)</a></td></tr>
        <?php endif; ?>
      </table>
    </div>
  </div>

  <div class="col-lg-8">
    <div class="card admin-form-card p-3 mb-3">
      <h6 class="mb-3"><i class="fa-solid fa-id-card"></i> اطلاعات کاربر</h6>

      <?php if (!$workLocationReady): ?>
        <div class="alert alert-warning py-2 small mb-3">
          <i class="fa-solid fa-triangle-exclamation"></i> فیلدِ «محل فعالیت» فعلاً روی دیتابیس فعال نیست — برای فعال‌سازیش، ستونِ زیر باید به جدولِ <code>users</code> اضافه بشه:
          <code>ALTER TABLE users ADD COLUMN work_location ENUM('onsite','remote') NULL;</code>
        </div>
      <?php endif; ?>

      <?php foreach ($errorsInfo as $err): ?>
        <div class="alert alert-danger py-2"><?= e($err) ?></div>
      <?php endforeach; ?>

      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update_info">
        <div class="row g-3 admin-edit-grid">
          <div class="col-md-6">
            <label class="form-label">نام و نام خانوادگی</label>
            <input type="text" name="full_name" class="form-control" value="<?= e($target['full_name']) ?>" required>
          </div>
          <div class="col-md-6">
            <label class="form-label">شماره موبایل</label>
            <input type="text" name="mobile" class="form-control" dir="ltr" value="<?= e($target['mobile']) ?>" required>
          </div>
          <?php if ($aradReady): ?>
          <div class="col-md-6">
            <label class="form-label">آراد کد</label>
            <input type="text" name="arad_code" class="form-control" dir="ltr" placeholder="<?= e($target['arad_code'] ?? '') ?>" value="<?= e($target['arad_code'] ?? '') ?>">
            <div class="form-text">با هر ساختاری که خودتان می‌خواهید قابل ویرایش است؛ باید بین همه‌ی کاربران یکتا بماند. اگر خالی بگذارید، کدِ فعلی دست‌نخورده می‌ماند.</div>
          </div>
          <?php endif; ?>
          <div class="col-md-6">
            <label class="form-label">نقشِ اصلی <span class="badge text-bg-dark">اصلی</span></label>
            <select name="role" id="role_select" class="form-select" <?= $id === (int) $admin['id'] ? 'disabled' : '' ?>>
              <?php if ($systemRolesForSelect): ?>
                <optgroup label="نقش‌های سیستمی">
                  <?php foreach ($systemRolesForSelect as $r):
                      $slug = (string)($r['role_key'] ?? '');
                      if ($slug === '') continue;
                  ?>
                    <option value="<?= e($slug) ?>" <?= $target['role'] === $slug ? 'selected' : '' ?>><?= e($r['name']) ?></option>
                  <?php endforeach; ?>
                </optgroup>
              <?php endif; ?>
              <?php if ($customRolesForSelect): ?>
                <optgroup label="نقش‌های سفارشی">
                  <?php foreach ($customRolesForSelect as $r):
                      $slug = (string)($r['role_key'] ?? '');
                      if ($slug === '') continue;
                  ?>
                    <option value="<?= e($slug) ?>" <?= $target['role'] === $slug ? 'selected' : '' ?>><?= e($r['name']) ?></option>
                  <?php endforeach; ?>
                </optgroup>
              <?php endif; ?>
            </select>
            <?php if ($id === (int) $admin['id']): ?>
              <input type="hidden" name="role" value="admin">
              <div class="form-text">نقش حساب خودتان قابل تغییر نیست.</div>
            <?php endif; ?>
          </div>
          <?php if ($jobGroupReady): ?>
          <div class="col-md-6">
            <label class="form-label">گروه شغلی</label>
            <select name="job_group" class="form-select">
              <option value="">— بدون تغییر —</option>
              <?php foreach (valid_job_groups() as $jg): ?>
                <option value="<?= e($jg) ?>" <?= ($target['job_group'] ?? '') === $jg ? 'selected' : '' ?>><?= e($jg) ?></option>
              <?php endforeach; ?>
            </select>
            <div class="form-text">فقط توسعه/عملیات معمولاً با نقشِ سرپرست یا واحدهای A/B/C هم‌خوانی دارند؛ ستادی با نقشِ «ستادی».</div>
          </div>
          <?php endif; ?>
          <?php if ($workLocationReady): ?>
          <div class="col-md-6">
            <label class="form-label">محل فعالیت</label>
            <select name="work_location" class="form-select">
              <option value="onsite" <?= (users_work_location_of($target) ?? 'onsite') === 'onsite' ? 'selected' : '' ?>>حضوری</option>
              <option value="remote" <?= users_work_location_of($target) === 'remote' ? 'selected' : '' ?>>دورکار</option>
            </select>
          </div>
          <?php endif; ?>
          <div class="col-md-6">
            <label class="form-label">سرپرست</label>
            <select name="leader_id" class="form-select">
              <option value="">فاقد سرپرست</option>
              <?php foreach ($leaders as $l): ?>
                <option value="<?= (int) $l['id'] ?>" <?= $currentLeaderId === (int) $l['id'] ? 'selected' : '' ?>><?= e($l['full_name']) ?></option>
              <?php endforeach; ?>
            </select>
            <div class="form-text">تیمِ این کاربر بر اساسِ سرپرستِ انتخابی تنظیم می‌شود.</div>
          </div>
          <?php if (is_super_admin($admin) && $id !== (int) $admin['id']): ?>
          <div class="col-md-6" id="super_admin_wrap" style="<?= $target['role'] === 'admin' ? '' : 'display:none' ?>">
            <label class="form-label d-block">دسترسی ویژه</label>
            <div class="form-check form-switch admin-switch mt-2">
              <input class="form-check-input" type="checkbox" role="switch" name="is_super_admin" id="is_super_admin_cb" value="1" <?= !empty($target['is_super_admin']) ? 'checked' : '' ?>>
              <label class="form-check-label" for="is_super_admin_cb">ادمین کل (اجازه حذف کامل کاربران)</label>
            </div>
            <div class="form-text">فقط ادمین کل می‌تواند کاربر دیگری را برای همیشه حذف کند؛ سایر ادمین‌ها فقط می‌توانند تعلیق کنند.</div>
          </div>
          <?php endif; ?>
          <div class="col-md-6">
            <label class="form-label">نقشِ ویژه <span class="badge text-bg-primary">ویژه</span></label>
            <select name="service_access_role" class="form-select" <?= user_can('admin_users_role', $admin) ? '' : 'disabled' ?>>
              <option value="">— هیچ‌کدام —</option>
              <?php foreach ($serviceRolesForSelect as $__sk => $__sn): ?>
                <option value="<?= e($__sk) ?>" <?= ($target['service_access_role'] ?? '') === $__sk ? 'selected' : '' ?>><?= e($__sn) ?></option>
              <?php endforeach; ?>
            </select>
            <div class="form-text">جایگاهِ دوم کنارِ نقشِ اصلی (مثلِ نظارت، رابط مالی، پذیرش، واحد قرارداد)؛ محدودیتِ «کارمندِ عادی» را هم برمی‌دارد.</div>
          </div>
          <div class="col-md-6">
            <label class="form-label">نقشِ مکمل <span class="badge text-bg-success">مکمل</span></label>
            <select name="extra_role_id" class="form-select" <?= user_can('admin_users_role', $admin) ? '' : 'disabled' ?>>
              <option value="0">— هیچ‌کدام —</option>
              <?php foreach ($extraRolesForSelect as $__xid => $__xn): ?>
                <option value="<?= (int) $__xid ?>" <?= $currentExtraRoleId === (int) $__xid ? 'selected' : '' ?>><?= e($__xn) ?></option>
              <?php endforeach; ?>
            </select>
            <div class="form-text">فقط مجوزهایش به کاربر اضافه می‌شود؛ نقشِ اصلی عوض نمی‌شود. همه‌ی مجوزهای این نقش (حتی حساس، مثلِ امورِ مالی) به کاربر داده می‌شود.</div>
          </div>
          <div class="col-12">
            <?php
              $__mainName = '';
              foreach ($allRolesForSelect as $__r) if ((string) $__r['role_key'] === (string) $target['role']) $__mainName = (string) $__r['name'];
              if ($__mainName === '' && function_exists('perm_role_label')) $__mainName = perm_role_label((string) $target['role']);
            ?>
            <div class="border rounded-3 p-2 small" style="background:#fdfcf8">
              <b>نقش‌های فعلیِ این کاربر:</b>
              <span class="badge text-bg-dark ms-1">اصلی: <?= e($__mainName ?: (string) $target['role']) ?></span>
              <span class="badge text-bg-primary ms-1">ویژه: <?= e(($target['service_access_role'] ?? '') !== '' ? ($serviceRolesForSelect[$target['service_access_role']] ?? $target['service_access_role']) : '—') ?></span>
              <span class="badge text-bg-success ms-1">مکمل: <?= e($currentExtraRoleId ? ($extraRolesForSelect[$currentExtraRoleId] ?? ('#' . $currentExtraRoleId)) : '—') ?></span>
            </div>
          </div>
        </div>
        <button type="submit" class="btn btn-primary mt-3"><i class="fa-solid fa-floppy-disk"></i> ذخیره تغییرات</button>
      </form>
    </div>

    <div class="card admin-form-card p-3 mb-3">
      <h6 class="mb-3"><i class="fa-solid fa-key"></i> تنظیم رمز عبور جدید</h6>
      <p class="text-muted small">با استفاده از این فرم می‌توانید بدون نیاز به دانستن رمز فعلی، رمز عبور این کاربر را تغییر دهید.</p>

      <?php foreach ($errorsPass as $err): ?>
        <div class="alert alert-danger py-2"><?= e($err) ?></div>
      <?php endforeach; ?>

      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="reset_password">
        <div class="row g-3 admin-edit-grid">
          <div class="col-md-6">
            <label class="form-label">رمز عبور جدید</label>
            <input type="password" name="new_password" class="form-control" required minlength="6">
          </div>
          <div class="col-md-6">
            <label class="form-label">تکرار رمز جدید</label>
            <input type="password" name="new_password_confirm" class="form-control" required minlength="6">
          </div>
        </div>
        <button type="submit" class="btn btn-primary mt-3"><i class="fa-solid fa-lock"></i> تنظیم رمز جدید</button>
      </form>
    </div>

    <?php if ($id !== (int) $admin['id']): ?>
    <div class="card admin-danger-card p-3 border border-danger-subtle">
      <h6 class="mb-3"><i class="fa-solid fa-triangle-exclamation"></i> منطقه خطر</h6>
      <?php if (!is_super_admin($admin)): ?>
        <p class="text-muted small mb-3">
          حذف کامل حساب کاربر فقط توسط «ادمین کل» قابل انجام است. در صورت نیاز می‌توانید از بخش «مدیریت کاربران»
          حساب این کاربر را تعلیق کنید تا تا اطلاع بعدی غیرفعال بماند و از آمار و لیست‌ها حذف شود.
        </p>
        <a href="admin_users.php" class="btn btn-outline-warning btn-sm">رفتن به مدیریت کاربران</a>
      <?php elseif ($customerCount > 0): ?>
        <p class="text-muted small mb-3">
          این کاربر <b><?= $customerCount ?></b> مشتری ثبت‌شده دارد، بنابراین برای جلوگیری از از دست رفتن اطلاعات، امکان حذف کامل حساب وجود ندارد.
          در صورت نیاز می‌توانید از بخش «مدیریت کاربران» فقط حساب او را تعلیق کنید.
        </p>
        <a href="admin_users.php" class="btn btn-outline-secondary btn-sm">رفتن به مدیریت کاربران</a>
      <?php else: ?>
        <p class="text-muted small mb-3">حذف حساب کاربر غیرقابل بازگشت است. این کاربر در حال حاضر هیچ مشتری ثبت‌شده‌ای ندارد.</p>
        <form method="post" onsubmit="return confirm('آیا از حذف کامل این کاربر مطمئن هستید؟ این عملیات غیرقابل بازگشت است.');">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="delete_user">
          <button type="submit" class="btn btn-danger btn-sm"><i class="fa-solid fa-trash"></i> حذف کامل حساب کاربر</button>
        </form>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
</div>

</div>

<?php if (is_super_admin($admin) && $id !== (int) $admin['id']): ?>
<script>
(function () {
  var roleEl = document.getElementById('role_select');
  var wrapEl = document.getElementById('super_admin_wrap');
  if (!roleEl || !wrapEl) return;
  roleEl.addEventListener('change', function () {
    wrapEl.style.display = roleEl.value === 'admin' ? '' : 'none';
  });
})();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/layout_bottom.php'; ?>