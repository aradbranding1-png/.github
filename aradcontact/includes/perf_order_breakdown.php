<?php
/**
 * «Performance Breakdown» یک سفارش: snapshotِ مالکیت/درصدها + تعیین‌تکلیفِ هر پرداخت با دلیلِ هر مبلغ.
 * ورودی: $pdo, $oid ؛ اختیاری: $pbBase (پیشوندِ مسیر) ، $pbCompact (نسخه‌ی خلاصه برای صفحه‌ی سفارش)
 */
$pbBase = $pbBase ?? '';
$pbCompact = $pbCompact ?? false;
$__m = static fn($n): string => number_format((int) $n);
$__snapSt = $pdo->prepare('SELECT s.*, v.percent AS ver_percent FROM ps_order_snapshots s LEFT JOIN ps_base_versions v ON v.id = s.base_version_id WHERE s.order_id = ?');
$__snapSt->execute([$oid]);
$__snap = $__snapSt->fetch(PDO::FETCH_ASSOC);
$__calcs = $pdo->prepare('SELECT * FROM ps_payment_calcs WHERE order_id = ? ORDER BY pay_date, id');
$__calcs->execute([$oid]);
$__calcs = $__calcs->fetchAll(PDO::FETCH_ASSOC) ?: [];
// محاسبه‌های فعال (سهمِ اصلی) بالا؛ باطل‌شده‌ها زیرشان و بسته (با کلیک باز می‌شوند)
usort($__calcs, static fn($a, $b) => [(int) !empty($a['voided_at']), $a['pay_date'], (int) $a['id']] <=> [(int) !empty($b['voided_at']), $b['pay_date'], (int) $b['id']]);
$__voidedN = count(array_filter($__calcs, static fn($c) => !empty($c['voided_at'])));
$__names = [];
$__nameOf = static function ($id) use ($pdo, &$__names): string {
    if (!$id) return PS_ORG_LABEL;
    if (!isset($__names[$id])) { $q = $pdo->prepare('SELECT full_name FROM users WHERE id = ?'); $q->execute([(int) $id]); $__names[$id] = (string) ($q->fetchColumn() ?: '#' . $id); }
    return $__names[$id];
};
?>
<div class="card p-3 mb-3" id="perf-breakdown" style="border-color:#fde68a">
  <h6 class="fw-bold mb-2"><i class="fa-solid fa-trophy text-warning"></i> Performance Breakdown (سهم عملکرد)
    <?php if (!empty($__voidedN)): ?><span class="small fw-normal text-muted">— <?= to_persian_digits((string) $__voidedN) ?> محاسبه‌ی باطل‌شده (پایینِ کادر، بسته)</span><?php endif; ?></h6>
  <?php if (!$__snap): ?>
    <div class="small text-muted">هنوز محاسبه‌ای نیست (بعد از اولین پرداختِ تأییدشده ساخته می‌شود).</div>
  <?php else: $__own = json_decode((string) $__snap['owners_json'], true) ?: []; ?>
    <div class="small mb-2">
      سهمِ پایه: <b><?= (float) $__snap['base_percent'] ?>٪</b> (نسخه‌ی #<?= (int) $__snap['base_version_id'] ?>) — مالیات: <b><?= (float) $__snap['tax_percent'] ?>٪</b> —
      مالکیت در لحظه‌ی ثبتِ سفارش<?= $__snap['source'] === 'migrated' ? ' <span class="badge text-bg-warning" title="سفارش قبل از راه‌اندازیِ مدلِ جدید ثبت شده؛ مالکیت در اولین محاسبه ثبت شد">مهاجرت</span>' : '' ?>:
      A: <?= e($__own['A']['name'] ?? '—') ?> ·
      B: <?= $__own['B'] ? e((string) ($__own['B'][0]['name'] ?? '—')) : '—' ?> ·
      C: <?= e($__own['C']['name'] ?? '—') ?><?= !empty($__own['direct_d']) ? ' · سفارشِ مستقیمِ سرپرست: ' . e($__own['direct_d']['name']) : '' ?>
      <?php if (!empty($__snap['registrant_user_id'])): $__reg = $pdo->prepare('SELECT full_name, role FROM users WHERE id = ?'); $__reg->execute([(int) $__snap['registrant_user_id']]); $__reg = $__reg->fetch(PDO::FETCH_ASSOC); ?>
        <br>ثبت‌کننده‌ی سفارش: <b><?= e((string) ($__reg['full_name'] ?? '—')) ?></b> (<?= e((string) ($__reg['role'] ?? '')) ?>)<?= !empty($__own['manual_edit']) ? ' · <span class="badge text-bg-danger">ویرایشِ دستی</span>' : '' ?>
      <?php endif; ?>
    </div>
    <?php foreach ($__calcs as $__c):
      $__ls = $pdo->prepare('SELECT * FROM ps_lines WHERE calc_id = ? ORDER BY FIELD(unit,\'A\',\'B\',\'C\',\'D\',\'O\'), id'); $__ls->execute([(int) $__c['id']]); $__ls = $__ls->fetchAll(PDO::FETCH_ASSOC) ?: [];
      $__sum = array_sum(array_map(static fn($l) => (int) $l['amount'], $__ls)); ?>
      <?php if ($__c['voided_at']): ?>
      <details class="border rounded-3 p-2 mb-2" style="background:#f8f8f7">
        <summary class="small" style="cursor:pointer">
          <span class="badge text-bg-secondary">باطل‌شده</span>
          واریزِ <?= to_jalali($__c['pay_date']) ?>: <?= $__m($__c['paid_amount']) ?> تومان — Pool <?= $__m($__c['pool']) ?>
          <span class="text-muted">(برای دیدنِ جزئیات کلیک کنید)</span>
        </summary>
        <div class="small text-muted mt-1" style="white-space:normal;overflow-wrap:anywhere">دلیلِ ابطال: <?= e((string) $__c['void_reason']) ?> — <?= to_jalali(substr((string) $__c['voided_at'], 0, 10)) ?></div>
        <div class="opacity-75">
      <?php else: ?>
      <div class="border rounded-3 p-2 mb-2" style="border-color:#86efac !important">
        <div><span class="badge text-bg-success">فعال — سهمِ اصلی</span></div>
      <?php endif; ?>
        <div class="small fw-bold" style="overflow-wrap:anywhere">واریزِ <?= to_jalali($__c['pay_date']) ?>: <?= $__m($__c['paid_amount']) ?> تومان</div>
        <div class="small text-muted" style="overflow-wrap:anywhere">مالیات <?= number_format((float) $__c['tax_amount'], 2) ?> ← خالصِ خدمات <?= number_format((float) $__c['net_amount'], 2) ?> ← × <?= (float) $__c['base_percent'] ?>٪ = Pool <b><?= $__m($__c['pool']) ?></b></div>
        <table class="table table-sm small mb-1 mt-1"><tbody>
          <?php foreach ($__ls as $__l): ?>
            <tr><td style="width:70px"><b><?= e(ps_slot_label((string) $__l['slot'])) ?></b></td><td><?= e($__nameOf($__l['user_id'])) ?><?= $__l['team_id'] ? ' <span class="text-muted">(تیم ' . (int) $__l['team_id'] . ')</span>' : '' ?>
              <?php if (!$pbCompact): ?><div class="text-muted" style="font-size:11px"><?= e(ps_label_fix(str_replace('→', '←', (string) $__l['reason']))) ?></div><?php endif; ?></td>
              <td class="text-nowrap text-end <?= $__l['user_id'] ? 'fw-bold' : 'text-muted' ?>"><?= $__m($__l['amount']) ?></td></tr>
          <?php endforeach; ?>
          <tr class="table-light"><td colspan="2">جمع (کارشناسان <?= $__m($__c['distributed']) ?> + سازمان <?= $__m($__c['org_total']) ?>)</td>
            <td class="text-end fw-bold <?= $__sum === (int) $__c['pool'] ? 'text-success' : 'text-danger' ?>"><?= $__m($__sum) ?> <?= $__sum === (int) $__c['pool'] ? '= Pool ✓' : '≠ Pool!' ?></td></tr>
        </tbody></table>
      <?php if ($__c['voided_at']): ?></div></details><?php else: ?></div><?php endif; ?>
    <?php endforeach; ?>
    <?php if (!$__calcs): ?><div class="small text-muted">هنوز پرداختِ تأییدشده‌ای محاسبه نشده.</div><?php endif; ?>
  <?php endif; ?>
</div>
