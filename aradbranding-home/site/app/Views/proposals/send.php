<?php
/** @var array $recipient @var array $proposals @var int $price @var int $balance @var string $token @var array $errors @var array $old */
$enough = $balance >= $price;
?>
<section class="panel form">
  <div class="owner-row">
    <?php $ra = media($recipient['avatar_path']); ?>
    <?php if ($ra): ?><img class="avatar" src="<?= e($ra) ?>" alt=""><?php else: ?><span class="avatar"><?= e(initials($recipient['first_name'], $recipient['last_name'])) ?></span><?php endif; ?>
    <span class="grow"><b>ارسال پیشنهاد برای <?= e($recipient['first_name'] . ' ' . $recipient['last_name']) ?></b><span class="muted"><?= flag($recipient['country_code']) ?> <?= e($recipient['country_fa']) ?></span></span>
  </div>

  <?php if ($proposals === []): ?>
    <div class="empty"><p>برای ارسال، ابتدا یک پیشنهاد بسازید.</p><a class="btn" href="/proposals/new">ساخت پیشنهاد</a></div>
  <?php else: ?>
    <form class="form" method="post" action="/proposals/send">
      <?= csrf_field() ?>
      <input type="hidden" name="to" value="<?= e($recipient['handle']) ?>">
      <input type="hidden" name="token" value="<?= e($token) ?>">
      <div class="field<?= isset($errors['proposal']) ? ' has-error' : '' ?>">
        <label for="f-proposal">کدام پیشنهاد؟</label>
        <select class="select" id="f-proposal" name="proposal">
          <?php foreach ($proposals as $p): ?><option value="<?= e($p['uid']) ?>"<?= ($old['proposal'] ?? '') === $p['uid'] ? ' selected' : '' ?>><?= e($p['title']) ?><?= (int) $p['status'] !== 2 ? ' (پیش‌نویس؛ هنگام ارسال منتشر می‌شود)' : '' ?></option><?php endforeach; ?>
        </select>
        <?php if (isset($errors['proposal'])): ?><div class="error"><?= e($errors['proposal']) ?></div><?php endif; ?>
      </div>
      <div class="field<?= isset($errors['message']) ? ' has-error' : '' ?>">
        <label for="f-message">پیام همراه <span class="muted">(اختیاری)</span></label>
        <textarea class="textarea" id="f-message" name="message" maxlength="1000" rows="4"><?= e($old['message'] ?? '') ?></textarea>
        <?php if (isset($errors['message'])): ?><div class="error"><?= e($errors['message']) ?></div><?php endif; ?>
      </div>
      <div class="form-actions">
        <?php if ($enough): ?>
          <button class="btn" type="submit"><svg class="icon"><use href="#i-send"/></svg>ارسال با <?= fa_int($price) ?> Star</button>
        <?php elseif (!empty($buyEnabled)): ?>
          <a class="btn" href="/wallet?need=<?= e($price - $balance) ?>&amp;next=<?= e(rawurlencode('/proposals/send?to=' . $recipient['handle'])) ?>">خرید Stars</a>
        <?php endif; ?>
        <span class="muted">موجودی: <?= fa_int($balance) ?> Star</span>
      </div>
    </form>
  <?php endif; ?>
</section>
