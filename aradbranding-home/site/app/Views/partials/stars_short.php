<?php
/** Not enough Stars for an action: say so and where to get them. @var int $need @var string|null $buyHref */
?>
<div class="stars-short" role="alert" lang="fa" dir="rtl">
  <span class="stars-short-ic" aria-hidden="true">⭐</span>
  <div>
    <b>موجودی Stars شما کافی نیست.</b>
    <span>برای این کار <?= fa_int(max(1, (int) $need)) ?> Star دیگر لازم دارید. برای تهیه Stars با کارشناسان آراد برندینگ ارتباط بگیرید.</span>
    <?php if (!empty($buyHref)): ?><a class="btn btn-sm" href="<?= e($buyHref) ?>">خرید Stars</a><?php endif; ?>
  </div>
</div>
