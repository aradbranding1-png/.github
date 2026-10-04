<?php foreach (['countries', 'languages', 'categories'] as $__ref) { if (isset($$__ref) && is_array($$__ref)) { $$__ref = localized_rows($$__ref, 'name_fa', $__ref !== 'categories'); } } unset($__ref); ?>
<?php
/** @var array $user @var array|null $page @var array $old @var array $errors @var array $languages @var array $usedLanguages */
$editing = $page !== null;
$action = $editing ? '/pages/' . $page['uid'] : '/pages';
$dir = $editing ? $page['direction'] : 'auto';
$coverUrl = $editing ? media($page['cover_path']) : null;
$avatarUrl = $editing ? media($page['avatar_path']) : null;
$f = fn (string $name, string $label, array $extra = []) => $this->partial('partials/field', $extra + [
    'name' => $name, 'label' => $label, 'old' => $old, 'errors' => $errors,
]);
$generalErrors = array_intersect_key($errors, array_flip(['cover', 'avatar']));
?>
<form class="stack" method="post" action="<?= e($action) ?>" enctype="multipart/form-data" novalidate>
  <?= csrf_field() ?>
  <?php foreach ($generalErrors as $msg): ?><div class="alert alert-error" role="alert"><?= e($msg) ?></div><?php endforeach; ?>

  <section class="panel form">
    <div class="panel-head"><h2><?= $editing ? te('ویرایش صفحه :lang', ['lang' => $page['lang_name']]) : t('صفحه جدید') ?></h2>
      <?php if ($editing && $user['handle']): ?>
        <a class="btn btn-ghost btn-sm" href="/p/<?= e($user['handle']) ?>/<?= e($page['lang_code']) ?>" target="_blank" rel="noopener"><svg class="icon"><use href="#i-link"/></svg><?= te('مشاهده صفحه') ?></a>
      <?php endif; ?>
    </div>

    <?php if (!$editing && $user['handle'] === null): ?>
      <div class="field<?= isset($errors['handle']) ? ' has-error' : '' ?>">
        <label for="f-handle"><?= te('نشانی صفحه شما') ?></label>
        <div class="join-ctl">
          <span class="jc-pre">aradbranding.app/p/</span>
          <input class="jc-in" id="f-handle" name="handle" value="<?= e($old['handle'] ?? '') ?>" required minlength="3" maxlength="32"
                 pattern="[a-z0-9][a-z0-9\-]{1,30}[a-z0-9]" autocapitalize="off" autocomplete="off" spellcheck="false" dir="ltr" aria-describedby="f-handle-hint">
        </div>
        <div class="hint" id="f-handle-hint"><?= te('فقط یک بار انتخاب می‌شود. حروف کوچک انگلیسی، عدد و خط تیره؛ مثلاً aradtrade') ?></div>
        <?php if (isset($errors['handle'])): ?><div class="error"><?= e($errors['handle']) ?></div><?php endif; ?>
      </div>
    <?php endif; ?>

    <?php if (!$editing): ?>
      <div class="field<?= isset($errors['language_id']) ? ' has-error' : '' ?>">
        <label for="f-language_id"><?= te('زبان این صفحه') ?></label>
        <select class="select" id="f-language_id" name="language_id" required>
          <option value=""><?= te('انتخاب کنید') ?></option>
          <?php foreach ($languages as $l): $taken = in_array($l['id'], $usedLanguages, true); ?>
            <option value="<?= e($l['id']) ?>" data-search="<?= e($l['name'] . ' ' . $l['code']) ?>"<?= (int) ($old['language_id'] ?? 0) === $l['id'] ? ' selected' : '' ?><?= $taken ? ' disabled' : '' ?>>
              <?= e($l['name_fa'] . ($l['name'] !== $l['name_fa'] ? ' · ' . $l['name'] : '') . ($taken ? t(' (ساخته شده)') : '')) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <div class="hint"><?= te('متن‌های این صفحه را به همین زبان بنویسید. برای هر زبان یک صفحه جدا می‌سازید.') ?></div>
        <?php if (isset($errors['language_id'])): ?><div class="error"><?= e($errors['language_id']) ?></div><?php endif; ?>
      </div>
    <?php endif; ?>

    <?= $f('title', t('عنوان صفحه'), ['hint' => t('نام تجاری یا عنوانی که تجار با آن شما را می‌شناسند'), 'attrs' => ['required' => true, 'maxlength' => 200, 'dir' => $dir]]) ?>
    <?= $f('company_name', t('نام شرکت'), ['attrs' => ['maxlength' => 200, 'dir' => $dir]]) ?>
    <?= $f('teaser', t('معرفی کوتاه'), ['type' => 'textarea', 'hint' => t('برای همه و در گوگل دیده می‌شود. شماره تماس، ایمیل، آیدی و نشانی سایت را اینجا ننویسید. برای پررنگ‌کردن: **متن**'), 'attrs' => ['required' => true, 'maxlength' => 500, 'rows' => 3, 'dir' => $dir]]) ?>
  </section>

  <section class="panel form">
    <h2><?= te('تصاویر') ?></h2>
    <div class="field">
      <span class="label"><?= te('تصویر کاور') ?> <span class="muted"><?= te('(۱۶۰۰ × ۶۰۰)') ?></span></span>
      <div class="image-pick cover">
        <?php if ($coverUrl): ?><img id="pg-cover" src="<?= e($coverUrl) ?>" alt=""><?php else: ?><div class="ph" id="pg-cover"><svg class="icon"><use href="#i-page"/></svg></div><?php endif; ?>
        <div class="stack">
          <input type="file" name="cover" accept="image/jpeg,image/png,image/webp" data-preview="pg-cover" aria-label="<?= te('انتخاب تصویر کاور') ?>" data-max-kb="<?= upload_max_kb() ?>">
        <div class="hint upload-hint"><?= te('JPG، PNG یا WebP · حداکثر :n کیلوبایت', ['n' => fa_num(upload_max_kb())]) ?></div>
          <?php if ($coverUrl): ?><label class="check"><input type="checkbox" name="remove_cover" value="1"> <?= te('حذف کاور') ?></label><?php endif; ?>
        </div>
      </div>
    </div>
    <div class="field">
      <span class="label"><?= te('لوگو') ?></span>
      <div class="image-pick">
        <?php if ($avatarUrl): ?><img id="pg-avatar" src="<?= e($avatarUrl) ?>" alt=""><?php else: ?><div class="ph" id="pg-avatar"><svg class="icon"><use href="#i-mark"/></svg></div><?php endif; ?>
        <div class="stack">
          <input type="file" name="avatar" accept="image/jpeg,image/png,image/webp" data-preview="pg-avatar" aria-label="<?= te('انتخاب لوگو') ?>" data-max-kb="<?= upload_max_kb() ?>">
        <div class="hint upload-hint"><?= te('JPG، PNG یا WebP · حداکثر :n کیلوبایت', ['n' => fa_num(upload_max_kb())]) ?></div>
          <?php if ($avatarUrl): ?><label class="check"><input type="checkbox" name="remove_avatar" value="1"> <?= te('حذف لوگو') ?></label><?php endif; ?>
        </div>
      </div>
    </div>
  </section>

  <section class="panel form">
    <div>
      <h2><?= te('اطلاعات کامل') ?></h2>
      <p class="muted"><?= te('این بخش فقط برای تجار واردشده به سامانه نمایش داده می‌شود.') ?></p>
    </div>
    <?= $f('about', t('درباره ما'), ['type' => 'textarea', 'attrs' => ['maxlength' => 5000, 'rows' => 6, 'dir' => $dir]]) ?>
    <div class="grid-2">
      <?= $f('products', t('محصولات'), ['type' => 'textarea', 'hint' => t('هر محصول در یک خط'), 'attrs' => ['rows' => 5, 'dir' => $dir]]) ?>
      <?= $f('services', t('خدمات'), ['type' => 'textarea', 'hint' => t('هر خدمت در یک خط'), 'attrs' => ['rows' => 5, 'dir' => $dir]]) ?>
    </div>
    <?= $f('markets', t('بازارهای هدف'), ['type' => 'textarea', 'hint' => t('هر کشور یا منطقه در یک خط'), 'attrs' => ['rows' => 3, 'dir' => $dir]]) ?>
  </section>

  <section class="panel form">
    <h2><?= te('ارتباط با شما') ?></h2>
    <p class="muted"><?= te('تجار از داخل سامانه با شما در ارتباط هستند: با «ارسال نامه» و «ارسال پیشنهاد تجاری» در همین صفحه. شماره تماس، ایمیل، واتساپ، تلگرام، اینستاگرام و دیگر شبکه‌های اجتماعی یا وب‌سایت در صفحه پذیرفته نمی‌شود؛ این اطلاعات را در نامه خصوصی برای تاجر مورد نظر بفرستید.') ?></p>
  </section>

  <section class="panel form">
    <label class="check"><input type="checkbox" name="publish" value="1"<?= !empty($old['publish']) || !$editing ? ' checked' : '' ?>> <?= te('صفحه منتشر شود و برای دیگران قابل مشاهده باشد') ?></label>
    <div class="form-actions">
      <button class="btn" type="submit"><?= $editing ? t('ذخیره تغییرات') : t('ساخت صفحه') ?></button>
      <a class="btn btn-quiet" href="/pages"><?= te('انصراف') ?></a>
    </div>
  </section>
</form>


