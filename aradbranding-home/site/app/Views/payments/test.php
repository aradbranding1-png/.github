<?php /** @var string $authority @var string $cb */
$sep = str_contains($cb, '?') ? '&' : '?'; ?>
<section class="panel result">
  <div class="result-icon" aria-hidden="true">🧪</div>
  <h2><?= te('درگاه آزمایشی') ?></h2>
  <p><?= te('این صفحه فقط برای تست است و پولی جابه‌جا نمی‌شود. پیش از راه‌اندازی عمومی،') ?> <code class="ltr">PAYMENT_TEST_GATEWAY</code> <?= te('را در .env خاموش کنید.') ?></p>
  <div class="form-actions form-actions-center">
    <a class="btn" href="<?= e($cb . $sep . 'Authority=' . rawurlencode($authority) . '&Status=OK') ?>"><?= te('پرداخت موفق') ?></a>
    <a class="btn btn-ghost" href="<?= e($cb . $sep . 'Authority=' . rawurlencode($authority) . '&Status=NOK') ?>"><?= te('انصراف از پرداخت') ?></a>
  </div>
</section>
