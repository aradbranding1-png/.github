<?php
/** @var array $google @var bool $googleEnabled @var bool $googleReset @var array $input @var array $user @var array $profile @var array $countries @var array $languages @var array $errors @var string $tab */
$tabs = ['profile' => t('پروفایل'), 'interests' => t('علاقه‌مندی‌ها'), 'security' => t('امنیت')];
$countries = localized_rows($countries ?? []);
$languages = localized_rows($languages ?? []);
$categories = localized_rows($categories ?? [], 'name_fa', false);
if (!isset($tabs[$tab])) { $tab = 'profile'; }
$old = ($input ?? []) + $user + $profile;
$avatarUrl = media($user['avatar_path'] ?? null);
?>
<nav class="tabs" aria-label="<?= te('بخش‌های حساب') ?>">
  <?php foreach ($tabs as $key => $label): ?>
    <a href="/account?tab=<?= e($key) ?>"<?= $tab === $key ? ' aria-current="page"' : '' ?>><?= te($label) ?></a>
  <?php endforeach; ?>
</nav>

<?php if ($tab === 'profile'): ?>
<form class="panel form" method="post" action="/account" enctype="multipart/form-data" novalidate>
  <?= csrf_field() ?>
  <div class="field<?= isset($errors['avatar']) ? ' has-error' : '' ?>">
    <span class="label"><?= te('تصویر پروفایل') ?></span>
    <div class="image-pick">
      <?php if ($avatarUrl): ?><img id="acc-avatar" src="<?= e($avatarUrl) ?>" alt=""><?php else: ?><div class="ph" id="acc-avatar"><svg class="icon"><use href="#i-user"/></svg></div><?php endif; ?>
      <div class="stack">
        <input type="file" name="avatar" accept="image/jpeg,image/png,image/webp" data-preview="acc-avatar" aria-label="<?= te('انتخاب تصویر پروفایل') ?>" data-max-kb="<?= upload_max_kb() ?>">
        <div class="hint upload-hint"><?= te('JPG، PNG یا WebP · حداکثر :n کیلوبایت', ['n' => fa_num(upload_max_kb())]) ?></div>
        <?php if ($avatarUrl): ?><label class="check"><input type="checkbox" name="remove_avatar" value="1"> <?= te('حذف تصویر فعلی') ?></label><?php endif; ?>
      </div>
    </div>
    <?php if (isset($errors['avatar'])): ?><div class="error"><?= e($errors['avatar']) ?></div><?php endif; ?>
  </div>

  <div class="row row-2">
    <?= $this->partial('partials/field', ['name' => 'first_name', 'label' => t('نام'), 'old' => $old, 'errors' => $errors, 'attrs' => ['required' => true, 'maxlength' => 100]]) ?>
    <?= $this->partial('partials/field', ['name' => 'last_name', 'label' => t('نام خانوادگی'), 'old' => $old, 'errors' => $errors, 'attrs' => ['required' => true, 'maxlength' => 100]]) ?>
  </div>
  <div class="row row-2">
    <div class="field<?= isset($errors['country_id']) ? ' has-error' : '' ?>">
      <label for="f-country_id"><?= te('کشور') ?></label>
      <select class="select" id="f-country_id" name="country_id" data-calling-target="f-phone_cc">
        <?php foreach ($countries as $c): ?>
          <option value="<?= e($c['id']) ?>" data-cc="<?= e($c['calling_code']) ?>" data-search="<?= e($c['name_en'] . ' ' . $c['code'] . ' +' . $c['calling_code']) ?>"<?= (int) $old['country_id'] === $c['id'] ? ' selected' : '' ?>><?= e(flag($c['code']) . ' ' . $c['name_fa']) ?></option>
        <?php endforeach; ?>
      </select>
      <?php if (isset($errors['country_id'])): ?><div class="error"><?= e($errors['country_id']) ?></div><?php endif; ?>
    </div>
    <div class="field<?= isset($errors['language_id']) ? ' has-error' : '' ?>">
      <label for="f-language_id"><?= te('زبان') ?></label>
      <select class="select" id="f-language_id" name="language_id">
        <?php foreach ($languages as $l): ?>
          <option value="<?= e($l['id']) ?>" data-search="<?= e($l['name'] . ' ' . $l['code']) ?>"<?= (int) $old['language_id'] === $l['id'] ? ' selected' : '' ?>><?= e($l['name_fa']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>
  <div class="field<?= isset($errors['phone']) || isset($errors['phone_cc']) ? ' has-error' : '' ?>">
    <label for="f-phone"><?= te('شماره تلفن همراه') ?></label>
    <div class="phone-group">
      <span class="pg-plus" aria-hidden="true">+</span>
      <input class="pg-cc" id="f-phone_cc" name="phone_cc" inputmode="numeric" maxlength="4" value="<?= e($old['phone_cc']) ?>" aria-label="<?= te('کد کشور') ?>" dir="ltr">
      <input class="pg-num" id="f-phone" name="phone" type="tel" inputmode="tel" value="<?= e($old['phone']) ?>" dir="ltr" aria-describedby="phone-note">
    </div>
    <div class="hint" id="phone-note"><?= te('شماره و ایمیل شما به هیچ تاجری نمایش داده نمی‌شود؛ فقط خودتان می‌توانید آن را در نامه بفرستید.') ?></div>
    <?php foreach (['phone_cc', 'phone'] as $k): if (isset($errors[$k])): ?><div class="error"><?= e($errors[$k]) ?></div><?php endif; endforeach; ?>
  </div>
  <div class="row row-2">
    <?= $this->partial('partials/field', ['name' => 'city', 'label' => t('شهر'), 'old' => $old, 'errors' => $errors, 'attrs' => ['maxlength' => 100]]) ?>
    <?= $this->partial('partials/field', ['name' => 'company_name', 'label' => t('نام شرکت'), 'old' => $old, 'errors' => $errors, 'attrs' => ['maxlength' => 200]]) ?>
  </div>
  <div class="field<?= isset($errors['trade_role']) ? ' has-error' : '' ?>">
    <span class="label"><?= te('نقش تجاری شما') ?></span>
    <div class="ff-chips">
      <?php $tr = (string) ($old['trade_role'] ?? '') ?: \App\Modules\Users\TradeRoles::DEFAULT; foreach (\App\Modules\Users\TradeRoles::TRADE as $k => $label): ?>
        <label class="ff-chip"><input type="radio" name="trade_role" value="<?= e($k) ?>"<?= $tr === $k ? ' checked' : '' ?>><span><?= te($label) ?></span></label>
      <?php endforeach; ?>
    </div>
    <div class="hint"><?= te('زیر نام شما در سامانه نمایش داده می‌شود.') ?></div>
    <?php if (isset($errors['trade_role'])): ?><div class="error"><?= e($errors['trade_role']) ?></div><?php endif; ?>
  </div>
  <?= $this->partial('partials/field', ['name' => 'business_area', 'label' => t('حوزه فعالیت'), 'old' => $old, 'errors' => $errors, 'hint' => t('مثال: زعفران، تجهیزات پزشکی، سنگ ساختمانی'), 'attrs' => ['maxlength' => 200]]) ?>
  <?= $this->partial('partials/field', ['name' => 'bio', 'label' => t('درباره من'), 'type' => 'textarea', 'old' => $old, 'errors' => $errors, 'attrs' => ['maxlength' => 2000]]) ?>
  <div class="field">
    <span class="label"><?= te('ایمیل') ?></span>
    <div class="ltr muted"><?= e($user['email']) ?></div>
  </div>
  <div class="form-actions"><button class="btn" type="submit"><?= te('ذخیره پروفایل') ?></button></div>
</form>

<?php elseif ($tab === 'interests'): ?>
<form class="panel form" method="post" action="/account/interests">
  <?= csrf_field() ?>
  <p class="muted"><?= te('تا ۵ دسته انتخاب کنید. فید «برای شما» پیشنهادهای این دسته‌ها را جلوتر نشان می‌دهد.') ?></p>
  <?php if (isset($errors['interests'])): ?><div class="alert alert-error" role="alert"><?= e($errors['interests']) ?></div><?php endif; ?>
  <div class="chip-picks">
    <?php foreach ($categories as $cat): ?>
      <label class="chip-pick"><input type="checkbox" name="interests[]" value="<?= e($cat['id']) ?>"<?= in_array($cat['id'], $interests, true) ? ' checked' : '' ?>><span><?= e($cat['name_fa']) ?></span></label>
    <?php endforeach; ?>
  </div>
  <div class="form-actions"><button class="btn" type="submit"><?= te('ذخیره علاقه‌مندی‌ها') ?></button></div>
</form>

<?php else: ?>
<?php $g = $google ?? []; $gLinked = !empty($g['linked']); ?>
<section class="panel form" id="google">
  <div>
    <h2><?= te('ورود با گوگل') ?></h2>
    <p class="muted"><?= te('جیمیل حساب گوگل خود را ثبت کنید تا با دکمه «ورود با گوگل» وارد شوید. اگر رمز عبور را فراموش کنید، با همین جیمیل وارد می‌شوید و رمز تازه می‌گذارید.') ?></p>
  </div>
  <?php if (empty($googleEnabled)): ?><p class="hint muted"><?= te('ورود با گوگل هنوز توسط مدیر سامانه فعال نشده است؛ می‌توانید جیمیل را از الان ثبت کنید.') ?></p><?php endif; ?>
  <?php if ($gLinked): ?>
    <div class="alert alert-success" role="status"><?= te('وصل به حساب گوگل :email', ['email' => (string) $g['google_email']]) ?></div>
    <form method="post" action="/account/google/unlink"><?= csrf_field() ?><div class="form-actions"><button class="btn btn-quiet" type="submit"><?= te('جدا کردن حساب گوگل') ?></button></div></form>
  <?php else: ?>
    <form method="post" action="/account/google" novalidate>
      <?= csrf_field() ?>
      <?= $this->partial('partials/field', ['name' => 'google_email', 'label' => t('جیمیل (آدرس حساب گوگل)'), 'type' => 'email', 'old' => ['google_email' => $input['google_email'] ?? ($g['google_email'] ?? '')], 'errors' => $errors, 'hint' => !empty($g['google_email']) ? t('ثبت شده؛ با اولین ورود با گوگل وصل می‌شود.') : t('مثلاً name@gmail.com'), 'attrs' => ['autocomplete' => 'email', 'dir' => 'ltr', 'placeholder' => 'name@gmail.com']]) ?>
      <div class="form-actions">
        <button class="btn" type="submit"><?= te('ذخیره جیمیل') ?></button>
        <?php if (!empty($googleEnabled)): ?><a class="btn btn-quiet" href="/auth/google"><?= te('همین حالا با گوگل وصل کن') ?></a><?php endif; ?>
      </div>
    </form>
  <?php endif; ?>
</section>

<form class="panel form" method="post" action="/account/password" novalidate>
  <?= csrf_field() ?>
  <?php if (!empty($googleReset)): ?>
  <div class="alert alert-success" role="status"><?= te('با گوگل وارد شده‌اید؛ برای گذاشتن رمز تازه، رمز فعلی لازم نیست.') ?></div>
  <?php else: ?>
  <?= $this->partial('partials/field', ['name' => 'current_password', 'label' => t('رمز عبور فعلی'), 'type' => 'password', 'old' => [], 'errors' => $errors, 'attrs' => ['autocomplete' => 'current-password', 'dir' => 'ltr']]) ?>
  <?php endif; ?>
  <div class="row row-2">
    <?= $this->partial('partials/field', ['name' => 'password', 'label' => t('رمز عبور جدید'), 'type' => 'password', 'old' => [], 'errors' => $errors, 'hint' => t('حداقل ۸ نویسه'), 'attrs' => ['autocomplete' => 'new-password', 'dir' => 'ltr']]) ?>
    <?= $this->partial('partials/field', ['name' => 'password_confirmation', 'label' => t('تکرار رمز عبور جدید'), 'type' => 'password', 'old' => [], 'errors' => $errors, 'attrs' => ['autocomplete' => 'new-password', 'dir' => 'ltr']]) ?>
  </div>
  <p class="hint muted"><?= te('پس از تغییر رمز، از همه دستگاه‌های دیگر خارج می‌شوید.') ?></p>
  <div class="form-actions"><button class="btn" type="submit"><?= te('تغییر رمز عبور') ?></button></div>
</form>
<?php endif; ?>
