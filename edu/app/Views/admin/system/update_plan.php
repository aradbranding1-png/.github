<?php
$m = $v['manifest'] ?? [];
$p = $v['plan'] ?? [];
$tone = ['ok' => 'success', 'warning' => 'warning', 'error' => 'danger'];
?>
<?php
$sigWarn = [];
$otherWarn = [];
foreach ($v['warnings'] ?? [] as $w) {
    if (str_contains($w, 'امضا')) $sigWarn[] = $w; else $otherWarn[] = $w;
}
$stats = [
    ['plus', 'success', count($p['new'] ?? []), 'فایل جدید'],
    ['pencil', 'warning', count($p['changed'] ?? []), 'فایل تغییرکرده'],
    ['trash-2', 'danger', count($p['deleted'] ?? []), 'فایل حذف‌شونده'],
    ['database', 'purple', count($p['migrations'] ?? []), 'Migration جدید'],
    ['check', 'info', (int)($p['unchanged'] ?? 0), 'بدون تغییر'],
];
?>
<div class="crumbs mb-1"><a href="<?= url('/admin/system/updates') ?>">بروزرسانی سامانه</a></div>
<?php $stTone = ['validated' => '#6366f1', 'success' => '#10b981', 'failed' => '#ef4444', 'rolled_back' => '#f59e0b']; ?>
<div class="card chero upd-chero mb-3" style="--cc:<?= $stTone[$u['status']] ?? '#6366f1' ?>">
    <div class="chero-top">
        <div class="chero-cover"><?= icon($u['status'] === 'success' ? 'circle-check' : ($u['status'] === 'failed' ? 'triangle-alert' : 'rocket')) ?></div>
        <div class="chero-main">
            <h1>بروزرسانی <span class="faint">#<?= fa($u['id']) ?></span></h1>
            <div class="chero-tags">
                <?= status_badge($u['status']) ?>
                <span class="upd-vpill ltr"><b><?= e($u['from_version']) ?></b><?= icon('chevron-right') ?><b class="is-to"><?= e($u['to_version'] ?? '?') ?></b></span>
                <span class="chero-meta ltr"><?= icon('package') ?> <?= e($u['package_name']) ?></span>
                <?php if ($sigWarn): ?><span class="chero-meta" title="<?= e(implode(' ', $sigWarn)) ?>"><?= icon('shield') ?> بدون امضای دیجیتال — Checksum بررسی شد</span>
                <?php elseif (!empty($m['signature'])): ?><span class="chero-meta" style="color:var(--success)"><?= icon('shield-check') ?> امضای دیجیتال معتبر</span><?php endif; ?>
            </div>
        </div>
        <div class="chero-acts">
            <?php if ($u['status'] === 'validated'): ?>
                <form method="post" action="<?= url('/admin/system/updates/' . $u['id'] . '/discard') ?>"><?= csrf_field() ?><button class="btn btn-ghost"><?= icon('x') ?> انصراف و حذف بسته</button></form>
            <?php else: ?>
                <a class="btn btn-outline" href="<?= url('/admin/system/updates') ?>"><?= icon('upload') ?> آپلود بسته دیگر</a>
            <?php endif; ?>
        </div>
    </div>
    <?php if ($u['status'] === 'validated'):
        $bad = array_values(array_filter($health, fn($c) => $c['status'] !== 'ok')); ?>
    <div class="upd-panel">
        <form class="upd-act-form" method="post" action="<?= url('/admin/system/updates/' . $u['id'] . '/apply') ?>"><?= csrf_field() ?>
            <label class="switch"><input type="checkbox" name="with_backup" value="1"> Backup قبل از بروزرسانی</label>
            <?= confirm_password_inline() ?>
            <button class="btn btn-grad btn-lg"><?= icon('rocket') ?> شروع بروزرسانی</button>
        </form>
        <div class="upd-health">
            <span class="faint small"><?= icon('heart-pulse') ?> Health Check:</span>
            <?php if (!$bad): ?><span class="chip tone-ok"><?= icon('circle-check') ?> همه <?= fa(count($health)) ?> مورد سالم است</span>
            <?php else: foreach ($bad as $c): ?><span class="chip tone-<?= $c['status'] === 'error' ? 'err' : 'warn' ?>"><span class="dot-st dot-<?= e($c['status']) ?>"></span><?= e($c['label']) ?></span><?php endforeach; endif; ?>
        </div>
    </div>
    <?php elseif ($u['status'] === 'success'): ?>
    <div class="upd-panel">
        <div class="upd-done"><?= icon('circle-check') ?> بروزرسانی در <?= jdatetime($u['finished_at']) ?> با موفقیت انجام شد.</div>
        <form class="upd-act-form" method="post" action="<?= url('/admin/system/updates/' . $u['id'] . '/rollback') ?>" data-confirm="نسخه قبلی بازگردانی شود؟"><?= csrf_field() ?>
            <?php if ($backup): ?><label class="switch" title="داده‌های ثبت‌شده پس از بروزرسانی از بین می‌روند"><input type="checkbox" name="restore_db" value="1"> بازیابی دیتابیس هم</label><?php endif; ?>
            <?= confirm_password_inline() ?>
            <button class="btn btn-outline"><?= icon('rotate-ccw') ?> بازگردانی به نسخه قبل</button>
        </form>
    </div>
    <?php endif; ?>
    <div class="chero-stats">
        <?php $toneC = ['success' => 'var(--success)', 'warning' => 'var(--warning)', 'danger' => 'var(--danger)', 'purple' => '#8b5cf6', 'info' => '#0ea5e9']; ?>
        <?php foreach ($stats as [$ic, $tn, $n, $label]): ?>
            <div class="cs<?= $n ? '' : ' is-zero' ?>" style="--tc:<?= $toneC[$tn] ?>"><span class="cs-ic"><?= icon($ic) ?></span><span><b><?= fa($n) ?></b><small><?= e($label) ?></small></span></div>
        <?php endforeach; ?>
    </div>
</div>

<?php foreach ($v['errors'] ?? [] as $er): ?><div class="alert alert-danger"><?= icon('circle-x') ?><div><?= e($er) ?></div></div><?php endforeach; ?>
<?php foreach ($otherWarn as $w): ?><div class="alert alert-warning"><?= icon('triangle-alert') ?><div><?= e($w) ?></div></div><?php endforeach; ?>
<?php if ($u['error'] && $u['status'] !== 'fail'): ?><div class="alert alert-danger"><?= icon('circle-x') ?><div><b>خطا:</b> <?= e($u['error']) ?><?= $u['failed_migration'] ? '<br>Migration ناقص: <code class="ltr">' . e($u['failed_migration']) . '</code>' : '' ?></div></div><?php endif; ?>

<div class="stack">
    <?php if (!empty($m['changelog'])): ?><div class="card"><h3><?= icon('sparkles') ?> تغییرات نسخه <?= e($m['version']) ?></h3><ul><?php foreach ((array)$m['changelog'] as $c): ?><li><?= e($c) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
    <div class="grid g-2">
        <div class="card"><h3><?= icon('file') ?> فایل‌ها</h3>
            <div class="diff-list">
                <?php foreach ($p['new'] ?? [] as $f): ?><div class="diff-add">+ <?= e($f) ?></div><?php endforeach; ?>
                <?php foreach ($p['changed'] ?? [] as $f): ?><div class="diff-mod">~ <?= e($f) ?></div><?php endforeach; ?>
                <?php foreach ($p['deleted'] ?? [] as $f): ?><div class="diff-del">- <?= e($f) ?></div><?php endforeach; ?>
                <?php if (empty($p['new']) && empty($p['changed']) && empty($p['deleted'])): ?><div class="faint">—</div><?php endif; ?>
            </div>
        </div>
        <div class="stack">
            <div class="card"><h3><?= icon('database') ?> تغییرات دیتابیس (Migrationها)</h3>
                <?php foreach ($p['migrations'] ?? [] as $mg): ?><div class="ltr small"><code><?= e($mg) ?></code></div><?php endforeach; ?>
                <?php if (empty($p['migrations'])): ?><div class="faint small">تغییری در ساختار دیتابیس وجود ندارد.</div><?php endif; ?>
            </div>
            <div class="card"><h3><?= icon('info') ?> اطلاعات بسته</h3><dl class="kv small"><dt>نسخه</dt><dd class="ltr"><?= e($m['version'] ?? '—') ?></dd><dt>حداقل نسخه</dt><dd class="ltr"><?= e($m['min_version'] ?? '—') ?></dd><dt>PHP لازم</dt><dd class="ltr"><?= e($m['requires_php'] ?? '—') ?></dd><dt>امضا</dt><dd><?= !empty($m['signature']) ? 'دارد' : 'ندارد' ?></dd><dt>SHA-256</dt><dd class="ltr small" style="word-break:break-all"><?= e($u['package_sha256']) ?></dd><?php if ($backup): ?><dt>پشتیبان</dt><dd class="ltr small"><?= e($backup['filename']) ?></dd><?php endif; ?></dl></div>
        </div>
    </div>
    <?php if ($u['log_text']): ?><div class="card"><h3><?= icon('scroll-text') ?> گزارش اجرا</h3><div class="code-block"><?= e($u['log_text']) ?></div></div><?php endif; ?>
</div>
