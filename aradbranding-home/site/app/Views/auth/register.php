<?php /** @var array $countries @var array $languages @var array $old @var array $errors */
$selCountry = (int) ($old['country_id'] ?? 0);
$selLang = (int) ($old['language_id'] ?? 0);
$cc = $old['phone_cc'] ?? '';
if ($cc === '' && $selCountry && isset($countries[$selCountry])) { $cc = $countries[$selCountry]['calling_code']; }
$countries = localized_rows($countries);
$languages = localized_rows($languages);
?>
<h1><?= te('عضویت رایگان') ?></h1>
<p class="lead"><?= te('عضویت و ساخت صفحه تجاری رایگان است. کمتر از یک دقیقه طول می‌کشد.') ?></p>
<?php if (isset($errors['avatar'])): ?><div class="alert alert-error" role="alert"><?= e($errors['avatar']) ?></div><?php endif; ?>
<form class="form" method="post" action="/register" enctype="multipart/form-data" novalidate>
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

  <?= $this->partial('partials/field', ['name' => 'email', 'label' => t('ایمیل'), 'type' => 'email', 'old' => $old, 'errors' => $errors, 'attrs' => ['autocomplete' => 'email', 'required' => true, 'dir' => 'ltr', 'autocapitalize' => 'off', 'spellcheck' => 'false']]) ?>
  <div class="row row-2">
    <?= $this->partial('partials/field', ['name' => 'password', 'label' => t('رمز عبور'), 'type' => 'password', 'old' => [], 'errors' => $errors, 'hint' => t('حداقل :n نویسه', ['n' => fa_num(8)]), 'attrs' => ['autocomplete' => 'new-password', 'required' => true, 'minlength' => 8, 'dir' => 'ltr']]) ?>
    <?= $this->partial('partials/field', ['name' => 'password_confirmation', 'label' => t('تکرار رمز عبور'), 'type' => 'password', 'old' => [], 'errors' => $errors, 'attrs' => ['autocomplete' => 'new-password', 'required' => true, 'dir' => 'ltr']]) ?>
  </div>

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
<p class="switch"><?= te('قبلاً عضو شده‌اید؟') ?> <a href="/login"><?= te('ورود') ?></a></p>
