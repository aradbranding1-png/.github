<?php
require_once __DIR__ . '/includes/auth.php';
$user = require_login();
$pdo  = db();

$isAdmin = $user['role'] === 'admin';
$dismissedStatusesSql = "('انصرافی','نامرتبط','خرید کرده')";

// =====================================================================
// کشِ مشترک (includes/perf_cache.php): شمارش‌های سنگین یک‌بار برای همه‌ی هم‌دسترسی‌ها حساب می‌شوند.
// بعد از انقضا عددِ قبلی فوراً نشان داده می‌شود و عددِ تازه بعد از رسیدنِ صفحه حساب می‌شود؛
// پس با میلیون‌ها مشتری هم داشبورد منتظرِ شمارش نمی‌ماند.
// همه‌ی شمارش‌ها یک‌جا (GROUP BY وضعیت) و فقط از روی ایندکس خوانده می‌شوند.
// =====================================================================
require_once __DIR__ . '/includes/perf_indexes.php';
perf_indexes_ready($pdo);
$CACHE_TTL = 120;

/** شمارشِ وضعیت‌ها + امروز/فردا/عقب‌افتاده در یک کوئری */
function dash_status_buckets(PDO $pdo, string $scopeSql, array $scopeParams): array
{
    $st = $pdo->prepare("SELECT status,
            COUNT(*) AS c,
            SUM(next_followup_date = CURDATE()) AS today_c,
            SUM(next_followup_date = CURDATE() + INTERVAL 1 DAY) AS tomorrow_c,
            SUM(next_followup_date < CURDATE()) AS overdue_c
        FROM customers
        WHERE $scopeSql contact_type = 'customer'
        GROUP BY status");
    $st->execute($scopeParams);
    $out = ['statusCounts' => [], 'totalCustomers' => 0, 'todayCount' => 0, 'tomorrowCount' => 0, 'overdueCount' => 0];
    foreach ($st->fetchAll() as $r) {
        $status = (string) $r['status'];
        $out['statusCounts'][$status] = (int) $r['c'];
        if (!in_array($status, ['شاکی', 'انصرافی', 'نامرتبط'], true)) $out['totalCustomers'] += (int) $r['c'];
        if (!in_array($status, ['شاکی', 'انصرافی', 'نامرتبط', 'خرید کرده'], true)) {
            $out['todayCount']    += (int) $r['today_c'];
            $out['tomorrowCount'] += (int) $r['tomorrow_c'];
            $out['overdueCount']  += (int) $r['overdue_c'];
        }
    }
    return $out;
}

/** نمودارِ ۶ ماهه‌ی مشتریانِ جدید */
function dash_trend(PDO $pdo, string $scopeSql, array $scopeParams): array
{
    $rangeStart = date('Y-m-01', strtotime('-5 months'));
    $trendStmt = $pdo->prepare("SELECT DATE_FORMAT(created_at, '%Y-%m') ym, COUNT(*) c
                                 FROM customers WHERE $scopeSql created_at >= ?
                                 GROUP BY ym");
    $trendStmt->execute(array_merge($scopeParams, [$rangeStart]));
    $trendMap = [];
    foreach ($trendStmt->fetchAll() as $r) {
        $trendMap[$r['ym']] = (int) $r['c'];
    }
    $labels = [];
    $values = [];
    for ($i = 5; $i >= 0; $i--) {
        $ym = date('Y-m', strtotime("-$i months"));
        [$yy, $mm] = explode('-', $ym);
        $j = gregorian_to_jalali_arr((int) $yy, (int) $mm, 15);
        $labels[] = jalali_month_name($j[1]);
        $values[] = $trendMap[$ym] ?? 0;
    }
    return [$labels, $values];
}

if ($isAdmin) {
    $adminData = app_cache_remember('dash_admin_v2_' . date('Y-m-d'), $CACHE_TTL, static function () use ($pdo) {
        $b = dash_status_buckets($pdo, '', []);
        $totalStaff = (int) $pdo->query("SELECT COUNT(*) c FROM users WHERE role != 'admin' AND is_approved = 1 AND is_active = 1")->fetch()['c'];
        $unitCounts = ['A' => 0, 'B' => 0, 'C' => 0];
        $unitStmt = $pdo->query("SELECT u.role, SUM(x.cnt) cnt
                                  FROM (SELECT owner_user_id, COUNT(*) cnt FROM customers GROUP BY owner_user_id) x
                                  JOIN users u ON u.id = x.owner_user_id
                                  WHERE u.role IN ('A','B','C') GROUP BY u.role");
        foreach ($unitStmt->fetchAll() as $r) {
            $unitCounts[$r['role']] = (int) $r['cnt'];
        }
        [$trendLabels, $trendValues] = dash_trend($pdo, "contact_type = 'customer' AND", []);
        return [
            'totalCustomers' => $b['totalCustomers'],
            'totalStaff'     => $totalStaff,
            'todayCount'     => $b['todayCount'],
            'tomorrowCount'  => $b['tomorrowCount'],
            'overdueCount'   => $b['overdueCount'],
            'unitCounts'     => $unitCounts,
            'trendLabels'    => $trendLabels,
            'trendValues'    => $trendValues,
        ];
    });
    $totalCustomers = $adminData['totalCustomers'];
    $totalStaff     = $adminData['totalStaff'];
    $todayCount     = $adminData['todayCount'];
    $tomorrowCount  = $adminData['tomorrowCount'];
    $overdueCount   = $adminData['overdueCount'];
    $unitCounts     = $adminData['unitCounts'];
    $trendLabels    = $adminData['trendLabels'];
    $trendValues    = $adminData['trendValues'];
} else {
    $visibleOwnerIds = visible_owner_ids_for($pdo, $user);
    $placeholders = implode(',', array_fill(0, count($visibleOwnerIds), '?'));
    $bucketsFn = static fn () => dash_status_buckets($pdo, "owner_user_id IN ($placeholders) AND", $visibleOwnerIds);
    // سرپرستِ تیمِ بزرگ: کشِ ۶۰ ثانیه‌ای؛ کارشناس (چند صد مشتری): همیشه عددِ لحظه‌ای
    $b = count($visibleOwnerIds) > 30
        ? app_cache_remember('dash_scope_' . (int) $user['id'] . '_' . date('Y-m-d') . '_' . md5(implode(',', $visibleOwnerIds)), 60, $bucketsFn)
        : $bucketsFn();
    $totalCustomers = $b['totalCustomers'];
    $todayCount     = $b['todayCount'];
    $tomorrowCount  = $b['tomorrowCount'];
    $overdueCount   = $b['overdueCount'];
    $statusCounts   = $b['statusCounts'];

    [$trendLabels, $trendValues] = dash_trend($pdo, 'owner_user_id = ? AND', [(int) $user['id']]);
}

$dueList = [];
$topCallers = [];
$yesterdayYmd = date('Y-m-d', strtotime('-1 day'));

if ($isAdmin) {
    // آمارِ «دیروز» در طولِ روز تغییرِ زیادی ندارد: ۱۰ دقیقه کش
    $topCallers = app_cache_remember('dash_top_callers_' . $yesterdayYmd, 600, static function () use ($pdo, $yesterdayYmd) {
        $topStmt = $pdo->prepare("SELECT u.id, u.full_name, u.role,
                COUNT(*) AS connected_calls,
                SUM(f.call_duration_seconds) AS total_seconds
            FROM followups f
            JOIN customers c ON c.id = f.customer_id
            JOIN users u ON u.id = f.created_by
            WHERE f.followup_date = ?
              AND f.source IN ('call_import','novatel_import')
              AND f.call_duration_seconds > 10
              AND (c.contact_type = 'customer' OR c.contact_type IS NULL)
              AND u.role <> 'admin'
            GROUP BY u.id, u.full_name, u.role");
        $topStmt->execute([$yesterdayYmd]);
        $topById = [];
        foreach ($topStmt->fetchAll() as $r) {
            $topById[(int) $r['id']] = [
                'id' => (int) $r['id'], 'full_name' => $r['full_name'], 'role' => $r['role'],
                'connected_calls' => (int) $r['connected_calls'], 'total_seconds' => (int) $r['total_seconds'],
                'meetings' => 0,
            ];
        }

        try {
            $mtStmt = $pdo->prepare("SELECT COALESCE(performer_confirmed.id, performer_booking.id, performer_followup.id) AS sid, COUNT(*) AS cnt
                FROM customers c
                LEFT JOIN meeting_bookings mb ON mb.id = (
                    SELECT mb2.id FROM meeting_bookings mb2
                    WHERE mb2.customer_id = c.id
                    ORDER BY mb2.created_at DESC
                    LIMIT 1
                )
                LEFT JOIN users performer_booking ON performer_booking.id = mb.staff_id
                LEFT JOIN meeting_verifications mv ON mv.id = (
                    SELECT mv2.id
                    FROM meeting_verifications mv2
                    WHERE mv2.customer_id = c.id
                      AND mv2.status = 'confirmed'
                    ORDER BY mv2.created_at DESC
                    LIMIT 1
                )
                LEFT JOIN users performer_confirmed ON performer_confirmed.id = mv.conductor_id
                LEFT JOIN followups legacy_meeting_followup ON legacy_meeting_followup.id = (
                    SELECT f2.id FROM followups f2
                    WHERE f2.customer_id = c.id
                      AND f2.status_after = 'جلسه برگزار شد'
                    ORDER BY f2.created_at DESC
                    LIMIT 1
                )
                LEFT JOIN users performer_followup ON performer_followup.id = legacy_meeting_followup.created_by
                WHERE c.status = 'جلسه برگزار شد'
                  AND COALESCE(c.meeting_held_at, c.updated_at) >= ? AND COALESCE(c.meeting_held_at, c.updated_at) < ?
                GROUP BY sid");
            $mtStmt->execute([$yesterdayYmd . ' 00:00:00', date('Y-m-d') . ' 00:00:00']);
            $meetingOnly = [];
            foreach ($mtStmt->fetchAll() as $r) {
                if ($r['sid'] === null) {
                    continue;
                }
                $sid = (int) $r['sid'];
                if (isset($topById[$sid])) {
                    $topById[$sid]['meetings'] = (int) $r['cnt'];
                } else {
                    $meetingOnly[$sid] = (int) $r['cnt'];
                }
            }
            if ($meetingOnly) {
                $ph = implode(',', array_fill(0, count($meetingOnly), '?'));
                $uStmt = $pdo->prepare("SELECT id, full_name, role FROM users WHERE id IN ($ph) AND role <> 'admin'");
                $uStmt->execute(array_keys($meetingOnly));
                foreach ($uStmt->fetchAll() as $u) {
                    $topById[(int) $u['id']] = [
                        'id' => (int) $u['id'], 'full_name' => $u['full_name'], 'role' => $u['role'],
                        'connected_calls' => 0, 'total_seconds' => 0, 'meetings' => $meetingOnly[(int) $u['id']],
                    ];
                }
            }
        } catch (Throwable $e) {
        }

        $topCallers = array_values($topById);
        usort($topCallers, static function ($a, $b) {
            return [$b['total_seconds'], $b['meetings'], $b['connected_calls'], $a['id']]
               <=> [$a['total_seconds'], $a['meetings'], $a['connected_calls'], $b['id']];
        });
        return array_slice($topCallers, 0, 10);
    });
} else {
    $stmt = $pdo->prepare("SELECT c.* FROM customers c
                           WHERE c.owner_user_id = ? AND c.next_followup_date <= CURDATE() AND c.status NOT IN ('انصرافی','نامرتبط','خرید کرده')
                           ORDER BY c.next_followup_date ASC LIMIT 8");
    $stmt->execute([$user['id']]);
    $dueList = $stmt->fetchAll();
}

$pageTitle = 'داشبورد';
require_once __DIR__ . '/includes/layout_top.php';
?>

<style>
.dash-page{--dp-line:#e7e2d3;--dp-ink:#1c1917;--dp-muted:#78716c;--dp-gold:#c9a24b;--dp-gold-2:#f1dfa8;}

.dash-page .dashboard-greeting{
  display:flex;align-items:center;gap:.5rem;font-weight:800;color:var(--dp-ink);font-size:1.12rem;
}
.dash-page .dashboard-greeting small{color:var(--dp-muted);font-weight:600}

.dash-page .stat-card{
  display:block;border-radius:18px;padding:1.1rem 1.2rem;color:#fff;text-decoration:none;position:relative;overflow:hidden;
  box-shadow:0 10px 26px -16px rgba(28,25,23,.45);transition:.18s ease;
}
.dash-page .stat-card::after{
  content:'';position:absolute;inset:0;pointer-events:none;
  background:radial-gradient(circle at 100% -20%, rgba(255,255,255,.22), transparent 55%);
}
.dash-page .stat-card:hover{transform:translateY(-3px);box-shadow:0 16px 34px -16px rgba(28,25,23,.5);color:#fff}
.dash-page .stat-icon{font-size:1.3rem;opacity:.9;margin-bottom:.5rem;display:block;position:relative;z-index:1}
.dash-page .stat-number{font-size:1.55rem;font-weight:800;position:relative;z-index:1;line-height:1.2}
.dash-page .stat-number-date{font-size:1.15rem}
.dash-page .stat-label{font-size:.78rem;opacity:.92;position:relative;z-index:1;margin-top:.2rem}

.dash-page .bg-grad-blue{background:linear-gradient(135deg,#1d4ed8,#0b0f1a)}
.dash-page .bg-grad-orange{background:linear-gradient(135deg,var(--dp-gold),#7c5a12)}
.dash-page .bg-grad-red{background:linear-gradient(135deg,#dc2626,#450a0a)}
.dash-page .bg-grad-purple{background:linear-gradient(135deg,#7c3aed,#241d0a)}
.dash-page .bg-grad-green{background:linear-gradient(135deg,#0f766e,#0b0f1a)}

.dash-page .card{border:1px solid var(--dp-line);border-radius:20px;box-shadow:0 4px 20px -16px rgba(28,25,23,.3);}
.dash-page .chart-card h6{font-weight:800;color:var(--dp-ink)}

.dash-page .unit-stacked-bar{display:flex;height:14px;border-radius:999px;overflow:hidden;background:#f3f1ea;border:1px solid var(--dp-line)}
.dash-page .unit-stacked-seg{height:100%}
.dash-page .chart-legend-item{display:flex;justify-content:space-between;align-items:center;gap:1rem;padding:.35rem 0;font-size:.85rem;border-bottom:1px solid #f3f1ea}
.dash-page .chart-legend-item:last-child{border-bottom:none}
.dash-page .chart-legend-dot{display:inline-block;width:9px;height:9px;border-radius:50%;margin-left:.4rem}

.dash-page .card > .d-flex h6, .dash-page .card > h6{
  display:flex;align-items:center;gap:.5rem;font-weight:800;color:var(--dp-ink);
}
.dash-page .card i.fa-phone-volume, .dash-page .card i.fa-bell{
  width:30px;height:30px;border-radius:9px;display:inline-flex;align-items:center;justify-content:center;
  background:linear-gradient(135deg,#0b0f1a 0%,#3d3220 55%,var(--dp-gold) 130%);color:#fff;font-size:.8rem;
}
.dash-page .btn-outline-primary{
  border-radius:10px;font-weight:700;border-color:var(--dp-gold);color:#8a6a1e;
}
.dash-page .btn-outline-primary:hover{background:linear-gradient(135deg,var(--dp-gold-2),var(--dp-gold));border-color:transparent;color:#241d0a}

.dash-page table{font-size:.85rem}
.dash-page table thead th{background:#faf9f5;color:#78716c;font-weight:700;font-size:.74rem;border-bottom:1px solid var(--dp-line)}
.dash-page table tbody td{border-bottom:1px solid #f3f1ea;vertical-align:middle}
.dash-page table tbody tr:hover{background:#faf8f2}
.dash-page .badge{border-radius:999px;font-weight:700}
.dash-page .badge-status{padding:.35rem .8rem;font-size:.72rem}
.dash-page .progress{border-radius:999px;background:#f3f1ea}
.dash-page .progress-bar{background:linear-gradient(90deg,var(--dp-gold-2),var(--dp-gold))}

.dash-page .table-row-overdue{background:#fef2f2}
.dash-page .table-row-today{background:#fffbeb}

@media (min-width:768px){
  .dash-page .due-followup-table th, .dash-page .due-followup-table td{text-align:center !important; vertical-align:middle}
  .dash-page .due-followup-table th:first-child, .dash-page .due-followup-table td:first-child{text-align:right !important}
  .dash-page .due-followup-table .due-mobile-col,
  .dash-page .due-followup-table .due-owner-col,
  .dash-page .due-followup-table .due-status-col,
  .dash-page .due-followup-table .due-date-col,
  .dash-page .due-followup-table .due-action-col{white-space:nowrap; width:1%}
}
.dash-page .btn-primary{
  border:none;border-radius:10px;background:linear-gradient(135deg,var(--dp-gold-2),var(--dp-gold));color:#241d0a;
  box-shadow:0 6px 14px -6px rgba(201,162,75,.6);
}

@media (max-width:767.98px){
  .dash-page .card{border-radius:16px}
  .dash-page .stat-card{padding:.9rem .95rem;border-radius:14px}
  .dash-page .stat-number{font-size:1.25rem}
  .dash-page .stat-label{font-size:.72rem}
  .dash-page table{font-size:.78rem}
}
</style>

<div class="dash-page">

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <h5 class="mb-0 dashboard-greeting">
    سلام <?= e($user['full_name']) ?> 👋
    <small>(<?= e(role_letter($user['role'])) ?>)</small>
  </h5>
</div>

<div class="row g-3 mb-4">
  <div class="col-6 col-md-3">
    <a href="customer_list.php" class="stat-card bg-grad-blue">
      <i class="fa-solid fa-users stat-icon"></i>
      <div class="stat-number"><?= to_persian_digits(number_format($totalCustomers)) ?></div>
      <div class="stat-label">تعداد کل مشتریان<?= $isAdmin ? ' (کل سیستم)' : '' ?></div>
    </a>
  </div>
  <div class="col-6 col-md-3">
    <a href="customer_list.php?filter=today" class="stat-card bg-grad-orange">
      <i class="fa-solid fa-clock stat-icon"></i>
      <div class="stat-number"><?= to_persian_digits(number_format($todayCount)) ?></div>
      <div class="stat-label">سررسید پیگیری امروز</div>
    </a>
  </div>
  <div class="col-6 col-md-3">
    <a href="customer_list.php?filter=tomorrow" class="stat-card bg-grad-blue">
      <i class="fa-solid fa-calendar-day stat-icon"></i>
      <div class="stat-number"><?= to_persian_digits(number_format($tomorrowCount)) ?></div>
      <div class="stat-label">سررسید پیگیری فردا</div>
    </a>
  </div>
  <div class="col-6 col-md-3">
    <a href="customer_list.php?filter=overdue" class="stat-card bg-grad-red">
      <i class="fa-solid fa-triangle-exclamation stat-icon"></i>
      <div class="stat-number"><?= to_persian_digits(number_format($overdueCount)) ?></div>
      <div class="stat-label">سررسید گذشته (عقب‌افتاده)</div>
    </a>
  </div>
  <div class="col-6 col-md-3">
    <?php if ($isAdmin): ?>
      <a href="admin/admin_users.php" class="stat-card bg-grad-purple">
        <i class="fa-solid fa-user-tie stat-icon"></i>
        <div class="stat-number"><?= to_persian_digits(number_format($totalStaff)) ?></div>
        <div class="stat-label">تعداد کارشناس‌های فعال</div>
      </a>
    <?php else: ?>
      <div class="stat-card bg-grad-green">
        <i class="fa-regular fa-calendar-check stat-icon"></i>
        <div class="stat-number stat-number-date"><?= today_jalali() ?></div>
        <div class="stat-label">امروز</div>
      </div>
    <?php endif; ?>
  </div>
</div>

<div class="row g-3 mb-4">
  <div class="col-lg-7">
    <div class="card chart-card h-100 compact-text p-3">
      <h6 class="mb-3">روند مشتریان جدید (۶ ماه اخیر)<?= $isAdmin ? ' - کل سیستم' : '' ?></h6>
      <canvas id="trendChart" height="150"></canvas>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="card chart-card h-100 compact-text p-3">
      <?php if ($isAdmin): ?>
        <h6 class="mb-3">تفکیک مشتریان بر اساس واحد</h6>
        <?php
          $unitTotal = max(1, (int) $unitCounts['A'] + (int) $unitCounts['B'] + (int) $unitCounts['C']);
          $unitPctA = round($unitCounts['A'] * 100 / $unitTotal, 1);
          $unitPctB = round($unitCounts['B'] * 100 / $unitTotal, 1);
          $unitPctC = round($unitCounts['C'] * 100 / $unitTotal, 1);
        ?>
        <div class="unit-stacked-bar">
          <?php if ($unitCounts['A'] > 0): ?><div class="unit-stacked-seg" style="width:<?= $unitPctA ?>%;background:#3b82f6" title="واحد A: <?= to_persian_digits((string) $unitPctA) ?>٪"></div><?php endif; ?>
          <?php if ($unitCounts['B'] > 0): ?><div class="unit-stacked-seg" style="width:<?= $unitPctB ?>%;background:#34d399" title="واحد B: <?= to_persian_digits((string) $unitPctB) ?>٪"></div><?php endif; ?>
          <?php if ($unitCounts['C'] > 0): ?><div class="unit-stacked-seg" style="width:<?= $unitPctC ?>%;background:#fbbf24" title="واحد C: <?= to_persian_digits((string) $unitPctC) ?>٪"></div><?php endif; ?>
        </div>
        <div class="unit-chart-legend mt-3">
          <div class="chart-legend-item"><span><span class="chart-legend-dot" style="background:#3b82f6"></span>واحد A</span><b><?= to_persian_digits((string) $unitCounts['A']) ?> (<?= to_persian_digits((string) $unitPctA) ?>٪)</b></div>
          <div class="chart-legend-item"><span><span class="chart-legend-dot" style="background:#34d399"></span>واحد B</span><b><?= to_persian_digits((string) $unitCounts['B']) ?> (<?= to_persian_digits((string) $unitPctB) ?>٪)</b></div>
          <div class="chart-legend-item"><span><span class="chart-legend-dot" style="background:#fbbf24"></span>واحد C</span><b><?= to_persian_digits((string) $unitCounts['C']) ?> (<?= to_persian_digits((string) $unitPctC) ?>٪)</b></div>
        </div>
      <?php else: ?>
        <h6 class="mb-3">تفکیک وضعیت مشتریان شما</h6>
        <?php
          $palette = ['#3b82f6', '#fbbf24', '#34d399', '#f87171', '#a78bfa', '#2dd4bf', '#f472b6', '#94a3b8', '#fb923c', '#22d3ee'];
          $statusList = status_options_for_role($user['role']);
          $statusTotal = max(1, array_sum(array_map(fn($s) => (int) ($statusCounts[$s] ?? 0), $statusList)));
        ?>
        <div class="unit-stacked-bar">
          <?php foreach ($statusList as $i => $st):
              $cnt = (int) ($statusCounts[$st] ?? 0);
              if ($cnt <= 0) { continue; }
              $pct = round($cnt * 100 / $statusTotal, 1);
              $color = $palette[$i % count($palette)];
          ?>
            <div class="unit-stacked-seg" style="width:<?= $pct ?>%;background:<?= $color ?>" title="<?= e($st) ?>: <?= to_persian_digits((string) $pct) ?>٪"></div>
          <?php endforeach; ?>
        </div>
        <div class="mt-3">
          <?php foreach ($statusList as $i => $st): ?>
            <div class="chart-legend-item">
              <span><span class="chart-legend-dot" style="background:<?= $palette[$i % count($palette)] ?>"></span><?= e($st) ?></span>
              <b><?= to_persian_digits(number_format($statusCounts[$st] ?? 0)) ?></b>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php if ($isAdmin): ?>
<?php
  $fmtCallDuration = static function (int $sec): string {
      $h = intdiv($sec, 3600);
      $m = intdiv($sec % 3600, 60);
      $sc = $sec % 60;
      if ($h > 0) {
          return to_persian_digits($h . ' ساعت' . ($m > 0 ? ' و ' . $m . ' دقیقه' : ''));
      }
      if ($m > 0) {
          return to_persian_digits($m . ' دقیقه' . ($sc > 0 ? ' و ' . $sc . ' ثانیه' : ''));
      }
      return to_persian_digits($sc . ' ثانیه');
  };
  $topMaxSeconds = $topCallers ? max(1, (int) $topCallers[0]['total_seconds']) : 1;
?>
<div class="card p-3 compact-text">
  <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h6 class="mb-0">
      <i class="fa-solid fa-phone-volume"></i> بیشترین مدت تماس دیروز
      <small class="text-muted">(<?= to_jalali($yesterdayYmd) ?>)</small>
    </h6>
    <a href="admin/admin_staff_report.php" class="btn btn-sm btn-outline-primary">گزارش کارکنان</a>
  </div>
  <?php if (!$topCallers): ?>
    <p class="text-muted mb-0">دیروز هیچ تماس برقرارشده‌ای با مشتریان برای کارشناس‌ها ثبت نشده است.</p>
  <?php else: ?>
  <div class="table-responsive">
    <table class="table table-hover table-compact align-middle mb-0">
      <thead>
        <tr>
          <th>کارشناس</th>
          <th>واحد</th>
          <th class="text-center">تماس برقرار شده</th>
          <th class="text-center">جلسات برگزار شده</th>
          <th style="min-width:11rem">مدت مکالمه</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($topCallers as $t):
            $pct = round(((int) $t['total_seconds']) * 100 / $topMaxSeconds);
        ?>
        <tr>
          <td>
            <a href="admin/admin_staff_report.php?staff=<?= (int) $t['id'] ?>&amp;preset=yesterday" class="text-decoration-none"><?= e($t['full_name']) ?></a>
          </td>
          <td><?= e((string) $t['role']) ?></td>
          <td class="text-center"><?= to_persian_digits(number_format((int) $t['connected_calls'])) ?></td>
          <td class="text-center"><?= to_persian_digits(number_format((int) $t['meetings'])) ?></td>
          <td>
            <div class="fw-semibold"><?= (int) $t['total_seconds'] > 0 ? e($fmtCallDuration((int) $t['total_seconds'])) : '—' ?></div>
            <div class="progress mt-1" style="height:5px" role="progressbar" aria-valuenow="<?= (int) $pct ?>" aria-valuemin="0" aria-valuemax="100">
              <div class="progress-bar" style="width:<?= (int) $pct ?>%"></div>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="text-muted small mt-2">فقط مکالمه با مخاطبین نوع «مشتری» و تماس‌های برقرارشده (بیش از ۱۰ ثانیه) حساب شده؛ تماس‌های بی‌پاسخ نمایش داده نمی‌شوند. با کلیک روی نام کارشناس، جزئیات دیروز او در «گزارش کارکنان» باز می‌شود.</div>
  <?php endif; ?>
</div>
<?php else: ?>
<div class="card p-3 compact-text">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h6 class="mb-0"><i class="fa-solid fa-bell"></i> سررسیدهای نیازمند پیگیری فوری</h6>
    <a href="customer_list.php?filter=due" class="btn btn-sm btn-outline-primary">مشاهده همه</a>
  </div>
  <?php if (!$dueList): ?>
    <p class="text-muted mb-0">در حال حاضر سررسید معوقی وجود ندارد. 🎉</p>
  <?php else: ?>
  <div class="table-responsive due-followup-table-wrap">
    <table class="table table-hover table-compact align-middle mb-0 due-followup-table">
      <thead>
        <tr>
          <th>نام مشتری</th>
          <th class="due-mobile-col">موبایل</th>
          <?php if ($isAdmin): ?><th class="due-owner-col">کارشناس</th><?php endif; ?>
          <th class="due-status-col">وضعیت</th>
          <th class="due-date-col">سررسید</th>
          <th class="due-action-col"></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($dueList as $c):
            $isOverdue = $c['next_followup_date'] < date('Y-m-d');
            $rowClass  = $isOverdue ? 'table-row-overdue' : 'table-row-today';
        ?>
        <tr class="<?= $rowClass ?>">
          <td><?= e($c['full_name']) ?></td>
          <td dir="ltr" class="due-mobile-col"><?= e($c['mobile']) ?></td>
          <?php if ($isAdmin): ?><td class="due-owner-col"><?= e($c['owner_name']) ?></td><?php endif; ?>
          <td class="due-status-col"><span class="badge badge-status <?= status_badge_class($c['status']) ?>"><?= e($c['status']) ?></span></td>
          <td class="due-date-col"><?= to_jalali($c['next_followup_date']) ?> <?= $isOverdue ? '<span class="badge bg-danger">عقب‌افتاده</span>' : '<span class="badge bg-warning text-dark">امروز</span>' ?></td>
          <td class="due-action-col"><a href="customer_view.php?id=<?= (int)$c['id'] ?>&amp;from=dashboard.php" class="btn btn-sm btn-primary" title="مشاهده"><i class="fa-solid fa-eye"></i></a></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script>
function toPersianDigits(input) {
  var en = ['0','1','2','3','4','5','6','7','8','9'];
  var fa = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
  var s = String(input);
  for (var i = 0; i < 10; i++) {
    s = s.split(en[i]).join(fa[i]);
  }
  return s;
}
function faTick(value) {
  return toPersianDigits(Number(value).toLocaleString('en-US'));
}

new Chart(document.getElementById('trendChart'), {
  type: 'line',
  data: {
    labels: <?= json_encode($trendLabels, JSON_UNESCAPED_UNICODE) ?>,
    datasets: [{
      label: 'مشتریان جدید',
      data: <?= json_encode($trendValues) ?>,
      borderColor: '#c9a24b',
      backgroundColor: 'rgba(201,162,75,.14)',
      tension: .4,
      fill: true,
      pointRadius: 4,
      pointBackgroundColor: '#c9a24b'
    }]
  },
  options: {
    plugins: {
      legend: { display: false },
      tooltip: { callbacks: { label: (ctx) => toPersianDigits(ctx.formattedValue) } }
    },
    scales: { y: { beginAtZero: true, ticks: { precision: 0, callback: faTick } } }
  }
});

</script>

<?php require_once __DIR__ . '/includes/layout_bottom.php'; ?>