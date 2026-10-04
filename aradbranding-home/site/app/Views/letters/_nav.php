<?php
/** Vertical navigation of the «ارتباطات» section (inside the page, like Gmail). @var array $user @var string $active @var bool $canOfficial */
$unreadOfficial = (int) ($user['unread_official'] ?? 0);
$items = [
    'inbox' => ['/letters', t('صندوق ورودی'), 'chat', (int) ($user['unread_letters'] ?? 0) + $unreadOfficial],
    'sent' => ['/letters?folder=sent', t('ارسال‌شده'), 'send', 0],
    'archive' => ['/letters?folder=archive', t('بایگانی'), 'archive', 0],
    'groups' => ['/letters?folder=groups', t('ارسال‌های گروهی من'), 'letter', 0],
    'connections' => ['/connections', t('ارتباطات تجاری'), 'route', 0],
    'updates' => ['/updates', t('تازه‌های سامانه'), 'spark', 0],
];
if (!empty($canOfficial)) {
    $items['official'] = ['/admin/letters', t('نامه رسمی آراد برندینگ'), 'mark', 0];
}
?>
<aside class="mail-nav" aria-label="<?= te('بخش‌های ارتباطات') ?>">
  <a class="btn mail-compose" href="/letters/send"><svg class="icon"><use href="#i-letter"/></svg><?= te('ارسال نامه') ?></a>
  <nav class="mail-folders">
    <?php foreach ($items as $key => [$href, $label, $icon, $count]): ?>
      <a href="<?= e($href) ?>"<?= $active === $key ? ' aria-current="page"' : '' ?>>
        <svg class="icon"><use href="#i-<?= e($icon) ?>"/></svg><?= te($label) ?>
        <?php if ($count > 0): ?><em class="badge"><?= e(fa_num(min(999, $count))) ?></em><?php endif; ?>
      </a>
    <?php endforeach; ?>
  </nav>
</aside>
