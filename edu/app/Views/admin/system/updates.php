<div class="page-head"><div><h1>بروزرسانی سامانه</h1><div class="sub">نسخه فعلی: <b class="ltr"><?= e(app_version()) ?></b> — نصب نسخه جدید با آپلود فایل ZIP، بدون نیاز به ورود به DirectAdmin</div></div>
<form method="post" action="<?= url('/admin/system/maintenance') ?>"><?= csrf_field() ?><input type="hidden" name="on" value="<?= $maintenance ? '0' : '1' ?>"><button class="btn <?= $maintenance ? 'btn-warning' : 'btn-outline' ?>"><?= icon('server') ?> <?= $maintenance ? 'خروج از حالت تعمیر' : 'فعال‌سازی حالت تعمیر' ?></button></form></div>
<?php if ($maintenance): ?><div class="alert alert-warning"><?= icon('triangle-alert') ?> سامانه در حالت تعمیر است و فقط مدیر کل به آن دسترسی دارد.</div><?php endif; ?>
<div class="card upd-hero upd-tools mb-3">
    <form class="upd-tool" method="post" enctype="multipart/form-data" action="<?= url('/admin/system/updates') ?>"><?= csrf_field() ?>
        <div class="upd-tool-h"><span class="upd-ico sm"><?= icon('upload') ?></span><div><b>آپلود بستهٔ بروزرسانی</b><div class="faint small">بسته کامل بررسی می‌شود و تا شروع بروزرسانی هیچ تغییری اعمال نمی‌شود.</div></div></div>
        <div class="upd-act-form">
            <input class="upd-file" type="file" name="package" accept=".zip,application/zip" required>
            <button class="btn btn-grad"><?= icon('shield-check') ?> آپلود و بررسی</button>
        </div>
        <div class="faint small mt-1 ltr" style="text-align:right">upload_max_filesize=<?= e($limits['upload']) ?> · post_max_size=<?= e($limits['post']) ?></div>
    </form>
    <form class="upd-tool" method="post" action="<?= url('/admin/system/updates/export') ?>" data-no-busy><?= csrf_field() ?>
        <div class="upd-tool-h"><span class="upd-ico sm alt"><?= icon('download') ?></span><div><b>دانلود نسخهٔ فعلی سایت (<span class="ltr"><?= e(app_version()) ?></span>)</b><div class="faint small">بدون storage و ‎.env‎ — فایل بلافاصله پس از دانلود از هاست حذف می‌شود.</div></div></div>
        <div class="upd-act-form">
            <?= confirm_password_inline() ?>
            <button class="btn btn-outline"><?= icon('download') ?> ساخت و دانلود ZIP</button>
        </div>
    </form>
</div>
<div class="card flush"><div class="card-head" style="padding:1rem 1.25rem"><h3><?= icon('history') ?> تاریخچه بروزرسانی</h3></div>
        <div class="table-wrap"><table class="table"><thead><tr><th>#</th><th>بسته</th><th>از نسخه</th><th>به نسخه</th><th>وضعیت</th><th>پشتیبان</th><th>مدیر</th><th>زمان</th><th></th></tr></thead><tbody>
        <?php foreach ($rows as $u): ?><tr><td class="num"><?= fa($u['id']) ?></td><td class="ltr small"><?= e($u['package_name']) ?></td><td class="ltr"><?= e($u['from_version']) ?></td><td class="ltr fw-b"><?= e($u['to_version'] ?? '—') ?></td><td><?= status_badge($u['status']) ?></td><td class="small"><?= $u['backup_file'] ? '<span class="badge badge-success">' . icon('check') . '</span>' : ($u['status'] === 'success' ? '<span class="badge badge-gray">بدون پشتیبان</span>' : '—') ?></td><td class="small"><?= e(trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''))) ?></td><td class="num small"><?= jdatetime($u['finished_at'] ?? $u['created_at']) ?></td><td class="actions"><a class="btn btn-xs btn-ghost" href="<?= url('/admin/system/updates/' . $u['id']) ?>"><?= icon('eye') ?></a></td></tr><?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="9"><div class="empty"><?= icon('refresh-cw') ?><div>هنوز بروزرسانی انجام نشده</div></div></td></tr><?php endif; ?>
        </tbody></table></div>
    </div>
