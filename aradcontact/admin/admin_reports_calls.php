<?php
require_once __DIR__ . '/../includes/auth.php';
$admin = require_login();
if (!perm_page_allowed($admin)) {
    http_response_code(403);
    die('دسترسی به این بخش ندارید.');
}
$pdo = db();
// نیروهای پذیرش: تفکیکِ «تماس با متقاضی» از «تماس با مشتری»
if (is_file(__DIR__ . '/../includes/reception_functions.php')) require_once __DIR__ . '/../includes/reception_functions.php';
$rxCalls = function_exists('rx_cc_ready') && rx_cc_ready($pdo);
// یک‌بار: تماس‌های مشتری‌هایی که هنگامِ آپلودِ کالیزر «مشتریِ جدید» ساخته شده بودند is_phone_call = 0 خورده بودند و در این گزارش نمی‌آمدند
try { followups_phone_call_backfill_v1($pdo); } catch (Throwable $e) {}

$referralTableAvailable = false;
try {
    $chkReferral = $pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_referrals'");
    $referralTableAvailable = (bool) $chkReferral->fetchColumn();
} catch (Throwable $e) {
    $referralTableAvailable = false;
}

// =====================================================================
// انتخاب بازه‌ی زمانی
// =====================================================================
$preset = $_GET['preset'] ?? 'today';
$todayG = date('Y-m-d');

switch ($preset) {
    case 'yesterday':
        $rangeFrom = $rangeTo = date('Y-m-d', strtotime('-1 day'));
        $rangeLabel = 'دیروز';
        break;
    case 'week':
        $rangeFrom = date('Y-m-d', strtotime('-' . (((int) date('N') + 1) % 7) . ' days')); // شنبه
        $rangeTo = $todayG;
        $rangeLabel = 'این هفته';
        break;
    case 'month':
        $rangeFrom = function_exists('rx_jalali_month') ? rx_jalali_month(0)[0] : date('Y-m-01'); // اولِ ماهِ شمسی
        $rangeTo = $todayG;
        $rangeLabel = 'این ماه';
        break;
    case 'custom':
        $rangeFromJalali = trim((string) ($_GET['from'] ?? ''));
        $rangeToJalali   = trim((string) ($_GET['to'] ?? ''));
        $rangeFrom = $rangeFromJalali !== '' ? to_gregorian(normalize_digits($rangeFromJalali)) : null;
        $rangeTo   = $rangeToJalali !== ''   ? to_gregorian(normalize_digits($rangeToJalali))   : null;
        if ($rangeFrom === null || $rangeTo === null) {
            $dateParseError = 'تاریخ واردشده («' . e($rangeFromJalali) . '» تا «' . e($rangeToJalali) . '») قابل تشخیص نبود، لطفاً از خودِ تقویم (با کلیک روی فیلد) انتخاب کنید.';
            $rangeFrom = $rangeTo = $todayG;
            $preset = 'today';
        }
        $rangeLabel = 'بازه‌ی دلخواه';
        break;
    case 'today':
    default:
        $rangeFrom = $rangeTo = $todayG;
        $preset = 'today';
        $rangeLabel = 'امروز';
        break;
}
if ($rangeFrom > $rangeTo) {
    [$rangeFrom, $rangeTo] = [$rangeTo, $rangeFrom];
}

// =====================================================================
// انتخاب نیرو (اختیاری)
// =====================================================================
$staffId = (int) ($_GET['staff_id'] ?? 0);
$allStaffForSearch = $pdo->query("SELECT id, full_name, role, mobile FROM users WHERE role IN ('A','B','C') AND is_active = 1 ORDER BY full_name")->fetchAll();
$supervisorJoinSql = "LEFT JOIN teams t ON t.id = u.team_id
                      LEFT JOIN users su ON su.id = t.leader_user_id";

$selectedStaffName = null;
$selectedStaffDisplay = '';
foreach ($allStaffForSearch as $s) {
    if ((int) $s['id'] === $staffId) {
        $selectedStaffName = $s['full_name'];
        $selectedStaffDisplay = person_pick_label((string) $s['full_name'], $s['mobile'] ?? null, 'واحد ' . $s['role']);
        break;
    }
}
if ($staffId > 0 && $selectedStaffName === null) {
    $staffId = 0;
}

// =====================================================================
// مدت مکالمه به تفکیک نوع مخاطب — کل سیستم (یا یک نیرو)
// =====================================================================
$byTypeSql = "SELECT contact_type,
        COUNT(*) AS call_count,
        COALESCE(SUM(call_duration_seconds), 0) AS total_duration
    FROM followups FORCE INDEX (idx_isphone_date_creator)
    WHERE is_phone_call = 1
      AND followup_date BETWEEN ? AND ?" . ($rxCalls ? ' AND NOT EXISTS (SELECT 1 FROM reception_callizer_calls rxl WHERE rxl.followup_id = followups.id)' : '');
$byTypeParams = [$rangeFrom, $rangeTo];
if ($staffId > 0) {
    $byTypeSql .= " AND created_by = ?";
    $byTypeParams[] = $staffId;
}
$byTypeSql .= " GROUP BY contact_type";
$stmt = $pdo->prepare($byTypeSql);
$stmt->execute($byTypeParams);
$systemCallByContactType = ['customer' => ['count' => 0, 'duration' => 0], 'colleague' => ['count' => 0, 'duration' => 0], 'family' => ['count' => 0, 'duration' => 0]];
foreach ($stmt->fetchAll() as $row) {
    $key = $row['contact_type'] ?? 'customer';
    if (isset($systemCallByContactType[$key])) {
        $systemCallByContactType[$key] = ['count' => (int) $row['call_count'], 'duration' => (int) $row['total_duration']];
    }
}
// تماس با متقاضی (نیروهای پذیرش): از «مشتری» جداست
$applicantCallsByUser = $rxCalls ? rx_cc_stats($pdo, $rangeFrom, $rangeTo, $staffId) : [];
$systemApplicantCalls = ['count' => 0, 'duration' => 0];
foreach ($applicantCallsByUser as $__a) {
    $systemApplicantCalls['count'] += $__a['n'];
    $systemApplicantCalls['duration'] += $__a['seconds'];
}

// =====================================================================
// گزارش تماس‌ها به تفکیک واحد — نسخه‌ی بهینه‌شده
// =====================================================================

// --- ۱) آمار گروهی همه‌ی کاربران در یک کوئری ---
$callStatsByUser = [];
$statsStmt = $pdo->prepare("
    SELECT created_by AS uid,
        SUM(CASE WHEN followup_number = 1 THEN 1 ELSE 0 END) AS new_calls,
        SUM(CASE WHEN followup_number > 1 THEN 1 ELSE 0 END) AS followup_calls,
        COUNT(*) AS calls_range,
        SUM(call_duration_seconds) AS duration_range,
        SUM(CASE WHEN source = 'novatel_import' THEN call_duration_seconds ELSE 0 END) AS novatel_duration_range,
        SUM(CASE WHEN source = 'call_import'    THEN call_duration_seconds ELSE 0 END) AS callizer_duration_range,
        SUM(CASE WHEN description = 'برقراری تماس' THEN 1 ELSE 0 END) AS connected_range,
        SUM(CASE WHEN description = 'بی پاسخ'      THEN 1 ELSE 0 END) AS missed_range
    FROM followups FORCE INDEX (idx_isphone_date_creator)
    WHERE is_phone_call = 1 AND contact_type = 'customer'
      AND followup_date BETWEEN ? AND ?" . ($rxCalls ? ' AND NOT EXISTS (SELECT 1 FROM reception_callizer_calls rxl WHERE rxl.followup_id = followups.id)' : '') . "
    GROUP BY created_by
");
$statsStmt->execute([$rangeFrom, $rangeTo]);
foreach ($statsStmt->fetchAll() as $row) {
    $callStatsByUser[(int) $row['uid']] = $row;
}
// --- ۲) آمار آپلود در بازه ---
$uploadByUser = [];
$uploadStmt = $pdo->prepare("
    SELECT created_by AS uid, COUNT(*) AS cnt
    FROM followups FORCE INDEX (idx_source_created_creator)
    WHERE source IN ('call_import','novatel_import')
      AND created_at >= ? AND created_at < DATE_ADD(?, INTERVAL 1 DAY)
    GROUP BY created_by
");
$uploadStmt->execute([$rangeFrom, $rangeTo]);
foreach ($uploadStmt->fetchAll() as $row) {
    $uploadByUser[(int) $row['uid']] = (int) $row['cnt'];
}

// --- ۳) ارجاع گرفته در بازه ---
$referralByUser = [];
if ($referralTableAvailable) {
    $refStmt = $pdo->prepare("
        SELECT to_user_id AS uid, COUNT(*) AS cnt
        FROM customer_referrals
        WHERE created_at >= ? AND created_at < DATE_ADD(?, INTERVAL 1 DAY)
        GROUP BY to_user_id
    ");
    $refStmt->execute([$rangeFrom, $rangeTo]);
    foreach ($refStmt->fetchAll() as $row) {
        $referralByUser[(int) $row['uid']] = (int) $row['cnt'];
    }
}

// --- ۴) کاربران فعال هر واحد + سرپرست ---
$usersStmt = $pdo->prepare("
    SELECT u.id, u.full_name, u.role, COALESCE(su.full_name, '') AS supervisor_name, COALESCE(u.service_access_role, '') AS service_access_role
    FROM users u
    $supervisorJoinSql
    WHERE u.role IN ('A','B','C')
      AND u.is_active = 1 AND u.is_approved = 1
      AND (u.service_access_role IS NULL OR u.service_access_role = '' OR u.service_access_role = 'reception_agent')
    ORDER BY u.full_name
");
$usersStmt->execute();
$allUsers = $usersStmt->fetchAll();

// --- ۵) ساخت آرایه‌ی نهایی به تفکیک واحد ---
$callUnitReports = ['A' => [], 'B' => [], 'C' => []];
foreach ($allUsers as $u) {
    $uid  = (int) $u['id'];
    $role = $u['role'];
    if (!isset($callUnitReports[$role])) {
        continue;
    }
    $s = $callStatsByUser[$uid] ?? null;
    if ($s === null || (int) $s['duration_range'] <= 0) {
        continue;
    }

    $novatelDur  = (int) $s['novatel_duration_range'];
    $callizerDur = (int) $s['callizer_duration_range'];
    if ($novatelDur > 0 && $callizerDur > 0) {
        $durationSource = 'mixed';
    } elseif ($novatelDur > 0) {
        $durationSource = 'novatel';
    } elseif ($callizerDur > 0) {
        $durationSource = 'callizer';
    } else {
        $durationSource = null;
    }

    $connected = (int) $s['connected_range'];
    $missed    = (int) $s['missed_range'];

    $row = [
        'id'                     => $uid,
        'full_name'              => $u['full_name'],
        'supervisor_name'        => $u['supervisor_name'],
        'is_reception'           => $u['service_access_role'] === 'reception_agent',
        'new_calls'              => (int) $s['new_calls'],
        'followup_calls'         => (int) $s['followup_calls'],
        'calls_range'            => (int) $s['calls_range'],
        'duration_range'         => (int) $s['duration_range'],
        'novatel_duration_range' => $novatelDur,
        'callizer_duration_range' => $callizerDur,
        'connected_range'        => $connected,
        'missed_range'           => $missed,
        'referrals_received_range' => $referralByUser[$uid] ?? 0,
        'uploaded_today'         => ($uploadByUser[$uid] ?? 0) > 0,
        'duration_source'        => $durationSource,
        'missed_ratio'           => ($connected > 0) ? round($missed / $connected, 2) : null,
        'referral_response'      => ['avg_minutes' => null, 'responded_count' => 0],
    ];

    // ⚠️ موقتاً کامنت شده تا سرعت رو بسنجیم
    // try {
    //     $row['referral_response'] = compute_avg_referral_response($pdo, $uid);
    // } catch (Throwable $e) {
    //     // در صورت خطا، مقدار پیش‌فرض می‌مونه
    // }

    $callUnitReports[$role][] = $row;
}

// --- ۶) نیروهای پذیرش: تماس با متقاضی | تماس با مشتری ---
$receptionCallRows = [];
foreach ($allUsers as $u) {
    if ($u['service_access_role'] !== 'reception_agent') continue;
    $uid = (int) $u['id'];
    if ($staffId > 0 && $uid !== $staffId) continue;
    $ap = $applicantCallsByUser[$uid] ?? ['n' => 0, 'connected' => 0, 'missed' => 0, 'seconds' => 0, 'applicants' => 0];
    $cs = $callStatsByUser[$uid] ?? null;
    $cust = ['n' => (int) ($cs['calls_range'] ?? 0), 'connected' => (int) ($cs['connected_range'] ?? 0), 'missed' => (int) ($cs['missed_range'] ?? 0), 'seconds' => (int) ($cs['duration_range'] ?? 0)];
    if ($ap['n'] === 0 && $cust['n'] === 0) continue;
    $receptionCallRows[] = ['id' => $uid, 'full_name' => $u['full_name'], 'supervisor_name' => $u['supervisor_name'], 'applicant' => $ap, 'customer' => $cust,
        'uploaded' => ($uploadByUser[$uid] ?? 0) > 0 || $ap['n'] > 0];
}
usort($receptionCallRows, static fn($a, $b) => ($b['applicant']['seconds'] + $b['customer']['seconds']) <=> ($a['applicant']['seconds'] + $a['customer']['seconds']));

// مرتب‌سازی هر واحد بر اساس مدت مکالمه
foreach (['A', 'B', 'C'] as $unitRole) {
    usort($callUnitReports[$unitRole], function ($a, $b) {
        if ($a['duration_range'] === $b['duration_range']) {
            return strcmp($a['full_name'], $b['full_name']);
        }
        return $b['duration_range'] <=> $a['duration_range'];
    });
}

// اگه یه نیروی خاص انتخاب شده، فقط همون رو نگه دار
if ($staffId > 0) {
    foreach ($callUnitReports as $unitRole => $rows) {
        $callUnitReports[$unitRole] = array_values(array_filter($rows, fn($r) => (int) $r['id'] === $staffId));
    }
}

// =====================================================================
// خروجی اکسل — همه‌ی واحدها با هم
// =====================================================================
if (isset($_GET['export']) && $_GET['export'] === 'xls') {
    $xlsRows = [];
    foreach (['A', 'B', 'C'] as $unitRole) {
        foreach ($callUnitReports[$unitRole] as $r) {
            $xlsRows[] = [
                'واحد ' . $unitRole, $r['full_name'], $r['supervisor_name'] ?? '', (int) $r['new_calls'], (int) $r['followup_calls'],
                (int) $r['calls_range'], format_duration_minutes_only((int) $r['duration_range']),
                (int) $r['connected_range'], (int) $r['missed_range'],
                ($r['duration_source'] ?? '') === 'novatel' ? 'نواتل' :
                (($r['duration_source'] ?? '') === 'callizer' ? 'کالیزر' :
                (($r['duration_source'] ?? '') === 'mixed' ? 'نواتل + کالیزر' : '')),
            ];
        }
    }
    foreach ($receptionCallRows as $r) {
        foreach (['applicant' => 'پذیرش — تماس با متقاضی', 'customer' => 'پذیرش — تماس با مشتری'] as $__k => $__lbl) {
            if ($r[$__k]['n'] === 0) continue;
            $xlsRows[] = [$__lbl, $r['full_name'], $r['supervisor_name'] ?? '', '', '', $r[$__k]['n'], format_duration_minutes_only($r[$__k]['seconds']),
                $r[$__k]['connected'], $r[$__k]['missed'], 'کالیزر'];
        }
    }
    export_table_as_xls('گزارش-تماس-' . $rangeFrom . '-تا-' . $rangeTo,
        ['واحد', 'نیرو', 'سرپرست', 'تماس مشتری جدید', 'تماس پیگیری', 'کل تماس', 'مدت مکالمه', 'برقرارشده', 'بی‌پاسخ', 'منبع مدت مکالمه'], $xlsRows);
}

// =====================================================================
// توابع کمکی
// =====================================================================
function __calls_range_link(string $preset, ?string $from = null, ?string $to = null): string
{
    global $staffId;
    $params = ['preset' => $preset];
    if ($preset === 'custom') {
        $params['from'] = $from;
        $params['to'] = $to;
    }
    if ($staffId > 0) {
        $params['staff_id'] = $staffId;
    }
    return 'admin_reports_calls.php?' . http_build_query($params);
}

function __calls_detail_link(string $type, string $preset, string $rangeFromJalali, string $rangeToJalali, ?int $userId = null, ?string $metric = null): string
{
    $params = ['type' => $type, 'preset' => $preset, 'from' => $rangeFromJalali, 'to' => $rangeToJalali];
    if ($userId !== null) {
        $params['user_id'] = $userId;
    }
    if ($metric !== null) {
        $params['metric'] = $metric;
    }
    return 'admin_reports_calls_detail.php?' . http_build_query($params);
}

$pageTitle = 'گزارش‌های تماس';
require_once __DIR__ . '/../includes/layout_top.php';
?>

<style>
.rc-page{--rc-line:#e7e2d3;--rc-ink:#1c1917;--rc-muted:#78716c;--rc-gold:#c9a24b;--rc-gold-2:#f1dfa8;font-size:.85rem;}
.rc-page h5,.rc-page h6{font-size:.9rem}
.rc-page .rc-back{display:inline-flex;align-items:center;gap:.45rem;border:1px solid var(--rc-line);border-radius:11px;padding:.4rem .9rem;font-size:.82rem;font-weight:700;color:var(--rc-muted);background:#fff;text-decoration:none;margin-bottom:1rem;transition:.15s ease;}
.rc-page .rc-back:hover{border-color:var(--rc-gold);color:#8a6a1e;background:#fdfaf1}
.rc-page .rc-hero{border-radius:20px;overflow:hidden;margin-bottom:1.2rem;position:relative;background:linear-gradient(135deg,#0b0f1a 0%,#3d3220 55%,var(--rc-gold) 130%);box-shadow:0 14px 34px -22px rgba(28,25,23,.55);}
.rc-page .rc-hero::before{content:'';position:absolute;inset:0;pointer-events:none;background:radial-gradient(circle at 92% -25%, rgba(241,223,168,.35), transparent 55%);}
.rc-page .rc-hero-body{position:relative;z-index:1;padding:1.3rem 1.5rem;display:flex;align-items:center;gap:.9rem;flex-wrap:wrap}
.rc-page .rc-hero-icon{width:48px;height:48px;border-radius:13px;display:inline-flex;align-items:center;justify-content:center;background:linear-gradient(135deg,var(--rc-gold-2),var(--rc-gold));color:#241d0a;font-size:1.15rem;box-shadow:0 10px 22px -10px rgba(201,162,75,.7);flex-shrink:0;}
.rc-page .rc-hero-title{color:#fff;font-weight:800;font-size:1.08rem;margin:0}
.rc-page .rc-hero-sub{color:rgba(255,255,255,.72);font-size:.8rem;margin:0}
.rc-page .card{border:1px solid var(--rc-line);border-radius:18px;box-shadow:0 6px 22px -18px rgba(28,25,23,.35)}
.rc-page .card h6{font-weight:800;color:var(--rc-ink);display:flex;align-items:center;gap:.5rem}
.rc-page .card h6 i{color:var(--rc-gold)}
.rc-page .rc-preset-btn{border:1px solid var(--rc-line);border-radius:999px;padding:.4rem 1.1rem;font-size:.82rem;font-weight:700;color:var(--rc-muted);background:#fff;text-decoration:none;transition:.15s ease;}
.rc-page .rc-preset-btn:hover{border-color:var(--rc-gold-2);color:#8a6a1e;background:#fdfaf1}
.rc-page .rc-preset-btn.active{border-color:transparent;color:#241d0a;background:linear-gradient(135deg,var(--rc-gold-2),var(--rc-gold));box-shadow:0 6px 14px -6px rgba(201,162,75,.6);}
.rc-page .form-control,.rc-page .form-select{border:1px solid var(--rc-line);border-radius:10px}
.rc-page .form-control:focus,.rc-page .form-select:focus{border-color:var(--rc-gold);box-shadow:0 0 0 .2rem rgba(201,162,75,.18)}
.rc-page .form-label{color:var(--rc-muted)}
.rc-page .btn-primary{border:none;border-radius:10px;font-weight:700;background:linear-gradient(135deg,var(--rc-gold-2),var(--rc-gold));color:#241d0a;box-shadow:0 6px 14px -6px rgba(201,162,75,.6);}
.rc-page .btn-outline-secondary{border:1px solid var(--rc-line);border-radius:10px;color:var(--rc-muted);font-weight:700}
.rc-page .btn-outline-secondary:hover{background:#faf9f5;border-color:var(--rc-gold-2);color:#8a6a1e}
.rc-page .btn-outline-success{border:1px solid #86c19a;border-radius:10px;color:#166534;font-weight:700;background:#fff;}
.rc-page .btn-outline-success:hover{background:linear-gradient(135deg,#bff0cf,#7fd39c);border-color:transparent;color:#0d3d20}
.rc-page .alert-warning{border-radius:12px;border:1px solid #f3d98a;background:#fdf6df;color:#6b5417}
.rc-page .glance-box{border:1px solid var(--rc-line);border-radius:16px;background:#fdfcf9;box-shadow:0 4px 16px -14px rgba(28,25,23,.3);transition:.15s ease;}
.rc-page .glance-box:hover{box-shadow:0 10px 22px -14px rgba(28,25,23,.35);transform:translateY(-2px);border-color:var(--rc-gold-2)}
.rc-page .glance-num{background:linear-gradient(135deg,#8a6d2c,var(--rc-gold));-webkit-background-clip:text;background-clip:text;color:transparent;font-weight:800;}
.rc-page .glance-label{color:var(--rc-muted)}
.rc-page .nav-tabs{border-bottom:1px solid var(--rc-line);gap:.3rem}
.rc-page .nav-tabs .nav-link{border:1px solid transparent;border-radius:11px 11px 0 0;color:var(--rc-muted);font-weight:700;font-size:.83rem;padding:.5rem 1.1rem;display:flex;align-items:center;gap:.4rem;}
.rc-page .nav-tabs .nav-link .badge{background:#f1ece0 !important;color:#78716c !important;border-radius:999px;font-weight:700;}
.rc-page .nav-tabs .nav-link.active{color:#241d0a;background:linear-gradient(135deg,var(--rc-gold-2),var(--rc-gold));border-color:var(--rc-gold);}
.rc-page .nav-tabs .nav-link.active .badge{background:rgba(36,29,10,.15) !important;color:#241d0a !important}
.rc-page .nav-tabs .nav-link:hover:not(.active){border-color:var(--rc-line);background:#faf9f5}
.rc-page table{font-size:.85rem}
.rc-page table thead.table-light th{background:linear-gradient(135deg,#faf5e7,#f1e6c8);color:#5c4a1e;font-weight:700;border-bottom:1px solid var(--rc-line);white-space:nowrap;}
.rc-page table tbody td{border-bottom:1px solid #f3f1ea;vertical-align:middle}
.rc-page table tbody tr:hover{background:#faf8f2}
.rc-page table a{color:var(--rc-ink);font-weight:700;text-decoration:none}
.rc-page table a:hover{color:#8a6a1e}
.rc-page .badge.bg-success-subtle{background:linear-gradient(135deg,#bdf0cf,#7fd39c) !important;color:#0d3d20 !important;border-radius:999px}
.rc-page .badge.bg-danger-subtle{background:linear-gradient(135deg,#f8c9c9,#ef9b9b) !important;color:#5c0f0f !important;border-radius:999px}
.rc-page .badge.bg-secondary-subtle{background:#f1ece0 !important;color:#78716c !important;border-radius:999px}
.rc-page .rc-source-badge{display:inline-flex;align-items:center;justify-content:center;border-radius:999px;padding:.25rem .65rem;font-weight:800;font-size:.75rem;white-space:nowrap}
.rc-page .rc-source-novatel{background:#dbeafe;color:#1d4ed8;border:1px solid #93c5fd}
.rc-page .rc-source-callizer{background:#dcfce7;color:#15803d;border:1px solid #86efac}
.rc-page .rc-source-mixed{background:linear-gradient(90deg,#dbeafe 0 50%,#dcfce7 50% 100%);color:#1f2937;border:1px solid #a7b0bd}
@media (max-width:767.98px){.rc-page .rc-hero-body{padding:1rem 1.1rem}.rc-page .rc-hero-title{font-size:.98rem}.rc-page .card{border-radius:15px}}
</style>

<div class="rc-page">
<a href="admin_reports.php" class="rc-back"><i class="fa-solid fa-arrow-right"></i> بازگشت به گزارش‌های مدیریتی</a>

<div class="rc-hero">
  <div class="rc-hero-body">
    <span class="rc-hero-icon"><i class="fa-solid fa-phone-volume"></i></span>
    <div>
      <h5 class="rc-hero-title">گزارش‌های تماس</h5>
      <p class="rc-hero-sub mb-0">مدت‌مکالمه، تماس‌های جدید/پیگیری و پاسخ به ارجاع، به تفکیک واحد و نیرو</p>
    </div>
  </div>
</div>

<div class="card p-3 mb-4">
  <div class="d-flex align-items-center gap-2 mb-3">
    <i class="fa-solid fa-calendar-days"></i>
    <h6 class="mb-0 fw-bold">بازه‌ی گزارش: <?= e($rangeLabel) ?> (<?= to_jalali($rangeFrom) ?><?= $rangeFrom !== $rangeTo ? ' تا ' . to_jalali($rangeTo) : '' ?>)</h6>
  </div>
  <?php if (!empty($dateParseError)): ?>
    <div class="alert alert-warning small py-2 mb-3"><i class="fa-solid fa-triangle-exclamation"></i> <?= $dateParseError ?></div>
  <?php endif; ?>
  <div class="d-flex gap-2 flex-wrap mb-3">
    <a href="<?= e(__calls_range_link('today')) ?>" class="rc-preset-btn <?= $preset === 'today' ? 'active' : '' ?>">امروز</a>
    <a href="<?= e(__calls_range_link('yesterday')) ?>" class="rc-preset-btn <?= $preset === 'yesterday' ? 'active' : '' ?>">دیروز</a>
    <a href="<?= e(__calls_range_link('week')) ?>" class="rc-preset-btn <?= $preset === 'week' ? 'active' : '' ?>">این هفته</a>
    <a href="<?= e(__calls_range_link('month')) ?>" class="rc-preset-btn <?= $preset === 'month' ? 'active' : '' ?>">این ماه</a>
  </div>
  <form method="get" class="d-flex gap-2 flex-wrap align-items-end">
    <input type="hidden" name="preset" value="custom">
    <div>
      <label class="form-label small mb-1">از تاریخ</label>
      <input type="text" name="from" class="form-control form-control-sm jalali-date" dir="ltr" autocomplete="off" value="<?= e($preset === 'custom' ? to_jalali($rangeFrom) : '') ?>" placeholder="۱۴۰۵/۰۱/۰۱">
    </div>
    <div>
      <label class="form-label small mb-1">تا تاریخ</label>
      <input type="text" name="to" class="form-control form-control-sm jalali-date" dir="ltr" autocomplete="off" value="<?= e($preset === 'custom' ? to_jalali($rangeTo) : '') ?>" placeholder="۱۴۰۵/۰۶/۱۹">
    </div>
    <button type="submit" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-magnifying-glass"></i> اعمال بازه</button>
  </form>
</div>

<div class="card p-3 mb-4">
  <form method="get" class="d-flex gap-2 flex-wrap align-items-end">
    <input type="hidden" name="preset" value="<?= e($preset) ?>">
    <?php if ($preset === 'custom'): ?>
      <input type="hidden" name="from" value="<?= e(to_jalali($rangeFrom)) ?>">
      <input type="hidden" name="to" value="<?= e(to_jalali($rangeTo)) ?>">
    <?php endif; ?>
    <div class="flex-grow-1" style="max-width:320px;">
      <label class="form-label small mb-1">جستجوی نیرو (نام)</label>
      <input type="text" id="staffSearch" class="form-control form-control-sm" list="staffDatalist" autocomplete="off" placeholder="شروع به تایپ کنید..." value="<?= e($selectedStaffDisplay) ?>">
      <datalist id="staffDatalist">
        <?php foreach ($allStaffForSearch as $s): ?>
          <option data-id="<?= (int) $s['id'] ?>" value="<?= e(person_pick_label((string) $s['full_name'], $s['mobile'] ?? null, 'واحد ' . $s['role'])) ?>"></option>
        <?php endforeach; ?>
      </datalist>
      <input type="hidden" name="staff_id" id="staffHidden" value="<?= $staffId ?: '' ?>">
    </div>
    <button type="submit" class="btn btn-sm btn-primary"><i class="fa-solid fa-magnifying-glass"></i> جستجو</button>
    <?php if ($staffId > 0):
      $clearParams = ['preset' => $preset];
      if ($preset === 'custom') {
          $clearParams['from'] = to_jalali($rangeFrom);
          $clearParams['to'] = to_jalali($rangeTo);
      }
    ?>
      <a href="admin_reports_calls.php?<?= e(http_build_query($clearParams)) ?>" class="btn btn-sm btn-outline-secondary">پاک کردن</a>
    <?php endif; ?>
  </form>
</div>

<script>
(function () {
  var searchEl = document.getElementById('staffSearch');
  var hiddenEl = document.getElementById('staffHidden');
  var datalistEl = document.getElementById('staffDatalist');
  if (!searchEl || !hiddenEl || !datalistEl) return;
  function sync() {
    var typed = searchEl.value;
    var options = datalistEl.querySelectorAll('option');
    var matched = null;
    for (var i = 0; i < options.length; i++) {
      if (options[i].value === typed) { matched = options[i]; break; }
    }
    hiddenEl.value = matched ? matched.getAttribute('data-id') : '';
  }
  searchEl.addEventListener('input', sync);
  searchEl.addEventListener('change', sync);
})();
</script>

<div class="card p-3 mb-4">
  <div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
    <i class="fa-solid fa-users-viewfinder"></i>
    <h6 class="mb-0 fw-bold">مدت مکالمه به تفکیک نوع مخاطب — <?= $staffId > 0 ? e($selectedStaffName) : 'کل سیستم' ?> — <?= e($rangeLabel) ?></h6>
  </div>
  <div class="row g-2 text-center">
    <div class="<?= $rxCalls ? 'col-6 col-md-3' : 'col-4' ?>">
      <a href="<?= e(__calls_detail_link('customer', $preset, to_jalali($rangeFrom), to_jalali($rangeTo), $staffId > 0 ? $staffId : null)) ?>" class="text-decoration-none">
        <div class="glance-box p-3">
          <div class="glance-num">
            <span class="d-none d-md-inline"><?= format_duration_seconds($systemCallByContactType['customer']['duration']) ?></span>
            <span class="d-md-none"><?= format_duration_minutes_only($systemCallByContactType['customer']['duration']) ?></span>
          </div>
          <div class="glance-label">با مشتری (<?= to_persian_digits((string) $systemCallByContactType['customer']['count']) ?> تماس)</div>
        </div>
      </a>
    </div>
    <div class="<?= $rxCalls ? 'col-6 col-md-3' : 'col-4' ?>">
      <a href="<?= e(__calls_detail_link('colleague', $preset, to_jalali($rangeFrom), to_jalali($rangeTo), $staffId > 0 ? $staffId : null)) ?>" class="text-decoration-none">
        <div class="glance-box p-3">
          <div class="glance-num">
            <span class="d-none d-md-inline"><?= format_duration_seconds($systemCallByContactType['colleague']['duration']) ?></span>
            <span class="d-md-none"><?= format_duration_minutes_only($systemCallByContactType['colleague']['duration']) ?></span>
          </div>
          <div class="glance-label">با همکار (<?= to_persian_digits((string) $systemCallByContactType['colleague']['count']) ?> تماس)</div>
        </div>
      </a>
    </div>
    <div class="<?= $rxCalls ? 'col-6 col-md-3' : 'col-4' ?>">
      <a href="<?= e(__calls_detail_link('family', $preset, to_jalali($rangeFrom), to_jalali($rangeTo), $staffId > 0 ? $staffId : null)) ?>" class="text-decoration-none">
        <div class="glance-box p-3">
          <div class="glance-num">
            <span class="d-none d-md-inline"><?= format_duration_seconds($systemCallByContactType['family']['duration']) ?></span>
            <span class="d-md-none"><?= format_duration_minutes_only($systemCallByContactType['family']['duration']) ?></span>
          </div>
          <div class="glance-label">با خانواده (<?= to_persian_digits((string) $systemCallByContactType['family']['count']) ?> تماس)</div>
        </div>
      </a>
    </div>
    <?php if ($rxCalls): ?>
    <div class="col-6 col-md-3">
      <a href="#reception-calls" class="text-decoration-none">
        <div class="glance-box p-3">
          <div class="glance-num">
            <span class="d-none d-md-inline"><?= format_duration_seconds($systemApplicantCalls['duration']) ?></span>
            <span class="d-md-none"><?= format_duration_minutes_only($systemApplicantCalls['duration']) ?></span>
          </div>
          <div class="glance-label">با متقاضیِ همکاری — پذیرش (<?= to_persian_digits((string) $systemApplicantCalls['count']) ?> تماس)</div>
        </div>
      </a>
    </div>
    <?php endif; ?>
  </div>
</div>

<div class="card p-3 mb-4">
  <div class="d-flex align-items-center justify-content-between gap-2 mb-3 flex-wrap">
    <div class="d-flex align-items-center gap-2">
      <i class="fa-solid fa-phone"></i>
      <h6 class="mb-0 fw-bold">گزارش تماس‌های کالیزر (به تفکیک واحد) — <?= e($rangeLabel) ?></h6>
      <span class="text-muted small">(روی هر عدد کلیک کنید تا تماس‌های تشکیل‌دهنده‌اش را ببینید — «مدت مکالمه» = تماس‌های برقرارِ بیش از ۱۰ ثانیه با مشتری (کالیزر/نواتل) — همان تعریفِ «گزارش تیم‌ها»؛ تماسِ پذیرش با متقاضی جدا، پایینِ صفحه)</span>
    </div>
    <a href="?<?= e(http_build_query(array_merge($_GET, ['export' => 'xls']))) ?>" class="btn btn-sm btn-outline-success"><i class="fa-solid fa-file-excel"></i> خروجی اکسل</a>
  </div>
  <ul class="nav nav-tabs mb-3" role="tablist">
    <?php foreach (['A' => 'واحد A', 'B' => 'واحد B', 'C' => 'واحد C'] as $unitRole => $unitLabel): ?>
      <li class="nav-item" role="presentation">
        <button class="nav-link <?= $unitRole === 'A' ? 'active' : '' ?>" data-bs-toggle="tab" data-bs-target="#callUnit<?= $unitRole ?>" type="button">
          <?= e($unitLabel) ?> <span class="badge bg-secondary-subtle text-secondary-emphasis"><?= to_persian_digits((string) count($callUnitReports[$unitRole])) ?></span>
        </button>
      </li>
    <?php endforeach; ?>
  </ul>
  <div class="tab-content">
    <?php foreach (['A', 'B', 'C'] as $unitRole): ?>
      <div class="tab-pane fade <?= $unitRole === 'A' ? 'show active' : '' ?>" id="callUnit<?= $unitRole ?>" role="tabpanel">
        <?php if (!$callUnitReports[$unitRole]): ?>
          <p class="text-muted small mb-0">کارشناس فعالی در این واحد ثبت نشده.</p>
        <?php else: ?>
          <div class="table-responsive call-unit-table-wrap">
            <table class="table table-sm align-middle mb-0 call-unit-table">
              <?php if ($unitRole === 'C'): ?>
                <thead class="table-light"><tr><th>کارشناس</th><th>سرپرست</th><th>تماس</th><th>ارجاع گرفته</th><th>مدت مکالمه</th><th class="d-none d-md-table-cell">نسبت بی‌پاسخ:برقرار</th><th class="d-none d-md-table-cell">میانگین پاسخ به ارجاع</th><th>آپلود</th><th>منبع مدت مکالمه</th></tr></thead>
                <tbody>
                  <?php foreach ($callUnitReports[$unitRole] as $r): ?>
                    <?php $rfJ = to_jalali($rangeFrom); $rtJ = to_jalali($rangeTo); ?>
                    <tr>
                      <td><?= e($r['full_name']) ?><?php if (!empty($r['is_reception'])): ?> <span class="badge bg-info-subtle text-info-emphasis" title="نیروی پذیرش؛ این ردیف فقط تماس با مشتری است — تماس با متقاضی در جدولِ «نیروهای پذیرش»">پذیرش</span><?php endif; ?></td>
                      <td><?= e($r['supervisor_name'] ?? '') ?: '-' ?></td>
                      <td><a href="<?= e(__calls_detail_link('customer', $preset, $rfJ, $rtJ, (int) $r['id'])) ?>" class="text-decoration-none"><?= to_persian_digits((string) $r['calls_range']) ?></a></td>
                      <td><?= to_persian_digits((string) (int) $r['referrals_received_range']) ?></td>
                      <td>
                        <a href="<?= e(__calls_detail_link('customer', $preset, $rfJ, $rtJ, (int) $r['id'])) ?>" class="text-decoration-none">
                          <span class="d-none d-md-inline"><?= format_duration_seconds((int) $r['duration_range']) ?></span>
                          <span class="d-md-none"><?= format_duration_minutes_only((int) $r['duration_range']) ?></span>
                        </a>
                      </td>
                      <td class="d-none d-md-table-cell">
                        <?php if ($r['missed_ratio'] === null): ?>
                          -
                        <?php else: ?>
                          <a href="<?= e(__calls_detail_link('customer', $preset, $rfJ, $rtJ, (int) $r['id'], 'missed')) ?>" class="text-decoration-none" title="تماس‌های بی‌پاسخ"><?= to_persian_digits((string) (int) $r['missed_range']) ?></a>
                          :
                          <a href="<?= e(__calls_detail_link('customer', $preset, $rfJ, $rtJ, (int) $r['id'], 'connected')) ?>" class="text-decoration-none" title="تماس‌های برقرارشده"><?= to_persian_digits((string) (int) $r['connected_range']) ?></a>
                        <?php endif; ?>
                      </td>
                      <td class="d-none d-md-table-cell"><?= $r['referral_response']['avg_minutes'] === null ? '-' : format_minutes_readable($r['referral_response']['avg_minutes']) ?></td>
                      <td>
                        <?php if ($r['uploaded_today']): ?>
                          <span class="badge bg-success-subtle text-success-emphasis" title="در این بازه آپلود کرده"><i class="fa-solid fa-check"></i></span>
                        <?php else: ?>
                          <span class="badge bg-danger-subtle text-danger-emphasis" title="در این بازه آپلود نکرده"><i class="fa-solid fa-xmark"></i></span>
                        <?php endif; ?>
                      </td>
                      <td>
                        <?php if (($r['duration_source'] ?? null) === 'novatel'): ?>
                          <span class="rc-source-badge rc-source-novatel">نواتل</span>
                        <?php elseif (($r['duration_source'] ?? null) === 'callizer'): ?>
                          <span class="rc-source-badge rc-source-callizer">کالیزر</span>
                        <?php elseif (($r['duration_source'] ?? null) === 'mixed'): ?>
                          <span class="rc-source-badge rc-source-mixed">نواتل + کالیزر</span>
                        <?php else: ?>
                          <span class="text-muted">-</span>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              <?php else: ?>
                <thead class="table-light"><tr><th>کارشناس</th><th>سرپرست</th><th>تماس جدید</th><th>تماس پیگیری</th><th>مدت مکالمه</th><th class="d-none d-md-table-cell">بی‌پاسخ:برقرار</th><th class="d-none d-md-table-cell">میانگین پاسخ به ارجاع</th><th>آپلود</th><th>منبع مدت مکالمه</th></tr></thead>
                <tbody>
                  <?php foreach ($callUnitReports[$unitRole] as $r): ?>
                    <?php $rfJ = to_jalali($rangeFrom); $rtJ = to_jalali($rangeTo); ?>
                    <tr>
                      <td><?= e($r['full_name']) ?><?php if (!empty($r['is_reception'])): ?> <span class="badge bg-info-subtle text-info-emphasis" title="نیروی پذیرش؛ این ردیف فقط تماس با مشتری است — تماس با متقاضی در جدولِ «نیروهای پذیرش»">پذیرش</span><?php endif; ?></td>
                      <td><?= e($r['supervisor_name'] ?? '') ?: '-' ?></td>
                      <td><a href="<?= e(__calls_detail_link('customer', $preset, $rfJ, $rtJ, (int) $r['id'], 'new')) ?>" class="text-decoration-none"><?= to_persian_digits((string) (int) $r['new_calls']) ?></a></td>
                      <td><a href="<?= e(__calls_detail_link('customer', $preset, $rfJ, $rtJ, (int) $r['id'], 'followup')) ?>" class="text-decoration-none"><?= to_persian_digits((string) (int) $r['followup_calls']) ?></a></td>
                      <td>
                        <a href="<?= e(__calls_detail_link('customer', $preset, $rfJ, $rtJ, (int) $r['id'])) ?>" class="text-decoration-none">
                          <span class="d-none d-md-inline"><?= format_duration_seconds((int) $r['duration_range']) ?></span>
                          <span class="d-md-none"><?= format_duration_minutes_only((int) $r['duration_range']) ?></span>
                        </a>
                      </td>
                      <td class="d-none d-md-table-cell">
                        <?php if ($r['missed_ratio'] === null): ?>
                          -
                        <?php else: ?>
                          <a href="<?= e(__calls_detail_link('customer', $preset, $rfJ, $rtJ, (int) $r['id'], 'missed')) ?>" class="text-decoration-none" title="تماس‌های بی‌پاسخ"><?= to_persian_digits((string) (int) $r['missed_range']) ?></a>
                          :
                          <a href="<?= e(__calls_detail_link('customer', $preset, $rfJ, $rtJ, (int) $r['id'], 'connected')) ?>" class="text-decoration-none" title="تماس‌های برقرارشده"><?= to_persian_digits((string) (int) $r['connected_range']) ?></a>
                        <?php endif; ?>
                      </td>
                      <td class="d-none d-md-table-cell"><?= $r['referral_response']['avg_minutes'] === null ? '-' : format_minutes_readable($r['referral_response']['avg_minutes']) ?></td>
                      <td>
                        <?php if ($r['uploaded_today']): ?>
                          <span class="badge bg-success-subtle text-success-emphasis" title="در این بازه آپلود کرده"><i class="fa-solid fa-check"></i></span>
                        <?php else: ?>
                          <span class="badge bg-danger-subtle text-danger-emphasis" title="در این بازه آپلود نکرده"><i class="fa-solid fa-xmark"></i></span>
                        <?php endif; ?>
                      </td>
                      <td>
                        <?php if (($r['duration_source'] ?? null) === 'novatel'): ?>
                          <span class="rc-source-badge rc-source-novatel">نواتل</span>
                        <?php elseif (($r['duration_source'] ?? null) === 'callizer'): ?>
                          <span class="rc-source-badge rc-source-callizer">کالیزر</span>
                        <?php elseif (($r['duration_source'] ?? null) === 'mixed'): ?>
                          <span class="rc-source-badge rc-source-mixed">نواتل + کالیزر</span>
                        <?php else: ?>
                          <span class="text-muted">-</span>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              <?php endif; ?>
            </table>
          </div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<?php if ($rxCalls): ?>
<div class="card p-3 mb-4" id="reception-calls">
  <div class="d-flex align-items-center gap-2 mb-1 flex-wrap">
    <i class="fa-solid fa-user-tie"></i>
    <h6 class="mb-0 fw-bold">نیروهای پذیرش — تماس با متقاضی و تماس با مشتری (کالیزر) — <?= e($rangeLabel) ?></h6>
  </div>
  <p class="text-muted small mb-3">در آپلودِ کالیزرِ نیروی پذیرش، شماره‌ای که در بانکِ متقاضیان هست «تماس با متقاضی» و بقیه «تماس با مشتری» حساب می‌شود؛ هر کدام جدا. روی هر عدد بزنید تا تماس‌ها را ببینید.</p>
  <?php if (!$receptionCallRows): ?>
    <p class="text-muted small mb-0">در این بازه تماسی از نیروهای پذیرش ثبت نشده.</p>
  <?php else: $rfJ = to_jalali($rangeFrom); $rtJ = to_jalali($rangeTo); $__tot = ['a' => 0, 'as' => 0, 'c' => 0, 'cs' => 0]; ?>
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0 text-center">
      <thead class="table-light">
        <tr><th rowspan="2" class="text-start">نیرو</th><th rowspan="2">سرپرست</th><th colspan="4" style="background:#e6f3f8">تماس با متقاضی (همکاری)</th><th colspan="4" style="background:#fbf4e1">تماس با مشتری</th><th rowspan="2">جمعِ مدت</th><th rowspan="2">آپلود</th></tr>
        <tr><th style="background:#e6f3f8">تماس</th><th style="background:#e6f3f8">برقرار</th><th style="background:#e6f3f8">بی‌پاسخ</th><th style="background:#e6f3f8">مدت</th>
            <th style="background:#fbf4e1">تماس</th><th style="background:#fbf4e1">برقرار</th><th style="background:#fbf4e1">بی‌پاسخ</th><th style="background:#fbf4e1">مدت</th></tr>
      </thead>
      <tbody>
      <?php foreach ($receptionCallRows as $r): $a = $r['applicant']; $c = $r['customer'];
            $__tot['a'] += $a['n']; $__tot['as'] += $a['seconds']; $__tot['c'] += $c['n']; $__tot['cs'] += $c['seconds']; ?>
        <tr>
          <td class="text-start"><?= e($r['full_name']) ?></td>
          <td><?= e($r['supervisor_name'] ?? '') ?: '-' ?></td>
          <td><a class="text-decoration-none" href="<?= e(__calls_detail_link('applicant', $preset, $rfJ, $rtJ, (int) $r['id'], 'all')) ?>"><?= to_persian_digits((string) $a['n']) ?></a></td>
          <td><a class="text-decoration-none text-success" href="<?= e(__calls_detail_link('applicant', $preset, $rfJ, $rtJ, (int) $r['id'], 'connected')) ?>"><?= to_persian_digits((string) $a['connected']) ?></a></td>
          <td><a class="text-decoration-none text-danger" href="<?= e(__calls_detail_link('applicant', $preset, $rfJ, $rtJ, (int) $r['id'], 'missed')) ?>"><?= to_persian_digits((string) $a['missed']) ?></a></td>
          <td><?= format_duration_minutes_only($a['seconds']) ?></td>
          <td><a class="text-decoration-none" href="<?= e(__calls_detail_link('customer', $preset, $rfJ, $rtJ, (int) $r['id'], 'all')) ?>"><?= to_persian_digits((string) $c['n']) ?></a></td>
          <td><a class="text-decoration-none text-success" href="<?= e(__calls_detail_link('customer', $preset, $rfJ, $rtJ, (int) $r['id'], 'connected')) ?>"><?= to_persian_digits((string) $c['connected']) ?></a></td>
          <td><a class="text-decoration-none text-danger" href="<?= e(__calls_detail_link('customer', $preset, $rfJ, $rtJ, (int) $r['id'], 'missed')) ?>"><?= to_persian_digits((string) $c['missed']) ?></a></td>
          <td><?= format_duration_minutes_only($c['seconds']) ?></td>
          <td class="fw-bold"><?= format_duration_minutes_only($a['seconds'] + $c['seconds']) ?></td>
          <td><?php if ($r['uploaded']): ?><span class="badge bg-success-subtle text-success-emphasis"><i class="fa-solid fa-check"></i></span><?php else: ?><span class="badge bg-danger-subtle text-danger-emphasis"><i class="fa-solid fa-xmark"></i></span><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
        <tr class="table-light fw-bold"><td class="text-start" colspan="2">جمع</td>
          <td><?= to_persian_digits((string) $__tot['a']) ?></td><td colspan="2"></td><td><?= format_duration_minutes_only($__tot['as']) ?></td>
          <td><?= to_persian_digits((string) $__tot['c']) ?></td><td colspan="2"></td><td><?= format_duration_minutes_only($__tot['cs']) ?></td>
          <td><?= format_duration_minutes_only($__tot['as'] + $__tot['cs']) ?></td><td></td></tr>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/layout_bottom.php'; ?>