<?php
/**
 * Sign-up form. With $google (a verified Google identity without an account) it is the short "sign up with Google"
 * step: the address comes from Google and no password is asked.
 * @var array $countries @var array $languages @var array $old @var array $errors @var array|null $google @var bool $googleEnabled
 */
$google ??= null;
$googleIcon = '<svg viewBox="0 0 48 48" aria-hidden="true"><path fill="#FFC107" d="M43.6 20.5H42V20H24v8h11.3C33.7 32.7 29.2 36 24 36c-6.6 0-12-5.4-12-12s5.4-12 12-12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 12.9 4 4 12.9 4 24s8.9 20 20 20 20-8.9 20-20c0-1.3-.1-2.4-.4-3.5z"/><path fill="#FF3D00" d="M6.3 14.7l6.6 4.8C14.7 15.1 19 12 24 12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 16.3 4 9.7 8.3 6.3 14.7z"/><path fill="#4CAF50" d="M24 44c5.2 0 9.9-2 13.4-5.2l-6.2-5.2C29.2 35.1 26.7 36 24 36c-5.2 0-9.6-3.3-11.3-8l-6.5 5C9.5 39.6 16.2 44 24 44z"/><path fill="#1976D2" d="M43.6 20.5H42V20H24v8h11.3c-.8 2.2-2.2 4.2-4.1 5.6l6.2 5.2C37 39.2 44 34 44 24c0-1.3-.1-2.4-.4-3.5z"/></svg>';
$selCountry = (int) ($old['country_id'] ?? 0);
$selLang = (int) ($old['language_id'] ?? 0);
$cc = $old['phone_cc'] ?? '';
if ($cc === '' && $selCountry && isset($countries[$selCountry])) { $cc = $countries[$selCountry]['calling_code']; }
$countries = localized_rows($countries);
$languages = localized_rows($languages);
?>
<?php if ($google !== null): ?>
<h1><?= te('عضویت با گوگل') ?></h1>
<p class="lead"><?= te('حساب گوگل شما تأیید شد. برای ساخت حساب، فقط شماره همراه را وارد کنید و کشور و زبان را بررسی کنید.') ?></p>
<div class="google-id" dir="ltr"><?= $googleIcon ?><span><?= e($google['email']) ?></span></div>
<?php if (isset($errors['google'])): ?><div class="alert alert-error" role="alert"><?= e($errors['google']) ?> <a href="/auth/google"><?= te('ورود با گوگل') ?></a></div><?php endif; ?>
<?php else: ?>
<h1><?= te('عضویت رایگان') ?></h1>
<p class="lead"><?= te('عضویت و ساخت صفحه تجاری رایگان است. کمتر از یک دقیقه طول می‌کشد.') ?></p>
<?php if (!empty($googleEnabled)): ?>
<a class="auth-google" href="/auth/google"><?= $googleIcon ?><span><?= te('ثبت‌نام با گوگل') ?></span></a>
<div class="auth-sep"><span><?= te('یا با ایمیل') ?></span></div>
<?php endif; ?>
<?php endif; ?>
<?php if (isset($errors['avatar'])): ?><div class="alert alert-error" role="alert"><?= e($errors['avatar']) ?></div><?php endif; ?>
<form class="form" method="post" action="<?= $google !== null ? '/register/google' : '/register' ?>" enctype="multipart/form-data" novalidate>
  <?= csrf_field() ?>
  <div class="row row-2">
    <?= $this->partial('partials/field', ['name' => 'first_name', 'label' => t('نام'), 'old' => $old, 'errors' => $errors, 'attrs' => ['autocomplete' => 'given-name', 'required' => true, 'maxlength' => 100]]) ?>
    <?= $this->partial('partials/field', ['name' => 'last_name', 'label' => t('نام خانوادگی'), 'old' => $old, 'errors' => $errors, 'attrs' => ['autocomplete' => 'family-name', 'required' => true, 'maxlength' => 100]]) ?>
  </div>

  <div class="row row-2">
    <div class="field<?= isset($errors['country_id']) ? ' has-error' : '' ?>">
      <label for="f-country_id"><?= te('کشور') ?></label>
      <select class="select" id="f-country_id" name="country_id" required data-calling-target="f-phone_cc">
        <option value=""><?= te('انتخاب کنید') ?></option>
        <?php foreach ($countries as $c): ?>
          <option value="<?= e($c['id']) ?>" data-cc="<?= e($c['calling_code']) ?>" data-search="<?= e($c['name_en'] . ' ' . $c['code'] . ' +' . $c['calling_code']) ?>"<?= $selCountry === $c['id'] ? ' selected' : '' ?>><?= e(flag($c['code']) . ' ' . $c['name_fa']) ?></option>
        <?php endforeach; ?>
      </select>
      <?php if (isset($errors['country_id'])): ?><div class="error"><?= e($errors['country_id']) ?></div><?php endif; ?>
    </div>
    <div class="field<?= isset($errors['language_id']) ? ' has-error' : '' ?>">
      <label for="f-language_id"><?= te('زبان') ?></label>
      <select class="select" id="f-language_id" name="language_id" required>
        <option value=""><?= te('انتخاب کنید') ?></option>
        <?php foreach ($languages as $l): ?>
          <option value="<?= e($l['id']) ?>" data-search="<?= e($l['name'] . ' ' . $l['code']) ?>"<?= $selLang === $l['id'] ? ' selected' : '' ?>><?= e($l['name_fa'] . ($l['name'] !== $l['name_fa'] ? ' · ' . $l['name'] : '')) ?></option>
        <?php endforeach; ?>
      </select>
      <?php if (isset($errors['language_id'])): ?><div class="error"><?= e($errors['language_id']) ?></div><?php endif; ?>
    </div>
  </div>
  <p class="hint muted"><?= te('صفحه‌های دیگران بر اساس کشور و زبان شما، به زبان مناسب نمایش داده می‌شوند.') ?></p>

  <div class="field<?= isset($errors['phone']) || isset($errors['phone_cc']) ? ' has-error' : '' ?>">
    <label for="f-phone"><?= te('شماره تلفن همراه') ?></label>
    <div class="prefix-input">
      <span>+</span>
      <input class="input cc-input" id="f-phone_cc" name="phone_cc" inputmode="numeric" maxlength="4" value="<?= e($cc) ?>" aria-label="<?= te('کد کشور') ?>" required>
      <input class="input" id="f-phone" name="phone" type="tel" inputmode="tel" autocomplete="tel-national" value="<?= e($old['phone'] ?? '') ?>" required>
    </div>
    <?php if (isset($errors['phone_cc'])): ?><div class="error"><?= e($errors['phone_cc']) ?></div><?php endif; ?>
    <?php if (isset($errors['phone'])): ?><div class="error"><?= e($errors['phone']) ?></div><?php endif; ?>
  </div>

  <?php if ($google === null): ?>
  <?= $this->partial('partials/field', ['name' => 'email', 'label' => t('ایمیل'), 'type' => 'email', 'old' => $old, 'errors' => $errors, 'attrs' => ['autocomplete' => 'email', 'required' => true, 'dir' => 'ltr', 'autocapitalize' => 'off', 'spellcheck' => 'false']]) ?>
  <div class="row row-2">
    <?= $this->partial('partials/field', ['name' => 'password', 'label' => t('رمز عبور'), 'type' => 'password', 'old' => [], 'errors' => $errors, 'hint' => t('حداقل :n نویسه', ['n' => fa_num(8)]), 'attrs' => ['autocomplete' => 'new-password', 'required' => true, 'minlength' => 8, 'dir' => 'ltr']]) ?>
    <?= $this->partial('partials/field', ['name' => 'password_confirmation', 'label' => t('تکرار رمز عبور'), 'type' => 'password', 'old' => [], 'errors' => $errors, 'attrs' => ['autocomplete' => 'new-password', 'required' => true, 'dir' => 'ltr']]) ?>
  </div>
  <?php endif; ?>

  <div class="field">
    <span class="label"><?= te('تصویر پروفایل') ?> <span class="muted"><?= te('(اختیاری)') ?></span></span>
    <div class="image-pick">
      <div class="ph" id="reg-avatar-preview"><svg class="icon"><use href="#i-user"/></svg></div>
      <input type="file" name="avatar" accept="image/jpeg,image/png,image/webp" data-preview="reg-avatar-preview" aria-label="<?= te('انتخاب تصویر پروفایل') ?>" data-max-kb="<?= upload_max_kb() ?>">
        <div class="hint upload-hint"><?= te('JPG، PNG یا WebP · حداکثر :n کیلوبایت', ['n' => fa_num(upload_max_kb())]) ?></div>
    </div>
  </div>

  <button class="btn btn-block" type="submit"><?= te('ساخت حساب') ?></button>
</form>
<?php if ($google !== null): ?>
<p class="switch"><?= te('حساب دیگری می‌خواهید؟') ?> <a href="/auth/google"><?= te('انتخاب حساب گوگل دیگر') ?></a> · <a href="/register"><?= te('عضویت با ایمیل') ?></a></p>
<?php else: ?>
<p class="switch"><?= te('قبلاً عضو شده‌اید؟') ?> <a href="/login"><?= te('ورود') ?></a></p>
<?php endif; ?>
