<?php
/** @var array $rows */
use App\Modules\Proposals\ProposalService;
?>
<?= $this->partial('proposals/_tabs', ['path' => '/proposals/mine']) ?>
<section class="panel">
  <div class="panel-head"><h2><?= te('پیشنهادهای من') ?></h2><a class="btn btn-sm" href="/proposals/new"><svg class="icon"><use href="#i-plus"/></svg><?= te('پیشنهاد جدید') ?></a></div>
  <?php if ($rows === []): ?>
    <div class="empty">
      <svg class="icon"><use href="#i-spark"/></svg>
      <h3><?= te('هنوز پیشنهادی ندارید') ?></h3>
      <p><?= te('خرید، فروش، مشارکت یا خدمات؛ فرصت تجاری خود را در فید تجار کشورهای مختلف قرار دهید.') ?></p>
      <a class="btn" href="/proposals/new"><?= te('ساخت پیشنهاد تجاری') ?></a>
    </div>
  <?php else: ?>
    <ul class="list">
      <?php foreach ($rows as $r): $status = (int) $r['status']; $thumb = media($r['thumb_path']); ?>
        <li class="list-row">
          <?php if ($thumb): ?><img class="thumb-sm" src="<?= e($thumb) ?>" alt="" loading="lazy"><?php else: ?><span class="thumb-sm"></span><?php endif; ?>
          <div class="grow">
            <a class="title" href="/proposals/<?= e($r['uid']) ?>"><?= e($r['title']) ?></a>
            <div class="meta">
              <span><?= te(ProposalService::TYPES[(int) $r['type']] ?? '') ?></span>
              <span class="chip<?= $status === ProposalService::PUBLISHED ? ' chip-ok' : '' ?>"><?= te(ProposalService::STATUS_LABELS[$status] ?? '') ?></span>
              <span><?= te(':n بازدید', ['n' => fa_int((int) $r['view_count'])]) ?></span>
            </div>
          </div>
          <?php if ($status === ProposalService::PUBLISHED): ?>
            <form class="inline-form" method="post" action="/proposals/<?= e($r['uid']) ?>/unpublish"><?= csrf_field() ?><button class="btn btn-quiet btn-sm" type="submit"><?= te('خارج از فید') ?></button></form>
          <?php else: ?>
            <form class="inline-form" method="post" action="/proposals/<?= e($r['uid']) ?>/publish"><?= csrf_field() ?><button class="btn btn-ghost btn-sm" type="submit"><?= te('انتشار') ?></button></form>
          <?php endif; ?>
          <a class="btn btn-quiet btn-sm" href="/proposals/<?= e($r['uid']) ?>/edit"><?= te('ویرایش') ?></a>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>
