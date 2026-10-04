<?php
/**
 * API و کلید دسترسی — personal keys for the official API v1.
 * @var bool $enabled @var array $keys @var array $scopes @var ?array $newKey @var string $baseUrl @var array $errors @var array $old
 */
use App\Modules\Integrations\ApiKeys;
$err = static fn (string $k): string => isset($errors[$k]) ? '<div class="error">' . e($errors[$k]) . '</div>' : '';
$cls = static fn (string $k): string => isset($errors[$k]) ? ' has-error' : '';
$picked = (array) ($old['scopes'] ?? ['profile.read', 'letters.read']);
$scopeNames = ['profile.read' => t('حساب'), 'wallet.read' => t('کیف پول'), 'letters.read' => t('خواندن نامه'), 'letters.send' => t('ارسال نامه'), 'proposals.read' => t('پیشنهادها'), 'notifications.read' => t('اعلان‌ها'), 'directory.read' => t('فهرست تاجران')];
$active = 0;
foreach ($keys as $k) {
    $active += $k['revoked_at'] === null && (int) $k['is_expired'] === 0 ? 1 : 0;
}
?>
<div class="stack api-page">
  <?php if ($newKey !== null): ?>
    <section class="panel api-newkey" role="status">
      <h2><?= te('کلید «:name» ساخته شد', ['name' => $newKey['name']]) ?></h2>
      <p class="muted"><?= te('این کلید فقط همین یک بار نمایش داده می‌شود. آن را در جای امنی نگه دارید و هرگز در کد سمت مرورگر یا مخزن عمومی قرار ندهید.') ?></p>
      <div class="api-key"><code dir="ltr"><?= e($newKey['key']) ?></code><button class="btn btn-sm" type="button" data-copy="<?= e($newKey['key']) ?>"><?= te('کپی') ?></button></div>
    </section>
  <?php endif; ?>

  <section class="panel">
    <div class="panel-head">
      <div>
        <h2><?= te('API و کلید دسترسی') ?></h2>
        <p class="muted"><?= te('این بخش فقط برای مدیر کل سامانه است. با کلید شخصی، نرم‌افزارهای خودتان (CRM، حسابداری، اتوماسیون فروش) می‌توانند به‌جای شما صندوق نامه‌ها، پیشنهادها، کیف پول و اعلان‌ها را بخوانند و در صورت اجازه نامه بفرستند. هر کلید فقط همان دسترسی‌هایی را دارد که انتخاب می‌کنید و هر وقت بخواهید باطل می‌شود.') ?></p>
      </div>
    </div>
    <?php if (!$enabled): ?><div class="alert alert-error" role="alert"><?= te('API فعلاً توسط مدیر سامانه غیرفعال شده است؛ کلیدها تا فعال‌شدن دوباره کار نمی‌کنند.') ?></div><?php endif; ?>
    <div class="api-endpoints">
      <div><b>GET</b><code dir="ltr"><?= e($baseUrl) ?>/me</code><span><?= te('حساب و شمارنده‌ها') ?></span></div>
      <div><b>GET</b><code dir="ltr"><?= e($baseUrl) ?>/wallet</code><span><?= te('موجودی و گردش Stars') ?></span></div>
      <div><b>GET</b><code dir="ltr"><?= e($baseUrl) ?>/letters</code><span><?= te('صندوق نامه‌ها') ?></span></div>
      <div><b>GET</b><code dir="ltr"><?= e($baseUrl) ?>/letters/{id}</code><span><?= te('یک گفتگو') ?></span></div>
      <div><b>POST</b><code dir="ltr"><?= e($baseUrl) ?>/letters</code><span><?= te('ارسال نامه اختصاصی') ?></span></div>
      <div><b>POST</b><code dir="ltr"><?= e($baseUrl) ?>/letters/{id}/reply</code><span><?= te('پاسخ (رایگان)') ?></span></div>
      <div><b>GET</b><code dir="ltr"><?= e($baseUrl) ?>/proposals/mine</code><span><?= te('پیشنهادهای من') ?></span></div>
      <div><b>GET</b><code dir="ltr"><?= e($baseUrl) ?>/proposals/feed</code><span><?= te('فید پیشنهادها') ?></span></div>
      <div><b>GET</b><code dir="ltr"><?= e($baseUrl) ?>/notifications</code><span><?= te('اعلان‌ها') ?></span></div>
      <div><b>GET</b><code dir="ltr"><?= e($baseUrl) ?>/traders?q=…</code><span><?= te('جستجوی تاجران') ?></span></div>
    </div>
    <pre class="api-sample" dir="ltr"><code>curl -H "Authorization: Bearer ark_…" <?= e($baseUrl) ?>/me</code></pre>
    <p class="hint muted"><?= te('پاسخ‌ها JSON با قالب') ?> <code dir="ltr">{"ok", "data", "message"}</code> <?= te('هستند. برای ارسال نامه، سربرگ') ?> <code dir="ltr">Idempotency-Key</code> <?= te('لازم است تا تکرار درخواست دوبار هزینه کم نکند. سقف درخواست: ۳۰۰ در دقیقه برای هر کلید.') ?></p>
  </section>

  <section class="panel">
    <h2><?= te('کلیدهای من') ?> <small class="muted">(<?= te(':n فعال از :max', ['n' => fa_int($active), 'max' => fa_int(ApiKeys::MAX_ACTIVE)]) ?>)</small></h2>
    <?php if ($keys === []): ?>
      <p class="muted"><?= te('هنوز کلیدی نساخته‌اید.') ?></p>
    <?php else: ?>
      <ul class="list">
        <?php foreach ($keys as $k): $live = $k['revoked_at'] === null && (int) $k['is_expired'] === 0; ?>
          <li class="list-row api-row<?= $live ? '' : ' is-off' ?>">
            <span class="api-dot<?= $live ? ' on' : '' ?>" aria-hidden="true"></span>
            <div class="grow">
              <div class="title"><?= e($k['name']) ?> <code dir="ltr">ark_<?= e($k['prefix']) ?>_…</code></div>
              <div class="meta">
                <span><?= e(implode(t('، '), array_map(static fn (string $s): string => $scopeNames[$s] ?? $s, array_filter(explode(',', (string) $k['scopes']))))) ?></span>
                <span><?= $k['expires_at'] ? ((int) $k['is_expired'] === 1 ? te('منقضی‌شده') : te('اعتبار تا :date', ['date' => fa_date($k['expires_at'])])) : te('بدون تاریخ انقضا') ?></span>
                <span><?= te(':n درخواست', ['n' => fa_int((int) $k['requests'])]) ?> · <?= $k['last_used_at'] ? te('آخرین استفاده :date', ['date' => fa_date($k['last_used_at'])]) : te('هنوز استفاده نشده') ?></span>
                <?php if ($k['revoked_at'] !== null): ?><span class="chip"><?= te('باطل‌شده') ?></span><?php endif; ?>
              </div>
            </div>
            <?php if ($k['revoked_at'] === null): ?>
              <form method="post" action="/account/api/<?= (int) $k['id'] ?>/revoke"><?= csrf_field() ?><button class="btn btn-quiet btn-sm" type="submit"><?= te('باطل‌کردن') ?></button></form>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>

  <form class="panel form" method="post" action="/account/api" novalidate>
    <?= csrf_field() ?>
    <h2><?= te('کلید جدید') ?></h2>
    <div class="row row-2">
      <div class="field<?= $cls('name') ?>"><label for="ak-name"><?= te('نام کلید') ?></label><input class="input" id="ak-name" name="name" maxlength="80" value="<?= e($old['name'] ?? '') ?>" placeholder="<?= te('مثلاً CRM فروش') ?>" required><?= $err('name') ?></div>
      <div class="field"><label for="ak-days"><?= te('اعتبار') ?></label>
        <select class="select" id="ak-days" name="days">
          <?php foreach (ApiKeys::EXPIRY_DAYS as $d): ?><option value="<?= $d ?>"<?= (int) ($old['days'] ?? 90) === $d ? ' selected' : '' ?>><?= $d === 0 ? te('بدون انقضا') : te(':n روز', ['n' => fa_int($d)]) ?></option><?php endforeach; ?>
        </select>
      </div>
    </div>
    <fieldset class="ff-group<?= $cls('scopes') ?>">
      <legend><?= te('دسترسی‌ها') ?></legend>
      <?php foreach ($scopes as $key => $label): ?>
        <label class="check"><input type="checkbox" name="scopes[]" value="<?= e($key) ?>"<?= in_array($key, $picked, true) ? ' checked' : '' ?>> <?= te($label) ?> <code dir="ltr"><?= e($key) ?></code></label>
      <?php endforeach; ?>
      <?= $err('scopes') ?>
    </fieldset>
    <div class="field<?= $cls('password') ?>"><label for="ak-pw"><?= te('رمز عبور شما') ?></label><input class="input narrow" id="ak-pw" name="password" type="password" autocomplete="current-password" dir="ltr" required><?= $err('password') ?></div>
    <div class="form-actions"><button class="btn" type="submit"<?= $enabled ? '' : ' disabled' ?>><?= te('ساخت کلید') ?></button></div>
  </form>
</div>
