<?php
/** @var array $recipient @var array $proposals @var int $price @var int $balance @var string $token @var array $errors @var array $old */
$enough = $balance >= $price;
?>
<section class="panel form">
  <div class="owner-row">
    <?php $ra = media($recipient['avatar_path']); ?>
    <?php if ($ra): ?><img class="avatar" src="<?= e($ra) ?>" alt=""><?php else: ?><span class="avatar"><?= e(initials($recipient['first_name'], $recipient['last_name'])) ?></span><?php endif; ?>
    <span class="grow"><b><?= te('ارسال پیشنهاد برای :name', ['name' => $recipient['first_name'] . ' ' . $recipient['last_name']]) ?></b><span class="muted"><?= flag($recipient['country_code']) ?> <?= te($recipient['country_fa']) ?></span></span>
  </div>

  <?php if ($proposals === []): ?>
    <div class="empty"><p><?= te('برای ارسال، ابتدا یک پیشنهاد بسازید.') ?></p><a class="btn" href="/proposals/new"><?= te('ساخت پیشنهاد') ?></a></div>
  <?php else: ?>
    <form class="form" method="post" action="/proposals/send">
      <?= csrf_field() ?>
      <input type="hidden" name="to" value="<?= e($recipient['handle']) ?>">
      <input type="hidden" name="token" value="<?= e($token) ?>">
      <div class="field<?= isset($errors['proposal']) ? ' has-error' : '' ?>">
        <label for="f-proposal"><?= te('کدام پیشنهاد؟') ?></label>
        <select class="select" id="f-proposal" name="proposal">
          <?php foreach ($proposals as $p): ?><option value="<?= e($p['uid']) ?>"<?= ($old['proposal'] ?? '') === $p['uid'] ? ' selected' : '' ?>><?= e($p['title']) ?><?= (int) $p['status'] !== 2 ? t(' (پیش‌نویس؛ هنگام ارسال منتشر می‌شود)') : '' ?></option><?php endforeach; ?>
        </select>
        <?php if (isset($errors['proposal'])): ?><div class="error"><?= e($errors['proposal']) ?></div><?php endif; ?>
      </div>
      <div class="field<?= isset($errors['message']) ? ' has-error' : '' ?>">
        <label for="f-message"><?= te('پیام همراه') ?> <span class="muted"><?= te('(اختیاری)') ?></span></label>
        <textarea class="textarea" id="f-message" name="message" maxlength="1000" rows="4"><?= e($old['message'] ?? '') ?></textarea>
        <?php if (isset($errors['message'])): ?><div class="error"><?= e($errors['message']) ?></div><?php endif; ?>
      </div>
      <?php if (!$enough): ?><?= $this->partial('partials/stars_short', ['need' => $price - $balance, 'buyHref' => !empty($buyEnabled) ? '/wallet?need=' . ($price - $balance) . '&next=' . rawurlencode('/proposals/send?to=' . $recipient['handle']) : null]) ?><?php endif; ?>
      <div class="form-actions">
        <button class="btn" type="submit"<?= $enough ? '' : ' disabled aria-disabled="true"' ?>><svg class="icon"><use href="#i-send"/></svg><?= te('ارسال با :n Star', ['n' => fa_int($price)]) ?></button>
        <span class="muted"><?= te('موجودی: :n Star', ['n' => fa_int($balance)]) ?></span>
      </div>
    </form>
  <?php endif; ?>
</section>
