<?php
$m = $v['manifest'] ?? [];
$p = $v['plan'] ?? [];
$tone = ['ok' => 'success', 'warning' => 'warning', 'error' => 'danger'];
?>
<div class="page-head"><div><div class="crumbs"><a href="<?= url('/admin/system/updates') ?>">بروزرسانی سامانه</a></div><h1>بروزرسانی #<?= fa($u['id']) ?>: <span dir="rtl"><bdi dir="ltr"><?= e($u['from_version']) ?></bdi> ← <bdi dir="ltr"><?= e($u['to_version'] ?? '?') ?></bdi></span></h1><div class="sub"><?= status_badge($u['status']) ?> <span class="ltr small"><?= e($u['package_name']) ?></span></div></div></div>

<?php foreach ($v['errors'] ?? [] as $er): ?><div class="alert alert-danger"><?= icon('circle-x') ?><div><?= e($er) ?></div></div><?php endforeach; ?>
<?php foreach ($v['warnings'] ?? [] as $w): ?><div class="alert alert-warning"><?= icon('triangle-alert') ?><div><?= e($w) ?></div></div><?php endforeach; ?>
<?php if ($u['error'] && $u['status'] !== 'fail'): ?><div class="alert alert-danger"><?= icon('circle-x') ?><div><b>خطا:</b> <?= e($u['error']) ?><?= $u['failed_migration'] ? '<br>Migration ناقص: <code class="ltr">' . e($u['failed_migration']) . '</code>' : '' ?></div></div><?php endif; ?>

<div class="grid g-5 mb-3">
    <div class="card stat tone-success"><div class="bubble"><?= icon('plus') ?></div><div><div class="v"><?= fa(count($p['new'] ?? [])) ?></div><div class="l">فایل جدید</div></div></div>
    <div class="card stat tone-warning"><div class="bubble"><?= icon('pencil') ?></div><div><div class="v"><?= fa(count($p['changed'] ?? [])) ?></div><div class="l">فایل تغییرکرده</div></div></div>
    <div class="card stat tone-danger"><div class="bubble"><?= icon('trash-2') ?></div><div><div class="v"><?= fa(count($p['deleted'] ?? [])) ?></div><div class="l">فایل حذف‌شونده</div></div></div>
    <div class="card stat tone-purple"><div class="bubble"><?= icon('database') ?></div><div><div class="v"><?= fa(count($p['migrations'] ?? [])) ?></div><div class="l">Migration جدید</div></div></div>
    <div class="card stat tone-info"><div class="bubble"><?= icon('check') ?></div><div><div class="v"><?= fa($p['unchanged'] ?? 0) ?></div><div class="l">بدون تغییر</div></div></div>
</div>

<div class="grid g-main">
    <div class="stack">
        <?php if (!empty($m['changelog'])): ?><div class="card"><h3><?= icon('sparkles') ?> تغییرات نسخه <?= e($m['version']) ?></h3><ul><?php foreach ((array)$m['changelog'] as $c): ?><li><?= e($c) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
        <div class="card"><h3><?= icon('file') ?> فایل‌ها</h3>
            <div class="diff-list">
                <?php foreach ($p['new'] ?? [] as $f): ?><div class="diff-add">+ <?= e($f) ?></div><?php endforeach; ?>
                <?php foreach ($p['changed'] ?? [] as $f): ?><div class="diff-mod">~ <?= e($f) ?></div><?php endforeach; ?>
                <?php foreach ($p['deleted'] ?? [] as $f): ?><div class="diff-del">- <?= e($f) ?></div><?php endforeach; ?>
                <?php if (empty($p['new']) && empty($p['changed']) && empty($p['deleted'])): ?><div class="faint">—</div><?php endif; ?>
            </div>
        </div>
        <div class="card"><h3><?= icon('database') ?> تغییرات دیتابیس (Migrationهایی که اجرا می‌شوند)</h3>
            <?php foreach ($p['migrations'] ?? [] as $mg): ?><div class="ltr small"><code><?= e($mg) ?></code></div><?php endforeach; ?>
            <?php if (empty($p['migrations'])): ?><div class="faint small">تغییری در ساختار دیتابیس وجود ندارد.</div><?php endif; ?>
        </div>
        <?php if ($u['log_text']): ?><div class="card"><h3><?= icon('scroll-text') ?> گزارش اجرا</h3><div class="code-block"><?= e($u['log_text']) ?></div></div><?php endif; ?>
    </div>
    <div class="stack">
        <?php if ($u['status'] === 'validated'): ?>
            <form class="card" method="post" action="<?= url('/admin/system/updates/' . $u['id'] . '/apply') ?>" data-confirm="بروزرسانی اجرا شود؟"><?= csrf_field() ?>
                <h3><?= icon('rocket') ?> اجرای بروزرسانی</h3>
                <label class="switch mb-2"><input type="checkbox" name="with_backup" value="1" checked> تهیه Backup قبل از بروزرسانی</label>
                <div class="hint mb-2">در صورت روشن بودن: دیتابیس، فایل‌های برنامه و تنظیمات پیش از بروزرسانی پشتیبان‌گیری می‌شوند و در صورت شکست Migration، دیتابیس به صورت خودکار بازیابی می‌شود. در هر حالت از فایل‌های تغییرکننده نسخه برگشت تهیه می‌شود.</div>
                <?= confirm_password_field() ?>
                <button class="btn btn-grad btn-lg w-100"><?= icon('rocket') ?> شروع بروزرسانی</button>
            </form>
            <div class="card"><h3><?= icon('heart-pulse') ?> Health Check پیش از بروزرسانی</h3>
                <?php foreach ($health as $c): ?><div class="flex between small" style="padding:.25rem 0"><span><span class="dot-st dot-<?= e($c['status']) ?>"></span><?= e($c['label']) ?></span><?= status_badge($c['status']) ?></div><?php endforeach; ?>
            </div>
            <form method="post" action="<?= url('/admin/system/updates/' . $u['id'] . '/discard') ?>"><?= csrf_field() ?><button class="btn btn-ghost w-100"><?= icon('x') ?> انصراف و حذف بسته</button></form>
        <?php elseif ($u['status'] === 'success'): ?>
            <div class="card"><div class="alert alert-success"><?= icon('circle-check') ?> بروزرسانی در <?= jdatetime($u['finished_at']) ?> با موفقیت انجام شد.</div>
                <form method="post" action="<?= url('/admin/system/updates/' . $u['id'] . '/rollback') ?>" data-confirm="نسخه قبلی بازگردانی شود؟"><?= csrf_field() ?>
                    <h4><?= icon('rotate-ccw') ?> بازگردانی به نسخه قبل</h4>
                    <?php if ($backup): ?><label class="switch mb-1"><input type="checkbox" name="restore_db" value="1"> بازیابی دیتابیس از پشتیبان پیش از بروزرسانی</label><div class="hint mb-1">توجه: داده‌های ثبت‌شده پس از بروزرسانی از بین می‌روند.</div><?php endif; ?>
                    <?= confirm_password_field() ?>
                    <button class="btn btn-outline w-100"><?= icon('rotate-ccw') ?> بازگردانی</button>
                </form>
            </div>
        <?php else: ?>
            <div class="card"><a class="btn btn-outline w-100" href="<?= url('/admin/system/updates') ?>"><?= icon('upload') ?> آپلود بسته دیگر</a></div>
        <?php endif; ?>
        <div class="card"><h3><?= icon('info') ?> اطلاعات بسته</h3><dl class="kv small"><dt>نسخه</dt><dd class="ltr"><?= e($m['version'] ?? '—') ?></dd><dt>حداقل نسخه</dt><dd class="ltr"><?= e($m['min_version'] ?? '—') ?></dd><dt>PHP لازم</dt><dd class="ltr"><?= e($m['requires_php'] ?? '—') ?></dd><dt>امضا</dt><dd><?= !empty($m['signature']) ? 'دارد' : 'ندارد' ?></dd><dt>SHA-256</dt><dd class="ltr small" style="word-break:break-all"><?= e($u['package_sha256']) ?></dd><?php if ($backup): ?><dt>پشتیبان</dt><dd class="ltr small"><?= e($backup['filename']) ?></dd><?php endif; ?></dl></div>
    </div>
</div>
