<?php
/** کارتِ «مالکیتِ مشتری» در پروفایل ۳۶۰ — ورودی: $pdo, $id (مشتری), $user */
require_once __DIR__ . '/performance_functions.php';
if (!perf_ready($pdo)) return;
$__o = ps_owners($pdo, (int) $id);
$__uid = (int) $user['id'];
$__B = $__o['B'][0] ?? null; // B هم مثلِ A و C فقط یک نفر است
$__isA = $__o['A'] && (int) $__o['A']['user_id'] === $__uid;
$__isB = $__B && (int) $__B['user_id'] === $__uid;
$__isC = $__o['C'] && (int) $__o['C']['user_id'] === $__uid;
$__mng = perf_can('owners', $user);
$__boxMng = perf_can('box_manage', $user);
$__open = [];
$__q = $pdo->prepare("SELECT box FROM ps_box_items WHERE person_key = ? AND status = 'open'");
$__q->execute([$__o['person_key']]);
foreach ($__q->fetchAll(PDO::FETCH_COLUMN) ?: [] as $__bx) $__open[$__bx] = true;
$__srcL = ['box' => 'Box', 'payment' => 'دریافتِ پول', 'manual' => 'دستی', 'calizer' => 'کالیزر', 'novatel' => 'نواتل', 'migration' => 'مهاجرت', 'peer' => 'ارجاعِ هم‌سطح'];
$__row = static function (string $label, ?array $o) use ($__srcL) {
    if (!$o) return '<tr><td style="width:60px"><b>' . $label . '</b></td><td class="text-muted" colspan="3">خالی — سهمِ این جایگاه به ' . e(PS_ORG_LABEL) . '</td></tr>';
    return '<tr><td style="width:60px"><b>' . $label . '</b></td><td>' . e($o['full_name']) . ($o['team_id'] ? ' <span class="text-muted">(تیم ' . (int) $o['team_id'] . ')</span>' : '') . '</td>'
        . '<td class="small text-muted">' . e($__srcL[$o['source']] ?? $o['source']) . ' — ' . to_jalali(substr((string) $o['created_at'], 0, 10)) . '</td>'
        . '<td class="small">D: ' . e((string) ($o['leader_name'] ?? '—')) . '</td></tr>';
};
// ارجاعِ هم‌سطح: B ← B دیگر، C ← C دیگر (صاحبِ همان جایگاه، یا مدیر)
$__peerSlots = [];
if ($__B && ($__isB || $__mng || $__boxMng)) $__peerSlots['B'] = $__B;
if ($__o['C'] && ($__isC || $__mng || $__boxMng)) $__peerSlots['C'] = $__o['C'];
?>
<div class="card p-3 p-md-4 mb-3 mt-4" id="ps-ownership" style="border-top:3px solid #f59e0b">
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
    <h6 class="fw-bold mb-0"><i class="fa-solid fa-sitemap text-warning"></i> مالکیتِ مشتری (A / B / C / D)</h6>
    <div class="small"><?php foreach (['A', 'B', 'C'] as $__bx) if (!empty($__open[$__bx])) echo '<span class="badge text-bg-warning ms-1">در Box ' . $__bx . '</span>'; ?></div>
  </div>
  <table class="table table-sm small mb-2"><tbody>
    <?= $__row('A', $__o['A']) ?>
    <?= $__row('B', $__B) ?>
    <?= $__row('C', $__o['C']) ?>
  </tbody></table>
  <div class="d-flex flex-wrap gap-2">
    <?php if (($__isA || $__boxMng) && !$__B && empty($__open['B'])): ?>
      <form method="post" action="customer_ownership.php"><?= csrf_field() ?><input type="hidden" name="action" value="refer_b"><input type="hidden" name="customer_id" value="<?= (int) $id ?>">
        <button class="btn btn-sm btn-outline-warning" onclick="return confirm('مشتری به Box B ارجاع شود؟')"><i class="fa-solid fa-share"></i> ارجاع به Box B</button></form>
    <?php endif; ?>
    <?php if (($__isB || $__boxMng) && !$__o['C'] && empty($__open['C'])): ?>
      <form method="post" action="customer_ownership.php"><?= csrf_field() ?><input type="hidden" name="action" value="refer_c"><input type="hidden" name="customer_id" value="<?= (int) $id ?>">
        <button class="btn btn-sm btn-outline-warning" onclick="return confirm('مشتری به Box C ارجاع شود؟')"><i class="fa-solid fa-share"></i> ارجاع به Box C</button></form>
    <?php endif; ?>
    <?php if ($__boxMng && !$__o['A'] && empty($__open['A'])): ?>
      <form method="post" action="customer_ownership.php"><?= csrf_field() ?><input type="hidden" name="action" value="to_box_a"><input type="hidden" name="customer_id" value="<?= (int) $id ?>">
        <button class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-box"></i> ورود به Box A</button></form>
    <?php endif; ?>
  </div>

  <?php foreach ($__peerSlots as $__ps => $__cur): $__targets = ps_peer_targets($pdo, $__ps, (int) $__cur['user_id']); ?>
    <details class="mt-2 border rounded-3 p-2" style="background:#fffbeb" <?= ($__ps === 'B' && $__isB) || ($__ps === 'C' && $__isC) ? 'open' : '' ?>>
      <summary class="small fw-bold text-warning-emphasis"><i class="fa-solid fa-people-arrows"></i> ارجاعِ این مشتری به <?= $__ps ?>ِ دیگر (هم‌سطح)</summary>
      <?php if (!$__targets): ?>
        <div class="small text-muted mt-2">نیروی فعالِ دیگری با نقشِ <?= $__ps ?> پیدا نشد.</div>
      <?php else: ?>
        <form method="post" action="customer_peer_refer.php" class="d-flex flex-wrap gap-2 mt-2 align-items-center"
              onsubmit="return confirm('مشتری به نیروی انتخاب‌شده ارجاع شود؟ از این به بعد او <?= $__ps ?>ِ این مشتری است و نفرِ فعلی دیگر حساب نمی‌شود.')">
          <?= csrf_field() ?><input type="hidden" name="slot" value="<?= $__ps ?>"><input type="hidden" name="customer_id" value="<?= (int) $id ?>">
          <select name="to_user_id" class="form-select form-select-sm" style="max-width:320px" data-search required>
            <option value="">انتخابِ <?= $__ps ?>ِ گیرنده</option>
            <?php foreach ($__targets as $__t): ?>
              <option value="<?= (int) $__t['id'] ?>"><?= e($__t['full_name'] . ($__t['team_id'] ? ' — تیم ' . ($__t['team_name'] ?: (int) $__t['team_id']) . ($__t['leader_name'] ? ' (سرپرست: ' . $__t['leader_name'] . ')' : '') : ' — بدونِ تیم')) ?></option>
            <?php endforeach; ?>
          </select>
          <input name="note" class="form-control form-control-sm" style="max-width:220px" placeholder="توضیح (اختیاری)">
          <button class="btn btn-sm btn-warning"><i class="fa-solid fa-share"></i> ارجاع</button>
        </form>
        <div class="small text-muted mt-1">همان پرونده با همه‌ی پیگیری‌ها، وضعیت و روندِ زمانی به گیرنده منتقل می‌شود. اگر گیرنده از تیمِ دیگری باشد، سهمِ D این جایگاه به سرپرستِ تیمِ گیرنده می‌رسد.</div>
      <?php endif; ?>
    </details>
  <?php endforeach; ?>

  <?php if ($__mng):
    $__staff = ['A' => [], 'B' => [], 'C' => []];
    foreach ($pdo->query("SELECT id, full_name, role, mobile, team_id FROM users WHERE is_active = 1 AND role IN ('A','B','C') ORDER BY role, full_name")->fetchAll(PDO::FETCH_ASSOC) ?: [] as $__s) $__staff[$__s['role']][] = $__s;
    $__curBy = ['A' => $__o['A'], 'B' => $__B, 'C' => $__o['C']]; ?>
    <details class="mt-2"><summary class="small text-primary">تعیین / اصلاحِ دستیِ جایگاه‌های A ، B ، C (مدیر)</summary>
      <div class="small text-muted mt-2 mb-1">برای هر جایگاه یک نفر را انتخاب کنید؛ اگر جایگاه پر باشد، نفرِ قبلی حذف و نفرِ جدید جایش ثبت می‌شود. نوشتنِ دلیل الزامی است و در تاریخچه ثبت می‌شود.</div>
      <?php foreach (['A', 'B', 'C'] as $__sl): $__c = $__curBy[$__sl]; ?>
        <div class="d-flex flex-wrap gap-2 align-items-center border-top pt-2 mt-2">
          <span class="badge text-bg-dark" style="width:32px"><?= $__sl ?></span>
          <span class="small" style="min-width:140px"><?= $__c ? e($__c['full_name']) : '<span class="text-muted">خالی</span>' ?></span>
          <form method="post" action="customer_ownership.php" class="d-flex flex-wrap gap-2 align-items-center"><?= csrf_field() ?>
            <input type="hidden" name="action" value="owner_set"><input type="hidden" name="customer_id" value="<?= (int) $id ?>"><input type="hidden" name="slot" value="<?= $__sl ?>">
            <select name="user_id" class="form-select form-select-sm" style="max-width:240px" data-search required>
              <option value="">کارشناسِ <?= $__sl ?></option>
              <?php foreach ($__staff[$__sl] as $__s): ?><option value="<?= (int) $__s['id'] ?>" <?= $__c && (int) $__c['user_id'] === (int) $__s['id'] ? 'selected' : '' ?>><?= e(person_pick_label((string) $__s['full_name'], $__s['mobile'] ?? null, 'تیم ' . ($__s['team_id'] ?: '—'))) ?></option><?php endforeach; ?>
            </select>
            <input name="note" class="form-control form-control-sm" style="max-width:180px" placeholder="دلیل" required minlength="3">
            <button class="btn btn-sm btn-primary"><?= $__c ? 'جایگزینی' : 'ثبت' ?></button>
          </form>
          <?php if ($__c): ?>
            <form method="post" action="customer_ownership.php" class="d-inline" onsubmit="var r=prompt('دلیلِ حذفِ این جایگاه:'); if(!r) return false; this.reason.value=r; return true;"><?= csrf_field() ?>
              <input type="hidden" name="action" value="owner_del"><input type="hidden" name="customer_id" value="<?= (int) $id ?>"><input type="hidden" name="owner_id" value="<?= (int) $__c['id'] ?>"><input type="hidden" name="reason">
              <button class="btn btn-sm btn-outline-danger">حذف</button></form>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
      <div class="small text-muted mt-2">تغییرِ مالکیت روی پرداخت‌هایی که قبلاً محاسبه شده‌اند اثر ندارد (snapshot)؛ فقط سفارش‌ها و پرداخت‌های بعدی. برای تغییرِ سهمِ یک سفارشِ مشخص از «ویرایشِ سهمِ سفارش» در صفحه‌ی سهم عملکرد استفاده کنید.</div>
    </details>
  <?php endif; ?>
</div>
