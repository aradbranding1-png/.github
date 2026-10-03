<div class="page-head"><div><h1>بروزرسانی سامانه</h1><div class="sub">نسخه فعلی: <b class="ltr"><?= e(app_version()) ?></b> — نصب نسخه جدید با آپلود فایل ZIP، بدون نیاز به ورود به DirectAdmin</div></div>
<form method="post" action="<?= url('/admin/system/maintenance') ?>"><?= csrf_field() ?><input type="hidden" name="on" value="<?= $maintenance ? '0' : '1' ?>"><button class="btn <?= $maintenance ? 'btn-warning' : 'btn-outline' ?>"><?= icon('server') ?> <?= $maintenance ? 'خروج از حالت تعمیر' : 'فعال‌سازی حالت تعمیر' ?></button></form></div>
<?php if ($maintenance): ?><div class="alert alert-warning"><?= icon('triangle-alert') ?> سامانه در حالت تعمیر است و فقط مدیر کل به آن دسترسی دارد.</div><?php endif; ?>
<div class="grid g-main">
    <div class="card flush"><div class="card-head" style="padding:1rem 1.25rem"><h3><?= icon('history') ?> تاریخچه بروزرسانی</h3></div>
        <div class="table-wrap"><table class="table"><thead><tr><th>#</th><th>بسته</th><th>از نسخه</th><th>به نسخه</th><th>وضعیت</th><th>پشتیبان</th><th>مدیر</th><th>زمان</th><th></th></tr></thead><tbody>
        <?php foreach ($rows as $u): ?><tr><td class="num"><?= fa($u['id']) ?></td><td class="ltr small"><?= e($u['package_name']) ?></td><td class="ltr"><?= e($u['from_version']) ?></td><td class="ltr fw-b"><?= e($u['to_version'] ?? '—') ?></td><td><?= status_badge($u['status']) ?></td><td class="small"><?= $u['backup_file'] ? '<span class="badge badge-success">' . icon('check') . '</span>' : ($u['status'] === 'success' ? '<span class="badge badge-gray">بدون پشتیبان</span>' : '—') ?></td><td class="small"><?= e(trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''))) ?></td><td class="num small"><?= jdatetime($u['finished_at'] ?? $u['created_at']) ?></td><td class="actions"><a class="btn btn-xs btn-ghost" href="<?= url('/admin/system/updates/' . $u['id']) ?>"><?= icon('eye') ?></a></td></tr><?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="9"><div class="empty"><?= icon('refresh-cw') ?><div>هنوز بروزرسانی انجام نشده</div></div></td></tr><?php endif; ?>
        </tbody></table></div>
    </div>
    <form class="card" method="post" enctype="multipart/form-data" action="<?= url('/admin/system/updates') ?>"><?= csrf_field() ?>
        <h3><?= icon('upload') ?> آپلود بسته بروزرسانی</h3>
        <p class="small muted">پس از آپلود، بسته به طور کامل بررسی می‌شود (ZIP، Manifest، نسخه، Path Traversal، Checksum، خطای نحوی PHP، امضا، پیش‌نیازها) و فهرست فایل‌های جدید/تغییرکرده/حذف‌شونده و Migrationهای جدید نمایش داده می‌شود. <b>هیچ تغییری تا تأیید شما اعمال نمی‌شود.</b></p>
        <div class="field"><input type="file" name="package" accept=".zip,application/zip" required><div class="hint">محدودیت فعلی سرور: upload_max_filesize=<?= e($limits['upload']) ?> ، post_max_size=<?= e($limits['post']) ?></div></div>
        <button class="btn btn-grad w-100"><?= icon('shield-check') ?> آپلود و بررسی بسته</button>
    </form>
</div>
