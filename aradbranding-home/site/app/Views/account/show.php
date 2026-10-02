<?php
/** @var array $input @var array $user @var array $profile @var array $countries @var array $languages @var array $errors @var string $tab */
$tabs = ['profile' => 'پروفایل', 'interests' => 'علاقه‌مندی‌ها', 'security' => 'امنیت'];
if (!isset($tabs[$tab])) { $tab = 'profile'; }
$old = ($input ?? []) + $user + $profile;
$avatarUrl = media($user['avatar_path'] ?? null);
?>
<nav class="tabs" aria-label="بخش‌های حساب">
  <?php foreach ($tabs as $key => $label): ?>
    <a href="/account?tab=<?= e($key) ?>"<?= $tab === $key ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
  <?php endforeach; ?>
</nav>

<?php if ($tab === 'profile'): ?>
<form class="panel form" method="post" action="/account" enctype="multipart/form-data" novalidate>
  <?= csrf_field() ?>
  <div class="field<?= isset($errors['avatar']) ? ' has-error' : '' ?>">
    <span class="label">تصویر پروفایل</span>
    <div class="image-pick">
      <?php if ($avatarUrl): ?><img id="acc-avatar" src="<?= e($avatarUrl) ?>" alt=""><?php else: ?><div class="ph" id="acc-avatar"><svg class="icon"><use href="#i-user"/></svg></div><?php endif; ?>
      <div class="stack">
        <input type="file" name="avatar" accept="image/jpeg,image/png,image/webp" data-preview="acc-avatar" aria-label="انتخاب تصویر پروفایل" data-max-kb="<?= upload_max_kb() ?>">
        <div class="hint upload-hint">JPG، PNG یا WebP · حداکثر <?= fa_num(upload_max_kb()) ?> کیلوبایت</div>
        <?php if ($avatarUrl): ?><label class="check"><input type="checkbox" name="remove_avatar" value="1"> حذف تصویر فعلی</label><?php endif; ?>
      </div>
    </div>
    <?php if (isset($errors['avatar'])): ?><div class="error"><?= e($errors['avatar']) ?></div><?php endif; ?>
  </div>

  <div class="row row-2">
    <?= $this->partial('partials/field', ['name' => 'first_name', 'label' => 'نام', 'old' => $old, 'errors' => $errors, 'attrs' => ['required' => true, 'maxlength' => 100]]) ?>
    <?= $this->partial('partials/field', ['name' => 'last_name', 'label' => 'نام خانوادگی', 'old' => $old, 'errors' => $errors, 'attrs' => ['required' => true, 'maxlength' => 100]]) ?>
  </div>
  <div class="row row-2">
    <div class="field<?= isset($errors['country_id']) ? ' has-error' : '' ?>">
      <label for="f-country_id">کشور</label>
      <select class="select" id="f-country_id" name="country_id" data-calling-target="f-phone_cc">
        <?php foreach ($countries as $c): ?>
          <option value="<?= e($c['id']) ?>" data-cc="<?= e($c['calling_code']) ?>" data-search="<?= e($c['name_en'] . ' ' . $c['code'] . ' +' . $c['calling_code']) ?>"<?= (int) $old['country_id'] === $c['id'] ? ' selected' : '' ?>><?= e(flag($c['code']) . ' ' . $c['name_fa']) ?></option>
        <?php endforeach; ?>
      </select>
      <?php if (isset($errors['country_id'])): ?><div class="error"><?= e($errors['country_id']) ?></div><?php endif; ?>
    </div>
    <div class="field<?= isset($errors['language_id']) ? ' has-error' : '' ?>">
      <label for="f-language_id">زبان</label>
      <select class="select" id="f-language_id" name="language_id">
        <?php foreach ($languages as $l): ?>
          <option value="<?= e($l['id']) ?>" data-search="<?= e($l['name'] . ' ' . $l['code']) ?>"<?= (int) $old['language_id'] === $l['id'] ? ' selected' : '' ?>><?= e($l['name_fa']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>
  <div class="field<?= isset($errors['phone']) || isset($errors['phone_cc']) ? ' has-error' : '' ?>">
    <label for="f-phone">شماره تلفن همراه</label>
    <div class="phone-group">
      <span class="pg-plus" aria-hidden="true">+</span>
      <input class="pg-cc" id="f-phone_cc" name="phone_cc" inputmode="numeric" maxlength="4" value="<?= e($old['phone_cc']) ?>" aria-label="کد کشور" dir="ltr">
      <input class="pg-num" id="f-phone" name="phone" type="tel" inputmode="tel" value="<?= e($old['phone']) ?>" dir="ltr" aria-describedby="phone-note">
    </div>
    <div class="hint" id="phone-note">شماره و ایمیل شما به هیچ تاجری نمایش داده نمی‌شود؛ فقط خودتان می‌توانید آن را در نامه بفرستید.</div>
    <?php foreach (['phone_cc', 'phone'] as $k): if (isset($errors[$k])): ?><div class="error"><?= e($errors[$k]) ?></div><?php endif; endforeach; ?>
  </div>
  <div class="row row-2">
    <?= $this->partial('partials/field', ['name' => 'city', 'label' => 'شهر', 'old' => $old, 'errors' => $errors, 'attrs' => ['maxlength' => 100]]) ?>
    <?= $this->partial('partials/field', ['name' => 'company_name', 'label' => 'نام شرکت', 'old' => $old, 'errors' => $errors, 'attrs' => ['maxlength' => 200]]) ?>
  </div>
  <?= $this->partial('partials/field', ['name' => 'business_area', 'label' => 'حوزه فعالیت', 'old' => $old, 'errors' => $errors, 'hint' => 'مثال: زعفران، تجهیزات پزشکی، سنگ ساختمانی', 'attrs' => ['maxlength' => 200]]) ?>
  <?= $this->partial('partials/field', ['name' => 'bio', 'label' => 'درباره من', 'type' => 'textarea', 'old' => $old, 'errors' => $errors, 'attrs' => ['maxlength' => 2000]]) ?>
  <div class="field">
    <span class="label">ایمیل</span>
    <div class="ltr muted"><?= e($user['email']) ?></div>
  </div>
  <div class="form-actions"><button class="btn" type="submit">ذخیره پروفایل</button></div>
</form>

<?php elseif ($tab === 'interests'): ?>
<form class="panel form" method="post" action="/account/interests">
  <?= csrf_field() ?>
  <p class="muted">تا ۵ دسته انتخاب کنید. فید «برای شما» پیشنهادهای این دسته‌ها را جلوتر نشان می‌دهد.</p>
  <?php if (isset($errors['interests'])): ?><div class="alert alert-error" role="alert"><?= e($errors['interests']) ?></div><?php endif; ?>
  <div class="chip-picks">
    <?php foreach ($categories as $cat): ?>
      <label class="chip-pick"><input type="checkbox" name="interests[]" value="<?= e($cat['id']) ?>"<?= in_array($cat['id'], $interests, true) ? ' checked' : '' ?>><span><?= e($cat['name_fa']) ?></span></label>
    <?php endforeach; ?>
  </div>
  <div class="form-actions"><button class="btn" type="submit">ذخیره علاقه‌مندی‌ها</button></div>
</form>

<?php else: ?>
<form class="panel form" method="post" action="/account/password" novalidate>
  <?= csrf_field() ?>
  <?= $this->partial('partials/field', ['name' => 'current_password', 'label' => 'رمز عبور فعلی', 'type' => 'password', 'old' => [], 'errors' => $errors, 'attrs' => ['autocomplete' => 'current-password', 'dir' => 'ltr']]) ?>
  <div class="row row-2">
    <?= $this->partial('partials/field', ['name' => 'password', 'label' => 'رمز عبور جدید', 'type' => 'password', 'old' => [], 'errors' => $errors, 'hint' => 'حداقل ۸ نویسه', 'attrs' => ['autocomplete' => 'new-password', 'dir' => 'ltr']]) ?>
    <?= $this->partial('partials/field', ['name' => 'password_confirmation', 'label' => 'تکرار رمز عبور جدید', 'type' => 'password', 'old' => [], 'errors' => $errors, 'attrs' => ['autocomplete' => 'new-password', 'dir' => 'ltr']]) ?>
  </div>
  <p class="hint muted">پس از تغییر رمز، از همه دستگاه‌های دیگر خارج می‌شوید.</p>
  <div class="form-actions"><button class="btn" type="submit">تغییر رمز عبور</button></div>
</form>
<?php endif; ?>
