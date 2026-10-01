<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/reception_functions.php';

$user = require_login();
$role = (string)($user['role'] ?? '');
$serviceRole = (string)($user['service_access_role'] ?? '');

if (!perm_page_allowed($user)) {
    perm_deny('', $user);
}

$pdo = db();
$moduleReady = reception_meeting_slots_ready($pdo);

$search = trim((string)($_GET['q'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 10;
$filter = (string)($_GET['filter'] ?? 'all');

$rows = [];
$total = 0;
$totalPages = 1;

if ($moduleReady) {
    $where = ["bk.status = 'booked'"];
    $params = [];

    if ($search !== '') {
        $where[] = "(CONCAT(COALESCE(a.first_name,''), ' ', COALESCE(a.last_name,'')) LIKE ?
                     OR a.mobile LIKE ?
                     OR ag.full_name LIKE ?
                     OR sv.full_name LIKE ?)";
        $like = '%' . $search . '%';
        array_push($params, $like, $like, $like, $like);
    }

    if ($filter === 'today') {
        $where[] = "s.slot_date = CURDATE()";
    } elseif ($filter === 'future') {
        $where[] = "s.slot_date >= CURDATE()";
    } elseif ($filter === 'past') {
        $where[] = "s.slot_date < CURDATE()";
    }

    $whereSql = ' WHERE ' . implode(' AND ', $where);

    try {
        $countSql = "SELECT COUNT(*)
            FROM reception_meeting_bookings bk
            INNER JOIN reception_meeting_slots s ON s.id = bk.slot_id
            LEFT JOIN reception_applicants a ON a.id = bk.applicant_id
            LEFT JOIN users ag ON ag.id = bk.agent_user_id
            LEFT JOIN users sv ON sv.id = s.supervisor_user_id
            $whereSql";
        $stmt = $pdo->prepare($countSql);
        $stmt->execute($params);
        $total = (int)$stmt->fetchColumn();

        $totalPages = max(1, (int)ceil($total / $perPage));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;

        $sql = "SELECT
                    bk.id AS booking_id,
                    bk.created_at AS booked_at,
                    s.slot_date,
                    s.start_time,
                    s.end_time,
                    sv.full_name AS supervisor_name,
                    sv.meeting_url,
                    ag.full_name AS agent_name,
                    a.first_name,
                    a.last_name,
                    a.mobile,
                    a.status AS applicant_status
                FROM reception_meeting_bookings bk
                INNER JOIN reception_meeting_slots s ON s.id = bk.slot_id
                LEFT JOIN reception_applicants a ON a.id = bk.applicant_id
                LEFT JOIN users ag ON ag.id = bk.agent_user_id
                LEFT JOIN users sv ON sv.id = s.supervisor_user_id
                $whereSql
                ORDER BY s.slot_date ASC, s.start_time ASC, bk.id ASC
                LIMIT $perPage OFFSET $offset";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $rows = [];
        $total = 0;
        $totalPages = 1;
    }
}

$pageTitle = 'جلسات پذیرش';
require_once __DIR__ . '/includes/layout_top.php';
?>

<style>
.rm-page{--gold:#c9a24b;--gold2:#f1dfa8;--ink:#211b12;--line:#eadfca;direction:rtl}
.rm-hero{background:linear-gradient(135deg,#0b0f1a,#3d3220 55%,#c9a24b 130%);color:#fff;border-radius:18px;padding:20px 22px;box-shadow:0 10px 28px -18px rgba(0,0,0,.5)}
.rm-hero h5{font-weight:800;color:#fff}.rm-hero p{color:#eee4cd}
.rm-card{background:#fff;border:1px solid var(--line);border-radius:18px;box-shadow:0 8px 25px -20px rgba(0,0,0,.35);overflow:hidden}
.rm-card-head{padding:14px 18px;background:linear-gradient(135deg,#fffdf8,#faf1dc);border-bottom:1px solid var(--line)}
.rm-table thead th{background:linear-gradient(135deg,#0b0f1a,#3d3220 55%,#c9a24b 130%);color:#f7f0df;border:0;white-space:nowrap}
.rm-table td{vertical-align:middle}
.rm-pill{display:inline-flex;align-items:center;gap:5px;border:1px solid var(--line);background:#fffaf0;border-radius:999px;padding:4px 9px;font-size:.78rem}
.rm-link{color:#8a6d2c;text-decoration:none;font-weight:700}.rm-link:hover{text-decoration:underline}
.rm-pagination .page-link{color:#80652a;border-color:var(--line)}.rm-pagination .active .page-link{background:linear-gradient(135deg,#c9a24b,#e6cb83);border-color:#c9a24b;color:#241d0f}
</style>

<div class="rm-page">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <a href="<?= e(user_can('admin_reception_hub', $user) ? 'admin/admin_reception_hub.php' : 'reception_dashboard.php') ?>" class="btn btn-sm btn-outline-secondary">
      <i class="fa-solid fa-arrow-right"></i> بازگشت
    </a>
    <a href="reception_inperson.php" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-building-user"></i> مصاحبه‌های حضوری</a>
  </div>

  <div class="rm-hero mb-4">
    <div class="d-flex align-items-center gap-2 mb-2">
      <i class="fa-solid fa-calendar-check fs-4"></i>
      <h5 class="mb-0">میتینگ‌های آنلاین پذیرش</h5>
    </div>
    <p class="small mb-0">فهرست رزروهای میتینگ آنلاین با سرپرست برای تمام تیم پذیرش (مصاحبه‌های حضوری در صفحه‌ی جداگانه هستند).</p>
  </div>

  <?php if (!$moduleReady): ?>
    <div class="alert alert-warning">سامانه رزرو جلسات هنوز آماده نشده است.</div>
  <?php else: ?>
    <div class="rm-card mb-3">
      <div class="rm-card-head">
        <form method="get" class="row g-2 align-items-end">
          <div class="col-md-6">
            <label class="form-label small fw-bold">جستجو</label>
            <input type="search" name="q" value="<?= e($search) ?>" class="form-control"
                   placeholder="نام متقاضی، شماره موبایل، کارشناس یا سرپرست...">
          </div>
          <div class="col-md-3">
            <label class="form-label small fw-bold">نمایش</label>
            <select name="filter" class="form-select">
              <option value="all" <?= $filter==='all'?'selected':'' ?>>همه رزروها</option>
              <option value="today" <?= $filter==='today'?'selected':'' ?>>امروز</option>
              <option value="future" <?= $filter==='future'?'selected':'' ?>>آینده</option>
              <option value="past" <?= $filter==='past'?'selected':'' ?>>گذشته</option>
            </select>
          </div>
          <div class="col-md-3 d-flex gap-2">
            <button class="btn btn-primary flex-grow-1"><i class="fa-solid fa-magnifying-glass"></i> جستجو</button>
            <?php if ($search !== '' || $filter !== 'all'): ?>
              <a href="reception_meetings.php" class="btn btn-outline-secondary">×</a>
            <?php endif; ?>
          </div>
        </form>
      </div>
    </div>

    <div class="rm-card">
      <div class="p-3 d-flex justify-content-between align-items-center">
        <strong>رزروهای جلسه با سرپرست</strong>
        <span class="rm-pill"><i class="fa-solid fa-list"></i> <?= to_persian_digits((string)$total) ?> رزرو</span>
      </div>

      <div class="table-responsive">
        <table class="table table-sm rm-table align-middle mb-0">
          <thead>
            <tr>
              <th>متقاضی</th>
              <th>کارشناس پذیرش</th>
              <th>سرپرست</th>
              <th>تاریخ</th>
              <th>ساعت</th>
              <th>لینک جلسه</th>
            </tr>
          </thead>
          <tbody>
          <?php if (!$rows): ?>
            <tr><td colspan="6" class="text-center text-muted py-5">رزروی برای نمایش وجود ندارد.</td></tr>
          <?php else: foreach ($rows as $r): ?>
            <tr>
              <td>
                <strong><?= e(trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')) ?: '—') ?></strong>
                <div class="small text-muted" dir="ltr"><?= e($r['mobile'] ?? '') ?></div>
              </td>
              <td><?= e($r['agent_name'] ?? '—') ?></td>
              <td><?= e($r['supervisor_name'] ?? '—') ?></td>
              <td><?= !empty($r['slot_date']) ? e(to_jalali($r['slot_date'])) : '—' ?></td>
              <td dir="ltr"><?= e(substr((string)($r['start_time'] ?? ''),0,5)) ?></td>
              <td>
                <?php if (!empty($r['meeting_url'])): ?>
                  <a class="rm-link" href="<?= e($r['meeting_url']) ?>" target="_blank" rel="noopener">
                    <i class="fa-solid fa-arrow-up-right-from-square"></i> ورود
                  </a>
                <?php else: ?>—<?php endif; ?>
              </td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>

      <?php if ($totalPages > 1): ?>
        <div class="p-3 rm-pagination">
          <nav>
            <ul class="pagination pagination-sm justify-content-center mb-2">
              <?php
                $base = ['q'=>$search,'filter'=>$filter];
                $prev = $base; $prev['page'] = max(1,$page-1);
              ?>
              <li class="page-item <?= $page<=1?'disabled':'' ?>">
                <a class="page-link" href="?<?= e(http_build_query($prev)) ?>">‹</a>
              </li>
              <?php for ($i=1; $i<=$totalPages; $i++):
                if ($i<=2 || $i>=$totalPages-1 || abs($i-$page)<=1):
                  $p = $base; $p['page']=$i;
              ?>
                <li class="page-item <?= $i===$page?'active':'' ?>">
                  <a class="page-link" href="?<?= e(http_build_query($p)) ?>"><?= to_persian_digits((string)$i) ?></a>
                </li>
              <?php elseif ($i===3 || $i===$totalPages-2): ?>
                <li class="page-item disabled"><span class="page-link">…</span></li>
              <?php endif; endfor;
                $next = $base; $next['page'] = min($totalPages,$page+1);
              ?>
              <li class="page-item <?= $page>=$totalPages?'disabled':'' ?>">
                <a class="page-link" href="?<?= e(http_build_query($next)) ?>">›</a>
              </li>
            </ul>
          </nav>
          <div class="text-center text-muted small">
            صفحه <?= to_persian_digits((string)$page) ?> از <?= to_persian_digits((string)$totalPages) ?>
            · نمایش <?= to_persian_digits((string)count($rows)) ?> مورد در این صفحه
          </div>
        </div>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/layout_bottom.php'; ?>
