<div class="page-head"><div><h1>خطاهای سامانه</h1><div class="sub">لاگ‌ها در storage/logs (خارج از Web Root) نگهداری می‌شوند؛ رمزها و توکن‌ها به صورت خودکار حذف (REDACT) می‌شوند. کاربر عادی فقط «کد پیگیری» می‌بیند.</div></div></div>
<form class="card filters" method="get" action="<?= url('/admin/system/errors') ?>">
    <div class="field"><label>فایل لاگ</label><select name="file"><?php foreach ($files as $f): ?><option value="<?= e($f) ?>"<?= selected($f, $sel) ?>><?= e($f) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label>سطح</label><select name="level"><option value="">همه</option><?php foreach (['error', 'warning', 'notice', 'info'] as $l): ?><option value="<?= $l ?>"<?= selected($l, $_GET['level'] ?? '') ?>><?= $l ?></option><?php endforeach; ?></select></div>
    <div class="field grow"><label>جست‌وجوی کد پیگیری</label><input type="search" id="logsearch" placeholder="مثلاً 281DA1DD" data-filter-log></div>
    <button class="btn btn-primary"><?= icon('filter') ?></button>
</form>
<?php if (!$lines): ?><div class="card empty"><?= icon('circle-check') ?><h3>خطایی ثبت نشده</h3></div><?php else: ?>
<div class="code-block"><?php foreach ($lines as $l): ?><div style="padding:.15rem 0;border-bottom:1px solid #1e293b;<?= str_contains($l, '.ERROR ') ? 'color:#fca5a5' : (str_contains($l, '.WARNING ') ? 'color:#fcd34d' : '') ?>"><?= e($l) ?></div><?php endforeach; ?></div>
<?php endif; ?>
