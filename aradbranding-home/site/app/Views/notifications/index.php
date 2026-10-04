<?php /** @var array $list */ ?>
<section class="panel">
  <div class="panel-head"><h2><?= te('اعلان‌ها') ?></h2>
    <?php if ($list['rows'] !== []): ?><form method="post" action="/notifications/read"><?= csrf_field() ?><button class="btn btn-ghost btn-sm" type="submit"><?= te('علامت‌گذاری همه به‌عنوان خوانده‌شده') ?></button></form><?php endif; ?>
  </div>
  <?php if ($list['rows'] === []): ?>
    <div class="empty"><svg class="icon"><use href="#i-bell"/></svg><h3><?= te('اعلانی ندارید') ?></h3><p><?= te('نامه‌ها، پاسخ‌ها، پیشنهادها و ارتباطات تازه اینجا نمایش داده می‌شوند.') ?></p></div>
  <?php else: ?>
    <ul class="list">
      <?php foreach ($list['rows'] as $n): ?>
        <li class="list-row<?= (int) $n['is_read'] === 0 ? ' unread' : '' ?>">
          <div class="grow">
            <?php if ($n['link']): ?><a class="title" href="<?= e($n['link']) ?>"><?= e($n['text']) ?></a><?php else: ?><span class="title"><?= e($n['text']) ?></span><?php endif; ?>
            <div class="meta"><span><?= e(fa_date($n['created_at'])) ?></span></div>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
    <?php if ($list['next']): ?><div class="form-actions form-actions-center"><a class="btn btn-ghost btn-sm" href="/notifications?cursor=<?= e(rawurlencode($list['next'])) ?>"><?= te('قدیمی‌تر') ?></a></div><?php endif; ?>
  <?php endif; ?>
</section>
