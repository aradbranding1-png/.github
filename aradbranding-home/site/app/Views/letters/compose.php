<?php
/** @var ?array $recipient @var ?int $price @var int $balance @var string $token @var array $old @var array $errors */
$err = static fn (string $k): string => isset($errors[$k]) ? '<div class="error">' . e($errors[$k]) . '</div>' : '';
?>
<div class="mailbox">
  <?= $this->partial('letters/_nav', ['user' => $user, 'active' => 'compose', 'canOfficial' => $canOfficial ?? false]) ?>
  <div class="mail-content stack">
<section class="panel form">
  <h2><?= te('نامه اختصاصی') ?></h2>
  <?php if ($recipient === null): ?>
    <form class="form" method="get" action="/letters/new">
      <div class="field<?= isset($errors['to']) ? ' has-error' : '' ?>">
        <label for="f-to"><?= te('گیرنده') ?></label>
        <div class="prefix-input"><span>aradbranding.app/p/</span><input class="input" id="f-to" name="to" value="<?= e($old['to'] ?? '') ?>" required autocapitalize="off" spellcheck="false"></div>
        <div class="hint"><?= te('نشانی صفحه تاجر را وارد کنید، یا از صفحه عمومی هر تاجر دکمه «ارسال نامه» را بزنید.') ?></div>
        <?= $err('to') ?>
      </div>
      <div class="form-actions"><button class="btn" type="submit"><?= te('ادامه') ?></button></div>
    </form>
  <?php else: $enough = $balance >= (int) $price; ?>
    <div class="owner-row">
      <?php $ra = media($recipient['avatar_path']); ?>
      <?php if ($ra): ?><img class="avatar" src="<?= e($ra) ?>" alt=""><?php else: ?><span class="avatar"><?= e(initials($recipient['first_name'], $recipient['last_name'])) ?></span><?php endif; ?>
      <span class="grow"><b><?= e($recipient['first_name'] . ' ' . $recipient['last_name']) ?></b><span class="muted"><?= flag($recipient['country_code']) ?> <?= te($recipient['country_fa']) ?></span></span>
    </div>
    <?php if (isset($errors['to'])): ?><div class="alert alert-error" role="alert"><?= e($errors['to']) ?></div><?php endif; ?>
    <form class="form" method="post" action="/letters/new">
      <?= csrf_field() ?>
      <input type="hidden" name="to" value="<?= e($recipient['handle']) ?>">
      <input type="hidden" name="token" value="<?= e($token) ?>">
      <div class="field<?= isset($errors['subject']) ? ' has-error' : '' ?>"><label for="f-subject"><?= te('موضوع') ?></label><input class="input" id="f-subject" name="subject" maxlength="150" value="<?= e($old['subject'] ?? '') ?>" required><?= $err('subject') ?></div>
      <div class="field<?= isset($errors['body']) ? ' has-error' : '' ?>"><label for="f-body"><?= te('متن نامه') ?></label><textarea class="textarea" id="f-body" name="body" rows="8" maxlength="10000" required><?= e($old['body'] ?? '') ?></textarea><?= $err('body') ?></div>
      <?php if (!$enough): ?><?= $this->partial('partials/stars_short', ['need' => (int) $price - $balance, 'buyHref' => !empty($buyEnabled) ? '/wallet?need=' . ((int) $price - $balance) . '&next=' . rawurlencode('/letters/new?to=' . $recipient['handle']) : null]) ?><?php endif; ?>
      <div class="form-actions">
        <button class="btn" type="submit"<?= $enough ? '' : ' disabled aria-disabled="true"' ?>><svg class="icon"><use href="#i-send"/></svg><?= te('ارسال با :n Star', ['n' => fa_int((int) $price)]) ?></button>
        <span class="muted"><?= te('موجودی: :n Star', ['n' => fa_int($balance)]) ?> · <?= te('پاسخ‌ها رایگان است') ?></span>
      </div>
    </form>
  <?php endif; ?>
</section>

  </div>
</div>
