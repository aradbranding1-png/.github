<?php /** @var array $rows @var ?string $next @var string $action @var int $days */ ?>
<?= $this->partial('admin/_wrap_start', ['perms' => $perms, 'active' => 'audit']) ?>
<form class="panel filters-form admin-search" method="get" action="/admin/audit">
  <input class="input" name="action" value="<?= e($action) ?>" dir="ltr" placeholder="مثلاً wallet یا users.status" aria-label="نوع رویداد">
  <select class="select" name="days" aria-label="بازه"><?php foreach ([7, 30, 90, 365] as $d): ?><option value="<?= $d ?>"<?= $days === $d ? ' selected' : '' ?>><?= fa_num($d) ?> روز اخیر</option><?php endforeach; ?></select>
  <button class="btn btn-sm" type="submit">نمایش</button>
</form>
<section class="panel">
  <?php if ($rows === []): ?><div class="empty"><p>رویدادی پیدا نشد.</p></div><?php else: ?>
    <div class="table-wrap"><table class="table">
      <thead><tr><th>زمان</th><th>انجام‌دهنده</th><th>رویداد</th><th>هدف</th><th>نتیجه</th><th>IP</th></tr></thead>
      <tbody><?php foreach ($rows as $r): ?>
        <tr><td><?= e(fa_date($r['created_at'])) ?></td>
          <td><?= e($r['first_name'] ? $r['first_name'] . ' ' . $r['last_name'] : 'سیستم') ?><?= $r['impersonator_id'] ? ' (به جای کاربر)' : '' ?></td>
          <td class="ltr"><?= e($r['action']) ?></td><td class="ltr"><?= e(trim(($r['target_type'] ?? '') . ' ' . ($r['target_id'] ?? ''))) ?></td>
          <td><?= e($r['result']) ?></td><td class="ltr muted"><?= e($r['ip_text']) ?></td></tr>
      <?php endforeach; ?></tbody>
    </table></div>
    <?php if ($next): ?><div class="form-actions form-actions-center"><a class="btn btn-ghost btn-sm" href="/admin/audit?<?= e(http_build_query(['action' => $action ?: null, 'days' => $days, 'cursor' => $next])) ?>">قدیمی‌تر</a></div><?php endif; ?>
  <?php endif; ?>
</section>
<?= $this->partial('admin/_wrap_end') ?>
