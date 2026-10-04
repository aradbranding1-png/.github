<?php /** @var array $t trader */
$av = media($t['avatar_path'] ?? null);
$name = $t['company_name'] ?: trim($t['first_name'] . ' ' . $t['last_name']); ?>
<a class="trader" href="/p/<?= e($t['handle']) ?>">
  <?php if ($av): ?><img class="avatar trader-avatar" src="<?= e($av) ?>" alt="" loading="lazy"><?php else: ?><span class="avatar trader-avatar"><?= e(mb_substr($name, 0, 1)) ?></span><?php endif; ?>
  <b><?= e($name) ?><?= $t['business_verified_at'] ? ' <span class="verified" title="' . te('کسب‌وکار تأییدشده') . '">✓</span>' : '' ?></b>
  <small><span aria-hidden="true"><?= flag($t['country_code']) ?></span> <?= e($t['business_area'] ?: trim($t['first_name'] . ' ' . $t['last_name'])) ?></small>
</a>
