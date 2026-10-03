<?php
$cur = $currentPath ?? '';
$tabs = [
    ['/admin/growth', 'مراحل و نمای کلی', 'mountain', 'growth.view'],
    ['/admin/growth/traders', 'جایگاه تاجران', 'trophy', 'growth.view'],
    ['/admin/growth/reviews', 'بررسی معاملات و مدارک', 'file-check', 'growth.view'],
    ['/admin/growth/services', 'خدمات و سامانه فروش', 'package', 'growth.view'],
    ['/admin/growth/settings', 'رتبه‌ها، مسیرها و تنظیمات', 'settings', 'growth.edit'],
];
?>
<div class="pill-nav mb-3">
    <?php foreach ($tabs as [$h, $l, $i, $p]): if (!can($p)) continue; $on = $h === '/admin/growth' ? ($cur === '/admin/growth' || str_starts_with($cur, '/admin/growth/stages')) : str_starts_with($cur, $h); ?>
        <a class="<?= $on ? 'active' : '' ?>" href="<?= url($h) ?>"><?= icon($i) ?> <?= $l ?></a>
    <?php endforeach; ?>
</div>
