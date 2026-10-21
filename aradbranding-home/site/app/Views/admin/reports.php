<?php /** @var string $metric @var string $period @var array $series @var array $byCountry @var array $leaders @var array $countries */
use App\Modules\Admin\MetricsService;
use App\Modules\Wallet\WalletService;
$periods = ['daily' => 'روزانه', 'weekly' => 'هفتگی', 'monthly' => 'ماهانه', 'yearly' => 'سالانه']; ?>
<?= $this->partial('admin/_wrap_start', ['perms' => $perms, 'active' => 'reports']) ?>
<form class="panel filters-form admin-search" method="get" action="/admin/reports">
  <select class="select" name="metric" aria-label="شاخص"><?php foreach (MetricsService::LABELS as $k => $l): ?><option value="<?= e($k) ?>"<?= $metric === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
  <select class="select" name="period" aria-label="دوره"><?php foreach ($periods as $k => $l): ?><option value="<?= e($k) ?>"<?= $period === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
  <button class="btn btn-sm" type="submit">نمایش</button>
</form>
<section class="panel"><?= $this->partial('admin/_chart', ['series' => $series, 'title' => MetricsService::LABELS[$metric] . ' · ' . $periods[$period], 'money' => $metric === 'revenue_rial']) ?></section>

<section class="panel">
  <h2>بر اساس کشور</h2>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>کشور</th><th>کاربران</th><th>صفحه منتشرشده</th><th>پیشنهاد فعال</th></tr></thead>
    <tbody><?php foreach (array_slice($byCountry, 0, 30) as $r): $c = $countries[$r['country_id']] ?? null; ?>
      <tr><td><?= $c ? e(flag($c['code']) . ' ' . $c['name_fa']) : '—' ?></td><td><?= fa_int($r['users']) ?></td><td><?= fa_int($r['pages']) ?></td><td><?= fa_int($r['proposals']) ?></td></tr>
    <?php endforeach; ?></tbody>
  </table></div>
</section>

<div class="grid-2">
  <section class="panel"><h2>دسته‌های پرکاربرد</h2><ul class="list"><?php foreach ($leaders['categories'] as $r): ?><li class="list-row"><div class="grow"><?= e($r['name_fa']) ?></div><b><?= fa_int((int) $r['n']) ?></b></li><?php endforeach; ?></ul></section>
  <section class="panel"><h2>مصرف Stars به تفکیک خدمت · ۳۰ روز</h2><ul class="list"><?php foreach ($leaders['actions'] as $r): ?><li class="list-row"><div class="grow"><?= e(WalletService::REASON_LABELS[$r['reason']] ?? $r['reason']) ?></div><b><?= fa_int((int) $r['n']) ?></b></li><?php endforeach; ?></ul></section>
  <section class="panel"><h2>بیشترین ارتباطات</h2><ul class="list"><?php foreach ($leaders['connectors'] as $r): ?><li class="list-row"><div class="grow"><?= e($r['first_name'] . ' ' . $r['last_name']) ?></div><b><?= fa_int((int) $r['n']) ?></b></li><?php endforeach; ?></ul></section>
  <section class="panel"><h2>بیشترین مصرف Stars</h2><ul class="list"><?php foreach ($leaders['spenders'] as $r): ?><li class="list-row"><div class="grow"><?= e($r['first_name'] . ' ' . $r['last_name']) ?></div><b><?= fa_int((int) $r['n']) ?></b></li><?php endforeach; ?></ul></section>
</div>
<?= $this->partial('admin/_wrap_end') ?>
