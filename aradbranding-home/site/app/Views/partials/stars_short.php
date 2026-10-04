<?php
/** Not enough Stars for an action: say so and where to get them. @var int $need @var string|null $buyHref */
?>
<div class="stars-short" role="alert" <?= \App\Core\I18n\I18n::langAttrs() ?>>
  <span class="stars-short-ic" aria-hidden="true">⭐</span>
  <div>
    <b><?= te('موجودی Stars شما کافی نیست.') ?></b>
    <span><?= te('برای این کار :n Star دیگر لازم دارید. برای تهیه Stars با کارشناسان آراد برندینگ ارتباط بگیرید.', ['n' => fa_int(max(1, (int) $need))]) ?></span>
    <?php if (!empty($buyHref)): ?><a class="btn btn-sm" href="<?= e($buyHref) ?>"><?= te('خرید Stars') ?></a><?php endif; ?>
  </div>
</div>
