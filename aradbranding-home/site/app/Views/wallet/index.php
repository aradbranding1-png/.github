<?php
/** @var int $balance @var array $history @var array $packages @var array|null $rate @var array $prices @var array $gateways @var array $payments @var int $need */
use App\Modules\Payments\PaymentService;
use App\Modules\Wallet\WalletService;
$actionLabels = ['page_view' => 'مشاهده کامل صفحه تجاری', 'proposal_send' => 'ارسال پیشنهاد تجاری',
    'public_letter' => 'نامه عمومی (هر گیرنده)', 'private_letter' => 'نامه اختصاصی'];
?>
<div class="stack">
  <?php if ($need > 0): ?>
    <div class="alert alert-error" role="status">برای ادامه، <?= fa_int($need) ?> Star دیگر لازم دارید. جهت تهیه Star با کارشناسان آراد برندینگ ارتباط بگیرید.<?= !empty($buyEnabled) ? ' همچنین می‌توانید یکی از بسته‌های زیر را انتخاب کنید.' : '' ?></div>
  <?php endif; ?>

  <section class="panel wallet-hero gilded">
    <div>
      <span class="muted">موجودی شما</span>
      <div class="wallet-balance"><span aria-hidden="true">⭐</span> <?= fa_int($balance) ?> <small>Star</small></div>
    </div>
    <p class="muted">Stars اعتبار داخلی سامانه برای مشاهده صفحه‌ها، ارسال پیشنهاد و نامه است.</p>
  </section>

  <?php if (!empty($buyEnabled)): ?>
  <section class="panel">
    <div class="panel-head"><h2>خرید Stars</h2><?php if ($rate): ?><span class="chip" dir="rtl">هر <bdi>Star</bdi> = <?= e(toman($rate['minor_per_star'])) ?></span><?php endif; ?></div>
    <?php if ($gateways === []): ?>
      <div class="empty"><p>درگاه پرداخت هنوز فعال نشده است. به‌زودی امکان خرید فراهم می‌شود.</p></div>
    <?php else: ?>
      <form class="form" method="post" action="/wallet/buy">
        <?= csrf_field() ?>
        <fieldset class="packages">
          <legend class="sr-only">بسته Stars</legend>
          <?php foreach ($packages as $i => $p): $price = $rate ? $p['stars'] * $rate['minor_per_star'] : 0; ?>
            <label class="package">
              <input type="radio" name="package_id" value="<?= e($p['id']) ?>"<?= $i === 1 || count($packages) === 1 ? ' checked' : '' ?> required>
              <span class="package-body">
                <b><?= fa_int($p['stars']) ?> <small>Star</small></b>
                <?php if ($p['bonus_stars'] > 0): ?><span class="chip chip-gold">+<?= fa_int($p['bonus_stars']) ?> هدیه</span><?php endif; ?>
                <span class="muted"><?= toman($price) ?></span>
              </span>
            </label>
          <?php endforeach; ?>
        </fieldset>
        <?php if (count($gateways) > 1): ?>
          <div class="field">
            <span class="label">درگاه پرداخت</span>
            <?php foreach ($gateways as $i => $g): ?>
              <label class="check"><input type="radio" name="gateway" value="<?= e($g['code']) ?>"<?= $i === 0 ? ' checked' : '' ?>> <?= e($g['name']) ?></label>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <input type="hidden" name="gateway" value="<?= e($gateways[0]['code']) ?>">
        <?php endif; ?>
        <div class="form-actions"><button class="btn" type="submit">پرداخت و خرید</button><span class="muted">پرداخت امن از طریق <?= e($gateways[0]['name']) ?><?= count($gateways) > 1 ? ' و …' : '' ?></span></div>
      </form>
    <?php endif; ?>
  </section>
  <?php endif; ?>

  <section class="panel">
    <h2>تعرفه‌ها</h2>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th>قابلیت</th><th>داخلی</th><th>بین‌المللی</th></tr></thead>
        <tbody>
          <?php foreach ($actionLabels as $key => $label): if (!isset($prices[$key])) { continue; } ?>
            <tr><td><?= e($label) ?></td><td><?= fa_int($prices[$key]['domestic'] ?? 0) ?> ⭐</td><td><?= fa_int($prices[$key]['international'] ?? 0) ?> ⭐</td></tr>
          <?php endforeach; ?>
          <?php if (!empty($publishFee)): ?><tr><td>انتشار پیشنهاد در فید</td><td colspan="2"><?= fa_int($publishFee) ?> ⭐ (یک بار برای هر پیشنهاد)</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
    <p class="hint muted">«داخلی» یعنی شما و طرف مقابل در یک کشور هستید.</p>
  </section>

  <?php if ($payments !== []): ?>
  <section class="panel">
    <h2>پرداخت‌های اخیر</h2>
    <ul class="list">
      <?php foreach ($payments as $p): $status = (int) $p['status']; ?>
        <li class="list-row">
          <div class="grow">
            <div class="title"><?= fa_int((int) $p['stars'] + (int) $p['bonus_stars']) ?> Star · <?= toman((int) $p['amount_minor']) ?></div>
            <div class="meta"><span><?= e($p['gateway_name']) ?></span><span class="ltr"><?= e(substr((string) $p['created_at'], 0, 16)) ?></span></div>
          </div>
          <span class="chip<?= $status === PaymentService::CREDITED ? ' chip-ok' : '' ?>"><?= e(PaymentService::LABELS[$status] ?? '') ?></span>
        </li>
      <?php endforeach; ?>
    </ul>
  </section>
  <?php endif; ?>

  <section class="panel">
    <h2>گردش حساب</h2>
    <?php if ($history['rows'] === []): ?>
      <div class="empty"><p>هنوز تراکنشی ندارید.</p></div>
    <?php else: ?>
      <ul class="list">
        <?php foreach ($history['rows'] as $t): $amount = (int) $t['amount']; $byAdmin = in_array((int) $t['type'], [WalletService::T_ADMIN_CREDIT, WalletService::T_ADMIN_DEBIT], true); ?>
          <li class="list-row">
            <div class="grow">
              <div class="title"><?= e($byAdmin ? WalletService::TYPE_LABELS[(int) $t['type']] : (WalletService::REASON_LABELS[$t['reason']] ?? WalletService::TYPE_LABELS[(int) $t['type']] ?? $t['reason'])) ?></div>
              <?php if (($t['note'] ?? '') !== '' && ($byAdmin || $t['reason'] === 'api_charge')): ?><div class="tx-note">توضیح: <?= e((string) $t['note']) ?></div><?php endif; ?>
              <div class="meta"><span><?= e(fa_date($t['created_at'])) ?></span><span>مانده: <?= fa_int((int) $t['balance_after']) ?></span></div>
            </div>
            <b class="amount <?= $amount >= 0 ? 'plus' : 'minus' ?>" dir="ltr"><?= $amount >= 0 ? '+' : '−' ?><?= fa_int(abs($amount)) ?></b>
          </li>
        <?php endforeach; ?>
      </ul>
      <?php if ($history['next'] !== null): ?>
        <div class="form-actions form-actions-center"><a class="btn btn-ghost btn-sm" href="/wallet?before=<?= e($history['next']) ?>">تراکنش‌های قدیمی‌تر</a></div>
      <?php endif; ?>
    <?php endif; ?>
  </section>
</div>
