<?php
/** @var int $balance @var array $history @var array $packages @var array|null $rate @var array $prices @var array $gateways @var array $payments @var int $need */
use App\Modules\Payments\PaymentService;
use App\Modules\Wallet\WalletService;
$actionLabels = ['page_view' => t('مشاهده کامل صفحه تجاری'), 'proposal_send' => t('ارسال پیشنهاد تجاری'),
    'public_letter' => t('نامه عمومی (هر گیرنده)'), 'private_letter' => t('نامه اختصاصی')];
?>
<div class="stack">
  <?php if ($need > 0): ?>
    <div class="alert alert-error" role="status"><?= te('برای ادامه، :n Star دیگر لازم دارید. جهت تهیه Star با کارشناسان آراد برندینگ ارتباط بگیرید.', ['n' => fa_int($need)]) ?><?= !empty($buyEnabled) ? t(' همچنین می‌توانید یکی از بسته‌های زیر را انتخاب کنید.') : '' ?></div>
  <?php endif; ?>

  <section class="panel wallet-hero gilded">
    <div>
      <span class="muted"><?= te('موجودی شما') ?></span>
      <div class="wallet-balance"><span aria-hidden="true">⭐</span> <?= fa_int($balance) ?> <small>Star</small></div>
    </div>
    <p class="muted"><?= te('Stars اعتبار داخلی سامانه برای مشاهده صفحه‌ها، ارسال پیشنهاد و نامه است.') ?></p>
  </section>

  <?php if (!empty($buyEnabled)): ?>
  <section class="panel">
    <div class="panel-head"><h2><?= te('خرید Stars') ?></h2><?php if ($rate): ?><span class="chip" dir="rtl"><?= te('هر') ?> <bdi>Star</bdi> = <?= e(toman($rate['minor_per_star'])) ?></span><?php endif; ?></div>
    <?php if ($gateways === []): ?>
      <div class="empty"><p><?= te('درگاه پرداخت هنوز فعال نشده است. به‌زودی امکان خرید فراهم می‌شود.') ?></p></div>
    <?php else: ?>
      <form class="form" method="post" action="/wallet/buy">
        <?= csrf_field() ?>
        <fieldset class="packages">
          <legend class="sr-only"><?= te('بسته Stars') ?></legend>
          <?php foreach ($packages as $i => $p): $price = $rate ? $p['stars'] * $rate['minor_per_star'] : 0; ?>
            <label class="package">
              <input type="radio" name="package_id" value="<?= e($p['id']) ?>"<?= $i === 1 || count($packages) === 1 ? ' checked' : '' ?> required>
              <span class="package-body">
                <b><?= fa_int($p['stars']) ?> <small>Star</small></b>
                <?php if ($p['bonus_stars'] > 0): ?><span class="chip chip-gold"><?= te('+:n هدیه', ['n' => fa_int($p['bonus_stars'])]) ?></span><?php endif; ?>
                <span class="muted"><?= toman($price) ?></span>
              </span>
            </label>
          <?php endforeach; ?>
        </fieldset>
        <?php if (count($gateways) > 1): ?>
          <div class="field">
            <span class="label"><?= te('درگاه پرداخت') ?></span>
            <?php foreach ($gateways as $i => $g): ?>
              <label class="check"><input type="radio" name="gateway" value="<?= e($g['code']) ?>"<?= $i === 0 ? ' checked' : '' ?>> <?= e($g['name']) ?></label>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <input type="hidden" name="gateway" value="<?= e($gateways[0]['code']) ?>">
        <?php endif; ?>
        <div class="form-actions"><button class="btn" type="submit"><?= te('پرداخت و خرید') ?></button><span class="muted"><?= te('پرداخت امن از طریق :name', ['name' => $gateways[0]['name']]) ?><?= count($gateways) > 1 ? t(' و …') : '' ?></span></div>
      </form>
    <?php endif; ?>
  </section>
  <?php endif; ?>

  <section class="panel">
    <h2><?= te('تعرفه‌ها') ?></h2>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th><?= te('قابلیت') ?></th><th><?= te('داخلی') ?></th><th><?= te('بین‌المللی') ?></th></tr></thead>
        <tbody>
          <?php foreach ($actionLabels as $key => $label): if (!isset($prices[$key])) { continue; } ?>
            <tr><td><?= te($label) ?></td><td><?= fa_int($prices[$key]['domestic'] ?? 0) ?> ⭐</td><td><?= fa_int($prices[$key]['international'] ?? 0) ?> ⭐</td></tr>
          <?php endforeach; ?>
          <?php if (!empty($publishFee)): ?><tr><td><?= te('انتشار پیشنهاد در فید') ?></td><td colspan="2"><?= fa_int($publishFee) ?> ⭐ <?= te('(یک بار برای هر پیشنهاد)') ?></td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
    <p class="hint muted"><?= te('«داخلی» یعنی شما و طرف مقابل در یک کشور هستید.') ?></p>
  </section>

  <?php if ($payments !== []): ?>
  <section class="panel">
    <h2><?= te('پرداخت‌های اخیر') ?></h2>
    <ul class="list">
      <?php foreach ($payments as $p): $status = (int) $p['status']; ?>
        <li class="list-row">
          <div class="grow">
            <div class="title"><?= fa_int((int) $p['stars'] + (int) $p['bonus_stars']) ?> Star · <?= toman((int) $p['amount_minor']) ?></div>
            <div class="meta"><span><?= e($p['gateway_name']) ?></span><span class="ltr"><?= e(substr((string) $p['created_at'], 0, 16)) ?></span></div>
          </div>
          <span class="chip<?= $status === PaymentService::CREDITED ? ' chip-ok' : '' ?>"><?= te(PaymentService::LABELS[$status] ?? '') ?></span>
        </li>
      <?php endforeach; ?>
    </ul>
  </section>
  <?php endif; ?>

  <section class="panel">
    <h2><?= te('گردش حساب') ?></h2>
    <?php if ($history['rows'] === []): ?>
      <div class="empty"><p><?= te('هنوز تراکنشی ندارید.') ?></p></div>
    <?php else: ?>
      <ul class="list">
        <?php foreach ($history['rows'] as $t): $amount = (int) $t['amount']; $byAdmin = in_array((int) $t['type'], [WalletService::T_ADMIN_CREDIT, WalletService::T_ADMIN_DEBIT], true); ?>
          <li class="list-row">
            <div class="grow">
              <div class="title"><?= te($byAdmin ? WalletService::TYPE_LABELS[(int) $t['type']] : (WalletService::REASON_LABELS[$t['reason']] ?? WalletService::TYPE_LABELS[(int) $t['type']] ?? $t['reason'])) ?></div>
              <?php if (($t['note'] ?? '') !== '' && ($byAdmin || $t['reason'] === 'api_charge')): ?><div class="tx-note"><?= te('توضیح: :note', ['note' => (string) $t['note']]) ?></div><?php endif; ?>
              <div class="meta"><span><?= e(fa_date($t['created_at'])) ?></span><span><?= te('مانده: :n', ['n' => fa_int((int) $t['balance_after'])]) ?></span></div>
            </div>
            <b class="amount <?= $amount >= 0 ? 'plus' : 'minus' ?>" dir="ltr"><?= $amount >= 0 ? '+' : '−' ?><?= fa_int(abs($amount)) ?></b>
          </li>
        <?php endforeach; ?>
      </ul>
      <?php if ($history['next'] !== null): ?>
        <div class="form-actions form-actions-center"><a class="btn btn-ghost btn-sm" href="/wallet?before=<?= e($history['next']) ?>"><?= te('تراکنش‌های قدیمی‌تر') ?></a></div>
      <?php endif; ?>
    <?php endif; ?>
  </section>
</div>
