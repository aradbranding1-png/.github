<?php
/** @var array $payment @var string|null $after */
use App\Modules\Payments\PaymentService;
$status = (int) $payment['status'];
$ok = $status === PaymentService::CREDITED;
$waiting = in_array($status, [PaymentService::PENDING, PaymentService::VERIFYING, PaymentService::PAID], true);
?>
<section class="panel result <?= $ok ? 'result-ok' : ($waiting ? '' : 'result-fail') ?>">
  <div class="result-icon" aria-hidden="true"><?= $ok ? '✓' : ($waiting ? '…' : '!') ?></div>
  <h2><?= $ok ? t('پرداخت موفق بود') : ($waiting ? t('پرداخت در حال بررسی است') : t('پرداخت انجام نشد')) ?></h2>
  <?php if ($ok): ?>
    <p><?= (int) $payment['bonus_stars'] > 0
        ? te(':n Star و :b Star هدیه به کیف پول شما اضافه شد.', ['n' => fa_int((int) $payment['stars']), 'b' => fa_int((int) $payment['bonus_stars'])])
        : te(':n Star به کیف پول شما اضافه شد.', ['n' => fa_int((int) $payment['stars'])]) ?></p>
  <?php elseif ($waiting): ?>
    <p><?= te('نتیجه تا چند دقیقه دیگر مشخص می‌شود. اگر مبلغ از حساب شما کم شده باشد، Stars خودکار اضافه می‌شود و در غیر این صورت، بانک طی ۷۲ ساعت مبلغ را برمی‌گرداند.') ?></p>
  <?php else: ?>
    <p><?= te('اگر مبلغی از حساب شما کم شده باشد، طی ۷۲ ساعت توسط بانک برگشت داده می‌شود.') ?></p>
  <?php endif; ?>
  <dl class="result-meta">
    <div><dt><?= te('مبلغ') ?></dt><dd><?= toman((int) $payment['amount_minor']) ?></dd></div>
    <div><dt><?= te('درگاه') ?></dt><dd><?= e($payment['gateway_name']) ?></dd></div>
    <?php if ($payment['gateway_ref']): ?><div><dt><?= te('کد پیگیری') ?></dt><dd class="ltr"><?= e($payment['gateway_ref']) ?></dd></div><?php endif; ?>
    <div><dt><?= te('وضعیت') ?></dt><dd><?= te(PaymentService::LABELS[$status] ?? '') ?></dd></div>
  </dl>
  <div class="form-actions form-actions-center">
    <?php if ($ok && $after): ?><a class="btn" href="<?= e($after) ?>"><?= te('ادامه کار قبلی') ?></a><?php endif; ?>
    <a class="btn <?= $ok && $after ? 'btn-ghost' : '' ?>" href="/wallet"><?= te('کیف پول') ?></a>
  </div>
</section>
