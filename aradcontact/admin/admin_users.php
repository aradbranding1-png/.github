<?php
require_once __DIR__ . '/../includes/auth.php';
$user = require_admin();
$pdo  = db();

// ===================== پردازش POST =====================
// نکته مهم: اکشن change_role نیاز به $validRoleSlugs دارد که پایین‌تر ساخته می‌شود،
// پس آن را در بلوک جداگانه‌ای پایین‌تر پردازش می‌کنیم. بقیه اکشن‌ها اینجا پردازش می‌شوند.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ⚠️ change_role را اینجا نمی‌گیریم چون $validRoleSlugs هنوز تعریف نشده.
    // آن در بلوک جداگانه بعد از ساخت $validRoleSlugs پردازش می‌شود.
    if ($action !== 'change_role') {
        if (!csrf_verify()) {
            flash_set('danger', 'نشست منقضی شده است.');
            redirect('admin_users.php');
        }
        $targetId = (int) ($_POST['user_id'] ?? 0);

        // ادمین‌ها حسابِ ادمینِ کل و ادمین‌های دیگر را تغییر نمی‌دهند
        $__t = $pdo->prepare('SELECT * FROM users WHERE id = ?');
        $__t->execute([$targetId]);
        $__target = $__t->fetch(PDO::FETCH_ASSOC);
        if ($__target && !actor_can_manage_user($user, $__target)) {
            flash_set('danger', 'فقط ادمینِ کل می‌تواند حسابِ ادمین‌ها را تغییر دهد.');
            redirect('admin_users.php');
        }

        if ($targetId === (int) $user['id'] && in_array($action, ['deactivate', 'delete'], true)) {
            flash_set('danger', 'نمی‌توانید این عملیات را روی حساب خودتان انجام دهید.');
            redirect('admin_users.php');
        }

        // هر عملیات، مجوزِ مخصوصِ خودش را از «نقش‌ها و دسترسی‌ها» لازم دارد
        $__actionPerms = [
            'approve' => 'admin_users_approve', 'reject' => 'admin_users_approve',
            'deactivate' => 'admin_users_activate', 'activate' => 'admin_users_activate',
            'change_job_group' => 'admin_users_edit', 'change_work_mode' => 'admin_users_edit', 'change_department' => 'admin_users_edit', 'change_team' => 'admin_users_edit',
        ];
        if (isset($__actionPerms[$action]) && !user_can($__actionPerms[$action], $user)) {
            flash_set('danger', 'برای این عملیات مجوزِ «' . perm_label($__actionPerms[$action]) . '» لازم است.');
            redirect('admin_users.php');
        }

        switch ($action) {
            case 'approve':
                $pdo->prepare('UPDATE users SET is_approved = 1 WHERE id = ?')->execute([$targetId]);
                $approvedMobile = $pdo->prepare('SELECT mobile FROM users WHERE id = ?');
                $approvedMobile->execute([$targetId]);
                sync_colleague_contact_type($pdo, $approvedMobile->fetchColumn() ?: null);
                flash_set('success', 'کاربر تایید شد.');
                break;
            case 'reject':
                $pdo->prepare('DELETE FROM users WHERE id = ? AND is_approved = 0')->execute([$targetId]);
                flash_set('success', 'درخواست عضویت رد و حذف شد.');
                break;
            case 'deactivate':
                $pdo->prepare('UPDATE users SET is_active = 0 WHERE id = ?')->execute([$targetId]);
                flash_set('success', 'کاربر تعلیق شد.');
                break;
            case 'activate':
                $pdo->prepare('UPDATE users SET is_active = 1 WHERE id = ?')->execute([$targetId]);
                flash_set('success', 'کاربر فعال شد.');
                break;
            case 'change_job_group':
                $newJobGroup = trim((string) ($_POST['new_job_group'] ?? ''));
                if (users_col_exists($pdo, 'job_group') && in_array($newJobGroup, ['', 'توسعه', 'عملیات', 'ستادی'], true)) {
                    $pdo->prepare('UPDATE users SET job_group = ? WHERE id = ?')->execute([$newJobGroup !== '' ? $newJobGroup : null, $targetId]);
                    flash_set('success', 'گروه شغلی بروزرسانی شد.');
                }
                break;
            case 'change_work_mode':
                // همان «محل فعالیت»ِ صفحه‌ی ویرایشِ کاربر و گزارشِ سرپرست (هر دو ستون با هم)
                $newWorkMode = $_POST['new_work_mode'] ?? '';
                if (in_array($newWorkMode, ['', 'onsite', 'remote'], true)) {
                    users_set_work_location($pdo, $targetId, $newWorkMode !== '' ? $newWorkMode : null);
                    flash_set('success', 'وضعیت حضور بروزرسانی شد.');
                }
                break;
            case 'change_team':
                // همان «سرپرست»ِ صفحه‌ی ویرایشِ کاربر: تیمِ کاربر = تیمِ سرپرستِ انتخابی
                $newTeam = (int) ($_POST['new_team_id'] ?? 0);
                if ($newTeam > 0) {
                    $tc = $pdo->prepare('SELECT COUNT(*) FROM teams WHERE id = ?');
                    $tc->execute([$newTeam]);
                    if (!(int) $tc->fetchColumn()) { flash_set('danger', 'تیم پیدا نشد.'); break; }
                }
                $pdo->prepare('UPDATE users SET team_id = ? WHERE id = ?')->execute([$newTeam > 0 ? $newTeam : null, $targetId]);
                flash_set('success', 'تیم/سرپرست بروزرسانی شد.');
                break;
            case 'change_department':
                $newDept = trim((string) ($_POST['new_department'] ?? ''));
                if (users_col_exists($pdo, 'department')) {
                    $pdo->prepare('UPDATE users SET department = ? WHERE id = ?')->execute([$newDept !== '' ? $newDept : null, $targetId]);
                    flash_set('success', 'تیم/دپارتمان بروزرسانی شد.');
                }
                break;
        }
        redirect('admin_users.php');
    }
}


function users_col_exists(PDO $pdo, string $col): bool {
    $s=$pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME=?");
    $s->execute([$col]);
    return (int)$s->fetchColumn()>0;
}

$hasLastSeen = users_col_exists($pdo, 'last_seen');
$hasAradCode = users_arad_code_ready($pdo);
$hasJobGroup = users_col_exists($pdo, 'job_group');
users_work_sync_v1($pdo);
$hasWorkMode = users_col_exists($pdo, 'work_mode') || users_col_exists($pdo, 'work_location');
$__wc = users_work_cols($pdo);
$workExpr = !empty($__wc['work_location']) && !empty($__wc['work_mode']) ? 'COALESCE(work_location, work_mode)' : (!empty($__wc['work_location']) ? 'work_location' : 'work_mode');
// تیم‌ها (به نامِ سرپرست) — همان انتخابِ «سرپرست» در صفحه‌ی ویرایشِ کاربر
$teamOptions = [];
try {
    foreach ($pdo->query('SELECT t.id, t.name, u.full_name AS leader FROM teams t LEFT JOIN users u ON u.id = t.leader_user_id ORDER BY u.full_name, t.id')->fetchAll(PDO::FETCH_ASSOC) ?: [] as $__t) {
        $teamOptions[(int) $__t['id']] = trim((string) ($__t['leader'] ?? '')) !== '' ? (string) $__t['leader'] . (trim((string) $__t['name']) !== '' ? ' — ' . $__t['name'] : '') : ('تیم ' . ($__t['name'] ?: $__t['id']));
    }
} catch (Throwable $e) {}
$hasDepartment = users_col_exists($pdo, 'department');
$departmentOptions = $hasDepartment
    ? array_column($pdo->query("SELECT DISTINCT department FROM users WHERE department IS NOT NULL AND department <> '' ORDER BY department")->fetchAll(), 'department')
    : [];

// ===================== خواندن نقش‌ها از جدول access_roles =====================
$allRolesForSelect = [];
$validRoleSlugs = [];
try {
    $rs = $pdo->query("SELECT name, role_key, is_system FROM access_roles WHERE role_key IS NOT NULL AND role_key <> '' ORDER BY is_system DESC, id ASC");
    $allRolesForSelect = $rs->fetchAll(PDO::FETCH_ASSOC) ?: [];
    // نقش‌های «ویژه» (نظارت، مالی، پذیرش) و ادمین کل در ستونِ نقشِ اصلی ذخیره نمی‌شوند
    $__sysRoles = perm_system_roles();
    $allRolesForSelect = array_values(array_filter($allRolesForSelect, static function ($r) use ($__sysRoles) {
        $k = (string) $r['role_key'];
        return !isset($__sysRoles[$k]) || $__sysRoles[$k]['kind'] === 'base';
    }));
    foreach ($allRolesForSelect as $r) {
        $validRoleSlugs[] = (string)$r['role_key'];
    }
} catch (Throwable $e) {
    $allRolesForSelect = [];
    $validRoleSlugs = [];
}
// اگر جدول access_roles خالی بود، از لیست پیش‌فرض استفاده کن
if (!$validRoleSlugs) {
    $validRoleSlugs = ['A', 'B', 'C', 'admin', 'leader', 'nonsales'];
    $allRolesForSelect = [
        ['name' => 'واحد A', 'role_key' => 'A', 'is_system' => 1],
        ['name' => 'واحد B', 'role_key' => 'B', 'is_system' => 1],
        ['name' => 'واحد C', 'role_key' => 'C', 'is_system' => 1],
        ['name' => 'ادمین', 'role_key' => 'admin', 'is_system' => 1],
        ['name' => 'سرپرست', 'role_key' => 'leader', 'is_system' => 1],
        ['name' => 'ستادی', 'role_key' => 'nonsales', 'is_system' => 1],
    ];
}
// فقط ادمینِ کل نقشِ «ادمین» را می‌بیند و می‌دهد
$assignableRoles = array_values(array_filter($allRolesForSelect, static fn($r) => actor_can_assign_role($user, (string) $r['role_key'])));
// =============================================================================

// ===================== پردازش change_role (بعد از تعریف $validRoleSlugs) =====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_role') {
    if (!csrf_verify()) {
        flash_set('danger', 'نشست منقضی شده است.');
        redirect('admin_users.php');
    }

    $newRole  = trim((string)($_POST['new_role'] ?? ''));
    $targetId = (int)($_POST['user_id'] ?? 0);

    if (!user_can('admin_users_role', $user)) {
        flash_set('danger', 'برای تغییرِ نقش، مجوزِ «' . perm_label('admin_users_role') . '» لازم است.');
        redirect('admin_users.php');
    }

    if ($targetId <= 0) {
        flash_set('danger', 'کاربر نامعتبر است.');
        redirect('admin_users.php');
    }

    if ($newRole === '' || !in_array($newRole, $validRoleSlugs, true)) {
        flash_set('danger', 'نقش انتخاب‌شده معتبر نیست.');
        redirect('admin_users.php');
    }
    if (!actor_can_assign_role($user, $newRole)) {
        flash_set('danger', 'فقط ادمینِ کل می‌تواند نقشِ ادمین بدهد.');
        redirect('admin_users.php');
    }
    $__t = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $__t->execute([$targetId]);
    $__target = $__t->fetch(PDO::FETCH_ASSOC);
    if (!$__target || !actor_can_manage_user($user, $__target)) {
        flash_set('danger', 'فقط ادمینِ کل می‌تواند نقشِ ادمین‌ها را تغییر دهد.');
        redirect('admin_users.php');
    }

    try {
        $upd = $pdo->prepare('UPDATE users SET role = ? WHERE id = ?');
        $upd->execute([$newRole, $targetId]);

        // تأیید ذخیره‌سازی
        $verify = $pdo->prepare('SELECT role FROM users WHERE id = ?');
        $verify->execute([$targetId]);
        $savedRole = (string)$verify->fetchColumn();

        if ($savedRole !== $newRole) {
            flash_set('danger', 'تغییر نقش ذخیره نشد. مقدار فعلی در دیتابیس: ' . ($savedRole === '' ? '(خالی)' : $savedRole));
        } else {
            flash_set('success', 'نقش کاربر با موفقیت تغییر یافت.');
        }
    } catch (Throwable $e) {
        flash_set('danger', 'خطا در تغییر نقش: ' . $e->getMessage());
    }
    redirect('admin_users.php');
}

$pending = REQUIRE_ADMIN_APPROVAL
    ? $pdo->query('SELECT * FROM users WHERE is_approved = 0 AND ' . users_visibility_sql($user) . ' ORDER BY created_at DESC')->fetchAll()
    : [];

$search = trim((string) ($_GET['q'] ?? ''));
$roleFilter = trim((string) ($_GET['role'] ?? ''));
$statusFilter = isset($_GET['status']) ? trim((string) $_GET['status']) : 'active';
$onlineFilter = trim((string) ($_GET['online'] ?? ''));
$jobGroupFilter = trim((string) ($_GET['job_group'] ?? ''));
$workModeFilter = trim((string) ($_GET['work_mode'] ?? ''));
$departmentFilter = trim((string) ($_GET['department'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 25;

if ($roleFilter !== '' && !in_array($roleFilter, $validRoleSlugs, true)) {
    $roleFilter = '';
}
if (!in_array($statusFilter, ['all', 'active', 'inactive'], true)) {
    $statusFilter = 'active';
}
if (!in_array($onlineFilter, ['', 'online', 'recent', 'offline'], true)) {
    $onlineFilter = '';
}
if (!in_array($jobGroupFilter, ['', 'توسعه', 'عملیات', 'ستادی'], true)) {
    $jobGroupFilter = '';
}
if (!in_array($workModeFilter, ['', 'onsite', 'remote'], true)) {
    $workModeFilter = '';
}

$where = [users_visibility_sql($user)];
$params = [];

if (REQUIRE_ADMIN_APPROVAL) {
    $where[] = 'is_approved = 1';
}
if ($search !== '') {
    // نام یا شماره موبایل (ارقامِ فارسی/انگلیسی، با یا بدونِ ۰ اول)
    [$__sq, $__sp] = users_search_sql($search);
    $where[] = $__sq;
    array_push($params, ...$__sp);
}
if ($roleFilter !== '') {
    $where[] = 'role = ?';
    $params[] = $roleFilter;
}
if ($statusFilter === 'active') {
    $where[] = 'is_active = 1';
} elseif ($statusFilter === 'inactive') {
    $where[] = 'is_active = 0';
}
if ($onlineFilter !== '' && $hasLastSeen) {
    if ($onlineFilter === 'online') {
        $where[] = 'last_seen >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)';
    } elseif ($onlineFilter === 'recent') {
        $where[] = 'last_seen < DATE_SUB(NOW(), INTERVAL 10 MINUTE) AND last_seen >= DATE_SUB(NOW(), INTERVAL 60 MINUTE)';
    } elseif ($onlineFilter === 'offline') {
        $where[] = '(last_seen IS NULL OR last_seen < DATE_SUB(NOW(), INTERVAL 60 MINUTE))';
    }
}
if ($jobGroupFilter !== '' && $hasJobGroup) {
    $where[] = 'job_group = ?';
    $params[] = $jobGroupFilter;
}
if ($workModeFilter !== '' && $hasWorkMode) {
    $where[] = $workExpr . ' = ?';
    $params[] = $workModeFilter;
}
if ($departmentFilter !== '') {
    if (str_starts_with($departmentFilter, 't:')) {
        $where[] = 'team_id = ?';
        $params[] = (int) substr($departmentFilter, 2);
    } elseif ($departmentFilter === 'none') {
        $where[] = 'team_id IS NULL';
    } elseif ($hasDepartment) {
        $where[] = 'department = ?';
        $params[] = $departmentFilter;
    }
}


$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$countStmt = $pdo->prepare('SELECT COUNT(*) FROM users' . $whereSql);
$countStmt->execute($params);
$totalUsers = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalUsers / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$listSql = "SELECT * FROM users{$whereSql} ORDER BY (role='admin') DESC, full_name ASC LIMIT {$perPage} OFFSET {$offset}";
$listStmt = $pdo->prepare($listSql);
$listStmt->execute($params);
$active = $listStmt->fetchAll();

$paginationQuery = [
    'q' => $search,
    'role' => $roleFilter,
    'status' => $statusFilter,
    'online' => $onlineFilter,
    'job_group' => $jobGroupFilter,
    'work_mode' => $workModeFilter,
    'department' => $departmentFilter,
];
$paginationQuery = array_filter($paginationQuery, static fn($v) => $v !== '');

$pageTitle = 'مدیریت کاربران';
require_once __DIR__ . '/../includes/layout_top.php';
?>

<style>
.au-page{--au-line:#e7e2d3;--au-ink:#1c1917;--au-muted:#78716c;--au-gold:#c9a24b;--au-gold-2:#f1dfa8;}
.au-page .admin-page-header{display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;background:linear-gradient(120deg,#0b0f1a 0%,#241d0a 55%,#3d3220 130%);border-radius:20px;padding:1.1rem 1.4rem;margin-bottom:1.2rem;position:relative;overflow:hidden;box-shadow:0 10px 26px -16px rgba(28,25,23,.5);}
.au-page .admin-page-header::before{content:'';position:absolute;inset:0;pointer-events:none;background:radial-gradient(circle at 95% -20%, rgba(201,162,75,.28), transparent 55%),radial-gradient(circle at 0% 130%, rgba(201,162,75,.14), transparent 45%);}
.au-page .admin-page-header h5{position:relative;z-index:1;margin:0;font-weight:800;color:#fff;font-size:1.15rem;display:flex;align-items:center;gap:.6rem;}
.au-page .admin-page-header h5 i{width:38px;height:38px;border-radius:11px;display:inline-flex;align-items:center;justify-content:center;background:linear-gradient(135deg,var(--au-gold-2),var(--au-gold));color:#241d0a;font-size:.95rem;}
.au-page .admin-page-header .btn-primary{position:relative;z-index:1;border:none;border-radius:12px;font-weight:700;padding:.55rem 1.1rem;background:linear-gradient(135deg,var(--au-gold-2),var(--au-gold));color:#241d0a;box-shadow:0 8px 16px -8px rgba(201,162,75,.7);}
.au-page .admin-page-header .btn-primary:hover{transform:translateY(-1px)}
.au-page .admin-page-header .btn-rose-gold{position:relative;z-index:1;border:none;border-radius:12px;font-weight:700;padding:.55rem 1.1rem;background:linear-gradient(135deg,#f3c1b5 0%,#d99a8c 48%,#b9786d 100%);color:#3b2420;box-shadow:0 8px 16px -8px rgba(185,120,109,.7);}
.au-page .admin-page-header .btn-rose-gold:hover{transform:translateY(-1px);background:linear-gradient(135deg,#f7cec4 0%,#e1a095 48%,#c27f73 100%);color:#3b2420;}
.au-page .admin-users-card{border:1px solid var(--au-line);border-radius:20px;box-shadow:0 4px 20px -16px rgba(28,25,23,.3);}
.au-page .admin-pending-card{border:1px solid #fde68a;background:linear-gradient(180deg,#fffbeb 0%,#fff 60%);}
.au-page .admin-pending-card h6{font-weight:800;color:#92400e;display:flex;align-items:center;gap:.5rem}
.au-page .admin-pending-card h6 i{color:#b45309}
.au-page .admin-card-heading{display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem;padding-bottom:.8rem;border-bottom:1px dashed var(--au-line);}
.au-page .admin-card-heading h6{font-weight:800;color:var(--au-ink);margin:0}
.au-page .admin-card-count{font-size:.76rem;font-weight:700;color:#241d0a;background:linear-gradient(135deg,var(--au-gold-2),var(--au-gold));padding:.3rem .75rem;border-radius:999px;}
.au-page .admin-users-filters{background:#faf9f5;border:1px dashed var(--au-line);border-radius:14px;padding:.9rem 1rem .2rem;}
.au-page .admin-users-filters .form-label{font-size:.74rem;font-weight:700;color:#57534e;}
.au-page .admin-users-filters .form-control,.au-page .admin-users-filters .form-select,.au-page .admin-users-filters .input-group-text{border:1px solid var(--au-line);border-radius:10px;background:#fff;}
.au-page .admin-users-filters .form-control:focus,.au-page .admin-users-filters .form-select:focus{border-color:var(--au-gold);box-shadow:0 0 0 4px rgba(201,162,75,.15);}
.au-page .admin-users-filters .input-group .form-control{border-top-right-radius:0;border-bottom-right-radius:0}
.au-page .admin-users-filters .input-group .input-group-text{border-top-left-radius:0;border-bottom-left-radius:0;color:var(--au-muted)}
.au-page .admin-users-filters .btn-primary{border:none;border-radius:10px;font-weight:700;background:linear-gradient(135deg,var(--au-gold-2),var(--au-gold));color:#241d0a;}
.au-page .admin-users-filters .btn-outline-secondary{border-radius:10px}
.au-page .admin-users-table{font-size:.85rem}
.au-page .admin-users-table thead th{background:#faf9f5;color:#78716c;font-weight:700;font-size:.74rem;border-bottom:1px solid var(--au-line);white-space:nowrap;}
.au-page .admin-users-table tbody td{border-bottom:1px solid #f3f1ea;vertical-align:middle}
.au-page .admin-users-table tbody tr:hover{background:#faf8f2}
.au-page .admin-user-name-cell{display:flex;align-items:center;gap:.5rem;font-weight:700;color:var(--au-ink)}
.au-page .sidebar-avatar-sm{box-shadow:0 4px 10px -6px rgba(28,25,23,.4)}
.au-page .admin-user-online-dot{width:9px;height:9px;border-radius:50%;display:inline-block;flex-shrink:0}
.au-page .online-dot-online{background:#22c55e;box-shadow:0 0 0 3px rgba(34,197,94,.2)}
.au-page .online-dot-recent{background:#eab308;box-shadow:0 0 0 3px rgba(234,179,8,.2)}
.au-page .online-dot-offline{background:#d6d3d1}
.au-page .admin-select-wrap .form-select{border:1px solid var(--au-line);border-radius:10px;font-size:.8rem;background:#fff;font-weight:600;}
.au-page .admin-select-wrap .form-select:focus{border-color:var(--au-gold);box-shadow:0 0 0 4px rgba(201,162,75,.15)}
.au-page .admin-select-wrap .form-select:disabled{background:#f5f4f0;color:#a8a29e}
.au-page .badge{border-radius:999px;font-weight:700;padding:.4rem .8rem;font-size:.74rem}
.au-page .badge.bg-primary{background:linear-gradient(135deg,#0b0f1a,var(--au-gold)) !important}
.au-page .admin-action-btn{width:34px;height:34px;border-radius:10px;display:inline-flex;align-items:center;justify-content:center;padding:0;border:1px solid var(--au-line);color:#57534e;background:#fff;transition:.15s ease;}
.au-page .admin-action-btn:hover{background:linear-gradient(135deg,var(--au-gold-2),var(--au-gold));color:#241d0a;border-color:transparent;transform:translateY(-2px);box-shadow:0 8px 16px -8px rgba(201,162,75,.6);}
.au-page .btn-outline-info.admin-action-btn:hover{background:linear-gradient(135deg,#7dd3fc,#0369a1);color:#fff}
.au-page .btn-outline-primary.admin-action-btn:hover{background:linear-gradient(135deg,var(--au-gold-2),var(--au-gold));color:#241d0a}
.au-page form.d-flex.gap-1{margin:0}
.au-page .admin-pending-card .btn-success{border-radius:10px;font-weight:700}
.au-page .admin-pending-card .btn-outline-danger{border-radius:10px;font-weight:700}
.au-page .admin-users-pagination .page-link{border:1px solid var(--au-line);color:var(--au-ink);border-radius:10px !important;margin:0 .15rem;font-weight:600;}
.au-page .admin-users-pagination .page-item.active .page-link{background:linear-gradient(135deg,var(--au-gold-2),var(--au-gold));border-color:transparent;color:#241d0a;}
.au-page .admin-users-pagination .page-item.disabled .page-link{color:#d6d3d1;background:#faf9f5}
</style>

<div class="au-page">

<div class="admin-page-header d-flex justify-content-between align-items-center mb-3">
  <h5 class="mb-0"><i class="fa-solid fa-users-gear"></i> مدیریت کاربران</h5>
  <div class="d-flex gap-2">
    <a href="admin_users_attributes_import.php" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-file-excel"></i> بارگذاری اکسل پرسنل</a>
    <a href="admin_customers.php" class="btn btn-sm btn-rose-gold"><i class="fa-solid fa-address-book"></i> مدیریت مشتریان</a>
    <a href="admin_user_create.php" class="btn btn-sm btn-primary"><i class="fa-solid fa-user-plus"></i> کاربر جدید</a>
  </div>
</div>

<?php if ($pending): ?>
<div class="card admin-users-card admin-pending-card p-3 mb-3">
  <h6 class="mb-3"><i class="fa-solid fa-user-clock"></i> منتظر تایید (<?= count($pending) ?>)</h6>
  <div class="table-responsive">
    <table class="table admin-users-table align-middle mb-0">
      <thead><tr><th>نام</th><th>موبایل</th><th>واحد درخواستی</th><th>تاریخ ثبت‌نام</th><th>عملیات</th></tr></thead>
      <tbody>
      <?php foreach ($pending as $p): ?>
        <tr>
          <td><?= e($p['full_name']) ?></td>
          <td dir="ltr"><?= e($p['mobile']) ?></td>
          <td><span class="badge bg-secondary"><?= e(role_label($p['role'])) ?></span></td>
          <td><?= to_jalali(substr($p['created_at'],0,10)) ?></td>
          <td class="d-flex gap-1">
            <form method="post"><?= csrf_field() ?>
              <input type="hidden" name="user_id" value="<?= $p['id'] ?>">
              <input type="hidden" name="action" value="approve">
              <button class="btn btn-sm btn-success"><i class="fa-solid fa-check"></i> تایید</button>
            </form>
            <form method="post" onsubmit="return confirm('حذف این درخواست ثبت‌نام؟');"><?= csrf_field() ?>
              <input type="hidden" name="user_id" value="<?= $p['id'] ?>">
              <input type="hidden" name="action" value="reject">
              <button class="btn btn-sm btn-outline-danger"><i class="fa-solid fa-xmark"></i> رد</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<div class="card admin-users-card p-3">
  <div class="admin-card-heading">
    <h6 class="mb-0">همه کاربران</h6>
    <span class="admin-card-count"><?= number_format($totalUsers) ?> کاربر</span>
  </div>

  <form method="get" class="admin-users-filters mb-3">
    <div class="row g-2 align-items-end">
      <div class="col-12 col-md-5">
        <label for="userSearch" class="form-label small fw-bold mb-1">جستجو بر اساس نام</label>
        <div class="input-group input-group-sm">
          <span class="input-group-text"><i class="fa-solid fa-magnifying-glass"></i></span>
          <input id="userSearch" type="search" name="q" value="<?= e($search) ?>" class="form-control" placeholder="نام یا شماره موبایل (مثلاً ۰۹۱۲ یا 912)...">
        </div>
      </div>
      <div class="col-12 col-md-3">
        <label for="userRole" class="form-label small fw-bold mb-1">نقش</label>
        <select id="userRole" name="role" class="form-select form-select-sm">
          <option value="">همه نقش‌ها</option>
          <?php foreach ($allRolesForSelect as $r):
              $slug = (string)($r['role_key'] ?? '');
              if ($slug === '') continue;
          ?>
            <option value="<?= e($slug) ?>" <?= $roleFilter === $slug ? 'selected' : '' ?>><?= e($r['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-12 col-md-2">
        <label for="userStatus" class="form-label small fw-bold mb-1">وضعیت</label>
        <select id="userStatus" name="status" class="form-select form-select-sm">
          <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>همه وضعیت‌ها</option>
          <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>فعال</option>
          <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>تعلیق شده</option>
        </select>
      </div>
      <div class="col-12 col-md-2">
        <label for="userOnline" class="form-label small fw-bold mb-1">فعالیت</label>
        <select id="userOnline" name="online" class="form-select form-select-sm">
          <option value="">همه کاربران</option>
          <option value="online" <?= $onlineFilter === 'online' ? 'selected' : '' ?>>آنلاین</option>
          <option value="recent" <?= $onlineFilter === 'recent' ? 'selected' : '' ?>>فعال اخیر</option>
          <option value="offline" <?= $onlineFilter === 'offline' ? 'selected' : '' ?>>آفلاین</option>
        </select>
      </div>
      <?php if ($hasJobGroup): ?>
      <div class="col-12 col-md-2">
        <label for="userJobGroup" class="form-label small fw-bold mb-1">گروه شغلی</label>
        <select id="userJobGroup" name="job_group" class="form-select form-select-sm">
          <option value="">همه</option>
          <option value="توسعه" <?= $jobGroupFilter === 'توسعه' ? 'selected' : '' ?>>توسعه</option>
          <option value="عملیات" <?= $jobGroupFilter === 'عملیات' ? 'selected' : '' ?>>عملیات</option>
          <option value="ستادی" <?= $jobGroupFilter === 'ستادی' ? 'selected' : '' ?>>ستادی</option>
        </select>
      </div>
      <?php endif; ?>
      <?php if ($hasWorkMode): ?>
      <div class="col-12 col-md-2">
        <label for="userWorkMode" class="form-label small fw-bold mb-1">وضعیت حضور</label>
        <select id="userWorkMode" name="work_mode" class="form-select form-select-sm">
          <option value="">همه</option>
          <option value="onsite" <?= $workModeFilter === 'onsite' ? 'selected' : '' ?>>حضوری</option>
          <option value="remote" <?= $workModeFilter === 'remote' ? 'selected' : '' ?>>دورکار</option>
        </select>
      </div>
      <?php endif; ?>
      <?php if ($teamOptions || ($hasDepartment && $departmentOptions)): ?>
      <div class="col-12 col-md-2">
        <label for="userDepartment" class="form-label small fw-bold mb-1">تیم/دپارتمان</label>
        <select id="userDepartment" name="department" class="form-select form-select-sm">
          <option value="">همه</option>
          <?php if ($teamOptions): ?><optgroup label="تیم (سرپرست)">
            <?php foreach ($teamOptions as $__tid => $__tl): ?><option value="t:<?= (int) $__tid ?>" <?= $departmentFilter === 't:' . $__tid ? 'selected' : '' ?>><?= e($__tl) ?></option><?php endforeach; ?>
            <option value="none" <?= $departmentFilter === 'none' ? 'selected' : '' ?>>بدونِ تیم</option>
          </optgroup><?php endif; ?>
          <?php if ($hasDepartment && $departmentOptions): ?><optgroup label="دپارتمان">
          <?php foreach ($departmentOptions as $dep): ?>
            <option value="<?= e($dep) ?>" <?= $departmentFilter === $dep ? 'selected' : '' ?>><?= e($dep) ?></option>
          <?php endforeach; ?>
          </optgroup><?php endif; ?>
        </select>
      </div>
      <?php endif; ?>
      <div class="col-12 col-md-2 d-flex gap-2">
        <button type="submit" class="btn btn-sm btn-primary flex-grow-1"><i class="fa-solid fa-filter"></i> جستجو</button>
        <?php if ($search !== '' || $roleFilter !== '' || $statusFilter !== 'active' || $onlineFilter !== '' || $jobGroupFilter !== '' || $workModeFilter !== '' || $departmentFilter !== ''): ?>
          <a href="admin_users.php" class="btn btn-sm btn-outline-secondary" title="حذف فیلترها"><i class="fa-solid fa-xmark"></i></a>
        <?php endif; ?>
      </div>
    </div>
  </form>

  <div class="table-responsive">
    <table class="table admin-users-table align-middle mb-0">
      <thead><tr><th></th><th>نام</th><?php if ($hasAradCode): ?><th>آراد کد</th><?php endif; ?><th>موبایل</th><th>نقش</th><?php if ($hasJobGroup): ?><th>گروه شغلی</th><?php endif; ?><?php if ($hasWorkMode): ?><th>وضعیت حضور</th><?php endif; ?><?php if ($teamOptions || $hasDepartment): ?><th>تیم/دپارتمان</th><?php endif; ?><th>وضعیت حساب</th><th>عملیات</th></tr></thead>
      <tbody>
      <?php foreach ($active as $a): ?>
        <tr>
          <td><?= avatar_markup($a, $__base, 'sidebar-avatar sidebar-avatar-sm') ?></td>
          <td>
            <div class="admin-user-name-cell">
              <span class="online-dot admin-user-online-dot online-dot-offline" data-online-user-id="<?= (int)$a['id'] ?>" aria-hidden="true"></span>
              <span><?= e($a['full_name']) ?></span>
              <?php if (is_super_admin($user) && !empty($a['is_super_admin'])): ?>
                <span class="badge bg-primary">ادمین کل</span>
              <?php endif; ?>
            </div>
          </td>
          <?php if ($hasAradCode): ?><td dir="ltr"><?= e($a['arad_code'] ?? '—') ?></td><?php endif; ?>
          <td dir="ltr"><?= e($a['mobile']) ?></td>
          <td>
            <form method="post" class="d-flex gap-1">
              <?= csrf_field() ?>
              <input type="hidden" name="user_id" value="<?= $a['id'] ?>">
              <input type="hidden" name="action" value="change_role">
              <div class="admin-select-wrap admin-role-select">
                <?php $__canRole = $a['id'] != $user['id'] && actor_can_manage_user($user, $a); ?>
                <?php if (!$__canRole && !actor_can_assign_role($user, (string) $a['role'])): ?>
                  <span class="badge bg-dark"><?= e(role_label((string) $a['role'])) ?></span>
                <?php else: ?>
                <select name="new_role" class="form-select form-select-sm" onchange="this.form.submit()" <?= $__canRole ? '' : 'disabled' ?>>
                  <?php foreach ($assignableRoles as $r):
                      $slug = (string)($r['role_key'] ?? '');
                      if ($slug === '') continue;
                  ?>
                    <option value="<?= e($slug) ?>" <?= $a['role'] === $slug ? 'selected' : '' ?>><?= e($r['name']) ?></option>
                  <?php endforeach; ?>
                </select>
                <?php endif; ?>
              </div>
            </form>
          </td>
          <?php if ($hasJobGroup): ?>
          <td>
            <form method="post" class="d-flex gap-1">
              <?= csrf_field() ?>
              <input type="hidden" name="user_id" value="<?= $a['id'] ?>">
              <input type="hidden" name="action" value="change_job_group">
              <div class="admin-select-wrap"><select name="new_job_group" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="" <?= empty($a['job_group']) ? 'selected' : '' ?>>—</option>
                <option value="توسعه" <?= $a['job_group'] === 'توسعه' ? 'selected' : '' ?>>توسعه</option>
                <option value="عملیات" <?= $a['job_group'] === 'عملیات' ? 'selected' : '' ?>>عملیات</option>
                <option value="ستادی" <?= $a['job_group'] === 'ستادی' ? 'selected' : '' ?>>ستادی</option>
              </select></div>
            </form>
          </td>
          <?php endif; ?>
          <?php if ($hasWorkMode): ?>
          <td>
            <form method="post" class="d-flex gap-1">
              <?= csrf_field() ?>
              <input type="hidden" name="user_id" value="<?= $a['id'] ?>">
              <input type="hidden" name="action" value="change_work_mode">
              <div class="admin-select-wrap"><select name="new_work_mode" class="form-select form-select-sm" onchange="this.form.submit()">
                <?php $__wl = users_work_location_of($a); ?>
                <option value="" <?= $__wl === null ? 'selected' : '' ?>>—</option>
                <option value="onsite" <?= $__wl === 'onsite' ? 'selected' : '' ?>>حضوری</option>
                <option value="remote" <?= $__wl === 'remote' ? 'selected' : '' ?>>دورکار</option>
              </select></div>
            </form>
          </td>
          <?php endif; ?>
          <?php if ($teamOptions || $hasDepartment): ?>
          <td>
            <form method="post" class="d-flex gap-1">
              <?= csrf_field() ?>
              <input type="hidden" name="user_id" value="<?= $a['id'] ?>">
              <input type="hidden" name="action" value="change_team">
              <div class="admin-select-wrap"><select name="new_team_id" class="form-select form-select-sm" onchange="this.form.submit()" title="همان «سرپرست» در صفحه‌ی ویرایشِ کاربر">
                <option value="0" <?= empty($a['team_id']) ? 'selected' : '' ?>>—</option>
                <?php foreach ($teamOptions as $__tid => $__tl): ?>
                  <option value="<?= (int) $__tid ?>" <?= (int) ($a['team_id'] ?? 0) === $__tid ? 'selected' : '' ?>><?= e($__tl) ?></option>
                <?php endforeach; ?>
              </select></div>
            </form>
            <?php if ($hasDepartment && !empty($a['department'])): ?><div class="text-muted" style="font-size:11px">دپارتمان: <?= e((string) $a['department']) ?></div><?php endif; ?>
          </td>
          <?php endif; ?>
          <td><?= $a['is_active'] ? '<span class="badge bg-success">فعال</span>' : '<span class="badge bg-warning text-dark">تعلیق شده</span>' ?></td>
          <td>
            <div class="d-flex gap-1 justify-content-center">
              <a href="admin_user_edit.php?id=<?= $a['id'] ?>" class="btn btn-sm btn-outline-secondary admin-action-btn" title="ویرایش"><i class="fa-solid fa-pen"></i></a>
              <?php if (!is_impersonating() && can_impersonate_target($user, $a)): ?>
                <form method="post" action="admin_impersonate.php" class="d-inline" onsubmit="return confirm('ورود موقت به پنل این کاربر انجام شود؟');">
                  <?= csrf_field() ?>
                  <input type="hidden" name="user_id" value="<?= $a['id'] ?>">
                  <button type="submit" class="btn btn-sm btn-outline-info admin-action-btn" title="ورود به پنل"><i class="fa-solid fa-user-secret"></i></button>
                </form>
              <?php endif; ?>
              <a href="admin_staff_view.php?id=<?= $a['id'] ?>" class="btn btn-sm btn-outline-primary admin-action-btn" title="جزئیات"><i class="fa-solid fa-eye"></i></a>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <script>
(function () {
  var dots = Array.prototype.slice.call(document.querySelectorAll('[data-online-user-id]'));
  if (!dots.length) return;

  function apply(users) {
    users.forEach(function (u) {
      var dot = document.querySelector('[data-online-user-id="' + String(u.id) + '"]');
      if (!dot) return;
      dot.className = 'online-dot admin-user-online-dot online-dot-' + u.status;
    });
  }

  function refresh() {
    fetch('admin_online_users.php', {credentials: 'same-origin', cache: 'no-store'})
      .then(function (r) { return r.json(); })
      .then(function (d) { if (d.ok) apply(d.users); })
      .catch(function () {});
  }

  refresh();
  setInterval(refresh, 60000);
})();
</script>

  <?php if ($totalPages > 1): ?>
    <nav class="admin-users-pagination mt-3" aria-label="صفحه‌بندی کاربران">
      <ul class="pagination pagination-sm justify-content-center mb-0">
        <?php
          $prevQuery = $paginationQuery;
          $prevQuery['page'] = max(1, $page - 1);
          $nextQuery = $paginationQuery;
          $nextQuery['page'] = min($totalPages, $page + 1);
        ?>
        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
          <a class="page-link" href="?<?= e(http_build_query($prevQuery)) ?>" aria-label="قبلی">‹</a>
        </li>

        <?php
          $startPage = max(1, $page - 2);
          $endPage = min($totalPages, $page + 2);
          if ($startPage > 1):
        ?>
          <li class="page-item"><a class="page-link" href="?<?= e(http_build_query(array_merge($paginationQuery, ['page' => 1]))) ?>">1</a></li>
          <?php if ($startPage > 2): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>
        <?php endif; ?>

        <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
          <li class="page-item <?= $i === $page ? 'active' : '' ?>">
            <a class="page-link" href="?<?= e(http_build_query(array_merge($paginationQuery, ['page' => $i]))) ?>"><?= $i ?></a>
          </li>
        <?php endfor; ?>

        <?php if ($endPage < $totalPages): ?>
          <?php if ($endPage < $totalPages - 1): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>
          <li class="page-item"><a class="page-link" href="?<?= e(http_build_query(array_merge($paginationQuery, ['page' => $totalPages]))) ?>"><?= $totalPages ?></a></li>
        <?php endif; ?>

        <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
          <a class="page-link" href="?<?= e(http_build_query($nextQuery)) ?>" aria-label="بعدی">›</a>
        </li>
      </ul>
      <div class="text-center text-muted small mt-2">
        صفحه <?= number_format($page) ?> از <?= number_format($totalPages) ?> · نمایش <?= number_format(count($active)) ?> کاربر
      </div>
    </nav>
  <?php endif; ?>
</div>

</div>

<?php require_once __DIR__ . '/../includes/layout_bottom.php'; ?>