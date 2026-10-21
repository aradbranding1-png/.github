<?php /** @var array $rows @var array $types */
$st = ['queued' => 'در صف', 'done' => 'آماده']; ?>
<?= $this->partial('admin/_wrap_start', ['perms' => $perms, 'active' => 'exports']) ?>
<form class="panel filters-form admin-search" method="post" action="/admin/exports">
  <?= csrf_field() ?>
  <select class="select" name="type" aria-label="نوع داده"><?php foreach ($types as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select>
  <select class="select" name="format" aria-label="فرمت"><option value="xlsx">Excel (XLSX)</option><option value="csv">CSV</option></select>
  <button class="btn btn-sm" type="submit">ساخت خروجی</button>
</form>
<section class="panel">
  <h2>خروجی‌های شما</h2>
  <p class="muted">فایل‌ها در پس‌زمینه ساخته می‌شوند (معمولاً کمتر از یک دقیقه) و فقط برای خود شما قابل دانلود هستند.</p>
  <?php if ($rows === []): ?><div class="empty"><p>هنوز خروجی نگرفته‌اید.</p></div><?php else: ?>
    <ul class="list"><?php foreach ($rows as $r): ?>
      <li class="list-row"><div class="grow"><div class="title"><?= e($types[$r['type']] ?? $r['type']) ?> · <?= e(strtoupper($r['format'])) ?></div>
        <div class="meta"><span><?= e(fa_date($r['created_at'])) ?></span><?php if ($r['status'] === 'done'): ?><span><?= fa_int((int) $r['row_count']) ?> ردیف</span><?php endif; ?></div></div>
        <?php if ($r['status'] === 'done'): ?><a class="btn btn-sm" href="/admin/exports/<?= e($r['uid']) ?>">دانلود</a><?php else: ?><span class="chip"><?= e($st[$r['status']] ?? $r['status']) ?></span><?php endif; ?></li>
    <?php endforeach; ?></ul>
  <?php endif; ?>
</section>
<?= $this->partial('admin/_wrap_end') ?>
