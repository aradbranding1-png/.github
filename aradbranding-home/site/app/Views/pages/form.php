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
    <div class="panel-head"><h2><?= $editing ? 'ویرایش صفحه ' . e($page['lang_name']) : 'صفحه جدید' ?></h2>
      <?php if ($editing && $user['handle']): ?>
        <a class="btn btn-ghost btn-sm" href="/p/<?= e($user['handle']) ?>/<?= e($page['lang_code']) ?>" target="_blank" rel="noopener"><svg class="icon"><use href="#i-link"/></svg>مشاهده صفحه</a>
      <?php endif; ?>
    </div>

    <?php if (!$editing && $user['handle'] === null): ?>
      <div class="field<?= isset($errors['handle']) ? ' has-error' : '' ?>">
        <label for="f-handle">نشانی صفحه شما</label>
        <div class="prefix-input">
          <span>aradbranding.app/p/</span>
          <input class="input" id="f-handle" name="handle" value="<?= e($old['handle'] ?? '') ?>" required minlength="3" maxlength="32"
                 pattern="[a-z0-9][a-z0-9\-]{1,30}[a-z0-9]" autocapitalize="off" spellcheck="false" aria-describedby="f-handle-hint">
        </div>
        <div class="hint" id="f-handle-hint">فقط یک بار انتخاب می‌شود. حروف کوچک انگلیسی، عدد و خط تیره؛ مثلاً aradtrade</div>
        <?php if (isset($errors['handle'])): ?><div class="error"><?= e($errors['handle']) ?></div><?php endif; ?>
      </div>
    <?php endif; ?>

    <?php if (!$editing): ?>
      <div class="field<?= isset($errors['language_id']) ? ' has-error' : '' ?>">
        <label for="f-language_id">زبان این صفحه</label>
        <select class="select" id="f-language_id" name="language_id" required>
          <option value="">انتخاب کنید</option>
          <?php foreach ($languages as $l): $taken = in_array($l['id'], $usedLanguages, true); ?>
            <option value="<?= e($l['id']) ?>" data-search="<?= e($l['name'] . ' ' . $l['code']) ?>"<?= (int) ($old['language_id'] ?? 0) === $l['id'] ? ' selected' : '' ?><?= $taken ? ' disabled' : '' ?>>
              <?= e($l['name_fa'] . ($l['name'] !== $l['name_fa'] ? ' · ' . $l['name'] : '') . ($taken ? ' (ساخته شده)' : '')) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <div class="hint">متن‌های این صفحه را به همین زبان بنویسید. برای هر زبان یک صفحه جدا می‌سازید.</div>
        <?php if (isset($errors['language_id'])): ?><div class="error"><?= e($errors['language_id']) ?></div><?php endif; ?>
      </div>
    <?php endif; ?>

    <?= $f('title', 'عنوان صفحه', ['hint' => 'نام تجاری یا عنوانی که تجار با آن شما را می‌شناسند', 'attrs' => ['required' => true, 'maxlength' => 200, 'dir' => $dir]]) ?>
    <?= $f('company_name', 'نام شرکت', ['attrs' => ['maxlength' => 200, 'dir' => $dir]]) ?>
    <?= $f('teaser', 'معرفی کوتاه', ['type' => 'textarea', 'hint' => 'برای همه و در گوگل دیده می‌شود. شماره تماس، ایمیل، آیدی و نشانی سایت را اینجا ننویسید. برای پررنگ‌کردن: **متن**', 'attrs' => ['required' => true, 'maxlength' => 500, 'rows' => 3, 'dir' => $dir]]) ?>
  </section>

  <section class="panel form">
    <h2>تصاویر</h2>
    <div class="field">
      <span class="label">تصویر کاور <span class="muted">(۱۶۰۰ × ۶۰۰)</span></span>
      <div class="image-pick cover">
        <?php if ($coverUrl): ?><img id="pg-cover" src="<?= e($coverUrl) ?>" alt=""><?php else: ?><div class="ph" id="pg-cover"><svg class="icon"><use href="#i-page"/></svg></div><?php endif; ?>
        <div class="stack">
          <input type="file" name="cover" accept="image/jpeg,image/png,image/webp" data-preview="pg-cover" aria-label="انتخاب تصویر کاور" data-max-kb="<?= upload_max_kb() ?>">
        <div class="hint upload-hint">JPG، PNG یا WebP · حداکثر <?= fa_num(upload_max_kb()) ?> کیلوبایت</div>
          <?php if ($coverUrl): ?><label class="check"><input type="checkbox" name="remove_cover" value="1"> حذف کاور</label><?php endif; ?>
        </div>
      </div>
    </div>
    <div class="field">
      <span class="label">لوگو</span>
      <div class="image-pick">
        <?php if ($avatarUrl): ?><img id="pg-avatar" src="<?= e($avatarUrl) ?>" alt=""><?php else: ?><div class="ph" id="pg-avatar"><svg class="icon"><use href="#i-mark"/></svg></div><?php endif; ?>
        <div class="stack">
          <input type="file" name="avatar" accept="image/jpeg,image/png,image/webp" data-preview="pg-avatar" aria-label="انتخاب لوگو" data-max-kb="<?= upload_max_kb() ?>">
        <div class="hint upload-hint">JPG، PNG یا WebP · حداکثر <?= fa_num(upload_max_kb()) ?> کیلوبایت</div>
          <?php if ($avatarUrl): ?><label class="check"><input type="checkbox" name="remove_avatar" value="1"> حذف لوگو</label><?php endif; ?>
        </div>
      </div>
    </div>
  </section>

  <section class="panel form">
    <div>
      <h2>اطلاعات کامل</h2>
      <p class="muted">این بخش فقط برای تجار واردشده به سامانه نمایش داده می‌شود.</p>
    </div>
    <?= $f('about', 'درباره ما', ['type' => 'textarea', 'attrs' => ['maxlength' => 5000, 'rows' => 6, 'dir' => $dir]]) ?>
    <div class="grid-2">
      <?= $f('products', 'محصولات', ['type' => 'textarea', 'hint' => 'هر محصول در یک خط', 'attrs' => ['rows' => 5, 'dir' => $dir]]) ?>
      <?= $f('services', 'خدمات', ['type' => 'textarea', 'hint' => 'هر خدمت در یک خط', 'attrs' => ['rows' => 5, 'dir' => $dir]]) ?>
    </div>
    <?= $f('markets', 'بازارهای هدف', ['type' => 'textarea', 'hint' => 'هر کشور یا منطقه در یک خط', 'attrs' => ['rows' => 3, 'dir' => $dir]]) ?>
  </section>

  <section class="panel form">
    <h2>ارتباط با شما</h2>
    <p class="muted">تجار از داخل سامانه با شما در ارتباط هستند: با «ارسال نامه» و «ارسال پیشنهاد تجاری» در همین صفحه. شماره تماس، ایمیل، واتساپ، تلگرام، اینستاگرام و دیگر شبکه‌های اجتماعی یا وب‌سایت در صفحه پذیرفته نمی‌شود؛ این اطلاعات را در نامه خصوصی برای تاجر مورد نظر بفرستید.</p>
  </section>

  <section class="panel form">
    <label class="check"><input type="checkbox" name="publish" value="1"<?= !empty($old['publish']) || !$editing ? ' checked' : '' ?>> صفحه منتشر شود و برای دیگران قابل مشاهده باشد</label>
    <div class="form-actions">
      <button class="btn" type="submit"><?= $editing ? 'ذخیره تغییرات' : 'ساخت صفحه' ?></button>
      <a class="btn btn-quiet" href="/pages">انصراف</a>
    </div>
  </section>
</form>

<?php if ($editing): ?>
<form class="panel" method="post" action="/pages/<?= e($page['uid']) ?>/delete" data-confirm="این صفحه حذف شود؟ قوانین نمایش مربوط به آن هم حذف می‌شوند.">
  <?= csrf_field() ?>
  <div class="panel-head">
    <div><h3>حذف صفحه</h3><p class="muted">صفحه از دسترس خارج می‌شود و می‌توانید برای همین زبان صفحه جدید بسازید.</p></div>
    <button class="btn btn-danger" type="submit">حذف این صفحه</button>
  </div>
</form>
<?php endif; ?>
