<?php
/** Compact opportunity row for Discover (no tags). @var array $p feed card */
use App\Modules\Proposals\ProposalService;
$thumb = media($p['thumb'] ?? null);
?>
<a class="pmini" href="/proposals/<?= e($p['uid']) ?>">
  <span class="pmini-img"><?php if ($thumb): ?><img src="<?= e($thumb) ?>" alt="" width="120" height="90" loading="lazy" decoding="async"><?php else: ?><svg class="icon" aria-hidden="true"><use href="#i-spark"/></svg><?php endif; ?></span>
  <span class="pmini-body">
    <b><?= e(\App\Core\Security\ContactGuard::mask((string) $p['title'])) ?></b>
    <small><span class="pmini-type"><?= te(ProposalService::TYPES[$p['type']] ?? '') ?></span><span aria-hidden="true"><?= flag($p['country'] ?? '') ?></span> <?= e($p['owner'] ?? '') ?></small>
  </span>
</a>
