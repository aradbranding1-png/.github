<?php
require_once __DIR__ . '/includes/auth.php';
$user = require_login();
$isAdmin = $user['role'] === 'admin';
$canBulkRefer = true;
$pdo = db();

/* ---------- کنترل مجوزهای نمایشی ---------- */
if (!function_exists('cl_permission_allowed')) {
    function cl_permission_allowed(array $user, string $permission): bool {
        try {
            if (function_exists('is_super_admin') && is_super_admin($user)) {
                return true;
            }
            $pdo = db();
            $roleId = (int)($user['access_role_id'] ?? 0);
            if ($roleId > 0) {
                $stmt = $pdo->prepare("SELECT role_key, permissions_json FROM access_roles WHERE id=? LIMIT 1");
                $stmt->execute([$roleId]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    $roleKey = (string)($row['role_key'] ?? '');
                    if ($roleKey === 'super_admin') return true;
                    $permissions = json_decode((string)($row['permissions_json'] ?? ''), true);
                    return is_array($permissions) && !empty($permissions[$permission]);
                }
            }
            $legacyRole = (string)($user['role'] ?? '');
            if ($legacyRole !== '') {
                $stmt = $pdo->prepare("SELECT role_key, permissions_json FROM access_roles WHERE role_key=? LIMIT 1");
                $stmt->execute([$legacyRole]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    $roleKey = (string)($row['role_key'] ?? '');
                    if ($roleKey === 'super_admin') return true;
                    $permissions = json_decode((string)($row['permissions_json'] ?? ''), true);
                    return is_array($permissions) && !empty($permissions[$permission]);
                }
            }
            return false;
        } catch (Throwable $e) {
            return false;
        }
    }
}
$canViewPhoneHistory = cl_permission_allowed($user, 'phone_history_view');
$canViewCustomerProfile = cl_permission_allowed($user, 'customer_profile_view');

$isLeader = $user['role'] === 'leader';
$myLedTeam = $isLeader ? team_led_by($pdo, (int) $user['id']) : null;
$visibleOwnerIds = (!$isAdmin) ? visible_owner_ids_for($pdo, $user) : [];

$PER_PAGE = 25;

$q             = trim($_GET['q'] ?? '');
$statusFilt    = trim($_GET['status'] ?? '');
$dueFilter     = trim($_GET['filter'] ?? '');
$ownerFilt     = (int) ($_GET['owner'] ?? 0);
$followupRange = trim($_GET['followup_range'] ?? '');
$createdFromJ  = trim($_GET['created_from'] ?? '');
$createdToJ    = trim($_GET['created_to'] ?? '');
$contactTypeFilt = array_key_exists('contact_type', $_GET) ? trim((string) $_GET['contact_type']) : 'customer';
$page          = max(1, (int) ($_GET['page'] ?? 1));

$dismissedStatuses = ['انصرافی', 'نامرتبط'];
$hideDismissed = $q === '' && $statusFilt === '';

$where  = [];
$params = [];

$where[] = "c.status != 'شاکی'";

$__readAll = user_can('customer_view_all', $user);
if (!$isAdmin && !$__readAll) {
    if ($ownerFilt > 0 && in_array($ownerFilt, $visibleOwnerIds, true)) {
        $where[]  = 'c.owner_user_id = ?';
        $params[] = $ownerFilt;
    } else {
        $placeholders = implode(',', array_fill(0, count($visibleOwnerIds), '?'));
        $where[]  = "c.owner_user_id IN ($placeholders)";
        foreach ($visibleOwnerIds as $vid) {
            $params[] = $vid;
        }
    }
} elseif ($ownerFilt > 0) {
    $where[]  = 'c.owner_user_id = ?';
    $params[] = $ownerFilt;
}

if ($q !== '') {
    $qDigits = function_exists('normalize_digits') ? normalize_digits($q) : $q;
    $qMobile = preg_replace('/\D/', '', $qDigits);
    if ($qMobile !== '' && strlen($qMobile) === 11 && str_starts_with($qMobile, '0')) {
        $qMobile = substr($qMobile, 1);
    }
    $qMobileNeedle = $qMobile !== '' ? $qMobile : $qDigits;
    $where[]  = '(c.full_name LIKE ? OR c.mobile LIKE ? OR c.mobile_2 LIKE ?)';
    $params[] = "%$qDigits%";
    $params[] = "%$qMobileNeedle%";
    $params[] = "%$qMobileNeedle%";
}
if ($statusFilt !== '') {
    $where[]  = 'c.status = ?';
    $params[] = $statusFilt;
}
if ($hideDismissed) {
    $where[] = 'c.status NOT IN (' . implode(',', array_fill(0, count($dismissedStatuses), '?')) . ')';
    foreach ($dismissedStatuses as $ds) {
        $params[] = $ds;
    }
}
if ($dueFilter === 'today') {
    $where[] = 'c.next_followup_date = CURDATE()';
} elseif ($dueFilter === 'tomorrow') {
    $where[] = 'c.next_followup_date = CURDATE() + INTERVAL 1 DAY';
} elseif ($dueFilter === 'overdue') {
    $where[] = 'c.next_followup_date < CURDATE()';
} elseif ($dueFilter === 'due') {
    $where[] = 'c.next_followup_date <= CURDATE()';
}
if ($dueFilter !== '') {
    $where[] = "c.status NOT IN ('انصرافی','نامرتبط','خرید کرده')";
}
if ($contactTypeFilt !== '' && isset(contact_type_options()[$contactTypeFilt])) {
    $where[]  = 'c.contact_type = ?';
    $params[] = $contactTypeFilt;
}
if ($followupRange === 'lt5') {
    $where[] = 'c.followup_count < 5';
} elseif ($followupRange === '5to10') {
    $where[] = 'c.followup_count BETWEEN 5 AND 10';
} elseif ($followupRange === '10to20') {
    $where[] = 'c.followup_count BETWEEN 10 AND 20';
} elseif ($followupRange === 'gt20') {
    $where[] = 'c.followup_count > 20';
}
if ($createdFromJ !== '' && ($createdFromG = to_gregorian($createdFromJ))) {
    $where[]  = 'c.initial_contact_date >= ?';
    $params[] = $createdFromG;
}
if ($createdToJ !== '' && ($createdToG = to_gregorian($createdToJ))) {
    $where[]  = 'c.initial_contact_date <= ?';
    $params[] = $createdToG;
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// -----------------------------------------------------------------
// حذف مشتری
// -----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAdmin) {
    $backTo = 'customer_list.php' . ((($qs = $_SERVER['QUERY_STRING'] ?? '') !== '') ? '?' . $qs : '');
    if (!csrf_verify()) {
        flash_set('danger', 'نشست شما منقضی شده است، دوباره تلاش کنید.');
        redirect($backTo);
    }
    if (isset($_POST['bulk_delete_ids_submit'])) {
        $ids = array_values(array_filter(array_map('intval', (array) ($_POST['bulk_delete_ids'] ?? []))));
        if ($ids) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $pdo->prepare("DELETE FROM customers WHERE id IN ($placeholders)")->execute($ids);
            flash_set('success', to_persian_digits((string) count($ids)) . ' مشتری حذف شد.');
        }
        redirect($backTo);
    }
    if (isset($_POST['bulk_delete_all_matching'])) {
        $delStmt = $pdo->prepare("DELETE c FROM customers c JOIN users u ON u.id = c.owner_user_id $whereSql");
        $delStmt->execute($params);
        $deletedCount = $delStmt->rowCount();
        flash_set('success', to_persian_digits((string) $deletedCount) . ' مشتری مطابق فیلتر انتخاب‌شده حذف شد.');
        redirect('customer_list.php');
    }
}

// -----------------------------------------------------------------
// ارجاع دسته‌جمعی
// -----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_box_a_submit'])) {
    require_once __DIR__ . '/includes/customer_credit.php';
    require_once __DIR__ . '/includes/performance_functions.php';
    $backTo = 'customer_list.php' . ((($qs = $_SERVER['QUERY_STRING'] ?? '') !== '') ? '?' . $qs : '');
    if (!csrf_verify()) { flash_set('danger', 'نشست شما منقضی شده است، دوباره تلاش کنید.'); redirect($backTo); }
    if (!perf_can('box_manage', $user)) { flash_set('danger', 'اجازه‌ی مدیریتِ Boxها را ندارید.'); redirect($backTo); }
    $ids = array_values(array_filter(array_map('intval', (array) ($_POST['bulk_delete_ids'] ?? []))));
    if (!$ids) { flash_set('warning', 'مشتری‌ای انتخاب نشده.'); redirect($backTo); }
    $r = ps_box_add_many($pdo, $ids, (int) $user['id'], 'customer_list');
    flash_set($r['added'] ? 'success' : 'warning', ps_box_bulk_message($r));
    redirect($backTo);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_refer_submit'])) {
    $backTo = 'customer_list.php' . ((($qs = $_SERVER['QUERY_STRING'] ?? '') !== '') ? '?' . $qs : '');
    if (!csrf_verify()) {
        flash_set('danger', 'نشست شما منقضی شده است، دوباره تلاش کنید.');
        redirect($backTo);
    }
    $ids = array_values(array_filter(array_map('intval', (array) ($_POST['bulk_delete_ids'] ?? []))));
    $toUserId = (int) ($_POST['bulk_refer_to'] ?? 0);
    $validTargets = referral_target_staff($pdo, $isAdmin ? 0 : $user['id']);
    $validTargetIds = array_column($validTargets, 'id');

    if ($ids && in_array($toUserId, $validTargetIds, true)) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rowsStmt = $pdo->prepare("SELECT id, owner_user_id FROM customers WHERE id IN ($placeholders)");
        $rowsStmt->execute($ids);
        $rows = $rowsStmt->fetchAll();

        if (!$isAdmin) {
            $rows = array_filter($rows, fn($r) => in_array((int) $r['owner_user_id'], $visibleOwnerIds, true));
        }

        foreach ($rows as $row) {
            refer_customer($pdo, (int) $row['id'], (int) $row['owner_user_id'], $toUserId, (int) $user['id']);
        }
        $referredCount = count($rows);
        $skippedCount  = count($ids) - $referredCount;
        $msg = to_persian_digits((string) $referredCount) . ' مشتری ارجاع داده شد.';
        if ($skippedCount > 0) {
            $msg .= ' (' . to_persian_digits((string) $skippedCount) . ' مورد چون متعلق به شما نبود، ارجاع داده نشد.)';
        }
        flash_set('success', $msg);
    } else {
        flash_set('danger', 'کارشناس مقصد یا مشتری انتخاب‌شده معتبر نیست.');
    }
    redirect($backTo);
}

// -----------------------------------------------------------------
// تغییر وضعیت دسته‌جمعی
// -----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_status_submit'])) {
    $backTo = 'customer_list.php' . ((($qs = $_SERVER['QUERY_STRING'] ?? '') !== '') ? '?' . $qs : '');
    if (!csrf_verify()) {
        flash_set('danger', 'نشست شما منقضی شده است، دوباره تلاش کنید.');
        redirect($backTo);
    }
    $ids = array_values(array_filter(array_map('intval', (array) ($_POST['bulk_delete_ids'] ?? []))));
    $newStatus = trim((string) ($_POST['bulk_status'] ?? ''));
    $validStatuses = unified_status_options();

    if ($ids && $newStatus !== 'جلسه برگزار شد' && in_array($newStatus, $validStatuses, true)) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        // ⭐ contact_type به SELECT اضافه شد
        $rowsStmt = $pdo->prepare("SELECT id, owner_user_id, status, followup_count, status_locked, contact_type FROM customers WHERE id IN ($placeholders)");
        $rowsStmt->execute($ids);
        $rows = $rowsStmt->fetchAll();

        if (!$isAdmin) {
            $rows = array_filter($rows, fn($r) => in_array((int) $r['owner_user_id'], $visibleOwnerIds, true));
        }
        if ($user['role'] !== 'admin') {
            $rows = array_filter($rows, fn($r) => empty($r['status_locked']));
        }

        $updStatus = $pdo->prepare('UPDATE customers SET status = ? WHERE id = ?');
        $updStatusClearDate = $pdo->prepare('UPDATE customers SET status = ?, next_followup_date = NULL WHERE id = ?');
        $updCount  = $pdo->prepare('UPDATE customers SET followup_count = ? WHERE id = ?');
        // ⭐ INSERT با ستون‌های denormalized
        $insFollowup = $pdo->prepare('INSERT INTO followups
            (customer_id, followup_number, followup_date, description, status_after, next_followup_date, source, created_by, contact_type, is_phone_call)
            VALUES (?, ?, CURDATE(), ?, ?, NULL, \'manual\', ?, ?, ?)');

        foreach ($rows as $row) {
            $nextNumber = (int) $row['followup_count'] + 1;
            if (status_needs_no_followup($newStatus)) {
                $updStatusClearDate->execute([$newStatus, $row['id']]);
            } else {
                $updStatus->execute([$newStatus, $row['id']]);
            }
            $updCount->execute([$nextNumber, $row['id']]);

            // ⭐ محاسبه‌ی contact_type
            $__ct = (string) ($row['contact_type'] ?? 'customer');
            if (!in_array($__ct, ['customer', 'family', 'colleague'], true)) $__ct = 'customer';

            $insFollowup->execute([
                $row['id'],
                $nextNumber,
                'وضعیت به‌صورت دسته‌جمعی به «' . $newStatus . '» تغییر کرد.',
                $newStatus,
                (int) $user['id'],
                $__ct,
                0,
            ]);
            record_meeting_flag_if_needed($pdo, (int) $row['id'], $newStatus);
        }
        $changedCount = count($rows);
        $skippedCount = count($ids) - $changedCount;
        $msg = to_persian_digits((string) $changedCount) . ' مشتری به وضعیت «' . $newStatus . '» تغییر کرد.';
        if ($skippedCount > 0) {
            $msg .= ' (' . to_persian_digits((string) $skippedCount) . ' مورد چون متعلق به شما نبود، تغییر نکرد.)';
        }
        flash_set('success', $msg);
    } else {
        flash_set('danger', 'وضعیت انتخاب‌شده یا مشتری انتخاب‌شده معتبر نیست.');
    }
    redirect($backTo);
}

// -----------------------------------------------------------------
// تغییر سررسید پیگیری دسته‌جمعی
// -----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_due_date_submit'])) {
    $backTo = 'customer_list.php' . ((($qs = $_SERVER['QUERY_STRING'] ?? '') !== '') ? '?' . $qs : '');
    if (!csrf_verify()) {
        flash_set('danger', 'نشست شما منقضی شده است، دوباره تلاش کنید.');
        redirect($backTo);
    }
    $ids = array_values(array_filter(array_map('intval', (array) ($_POST['bulk_delete_ids'] ?? []))));
    $newDueDateG = to_gregorian(normalize_digits(trim((string) ($_POST['bulk_due_date'] ?? ''))));

    if ($ids && $newDueDateG) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rowsStmt = $pdo->prepare("SELECT id, owner_user_id, status FROM customers WHERE id IN ($placeholders)");
        $rowsStmt->execute($ids);
        $rows = $rowsStmt->fetchAll();

        $skippedNoOwn = 0;
        $skippedNoFollowup = 0;
        $eligible = [];
        foreach ($rows as $r) {
            if (!$isAdmin && !in_array((int) $r['owner_user_id'], $visibleOwnerIds, true)) {
                $skippedNoOwn++;
                continue;
            }
            if (status_needs_no_followup((string) $r['status'])) {
                $skippedNoFollowup++;
                continue;
            }
            $eligible[] = $r;
        }

        $updDate = $pdo->prepare('UPDATE customers SET next_followup_date = ? WHERE id = ?');
        $log = $pdo->prepare('INSERT INTO customer_activity_logs (customer_id, user_id, activity_type, description) VALUES (?,?,?,?)');
        foreach ($eligible as $row) {
            $updDate->execute([$newDueDateG, $row['id']]);
            $log->execute([$row['id'], $user['id'], 'followup', 'سررسید پیگیری به‌صورت دسته‌جمعی به ' . to_jalali($newDueDateG) . ' تغییر کرد.']);
        }

        $msg = to_persian_digits((string) count($eligible)) . ' مشتری سررسیدشون به ' . to_jalali($newDueDateG) . ' تغییر کرد.';
        if ($skippedNoOwn > 0) {
            $msg .= ' (' . to_persian_digits((string) $skippedNoOwn) . ' مورد چون متعلق به شما نبود، تغییر نکرد.)';
        }
        if ($skippedNoFollowup > 0) {
            $msg .= ' (' . to_persian_digits((string) $skippedNoFollowup) . ' مورد چون وضعیتشون نیاز به پیگیری نداره — انصرافی/نامرتبط — تغییر نکرد.)';
        }
        flash_set('success', $msg);
    } else {
        flash_set('danger', 'تاریخ واردشده معتبر نیست یا هیچ مشتری‌ای انتخاب نشده.');
    }
    redirect($backTo);
}

// =====================================================================
// کش سشن
// =====================================================================
$CL_CACHE_TTL = 120;

$clStatusPriority = ['در انتظار پرداخت', 'در انتظار تصمیم', 'جلسه برگزار شد', 'در حال پیگیری', 'تعویق', 'جدید', 'عدم پاسخ', 'مشتری قدیمی', 'خرید کرده'];
$clStatusOrder = 'CASE c.status';
foreach ($clStatusPriority as $__i => $__st) {
    $clStatusOrder .= ' WHEN ' . $pdo->quote($__st) . ' THEN ' . ($__i + 1);
}
$clStatusOrder .= ' ELSE 99 END';
$cacheKey = 'cl_cache_v2_' . md5($_SERVER['QUERY_STRING'] ?? '') . '_' . (int) $user['id'];

$cached = $_SESSION[$cacheKey] ?? null;
if ($cached && isset($cached['expires_at']) && $cached['expires_at'] > time()) {
    $totalCount   = $cached['totalCount'];
    $totalPages   = $cached['totalPages'];
    $page         = $cached['page'];
    $offset       = $cached['offset'];
    $customers    = $cached['customers'];
    $messengersByCustomer = $cached['messengersByCustomer'];
} else {
    $countSql = "SELECT COUNT(*) FROM customers c $whereSql";
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($params);
    $totalCount = (int) $countStmt->fetchColumn();
    $totalPages = max(1, (int) ceil($totalCount / $PER_PAGE));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $PER_PAGE;

    $sql = "SELECT c.*, u.full_name AS owner_name, u.role AS owner_role
            FROM customers c JOIN users u ON u.id = c.owner_user_id
            $whereSql
            ORDER BY $clStatusOrder, (c.next_followup_date IS NULL), c.next_followup_date ASC, c.created_at DESC
            LIMIT $PER_PAGE OFFSET $offset";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $customers = $stmt->fetchAll();

    $messengersByCustomer = [];
    if ($customers) {
        $ids = array_column($customers, 'id');
        $inPlaceholders = implode(',', array_fill(0, count($ids), '?'));
        $mStmt = $pdo->prepare("SELECT customer_id, mobile_slot, messenger FROM customer_messengers WHERE customer_id IN ($inPlaceholders)");
        $mStmt->execute($ids);
        foreach ($mStmt->fetchAll() as $row) {
            $messengersByCustomer[$row['customer_id']][$row['mobile_slot']][] = $row['messenger'];
        }
    }

    $_SESSION[$cacheKey] = [
        'totalCount'   => $totalCount,
        'totalPages'   => $totalPages,
        'page'         => $page,
        'offset'       => $offset,
        'customers'    => $customers,
        'messengersByCustomer' => $messengersByCustomer,
        'expires_at'   => time() + $CL_CACHE_TTL,
    ];
}

$fromParam = urlencode('customer_list.php' . (($qs = $_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $qs : ''));

if ($isAdmin) {
    $allStatuses = array_unique(array_merge(
        status_options_for_role('A'),
        status_options_for_role('B'),
        status_options_for_role('C')
    ));
} else {
    $allStatuses = status_options_for_role($user['role']);
}

$staffList = [];
if ($isAdmin) {
    $staffList = $pdo->query("SELECT id, full_name, role, mobile FROM users WHERE role != 'admin' ORDER BY full_name")->fetchAll();
} elseif ($isLeader && $myLedTeam) {
    $placeholders = implode(',', array_fill(0, count($visibleOwnerIds), '?'));
    $staffStmt = $pdo->prepare("SELECT id, full_name, role, mobile FROM users WHERE id IN ($placeholders) ORDER BY full_name");
    $staffStmt->execute($visibleOwnerIds);
    $staffList = $staffStmt->fetchAll();
}
$showOwnerFilter = $isAdmin || ($isLeader && $myLedTeam);

function __page_link(int $p): string
{
    $params = $_GET;
    $params['page'] = $p;
    return 'customer_list.php?' . http_build_query($params);
}

$pageTitle = 'پیگیری مشتریان';
require_once __DIR__ . '/includes/layout_top.php';
?>
<style>
.cl-page{--cl-line:#e7e2d3;--cl-ink:#1c1917;--cl-muted:#78716c;--cl-gold:#c9a24b;--cl-gold-2:#f1dfa8;}

.cl-page .cl-hero{
  position:relative;overflow:hidden;border-radius:20px;padding:1.6rem 1.8rem;margin-bottom:1.25rem;color:#fff;
  background:linear-gradient(125deg,#0b0f1a 0%,#241d0a 55%,#3d3220 130%);
  box-shadow:0 14px 32px -14px rgba(28,25,23,.45);
}
.cl-page .cl-hero::before{
  content:'';position:absolute;inset:0;pointer-events:none;
  background:radial-gradient(circle at 90% 0%, rgba(201,162,75,.22), transparent 55%),
             radial-gradient(circle at 5% 130%, rgba(201,162,75,.12), transparent 45%);
}
.cl-page .cl-hero::after{
  content:'';position:absolute;left:0;right:0;bottom:0;height:2px;
  background:linear-gradient(90deg,transparent, var(--cl-gold) 35%, var(--cl-gold-2) 50%, var(--cl-gold) 65%, transparent);
}
.cl-page .cl-hero-row{position:relative;z-index:1;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:1rem}
.cl-page .cl-hero-left{display:flex;align-items:center;gap:.9rem}
.cl-page .cl-hero-icon{
  width:48px;height:48px;border-radius:14px;display:inline-flex;align-items:center;justify-content:center;
  font-size:1.2rem;background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.16);flex-shrink:0;
}
.cl-page .cl-hero h5{margin:0;font-weight:800;font-size:1.25rem;color:#fff}
.cl-page .cl-hero .cl-hero-sub{font-size:.8rem;color:rgba(226,232,240,.72);margin-top:.25rem}
.cl-page .cl-hero-actions{display:flex;gap:.5rem;flex-wrap:wrap}
.cl-page .cl-btn-ghost{
  border:1px solid rgba(255,255,255,.22);background:rgba(255,255,255,.06);color:#fff;border-radius:12px;
  padding:.5rem 1rem;font-size:.82rem;font-weight:600;display:inline-flex;align-items:center;gap:.4rem;
  text-decoration:none;transition:.15s ease;
}
.cl-page .cl-btn-ghost:hover{background:rgba(255,255,255,.14);color:#fff}
.cl-page .cl-btn-gold{
  border:none;border-radius:12px;padding:.5rem 1.05rem;font-weight:700;font-size:.82rem;color:#241d0a;
  background:linear-gradient(135deg,var(--cl-gold-2),var(--cl-gold));box-shadow:0 8px 16px -8px rgba(201,162,75,.7);
  display:inline-flex;align-items:center;gap:.4rem;transition:.15s ease;text-decoration:none;
}
.cl-page .cl-btn-gold:hover{transform:translateY(-2px);box-shadow:0 10px 20px -8px rgba(201,162,75,.8);color:#241d0a}

.cl-page .card{border:1px solid var(--cl-line);border-radius:18px;box-shadow:0 1px 3px rgba(28,25,23,.05)}

.cl-page .cl-filter-card .form-label{font-size:.76rem;font-weight:700;color:#57534e;margin-bottom:.3rem;display:flex;align-items:center;gap:.3rem}
.cl-page .cl-filter-card .form-label::before{content:'';width:5px;height:5px;border-radius:50%;background:var(--cl-gold);display:inline-block}
.cl-page .cl-filter-card .form-control, .cl-page .cl-filter-card .form-select{
  border:1px solid var(--cl-line);border-radius:11px;padding:.5rem .75rem;font-size:.83rem;background:#fdfcf9;transition:.15s ease;
}
.cl-page .cl-filter-card .form-control:focus, .cl-page .cl-filter-card .form-select:focus{
  border-color:var(--cl-gold);background:#fff;box-shadow:0 0 0 4px rgba(201,162,75,.15);
}
.cl-page .cl-filter-card button.cl-btn-gold{width:100%;justify-content:center;padding:.55rem}

.cl-page .cl-list-title{font-weight:800;color:var(--cl-ink)}
.cl-page .cl-bulk-bar{
  background:#faf9f5;border:1px solid var(--cl-line);border-radius:14px;padding:.55rem .75rem;margin-bottom:1rem;
  flex-wrap:nowrap !important;overflow-x:auto;-webkit-overflow-scrolling:touch;
}
.cl-page .cl-bulk-bar::-webkit-scrollbar{height:6px}
.cl-page .cl-bulk-bar::-webkit-scrollbar-thumb{background:var(--cl-line);border-radius:99px}
.cl-page .cl-bulk-bar > *{flex-shrink:0}
.cl-page .cl-bulk-bar .btn{font-size:.74rem;padding:.32rem .65rem;border-radius:9px;white-space:nowrap}
.cl-page .cl-bulk-bar .form-control, .cl-page .cl-bulk-bar .form-select{
  font-size:.74rem;padding:.32rem .6rem;border-radius:9px;
}
.cl-page .cl-bulk-bar input#bulkReferSearch{width:150px !important}
.cl-page .cl-bulk-bar input#bulkDueDateInput{width:105px !important}
.cl-page .cl-bulk-bar select#bulkStatusSelect{max-width:150px}
.cl-page .cl-bulk-bar .text-muted.small, .cl-page .cl-bulk-bar #bulkSelectedCount{white-space:nowrap}

.cl-page table.cl-table{border-collapse:separate;border-spacing:0;width:100%}
.cl-page table.cl-table thead th{
  background:#faf9f5;color:#78716c;font-weight:700;font-size:.74rem;padding:.75rem .6rem;
  border-bottom:1px solid var(--cl-line);white-space:nowrap;
}
.cl-page table.cl-table tbody td{padding:.65rem .6rem;border-bottom:1px solid #f3f1ea;vertical-align:middle;font-size:.85rem}
.cl-page table.cl-table tbody tr:hover{background:#faf8f2}
.cl-page table.cl-table tbody tr:last-child td{border-bottom:none}
.cl-page table.cl-table tbody tr.table-row-today{background:#fffbeb}
.cl-page table.cl-table tbody tr.table-row-overdue{background:#fef2f2}
.cl-page .cl-person{display:flex;align-items:center;gap:.55rem}
.cl-page .cl-avatar{
  width:30px;height:30px;border-radius:50%;flex-shrink:0;display:inline-flex;align-items:center;justify-content:center;
  font-size:.72rem;font-weight:800;color:#fff;background:linear-gradient(135deg,#0b0f1a,var(--cl-gold));
  box-sizing:border-box !important;
}
.cl-page .cl-person-name{font-weight:700;color:var(--cl-ink)}
.cl-page .cl-person-owner{font-size:.72rem;color:var(--cl-muted)}
.cl-page .cl-due-rel{font-size:.7rem;font-weight:700;color:var(--cl-muted)}
.cl-page .cl-due-rel.is-late,.cl-page .is-late .cl-due-rel{color:#dc2626}
.cl-page .cl-due-rel.is-today,.cl-page .is-today .cl-due-rel{color:#b45309}
.cl-page .cl-due-rel.is-soon,.cl-page .is-soon .cl-due-rel{color:#0f766e}
.cl-page .cl-due-m{display:none}
/* ─── موبایل: هر مشتری یک کارت ─── */
@media (max-width: 767.98px){
  .cl-page .table-responsive{overflow:visible !important}
  .cl-page .table-responsive table.cl-table,.cl-page .table-responsive table.cl-table tbody{display:block;width:100% !important;table-layout:auto !important}
  .cl-page .table-responsive table.cl-table thead{display:none}
  .cl-page .table-responsive table.cl-table tbody tr{
    display:flex;flex-wrap:wrap;align-items:center;gap:.35rem .5rem;background:#fff;
    border:1px solid var(--cl-line);border-radius:14px;padding:.7rem .75rem;margin-bottom:.6rem;
    box-shadow:0 3px 12px -10px rgba(28,25,23,.35);position:relative;overflow:hidden;
  }
  .cl-page .table-responsive table.cl-table tbody tr::before{content:'';position:absolute;inset:0 0 0 auto;width:4px;background:#e7e2d3}
  .cl-page .table-responsive table.cl-table tbody tr.table-row-overdue{background:#fff;border-color:#fecaca}
  .cl-page .table-responsive table.cl-table tbody tr.table-row-overdue::before{background:#dc2626}
  .cl-page .table-responsive table.cl-table tbody tr.table-row-today{background:#fff;border-color:#fde68a}
  .cl-page .table-responsive table.cl-table tbody tr.table-row-today::before{background:#f59e0b}
  .cl-page .table-responsive table.cl-table tbody td{display:block;border:none !important;padding:0 !important;width:auto !important;font-size:.85rem}
  .cl-page .table-responsive table.cl-table tbody td:first-child:has(.row-select-checkbox){order:0;flex:0 0 auto}
  .cl-page .table-responsive table.cl-table tbody td:has(.cl-person){order:1;flex:1 1 0;min-width:0}
  .cl-page .table-responsive table.cl-table tbody td:has(.badge-status){order:2;flex:0 0 auto;max-width:42%;text-align:left}
  .cl-page .table-responsive table.cl-table tbody td.operations-col{order:3;flex:1 0 100%;padding-top:.45rem !important;border-top:1px dashed #efe9da !important;margin-top:.15rem}
  .cl-page .table-responsive .cl-avatar{display:inline-flex !important;width:34px;height:34px}
  .cl-page .table-responsive .cl-person{gap:.55rem !important}
  .cl-page .table-responsive .cl-person-name{font-size:.92rem !important;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .cl-page .table-responsive .badge-status{white-space:nowrap !important;font-size:.7rem !important;padding:.3rem .6rem !important}
  .cl-page .table-responsive .cl-due-m{display:flex;align-items:center;gap:.35rem;font-size:.74rem;color:var(--cl-muted);margin-top:.35rem;padding-right:2.6rem}
  .cl-page .table-responsive .cl-due-m b{color:var(--cl-ink);font-weight:700}
  .cl-page .table-responsive .operations-wrap{justify-content:flex-start;flex-wrap:wrap;gap:.4rem !important}
  .cl-page .table-responsive .operations-wrap::before{display:none !important}
  .cl-page .table-responsive .cl-op-btn{width:38px;height:38px;border-radius:11px;font-size:.9rem}
  .cl-page .table-responsive .cl-op-call{flex:1 1 auto;max-width:130px}
  .cl-page .table-responsive .cl-op-call::after{content:' تماس';font-family:inherit;font-weight:700;font-size:.8rem;margin-right:.35rem}
}
.cl-page .badge-status{border-radius:999px;padding:.32rem .75rem;font-weight:700;font-size:.74rem}
.cl-page .cl-count-chip{
  display:inline-flex;align-items:center;justify-content:center;min-width:26px;height:22px;padding:0 .4rem;
  border-radius:999px;background:#eefdfb;color:#0d9488;font-weight:700;font-size:.76rem;
}
.cl-page .cl-op-btn{
  width:32px;height:32px;border-radius:9px;display:inline-flex;align-items:center;justify-content:center;
  font-size:.82rem;text-decoration:none;border:none;transition:.15s ease;
}
.cl-page .cl-op-call{background:linear-gradient(135deg,#0f766e,#134e4a);color:#fff}
.cl-page .cl-op-chat{background:linear-gradient(135deg,#0369a1,#0c4a6e);color:#fff}
.cl-page .cl-op-view{background:linear-gradient(135deg,var(--cl-gold-2),var(--cl-gold));color:#241d0a}
.cl-page .cl-op-btn:hover{transform:translateY(-2px);filter:brightness(1.05)}
.cl-page .cl-op-360 .fa-stack{width:1.2em;height:1.2em;line-height:1.2em;font-size:1rem}
.cl-page .cl-op-360 .fa-stack-2x{font-size:1.2em}
.cl-page .cl-op-360 .fa-stack-1x{font-size:.32em}

.cl-page .pagination .page-link{border-radius:10px;margin:0 .12rem;border:1px solid var(--cl-line);color:#57534e}
.cl-page .pagination .page-item.active .page-link{
  background:linear-gradient(135deg,var(--cl-gold-2),var(--cl-gold));border-color:transparent;color:#241d0a;font-weight:700;
}
</style>

<div class="cl-page">

<div class="cl-hero">
  <div class="cl-hero-row">
    <div class="cl-hero-left">
      <span class="cl-hero-icon"><i class="fa-solid fa-users"></i></span>
      <div>
        <h5>پیگیری مشتریان</h5>
        <div class="cl-hero-sub"><?= to_persian_digits((string) $totalCount) ?> مورد در سیستم — فیلتر و جستجو در پایین</div>
      </div>
    </div>
    <div class="cl-hero-actions">
      <a href="customer_new.php" class="cl-btn-gold"><i class="fa-solid fa-user-plus"></i> ثبتِ مشتریِ جدید</a>
      <?php if ($user['role'] === 'C' || can_manage_service_requests($user)): ?>
      <a href="service_requests.php" class="cl-btn-ghost" style="position:relative">
        <i class="fa-solid fa-headset"></i> درخواست خدمات
        <span id="navSvcReqBadge" class="nav-chat-badge" style="display:none"></span>
      </a>
      <?php endif; ?>
      <?php if ($canViewPhoneHistory): ?>
      <a href="phone_history.php" class="cl-btn-ghost"><i class="fa-solid fa-magnifying-glass"></i> بررسیِ سابقه</a>
      <?php endif; ?>
      <a href="customer_referrals.php" class="cl-btn-ghost" style="position:relative">
        <i class="fa-solid fa-share-from-square"></i> ارجاع‌های من
        <span id="navReferralBadge" class="nav-chat-badge" style="display:none"></span>
      </a>
    </div>
  </div>
</div>

<div class="card p-3 p-md-4 mb-3 compact-text cl-filter-card">
  <form method="get" class="row g-2 align-items-end">
    <div class="col-md-3">
      <label class="form-label small">جستجو (نام / موبایل)</label>
      <input type="text" name="q" class="form-control" value="<?= e($q) ?>">
    </div>
    <div class="col-md-2">
      <label class="form-label small">وضعیت</label>
      <select name="status" class="form-select">
        <option value="">همه</option>
        <?php foreach ($allStatuses as $st): ?>
          <option value="<?= e($st) ?>" <?= $statusFilt === $st ? 'selected' : '' ?>><?= e($st) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2">
      <label class="form-label small">سررسید</label>
      <select name="filter" class="form-select">
        <option value="">همه</option>
        <option value="today" <?= $dueFilter === 'today' ? 'selected' : '' ?>>امروز</option>
        <option value="tomorrow" <?= $dueFilter === 'tomorrow' ? 'selected' : '' ?>>فردا</option>
        <option value="overdue" <?= $dueFilter === 'overdue' ? 'selected' : '' ?>>عقب‌افتاده</option>
        <option value="due" <?= $dueFilter === 'due' ? 'selected' : '' ?>>امروز و گذشته</option>
      </select>
    </div>
    <div class="col-md-2">
      <label class="form-label small">تعداد پیگیری</label>
      <select name="followup_range" class="form-select">
        <option value="">همه</option>
        <option value="lt5" <?= $followupRange === 'lt5' ? 'selected' : '' ?>>کمتر از ۵</option>
        <option value="5to10" <?= $followupRange === '5to10' ? 'selected' : '' ?>>بین ۵ تا ۱۰</option>
        <option value="10to20" <?= $followupRange === '10to20' ? 'selected' : '' ?>>بین ۱۰ تا ۲۰</option>
        <option value="gt20" <?= $followupRange === 'gt20' ? 'selected' : '' ?>>بیشتر از ۲۰</option>
      </select>
    </div>
    <div class="col-md-2">
      <label class="form-label small">نوع مخاطب</label>
      <select name="contact_type" class="form-select">
        <?php foreach (contact_type_options() as $val => $label): ?>
          <option value="<?= e($val) ?>" <?= $contactTypeFilt === $val ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
        <option value="" <?= $contactTypeFilt === '' ? 'selected' : '' ?>>همه انواع</option>
      </select>
    </div>
    <?php if ($showOwnerFilter): ?>
    <div class="col-md-2">
      <label class="form-label small">کارشناس</label>
      <?php
      $ownerFiltName = '';
      foreach ($staffList as $s) {
          if ((int) $s['id'] === $ownerFilt) {
              $ownerFiltName = person_pick_label((string) $s['full_name'], $s['mobile'] ?? null, role_letter($s['role']));
              break;
          }
      }
      ?>
      <input type="text" id="ownerFilterSearch" class="form-control" list="ownerFilterDatalist"
             placeholder="جستجوی نام کارشناس..." autocomplete="off" value="<?= e($ownerFiltName) ?>">
      <datalist id="ownerFilterDatalist">
        <?php foreach ($staffList as $s): ?>
          <option data-id="<?= (int) $s['id'] ?>" value="<?= e(person_pick_label((string) $s['full_name'], $s['mobile'] ?? null, role_letter($s['role']))) ?>"></option>
        <?php endforeach; ?>
      </datalist>
      <input type="hidden" name="owner" id="ownerFilterHidden" value="<?= $ownerFilt ?>">
    </div>
    <?php endif; ?>
    <?php if ($isLeader && !$myLedTeam): ?>
    <div class="col-md-2">
      <div class="alert alert-light border compact-text mb-0 py-2 small text-muted">شما هنوز سرپرستِ هیچ تیمی نیستید؛ فقط مشتریان خودتان نمایش داده می‌شود.</div>
    </div>
    <?php endif; ?>
    <div class="col-md-2">
      <label class="form-label small">ارتباط اولیه از تاریخ</label>
      <input type="text" name="created_from" class="form-control jalali-date" autocomplete="off" dir="ltr" placeholder="۱۴۰۵/۰۶/۱۷" value="<?= e($createdFromJ) ?>">
    </div>
    <div class="col-md-2">
      <label class="form-label small">تا تاریخ</label>
      <input type="text" name="created_to" class="form-control jalali-date" autocomplete="off" dir="ltr" placeholder="۱۴۰۵/۰۶/۱۷" value="<?= e($createdToJ) ?>">
    </div>
    <div class="col-md-1">
      <button class="cl-btn-gold"><i class="fa-solid fa-filter"></i></button>
    </div>
  </form>
</div>

<?php if ($showOwnerFilter): ?>
<script>
(function () {
  var searchEl = document.getElementById('ownerFilterSearch');
  var hiddenEl = document.getElementById('ownerFilterHidden');
  var datalistEl = document.getElementById('ownerFilterDatalist');
  if (!searchEl || !hiddenEl || !datalistEl) return;

  function sync() {
    var typed = searchEl.value;
    if (typed === '') {
      hiddenEl.value = '0';
      return;
    }
    var options = datalistEl.querySelectorAll('option');
    var matched = null;
    for (var i = 0; i < options.length; i++) {
      if (options[i].value === typed) {
        matched = options[i];
        break;
      }
    }
    hiddenEl.value = matched ? matched.getAttribute('data-id') : '0';
  }
  searchEl.addEventListener('input', sync);
  searchEl.addEventListener('change', sync);
})();
</script>
<?php endif; ?>

<div class="card p-3 p-md-4">
  <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h6 class="mb-0 cl-list-title"><i class="fa-solid fa-list text-warning-emphasis" style="color:var(--cl-gold)"></i> لیست مشتریان <span class="cl-count-chip"><?= to_persian_digits((string) $totalCount) ?></span><?= $totalPages > 1 ? '<span class="text-muted small"> — صفحه ' . to_persian_digits((string) $page) . ' از ' . to_persian_digits((string) $totalPages) . '</span>' : '' ?></h6>
    <div class="d-flex gap-2">
      <?php if ($isAdmin && $totalCount > 0): ?>
        <form method="post" action="?<?= e($_SERVER['QUERY_STRING'] ?? '') ?>" class="d-inline"
              onsubmit="return confirm('مطمئنید می‌خواهید همه <?= to_persian_digits((string) $totalCount) ?> مشتری مطابق فیلتر فعلی برای همیشه حذف شوند؟ این عمل قابل بازگشت نیست.');">
          <?= csrf_field() ?>
          <input type="hidden" name="bulk_delete_all_matching" value="1">
          <button type="submit" class="btn btn-sm btn-outline-danger"><i class="fa-solid fa-trash-can"></i> حذف همه <?= to_persian_digits((string) $totalCount) ?> نتیجه این فیلتر</button>
        </form>
      <?php endif; ?>
      <a href="customer_new.php" class="cl-btn-gold"><i class="fa-solid fa-plus"></i> مشتری جدید</a>
    </div>
  </div>

  <?php if (!$customers): ?>
    <div class="customer-tab-empty py-5" style="text-align:center;color:#a8a29e">
      <i class="fa-regular fa-folder-open" style="font-size:2rem;color:#d6d3d1;display:block;margin-bottom:.6rem"></i>
      موردی یافت نشد.
    </div>
  <?php else: ?>
  <form method="post" action="?<?= e($_SERVER['QUERY_STRING'] ?? '') ?>" id="bulkDeleteForm">
    <?= csrf_field() ?>
    <?php if ($isAdmin || $canBulkRefer): ?>
      <div class="d-flex align-items-center gap-2 mb-3 cl-bulk-bar">
        <?php if ($isAdmin): ?>
        <button type="submit" name="bulk_delete_ids_submit" class="btn btn-sm btn-outline-danger" id="bulkDeleteBtn" disabled
                onclick="return confirm('مشتری(های) انتخاب‌شده برای همیشه حذف می‌شوند. ادامه می‌دهید؟');">
          <i class="fa-solid fa-trash"></i> حذف انتخاب‌شده‌ها
        </button>
        <?php endif; ?>
        <?php if ($isAdmin && $canBulkRefer): ?><span class="text-muted small">|</span><?php endif; ?>
        <?php if ($canBulkRefer): ?>
        <?php
        $bulkReferGrouped = [];
        foreach (referral_target_staff($pdo, $isAdmin ? 0 : $user['id']) as $s) {
            $bulkReferGrouped[$s['role']][] = $s;
        }
        ?>
        <input type="text" id="bulkReferSearch" class="form-control form-control-sm" style="width:200px" list="bulkReferDatalist"
               placeholder="جستجوی نام کارشناس..." autocomplete="off">
        <datalist id="bulkReferDatalist">
          <?php foreach ($bulkReferGrouped as $roleKey => $staffInRole): ?>
            <?php foreach ($staffInRole as $s): ?>
              <option data-id="<?= (int) $s['id'] ?>" value="<?= e(person_pick_label((string) $s['full_name'], $s['mobile'] ?? null, 'واحد ' . role_letter($roleKey))) ?>"></option>
            <?php endforeach; ?>
          <?php endforeach; ?>
        </datalist>
        <input type="hidden" name="bulk_refer_to" id="bulkReferSelect" value="">
        <button type="submit" name="bulk_refer_submit" class="btn btn-sm btn-outline-primary" id="bulkReferBtn" disabled
                onclick="return confirm('مشتری(های) انتخاب‌شده به کارشناس انتخابی ارجاع داده می‌شوند. ادامه می‌دهید؟');">
          <i class="fa-solid fa-share-from-square"></i> ارجاع انتخاب‌شده‌ها
        </button>
        <?php endif; ?>
        <?php if (is_super_admin($user) || user_can('box_manage', $user)): ?>
        <button type="submit" name="bulk_box_a_submit" class="btn btn-sm btn-outline-warning" id="bulkBoxABtn" disabled
                onclick="return confirm('مشتری(های) انتخاب‌شده به Box A فرستاده شوند؟ فقط مشتریانی که هیچ A/B/C ندارند وارد می‌شوند.');">
          <i class="fa-solid fa-box"></i> ارسال به Box A
        </button>
        <?php endif; ?>
        <?php if ($canBulkRefer): ?>
        <span class="text-muted small">|</span>
        <select name="bulk_status" class="form-select form-select-sm" style="width:auto" id="bulkStatusSelect">
          <option value="">-- تغییر وضعیت به --</option>
          <?php foreach (unified_status_options() as $st): ?>
            <?php if ($st === 'جلسه برگزار شد') continue; ?>
            <option value="<?= e($st) ?>"><?= e($st) ?></option>
          <?php endforeach; ?>
        </select>
        <button type="submit" name="bulk_status_submit" class="btn btn-sm btn-outline-success" id="bulkStatusBtn" disabled
                onclick="return confirm('وضعیت مشتری(های) انتخاب‌شده تغییر می‌کند. ادامه می‌دهید؟');">
          <i class="fa-solid fa-list-check"></i> اعمال وضعیت
        </button>
        <span class="text-muted small">|</span>
        <input type="text" name="bulk_due_date" class="form-control form-control-sm jalali-date" dir="ltr" autocomplete="off" style="width:130px" id="bulkDueDateInput" placeholder="سررسید جدید">
        <button type="submit" name="bulk_due_date_submit" class="btn btn-sm btn-outline-info" id="bulkDueDateBtn" disabled
                onclick="return confirm('سررسید مشتری(های) انتخاب‌شده تغییر می‌کند. ادامه می‌دهید؟');">
          <i class="fa-solid fa-calendar-days"></i> اعمال سررسید
        </button>
        <?php endif; ?>
        <span class="text-muted small" id="bulkSelectedCount"></span>
      </div>
    <?php endif; ?>
  <div class="table-responsive">
    <table class="table table-hover table-compact align-middle mb-0 cl-table">
      <thead>
        <tr>
          <?php if ($isAdmin || $canBulkRefer): ?><th style="width:2rem"><input type="checkbox" id="selectAllRows" class="form-check-input"></th><?php endif; ?>
          <th>نام مشتری</th>
          <th>وضعیت</th>
          <th class="mobile-hide-col">ارتباط اولیه</th>
          <th class="mobile-hide-col">سررسید</th>
          <th class="operations-col">عملیات</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($customers as $c):
            $today = date('Y-m-d');
            $rowClass = '';
            if ($c['next_followup_date'] !== null) {
                if ($c['next_followup_date'] < $today) $rowClass = 'table-row-overdue';
                elseif ($c['next_followup_date'] === $today) $rowClass = 'table-row-today';
            }
            $dueLabel = '';
            $dueCls = '';
            if (!empty($c['next_followup_date'])) {
                $__days = (int) round((strtotime(substr((string) $c['next_followup_date'], 0, 10)) - strtotime($today)) / 86400);
                if ($__days === 0) { $dueLabel = 'امروز'; $dueCls = 'is-today'; }
                elseif ($__days < 0) { $dueLabel = to_persian_digits((string) abs($__days)) . ' روز گذشته'; $dueCls = 'is-late'; }
                elseif ($__days === 1) { $dueLabel = 'فردا'; $dueCls = 'is-soon'; }
                else { $dueLabel = to_persian_digits((string) $__days) . ' روز دیگر'; $dueCls = 'is-later'; }
            }
            $cInitial = mb_substr(trim((string) $c['full_name']), 0, 1);
            if ($cInitial === '') { $cInitial = '؟'; }
        ?>
        <tr class="<?= $rowClass ?>" title="<?= $rowClass === 'table-row-today' ? 'پیگیری امروز' : ($rowClass === 'table-row-overdue' ? 'پیگیری عقب‌افتاده' : '') ?>">
          <?php if ($isAdmin || $canBulkRefer): ?>
            <td><input type="checkbox" name="bulk_delete_ids[]" value="<?= (int) $c['id'] ?>" class="form-check-input row-select-checkbox"></td>
          <?php endif; ?>
          <td>
            <a href="customer_view.php?id=<?= (int)$c['id'] ?>&amp;from=<?= $fromParam ?>" class="cl-person text-decoration-none">
              <span class="cl-avatar"><?= e($cInitial) ?></span>
              <span>
                <span class="cl-person-name d-block"><?= e($c['full_name']) ?></span>
                <?php if ($isAdmin || $isLeader): ?><span class="cl-person-owner"><?= e($c['owner_name']) ?></span><?php endif; ?>
              </span>
            </a>
            <div class="cl-due-m <?= e($dueCls) ?>"><i class="fa-regular fa-calendar"></i>
              <?php if ($dueLabel !== ''): ?>سررسید: <b><?= to_jalali($c['next_followup_date']) ?></b> <span class="cl-due-rel"><?= e($dueLabel) ?></span>
              <?php else: ?>بدونِ سررسید<?php endif; ?>
            </div>
          </td>
          <td><span class="badge badge-status <?= status_badge_class($c['status']) ?>"><?= e($c['status']) ?></span></td>
          <td class="mobile-hide-col"><?= to_jalali($c['initial_contact_date']) ?></td>
          <td class="mobile-hide-col"><?= to_jalali($c['next_followup_date']) ?><?php if ($dueLabel !== ''): ?><div class="cl-due-rel <?= e($dueCls) ?>"><?= e($dueLabel) ?></div><?php endif; ?></td>
          <td class="operations-cell operations-col">
            <div class="operations-wrap d-flex gap-1">
            <a href="tel:<?= e(preg_replace('/\D/', '', $c['mobile'])) ?>" class="cl-op-btn cl-op-call" title="تماس"><i class="fa-solid fa-phone"></i></a>
            <?php
            $rowMessengers = $messengersByCustomer[$c['id']]['mobile'] ?? [];
            foreach ($rowMessengers as $m):
                $mLink = messenger_link($m, $c['mobile']);
                if (!$mLink) continue;
            ?>
              <a href="<?= e($mLink) ?>" target="_blank" class="cl-op-btn cl-op-chat" title="چت در <?= e(social_network_options()[$m] ?? $m) ?>">
                <i class="<?= e(social_network_icon_class($m)) ?>"></i>
              </a>
            <?php endforeach; ?>
            <a href="customer_view.php?id=<?= (int)$c['id'] ?>&amp;from=<?= $fromParam ?>" class="cl-op-btn cl-op-view" title="جزئیات و پیگیری"><i class="fa-solid fa-eye"></i></a>
            <?php if ($canViewCustomerProfile): ?>
            <a href="customer_profile.php?id=<?= (int)$c['id'] ?>" class="cl-op-btn cl-op-view cl-op-360" title="پروفایل ۳۶۰ مشتری">
              <span class="fa-stack">
                <i class="fa-solid fa-rotate fa-stack-2x"></i>
                <i class="fa-solid fa-user fa-stack-1x"></i>
              </span>
            </a>
            <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  </form>
  <?php if ($totalPages > 1): ?>
  <nav class="mt-3">
    <ul class="pagination pagination-sm justify-content-center mb-0">
      <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
        <a class="page-link" href="<?= e(__page_link(max(1, $page - 1))) ?>">قبلی</a>
      </li>
      <?php
      $rangeStart = max(1, $page - 2);
      $rangeEnd   = min($totalPages, $page + 2);
      if ($rangeStart > 1): ?>
        <li class="page-item"><a class="page-link" href="<?= e(__page_link(1)) ?>">۱</a></li>
        <?php if ($rangeStart > 2): ?><li class="page-item disabled"><span class="page-link">...</span></li><?php endif; ?>
      <?php endif; ?>
      <?php for ($p = $rangeStart; $p <= $rangeEnd; $p++): ?>
        <li class="page-item <?= $p === $page ? 'active' : '' ?>">
          <a class="page-link" href="<?= e(__page_link($p)) ?>"><?= to_persian_digits((string) $p) ?></a>
        </li>
      <?php endfor; ?>
      <?php if ($rangeEnd < $totalPages): ?>
        <?php if ($rangeEnd < $totalPages - 1): ?><li class="page-item disabled"><span class="page-link">...</span></li><?php endif; ?>
        <li class="page-item"><a class="page-link" href="<?= e(__page_link($totalPages)) ?>"><?= to_persian_digits((string) $totalPages) ?></a></li>
      <?php endif; ?>
      <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
        <a class="page-link" href="<?= e(__page_link(min($totalPages, $page + 1))) ?>">بعدی</a>
      </li>
    </ul>
  </nav>
  <?php endif; ?>
  <?php endif; ?>
</div>

<?php if ($isAdmin || $canBulkRefer): ?>
<script>
(function () {
  var selectAll = document.getElementById('selectAllRows');
  var checkboxes = document.querySelectorAll('.row-select-checkbox');
  var deleteBtn = document.getElementById('bulkDeleteBtn');
  var referBtn = document.getElementById('bulkReferBtn');
  var referSelect = document.getElementById('bulkReferSelect');
  var referSearch = document.getElementById('bulkReferSearch');
  var referDatalist = document.getElementById('bulkReferDatalist');
  var statusBtn = document.getElementById('bulkStatusBtn');
  var statusSelect = document.getElementById('bulkStatusSelect');
  var dueDateBtn = document.getElementById('bulkDueDateBtn');
  var dueDateInput = document.getElementById('bulkDueDateInput');
  var countLabel = document.getElementById('bulkSelectedCount');
  var faDigits = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
  function toFaDigits(n) {
    return String(n).replace(/[0-9]/g, function (d) { return faDigits[+d]; });
  }

  function updateState() {
    var checkedCount = document.querySelectorAll('.row-select-checkbox:checked').length;
    if (deleteBtn) deleteBtn.disabled = checkedCount === 0;
    var boxABtn = document.getElementById('bulkBoxABtn');
    if (boxABtn) boxABtn.disabled = checkedCount === 0;
    if (referBtn) referBtn.disabled = checkedCount === 0 || !referSelect || referSelect.value === '';
    if (statusBtn) statusBtn.disabled = checkedCount === 0 || !statusSelect || statusSelect.value === '';
    if (dueDateBtn) dueDateBtn.disabled = checkedCount === 0 || !dueDateInput || dueDateInput.value === '';
    if (countLabel) countLabel.textContent = checkedCount > 0 ? toFaDigits(checkedCount) + ' مورد انتخاب شده' : '';
  }

  if (referSearch && referDatalist && referSelect) {
    function syncReferSelection() {
      var typed = referSearch.value;
      var matched = null;
      var options = referDatalist.querySelectorAll('option');
      for (var i = 0; i < options.length; i++) {
        if (options[i].value === typed) {
          matched = options[i];
          break;
        }
      }
      referSelect.value = matched ? matched.getAttribute('data-id') : '';
      updateState();
    }
    referSearch.addEventListener('input', syncReferSelection);
    referSearch.addEventListener('change', syncReferSelection);
  }

  if (selectAll) {
    selectAll.addEventListener('change', function () {
      checkboxes.forEach(function (cb) { cb.checked = selectAll.checked; });
      updateState();
    });
  }
  checkboxes.forEach(function (cb) { cb.addEventListener('change', updateState); });
  if (referSelect) referSelect.addEventListener('change', updateState);
  if (statusSelect) statusSelect.addEventListener('change', updateState);
  if (dueDateInput) {
    dueDateInput.addEventListener('change', updateState);
    dueDateInput.addEventListener('input', updateState);
  }
  updateState();
})();
</script>
<?php endif; ?>

</div>

<?php require_once __DIR__ . '/includes/layout_bottom.php'; ?>