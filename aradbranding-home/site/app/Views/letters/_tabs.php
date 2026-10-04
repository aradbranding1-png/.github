<?php /** @var string $folderKey */
$tabs = ['inbox' => t('صندوق ورودی'), 'sent' => t('ارسال‌شده'), 'archive' => t('بایگانی'), 'public' => t('نامه‌های عمومی من')]; ?>
<div class="feed-head">
  <nav class="tabs tabs-flat" aria-label="<?= te('پوشه‌ها') ?>">
    <?php foreach ($tabs as $k => $label): ?>
      <a href="/letters?folder=<?= e($k) ?>"<?= $folderKey === $k ? ' aria-current="page"' : '' ?>><?= te($label) ?></a>
    <?php endforeach; ?>
  </nav>
  <a class="btn btn-sm" href="/letters/public/new"><svg class="icon"><use href="#i-letter"/></svg><?= te('نامه عمومی') ?></a>
</div>
