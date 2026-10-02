<?php
/** Admin section navigation (inside the page, like the mailbox). Items appear only with the right permission. @var array $perms @var string $active */
$has = static fn (string ...$codes): bool => array_intersect($codes, array_keys($perms)) !== [];
$items = [
    'dashboard' => ['/admin', 'داشبورد', 'home', $has('reports.view', 'settings.manage', 'users.view')],
    'users' => ['/admin/users', 'کاربران', 'user', $has('users.view')],
    'content' => ['/admin/content', 'محتوا و بررسی', 'page', $has('pages.view', 'pages.approve', 'proposals.view', 'proposals.moderate')],
    'finance' => ['/admin/finance', 'مالی', 'star', $has('payments.view', 'wallet.view')],
    'reports' => ['/admin/reports', 'گزارش‌ها', 'spark', $has('reports.view')],
    'exports' => ['/admin/exports', 'خروجی Excel', 'archive', $has('reports.export')],
    'roles' => ['/admin/roles', 'نقش‌ها و دسترسی‌ها', 'lock', $has('roles.manage')],
    'audit' => ['/admin/audit', 'رویدادهای امنیتی', 'eye', $has('audit.view')],
    'settings' => ['/admin/settings', 'تنظیمات', 'gear', $has('settings.manage')],
    'home' => ['/admin/home', 'صفحه اصلی سایت', 'home', $has('settings.manage')],
    'backups' => ['/admin/backups', 'نسخه‌های پشتیبان', 'archive', $has('backup.manage')],
    'update' => ['/admin/system-update', 'بروزرسانی سامانه', 'route', $has('updates.manage')],
];
?>
<aside class="mail-nav" aria-label="بخش‌های مدیریت">
  <nav class="mail-folders">
    <?php foreach ($items as $key => [$href, $label, $icon, $visible]): if (!$visible) { continue; } ?>
      <a href="<?= e($href) ?>"<?= $active === $key ? ' aria-current="page"' : '' ?>><svg class="icon"><use href="#i-<?= e($icon) ?>"/></svg><?= e($label) ?></a>
    <?php endforeach; ?>
  </nav>
</aside>
