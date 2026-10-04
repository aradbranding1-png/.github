<?php
/** @var array $p feed card */
use App\Modules\Proposals\ProposalService;
$thumb = media($p['thumb'] ?? null);
?>
<article class="pcard" data-id="<?= e($p['id']) ?>">
  <header class="pcard-head">
    <?php $oa = media($p['owner_avatar'] ?? null); ?>
    <?php if ($oa): ?><img class="avatar avatar-sm" src="<?= e($oa) ?>" alt="" loading="lazy"><?php else: ?><span class="avatar avatar-sm"><?= e(mb_substr((string) ($p['owner'] ?? '?'), 0, 1)) ?></span><?php endif; ?>
    <span class="pcard-who"><b><?= e($p['owner'] ?? '') ?></b><small><span aria-hidden="true"><?= flag($p['country'] ?? '') ?></span> <?= e(fa_date($p['published_at'] ?? null, false)) ?></small></span>
  </header>
  <a class="pcard-media" href="/proposals/<?= e($p['uid']) ?>" tabindex="-1" aria-hidden="true">
    <?php if ($thumb): ?><img src="<?= e($thumb) ?>" alt="" width="480" height="360" loading="lazy" decoding="async"><?php else: ?><span class="pcard-ph"><svg class="icon"><use href="#i-spark"/></svg></span><?php endif; ?>
    <span class="pcard-type"><?= te(ProposalService::TYPES[$p['type']] ?? '') ?></span>
  </a>
  <div class="pcard-body">
    <h3><a href="/proposals/<?= e($p['uid']) ?>"><?= e(\App\Core\Security\ContactGuard::mask((string) $p['title'])) ?></a></h3>
    <?php if (!empty($p['tags'])): ?>
      <div class="pcard-tags"><?php foreach ($p['tags'] as $t): ?><span>#<?= e($t) ?></span><?php endforeach; ?></div>
    <?php endif; ?>
  </div>
</article>
