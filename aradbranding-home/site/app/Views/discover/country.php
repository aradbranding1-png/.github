<?php /** @var array $country @var array $traders @var ?int $next */ ?>
<div class="stack">
  <section class="welcome gilded"><div><h2><span aria-hidden="true"><?= flag($country['code']) ?></span> <?= te('تجار :country', ['country' => t($country['name_fa'])]) ?></h2><p class="muted"><?= te('صفحه هر تاجر را ببینید و با ارسال نامه یا پیشنهاد، ارتباط بگیرید.') ?></p></div>
    <div class="welcome-actions"><a class="btn btn-ghost" href="/letters/send?country=<?= e($country['id']) ?>"><?= te('ارسال نامه به تجار این کشور') ?></a></div></section>
  <?php if ($traders === []): ?><div class="panel empty"><p><?= te('هنوز تاجری از این کشور صفحه منتشر نکرده است.') ?></p></div>
  <?php else: ?><div class="trader-grid"><?php foreach ($traders as $t): ?><?= $this->partial('discover/_trader', ['t' => $t]) ?><?php endforeach; ?></div><?php endif; ?>
  <?php if ($next): ?><div class="form-actions form-actions-center"><a class="btn btn-ghost btn-sm" href="/discover/country/<?= e($country['id']) ?>?before=<?= e($next) ?>"><?= te('بیشتر') ?></a></div><?php endif; ?>
</div>
