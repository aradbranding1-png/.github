<?php
/**
 * مالی — payments, live figures for a period, a breakdown by audience (staff vs. each trade role), soft delete.
 * @var array $payments @var ?int $next @var string $status @var string $audience @var int $days @var array $audiences
 * @var array $byAudience @var array $month @var array $totals @var array $revenueSeries @var bool $canDelete @var array $errors
 */
use App\Modules\Payments\PaymentService;
$qs = static fn (array $over): string => '/admin/finance?' . http_build_query(array_filter($over + ['status' => $status, 'audience' => $audience, 'days' => $days], static fn ($v) => $v !== '' && $v !== null && $v !== 30));
$periodLabel = [7 => '۷ روز', 30 => '۳۰ روز', 90 => '۹۰ روز', 365 => 'یک سال'][$days];
?>
<?= $this->partial('admin/_wrap_start', ['perms' => $perms, 'active' => 'finance']) ?>
<section class="panel fin-period">
  <nav class="seg-tabs" aria-label="بازه">
    <?php foreach ([7 => '۷ روز', 30 => '۳۰ روز', 90 => '۹۰ روز', 365 => 'یک سال'] as $d => $l): ?>
      <a href="<?= e($qs(['days' => $d, 'before' => null])) ?>"<?= $days === $d ? ' aria-current="page"' : '' ?>><?= e($l) ?></a>
    <?php endforeach; ?>
  </nav>
</section>
<section class="stats stats-admin">
  <div class="stat"><b><?= e(toman($month['revenue'])) ?></b><span>درآمد <?= e($periodLabel) ?></span></div>
  <div class="stat"><b><?= fa_int($month['payments']) ?></b><span>پرداخت موفق</span></div>
  <div class="stat"><b><?= fa_int($month['sold']) ?></b><span>Stars فروخته‌شده</span></div>
  <div class="stat stat-gift"><b>🎁 <?= fa_int($month['bonus']) ?></b><span>Stars هدیه بسته‌های خرید</span></div>
  <div class="stat stat-gift"><b>🎁 <?= fa_int($month['gifted']) ?></b><span>کل Stars هدیه‌شده</span></div>
  <div class="stat"><b><?= fa_int($month['admin']) ?></b><span>افزایش دستی مدیر</span></div>
  <div class="stat"><b><?= fa_int($month['used']) ?></b><span>Stars مصرف‌شده</span></div>
  <div class="stat"><b><?= fa_int($month['refunded']) ?></b><span>Stars بازپرداخت‌شده</span></div>
  <div class="stat"><b><?= fa_int($totals['stars_in_wallets']) ?></b><span>Stars در کیف‌ها</span></div>
</section>
<section class="panel"><?= $this->partial('admin/_chart', ['series' => $revenueSeries, 'title' => 'درآمد روزانه (۳۰ روز)', 'money' => true]) ?></section>

<section class="panel">
  <div class="panel-head"><div><h2>گزارش به تفکیک نوع مخاطب</h2><p class="muted">پرداخت‌های موفق <?= e($periodLabel) ?> اخیر. «کارکنان سامانه» یعنی هر حسابی که نقش مدیریتی یا کارمندی دارد؛ بقیه بر اساس نقش تجاری‌شان دسته‌بندی شده‌اند.</p></div></div>
  <?php if ($byAudience === []): ?><p class="muted">در این بازه پرداخت موفقی نیست.</p><?php else: ?>
    <div class="table-wrap"><table class="table fin-table">
      <thead><tr><th>نوع مخاطب</th><th>پرداخت‌کننده</th><th>پرداخت</th><th>مبلغ</th><th>Stars</th><th>هدیه</th><th>سهم</th></tr></thead>
      <tbody><?php foreach ($byAudience as $a): $share = $month['revenue'] > 0 ? (int) round((int) $a['amount'] * 100 / $month['revenue']) : 0; ?>
        <tr>
          <td data-label="نوع مخاطب"><a href="<?= e($qs(['audience' => $a['audience'], 'before' => null])) ?>"><b><?= e($audiences[$a['audience']] ?? $a['audience']) ?></b></a></td>
          <td data-label="پرداخت‌کننده"><?= fa_int((int) $a['payers']) ?></td>
          <td data-label="پرداخت"><?= fa_int((int) $a['payments']) ?></td>
          <td data-label="مبلغ"><?= e(toman((int) $a['amount'])) ?></td>
          <td data-label="Stars"><?= fa_int((int) $a['stars']) ?></td>
          <td data-label="هدیه" class="fin-gift"><?= (int) $a['bonus'] > 0 ? '🎁 ' . fa_int((int) $a['bonus']) : '—' ?></td>
          <td data-label="سهم"><span class="fin-share"><i data-w="<?= $share ?>"></i></span> <?= fa_int($share) ?>٪</td>
        </tr>
      <?php endforeach; ?></tbody>
    </table></div>
  <?php endif; ?>
</section>

<section class="panel">
  <div class="panel-head"><h2>پرداخت‌ها</h2>
    <form class="fin-filter" method="get" action="/admin/finance">
      <?php if ($days !== 30): ?><input type="hidden" name="days" value="<?= (int) $days ?>"><?php endif; ?>
      <select class="select" name="status" aria-label="وضعیت"><option value="">همه وضعیت‌ها</option>
        <?php foreach (PaymentService::LABELS as $k => $l): ?><option value="<?= $k ?>"<?= $status === (string) $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
      <select class="select" name="audience" aria-label="نوع مخاطب"><option value="">همه مخاطبان</option>
        <?php foreach ($audiences as $k => $l): ?><option value="<?= e($k) ?>"<?= $audience === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
      <button class="btn btn-sm" type="submit">نمایش</button>
    </form>
  </div>
  <?php if ($payments === []): ?><div class="empty"><p>پرداختی با این فیلتر نیست.</p></div><?php else: ?>
    <div class="table-wrap"><table class="table fin-table fin-payments">
      <thead><tr><th>کاربر</th><th>نوع مخاطب</th><th>مبلغ</th><th>Stars خرید</th><th>هدیه</th><th>درگاه</th><th>کد پیگیری</th><th>وضعیت</th><th>تاریخ</th><?php if ($canDelete): ?><th></th><?php endif; ?></tr></thead>
      <tbody><?php foreach ($payments as $p): $err = $errors['pay' . $p['id']] ?? null; $credited = (int) $p['status'] === PaymentService::CREDITED; ?>
        <tr id="pay<?= (int) $p['id'] ?>">
          <td data-label="کاربر"><a href="/admin/users/<?= e($p['user_id']) ?>"><?= e($p['first_name'] . ' ' . $p['last_name']) ?></a></td>
          <td data-label="نوع مخاطب"><span class="chip<?= $p['audience'] === 'staff' ? ' chip-warn' : '' ?>"><?= e($audiences[$p['audience']] ?? '') ?></span></td>
          <td data-label="مبلغ"><?= e(toman((int) $p['amount_minor'])) ?></td>
          <td data-label="Stars خرید"><?= fa_int((int) $p['stars']) ?></td>
          <td data-label="هدیه" class="fin-gift"><?= (int) $p['bonus_stars'] > 0 ? '🎁 +' . fa_int((int) $p['bonus_stars']) : '—' ?></td>
          <td data-label="درگاه"><?= e($p['gateway']) ?></td>
          <td data-label="کد پیگیری" class="ltr"><?= e((string) $p['gateway_ref']) ?: '—' ?></td>
          <td data-label="وضعیت"><?= e(PaymentService::LABELS[(int) $p['status']] ?? '') ?></td>
          <td data-label="تاریخ"><?= e(fa_date($p['created_at'])) ?></td>
          <?php if ($canDelete): ?>
            <td class="fin-actions">
              <details class="fin-del"<?= $err ? ' open' : '' ?>>
                <summary class="btn btn-quiet btn-sm">حذف</summary>
                <form class="form" method="post" action="/admin/finance/payments/<?= (int) $p['id'] ?>/delete">
                  <?= csrf_field() ?>
                  <?php if ($err): ?><div class="alert alert-error" role="alert"><?= e($err) ?></div><?php endif; ?>
                  <p class="muted">پرداخت از فهرست و همه گزارش‌های مالی حذف می‌شود؛ سابقه آن در رویدادهای امنیتی می‌ماند.</p>
                  <input class="input" name="reason" maxlength="255" required placeholder="دلیل حذف (مثلاً پرداخت آزمایشی)" aria-label="دلیل حذف">
                  <?php if ($credited): ?><label class="check"><input type="checkbox" name="reverse" value="1"> <?= fa_int((int) $p['stars'] + (int) $p['bonus_stars']) ?> Star این پرداخت از کیف پول کاربر هم کسر شود</label><?php endif; ?>
                  <input class="input" name="password" type="password" autocomplete="current-password" dir="ltr" required placeholder="رمز عبور شما" aria-label="رمز عبور شما">
                  <button class="btn btn-sm btn-danger-fill" type="submit">حذف پرداخت</button>
                </form>
              </details>
            </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?></tbody>
    </table></div>
    <?php if ($next): ?><div class="form-actions form-actions-center"><a class="btn btn-ghost btn-sm" href="<?= e($qs(['before' => $next])) ?>">بیشتر</a></div><?php endif; ?>
  <?php endif; ?>
</section>
<?= $this->partial('admin/_wrap_end') ?>
