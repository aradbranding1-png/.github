<?php
// ─────────────────────────────────────────────────────────────────────
// دیباگ موقت: اگر خطای 500 داشتید، سه خط زیر را از کامنت خارج کنید.
// پس از حل مشکل، دوباره کامنت کنید.
// ini_set('display_errors', 1);
// ini_set('display_startup_errors', 1);
// error_reporting(E_ALL);
// ─────────────────────────────────────────────────────────────────────

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/reception_functions.php';
$admin = require_login();
if (!perm_page_allowed($admin)) {
    http_response_code(403);
    die('دسترسی به این بخش ندارید.');
}
$pdo   = db();

$moduleReady = reception_module_ready($pdo);

$preset           = (string) ($_GET['preset'] ?? 'this_month');
$agentFilter      = (int)    ($_GET['agent_id'] ?? 0);
$supervisorFilter = (int)    ($_GET['supervisor_id'] ?? 0);
$statusFilter     = trim((string) ($_GET['status'] ?? ''));

/* =====================================================================
   تبدیل مطمئن تاریخ شمسی به میلادی + نرمال‌سازی ارقام
   این توابع اگر جای دیگری از پروژه هم موجود باشند، دوباره تعریف نمی‌شوند.
   ===================================================================== */

if (!function_exists('rrp_normalize_digits')) {
    function rrp_normalize_digits(string $s): string {
        $fa = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
        $ar = ['٠','١','٢','٣','٤','٥','٦','٧','٨','٩'];
        $en = ['0','1','2','3','4','5','6','7','8','9'];
        return str_replace(array_merge($fa, $ar), array_merge($en, $en), $s);
    }
}

if (!function_exists('rrp_jalali_to_gregorian')) {
    /**
     * تبدیل تاریخ شمسی به میلادی با فرمت Y-m-d
     * ورودی می‌تواند ۱۴۰۵/۰۷/۰۲ یا 1405-07-02 باشد.
     * خروجی: '2026-09-24' یا null در صورت نامعتبر بودن.
     */
    function rrp_jalali_to_gregorian(string $jalali): ?string {
        $jalali = rrp_normalize_digits(trim($jalali));
        $jalali = str_replace(['-', '.'], '/', $jalali);

        if (!preg_match('#^(\d{4})/(\d{1,2})/(\d{1,2})$#', $jalali, $m)) {
            return null;
        }
        $jy = (int)$m[1];
        $jm = (int)$m[2];
        $jd = (int)$m[3];

        if ($jm < 1 || $jm > 12 || $jd < 1 || $jd > 31) return null;

        // الگوریتم تبدیل شمسی به میلادی (بدون نیاز به intl)
        $jy += 1595;
        $days = -355668 + (365 * $jy) + (((int)($jy / 33)) * 8) + ((int)((($jy % 33) + 3) / 4)) + $jd;
        $days += ($jm < 7) ? ($jm - 1) * 31 : (($jm - 7) * 30 + 186);

        $gy = 400 * ((int)($days / 146097));
        $days %= 146097;
        if ($days > 36524) {
            $gy += 100 * ((int)(--$days / 36524));
            $days %= 36524;
            if ($days >= 365) $days++;
        }
        $gy += 4 * ((int)($days / 1461));
        $days %= 1461;
        if ($days > 365) {
            $gy += (int)(($days - 1) / 365);
            $days = ($days - 1) % 365;
        }
        $gd = $days + 1;

        $isLeap = (($gy % 4 === 0) && ($gy % 100 !== 0)) || ($gy % 400 === 0);
        $sal_a = [0, 31, $isLeap ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
        $gm = 0;
        for ($gm = 0; $gm < 13 && $gd > $sal_a[$gm]; $gm++) {
            $gd -= $sal_a[$gm];
        }
        return sprintf('%04d-%02d-%02d', $gy, $gm, $gd);
    }
}

/* =====================================================================
   محاسبه بازه تاریخی (با try/catch برای جلوگیری از خطای 500)
   ===================================================================== */
try {
    $today = new DateTime('today');
    switch ($preset) {
        case 'today':
            $from = (clone $today);
            $to   = (clone $today);
            break;

        case 'yesterday':
            $from = (clone $today)->modify('-1 day');
            $to   = (clone $from);
            break;

        case 'this_week':
            $from = (clone $today)->modify('-' . ((int) $today->format('N') % 7) . ' days');
            $to   = (clone $today);
            break;

        case 'last_week':
            $from = (clone $today)->modify('-' . (((int) $today->format('N') % 7) + 7) . ' days');
            $to   = (clone $from)->modify('+6 days');
            break;

        case 'last_month':
            $from = (clone $today)->modify('first day of last month');
            $to   = (clone $today)->modify('last day of last month');
            break;

        case 'custom':
            $fromRaw = isset($_GET['from']) ? trim((string)$_GET['from']) : '';
            $toRaw   = isset($_GET['to'])   ? trim((string)$_GET['to'])   : '';

            $fromGreg = null;
            $toGreg   = null;
            if ($fromRaw !== '') {
                if (function_exists('to_gregorian')) {
                    $fromGreg = to_gregorian(rrp_normalize_digits($fromRaw));
                } elseif (function_exists('jalali_to_gregorian')) {
                    $fromGreg = jalali_to_gregorian(rrp_normalize_digits($fromRaw));
                } else {
                    $fromGreg = rrp_jalali_to_gregorian($fromRaw);
                }
            }
            if ($toRaw !== '') {
                if (function_exists('to_gregorian')) {
                    $toGreg = to_gregorian(rrp_normalize_digits($toRaw));
                } elseif (function_exists('jalali_to_gregorian')) {
                    $toGreg = jalali_to_gregorian(rrp_normalize_digits($toRaw));
                } else {
                    $toGreg = rrp_jalali_to_gregorian($toRaw);
                }
            }

            $from = $fromGreg ? DateTime::createFromFormat('Y-m-d', $fromGreg) : null;
            $to   = $toGreg   ? DateTime::createFromFormat('Y-m-d', $toGreg)   : null;

            if (!$from) $from = (clone $today)->modify('first day of this month');
            if (!$to)   $to   = (clone $today);

            if ($from > $to) {
                [$from, $to] = [$to, $from];
            }
            $preset = 'custom';
            break;

        case 'this_month':
        default:
            $from = (clone $today)->modify('first day of this month');
            $to   = (clone $today);
            $preset = 'this_month';
            break;
    }
} catch (Throwable $e) {
    error_log('Reception reports date error: ' . $e->getMessage());
    $today = new DateTime('today');
    $from = (clone $today)->modify('first day of this month');
    $to   = (clone $today);
    $preset = 'this_month';
}

$fromStr = $from->format('Y-m-d') . ' 00:00:00';
$toStr   = $to->format('Y-m-d') . ' 23:59:59';

$kpis = [
    'total_in' => 0, 'total_assigned' => 0, 'total_calls' => 0, 'success_calls' => 0,
    'no_answer' => 0, 'cancelled' => 0, 'followup' => 0, 'referred' => 0,
    'accepted' => 0, 'rejected' => 0, 'pending_supervisor' => 0, 'remaining_queue' => 0,
    'employment_requests' => 0, 'reviewed_leads' => 0, 'remote_meetings' => 0, 'active_reception_staff' => 0,
    'inperson_meetings' => 0, 'inperson_done' => 0, 'inperson_no_show' => 0, 'inperson_scheduled' => 0,
];
$inpersonRows = [];
$dailyInperson = [];
$dailyOnline = [];
$agentRows = [];
$agents = [];
$supervisors = [];
$dailyInflow = [];
$dailyCalls = [];
$callOutcomes = [];
$monthlyPerf = [];
$meetingBookings = [];
$meetingBookingsTotal = 0;
$meetingBookingsPerPage = 10;
$meetingBookingsPage = max(1, (int) ($_GET['meeting_page'] ?? 1));
$slotsReady = reception_meeting_slots_ready($pdo);

if ($slotsReady) {
    try {
        $bwhere = 'bk.created_at BETWEEN ? AND ?';
        $bparams = [$fromStr, $toStr];
        if ($supervisorFilter > 0) { $bwhere .= ' AND s.supervisor_user_id = ?'; $bparams[] = $supervisorFilter; }
        if ($agentFilter > 0) { $bwhere .= ' AND bk.agent_user_id = ?'; $bparams[] = $agentFilter; }

        // ابتدا تعداد کل رزروهای منطبق با فیلترها را می‌گیریم تا صفحه‌بندی دقیق باشد.
        $countStmt = $pdo->prepare("SELECT COUNT(*)
            FROM reception_meeting_bookings bk
            JOIN reception_meeting_slots s ON s.id = bk.slot_id
            WHERE $bwhere AND bk.status = 'booked'");
        $countStmt->execute($bparams);
        $meetingBookingsTotal = (int) $countStmt->fetchColumn();
        $kpis['remote_meetings'] = $meetingBookingsTotal;

        $meetingPages = max(1, (int) ceil($meetingBookingsTotal / $meetingBookingsPerPage));
        if ($meetingBookingsPage > $meetingPages) { $meetingBookingsPage = $meetingPages; }
        $meetingOffset = ($meetingBookingsPage - 1) * $meetingBookingsPerPage;

        $stmt = $pdo->prepare("SELECT bk.*, s.slot_date, s.start_time, su.full_name AS supervisor_name,
                ra.first_name, ra.last_name, ra.mobile, ag.full_name AS agent_name
            FROM reception_meeting_bookings bk
            JOIN reception_meeting_slots s ON s.id = bk.slot_id
            LEFT JOIN users su ON su.id = s.supervisor_user_id
            LEFT JOIN reception_applicants ra ON ra.id = bk.applicant_id
            LEFT JOIN users ag ON ag.id = bk.agent_user_id
            WHERE $bwhere AND bk.status = 'booked'
            ORDER BY s.slot_date ASC, s.start_time ASC
            LIMIT $meetingBookingsPerPage OFFSET $meetingOffset");
        $stmt->execute($bparams);
        $meetingBookings = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $meetingBookings = [];
        $kpis['remote_meetings'] = 0;
    }
}

// ─── جلسات: میتینگ آنلاین در برابر مصاحبه‌ی حضوری ───
if ($moduleReady) {
    $mt = reception_meeting_type_summary($pdo, $fromStr, $toStr, $agentFilter, $supervisorFilter);
    $kpis['remote_meetings']    = $mt['online'];
    $kpis['inperson_meetings']  = $mt['inperson'];
    $kpis['inperson_done']      = $mt['inperson_done'];
    $kpis['inperson_no_show']   = $mt['inperson_no_show'];
    $kpis['inperson_scheduled'] = $mt['inperson_scheduled'];
    if (reception_inperson_table_ready($pdo)) {
        try {
            $ip = [];
            $iw = reception_inperson_filters_sql(['agent_id' => $agentFilter, 'supervisor_id' => $supervisorFilter], $ip);
            $st = $pdo->prepare("SELECT ii.*, ra.first_name, ra.last_name, ra.mobile, ag.full_name AS agent_name, su.full_name AS supervisor_name
                FROM reception_inperson_interviews ii
                JOIN reception_applicants ra ON ra.id = ii.applicant_id
                LEFT JOIN users ag ON ag.id = ii.agent_user_id
                LEFT JOIN users su ON su.id = COALESCE(ii.supervisor_user_id, ra.supervisor_user_id)
                WHERE $iw AND ii.created_at BETWEEN ? AND ?
                ORDER BY ii.interview_date DESC, ii.id DESC LIMIT 10");
            $st->execute(array_merge($ip, [$fromStr, $toStr]));
            $inpersonRows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $sql = "SELECT DATE(ii.created_at) d, COUNT(*) c FROM reception_inperson_interviews ii WHERE ii.status <> 'cancelled' AND ii.created_at BETWEEN ? AND ?";
            $p = [$fromStr, $toStr];
            if ($agentFilter > 0) { $sql .= ' AND ii.agent_user_id = ?'; $p[] = $agentFilter; }
            $st = $pdo->prepare($sql . ' GROUP BY DATE(ii.created_at)');
            $st->execute($p);
            $dailyInperson = $st->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
        } catch (Throwable $e) {
        }
    }
    if ($slotsReady) {
        try {
            $sql = "SELECT DATE(bk.created_at) d, COUNT(*) c FROM reception_meeting_bookings bk WHERE bk.status = 'booked' AND bk.created_at BETWEEN ? AND ?";
            $p = [$fromStr, $toStr];
            if ($agentFilter > 0) { $sql .= ' AND bk.agent_user_id = ?'; $p[] = $agentFilter; }
            $st = $pdo->prepare($sql . ' GROUP BY DATE(bk.created_at)');
            $st->execute($p);
            $dailyOnline = $st->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
        } catch (Throwable $e) {
        }
    }
}

if ($moduleReady) {
    try {
        // منبع و تعریف تمام شاخص‌های عملیاتی با پنل کارشناس یکسان است.
        $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE service_access_role = 'reception_agent' AND is_active = 1");
        $kpis['active_reception_staff'] = (int) $stmt->fetchColumn();

        // درخواست استخدامی دریافتی = تعداد شماره‌های واردشده/آپلودشده در بازه.
        $whereApplicantCreated = 'ra.created_at BETWEEN ? AND ?';
        $paramsApplicantCreated = [$fromStr, $toStr];
        if ($statusFilter !== '') { $whereApplicantCreated .= ' AND ra.status = ?'; $paramsApplicantCreated[] = $statusFilter; }
        if ($agentFilter > 0) { $whereApplicantCreated .= ' AND ra.assigned_agent_id = ?'; $paramsApplicantCreated[] = $agentFilter; }
        if ($supervisorFilter > 0) { $whereApplicantCreated .= ' AND ra.supervisor_user_id = ?'; $paramsApplicantCreated[] = $supervisorFilter; }

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM reception_applicants ra WHERE $whereApplicantCreated");
        $stmt->execute($paramsApplicantCreated);
        $kpis['total_in'] = (int) $stmt->fetchColumn();
        $kpis['employment_requests'] = $kpis['total_in'];

        // واگذارشده: بر اساس زمان واقعی واگذاری.
        $whereAssigned = 'ra.assigned_at BETWEEN ? AND ? AND ra.assigned_agent_id IS NOT NULL';
        $paramsAssigned = [$fromStr, $toStr];
        if ($agentFilter > 0) { $whereAssigned .= ' AND ra.assigned_agent_id = ?'; $paramsAssigned[] = $agentFilter; }
        if ($supervisorFilter > 0) { $whereAssigned .= ' AND ra.supervisor_user_id = ?'; $paramsAssigned[] = $supervisorFilter; }
        if ($statusFilter !== '') { $whereAssigned .= ' AND ra.status = ?'; $paramsAssigned[] = $statusFilter; }
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM reception_applicants ra WHERE $whereAssigned");
        $stmt->execute($paramsAssigned);
        $kpis['total_assigned'] = (int) $stmt->fetchColumn();

        // باقی‌مانده صف = وضعیت لحظه‌ای، بدون وابستگی به بازه تاریخ.
        $kpis['remaining_queue'] = (int) $pdo->query('SELECT COUNT(*) FROM reception_applicants WHERE assigned_agent_id IS NULL')->fetchColumn();

        // ─── کلِ تماس‌ها و نتایج: همه از جدول reception_calls ───
        $whereReceptionCall = 'c.started_at BETWEEN ? AND ? AND EXISTS (SELECT 1 FROM users uc WHERE uc.id = c.agent_user_id AND uc.service_access_role = \'reception_agent\' AND uc.is_active = 1)';
        $paramsReceptionCall = [$fromStr, $toStr];
        if ($agentFilter > 0) { $whereReceptionCall .= ' AND c.agent_user_id = ?'; $paramsReceptionCall[] = $agentFilter; }

        // یک کوئری GROUP BY، به‌جای ۴ کوئری جدا.
        $stmt = $pdo->prepare("SELECT c.result, COUNT(*) AS cnt FROM reception_calls c WHERE $whereReceptionCall GROUP BY c.result");
        $stmt->execute($paramsReceptionCall);
        $resultCounts = $stmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];

        $kpis['success_calls'] = (int) ($resultCounts['success']   ?? 0);
        $kpis['no_answer']     = (int) ($resultCounts['no_answer'] ?? 0);
        $kpis['cancelled']     = (int) ($resultCounts['cancelled'] ?? 0);
        $kpis['followup']      = (int) ($resultCounts['followup']  ?? 0);

        $kpis['total_calls']    = array_sum($resultCounts);
        $kpis['reviewed_leads'] = $kpis['total_calls'];

        // ─── منبع جداگانه: لیدهای واردشده از طریق call_import (برای نمودار روزانه) ───
        $whereCallImport = "f.source = 'call_import' AND f.followup_date BETWEEN ? AND ? AND EXISTS (SELECT 1 FROM users uci WHERE uci.id = f.created_by AND uci.service_access_role = 'reception_agent' AND uci.is_active = 1)";
        $paramsCallImport = [$from->format('Y-m-d'), $to->format('Y-m-d')];
        if ($agentFilter > 0) { $whereCallImport .= ' AND f.created_by = ?'; $paramsCallImport[] = $agentFilter; }

        // تغییر وضعیت‌های بازه.
        $statusHistoryReady = reception_table_exists($pdo, 'reception_status_history');
        if ($statusHistoryReady) {
            $statusBase = 'h.created_at BETWEEN ? AND ? AND h.changed_by = ra.assigned_agent_id AND EXISTS (SELECT 1 FROM users uh WHERE uh.id = h.changed_by AND uh.service_access_role = \'reception_agent\' AND uh.is_active = 1)';
            $statusParamsBase = [$fromStr, $toStr];
            if ($agentFilter > 0) { $statusBase .= ' AND h.changed_by = ?'; $statusParamsBase[] = $agentFilter; }
            if ($supervisorFilter > 0) { $statusBase .= ' AND ra.supervisor_user_id = ?'; $statusParamsBase[] = $supervisorFilter; }
            foreach (['referred_to_supervisor' => 'referred', 'accepted' => 'accepted', 'rejected' => 'rejected'] as $code => $key) {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM reception_status_history h JOIN reception_applicants ra ON ra.id = h.applicant_id WHERE $statusBase AND h.new_status = ?");
                $stmt->execute(array_merge($statusParamsBase, [$code]));
                $kpis[$key] = (int) $stmt->fetchColumn();
            }
        }

        // در انتظار تعیین سرپرست.
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM reception_applicants ra WHERE $whereApplicantCreated AND ra.status = 'pending_supervisor'");
        $stmt->execute($paramsApplicantCreated);
        $kpis['pending_supervisor'] = (int) $stmt->fetchColumn();

        // عملکرد هر کارشناس فعال.
        $agentSql = "SELECT au.id, au.full_name,
                COUNT(DISTINCT CASE WHEN ra.assigned_at BETWEEN ? AND ? THEN ra.id END) AS received
            FROM users au
            LEFT JOIN reception_applicants ra ON ra.assigned_agent_id = au.id
            WHERE au.service_access_role = 'reception_agent' AND au.is_active = 1";
        $agentParams = [$fromStr, $toStr];
        if ($supervisorFilter > 0) { $agentSql .= ' AND ra.supervisor_user_id = ?'; $agentParams[] = $supervisorFilter; }
        if ($statusFilter !== '') { $agentSql .= ' AND ra.status = ?'; $agentParams[] = $statusFilter; }
        $agentSql .= ' GROUP BY au.id, au.full_name ORDER BY received DESC';
        $stmt = $pdo->prepare($agentSql);
        $stmt->execute($agentParams);
        $agentRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($agentRows as &$ar) {
            $aid = (int) $ar['id'];

            // کل تماس‌های واقعی کارشناس (هم‌منبع با جدول اصلی).
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM reception_calls c WHERE c.agent_user_id = ? AND c.started_at BETWEEN ? AND ?");
            $stmt->execute([$aid, $fromStr, $toStr]);
            $ar['total_calls'] = (int) $stmt->fetchColumn();

            $stmt = $pdo->prepare("SELECT COUNT(*) FROM reception_calls c WHERE c.agent_user_id = ? AND c.result = 'success' AND c.started_at BETWEEN ? AND ?");
            $stmt->execute([$aid, $fromStr, $toStr]);
            $ar['success_calls'] = (int) $stmt->fetchColumn();
            $ar['call_success_rate'] = $ar['total_calls'] > 0 ? round($ar['success_calls'] / $ar['total_calls'] * 100, 1) : 0;

            if ($statusHistoryReady) {
                foreach (['referred_to_supervisor' => 'referred', 'accepted' => 'accepted'] as $code => $key) {
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM reception_status_history h JOIN reception_applicants ra ON ra.id = h.applicant_id WHERE h.changed_by = ? AND h.new_status = ? AND h.created_at BETWEEN ? AND ? AND ra.assigned_agent_id = ?");
                    $stmt->execute([$aid, $code, $fromStr, $toStr, $aid]);
                    $ar[$key] = (int) $stmt->fetchColumn();
                }
            } else {
                $ar['referred'] = 0; $ar['accepted'] = 0;
            }
            $agentMt = reception_meeting_type_summary($pdo, $fromStr, $toStr, $aid, $supervisorFilter);
            $ar['online_meetings'] = $agentMt['online'];
            $ar['inperson_meetings'] = $agentMt['inperson'];
            $ar['inperson_done'] = $agentMt['inperson_done'];
            $ar['referral_rate'] = $ar['received'] > 0 ? round($ar['referred'] / $ar['received'] * 100, 1) : 0;
            $ar['acceptance_rate'] = $ar['received'] > 0 ? round($ar['accepted'] / $ar['received'] * 100, 1) : 0;
        }
        unset($ar);

        // فقط کارشناسانی که فعالیت واقعی داشتند.
        $agentRows = array_values(array_filter($agentRows, static function (array $ar): bool {
            return (int) ($ar['received'] ?? 0) > 0
                || (int) ($ar['total_calls'] ?? 0) > 0
                || (int) ($ar['success_calls'] ?? 0) > 0
                || (int) ($ar['referred'] ?? 0) > 0
                || (int) ($ar['accepted'] ?? 0) > 0
                || (int) ($ar['online_meetings'] ?? 0) > 0
                || (int) ($ar['inperson_meetings'] ?? 0) > 0;
        }));

        $agents = $pdo->query("SELECT id, full_name FROM users WHERE service_access_role = 'reception_agent' AND is_active = 1 ORDER BY full_name ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $supervisors = $pdo->query("SELECT id, full_name FROM users WHERE role = 'leader' ORDER BY full_name ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // نمودارها.
        $stmt = $pdo->prepare("SELECT DATE(created_at) d, COUNT(*) c FROM reception_applicants WHERE created_at BETWEEN ? AND ? GROUP BY DATE(created_at) ORDER BY d ASC");
        $stmt->execute([$fromStr, $toStr]);
        $dailyInflow = $stmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];

        // تماس‌های روزانه: از همان منبعِ شاخصِ «کلِ تماس‌ها» (تماس‌های ثبت‌شده در پرونده‌ی متقاضیان)
        $stmt = $pdo->prepare("SELECT DATE(c.started_at) d, COUNT(*) c FROM reception_calls c WHERE $whereReceptionCall GROUP BY DATE(c.started_at) ORDER BY d ASC");
        $stmt->execute($paramsReceptionCall);
        $dailyCalls = $stmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];

        $stmt = $pdo->prepare("SELECT COALESCE(c.result,'نامشخص') r, COUNT(*) c FROM reception_calls c WHERE $whereReceptionCall GROUP BY c.result");
        $stmt->execute($paramsReceptionCall);
        $callOutcomes = $stmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];

        $stmt = $pdo->query("SELECT DATE_FORMAT(created_at, '%Y-%m') m, COUNT(*) c FROM reception_applicants WHERE created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH) GROUP BY m ORDER BY m ASC");
        $monthlyPerf = $stmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
    } catch (Throwable $e) {
        error_log('Reception reports module error: ' . $e->getMessage());
        // مقادیر پیش‌فرض (صفر/خالی) باقی می‌مانند.
    }
}

$pageTitle = 'گزارش آماری پذیرش';
require_once __DIR__ . '/../includes/layout_top.php';
?>

<style>
.rrp-page{--rrp-line:#e7e2d3;--rrp-ink:#1c1917;--rrp-muted:#78716c;--rrp-gold:#c9a24b;--rrp-gold-2:#f1dfa8;}
.rrp-page .admin-page-header h5{display:flex;align-items:center;gap:.55rem;font-weight:800;color:var(--rrp-ink)}
.rrp-page .admin-page-header h5 i{
  width:34px;height:34px;border-radius:10px;display:inline-flex;align-items:center;justify-content:center;
  background:linear-gradient(135deg,#0b0f1a 0%,#3d3220 55%,var(--rrp-gold) 130%);color:#fff;font-size:.85rem;
}
.rrp-page .card{border:1px solid var(--rrp-line);border-radius:16px;box-shadow:0 4px 16px -14px rgba(28,25,23,.3)}
.rrp-page .btn-primary{background:linear-gradient(135deg,var(--rrp-gold-2),var(--rrp-gold));border:none;color:#241708;font-weight:700}
.rrp-page .btn-primary:hover{filter:brightness(.97);color:#241708}
.rrp-page .btn-outline-secondary{border-color:var(--rrp-line);color:var(--rrp-ink)}
.rrp-page .rrp-preset-btn{border:1px solid var(--rrp-line);border-radius:20px;padding:.3rem .9rem;font-size:.8rem;color:var(--rrp-ink);background:#fff;text-decoration:none;display:inline-block}
.rrp-page .rrp-preset-btn.active{background:linear-gradient(135deg,var(--rrp-gold-2),var(--rrp-gold));color:#241708;border-color:transparent;font-weight:700}
.rrp-page .glance-box{border:1px solid var(--rrp-line);border-radius:14px;background:#fff;box-shadow:0 4px 14px -12px rgba(28,25,23,.3)}
.rrp-page .glance-num{font-weight:800;font-size:1.5rem;background:linear-gradient(135deg,#8a6d2c,var(--rrp-gold));-webkit-background-clip:text;background-clip:text;color:transparent}
.rrp-page table thead th{background:linear-gradient(135deg,#0b0f1a 0%,#3d3220 55%,var(--rrp-gold) 130%);color:#f6efdd;border-color:transparent}
.rrp-page input.js-persian-date{cursor:pointer;background:#fff}
</style>

<div class="rrp-page">

<div class="admin-page-header d-flex justify-content-between align-items-center mb-3">
  <h5 class="mb-0"><i class="fa-solid fa-chart-column"></i> گزارش آماری پذیرش</h5>
  <a href="admin_reception_hub.php" class="btn btn-sm btn-outline-secondary">بازگشت به پذیرش کارشناس</a>
</div>

<?php if (!$moduleReady): ?>
  <div class="alert alert-warning py-2">جدول‌های ماژولِ پذیرش کارشناس هنوز روی سرور ایجاد نشده‌اند؛ ابتدا بروزرسانیِ سیستم را اجرا کنید.</div>
<?php else: ?>

<div class="card p-3 mb-3">
  <form method="get" class="row g-2 align-items-end" id="rrpFilterForm">
    <div class="col-12 mb-2 d-flex gap-2 flex-wrap">
      <?php
      $presets = ['today' => 'امروز', 'yesterday' => 'دیروز', 'this_week' => 'این هفته', 'last_week' => 'هفته گذشته', 'this_month' => 'این ماه', 'last_month' => 'ماه گذشته', 'custom' => 'بازه دلخواه'];
      foreach ($presets as $pk => $pl):
        $qs = $_GET; $qs['preset'] = $pk;
      ?>
        <a href="?<?= http_build_query($qs) ?>" class="rrp-preset-btn <?= $preset === $pk ? 'active' : '' ?>"><?= $pl ?></a>
      <?php endforeach; ?>
    </div>
    <?php if ($preset === 'custom'): ?>
    <div class="col-md-3">
      <label class="form-label small text-muted mb-1">از تاریخ</label>
      <input type="text"
             id="rrpFromDate"
             name="from"
             class="form-control form-control-sm js-persian-date"
             autocomplete="off"
             readonly
             value="<?= e((string) ($_GET['from'] ?? to_jalali($from->format('Y-m-d')))) ?>">
    </div>
    <div class="col-md-3">
      <label class="form-label small text-muted mb-1">تا تاریخ</label>
      <input type="text"
             id="rrpToDate"
             name="to"
             class="form-control form-control-sm js-persian-date"
             autocomplete="off"
             readonly
             value="<?= e((string) ($_GET['to'] ?? to_jalali($to->format('Y-m-d')))) ?>">
    </div>
    <input type="hidden" name="preset" value="custom">
    <?php endif; ?>
    <div class="col-md-2">
      <label class="form-label small text-muted mb-1">کارشناسِ پذیرش</label>
      <select name="agent_id" class="form-select form-select-sm">
        <option value="0">همه</option>
        <?php foreach ($agents as $ag): ?>
          <option value="<?= (int) $ag['id'] ?>" <?= $agentFilter === (int) $ag['id'] ? 'selected' : '' ?>><?= e($ag['full_name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2">
      <label class="form-label small text-muted mb-1">سرپرست</label>
      <select name="supervisor_id" class="form-select form-select-sm">
        <option value="0">همه</option>
        <?php foreach ($supervisors as $sv): ?>
          <option value="<?= (int) $sv['id'] ?>" <?= $supervisorFilter === (int) $sv['id'] ? 'selected' : '' ?>><?= e($sv['full_name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2">
      <label class="form-label small text-muted mb-1">وضعیت</label>
      <select name="status" class="form-select form-select-sm">
        <option value="">همه</option>
        <?php foreach (reception_load_statuses($pdo, false) as $st): ?>
          <option value="<?= e($st['code']) ?>" <?= $statusFilter === $st['code'] ? 'selected' : '' ?>><?= e($st['label']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-auto"><button type="submit" class="btn btn-primary btn-sm">اعمالِ فیلتر</button></div>
  </form>
</div>

<div class="row g-2 mb-4 text-center">
  <?php
  $glances = [
      'employment_requests' => 'تعداد درخواست استخدامی دریافت شده',
      'reviewed_leads' => 'تعداد لیدهای دریافت شده و بررسی شده',
      'remote_meetings' => 'دعوت به میتینگ آنلاین',
      'inperson_meetings' => 'دعوت به مصاحبه حضوری',
      'inperson_done' => 'حضور یافته (حضوری)',
      'inperson_no_show' => 'عدم حضور (حضوری)',
      'total_assigned' => 'کلِ واگذارشده', 'total_calls' => 'کلِ تماس‌ها',
      'success_calls' => 'تماسِ موفق', 'no_answer' => 'عدمِ پاسخ', 'cancelled' => 'انصرافی',
      'followup' => 'نیازمندِ پیگیری', 'referred' => 'ارجاع به سرپرست', 'accepted' => 'پذیرش‌شده',
      'rejected' => 'ردشده', 'pending_supervisor' => 'در انتظارِ تعیینِ سرپرست', 'remaining_queue' => 'باقی‌مانده در صف',
      'active_reception_staff' => 'کلِ کارکنانِ فعالِ بخشِ پذیرش',
  ];
  foreach ($glances as $k => $label):
  ?>
  <div class="col-6 col-md-2">
    <div class="glance-box p-3"><div class="glance-num"><?= to_persian_digits((string) $kpis[$k]) ?></div><div class="text-muted small"><?= $label ?></div></div>
  </div>
  <?php endforeach; ?>
</div>

<div class="card p-3 mb-4">
  <h6 class="fw-bold mb-3">عملکردِ هر کارشناسِ پذیرش</h6>
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
      <thead><tr><th>کارشناس</th><th>دریافت‌شده</th><th>کلِ تماس</th><th>تماسِ موفق</th><th>نرخِ تماسِ موفق</th><th>میتینگ آنلاین</th><th>مصاحبه حضوری</th><th>حضور یافته</th><th>ارجاع به سرپرست</th><th>نرخِ ارجاع</th><th>پذیرش نهایی</th><th>نرخِ پذیرش</th></tr></thead>
      <tbody>
        <?php if (!$agentRows): ?><tr><td colspan="12" class="text-center text-muted py-3">کارشناسِ پذیرشی ثبت نشده است.</td></tr><?php endif; ?>
        <?php foreach ($agentRows as $ar): ?>
        <tr>
          <td><?= e($ar['full_name']) ?></td>
          <td><?= to_persian_digits((string) $ar['received']) ?></td>
          <td><?= to_persian_digits((string) $ar['total_calls']) ?></td>
          <td><?= to_persian_digits((string) $ar['success_calls']) ?></td>
          <td><?= to_persian_digits((string) $ar['call_success_rate']) ?>٪</td>
          <td><span class="badge text-bg-info"><?= to_persian_digits((string) ($ar['online_meetings'] ?? 0)) ?></span></td>
          <td><span class="badge text-bg-warning"><?= to_persian_digits((string) ($ar['inperson_meetings'] ?? 0)) ?></span></td>
          <td><?= to_persian_digits((string) ($ar['inperson_done'] ?? 0)) ?></td>
          <td><?= to_persian_digits((string) $ar['referred']) ?></td>
          <td><?= to_persian_digits((string) $ar['referral_rate']) ?>٪</td>
          <td><?= to_persian_digits((string) $ar['accepted']) ?></td>
          <td><?= to_persian_digits((string) $ar['acceptance_rate']) ?>٪</td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="row g-3 mb-4">
  <div class="col-lg-4">
    <div class="card p-3 h-100">
      <h6 class="fw-bold mb-2">نوع جلسه: آنلاین / حضوری</h6>
      <canvas id="chartMeetingType" height="190"></canvas>
      <div class="d-flex justify-content-around small mt-2 text-center">
        <div><div class="fw-bold text-info"><?= to_persian_digits((string) $kpis['remote_meetings']) ?></div>آنلاین</div>
        <div><div class="fw-bold text-warning"><?= to_persian_digits((string) $kpis['inperson_meetings']) ?></div>حضوری</div>
        <div><div class="fw-bold text-success"><?= to_persian_digits((string) $kpis['inperson_done']) ?></div>حضور یافته</div>
        <div><div class="fw-bold text-danger"><?= to_persian_digits((string) $kpis['inperson_no_show']) ?></div>عدم حضور</div>
      </div>
    </div>
  </div>
  <div class="col-lg-8">
    <div class="card p-3 h-100">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <h6 class="fw-bold mb-0">آخرین مصاحبه‌های حضوریِ ثبت‌شده در این بازه</h6>
        <a href="../reception_inperson.php?preset=all" class="btn btn-sm btn-outline-secondary">همه‌ی مصاحبه‌های حضوری <i class="fa-solid fa-arrow-left"></i></a>
      </div>
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
          <thead><tr><th>تاریخ مصاحبه</th><th>متقاضی</th><th>موبایل</th><th>وضعیت</th><th>سرپرست</th><th>ثبت‌کننده</th></tr></thead>
          <tbody>
            <?php if (!$inpersonRows): ?><tr><td colspan="6" class="text-center text-muted py-3">در این بازه مصاحبه‌ی حضوری ثبت نشده است.</td></tr><?php endif; ?>
            <?php $__ivs = reception_inperson_statuses(); foreach ($inpersonRows as $ir): $__m = $__ivs[$ir['status']] ?? ['label' => $ir['status'], 'color' => 'secondary']; ?>
            <tr>
              <td><?= to_jalali($ir['interview_date']) ?><?= $ir['interview_time'] ? ' <span dir="ltr" class="text-muted">' . e(substr((string) $ir['interview_time'], 0, 5)) . '</span>' : '' ?></td>
              <td><a href="../reception_applicant.php?id=<?= (int) $ir['applicant_id'] ?>" class="text-decoration-none"><?= e(trim($ir['first_name'] . ' ' . $ir['last_name'])) ?></a></td>
              <td dir="ltr"><?= e($ir['mobile']) ?></td>
              <td><span class="badge text-bg-<?= e($__m['color']) ?>"><?= e($__m['label']) ?></span></td>
              <td><?= e($ir['supervisor_name'] ?? '—') ?></td>
              <td><?= e($ir['agent_name'] ?? '—') ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<?php if ($slotsReady): ?>
<div class="card p-3 mb-4">
  <h6 class="fw-bold mb-3"><i class="fa-solid fa-video text-info"></i> میتینگ‌های آنلاین با سرپرست (در بازه‌ی انتخاب‌شده)</h6>
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
      <thead><tr><th>سرپرست</th><th>تاریخ</th><th>ساعت</th><th>متقاضی</th><th>موبایل</th><th>رزروکننده (نیرویِ پذیرش)</th></tr></thead>
      <tbody>
        <?php if (!$meetingBookings): ?><tr><td colspan="6" class="text-center text-muted py-3">در این بازه رزروی ثبت نشده است.</td></tr><?php endif; ?>
        <?php foreach ($meetingBookings as $mb): ?>
        <tr>
          <td><?= e($mb['supervisor_name'] ?? '—') ?></td>
          <td><?= to_jalali($mb['slot_date']) ?></td>
          <td dir="ltr"><?= e($mb['start_time']) ?></td>
          <td><?= e(trim($mb['first_name'] . ' ' . $mb['last_name'])) ?></td>
          <td dir="ltr"><?= e($mb['mobile']) ?></td>
          <td><?= e($mb['agent_name'] ?? '—') ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php if ($meetingBookingsTotal > $meetingBookingsPerPage): ?>
    <?php
      $meetingPages = max(1, (int) ceil($meetingBookingsTotal / $meetingBookingsPerPage));
      $meetingQuery = $_GET;
      unset($meetingQuery['meeting_page']);
      $meetingQueryBase = http_build_query($meetingQuery);
      $meetingUrl = static function (int $page) use ($meetingQueryBase): string {
          return '?' . ($meetingQueryBase !== '' ? $meetingQueryBase . '&' : '') . 'meeting_page=' . $page;
      };
      $meetingStartPage = max(1, $meetingBookingsPage - 2);
      $meetingEndPage = min($meetingPages, $meetingBookingsPage + 2);
    ?>
    <div class="d-flex justify-content-center align-items-center gap-2 flex-wrap mt-3" dir="rtl">
      <?php if ($meetingBookingsPage > 1): ?>
        <a class="btn btn-sm btn-outline-secondary" href="<?= e($meetingUrl($meetingBookingsPage - 1)) ?>">قبلی</a>
      <?php endif; ?>
      <?php for ($p = $meetingStartPage; $p <= $meetingEndPage; $p++): ?>
        <a class="btn btn-sm <?= $p === $meetingBookingsPage ? 'btn-warning' : 'btn-outline-secondary' ?>" href="<?= e($meetingUrl($p)) ?>"><?= to_persian_digits((string) $p) ?></a>
      <?php endfor; ?>
      <?php if ($meetingBookingsPage < $meetingPages): ?>
        <a class="btn btn-sm btn-outline-secondary" href="<?= e($meetingUrl($meetingBookingsPage + 1)) ?>">بعدی</a>
      <?php endif; ?>
    </div>
    <div class="text-center text-muted small mt-2">
      صفحه <?= to_persian_digits((string) $meetingBookingsPage) ?> از <?= to_persian_digits((string) $meetingPages) ?> — مجموع <?= to_persian_digits((string) $meetingBookingsTotal) ?> رزرو
    </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-lg-6"><div class="card p-3"><h6 class="fw-bold mb-2">ورودیِ روزانه‌ی متقاضی</h6><canvas id="chartInflow" height="160"></canvas></div></div>
  <div class="col-lg-6"><div class="card p-3"><h6 class="fw-bold mb-2">تماس‌های روزانه</h6><canvas id="chartCalls" height="160"></canvas></div></div>
  <div class="col-lg-12"><div class="card p-3"><h6 class="fw-bold mb-2">جلسات روزانه: آنلاین در برابر حضوری</h6><canvas id="chartMeetDaily" height="110"></canvas></div></div>
  <div class="col-lg-4"><div class="card p-3"><h6 class="fw-bold mb-2">توزیعِ نتیجه‌ی تماس‌ها</h6><canvas id="chartOutcome" height="200"></canvas></div></div>
  <div class="col-lg-4"><div class="card p-3"><h6 class="fw-bold mb-2">مقایسه‌ی کارشناسان</h6><canvas id="chartAgents" height="200"></canvas></div></div>
  <div class="col-lg-4"><div class="card p-3"><h6 class="fw-bold mb-2">قیفِ پذیرش</h6><canvas id="chartFunnel" height="200"></canvas></div></div>
  <div class="col-lg-12"><div class="card p-3"><h6 class="fw-bold mb-2">عملکردِ ماهانه (۶ ماهِ اخیر)</h6><canvas id="chartMonthly" height="120"></canvas></div></div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script>
const goldColors = ['#c9a24b','#8a6d2c','#f1dfa8','#3d3220','#e7ddc4','#b98b2e'];

new Chart(document.getElementById('chartInflow'), {
  type: 'line',
  data: { labels: <?= json_encode(array_map('to_jalali', array_keys($dailyInflow)), JSON_UNESCAPED_UNICODE) ?>, datasets: [{ label: 'متقاضیِ جدید', data: <?= json_encode(array_values($dailyInflow)) ?>, borderColor: '#c9a24b', backgroundColor: 'rgba(201,162,75,.15)', fill: true, tension: .3 }] },
  options: { plugins: { legend: { display: false } } }
});

new Chart(document.getElementById('chartCalls'), {
  type: 'bar',
  data: { labels: <?= json_encode(array_map('to_jalali', array_map('strval', array_keys($dailyCalls))), JSON_UNESCAPED_UNICODE) ?>, datasets: [{ label: 'تماس', data: <?= json_encode(array_values($dailyCalls)) ?>, backgroundColor: '#8a6d2c' }] },
  options: { plugins: { legend: { display: false } } }
});

new Chart(document.getElementById('chartMeetingType'), {
  type: 'doughnut',
  data: { labels: ['میتینگ آنلاین', 'حضوری: در انتظار', 'حضوری: حضور یافته', 'حضوری: عدم حضور'],
    datasets: [{ data: [<?= (int) $kpis['remote_meetings'] ?>, <?= (int) $kpis['inperson_scheduled'] ?>, <?= (int) $kpis['inperson_done'] ?>, <?= (int) $kpis['inperson_no_show'] ?>],
      backgroundColor: ['#38bdf8', '#f1dfa8', '#22c55e', '#ef4444'] }] },
  options: { plugins: { legend: { position: 'bottom' } } }
});

<?php
$__meetDays = array_values(array_unique(array_merge(array_keys($dailyOnline), array_keys($dailyInperson))));
sort($__meetDays);
$__meetLabels = array_map(static fn($d) => to_jalali($d), $__meetDays);
?>
new Chart(document.getElementById('chartMeetDaily'), {
  type: 'bar',
  data: { labels: <?= json_encode($__meetLabels, JSON_UNESCAPED_UNICODE) ?>,
    datasets: [
      { label: 'آنلاین', data: <?= json_encode(array_map(static fn($d) => (int) ($dailyOnline[$d] ?? 0), $__meetDays)) ?>, backgroundColor: '#38bdf8' },
      { label: 'حضوری', data: <?= json_encode(array_map(static fn($d) => (int) ($dailyInperson[$d] ?? 0), $__meetDays)) ?>, backgroundColor: '#c9a24b' }
    ] },
  options: { scales: { x: { stacked: true }, y: { stacked: true, beginAtZero: true } } }
});

new Chart(document.getElementById('chartOutcome'), {
  type: 'doughnut',
  data: { labels: <?= json_encode(array_keys($callOutcomes), JSON_UNESCAPED_UNICODE) ?>, datasets: [{ data: <?= json_encode(array_values($callOutcomes)) ?>, backgroundColor: goldColors }] },
});

new Chart(document.getElementById('chartAgents'), {
  type: 'bar',
  data: {
    labels: <?= json_encode(array_column($agentRows, 'full_name'), JSON_UNESCAPED_UNICODE) ?>,
    datasets: [
      { label: 'دریافت‌شده', data: <?= json_encode(array_column($agentRows, 'received')) ?>, backgroundColor: '#c9a24b' },
      { label: 'پذیرش‌شده', data: <?= json_encode(array_column($agentRows, 'accepted')) ?>, backgroundColor: '#3d3220' },
    ]
  },
  options: { indexAxis: 'y' }
});

new Chart(document.getElementById('chartFunnel'), {
  type: 'bar',
  data: {
    labels: ['ورودی', 'واگذارشده', 'ارجاع به سرپرست', 'پذیرش نهایی'],
    datasets: [{ data: [<?= (int) $kpis['total_in'] ?>, <?= (int) $kpis['total_assigned'] ?>, <?= (int) $kpis['referred'] ?>, <?= (int) $kpis['accepted'] ?>], backgroundColor: goldColors }]
  },
  options: { plugins: { legend: { display: false } } }
});

new Chart(document.getElementById('chartMonthly'), {
  type: 'line',
  data: { labels: <?= json_encode(array_keys($monthlyPerf), JSON_UNESCAPED_UNICODE) ?>, datasets: [{ label: 'متقاضیِ ماهانه', data: <?= json_encode(array_values($monthlyPerf)) ?>, borderColor: '#c9a24b', backgroundColor: 'rgba(201,162,75,.15)', fill: true, tension: .3 }] },
});
</script>

<?php endif; ?>

</div>

<?php require_once __DIR__ . '/../includes/layout_bottom.php'; ?>

<!-- ═══════════════════════════════════════════════════════════════════
     datepicker — اگر jQuery/persian-date روی صفحه لود نشده باشند،
     این بلاک خودش از CDN لود می‌کند و datepicker را فعال می‌کند.
     ═══════════════════════════════════════════════════════════════════ -->
<?php if ($preset === 'custom'): ?>
<script>
(function () {
    function loadScript(src, cb) {
        var s = document.createElement('script');
        s.src = src;
        s.async = false;
        s.onload = cb;
        s.onerror = function () {
            console.error('خطا در بارگذاری:', src);
            if (cb) cb();
        };
        document.head.appendChild(s);
    }

    function initDatepicker() {
        if (!window.jQuery) return;
        if (!jQuery.fn.persianDatepicker) return;

        jQuery('#rrpFromDate').persianDatepicker({
            format: 'YYYY/MM/DD',
            initialValue: false,
            autoClose: true,
            persianDigit: true,
            observer: true,
            calendar: {
                persian: { locale: 'fa', leapYearMode: 'algorithmic' }
            }
        });

        jQuery('#rrpToDate').persianDatepicker({
            format: 'YYYY/MM/DD',
            initialValue: false,
            autoClose: true,
            persianDigit: true,
            observer: true,
            calendar: {
                persian: { locale: 'fa', leapYearMode: 'algorithmic' }
            }
        });
    }

    function bootstrap() {
        if (!window.jQuery) {
            loadScript('https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js', function () {
                if (window.jQuery && !jQuery.fn.persianDatepicker) {
                    loadScript('https://cdn.jsdelivr.net/npm/persian-date@1.1.0/dist/persian-date.min.js', function () {
                        loadScript('https://cdn.jsdelivr.net/npm/persian-datepicker@1.2.0/dist/js/persian-datepicker.min.js', initDatepicker);
                    });
                } else {
                    initDatepicker();
                }
            });
            return;
        }
        if (!jQuery.fn.persianDatepicker) {
            loadScript('https://cdn.jsdelivr.net/npm/persian-date@1.1.0/dist/persian-date.min.js', function () {
                loadScript('https://cdn.jsdelivr.net/npm/persian-datepicker@1.2.0/dist/js/persian-datepicker.min.js', initDatepicker);
            });
            return;
        }
        initDatepicker();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bootstrap);
    } else {
        bootstrap();
    }
})();
</script>
<?php endif; ?>