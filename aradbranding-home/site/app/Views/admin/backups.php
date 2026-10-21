<?php /** @var array $rows */
$kind = ['manual' => 'دستی', 'auto' => 'خودکار روزانه', 'update' => 'پیش از بروزرسانی']; ?>
<?= $this->partial('admin/_wrap_start', ['perms' => $perms, 'active' => 'backups']) ?>
<form class="panel form" method="post" action="/admin/backups">
  <?= csrf_field() ?>
  <div><h2>ساخت نسخه پشتیبان</h2><p class="muted">از کل دیتابیس یک فایل فشرده در پوشه backups (خارج از دسترس وب) ساخته می‌شود. پشتیبان خودکار روزانه و مدت نگه‌داری را در «تنظیمات» تعیین کنید.</p></div>
  <div class="form-actions"><input class="input narrow-wide" type="password" name="password" placeholder="رمز عبور شما" autocomplete="current-password" dir="ltr" required aria-label="رمز عبور شما"><button class="btn" type="submit">ساخت پشتیبان</button></div>
</form>
<section class="panel">
  <h2>پشتیبان‌های موجود</h2>
  <?php if ($rows === []): ?><div class="empty"><p>هنوز پشتیبانی ساخته نشده است.</p></div><?php else: ?>
    <ul class="list"><?php foreach ($rows as $b): $k = explode('-', $b['name'])[0]; ?>
      <li class="list-row">
        <div class="grow"><div class="title"><?= e($kind[$k] ?? $k) ?> · <bdi class="muted"><?= e($b['name']) ?></bdi></div>
          <div class="meta"><span><?= e(fa_date(gmdate('Y-m-d H:i:s', $b['time']))) ?></span><span><?= fa_num(number_format($b['size'] / 1048576, 1)) ?> مگابایت</span></div></div>
        <?php if ($b['has_db']): ?><a class="btn btn-ghost btn-sm" href="/admin/backups/<?= e($b['name']) ?>/database.sql.gz">دانلود دیتابیس</a><?php endif; ?>
        <?php if ($b['has_code']): ?><a class="btn btn-quiet btn-sm" href="/admin/backups/<?= e($b['name']) ?>/code.zip">کد</a><?php endif; ?>
        <form class="inline-form" method="post" action="/admin/backups/<?= e($b['name']) ?>/delete" data-confirm="این نسخه پشتیبان حذف شود؟"><?= csrf_field() ?><button class="btn btn-danger btn-sm" type="submit">حذف</button></form>
      </li>
    <?php endforeach; ?></ul>
  <?php endif; ?>
  <p class="hint muted">بازگردانی: فایل database.sql.gz را در phpMyAdmin دایرکت‌ادمین (Import) وارد کنید. راهنمای کامل در docs/BACKUP.md.</p>
</section>
<?= $this->partial('admin/_wrap_end') ?>
