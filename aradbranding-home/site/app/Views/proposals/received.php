<?php
/** @var array $rows @var ?int $next */
use App\Modules\Proposals\ProposalService;
?>
<?= $this->partial('proposals/_tabs', ['path' => '/proposals/received']) ?>
<section class="panel">
  <h2>پیشنهادهای دریافتی</h2>
  <?php if ($rows === []): ?>
    <div class="empty">
      <svg class="icon"><use href="#i-send"/></svg>
      <h3>هنوز پیشنهادی دریافت نکرده‌اید</h3>
      <p>صفحه تجاری کامل و پیشنهادهای منتشرشده، تجار دیگر را به ارسال پیشنهاد تشویق می‌کند.</p>
      <a class="btn" href="/proposals">کشف فرصت‌های تجاری</a>
    </div>
  <?php else: ?>
    <ul class="list">
      <?php foreach ($rows as $r): $thumb = media($r['thumb_path']); ?>
        <li class="list-row<?= $r['read_at'] === null ? ' unread' : '' ?>">
          <?php if ($thumb): ?><img class="thumb-sm" src="<?= e($thumb) ?>" alt="" loading="lazy"><?php else: ?><span class="thumb-sm"></span><?php endif; ?>
          <div class="grow">
            <a class="title" href="/proposals/<?= e($r['uid']) ?>"><?= e($r['title']) ?></a>
            <div class="meta">
              <span><?= flag($r['country_code']) ?> <?= e($r['first_name'] . ' ' . $r['last_name']) ?></span>
              <span><?= e(ProposalService::TYPES[(int) $r['type']] ?? '') ?></span>
              <span class="ltr"><?= e(substr((string) $r['created_at'], 0, 16)) ?></span>
            </div>
            <?php if ($r['message']): ?><p class="list-note"><?= e(\App\Core\Security\ContactGuard::mask(\App\Core\Support\Str::excerpt((string) $r['message'], 160))) ?></p><?php endif; ?>
          </div>
          <?php if ($r['handle']): ?><a class="btn btn-ghost btn-sm" href="/p/<?= e($r['handle']) ?>">صفحه فرستنده</a><?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
    <?php if ($next): ?><div class="form-actions form-actions-center"><a class="btn btn-ghost btn-sm" href="/proposals/received?before=<?= e($next) ?>">قدیمی‌تر</a></div><?php endif; ?>
  <?php endif; ?>
</section>
