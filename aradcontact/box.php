<?php
/** Box A / B / C — صفِ مشترکِ مشتریان؛ هر کارشناس با «دریافتِ مشتری» اولین موردِ آزاد را می‌گیرد (Atomic) */
require_once __DIR__ . '/includes/auth.php';
$user = require_login();
$pdo = db();
require_once __DIR__ . '/includes/services_functions.php';
require_once __DIR__ . '/includes/orders_functions.php';
require_once __DIR__ . '/includes/customer_credit.php';
require_once __DIR__ . '/includes/performance_functions.php';
require_once __DIR__ . '/includes/spreadsheet_reader.php';
if (!perf_ready($pdo)) die('ماژول آماده نیست.');
$role = (string) ($user['role'] ?? '');
$myBox = in_array($role, ['A', 'B', 'C'], true) && perf_can('box_' . $role, $user) ? $role : null;
$manage = perf_can('box_manage', $user);
if (!$myBox && !$manage) perm_deny('دسترسی به Box ندارید.', $user);
$uid = (int) $user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) { flash_set('danger', 'نشست منقضی شده.'); redirect('box.php'); }
    $a = (string) ($_POST['action'] ?? '');
    if ($a === 'claim' && $myBox) {
        $r = ps_box_claim($pdo, $myBox, $user);
        flash_set($r['ok'] ? 'success' : 'warning', $r['message']);
        redirect($r['ok'] ? 'customer_view.php?id=' . (int) $r['customer_id'] : 'box.php');
    }
    if ($a === 'import' && $manage) {
        $rows = [];
        foreach (preg_split('/\R/u', (string) ($_POST['paste'] ?? '')) as $ln) {
            $ln = trim($ln);
            if ($ln === '') continue;
            $parts = preg_split('/[\t,،;|]+/u', $ln);
            $rows[] = count($parts) >= 2 ? [trim($parts[0]), trim($parts[1])] : ['', trim($parts[0])];
        }
        if (!empty($_FILES['file']['tmp_name']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
            $err = null;
            $data = read_uploaded_spreadsheet($_FILES['file']['tmp_name'], (string) $_FILES['file']['name'], $err);
            if ($data) {
                foreach ((array) ($data['rows'] ?? $data) as $r) {
                    $r = array_values((array) $r);
                    $rows[] = count($r) >= 2 ? [(string) $r[0], (string) $r[1]] : ['', (string) ($r[0] ?? '')];
                }
            } else {
                flash_set('danger', 'فایل خوانده نشد: ' . (string) $err);
            }
        }
        $r = ps_box_import($pdo, $rows, $uid);
        flash_set('success', to_persian_digits((string) $r['ok']) . ' مورد وارد Box A شد (' . to_persian_digits((string) $r['new']) . ' مشتریِ جدید).' . ($r['skipped'] ? ' ردشده: ' . implode(' | ', array_slice($r['skipped'], 0, 15)) : ''));
        redirect('box.php?view=A');
    }
    if ($a === 'cancel' && $manage) {
        $it = $pdo->prepare("SELECT * FROM ps_box_items WHERE id = ? AND status = 'open'");
        $it->execute([(int) $_POST['id']]);
        $it = $it->fetch(PDO::FETCH_ASSOC);
        $pdo->prepare("UPDATE ps_box_items SET status = 'cancelled', note = ? WHERE id = ? AND status = 'open'")->execute([mb_substr('لغو توسطِ ' . $user['full_name'], 0, 300), (int) $_POST['id']]);
        if ($it && !empty($it['prev_owner_id'])) {
            // پرونده به کارشناسِ قبلی برمی‌گردد (اگر هنوز در اختیارِ Box است)
            $pdo->prepare('UPDATE customers SET owner_user_id = ? WHERE id = ? AND owner_user_id = ?')->execute([(int) $it['prev_owner_id'], (int) $it['customer_id'], ps_box_user_id($pdo)]);
            ps_activity($pdo, (int) $it['customer_id'], $uid, 'خروج از Box ' . $it['box'] . ' و برگشتِ پرونده به کارشناسِ قبلی');
        }
        perf_audit($pdo, $uid, 'box_cancel', 'ps_box_items', (int) $_POST['id']);
        flash_set('success', 'از Box خارج شد.');
        redirect('box.php?view=' . urlencode((string) ($_POST['box'] ?? 'A')));
    }
    redirect('box.php');
}
$counts = [];
foreach ($pdo->query("SELECT box, COUNT(*) n FROM ps_box_items WHERE status = 'open' GROUP BY box") as $r) $counts[$r['box']] = (int) $r['n'];
$pageTitle = 'Box مشتریان';
require_once __DIR__ . '/includes/layout_top.php';
?>
<h4 class="fw-bold mb-3"><i class="fa-solid fa-box-open text-warning"></i> Box مشتریان</h4>
<?php if ($myBox):
  $mine = $pdo->prepare('SELECT b.*, c.full_name, c.mobile FROM ps_box_items b JOIN customers c ON c.id = b.customer_id WHERE b.box = ? AND b.claimed_by = ? ORDER BY b.claimed_at DESC LIMIT 30');
  $mine->execute([$myBox, $uid]); ?>
  <?php
    $myTeam = (int) ($user['team_id'] ?? 0);
    $teamCnt = 0;
    if ($myTeam) {
        $tc = $pdo->prepare("SELECT COUNT(*) FROM ps_box_items WHERE box = ? AND status = 'open' AND (reserved_user_id IS NULL OR reserved_user_id = ?) AND (origin_team_id = ? OR entered_team_id = ?)");
        $tc->execute([$myBox, $uid, $myTeam, $myTeam]);
        $teamCnt = (int) $tc->fetchColumn();
    }
    // مواردِ اختصاصیِ دیگران (مثلاً مشتریِ C ِ دیگر که به Box C برگشته) برای من قابلِ دریافت نیست
    $myClaimable = ps_box_claimable_count($pdo, $myBox, $uid);
    $rc = $pdo->prepare("SELECT COUNT(*) FROM ps_box_items WHERE box = ? AND status = 'open' AND reserved_user_id = ?");
    $rc->execute([$myBox, $uid]);
    $myReserved = (int) $rc->fetchColumn();
  ?>
  <div class="card p-4 mb-3 text-center" style="border-radius:16px">
    <div class="small text-muted">مشتریانِ آزاد در Box <?= $myBox ?></div>
    <div class="display-6 fw-bold my-2"><?= to_persian_digits((string) $myClaimable) ?></div>
    <?php if ($myReserved): ?><div class="small mb-2"><span class="badge text-bg-primary"><?= to_persian_digits((string) $myReserved) ?> مشتریِ خودتان (اختصاصی برای شما) — اول از همه همین‌ها به شما می‌رسد</span></div><?php endif; ?>
    <?php if ($myTeam): ?><div class="small mb-2"><span class="badge text-bg-success"><?= to_persian_digits((string) $teamCnt) ?> مورد از ارجاعِ هم‌تیمی‌های شما (تیم <?= to_persian_digits((string) $myTeam) ?>) — اول همین‌ها به شما می‌رسد</span></div><?php endif; ?>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="claim">
      <button class="btn btn-lg btn-warning px-5" <?= $myClaimable ? '' : 'disabled' ?>><i class="fa-solid fa-hand-pointer"></i> دریافتِ مشتری</button></form>
    <div class="small text-muted mt-2">با دریافت، شما <b><?= $myBox ?></b>ِ این مشتری می‌شوید و پرونده‌اش در لیستِ مشتریانِ شما قرار می‌گیرد.</div>
  </div>
  <div class="card p-3 mb-3"><div class="fw-bold small mb-2">آخرین مشتریانی که دریافت کرده‌اید</div>
    <table class="table table-sm small mb-0"><tbody>
      <?php foreach ($mine->fetchAll(PDO::FETCH_ASSOC) ?: [] as $m): ?><tr><td><a href="customer_view.php?id=<?= (int) $m['customer_id'] ?>"><?= e($m['full_name']) ?></a></td><td dir="ltr"><?= e((string) $m['mobile']) ?></td><td><?= to_jalali(substr((string) $m['claimed_at'], 0, 10)) ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
<?php endif; ?>
<?php if ($manage):
  $view = in_array($_GET['view'] ?? '', ['A', 'B', 'C'], true) ? $_GET['view'] : 'A';
  $per = 10;
  $total = (int) ($counts[$view] ?? 0);
  $pages = max(1, (int) ceil($total / $per));
  $page = min($pages, max(1, (int) ($_GET['page'] ?? 1)));
  $items = $pdo->prepare("SELECT b.*, c.full_name, c.mobile, u.full_name AS entered_name, ru.full_name AS reserved_name FROM ps_box_items b JOIN customers c ON c.id = b.customer_id
      LEFT JOIN users u ON u.id = b.entered_by LEFT JOIN users ru ON ru.id = b.reserved_user_id
      WHERE b.box = ? AND b.status = 'open' ORDER BY b.id LIMIT $per OFFSET " . (($page - 1) * $per));
  $items->execute([$view]);
  $items = $items->fetchAll(PDO::FETCH_ASSOC) ?: [];
  $srcL = ['import' => 'اکسل', 'staff_report' => 'گزارش کارکنان', 'customer_list' => 'لیست مشتریان', 'bulk_referral' => 'ارجاع گروهی', 'refer_a' => 'ارجاعِ A', 'refer_b' => 'ارجاعِ B', 'manual' => 'دستی', 'peer' => 'ارجاعِ هم‌سطح', 'c_revive' => 'احیای مشتری توسطِ C'];
  $pageUrl = static fn(int $p): string => '?' . http_build_query(['view' => $view, 'page' => $p]);
?>
  <style>
    .bx-row{border:1px solid #eee;border-radius:12px;padding:10px;margin-bottom:8px;background:#fff}
    .bx-row .nm{font-weight:700}
    @media (min-width:768px){.bx-cards{display:none}}
    @media (max-width:767.98px){.bx-table{display:none}}
  </style>
  <div class="card p-3 mb-3"><div class="d-flex gap-2 mb-2 flex-wrap">
    <?php foreach (['A', 'B', 'C'] as $bx): ?><a class="btn btn-sm <?= $view === $bx ? 'btn-dark' : 'btn-outline-dark' ?>" href="?view=<?= $bx ?>">Box <?= $bx ?> (<?= to_persian_digits((string) ($counts[$bx] ?? 0)) ?>)</a><?php endforeach; ?></div>
    <?php if ($view === 'A'): ?>
      <form method="post" enctype="multipart/form-data" class="border rounded-3 p-2 mb-3"><?= csrf_field() ?><input type="hidden" name="action" value="import">
        <div class="fw-bold small mb-1">ورودِ لید به Box A — اکسل/CSV (ستونِ اول نام، ستونِ دوم موبایل) یا چسباندنِ لیست (هر خط: نام، موبایل)</div>
        <div class="row g-2"><div class="col-12 col-md-4"><input type="file" name="file" class="form-control form-control-sm" accept=".xlsx,.csv"></div>
          <div class="col-12 col-md-6"><textarea name="paste" class="form-control form-control-sm" rows="2" placeholder="علی رضایی, 09121234567"></textarea></div>
          <div class="col-12 col-md-2"><button class="btn btn-sm btn-primary w-100">ورود به Box A</button></div></div>
        <div class="small text-muted mt-1">فقط مشتریِ بدونِ A/B/C وارد می‌شود؛ شماره‌ی موجود در سامانه به همان مشتری وصل می‌شود.</div>
      </form>
    <?php endif; ?>

    <?php if (!$items): ?><div class="text-center text-muted small py-3">Box <?= $view ?> خالی است.</div><?php endif; ?>

    <!-- دسکتاپ: جدول -->
    <div class="bx-table table-responsive"><?php if ($items): ?><table class="table table-sm small align-middle mb-0">
      <thead class="table-light"><tr><th>#</th><th>مشتری</th><th>موبایل</th><th>منبع</th><th>اختصاصی برای</th><th>تیمِ مبدأ / ارجاع‌دهنده</th><th>ورود</th><th></th></tr></thead><tbody>
      <?php foreach ($items as $it): ?><tr>
        <td><?= to_persian_digits((string) $it['id']) ?></td>
        <td><a href="customer_view.php?id=<?= (int) $it['customer_id'] ?>"><?= e($it['full_name']) ?></a></td>
        <td dir="ltr"><?= e((string) $it['mobile']) ?></td>
        <td><?= e($srcL[$it['source']] ?? $it['source']) ?></td>
        <td><?= $it['reserved_user_id'] ? '<span class="badge text-bg-primary">' . e((string) ($it['reserved_name'] ?? '#' . $it['reserved_user_id'])) . '</span>' : '—' ?></td>
        <td><?= $it['origin_team_id'] ? 'تیم ' . to_persian_digits((string) $it['origin_team_id']) : '—' ?> / <?= $it['entered_team_id'] ? 'تیم ' . to_persian_digits((string) $it['entered_team_id']) : '—' ?></td>
        <td class="text-nowrap"><?= to_jalali(substr($it['entered_at'], 0, 10)) ?><div class="text-muted"><?= e((string) $it['entered_name']) ?></div></td>
        <td><form method="post" onsubmit="return confirm('از Box خارج شود؟ (پرونده به کارشناسِ قبلی برمی‌گردد)');"><?= csrf_field() ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="id" value="<?= (int) $it['id'] ?>"><input type="hidden" name="box" value="<?= $view ?>"><button class="btn btn-sm btn-outline-danger py-0">خروج</button></form></td>
      </tr><?php endforeach; ?>
    </tbody></table><?php endif; ?></div>

    <!-- موبایل: کارت -->
    <div class="bx-cards">
      <?php foreach ($items as $it): ?>
        <div class="bx-row">
          <div class="d-flex justify-content-between align-items-start gap-2">
            <div><a class="nm" href="customer_view.php?id=<?= (int) $it['customer_id'] ?>"><?= e($it['full_name']) ?></a>
              <div class="small" dir="ltr" style="text-align:right"><?= e((string) $it['mobile']) ?></div></div>
            <form method="post" onsubmit="return confirm('از Box خارج شود؟ (پرونده به کارشناسِ قبلی برمی‌گردد)');"><?= csrf_field() ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="id" value="<?= (int) $it['id'] ?>"><input type="hidden" name="box" value="<?= $view ?>"><button class="btn btn-sm btn-outline-danger py-0">خروج</button></form>
          </div>
          <div class="small text-muted mt-1"><?= to_jalali(substr($it['entered_at'], 0, 10)) ?> — <?= e((string) $it['entered_name']) ?> — <?= e($srcL[$it['source']] ?? $it['source']) ?>
            <?= $it['origin_team_id'] ? ' — تیمِ مبدأ ' . to_persian_digits((string) $it['origin_team_id']) : '' ?>
            <?= $it['reserved_user_id'] ? ' — <span class="badge text-bg-primary">اختصاصی: ' . e((string) ($it['reserved_name'] ?? '#' . $it['reserved_user_id'])) . '</span>' : '' ?></div>
        </div>
      <?php endforeach; ?>
    </div>

    <?php if ($pages > 1): ?>
      <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mt-3">
        <div class="small text-muted">نمایش <?= to_persian_digits((string) (($page - 1) * $per + 1)) ?> تا <?= to_persian_digits((string) min($total, $page * $per)) ?> از <?= to_persian_digits((string) $total) ?></div>
        <nav><ul class="pagination pagination-sm mb-0 flex-wrap">
          <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="<?= e($pageUrl($page - 1)) ?>">قبلی</a></li>
          <?php
            $win = [];
            foreach ([1, $page - 1, $page, $page + 1, $pages] as $p) if ($p >= 1 && $p <= $pages) $win[$p] = true;
            ksort($win); $prev = 0;
            foreach (array_keys($win) as $p):
              if ($prev && $p > $prev + 1): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; $prev = $p; ?>
            <li class="page-item <?= $p === $page ? 'active' : '' ?>"><a class="page-link" href="<?= e($pageUrl($p)) ?>"><?= to_persian_digits((string) $p) ?></a></li>
          <?php endforeach; ?>
          <li class="page-item <?= $page >= $pages ? 'disabled' : '' ?>"><a class="page-link" href="<?= e($pageUrl($page + 1)) ?>">بعدی</a></li>
        </ul></nav>
        <form class="d-flex gap-1 align-items-center"><input type="hidden" name="view" value="<?= $view ?>"><span class="small">صفحه</span><input name="page" class="form-control form-control-sm" style="width:64px" value="<?= $page ?>"><span class="small">از <?= to_persian_digits((string) $pages) ?></span><button class="btn btn-sm btn-outline-secondary">برو</button></form>
      </div>
    <?php endif; ?>
  </div>
<?php endif; ?>
<?php require_once __DIR__ . '/includes/layout_bottom.php'; ?>
