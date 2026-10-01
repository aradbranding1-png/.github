<?php
/**
 * جلساتِ پذیرشِ برگزارکننده (سرپرست): لیستِ افرادِ هر روز + ثبتِ سریعِ حاضر / غایب.
 * با ثبتِ حضور، سامانه خودش متقاضی را به مرحله‌ی بعد می‌برد:
 *   حاضر → پیگیریِ بعد از جلسه   |   غایب → عدم حضور (جلسه‌ی مجدد)
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/reception_functions.php';
$user = require_login();
$pdo = db();

$isManager = rp_is_manager($user) || ($user['role'] ?? '') === 'admin' || is_super_admin($user);
if (!$isManager && !user_can('reception_supervisor_meetings_view', $user)) {
    perm_deny('', $user);
}
$ready = rp_ready($pdo);
if ($ready) {
    rp_sync($pdo, 1500);
}
$slotsReady = reception_meeting_slots_ready($pdo);
$rxOn = function_exists('rx_ready') && rx_ready($pdo);
// ─── بعد از «حاضر» در جلسه‌ی حضوری: به کدام سرپرست/واحد ارجاع شد + اعلامِ حضورِ برگزارکننده ───
if ($rxOn && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $back = 'reception_supervisor_meetings.php' . ($_GET ? '?' . http_build_query($_GET) : '');
    if (!csrf_verify()) { flash_set('danger', 'نشست منقضی شده است.'); redirect($back); }
    $act = (string) ($_POST['action'] ?? '');
    if ($act === 'refer_supervisor') {
        $ivId = (int) ($_POST['interview_id'] ?? 0);
        $iv = $pdo->prepare("SELECT applicant_id, supervisor_user_id, status FROM reception_inperson_interviews WHERE id = ?");
        $iv->execute([$ivId]);
        $iv = $iv->fetch(PDO::FETCH_ASSOC);
        if (!$iv || $iv['status'] !== 'done') {
            flash_set('danger', 'ارجاع فقط بعد از ثبتِ «حاضر» ممکن است.');
        } elseif (!$isManager && (int) $iv['supervisor_user_id'] !== (int) $user['id']) {
            flash_set('danger', 'اجازه‌ی این کار را ندارید.');
        } else {
            $r = rx_refer_to_supervisor($pdo, (int) $iv['applicant_id'], (int) ($_POST['supervisor_id'] ?? 0), $user, $ivId);
            flash_set($r['ok'] ? 'success' : 'danger', $r['message']);
        }
    } elseif ($act === 'host_checkin') {
        $hk = (string) ($_POST['kind'] ?? '');
        $hh = (int) ($_POST['host_id'] ?? 0);
        if (!$isManager && $hh !== (int) $user['id']) {
            flash_set('danger', 'فقط خودِ برگزارکننده می‌تواند اعلامِ حضور کند.');
        } else {
            $r = rx_host_checkin($pdo, $hk, $hh, (string) ($_POST['meet_date'] ?? ''), (string) ($_POST['meet_time'] ?? ''), (int) $user['id']);
            flash_set($r['ok'] ? 'success' : 'danger', $r['message']);
        }
    }
    redirect($back);
}
$ipReady = reception_inperson_table_ready($pdo);

$hostId = (int) $user['id'];
$hosts = [];
if ($isManager) {
    try {
        $hosts = $pdo->query("SELECT id, full_name FROM users WHERE role = 'leader' AND is_active = 1
            OR id IN (SELECT DISTINCT supervisor_user_id FROM reception_meeting_slots)
            ORDER BY full_name ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $hosts = $pdo->query("SELECT id, full_name FROM users WHERE role = 'leader' ORDER BY full_name ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
    $hostId = (int) ($_GET['host'] ?? ($_GET['supervisor_id'] ?? 0));
    if ($hostId <= 0) {
        $hostId = in_array((int) $user['id'], array_map(static fn($h) => (int) $h['id'], $hosts), true) ? (int) $user['id'] : 0;
    }
}

$d = (string) ($_GET['d'] ?? date('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
    $d = date('Y-m-d');
}
$prev = date('Y-m-d', strtotime($d . ' -1 day'));
$next = date('Y-m-d', strtotime($d . ' +1 day'));

$rows = [];
$hasAtt = $ready && rp_bookings_have_attendance($pdo);
if ($slotsReady) {
    try {
        $sql = "SELECT 'online' AS kind, bk.id AS ref, bk.applicant_id, s.slot_date AS mdate, s.start_time AS mtime, s.supervisor_user_id AS host_id,
                   " . ($hasAtt ? 'bk.attendance, bk.attendance_reason' : 'NULL AS attendance, NULL AS attendance_reason') . ",
                   ra.first_name, ra.last_name, ra.mobile, ra.mobile_normalized, ag.full_name AS agent_name, ho.full_name AS host_name
                FROM reception_meeting_bookings bk
                JOIN reception_meeting_slots s ON s.id = bk.slot_id
                JOIN reception_applicants ra ON ra.id = bk.applicant_id
                LEFT JOIN users ag ON ag.id = bk.agent_user_id
                LEFT JOIN users ho ON ho.id = s.supervisor_user_id
                WHERE bk.status = 'booked' AND s.slot_date = ?" . ($hostId > 0 ? ' AND s.supervisor_user_id = ?' : '');
        $st = $pdo->prepare($sql);
        $st->execute($hostId > 0 ? [$d, $hostId] : [$d]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('host meetings online: ' . $e->getMessage());
    }
}
if ($ipReady) {
    try {
        $sql = "SELECT 'inperson' AS kind, ii.id AS ref, ii.applicant_id, " . ($rxOn ? "(SELECT full_name FROM users WHERE id = ii.referred_supervisor_id) AS referred_name," : "NULL AS referred_name,") . " ii.interview_date AS mdate, ii.interview_time AS mtime, ii.supervisor_user_id AS host_id,
                   CASE ii.status WHEN 'done' THEN 'attended' WHEN 'no_show' THEN 'no_show' ELSE NULL END AS attendance, ii.result_note AS attendance_reason,
                   ra.first_name, ra.last_name, ra.mobile, ra.mobile_normalized, ag.full_name AS agent_name, ho.full_name AS host_name
                FROM reception_inperson_interviews ii
                JOIN reception_applicants ra ON ra.id = ii.applicant_id
                LEFT JOIN users ag ON ag.id = ii.agent_user_id
                LEFT JOIN users ho ON ho.id = ii.supervisor_user_id
                WHERE ii.status <> 'cancelled' AND ii.interview_date = ?" . ($hostId > 0 ? ' AND ii.supervisor_user_id = ?' : '');
        $st = $pdo->prepare($sql);
        $st->execute($hostId > 0 ? [$d, $hostId] : [$d]);
        $rows = array_merge($rows, $st->fetchAll(PDO::FETCH_ASSOC) ?: []);
    } catch (Throwable $e) {
        error_log('host meetings inperson: ' . $e->getMessage());
    }
}
// وضعیتِ مسیرِ پیگیری (یادآوری/تأیید) برای هر نفر
$pipe = [];
if ($ready && $rows) {
    $ids = array_values(array_unique(array_map(static fn($r) => (int) $r['applicant_id'], $rows)));
    $st = $pdo->prepare('SELECT * FROM reception_pipeline WHERE applicant_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
    $st->execute($ids);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $p) {
        $pipe[(int) $p['applicant_id']] = $p;
    }
}
foreach ($rows as &$r) {
    $r['at'] = rp_meeting_datetime((string) $r['mdate'], (string) $r['mtime']);
    $p = $pipe[(int) $r['applicant_id']] ?? null;
    $r['p'] = $p;
    $r['linked'] = $p && (string) $p['meeting_kind'] === $r['kind'] && (int) $p['meeting_ref'] === (int) $r['ref'];
}
unset($r);
usort($rows, static fn($a, $b) => strcmp($a['at'], $b['at']) ?: strcmp($a['last_name'], $b['last_name']));

$sum = ['total' => count($rows), 'attended' => 0, 'no_show' => 0, 'pending' => 0, 'confirmed' => 0];
foreach ($rows as $r) {
    if ($r['attendance'] === 'attended') $sum['attended']++;
    elseif ($r['attendance'] === 'no_show') $sum['no_show']++;
    else $sum['pending']++;
    if ($r['p'] && $r['linked'] && in_array($r['p']['reminder_status'], ['confirmed'], true)) $sum['confirmed']++;
}
$remStates = rp_reminder_states();
$referTargets = $rxOn ? rx_supervisors($pdo) : [];
$reasons = rp_noshow_reasons();

$pageTitle = 'جلسات پذیرش — ثبت حضور';
require_once __DIR__ . '/includes/layout_top.php';
?>
<style>
.hm{--line:#e7e2d3;--gold:#c9a24b;--gold2:#f1dfa8}
.hm .hero{background:linear-gradient(135deg,#0b0f1a 0%,#3d3220 55%,var(--gold) 130%);border-radius:18px;padding:16px 20px;color:#f6efdd;margin-bottom:14px}
.hm .hero h5{color:#f6efdd;font-weight:800;margin:0}
.hm .k{border:1px solid var(--line);border-radius:14px;background:#fff;padding:10px 12px;text-align:center}
.hm .k b{font-size:1.35rem;display:block}
.hm .row-m{border:1px solid var(--line);border-radius:14px;background:#fff;padding:10px 14px;margin-bottom:8px;display:flex;align-items:center;gap:12px;flex-wrap:wrap}
.hm .row-m .tm{font-weight:800;font-size:1.05rem;min-width:58px;text-align:center;color:#6f5520}
.hm .row-m .who{flex:1 1 220px}
.hm .row-m.att{border-color:#bbf7d0;background:#f7fef9}
.hm .row-m.abs{border-color:#fecaca;background:#fffafa}
.hm .big-btn{font-size:.9rem;padding:.45rem .9rem;border-radius:11px;font-weight:700}
</style>
<div class="hm">
  <div class="hero d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
      <h5><i class="fa-solid fa-user-check"></i> جلساتِ پذیرش — ثبتِ حضور</h5>
      <div class="small mt-1">بعد از جلسه فقط «حاضر» یا «غایب» را بزنید؛ سامانه بقیه‌ی مسیر را خودش برای نیروی پذیرش می‌سازد.</div>
    </div>
    <a href="meetings_hub.php" class="btn btn-sm btn-light">بازگشت به جلسات</a>
  </div>

  <form method="get" class="d-flex flex-wrap gap-2 align-items-center mb-3">
    <?php if ($isManager): ?>
      <select name="host" class="form-select form-select-sm" style="max-width:260px" onchange="this.form.submit()">
        <option value="0">همه‌ی برگزارکننده‌ها</option>
        <?php foreach ($hosts as $h): ?><option value="<?= (int) $h['id'] ?>" <?= $hostId === (int) $h['id'] ? 'selected' : '' ?>><?= e($h['full_name']) ?></option><?php endforeach; ?>
      </select>
    <?php endif; ?>
    <input type="hidden" name="d" value="<?= e($d) ?>">
    <div class="btn-group btn-group-sm">
      <a class="btn btn-outline-secondary" href="?<?= e(http_build_query(array_merge($_GET, ['d' => $prev]))) ?>"><i class="fa-solid fa-chevron-right"></i> روزِ قبل</a>
      <a class="btn btn-<?= $d === date('Y-m-d') ? 'warning' : 'outline-secondary' ?>" href="?<?= e(http_build_query(array_merge($_GET, ['d' => date('Y-m-d')]))) ?>">امروز</a>
      <a class="btn btn-outline-secondary" href="?<?= e(http_build_query(array_merge($_GET, ['d' => $next]))) ?>">روزِ بعد <i class="fa-solid fa-chevron-left"></i></a>
    </div>
    <b class="ms-2"><?= to_jalali($d) ?></b>
  </form>

  <div class="row g-2 mb-3">
    <div class="col-6 col-md"><div class="k"><b><?= to_persian_digits((string) $sum['total']) ?></b><small class="text-muted">نفر دعوت‌شده</small></div></div>
    <div class="col-6 col-md"><div class="k"><b class="text-primary"><?= to_persian_digits((string) $sum['confirmed']) ?></b><small class="text-muted">تأییدِ حضور (قبل از جلسه)</small></div></div>
    <div class="col-4 col-md"><div class="k"><b class="text-success"><?= to_persian_digits((string) $sum['attended']) ?></b><small class="text-muted">حاضر</small></div></div>
    <div class="col-4 col-md"><div class="k"><b class="text-danger"><?= to_persian_digits((string) $sum['no_show']) ?></b><small class="text-muted">غایب</small></div></div>
    <div class="col-4 col-md"><div class="k"><b class="text-warning"><?= to_persian_digits((string) $sum['pending']) ?></b><small class="text-muted">ثبت‌نشده</small></div></div>
  </div>

  <?php if ($rxOn && $rows): $__sess = rm_sessions($pdo, $d, $d, $hostId); $__states = rm_session_states(); ?>
    <div class="card p-3 mb-3">
      <div class="fw-bold small mb-2"><i class="fa-solid fa-door-open text-warning"></i> جلسه‌های این روز — اعلامِ حضورِ جلسه‌رونده
        <span class="text-muted fw-normal">(جلسه‌رونده قبل از شروع «حضورم را ثبت کن» را بزند؛ جلسه‌ای که وقتش گذشته و نه حضوری اعلام شده و نه حاضر/غایبی ثبت شده، «بدونِ آمار — احتمالاً جلسه‌رونده حاضر نبوده» حساب می‌شود)</span></div>
      <div class="table-responsive"><table class="table table-sm small align-middle mb-0">
        <thead class="table-light"><tr><th>ساعت</th><th>نوع</th><th>جلسه‌رونده</th><th>دعوت‌شده</th><th>حاضر</th><th>غایب</th><th>ثبت‌نشده</th><th>وضعیت</th><th></th></tr></thead><tbody>
        <?php foreach ($__sess as $__s): $__st = $__states[$__s['state']];
          // دکمه‌ی «اعلامِ حضور» فقط از ۳۰ دقیقه قبل از شروع نشان داده می‌شود
          $__early = ($__ts = strtotime($__s['d'] . ' ' . rx_hhmm((string) $__s['t']) . ':00')) !== false && time() < $__ts - 1800;
          $__s['checkin_soon'] = $__early && !$__s['checkin'] && $__s['host'] > 0 && ($isManager || $__s['host'] === (int) $user['id']); ?>
          <tr><td dir="ltr" class="fw-bold"><?= e($__s['t']) ?></td><td><?= $__s['kind'] === 'online' ? 'آنلاین' : 'حضوری' ?></td><td><?= e($__s['host_name']) ?></td>
            <td><?= to_persian_digits((string) $__s['invited']) ?><?= $__s['cap'] ? ' / ' . to_persian_digits((string) $__s['cap']) : '' ?></td>
            <td class="text-success"><?= to_persian_digits((string) $__s['att']) ?></td><td class="text-danger"><?= to_persian_digits((string) $__s['ns']) ?></td><td class="text-warning"><?= to_persian_digits((string) $__s['pending']) ?></td>
            <td><span class="badge text-bg-<?= e($__st['color']) ?>"><?= e($__st['label']) ?></span>
              <?php if ($__s['checkin']): ?><div class="text-success" style="font-size:11px"><i class="fa-solid fa-circle-check"></i> اعلامِ حضور: <?= e(substr((string) $__s['checkin'], 11, 5)) ?></div><?php endif; ?></td>
            <td><?php if (!$__s['checkin'] && $d === date('Y-m-d') && ($isManager || $__s['host'] === (int) $user['id']) && $__s['host'] > 0 && !$__early && !in_array($__s['state'], ['held'], true)): ?>
              <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="host_checkin"><input type="hidden" name="kind" value="<?= e($__s['kind']) ?>">
                <input type="hidden" name="host_id" value="<?= (int) $__s['host'] ?>"><input type="hidden" name="meet_date" value="<?= e($__s['d']) ?>"><input type="hidden" name="meet_time" value="<?= e($__s['t']) ?>">
                <button class="btn btn-sm btn-warning py-0"><i class="fa-solid fa-hand"></i> <?= $__s['host'] === (int) $user['id'] ? 'حضورم را ثبت کن' : 'ثبتِ حضورِ جلسه‌رونده' ?></button></form>
            <?php elseif ($__s['checkin_soon'] ?? false): ?><span class="text-muted" style="font-size:11px">اعلامِ حضور از ۳۰ دقیقه قبل</span>
            <?php endif; ?></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
    </div>
  <?php endif; ?>

  <?php if (!$rows): ?>
    <div class="card p-4 text-center text-muted">برای این روز جلسه‌ای ثبت نشده است.</div>
  <?php endif; ?>

  <?php foreach ($rows as $r):
      $name = trim($r['first_name'] . ' ' . $r['last_name']);
      $mobile = (string) ($r['mobile_normalized'] ?: $r['mobile']);
      $cls = $r['attendance'] === 'attended' ? 'att' : ($r['attendance'] === 'no_show' ? 'abs' : '');
      $canMark = $r['linked'] && in_array($r['p']['stage'], ['invited', 'attended', 'no_show'], true) && (rp_ts($r['at']) ?? 0) <= time() + 3600;
  ?>
    <div class="row-m <?= $cls ?>" data-rp-row="<?= (int) $r['applicant_id'] ?>">
      <div class="tm" dir="ltr"><?= e(substr(rp_parse_time((string) $r['mtime']), 0, 5)) ?></div>
      <div class="who">
        <div class="fw-bold"><?= e($name) ?>
          <span class="badge <?= $r['kind'] === 'inperson' ? 'text-bg-light border' : 'text-bg-primary' ?> ms-1"><?= $r['kind'] === 'inperson' ? 'حضوری' : 'آنلاین' ?></span>
          <?php if ($r['linked'] && $r['p']['stage'] === 'invited'): $rs = $remStates[$r['p']['reminder_status']] ?? $remStates['none']; ?>
            <span class="badge text-bg-<?= e($rs['color']) ?>"><?= e($rs['label']) ?></span>
          <?php endif; ?>
        </div>
        <div class="small text-muted"><a href="tel:<?= e($mobile) ?>" dir="ltr" class="text-decoration-none"><?= e($mobile) ?></a>
          | نیروی پذیرش: <?= e((string) ($r['agent_name'] ?? '—')) ?><?= $hostId <= 0 ? ' | برگزارکننده: ' . e((string) ($r['host_name'] ?? '—')) : '' ?></div>
      </div>
      <div class="d-flex gap-2 flex-wrap align-items-center">
        <?php if ($r['attendance'] === 'attended'): ?>
          <span class="badge text-bg-success fs-6"><i class="fa-solid fa-user-check"></i> حاضر</span>
          <?php if ($r['kind'] === 'inperson' && $rxOn): ?>
            <?php if (!empty($r['referred_name'])): ?>
              <span class="badge text-bg-light border fs-6"><i class="fa-solid fa-user-tie text-success"></i> ارجاع به: <?= e((string) $r['referred_name']) ?></span>
            <?php elseif ($isManager || (int) $r['host_id'] === (int) $user['id']): ?>
              <form method="post" class="d-flex gap-1 align-items-center"><?= csrf_field() ?><input type="hidden" name="action" value="refer_supervisor"><input type="hidden" name="interview_id" value="<?= (int) $r['ref'] ?>">
                <select name="supervisor_id" class="form-select form-select-sm" style="min-width:170px" required><option value="">ارجاع به کدام سرپرست؟</option>
                  <?php foreach ($referTargets as $__sv): ?><option value="<?= (int) $__sv['id'] ?>"><?= e($__sv['full_name'] . (!empty($__sv['team_id']) ? ' — ' . team_display_name($__sv['team_name'] ?? null, (int) $__sv['team_id']) : '')) ?></option><?php endforeach; ?></select>
                <button class="btn btn-sm btn-success"><i class="fa-solid fa-share"></i> ارجاع</button></form>
            <?php endif; ?>
          <?php endif; ?>
        <?php elseif ($r['attendance'] === 'no_show'): ?>
          <span class="badge text-bg-danger fs-6"><i class="fa-solid fa-user-xmark"></i> غایب<?= $r['attendance_reason'] ? ' — ' . e($reasons[$r['attendance_reason']] ?? $r['attendance_reason']) : '' ?></span>
        <?php endif; ?>
        <?php if ($canMark): ?>
          <button type="button" class="btn btn-success big-btn" data-act="attended" data-kind="direct" data-id="<?= (int) $r['applicant_id'] ?>" data-name="<?= e($name) ?>"><i class="fa-solid fa-check"></i> حاضر</button>
          <button type="button" class="btn btn-outline-danger big-btn" data-act="no_show" data-kind="reason" data-id="<?= (int) $r['applicant_id'] ?>" data-name="<?= e($name) ?>"><i class="fa-solid fa-xmark"></i> غایب</button>
        <?php elseif (!$r['attendance'] && (rp_ts($r['at']) ?? 0) > time() + 3600): ?>
          <span class="small text-muted"><i class="fa-regular fa-clock"></i> <?= e(rp_relative($r['at'])) ?></span>
        <?php elseif (!$r['linked'] && !$r['attendance']): ?>
          <span class="small text-muted">جلسه‌ی دیگری برای این فرد ثبت شده</span>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
</div>
<?php require __DIR__ . '/includes/reception_pipeline_modal.php'; ?>
<script>window.RP_CONFIG = { endpoint: 'reception_pipeline_action.php', reload: true };</script>
<script src="assets/js/reception-pipeline.js?v=1"></script>
<?php require_once __DIR__ . '/includes/layout_bottom.php'; ?>
