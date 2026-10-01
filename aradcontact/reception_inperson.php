<?php
/**
 * مصاحبه‌های حضوری — پیدا کردنِ متقاضیانی که برای مصاحبه‌ی حضوری ثبت شده‌اند.
 *  - ادمین/مدیرِ پذیرش (مجوز admin_reception_inperson): همه‌ی ثبت‌ها
 *  - کارشناسِ پذیرش (reception_agent_panel): فقط ثبت‌های خودش
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/reception_functions.php';
$user = require_login();
$pdo = db();

$canAll = user_can('admin_reception_inperson', $user);
$ready = reception_module_ready($pdo) && reception_inperson_table_ready($pdo);
$statuses = reception_inperson_statuses();

// ─── تغییرِ سریعِ وضعیت ───
if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'set_status') {
    if (!csrf_verify()) {
        flash_set('danger', 'نشست منقضی شده است.');
    } else {
        $ok = reception_update_inperson_status(
            $pdo,
            (int) ($_POST['interview_id'] ?? 0),
            (string) ($_POST['iv_status'] ?? ''),
            (int) $user['id'],
            trim((string) ($_POST['result_note'] ?? '')),
            $canAll ? null : (int) $user['id']
        );
        flash_set($ok ? 'success' : 'danger', $ok ? 'وضعیتِ مصاحبه به‌روز شد.' : 'تغییرِ وضعیت انجام نشد.');
    }
    $qs = $_GET;
    redirect('reception_inperson.php' . ($qs ? '?' . http_build_query($qs) : ''));
}

// ─── فیلترها ───
$preset = (string) ($_GET['preset'] ?? 'this_month');
$today = date('Y-m-d');
switch ($preset) {
    case 'today':     $from = $to = $today; break;
    case 'tomorrow':  $from = $to = date('Y-m-d', strtotime('+1 day')); break;
    case 'upcoming':  $from = $today; $to = date('Y-m-d', strtotime('+60 days')); break;
    case 'this_week': $from = date('Y-m-d', strtotime('-' . ((int) date('N') % 7) . ' days')); $to = date('Y-m-d', strtotime($from . ' +6 days')); break;
    case 'last_month':$from = date('Y-m-01', strtotime('first day of last month')); $to = date('Y-m-t', strtotime('last day of last month')); break;
    case 'all':       $from = ''; $to = ''; break;
    case 'custom':
        $from = (string) (to_gregorian((string) ($_GET['from'] ?? '')) ?? '');
        $to   = (string) (to_gregorian((string) ($_GET['to'] ?? '')) ?? '');
        break;
    case 'this_month':
    default:
        $preset = 'this_month';
        $from = date('Y-m-01');
        $to = date('Y-m-t');
}
$filters = [
    'from' => $from, 'to' => $to,
    'agent_id' => $canAll ? (int) ($_GET['agent_id'] ?? 0) : (int) $user['id'],
    'supervisor_id' => (int) ($_GET['supervisor_id'] ?? 0),
    'status' => isset($statuses[$_GET['status'] ?? '']) ? (string) $_GET['status'] : '',
    'q' => trim((string) ($_GET['q'] ?? '')),
    'include_cancelled' => true,
];

$rows = [];
$total = 0;
$byStatus = array_fill_keys(array_keys($statuses), 0);
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 25;
$agents = [];
$supervisors = [];

if ($ready) {
    $params = [];
    $where = reception_inperson_filters_sql($filters, $params);
    $base = "FROM reception_inperson_interviews ii
             JOIN reception_applicants ra ON ra.id = ii.applicant_id
             LEFT JOIN users ag ON ag.id = ii.agent_user_id
             LEFT JOIN users su ON su.id = COALESCE(ii.supervisor_user_id, ra.supervisor_user_id)
             WHERE $where";

    // شمارش به تفکیکِ وضعیت (بدونِ فیلترِ وضعیت، برای کارت‌ها)
    $sp = [];
    $sf = $filters; $sf['status'] = '';
    $sw = reception_inperson_filters_sql($sf, $sp);
    $st = $pdo->prepare("SELECT ii.status, COUNT(*) FROM reception_inperson_interviews ii JOIN reception_applicants ra ON ra.id = ii.applicant_id WHERE $sw GROUP BY ii.status");
    $st->execute($sp);
    foreach ($st->fetchAll(PDO::FETCH_KEY_PAIR) ?: [] as $k => $c) {
        if (isset($byStatus[$k])) $byStatus[$k] = (int) $c;
    }

    $st = $pdo->prepare("SELECT COUNT(*) $base");
    $st->execute($params);
    $total = (int) $st->fetchColumn();

    $selectCols = "ii.*, ra.first_name, ra.last_name, ra.mobile, ra.status AS applicant_status, ag.full_name AS agent_name, su.full_name AS supervisor_name";

    // خروجیِ اکسل (CSV)
    if (isset($_GET['export'])) {
        $st = $pdo->prepare("SELECT $selectCols $base ORDER BY ii.interview_date DESC, ii.interview_time DESC LIMIT 20000");
        $st->execute($params);
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="inperson_interviews_' . date('Ymd_His') . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['تاریخ مصاحبه', 'ساعت', 'نام متقاضی', 'موبایل', 'وضعیت مصاحبه', 'مصاحبه‌کننده/سرپرست', 'ثبت‌کننده', 'محل', 'یادداشت', 'نتیجه', 'تاریخ ثبت']);
        foreach ($st as $r) {
            fputcsv($out, [
                to_jalali($r['interview_date']), $r['interview_time'] ? substr((string) $r['interview_time'], 0, 5) : '',
                trim($r['first_name'] . ' ' . $r['last_name']), $r['mobile'], reception_inperson_status_label((string) $r['status']),
                $r['supervisor_name'] ?? '', $r['agent_name'] ?? '', $r['location'] ?? '', $r['notes'] ?? '', $r['result_note'] ?? '',
                to_jalali($r['created_at']),
            ]);
        }
        fclose($out);
        exit;
    }

    $pages = max(1, (int) ceil($total / $perPage));
    $page = min($page, $pages);
    $offset = ($page - 1) * $perPage;
    $st = $pdo->prepare("SELECT $selectCols $base ORDER BY (ii.status = 'scheduled') DESC, ii.interview_date ASC, ii.interview_time ASC, ii.id DESC LIMIT $perPage OFFSET $offset");
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if ($canAll) {
        $agents = $pdo->query("SELECT DISTINCT u.id, u.full_name FROM reception_inperson_interviews ii JOIN users u ON u.id = ii.agent_user_id ORDER BY u.full_name")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
    $supervisors = $pdo->query("SELECT id, full_name FROM users WHERE role = 'leader' AND is_active = 1 ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
$pages = $pages ?? 1;

$pageTitle = 'مصاحبه‌های حضوری';
require_once __DIR__ . '/includes/layout_top.php';
?>
<style>
.rip{--l:#e7e2d3;--g:#c9a24b;--g2:#f1dfa8}
.rip .hero{background:linear-gradient(135deg,#0b0f1a 0%,#3d3220 55%,var(--g) 130%);border-radius:18px;padding:18px 22px;color:#f6efdd;margin-bottom:16px;display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:center}
.rip .hero h5{margin:0;font-weight:800;color:#f6efdd}.rip .hero p{margin:.25rem 0 0;font-size:.78rem;color:#e7ddc4}
.rip .card{border:1px solid var(--l);border-radius:16px;box-shadow:0 4px 16px -14px rgba(28,25,23,.3)}
.rip .kpi{border:1px solid var(--l);border-radius:14px;background:#fff;padding:12px;text-align:center;text-decoration:none;color:inherit;display:block;height:100%}
.rip .kpi.active{outline:2px solid var(--g)}
.rip .kpi .n{font-weight:800;font-size:1.4rem}
.rip .chip{border:1px solid var(--l);border-radius:20px;padding:.25rem .8rem;font-size:.78rem;color:#1c1917;background:#fff;text-decoration:none;display:inline-block}
.rip .chip.active{background:linear-gradient(135deg,var(--g2),var(--g));border-color:transparent;font-weight:700}
.rip table thead th{background:#f8f0db;font-size:.78rem;white-space:nowrap}
.rip table td{font-size:.8rem;vertical-align:middle}
.rip .btn-gold{background:linear-gradient(135deg,var(--g2),var(--g));border:none;color:#241708;font-weight:700}
</style>

<div class="rip">
  <div class="hero">
    <div>
      <h5><i class="fa-solid fa-building-user"></i> مصاحبه‌های حضوری</h5>
      <p><?= $canAll ? 'همه‌ی متقاضیانی که برای مصاحبه‌ی حضوری ثبت شده‌اند — جدا از میتینگ‌های آنلاین.' : 'متقاضیانی که شما برایشان مصاحبه‌ی حضوری ثبت کرده‌اید.' ?></p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
      <?php if ($canAll && user_can('admin_reception_reports', $user)): ?><a href="admin/admin_reception_reports.php" class="btn btn-sm btn-outline-light"><i class="fa-solid fa-chart-column"></i> آمار پذیرش</a><?php endif; ?>
      <?php if (user_can('reception_agent_panel', $user)): ?><a href="reception_meetings.php" class="btn btn-sm btn-outline-light"><i class="fa-solid fa-video"></i> میتینگ‌های آنلاین</a><?php endif; ?>
      <a href="<?= $canAll && user_can('admin_reception_hub', $user) ? 'admin/admin_reception_hub.php' : 'reception_dashboard.php' ?>" class="btn btn-sm btn-outline-light"><i class="fa-solid fa-arrow-right"></i> بازگشت</a>
    </div>
  </div>

  <?php if (!$ready): ?>
    <div class="alert alert-warning">ماژولِ پذیرش هنوز روی سرور فعال نشده است.</div>
  <?php else: ?>

  <div class="row g-2 mb-3">
    <?php
    $qsBase = $_GET; unset($qsBase['status'], $qsBase['page']);
    $allCnt = array_sum($byStatus);
    ?>
    <div class="col-6 col-md">
      <a class="kpi <?= $filters['status'] === '' ? 'active' : '' ?>" href="?<?= e(http_build_query($qsBase)) ?>"><div class="n"><?= to_persian_digits((string) $allCnt) ?></div><div class="small text-muted">همه</div></a>
    </div>
    <?php foreach ($statuses as $code => $meta): $q2 = $qsBase; $q2['status'] = $code; ?>
      <div class="col-6 col-md">
        <a class="kpi <?= $filters['status'] === $code ? 'active' : '' ?>" href="?<?= e(http_build_query($q2)) ?>">
          <div class="n text-<?= e($meta['color']) ?>"><?= to_persian_digits((string) $byStatus[$code]) ?></div>
          <div class="small text-muted"><i class="fa-solid <?= e($meta['icon']) ?>"></i> <?= e($meta['label']) ?></div>
        </a>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="card p-3 mb-3">
    <form method="get" class="row g-2 align-items-end">
      <div class="col-12 d-flex gap-2 flex-wrap mb-1">
        <?php foreach (['today' => 'امروز', 'tomorrow' => 'فردا', 'this_week' => 'این هفته', 'upcoming' => 'آینده (۶۰ روز)', 'this_month' => 'این ماه', 'last_month' => 'ماه گذشته', 'all' => 'همه', 'custom' => 'بازه دلخواه'] as $pk => $pl):
            $q3 = $_GET; $q3['preset'] = $pk; unset($q3['page']); ?>
          <a class="chip <?= $preset === $pk ? 'active' : '' ?>" href="?<?= e(http_build_query($q3)) ?>"><?= $pl ?></a>
        <?php endforeach; ?>
      </div>
      <input type="hidden" name="preset" value="<?= e($preset) ?>">
      <?php if ($filters['status'] !== ''): ?><input type="hidden" name="status" value="<?= e($filters['status']) ?>"><?php endif; ?>
      <?php if ($preset === 'custom'): ?>
        <div class="col-md-2"><label class="form-label small mb-1">از تاریخ</label><input name="from" class="form-control form-control-sm jalali-date" autocomplete="off" value="<?= e((string) ($_GET['from'] ?? '')) ?>" placeholder="۱۴۰۵/۰۷/۰۱"></div>
        <div class="col-md-2"><label class="form-label small mb-1">تا تاریخ</label><input name="to" class="form-control form-control-sm jalali-date" autocomplete="off" value="<?= e((string) ($_GET['to'] ?? '')) ?>" placeholder="۱۴۰۵/۰۷/۳۰"></div>
      <?php endif; ?>
      <div class="col-md-3"><label class="form-label small mb-1">جستجو (نام/موبایل)</label><input name="q" value="<?= e($filters['q']) ?>" class="form-control form-control-sm"></div>
      <?php if ($canAll): ?>
      <div class="col-md-2"><label class="form-label small mb-1">ثبت‌کننده</label>
        <select name="agent_id" class="form-select form-select-sm"><option value="0">همه</option>
          <?php foreach ($agents as $a): ?><option value="<?= (int) $a['id'] ?>" <?= $filters['agent_id'] === (int) $a['id'] ? 'selected' : '' ?>><?= e($a['full_name']) ?></option><?php endforeach; ?>
        </select></div>
      <?php endif; ?>
      <div class="col-md-2"><label class="form-label small mb-1">سرپرست</label>
        <select name="supervisor_id" class="form-select form-select-sm"><option value="0">همه</option>
          <?php foreach ($supervisors as $sv): ?><option value="<?= (int) $sv['id'] ?>" <?= $filters['supervisor_id'] === (int) $sv['id'] ? 'selected' : '' ?>><?= e($sv['full_name']) ?></option><?php endforeach; ?>
        </select></div>
      <div class="col-md-auto d-flex gap-2">
        <button class="btn btn-sm btn-gold">اعمال</button>
        <a class="btn btn-sm btn-outline-success" href="?<?= e(http_build_query(array_merge($_GET, ['export' => 1]))) ?>"><i class="fa-solid fa-file-csv"></i> خروجی اکسل</a>
      </div>
    </form>
  </div>

  <div class="card p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead><tr><th>تاریخ و ساعت</th><th>متقاضی</th><th>موبایل</th><th>وضعیت مصاحبه</th><th>سرپرست</th><?php if ($canAll): ?><th>ثبت‌کننده</th><?php endif; ?><th>محل / یادداشت</th><th>نتیجه</th><th></th></tr></thead>
        <tbody>
        <?php if (!$rows): ?><tr><td colspan="9" class="text-center text-muted py-4">موردی در این بازه پیدا نشد.</td></tr><?php endif; ?>
        <?php foreach ($rows as $r):
            $meta = $statuses[$r['status']] ?? ['label' => $r['status'], 'color' => 'secondary', 'icon' => 'fa-circle'];
            $isPast = $r['interview_date'] < $today;
        ?>
          <tr>
            <td class="text-nowrap"><b><?= to_jalali($r['interview_date']) ?></b><?php if ($r['interview_time']): ?><br><span class="text-muted" dir="ltr"><?= e(substr((string) $r['interview_time'], 0, 5)) ?></span><?php endif; ?>
              <?php if ($r['status'] === 'scheduled' && $isPast): ?><br><span class="badge text-bg-light border text-danger">نتیجه ثبت نشده</span><?php endif; ?></td>
            <td class="fw-semibold"><a class="text-decoration-none" href="reception_applicant.php?id=<?= (int) $r['applicant_id'] ?>"><?= e(trim($r['first_name'] . ' ' . $r['last_name'])) ?></a></td>
            <td dir="ltr"><?= e($r['mobile']) ?></td>
            <td><span class="badge text-bg-<?= e($meta['color']) ?>"><i class="fa-solid <?= e($meta['icon']) ?>"></i> <?= e($meta['label']) ?></span></td>
            <td><?= e($r['supervisor_name'] ?? '—') ?></td>
            <?php if ($canAll): ?><td><?= e($r['agent_name'] ?? '—') ?></td><?php endif; ?>
            <td class="small"><?= e((string) ($r['location'] ?? '')) ?><?php if (!empty($r['notes'])): ?><div class="text-muted"><?= e(mb_strimwidth((string) $r['notes'], 0, 80, '…')) ?></div><?php endif; ?></td>
            <td class="small"><?= e((string) ($r['result_note'] ?? '')) ?></td>
            <td class="text-nowrap">
              <?php if ($canAll || (int) $r['agent_user_id'] === (int) $user['id']): ?>
              <form method="post" class="d-inline-flex gap-1">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="set_status">
                <input type="hidden" name="interview_id" value="<?= (int) $r['id'] ?>">
                <?php foreach ($statuses as $code => $m): if ($code === $r['status']) continue; ?>
                  <button class="btn btn-sm btn-outline-<?= e($m['color']) ?>" name="iv_status" value="<?= e($code) ?>" title="<?= e($m['label']) ?>"><i class="fa-solid <?= e($m['icon']) ?>"></i></button>
                <?php endforeach; ?>
              </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <?php if ($pages > 1): ?>
    <nav class="mt-3"><ul class="pagination pagination-sm justify-content-center flex-wrap">
      <?php for ($p = max(1, $page - 3); $p <= min($pages, $page + 3); $p++): $q4 = $_GET; $q4['page'] = $p; ?>
        <li class="page-item <?= $p === $page ? 'active' : '' ?>"><a class="page-link" href="?<?= e(http_build_query($q4)) ?>"><?= to_persian_digits((string) $p) ?></a></li>
      <?php endfor; ?>
    </ul><div class="text-center small text-muted">مجموع <?= to_persian_digits((string) $total) ?> مورد</div></nav>
  <?php endif; ?>
  <?php endif; ?>
</div>
<?php require_once __DIR__ . '/includes/layout_bottom.php'; ?>
