<?php /** @var string $version @var array $history @var ?array $result @var array $errors @var string $uploadLimit */
$statusLabel = ['success' => 'موفق', 'failed' => 'ناموفق', 'rolled_back' => 'بازگردانده شد', 'running' => 'در حال اجرا'];
$statusChip = ['success' => 'chip-ok', 'failed' => 'chip-danger', 'rolled_back' => 'chip-danger', 'running' => 'chip-gold'];
$steps = [
    ['نگه‌داری', 'سامانه چند لحظه به حالت نگه‌داری می‌رود.'],
    ['جایگزینی', 'فایل‌ها جایگزین و Migrationها اجرا می‌شوند.'],
    ['بررسی سلامت', 'در صورت خطا، کد قبلی خودکار بازمی‌گردد.'],
]; ?>
<?= $this->partial('admin/_wrap_start', ['perms' => $perms, 'active' => 'update']) ?>

<header class="su-head">
  <h1 class="su-title">بروزرسانی سامانه</h1>
  <span class="chip chip-gold su-version">نسخه فعلی: <bdi><?= e($version) ?></bdi></span>
</header>

<section class="panel su-panel">
  <form class="su-form" method="post" action="/admin/system-update" enctype="multipart/form-data" data-su-form>
    <?= csrf_field() ?>
    <div class="su-row">
      <div class="field su-field-file<?= isset($errors['package']) ? ' has-error' : '' ?>">
        <input class="su-file" id="f-package" type="file" name="package" accept=".zip,application/zip" required>
        <label class="su-drop" for="f-package">
          <span class="su-drop-icon" aria-hidden="true">⇪</span>
          <span class="su-drop-body"><span class="su-drop-text" data-su-name>انتخاب فایل ZIP بروزرسانی</span><span class="muted su-drop-hint">سقف آپلود سرور: <bdi><?= e($uploadLimit) ?></bdi></span></span>
        </label>
        <?php if (isset($errors['package'])): ?><div class="error"><?= e($errors['package']) ?></div><?php endif; ?>
      </div>
      <div class="field su-field-pass<?= isset($errors['password']) ? ' has-error' : '' ?>">
        <input class="input" id="f-password" type="password" name="password" placeholder="رمز عبور شما (برای تأیید)" aria-label="رمز عبور شما" autocomplete="current-password" dir="ltr" required>
        <?php if (isset($errors['password'])): ?><div class="error"><?= e($errors['password']) ?></div><?php endif; ?>
      </div>
      <button class="btn su-go" type="submit" data-su-submit>شروع بروزرسانی</button>
    </div>
    <div class="su-options">
      <label class="switch" title="دیتابیس و کد در پوشه backups ذخیره می‌شود؛ خاموش = سریع‌تر">
        <input type="checkbox" name="full_backup" value="1" role="switch"><span class="switch-track" aria-hidden="true"></span><span>نسخه پشتیبان کامل</span>
      </label>
      <label class="check" title="خودکار از روی CHANGELOG بسته"><input type="checkbox" name="publish_note" value="1"><span>انتشار یادداشت «تازه‌های سامانه»</span></label>
      <label class="check" title="همان یادداشت به‌صورت نامه رسمی برای همه"><input type="checkbox" name="announce" value="1"><span>ارسال نامه رسمی به همه</span></label>
    </div>
    <p class="muted su-note">مراحل: نگه‌داری ← جایگزینی فایل‌ها و Migration ← بررسی سلامت (در صورت خطا، کد قبلی خودکار برمی‌گردد). .env، storage و تصاویر کاربران دست نمی‌خورند. تا پایان کار، صفحه را نبندید.</p>
  </form>
</section>

<section class="panel su-panel su-export">
  <form class="su-form" method="post" action="/admin/system-update/export">
    <?= csrf_field() ?>
    <div class="su-row">
      <div class="su-export-text">
        <b>خروجی آخرین نسخه روی هاست (ZIP)</b>
        <span class="muted">بعد از دانلود از هاست پاک می‌شود. بدون media، backups، storage، vendor و .env.</span>
      </div>
      <div class="field su-field-pass<?= isset($errors['export_password']) || isset($errors['export']) ? ' has-error' : '' ?>">
        <input class="input" id="f-export-password" type="password" name="password" placeholder="رمز عبور شما" aria-label="رمز عبور شما" autocomplete="current-password" dir="ltr" required>
        <?php if (isset($errors['export_password'])): ?><div class="error"><?= e($errors['export_password']) ?></div><?php endif; ?>
        <?php if (isset($errors['export'])): ?><div class="error"><?= e($errors['export']) ?></div><?php endif; ?>
      </div>
      <button class="btn btn-ghost su-go" type="submit">ساخت و دانلود ZIP</button>
    </div>
  </form>
</section>

<?php if ($result): ?>
  <section class="panel">
    <div class="panel-head"><h2>گزارش آخرین بروزرسانی</h2><span class="chip<?= $result['ok'] ? ' chip-ok' : ' chip-danger' ?>"><?= $result['ok'] ? 'موفق' : 'ناموفق' ?></span></div>
    <pre class="log" dir="ltr"><?= e(implode("\n", $result['log'])) ?></pre>
    <?php if ($result['ok'] && empty($result['note_published'])): ?>
      <p class="muted">می‌خواهید به کاربران اطلاع دهید چه امکاناتی اضافه شده است؟</p>
      <div class="form-actions"><a class="btn" href="/updates">نوشتن یادداشت «تازه‌های سامانه»</a></div>
    <?php elseif ($result['ok']): ?>
      <div class="form-actions"><a class="btn btn-ghost" href="/updates">مشاهده یادداشت منتشرشده</a></div>
    <?php endif; ?>
  </section>
<?php endif; ?>

<section class="panel">
  <div class="panel-head"><h2>سابقه بروزرسانی‌ها</h2></div>
  <?php if ($history === []): ?>
    <div class="empty"><p>هنوز بروزرسانی از داخل سامانه انجام نشده است.</p></div>
  <?php else: ?>
    <div class="table-wrap"><table class="table">
      <thead><tr><th>تاریخ</th><th>از نسخه</th><th>به نسخه</th><th>نتیجه</th><th>نسخه پشتیبان</th></tr></thead>
      <tbody>
        <?php foreach ($history as $h): ?>
          <tr>
            <td><?= e(fa_date($h['started_at'])) ?></td>
            <td><bdi><?= e($h['from_version']) ?></bdi></td>
            <td><bdi><?= e($h['to_version']) ?></bdi></td>
            <td><span class="chip <?= e($statusChip[$h['status']] ?? '') ?>"><?= e($statusLabel[$h['status']] ?? $h['status']) ?></span></td>
            <td class="ltr muted"><?= $h['backup_dir'] ? e(basename((string) $h['backup_dir'])) : '—' ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>
</section>

<script src="<?= e(asset('system-update.js')) ?>" defer></script>
<?= $this->partial('admin/_wrap_end') ?>
