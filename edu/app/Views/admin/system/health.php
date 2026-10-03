<?php $tone = ['ok' => 'success', 'warning' => 'warning', 'error' => 'danger']; $ic = ['ok' => 'circle-check', 'warning' => 'triangle-alert', 'error' => 'circle-x'];
$cnt = array_count_values(array_column($checks, 'status')); ?>
<div class="page-head"><div><h1>سلامت سامانه</h1><div class="sub">وضعیت Database، Storage، PHP، Extensions، Permission، Cron، Migration، Disk Space و ...</div></div>
<div class="btn-group"><a class="btn btn-outline" href="<?= url('/admin/system/health') ?>"><?= icon('refresh-cw') ?> بررسی مجدد</a><a class="btn btn-outline" href="<?= url('/admin/system/health', ['deep' => 1]) ?>"><?= icon('activity') ?> بررسی عمیق (شامل تست SSO)</a></div></div>
<div class="grid g-4 mb-3">
    <div class="card stat tone-<?= $tone[$summary] ?>"><div class="bubble"><?= icon('heart-pulse') ?></div><div><div class="v"><?= e(strtoupper($summary)) ?></div><div class="l">وضعیت کلی</div></div></div>
    <div class="card stat tone-success"><div class="bubble"><?= icon('circle-check') ?></div><div><div class="v"><?= fa($cnt['ok'] ?? 0) ?></div><div class="l">OK</div></div></div>
    <div class="card stat tone-warning"><div class="bubble"><?= icon('triangle-alert') ?></div><div><div class="v"><?= fa($cnt['warning'] ?? 0) ?></div><div class="l">Warning</div></div></div>
    <div class="card stat tone-danger"><div class="bubble"><?= icon('circle-x') ?></div><div><div class="v"><?= fa($cnt['error'] ?? 0) ?></div><div class="l">Error</div></div></div>
</div>
<div class="grid g-main">
    <div class="card">
        <?php foreach ($checks as $c): ?>
            <div class="health-row"><span class="hi" style="background:var(--<?= $tone[$c['status']] ?>-soft);color:var(--<?= $tone[$c['status']] ?>)"><?= icon($ic[$c['status']]) ?></span><div class="grow"><b><?= e($c['label']) ?></b><div class="small muted"><?= e($c['msg']) ?></div></div><?= status_badge($c['status']) ?></div>
        <?php endforeach; ?>
    </div>
    <div class="card"><h3><?= icon('server') ?> اطلاعات سرور</h3>
        <dl class="kv">
            <dt>نسخه سامانه</dt><dd class="ltr"><?= e($info['version']) ?></dd><dt>PHP</dt><dd class="ltr"><?= e($info['php']) ?> (<?= e($info['sapi']) ?>)</dd><dt>دیتابیس</dt><dd class="ltr"><?= e($info['db']) ?></dd>
            <dt>وب‌سرور</dt><dd class="ltr small"><?= e($info['server']) ?></dd><dt>upload_max_filesize</dt><dd class="ltr"><?= e($info['upload_max']) ?></dd><dt>post_max_size</dt><dd class="ltr"><?= e($info['post_max']) ?></dd>
            <dt>memory_limit</dt><dd class="ltr"><?= e($info['memory']) ?></dd><dt>max_execution_time</dt><dd class="ltr"><?= e($info['max_exec']) ?></dd><dt>منطقه زمانی</dt><dd class="ltr"><?= e($info['tz']) ?></dd>
        </dl>
    </div>
</div>
