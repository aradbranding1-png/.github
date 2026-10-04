<?php /** @var string $q @var string $type */ ?>
<form class="search-bar" method="get" action="/search" role="search">
  <svg class="icon" aria-hidden="true"><use href="#i-search"/></svg>
  <input class="input" type="search" name="q" value="<?= e($q ?? '') ?>" placeholder="<?= te('جستجوی تاجر، کالا یا فرصت…') ?>" aria-label="<?= te('جستجو') ?>" enterkeyhint="search">
  <input type="hidden" name="type" value="<?= e($type ?? 'traders') ?>">
</form>
