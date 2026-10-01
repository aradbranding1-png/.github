<?php
/**
 * قیفِ پذیرشِ نیرو: تماس → دعوت → پیگیری/یادآوری → تأیید → حضور → تعیین تکلیف
 * + عملکردِ هر نیرو بر اساسِ «جلو بردنِ افراد در مسیر»، دلایلِ عدمِ حضور، پرونده‌های رهاشده،
 *   واگذاریِ مجدد و تنظیماتِ زمان‌بندیِ یادآوری.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/reception_functions.php';
$admin = require_login();
if (!perm_page_allowed($admin)) {
    perm_deny('', $admin);
}
$pdo = db();
$ready = rp_ready($pdo);
if ($ready) {
    rp_sync($pdo, 3000);
}
$canReassign = user_can('admin_reception_staff', $admin) || user_can('admin_reception_candidates', $admin) || is_super_admin($admin);
$canSettings = user_can('admin_reception_settings', $admin) || is_super_admin($admin);

$flash = null;
$flashType = 'success';
if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = 'نشست منقضی شده است؛ دوباره تلاش کنید.';
        $flashType = 'danger';
    } elseif (($_POST['action'] ?? '') === 'reassign' && $canReassign) {
        $n = rp_reassign($pdo, (int) ($_POST['from_agent'] ?? 0), (int) ($_POST['to_agent'] ?? 0), (string) ($_POST['scope'] ?? 'all'),
            (int) normalize_digits((string) ($_POST['limit'] ?? '500')), (int) $admin['id']);
        $flash = $n > 0 ? to_persian_digits((string) $n) . ' پرونده با حفظِ کاملِ تاریخچه واگذار شد.' : 'پرونده‌ای برای واگذاری پیدا نشد (یا نیروی مبدأ و مقصد یکسان است).';
        $flashType = $n > 0 ? 'success' : 'warning';
    } elseif (($_POST['action'] ?? '') === 'settings' && $canSettings) {
        rp_save_settings($pdo, $_POST + ['initial_followup' => isset($_POST['initial_followup']) ? 1 : 0]);
        $flash = 'تنظیماتِ مسیرِ پیگیری ذخیره شد (برای دعوت‌های جدید اعمال می‌شود).';
    }
}

// ─── بازه ───
$preset = (string) ($_GET['preset'] ?? 'today');
$today = date('Y-m-d');
[$jy, $jm] = gregorian_to_jalali_arr((int) date('Y'), (int) date('m'), (int) date('d'));
$monthStart = to_gregorian(sprintf('%04d/%02d/01', $jy, $jm)) ?: date('Y-m-01');
$ranges = [
    'today' => [$today, $today, 'امروز'],
    'yesterday' => [date('Y-m-d', strtotime('-1 day')), date('Y-m-d', strtotime('-1 day')), 'دیروز'],
    '7d' => [date('Y-m-d', strtotime('-6 days')), $today, '۷ روزِ اخیر'],
    'month' => [$monthStart, $today, 'ماهِ جاری'],
    '30d' => [date('Y-m-d', strtotime('-29 days')), $today, '۳۰ روزِ اخیر'],
];
if ($preset === 'custom') {
    $from = to_gregorian(normalize_digits((string) ($_GET['from'] ?? ''))) ?: $today;
    $to = to_gregorian(normalize_digits((string) ($_GET['to'] ?? ''))) ?: $today;
    if ($from > $to) [$from, $to] = [$to, $from];
} else {
    if (!isset($ranges[$preset])) $preset = 'today';
    [$from, $to] = $ranges[$preset];
}
$agentId = (int) ($_GET['agent'] ?? 0);
$agents = $ready ? rp_agents($pdo) : [];

$f = $ready ? rp_funnel($pdo, $from, $to, $agentId) : ['steps' => [], 'metrics' => [], 'per_agent' => [], 'reasons' => [], 'outcomes' => []];
$live = $ready ? rp_counts($pdo, $agentId > 0 ? [$agentId] : []) : null;
$settings = $ready ? rp_settings($pdo) : rp_default_settings();

// بزرگ‌ترین ریزش (از «دعوت» به بعد، جایی که واحدها یکسان است)
$worst = null;
$steps = $f['steps'];
for ($i = 3; $i < count($steps); $i++) {
    $prevN = (int) $steps[$i - 1]['n'];
    if ($prevN <= 0) continue;
    $drop = 1 - ((int) $steps[$i]['n'] / $prevN);
    if ($worst === null || $drop > $worst['drop']) {
        $worst = ['drop' => $drop, 'from' => $steps[$i - 1]['label'], 'to' => $steps[$i]['label'], 'lost' => $prevN - (int) $steps[$i]['n']];
    }
}
$m = $f['metrics'];
$attRate = ($m['attended'] ?? 0) + ($m['no_show'] ?? 0) > 0 ? round(100 * $m['attended'] / ($m['attended'] + $m['no_show'])) : null;
$inv2att = ($m['invited'] ?? 0) > 0 ? round(100 * $m['attended'] / $m['invited']) : null;
$pct = static fn($a, $b) => $b > 0 ? to_persian_digits((string) round(100 * $a / $b)) . '٪' : '—';
$maxStep = max(1, ...array_map(static fn($s) => (int) $s['n'], $steps ?: [['n' => 1]]));

$pageTitle = 'قیف پذیرش نیرو';
require_once __DIR__ . '/../includes/layout_top.php';
?>
<style>
.rfn{--line:#e7e2d3;--gold:#c9a24b;--gold2:#f1dfa8}
.rfn .hero{background:linear-gradient(135deg,#0b0f1a 0%,#3d3220 55%,var(--gold) 130%);border-radius:18px;padding:18px 22px;color:#f6efdd;margin-bottom:14px}
.rfn .hero h5{color:#f6efdd;font-weight:800;margin:0}
.rfn .card{border:1px solid var(--line);border-radius:16px;box-shadow:0 4px 16px -14px rgba(28,25,23,.3)}
.rfn .chips a{border:1px solid var(--line);border-radius:20px;padding:.22rem .8rem;font-size:.8rem;text-decoration:none;color:#1c1917;background:#fff}
.rfn .chips a.on{background:linear-gradient(135deg,var(--gold2),var(--gold));border-color:transparent;font-weight:700}
.rfn .fstep{display:grid;grid-template-columns:150px 1fr 120px;gap:10px;align-items:center;margin-bottom:8px}
.rfn .fbar{height:30px;border-radius:9px;background:linear-gradient(90deg,#c9a24b,#f1dfa8);display:flex;align-items:center;padding:0 10px;font-weight:800;color:#241708;min-width:42px;transition:width .6s}
.rfn .fstep .lbl{font-weight:700;font-size:.85rem}
.rfn .fstep .drop{font-size:.75rem;color:#78716c}
.rfn .fstep .drop.bad{color:#dc2626;font-weight:700}
.rfn .kp{border:1px solid var(--line);border-radius:14px;background:#fff;padding:12px;text-align:center;height:100%}
.rfn .kp b{font-size:1.4rem;display:block}
.rfn .lb{display:flex;gap:6px;flex-wrap:wrap}
.rfn .lb a{flex:1 1 110px;border:1px solid var(--line);border-radius:12px;padding:8px 10px;text-decoration:none;color:#1c1917;background:#fff;text-align:center}
.rfn .lb a b{display:block;font-size:1.2rem}
.rfn table td,.rfn table th{font-size:.8rem;white-space:nowrap;vertical-align:middle}
.rfn .rbar{height:8px;border-radius:5px;background:#fecaca}
.rfn .rbar>span{display:block;height:100%;border-radius:5px;background:#dc2626}
@media (max-width:640px){.rfn .fstep{grid-template-columns:100px 1fr 80px}}
</style>
<div class="rfn">
  <div class="hero d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
      <h5><i class="fa-solid fa-filter"></i> قیفِ پذیرشِ نیرو — از تماس تا حضور</h5>
      <div class="small mt-1">عملکردِ واقعیِ هر نیرو: نه فقط تعدادِ تماس، بلکه چند نفر را در مسیر جلو برده است.</div>
    </div>
    <div class="d-flex gap-2">
      <a class="btn btn-sm btn-light" href="../reception_pipeline.php<?= $agentId ? '?agent=' . $agentId : '' ?>"><i class="fa-solid fa-route"></i> مسیرِ پیگیری</a>
      <a class="btn btn-sm btn-warning" href="../reception_leaderboard.php"><i class="fa-solid fa-trophy"></i> جدولِ رقابت</a>
      <a class="btn btn-sm btn-outline-light" href="../reception_supervisor_meetings.php"><i class="fa-solid fa-user-check"></i> ثبتِ حضورِ جلسات</a>
    </div>
  </div>
  <?php if ($flash): ?><div class="alert alert-<?= e($flashType) ?> py-2"><?= e($flash) ?></div><?php endif; ?>
  <?php if (!$ready): ?><div class="alert alert-warning">ماژولِ پذیرش آماده نیست.</div><?php else: ?>

  <div class="card p-3 mb-3">
    <form method="get" class="d-flex flex-wrap gap-2 align-items-center">
      <div class="chips d-flex gap-1 flex-wrap">
        <?php foreach ($ranges as $k => $r): ?>
          <a class="<?= $preset === $k ? 'on' : '' ?>" href="?<?= e(http_build_query(['preset' => $k, 'agent' => $agentId ?: null])) ?>"><?= e($r[2]) ?></a>
        <?php endforeach; ?>
      </div>
      <input type="hidden" name="preset" value="custom">
      <input name="from" class="form-control form-control-sm jalali-date" style="width:110px" placeholder="از تاریخ" value="<?= $preset === 'custom' ? e(normalize_digits(to_jalali($from))) : '' ?>">
      <input name="to" class="form-control form-control-sm jalali-date" style="width:110px" placeholder="تا تاریخ" value="<?= $preset === 'custom' ? e(normalize_digits(to_jalali($to))) : '' ?>">
      <select name="agent" class="form-select form-select-sm" style="max-width:220px">
        <option value="0">همه‌ی نیروها</option>
        <?php foreach ($agents as $ag): ?><option value="<?= (int) $ag['id'] ?>" <?= $agentId === (int) $ag['id'] ? 'selected' : '' ?>><?= e($ag['full_name']) ?></option><?php endforeach; ?>
      </select>
      <button class="btn btn-sm btn-warning">نمایش</button>
      <span class="small text-muted ms-auto"><?= to_jalali($from) ?> تا <?= to_jalali($to) ?></span>
    </form>
  </div>

  <div class="row g-3 mb-3">
    <div class="col-lg-8">
      <div class="card p-3 h-100">
        <h6 class="fw-bold mb-3"><i class="fa-solid fa-filter text-warning"></i> قیف (رویدادهای ثبت‌شده در بازه)</h6>
        <?php foreach ($steps as $i => $s):
            $prevN = $i > 0 ? (int) $steps[$i - 1]['n'] : 0;
            $isWorst = $worst && $s['label'] === $worst['to'];
        ?>
          <div class="fstep">
            <div class="lbl"><?= e($s['label']) ?></div>
            <div><div class="fbar" style="width:<?= max(4, round(100 * (int) $s['n'] / $maxStep)) ?>%"><?= to_persian_digits((string) $s['n']) ?></div></div>
            <div class="drop <?= $isWorst ? 'bad' : '' ?>"><?php if ($i > 0 && $prevN > 0): ?><?= $pct((int) $s['n'], $prevN) ?> از مرحله‌ی قبل<?php endif; ?></div>
          </div>
        <?php endforeach; ?>
        <?php if ($worst && $worst['lost'] > 0): ?>
          <div class="alert alert-danger py-2 mt-2 mb-0 small"><i class="fa-solid fa-arrow-trend-down"></i>
            بیشترین ریزش بینِ «<?= e($worst['from']) ?>» و «<?= e($worst['to']) ?>» است: <b><?= to_persian_digits((string) $worst['lost']) ?> نفر (<?= to_persian_digits((string) round($worst['drop'] * 100)) ?>٪)</b>.</div>
        <?php endif; ?>
        <div class="small text-muted mt-2">«تماس» از ورودیِ کالیزرِ نیروهای پذیرش است (یا ثبتِ تماسِ پرونده‌ها اگر کالیزر آپلود نشده). مراحلِ بعد از روی ثبت‌های «مسیرِ پیگیری» شمرده می‌شوند.</div>
      </div>
    </div>
    <div class="col-lg-4">
      <div class="row g-2">
        <div class="col-6"><div class="kp"><b class="text-success"><?= $attRate === null ? '—' : to_persian_digits((string) $attRate) . '٪' ?></b><small class="text-muted">نرخِ حضور (حاضر ÷ جلساتِ برگزارشده)</small></div></div>
        <div class="col-6"><div class="kp"><b class="text-primary"><?= $inv2att === null ? '—' : to_persian_digits((string) $inv2att) . '٪' ?></b><small class="text-muted">دعوت → حضور</small></div></div>
        <div class="col-6"><div class="kp"><b class="text-danger"><?= to_persian_digits((string) ($m['no_show'] ?? 0)) ?></b><small class="text-muted">عدمِ حضور</small></div></div>
        <div class="col-6"><div class="kp"><b style="color:#6f5520"><?= to_persian_digits((string) ($m['joined'] ?? 0)) ?></b><small class="text-muted">پذیرفته / پیوست</small></div></div>
      </div>
      <div class="card p-3 mt-2">
        <h6 class="fw-bold small mb-2"><i class="fa-solid fa-user-xmark text-danger"></i> دلایلِ عدمِ حضور</h6>
        <?php if (!$f['reasons']): ?><div class="small text-muted">موردی ثبت نشده.</div><?php endif; ?>
        <?php $rmax = max(1, ...array_values($f['reasons'] ?: [1])); foreach ($f['reasons'] as $k => $n): ?>
          <div class="d-flex justify-content-between small"><span><?= e(rp_noshow_reasons()[$k] ?? $k) ?></span><b><?= to_persian_digits((string) $n) ?></b></div>
          <div class="rbar mb-2"><span style="width:<?= round(100 * $n / $rmax) ?>%"></span></div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <div class="card p-3 mb-3">
    <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
      <h6 class="fw-bold mb-0"><i class="fa-solid fa-signal text-warning"></i> وضعیتِ لحظه‌ایِ مسیر<?= $agentId ? '' : ' (همه‌ی نیروها)' ?></h6>
      <span class="small">باز: <b><?= to_persian_digits((string) $live['open']) ?></b> | عقب‌افتاده: <b class="text-danger"><?= to_persian_digits((string) $live['overdue']) ?></b> | رهاشده: <b class="text-danger"><?= to_persian_digits((string) $live['stale']) ?></b></span>
    </div>
    <div class="lb">
      <?php foreach (rp_boxes() as $k => $b): ?>
        <a href="../reception_pipeline.php?<?= e(http_build_query(['box' => $k, 'agent' => $agentId ?: null])) ?>"><b><?= to_persian_digits((string) $live['boxes'][$k]) ?></b><small><?= e($b['label']) ?></small></a>
      <?php endforeach; ?>
      <a href="../reception_pipeline.php?<?= e(http_build_query(['box' => 'stale', 'agent' => $agentId ?: null])) ?>" style="border-color:#fecaca;background:#fef2f2"><b class="text-danger"><?= to_persian_digits((string) $live['stale']) ?></b><small>رهاشده</small></a>
    </div>
  </div>

  <div class="card p-3 mb-3">
    <h6 class="fw-bold mb-2"><i class="fa-solid fa-ranking-star text-warning"></i> عملکردِ نیروها — جلو بردنِ افراد در مسیر</h6>
    <div class="table-responsive">
      <table class="table table-sm table-hover align-middle mb-0">
        <thead class="table-light"><tr>
          <th>نیرو</th><th>تماس</th><th>متقاضیِ تماس‌گرفته</th><th>دعوت</th><th>پیگیری/یادآوری</th><th>تأیید</th><th>حاضر</th><th>غایب</th><th>نرخِ حضور</th>
          <th>تعیین تکلیف</th><th>پذیرفته</th><th title="دعوت + تأیید + حضور + پذیرش">جلو بردن</th><th>باز</th><th>عقب‌افتاده</th><th>رهاشده</th><th></th>
        </tr></thead>
        <tbody>
        <?php if (!$f['per_agent']): ?><tr><td colspan="16" class="text-center text-muted py-4">در این بازه فعالیتی ثبت نشده است.</td></tr><?php endif; ?>
        <?php foreach ($f['per_agent'] as $p): $held = $p['attended'] + $p['no_show']; ?>
          <tr>
            <td class="fw-semibold"><?= e($p['name']) ?></td>
            <td><?= to_persian_digits((string) $p['calls']) ?></td>
            <td><?= to_persian_digits((string) $p['contacted']) ?></td>
            <td><?= to_persian_digits((string) $p['invited']) ?> <span class="text-muted small">(<?= $pct($p['invited'], max($p['contacted'], 0)) ?>)</span></td>
            <td><?= to_persian_digits((string) $p['followed']) ?></td>
            <td><?= to_persian_digits((string) $p['confirmed']) ?></td>
            <td class="text-success fw-bold"><?= to_persian_digits((string) $p['attended']) ?></td>
            <td class="text-danger"><?= to_persian_digits((string) $p['no_show']) ?></td>
            <td><?= $pct($p['attended'], $held) ?></td>
            <td><?= to_persian_digits((string) $p['decided']) ?></td>
            <td><?= to_persian_digits((string) $p['joined']) ?></td>
            <td><span class="badge text-bg-warning"><?= to_persian_digits((string) $p['advanced']) ?></span></td>
            <td><?= to_persian_digits((string) $p['open']) ?></td>
            <td class="<?= $p['overdue'] > 0 ? 'text-danger fw-bold' : '' ?>"><?= to_persian_digits((string) $p['overdue']) ?></td>
            <td class="<?= $p['stale'] > 0 ? 'text-danger fw-bold' : '' ?>"><?= to_persian_digits((string) $p['stale']) ?></td>
            <td><a class="btn btn-sm btn-outline-secondary py-0" href="../reception_pipeline.php?agent=<?= (int) $p['id'] ?>" title="مسیرِ این نیرو"><i class="fa-solid fa-route"></i></a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="row g-3 mb-3">
    <div class="col-lg-4">
      <div class="card p-3 h-100">
        <h6 class="fw-bold small mb-2"><i class="fa-solid fa-flag-checkered"></i> نتایجِ تعیین تکلیف</h6>
        <?php if (!$f['outcomes']): ?><div class="small text-muted">موردی ثبت نشده.</div><?php endif; ?>
        <?php foreach ($f['outcomes'] as $k => $n): $o = rp_outcomes()[$k] ?? rp_outcomes()['other']; ?>
          <div class="d-flex justify-content-between small mb-1"><span><span class="badge text-bg-<?= e($o['color']) ?>">&nbsp;</span> <?= e($o['label']) ?></span><b><?= to_persian_digits((string) $n) ?></b></div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php if ($canReassign): ?>
    <div class="col-lg-4">
      <div class="card p-3 h-100">
        <h6 class="fw-bold small mb-2"><i class="fa-solid fa-people-arrows text-warning"></i> واگذاریِ مجددِ پرونده‌ها</h6>
        <form method="post" onsubmit="return confirm('پرونده‌ها به نیروی مقصد منتقل شوند؟ تاریخچه حفظ می‌شود.')">
          <?= csrf_field() ?><input type="hidden" name="action" value="reassign">
          <label class="small">از نیرو</label>
          <select name="from_agent" class="form-select form-select-sm mb-2" required><option value="">انتخاب…</option>
            <?php foreach ($agents as $ag): ?><option value="<?= (int) $ag['id'] ?>"><?= e($ag['full_name']) ?></option><?php endforeach; ?></select>
          <label class="small">به نیرو</label>
          <select name="to_agent" class="form-select form-select-sm mb-2" required><option value="">انتخاب…</option>
            <?php foreach ($agents as $ag): if (isset($ag['is_active']) && !(int) $ag['is_active']) continue; ?><option value="<?= (int) $ag['id'] ?>"><?= e($ag['full_name']) ?></option><?php endforeach; ?></select>
          <div class="row g-2">
            <div class="col-7"><label class="small">کدام پرونده‌ها</label>
              <select name="scope" class="form-select form-select-sm">
                <option value="all">همه‌ی پرونده‌های باز</option>
                <option value="overdue">فقط عقب‌افتاده‌ها</option>
                <option value="stale">فقط رهاشده‌ها</option>
                <?php foreach (rp_boxes() as $k => $b): if ($k === 'closed') continue; ?><option value="<?= e($k) ?>"><?= e($b['label']) ?></option><?php endforeach; ?>
              </select></div>
            <div class="col-5"><label class="small">حداکثر تعداد</label><input name="limit" class="form-control form-control-sm" value="500" dir="ltr"></div>
          </div>
          <button class="btn btn-sm btn-warning w-100 mt-2">واگذاری</button>
        </form>
      </div>
    </div>
    <?php endif; ?>
    <?php if ($canSettings): ?>
    <div class="col-lg-4">
      <div class="card p-3 h-100">
        <h6 class="fw-bold small mb-2"><i class="fa-solid fa-sliders"></i> تنظیماتِ زمان‌بندیِ مسیر</h6>
        <form method="post">
          <?= csrf_field() ?><input type="hidden" name="action" value="settings">
          <div class="row g-2 small">
            <div class="col-7">یادآوری از ساعتِ</div><div class="col-5"><input name="remind_morning_hour" class="form-control form-control-sm" dir="ltr" value="<?= (int) $settings['remind_morning_hour'] ?>"></div>
            <div class="col-7">حداقل چند ساعت قبل از جلسه</div><div class="col-5"><input name="remind_min_before" class="form-control form-control-sm" dir="ltr" value="<?= (int) $settings['remind_min_before'] ?>"></div>
            <div class="col-7">«منتظرِ نتیجه» چند دقیقه بعد از شروع</div><div class="col-5"><input name="await_minutes" class="form-control form-control-sm" dir="ltr" value="<?= (int) $settings['await_minutes'] ?>"></div>
            <div class="col-7">«رهاشده» بعد از چند ساعت بی‌اقدامی</div><div class="col-5"><input name="stale_hours" class="form-control form-control-sm" dir="ltr" value="<?= (int) $settings['stale_hours'] ?>"></div>
            <div class="col-7">«پاسخ نداد» → چند ساعت بعد دوباره</div><div class="col-5"><input name="retry_hours" class="form-control form-control-sm" dir="ltr" value="<?= (int) $settings['retry_hours'] ?>"></div>
            <div class="col-12"><label class="form-check"><input type="checkbox" class="form-check-input" name="initial_followup" value="1" <?= (int) $settings['initial_followup'] ? 'checked' : '' ?>> پیگیریِ اولیه (روزِ بعد از دعوت) برای جلساتِ دورتر از فردا</label></div>
          </div>
          <button class="btn btn-sm btn-outline-dark w-100 mt-2">ذخیره</button>
        </form>
      </div>
    </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>
<?php require_once __DIR__ . '/../includes/layout_bottom.php'; ?>
