<div class="page-head"><div><h1>درخواست #<?= fa((int)$log['id']) ?> آراد کانتکت</h1><div class="sub"><?= jdatetime($log['created_at']) ?> · <span class="ltr"><?= e($log['method'] . ' ' . $log['endpoint']) ?></span> · IP <span class="ltr"><?= e($log['ip']) ?></span> · <?= fa((int)$log['duration_ms']) ?> میلی‌ثانیه</div></div>
    <a class="btn btn-outline" href="<?= url('/admin/integrations/arad-contact') ?>"><?= icon('chevron-right') ?> بازگشت</a></div>
<div class="grid g-4 mb-3">
    <div class="card"><div class="small muted">کد پاسخ</div><div class="fw-b"><span class="badge badge-<?= (int)$log['http_status'] < 300 ? 'success' : ((int)$log['http_status'] >= 500 ? 'danger' : 'warning') ?>"><?= (int)$log['http_status'] ?></span> <?= (int)$log['success'] === 1 ? 'موفق' : 'ناموفق' ?></div></div>
    <div class="card"><div class="small muted">external_id</div><div class="ltr small" style="word-break:break-all"><?= e($log['external_id'] ?? '—') ?></div></div>
    <div class="card"><div class="small muted">کاربر</div><div><?php if ($log['user_id']): ?><a href="<?= url('/admin/users/' . (int)$log['user_id']) ?>"><?= e(trim(($log['first_name'] ?? '') . ' ' . ($log['last_name'] ?? '')) ?: '#' . (int)$log['user_id']) ?></a><?php else: ?>—<?php endif; ?> <span class="ltr small faint"><?= e($log['mobile'] ?? '') ?></span></div></div>
    <div class="card"><div class="small muted">وضعیت</div><div><?= (int)$log['user_created'] === 1 ? '<span class="badge badge-info">حساب جدید ساخته شد</span> ' : '' ?><?= (int)$log['duplicate'] === 1 ? '<span class="badge badge-gray">تکراری — شارژ نشد</span>' : '' ?><?= (int)$log['user_created'] !== 1 && (int)$log['duplicate'] !== 1 ? '—' : '' ?></div></div>
</div>
<?php if ($log['message']): ?><div class="alert alert-<?= (int)$log['success'] === 1 ? 'info' : 'danger' ?>"><?= icon('info') ?><div><?= e($log['message']) ?></div></div><?php endif; ?>
<?php if ($order): ?><div class="alert alert-info"><?= icon('receipt') ?><div>سفارش با این external_id در <?= jdatetime($order['created_at']) ?> اعمال شده است<?= (int)$order['replay_count'] > 0 ? ' و ' . fa((int)$order['replay_count']) . ' بار دوباره ارسال شده (آخرین: ' . jdatetime($order['last_replay_at']) . ')' : '' ?>.</div></div><?php endif; ?>
<div class="grid g-2">
    <div class="card"><h3><?= icon('upload') ?> درخواست</h3><?php if ($request !== ''): ?><div class="code-block"><?= e($request) ?></div><?php else: ?><div class="faint">بدون بدنه</div><?php endif; ?></div>
    <div class="card"><h3><?= icon('download') ?> پاسخ</h3><div class="code-block"><?= e($response) ?></div><div class="hint mt-1">رمز عبور حساب‌های جدید هرگز در لاگ ذخیره نمی‌شود.</div></div>
</div>
