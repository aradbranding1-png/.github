<?php
/** گزارشِ سرپرست: فعالیتِ خودِ سرپرست + تماس با نیروها + راندمانِ نیروها (سرپرست: تیمِ خودش — مدیر: همه) */
require_once __DIR__ . '/includes/auth.php';
$user = require_login();
$pdo = db();
try { contact_type_backfill_v1($pdo); } catch (Throwable $e) {} // یک‌بار: اصلاحِ همکار/خانواده‌هایی که «مشتری» مانده‌اند
require_once __DIR__ . '/includes/customer_credit.php';
require_once __DIR__ . '/includes/performance_functions.php';
require_once __DIR__ . '/includes/supervisor_report.php';
require_once __DIR__ . '/includes/team_sales.php';

$isAll = is_super_admin($user) || user_can('supervisor_report_all', $user);
$isLeader = ($user['role'] ?? '') === 'leader';
if (!$isAll && !($isLeader && user_can('supervisor_report_view', $user))) perm_deny('دسترسی به «گزارش سرپرست» ندارید.', $user);

$preset = (string) ($_GET['preset'] ?? 'month');
[$from, $to, $rl] = perf_range($preset, (string) ($_GET['from'] ?? ''), (string) ($_GET['to'] ?? ''));
$jFrom = to_jalali($from); $jTo = to_jalali($to);
$leaderId = $isAll ? (int) ($_GET['leader'] ?? 0) : (int) $user['id'];
$onlyUnknown = !empty($_GET['unknown']);

// ─── ویرایشِ «محلِ فعالیت» و «گروهِ شغلی» نیروهای تیم توسطِ سرپرست (یا مدیر) — در کلِ سامانه اعمال می‌شود ───
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'member_profile') {
    $back = 'supervisor_report.php?' . http_build_query($_GET) . '#spr-members';
    $mid = (int) ($_POST['member_id'] ?? 0);
    $lid = $isAll ? (int) ($_POST['leader'] ?? $leaderId) : (int) $user['id'];
    if (!csrf_verify() || $mid <= 0 || $lid <= 0) { flash_set('danger', 'درخواست نامعتبر است.'); redirect($back); }
    $team = team_led_by($pdo, $lid);
    $allowed = false;
    $memberName = '';
    if ($team) {
        foreach (sup_members($pdo, (int) $team['id'], $lid) as $__m) {
            if ((int) $__m['id'] === $mid) { $allowed = true; $memberName = (string) $__m['full_name']; break; }
        }
    }
    if (!$allowed) { flash_set('danger', 'این نیرو عضوِ تیمِ شما نیست.'); redirect($back); }
    $changed = [];
    $loc = (string) ($_POST['work_location'] ?? '');
    if (in_array($loc, ['onsite', 'remote'], true)) {
        try { $pdo->prepare('UPDATE users SET work_location = ? WHERE id = ?')->execute([$loc, $mid]); $changed[] = 'محلِ فعالیت: ' . sup_work_location_label($loc); }
        catch (Throwable $e) { error_log('spr work_location: ' . $e->getMessage()); }
    }
    $grp = normalize_job_group((string) ($_POST['job_group'] ?? ''));
    if ($grp !== null) {
        try { $pdo->prepare('UPDATE users SET job_group = ? WHERE id = ?')->execute([$grp, $mid]); $changed[] = 'گروهِ شغلی: ' . $grp; }
        catch (Throwable $e) { error_log('spr job_group: ' . $e->getMessage()); }
    }
    flash_set($changed ? 'success' : 'warning', $changed ? 'برای «' . $memberName . '» ذخیره شد — ' . implode('، ', $changed) . '.' : 'تغییری ذخیره نشد.');
    redirect($back);
}
$fmtMin = static fn(int $sec): string => to_persian_digits(number_format((int) round($sec / 60)));
$money = static fn($n): string => number_format((int) $n);
$effBadge = static function (?float $e): string {
    if ($e === null) return '<span class="text-muted">—</span>';
    $cls = $e >= 80 ? 'text-bg-success' : ($e >= 50 ? 'text-bg-warning' : ($e > 0 ? 'text-bg-danger' : 'text-bg-secondary'));
    return '<span class="badge ' . $cls . '">' . to_persian_digits((string) $e) . '٪</span>';
};
$detailLink = static function (int $staffId) use ($isAll, $jFrom, $jTo): string {
    $q = http_build_query(['staff' => $staffId, 'preset' => 'custom', 'from' => $jFrom, 'to' => $jTo]);
    return $isAll ? 'admin/admin_staff_report.php?' . $q : 'reports.php?' . $q;
};
$talk = sup_talk_by_user($pdo, $from, $to);
$roleAvg = sup_role_averages($pdo, $talk);

$pageTitle = 'گزارش سرپرست';
require_once __DIR__ . '/includes/layout_top.php';
?>
<style>.spr .card{border-radius:14px;border:1px solid #e7e2d3}.spr .stat{padding:10px;border:1px solid #eee;border-radius:12px;background:#fff;height:100%}.spr .stat .v{font-weight:800}</style>
<div class="spr">
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
  <div><h4 class="fw-bold mb-0"><i class="fa-solid fa-user-tie text-warning"></i> گزارش سرپرست</h4>
    <div class="small text-muted">راندمان از ۱۰۰٪ حساب می‌شود — راهنما پایین‌تر.</div></div>
  <form class="d-flex gap-2 flex-wrap align-items-end">
    <?php if ($leaderId): ?><input type="hidden" name="leader" value="<?= $leaderId ?>"><?php endif; ?>
    <select name="preset" class="form-select form-select-sm" style="width:auto"><?php foreach (['today' => 'امروز', 'yesterday' => 'دیروز', 'week' => 'این هفته', 'month' => 'این ماه', 'last_month' => 'ماهِ قبل', 'custom' => 'دلخواه'] as $k => $l): ?><option value="<?= $k ?>" <?= $preset === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select>
    <input name="from" class="form-control form-control-sm jalali-date" style="width:120px" placeholder="از" value="<?= e((string) ($_GET['from'] ?? '')) ?>">
    <input name="to" class="form-control form-control-sm jalali-date" style="width:120px" placeholder="تا" value="<?= e((string) ($_GET['to'] ?? '')) ?>">
    <button class="btn btn-sm btn-dark">نمایش</button>
  </form>
</div>
<div class="small text-muted mb-2"><?= e($rl) ?>: <?= $jFrom ?> تا <?= $jTo ?></div>
<details class="card p-3 mb-3" style="background:#fffdf5" open>
  <summary class="fw-bold small"><i class="fa-solid fa-circle-info text-warning"></i> راهنمای محاسبه‌ی راندمان (از ۱۰۰٪)</summary>
  <div class="small mt-2" style="line-height:2">
    <div>۱) <b>معیارِ هر نقش:</b> میانگینِ دقیقه‌ی مکالمه‌ی مفیدِ همه‌ی نیروهای همان نقش (A، B، C یا سرپرست) در کلِ سازمان که در این بازه مکالمه داشته‌اند. نیروهای بی‌مکالمه در این میانگین حساب نمی‌شوند.</div>
    <div>۲) <b>راندمانِ هر نیرو</b> = دقیقه‌ی مکالمه‌ی مفیدِ او ÷ معیارِ نقشش × ۱۰۰ — اگر به معیار برسد یا از آن بیشتر شود، <b>۱۰۰٪</b> حساب می‌شود (بیشتر از ۱۰۰٪ نمی‌شود). نیرویی که مکالمه نداشته ۰٪ است.</div>
    <div>۳) <b>راندمانِ تیم</b> = میانگینِ راندمانِ همه‌ی نیروهای تیم. پس ۱۰۰٪ یعنی همه‌ی نیروهای تیم به معیارِ نقششان رسیده‌اند.</div>
    <div>رنگ‌ها: <span class="badge text-bg-success">۸۰٪ و بالاتر</span> <span class="badge text-bg-warning">۵۰ تا ۸۰٪</span> <span class="badge text-bg-danger">کمتر از ۵۰٪</span></div>
    <div class="text-muted">«پوششِ ارتباطِ سرپرست» = چند نفر از نیروهای تیم در این بازه حداقل یک تماسِ برقرارشده با سرپرست داشته‌اند.</div>
  </div>
</details>

<?php if ($isAll && !$leaderId):
  // ─── نمای کلی: همه‌ی سرپرست‌ها ───
  $rows = [];
  foreach (sup_leaders($pdo) as $L) {
      $members = sup_members($pdo, (int) $L['team_id'], (int) $L['id']);
      $contacts = sup_leader_member_contacts($pdo, (int) $L['id'], $members, $from, $to);
      $covered = 0; $effSum = 0; $effN = 0; $unknown = 0;
      foreach ($members as $m) {
          if (!empty($contacts[(int) $m['id']]['connected'])) $covered++;
          $e = sup_efficiency($talk[(int) $m['id']] ?? 0, (float) ($roleAvg[$m['role']] ?? 0));
          if ($e !== null) { $effSum += $e; $effN++; }
          if (sup_job_group_label($m['job_group'] ?? null) === 'نامشخص') $unknown++;
      }
      $rows[] = $L + ['n' => count($members), 'covered' => $covered, 'team_eff' => $effN ? round($effSum / $effN, 1) : null, 'unknown' => $unknown,
          'own_talk' => $talk[(int) $L['id']] ?? 0, 'sales' => tsr_team_sales_period($pdo, (int) $L['team_id'], $from, $to)];
  }
  $salesTot = ['cnt' => 0, 'net' => 0, 'A' => 0, 'B' => 0, 'C' => 0, 'D' => 0];
  foreach ($rows as $r) {
      $salesTot['cnt'] += $r['sales']['cnt']; $salesTot['net'] += $r['sales']['net'];
      foreach (['A', 'B', 'C', 'D'] as $__sl) $salesTot[$__sl] += $r['sales']['slots'][$__sl];
  } ?>
  <div class="card p-3 mb-3">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
      <div class="fw-bold"><i class="fa-solid fa-sack-dollar text-success"></i> فروشِ تیم‌ها <span class="small text-muted fw-normal">(سرپرست = D + نیروهای A / B / C هر تیم — سفارش‌های تأییدشده در این بازه، خالص بدونِ مالیات)</span></div>
      <a class="btn btn-sm btn-outline-success" href="admin/admin_orders.php?<?= e(http_build_query(['view' => 'report', 'preset' => 'custom', 'from' => $jFrom, 'to' => $jTo])) ?>"><i class="fa-solid fa-chart-column"></i> گزارشِ فروشِ تیم‌ها</a>
    </div>
    <div class="table-responsive"><table class="table table-sm align-middle small mb-0">
      <thead class="table-light"><tr><th>تیم</th><th>سرپرست</th><th class="text-end">A</th><th class="text-end">B</th><th class="text-end">C</th><th class="text-end">D (سرپرست)</th><th>تعداد سفارش</th><th class="text-end">فروشِ تیم</th><th>سهم از کل</th></tr></thead><tbody>
      <?php $__byNet = $rows; usort($__byNet, static fn($a, $b) => $b['sales']['net'] <=> $a['sales']['net']);
        foreach ($__byNet as $r): $__s = $r['sales']; ?>
        <tr><td><?= e(team_display_name($r['team_name'] ?? null, (int) $r['team_id'])) ?></td><td><?= e($r['full_name']) ?></td>
          <?php foreach (['A', 'B', 'C', 'D'] as $__sl): ?><td class="text-end"><?= $money($__s['slots'][$__sl]) ?></td><?php endforeach; ?>
          <td><?= to_persian_digits((string) $__s['cnt']) ?></td><td class="text-end fw-bold"><?= $money($__s['net']) ?></td>
          <td><?= $salesTot['net'] > 0 ? to_persian_digits((string) round($__s['net'] / $salesTot['net'] * 100, 1)) . '٪' : '—' ?></td></tr>
      <?php endforeach; ?>
      </tbody>
      <?php if ($rows): ?><tfoot class="table-light fw-bold"><tr><td colspan="2">جمعِ همه‌ی تیم‌ها</td>
        <?php foreach (['A', 'B', 'C', 'D'] as $__sl): ?><td class="text-end"><?= $money($salesTot[$__sl]) ?></td><?php endforeach; ?>
        <td><?= to_persian_digits((string) $salesTot['cnt']) ?></td><td class="text-end"><?= $money($salesTot['net']) ?></td><td></td></tr></tfoot><?php endif; ?>
    </table></div>
  </div>
  <div class="card p-0"><div class="table-responsive"><table class="table table-sm align-middle small mb-0">
    <thead class="table-light"><tr><th>تیم</th><th>سرپرست</th><th>نیروها</th><th>پوششِ ارتباطِ سرپرست</th><th>راندمانِ تیم</th><th>مکالمه‌ی خودِ سرپرست (دقیقه)</th><th>فروشِ تیم (خالص)</th><th></th></tr></thead><tbody>
    <?php foreach ($rows as $r): ?>
      <tr><td><?= to_persian_digits((string) $r['team_id']) ?></td><td><?= e($r['full_name']) ?></td><td><?= to_persian_digits((string) $r['n']) ?></td>
        <td><?= to_persian_digits((string) $r['covered']) ?> از <?= to_persian_digits((string) $r['n']) ?><?= $r['n'] ? ' (' . to_persian_digits((string) round($r['covered'] / $r['n'] * 100)) . '٪)' : '' ?></td>
        <td><?= $effBadge($r['team_eff']) ?></td><td><?= $fmtMin($r['own_talk']) ?></td>
        <td class="fw-bold"><?= $money($r['sales']['net']) ?> <span class="text-muted fw-normal">(<?= to_persian_digits((string) $r['sales']['cnt']) ?> سفارش)</span></td>
        <td><a class="btn btn-sm btn-outline-primary py-0" href="?<?= e(http_build_query(['leader' => (int) $r['id']] + $_GET)) ?>">جزئیات</a></td></tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="8" class="text-center text-muted py-3">تیمی با سرپرست تعریف نشده.</td></tr><?php endif; ?>
  </tbody></table></div></div>

<?php else:
  $team = team_led_by($pdo, $leaderId);
  $leader = $pdo->prepare('SELECT id, full_name, mobile, role FROM users WHERE id = ?');
  $leader->execute([$leaderId]);
  $leader = $leader->fetch(PDO::FETCH_ASSOC);
  if (!$team || !$leader): ?>
    <div class="alert alert-warning">برای این کاربر تیمی با سرپرستیِ او تعریف نشده.</div>
  <?php else:
    $members = sup_members($pdo, (int) $team['id'], $leaderId);
    $contacts = sup_leader_member_contacts($pdo, $leaderId, $members, $from, $to);
    $own = sup_person_stats($pdo, $leaderId, $from, $to);
    $acts = sup_activities($pdo, $leaderId, $from, $to);
    $stats = []; $effSum = 0; $effN = 0; $covered = 0;
    $loc = ['حضوری' => 0, 'دورکار' => 0, 'نامشخص' => 0];
    $grp = ['توسعه' => 0, 'عملیات' => 0, 'ستادی' => 0, 'نامشخص' => 0];
    foreach ($members as $m) {
        $mid = (int) $m['id'];
        $stats[$mid] = sup_person_stats($pdo, $mid, $from, $to);
        $stats[$mid]['eff'] = sup_efficiency($talk[$mid] ?? 0, (float) ($roleAvg[$m['role']] ?? 0));
        if ($stats[$mid]['eff'] !== null) { $effSum += $stats[$mid]['eff']; $effN++; }
        if (!empty($contacts[$mid]['connected'])) $covered++;
        $loc[sup_work_location_label($m['work_location'] ?? null)]++;
        $grp[sup_job_group_label($m['job_group'] ?? null)]++;
    }
    $teamEff = $effN ? round($effSum / $effN, 1) : null;
    $n = count($members);
    $unknownList = array_values(array_filter($members, static fn($m) => sup_job_group_label($m['job_group'] ?? null) === 'نامشخص'));
    $shown = $onlyUnknown ? $unknownList : $members;
  ?>
  <?php if ($isAll): ?><div class="mb-2"><a class="btn btn-sm btn-outline-secondary" href="?<?= e(http_build_query(array_diff_key($_GET, ['leader' => 1, 'unknown' => 1]))) ?>">← همه‌ی سرپرست‌ها</a></div><?php endif; ?>
  <div class="card p-3 mb-3">
    <div class="d-flex justify-content-between flex-wrap gap-2">
      <div class="fw-bold">تیم <?= to_persian_digits((string) $team['id']) ?> — سرپرست: <?= e($leader['full_name']) ?></div>
      <a class="btn btn-sm btn-outline-primary" href="<?= e($detailLink($leaderId)) ?>"><i class="fa-solid fa-phone"></i> جزئیاتِ تماس‌های سرپرست (با چه کسانی)</a>
    </div>
    <div class="row g-2 mt-1">
      <?php foreach ([['پوششِ ارتباط با نیروها', to_persian_digits((string) $covered) . ' از ' . to_persian_digits((string) $n) . ($n ? ' (' . to_persian_digits((string) round($covered / $n * 100)) . '٪)' : '')],
          ['راندمانِ تیم', $effBadge($teamEff)], ['راندمانِ مکالمه‌ی خودِ سرپرست', $effBadge(sup_efficiency($talk[$leaderId] ?? 0, (float) ($roleAvg['leader'] ?? 0)))],
          ['تماسِ برقرار', to_persian_digits((string) $own['connected_calls'])], ['دقیقه‌ی مکالمه', $fmtMin($own['total_seconds'])], ['مخاطبِ یکتا', to_persian_digits((string) $own['unique_contacts'])],
          ['جلسه‌ی برگزارشده', to_persian_digits((string) $own['meetings'])], ['روزِ فعال', to_persian_digits((string) $own['active_days'])],
          ['فروشِ تأییدشده (خالص)', $money($own['sales_net'])], ['سهمِ عملکرد', $money($own['share'])]] as [$l, $v]): ?>
        <div class="col-6 col-md-3 col-xl-2"><div class="stat"><div class="small text-muted"><?= e($l) ?></div><div class="v"><?= $v ?></div></div></div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php $ts = tsr_team_sales_period($pdo, (int) $team['id'], $from, $to); ?>
  <div class="card p-3 mb-3" style="border-top:3px solid #16a34a">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
      <div class="fw-bold"><i class="fa-solid fa-sack-dollar text-success"></i> فروشِ تیم <span class="small text-muted fw-normal">(سرپرست = D + نیروهای A / B / C — سفارش‌های تأییدشده در این بازه، خالص بدونِ مالیات)</span></div>
      <a class="btn btn-sm btn-outline-success" href="admin/admin_orders.php?<?= e(http_build_query(['view' => 'report', 'preset' => 'custom', 'from' => $jFrom, 'to' => $jTo, 'team' => (int) $team['id']])) ?>"><i class="fa-solid fa-chart-column"></i> جزئیات در گزارشِ فروش</a>
    </div>
    <div class="row g-2">
      <div class="col-12 col-md-4 col-xl-2"><div class="stat" style="background:#f0fdf4"><div class="small text-muted">فروشِ کلِ تیم</div><div class="v fs-5"><?= $money($ts['net']) ?></div><div class="small text-muted"><?= to_persian_digits((string) $ts['cnt']) ?> سفارش</div></div></div>
      <?php foreach (['A' => 'واحدِ A', 'B' => 'واحدِ B', 'C' => 'واحدِ C', 'D' => 'D — خودِ سرپرست'] as $__sl => $__lb): ?>
        <div class="col-6 col-md-4 col-xl-2"><div class="stat"><div class="small text-muted"><?= $__lb ?></div><div class="v"><?= $money($ts['slots'][$__sl]) ?></div>
          <div class="small text-muted"><?= $ts['net'] > 0 ? to_persian_digits((string) round($ts['slots'][$__sl] / $ts['net'] * 100)) . '٪ از فروشِ تیم' : '—' ?></div></div></div>
      <?php endforeach; ?>
      <div class="col-6 col-md-4 col-xl-2"><div class="stat"><div class="small text-muted">مبلغِ تأییدشده (با مالیات)</div><div class="v"><?= $money($ts['gross']) ?></div></div></div>
    </div>
  </div>
  <div class="row g-3 mb-3">
    <div class="col-lg-4"><div class="card p-3 h-100"><div class="fw-bold small mb-2"><i class="fa-solid fa-list-check"></i> کارهای سرپرست در این بازه</div>
      <?php if (!$acts): ?><div class="small text-muted">فعالیتی ثبت نشده.</div><?php endif; ?>
      <?php foreach ($acts as $l => $c): ?><div class="d-flex justify-content-between small border-bottom py-1"><span><?= e($l) ?></span><b><?= to_persian_digits((string) $c) ?></b></div><?php endforeach; ?>
    </div></div>
    <div class="col-lg-4"><div class="card p-3 h-100"><div class="fw-bold small mb-2"><i class="fa-solid fa-building-user"></i> محلِ فعالیت</div>
      <?php foreach ($loc as $l => $c): ?><div class="d-flex justify-content-between small border-bottom py-1"><span><?= $l ?></span><b class="<?= $l === 'نامشخص' && $c ? 'text-danger' : '' ?>"><?= to_persian_digits((string) $c) ?></b></div><?php endforeach; ?>
    </div></div>
    <div class="col-lg-4"><div class="card p-3 h-100"><div class="fw-bold small mb-2"><i class="fa-solid fa-layer-group"></i> گروهِ شغلی</div>
      <?php foreach ($grp as $l => $c): ?><div class="d-flex justify-content-between small border-bottom py-1"><span><?= $l ?></span><b class="<?= $l === 'نامشخص' && $c ? 'text-danger' : '' ?>"><?= to_persian_digits((string) $c) ?></b></div><?php endforeach; ?>
      <?php if ($unknownList): ?>
        <div class="small mt-2"><b class="text-danger">نامشخص‌ها:</b> <?= e(implode('، ', array_map(static fn($m) => $m['full_name'], $unknownList))) ?></div>
        <a class="btn btn-sm btn-outline-danger mt-2 py-0" href="?<?= e(http_build_query(['unknown' => $onlyUnknown ? null : 1] + $_GET)) ?>"><?= $onlyUnknown ? 'نمایشِ همه' : 'فقط نامشخص‌ها در جدول' ?></a>
      <?php endif; ?>
    </div></div>
  </div>
  <div class="small text-muted mb-1" id="spr-members"><i class="fa-solid fa-pen"></i> «محلِ فعالیت» و «گروهِ شغلی» هر نیرو را همین‌جا عوض کنید و <i class="fa-solid fa-floppy-disk"></i> را بزنید؛ در کلِ سامانه برای او اعمال می‌شود.</div>
  <div class="card p-0"><div class="table-responsive"><table class="table table-sm align-middle small mb-0">
    <thead class="table-light"><tr><th>نیرو</th><th>واحد</th><th>محلِ فعالیت</th><th>گروهِ شغلی</th><th>تماسِ برقرار</th><th>دقیقه‌ی مکالمه</th><th>مخاطبِ یکتا</th><th>روزِ فعال</th><th>جلسه</th><th>فروشِ خالص</th><th>راندمان</th><th>سرپرست با او صحبت کرده؟</th><th></th></tr></thead><tbody>
    <?php foreach ($shown as $m): $mid = (int) $m['id']; $s = $stats[$mid]; $ct = $contacts[$mid] ?? null;
      $locL = sup_work_location_label($m['work_location'] ?? null); $grpL = sup_job_group_label($m['job_group'] ?? null); ?>
      <tr><td><a href="<?= e($detailLink($mid)) ?>"><?= e($m['full_name']) ?></a><div class="text-muted" dir="ltr" style="font-size:11px;text-align:right"><?= e((string) $m['mobile']) ?></div>
          <div style="font-size:11px"><?php if ($ct && $ct['connected']): ?><span class="text-success"><i class="fa-solid fa-phone"></i> سرپرست: <?= to_persian_digits((string) $ct['connected']) ?> تماس، <?= $fmtMin($ct['seconds']) ?> دقیقه</span>
            <?php elseif ($ct): ?><span class="text-warning"><i class="fa-solid fa-phone-slash"></i> سرپرست: فقط بی‌پاسخ</span>
            <?php else: ?><span class="text-danger"><i class="fa-solid fa-xmark"></i> سرپرست صحبت نکرده</span><?php endif; ?></div></td>
        <td><?= e($m['role']) ?></td>
        <td>
          <form method="post" id="sprEdit<?= $mid ?>" class="d-none"><?= csrf_field() ?><input type="hidden" name="action" value="member_profile"><input type="hidden" name="member_id" value="<?= $mid ?>"><input type="hidden" name="leader" value="<?= (int) $leaderId ?>"></form>
          <select name="work_location" form="sprEdit<?= $mid ?>" class="form-select form-select-sm <?= $locL === 'نامشخص' ? 'border-danger text-danger' : '' ?>" style="min-width:95px">
            <?php if ($locL === 'نامشخص'): ?><option value="">نامشخص</option><?php endif; ?>
            <option value="onsite" <?= $locL === 'حضوری' ? 'selected' : '' ?>>حضوری</option>
            <option value="remote" <?= $locL === 'دورکار' ? 'selected' : '' ?>>دورکار</option>
          </select>
        </td>
        <td>
          <div class="d-flex gap-1 align-items-center">
            <select name="job_group" form="sprEdit<?= $mid ?>" class="form-select form-select-sm <?= $grpL === 'نامشخص' ? 'border-danger text-danger fw-bold' : '' ?>" style="min-width:95px">
              <?php if ($grpL === 'نامشخص'): ?><option value="">نامشخص</option><?php endif; ?>
              <?php foreach (valid_job_groups() as $__g): ?><option value="<?= e($__g) ?>" <?= $grpL === $__g ? 'selected' : '' ?>><?= e($__g) ?></option><?php endforeach; ?>
            </select>
            <button type="submit" form="sprEdit<?= $mid ?>" class="btn btn-sm btn-success py-0 px-2" title="ذخیره"><i class="fa-solid fa-floppy-disk"></i></button>
          </div>
        </td>
        <td><?= to_persian_digits((string) $s['connected_calls']) ?></td><td><?= $fmtMin($s['total_seconds']) ?></td><td><?= to_persian_digits((string) $s['unique_contacts']) ?></td>
        <td><?= to_persian_digits((string) $s['active_days']) ?></td><td><?= to_persian_digits((string) $s['meetings']) ?></td><td><?= $money($s['sales_net']) ?></td>
        <td><?= $effBadge($s['eff']) ?></td>
        <td><?php if ($ct && $ct['connected']): ?><span class="text-success"><i class="fa-solid fa-check"></i> <?= to_persian_digits((string) $ct['connected']) ?> تماس، <?= $fmtMin($ct['seconds']) ?> دقیقه</span><div class="text-muted" style="font-size:11px">آخرین: <?= to_jalali($ct['last']) ?></div>
          <?php elseif ($ct): ?><span class="text-warning"><i class="fa-solid fa-phone-slash"></i> <?= to_persian_digits((string) $ct['calls']) ?> تماسِ بی‌پاسخ</span>
          <?php else: ?><span class="text-danger"><i class="fa-solid fa-xmark"></i> صحبت نکرده</span><?php endif; ?></td>
        <td><a class="btn btn-sm btn-outline-primary py-0" href="<?= e($detailLink($mid)) ?>">جزئیاتِ تماس</a></td></tr>
    <?php endforeach; ?>
    <?php if (!$shown): ?><tr><td colspan="13" class="text-center text-muted py-3">نیرویی نیست.</td></tr><?php endif; ?>
  </tbody></table></div></div>
  <div class="small text-muted mt-2">«صحبت کرده» از روی تماس‌های خودِ سرپرست (کالیزر/نواتل/پیگیری) تشخیص داده می‌شود: شماره‌ی طرفِ تماس = موبایلِ نیرو. برای دیده‌شدن، سرپرست هم باید اکسلِ کالیزرِ خودش را وارد کند.
    گروهِ شغلی و محلِ فعالیت از «مدیریت کاربران» تعیین می‌شود.</div>

  <?php
    // مخاطبانی که سرپرست در این بازه با آن‌ها رکوردِ تماس دارد (فقط همین‌ها)
    $cPer = 25;
    $cPage = max(1, (int) ($_GET['cpage'] ?? 1));
    // تماس با نیروهای تیم در جدولِ بالا (ستونِ «سرپرست با او صحبت کرده؟») است؛ این‌جا فقط مخاطبانِ غیرِ نیرو
    $memberCustIds = [];
    foreach ($contacts as $ctm) foreach (array_keys($ctm['customer_ids'] ?? []) as $cidm) $memberCustIds[] = (int) $cidm;
    $lc = sup_user_contacts($pdo, $leaderId, $from, $to, $cPage, $cPer, $memberCustIds);
    $cPages = max(1, (int) ceil($lc['total'] / $cPer));
    $memberByPhone = [];
    foreach ($members as $m) {
        foreach (['mobile', 'mobile_2'] as $c) {
            $nn = !empty($m[$c]) ? normalize_phone_for_match((string) $m[$c]) : null;
            if ($nn) $memberByPhone[$nn] = $m['full_name'];
        }
    }
    $ctypes = contact_type_options();
    $cUrl = static fn(int $p): string => '?' . http_build_query(['cpage' => $p] + $_GET) . '#leader-contacts';
  ?>
  <div class="card p-3 mt-3" id="leader-contacts">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
      <div class="fw-bold"><i class="fa-solid fa-address-book text-primary"></i> مخاطبانی که سرپرست با آن‌ها تماس داشته
        <span class="badge text-bg-light border"><?= to_persian_digits((string) $lc['total']) ?> مخاطب</span></div>
      <div class="small text-muted">فقط مخاطبانِ دارای رکوردِ تماس در این بازه — مرتب بر اساسِ مدتِ مکالمه<?= $contacts ? ' — تماس با ' . to_persian_digits((string) count($contacts)) . ' نیروی تیم در جدولِ نیروها (بالا) آمده' : '' ?></div>
    </div>
    <?php if (!$lc['rows']): ?>
      <div class="text-center text-muted small py-3">در این بازه رکوردِ تماسی برای سرپرست ثبت نشده (اکسلِ کالیزر/نواتلِ سرپرست وارد شده؟).</div>
    <?php else: ?>
    <div class="table-responsive"><table class="table table-sm align-middle small mb-0">
      <thead class="table-light"><tr><th>#</th><th>مخاطب</th><th>نوع</th><th>کلِ تماس</th><th>برقرار</th><th>بی‌پاسخ/کوتاه</th><th>مدتِ مکالمه</th><th>طولانی‌ترین</th><th>اولین / آخرین تماس</th></tr></thead><tbody>
      <?php foreach ($lc['rows'] as $i => $r):
        $isMember = null;
        foreach (['mobile', 'mobile_2'] as $c) { $nn = !empty($r[$c]) ? normalize_phone_for_match((string) $r[$c]) : null; if ($nn && isset($memberByPhone[$nn])) { $isMember = $memberByPhone[$nn]; break; } } ?>
        <tr>
          <td><?= to_persian_digits((string) (($cPage - 1) * $cPer + $i + 1)) ?></td>
          <td><a href="customer_view.php?id=<?= (int) $r['id'] ?>"><?= e($r['full_name']) ?></a>
            <div class="text-muted" dir="ltr" style="font-size:11px;text-align:right"><?= e((string) $r['mobile']) ?></div></td>
          <td><?= e($ctypes[$r['contact_type'] ?? ''] ?? 'مشتری') ?></td>
          <td><?= to_persian_digits((string) $r['calls']) ?></td>
          <td class="text-success"><?= to_persian_digits((string) $r['connected']) ?></td>
          <td class="text-muted"><?= to_persian_digits((string) ((int) $r['calls'] - (int) $r['connected'])) ?></td>
          <td class="fw-bold"><?= e(staff_report_fmt_duration((int) $r['seconds'])) ?></td>
          <td><?= e(staff_report_fmt_duration((int) $r['longest'])) ?></td>
          <td class="text-nowrap"><?= to_jalali((string) $r['first_call']) ?><?= $r['first_call'] !== $r['last_call'] ? ' / ' . to_jalali((string) $r['last_call']) : '' ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody></table></div>
    <?php if ($cPages > 1): ?>
      <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mt-2">
        <div class="small text-muted">نمایش <?= to_persian_digits((string) (($cPage - 1) * $cPer + 1)) ?> تا <?= to_persian_digits((string) min($lc['total'], $cPage * $cPer)) ?> از <?= to_persian_digits((string) $lc['total']) ?></div>
        <ul class="pagination pagination-sm mb-0 flex-wrap">
          <li class="page-item <?= $cPage <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="<?= e($cUrl($cPage - 1)) ?>">قبلی</a></li>
          <?php $win = []; foreach ([1, $cPage - 1, $cPage, $cPage + 1, $cPages] as $pp) if ($pp >= 1 && $pp <= $cPages) $win[$pp] = true; ksort($win); $prev = 0;
            foreach (array_keys($win) as $pp): if ($prev && $pp > $prev + 1): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; $prev = $pp; ?>
            <li class="page-item <?= $pp === $cPage ? 'active' : '' ?>"><a class="page-link" href="<?= e($cUrl($pp)) ?>"><?= to_persian_digits((string) $pp) ?></a></li>
          <?php endforeach; ?>
          <li class="page-item <?= $cPage >= $cPages ? 'disabled' : '' ?>"><a class="page-link" href="<?= e($cUrl($cPage + 1)) ?>">بعدی</a></li>
        </ul>
      </div>
    <?php endif; ?>
    <?php endif; ?>
  </div>
  <?php endif; ?>
<?php endif; ?>
</div>
<?php require_once __DIR__ . '/includes/layout_bottom.php'; ?>
