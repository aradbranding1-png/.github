<?php /** Top-bar notifications dropdown body (fetched from /notifications/peek). @var array $rows */ ?>
<?php if ($rows === []): ?>
  <div class="np-empty"><svg class="icon"><use href="#i-bell"/></svg><p>اعلان تازه‌ای ندارید.</p></div>
<?php else: ?>
  <ul class="np-list">
    <?php foreach ($rows as $n): $unread = (int) $n['is_read'] === 0; ?>
      <li class="<?= $unread ? 'is-unread' : '' ?>">
        <?php if ($n['link']): ?><a href="<?= e($n['link']) ?>"><?php else: ?><span><?php endif; ?>
          <i class="np-dot" aria-hidden="true"></i>
          <span class="np-text"><b><?= e($n['text']) ?></b><small><?= e(fa_date($n['created_at'])) ?></small></span>
        <?php if ($n['link']): ?></a><?php else: ?></span><?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ul>
<?php endif; ?>
