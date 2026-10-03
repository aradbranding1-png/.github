<div class="page-head"><div><h1>گواهی‌های من</h1><div class="sub">گواهی‌های صادرشده برای دوره‌های تکمیل‌شده</div></div></div>
<?php if (!$rows): ?><div class="card empty"><?= icon('award') ?><h3>هنوز گواهی دریافت نکرده‌اید</h3><p>با تکمیل دوره‌های دارای گواهی، مدرک شما اینجا نمایش داده می‌شود.</p></div><?php endif; ?>
<div class="grid g-auto">
    <?php foreach ($rows as $c): $valid = !$c['revoked_at'] && (!$c['expires_at'] || $c['expires_at'] > now()); ?>
        <div class="card" style="border-top:4px solid <?= $valid ? '#f59e0b' : '#94a3b8' ?>">
            <div class="flex between"><span class="badge <?= $valid ? 'badge-warning' : 'badge-gray' ?>"><?= icon('medal') ?> <?= $c['revoked_at'] ? 'ابطال‌شده' : ($valid ? 'معتبر' : 'منقضی') ?></span><span class="ltr small faint"><?= e($c['code']) ?></span></div>
            <h3 class="mt-1"><?= e($c['course_title']) ?></h3>
            <div class="small muted">صدور: <?= jdate($c['issued_at']) ?><?= $c['expires_at'] ? ' · اعتبار تا ' . jdate($c['expires_at']) : '' ?><?= $c['score'] !== null ? ' · نمره ' . fa((float)$c['score']) : '' ?></div>
            <a class="btn btn-sm btn-primary mt-2" href="<?= url('/learn/certificate/' . $c['code']) ?>"><?= icon('eye') ?> مشاهده و چاپ</a>
        </div>
    <?php endforeach; ?>
</div>
