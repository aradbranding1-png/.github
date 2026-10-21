<?php /** @var string $active @var bool $canUpdate */ ?>
<nav class="tabs" aria-label="مدیریت سامانه">
  <a href="/admin/settings"<?= $active === 'settings' ? ' aria-current="page"' : '' ?>>تنظیمات</a>
  <?php if (!empty($canUpdate)): ?><a href="/admin/system-update"<?= $active === 'update' ? ' aria-current="page"' : '' ?>>بروزرسانی سامانه</a><?php endif; ?>
  <a href="/updates">تازه‌های سامانه</a>
</nav>
