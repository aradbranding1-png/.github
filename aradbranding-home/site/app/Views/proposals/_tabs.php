<?php /** @var string $path */ ?>
<nav class="tabs" aria-label="<?= te('بخش‌های پیشنهادها') ?>">
  <a href="/proposals"<?= $path === '/proposals' ? ' aria-current="page"' : '' ?>><?= te('فید') ?></a>
  <a href="/proposals/mine"<?= $path === '/proposals/mine' ? ' aria-current="page"' : '' ?>><?= te('پیشنهادهای من') ?></a>
  <a href="/proposals/received"<?= $path === '/proposals/received' ? ' aria-current="page"' : '' ?>><?= te('دریافتی') ?></a>
</nav>
