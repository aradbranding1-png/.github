<?php
/** Tab bar shared by the settings page and the pages that moved under it (Arad Contact, health, backups, migrations, errors) */
$cur = current_path();
$tabs = [
    ['/admin/settings', 'تنظیمات عمومی', 'settings', 'settings.view'],
    ['/admin/integrations/arad-contact', 'آراد کانتکت', 'handshake', 'settings.view'],
    ['/admin/integrations/bale', 'ربات بله', 'send', 'settings.view'],
    ['/admin/system/health', 'سلامت سامانه', 'heart-pulse', 'health.view'],
    ['/admin/system/backups', 'پشتیبان‌گیری', 'hard-drive', 'backups.view'],
    ['/admin/system/migrations', 'Migrationها', 'database', 'migrations.view'],
    ['/admin/system/errors', 'خطاهای سامانه', 'triangle-alert', 'errors.view'],
];
$tabs = array_values(array_filter($tabs, fn($t) => can($t[3])));
if (count($tabs) < 2 && ($tabs[0][0] ?? '') === '/admin/settings') return;
?>
<nav class="set-tabs" aria-label="بخش‌های تنظیمات">
    <?php foreach ($tabs as $t): $on = $cur === $t[0] || str_starts_with($cur, $t[0] . '/'); ?>
        <a class="set-tab<?= $on ? ' on' : '' ?>" href="<?= url($t[0]) ?>"<?= $on ? ' aria-current="page"' : '' ?>><?= icon($t[2]) ?><span><?= e($t[1]) ?></span></a>
    <?php endforeach; ?>
</nav>
