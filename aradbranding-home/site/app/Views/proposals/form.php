<?php
/** @var array|null $p @var array $old @var array $errors @var array $categories @var int $fee */
use App\Modules\Proposals\ProposalService;
$editing = $p !== null;
$action = $editing ? '/proposals/' . $p['uid'] : '/proposals';
$cover = $editing ? media($p['cover_path']) : null;
$gallery = $editing ? (json_decode((string) ($p['images'] ?? '[]'), true) ?: []) : [];
$f = fn (string $name, string $label, array $extra = []) => $this->partial('partials/field', $extra + ['name' => $name, 'label' => $label, 'old' => $old, 'errors' => $errors]);
?>
<form class="stack" method="post" action="<?= e($action) ?>" enctype="multipart/form-data" novalidate>
  <?= csrf_field() ?>
  <?php if (isset($errors['images'])): ?><div class="alert alert-error" role="alert"><?= e($errors['images']) ?></div><?php endif; ?>

  <section class="panel form">
    <h2><?= $editing ? 'ویرایش پیشنهاد' : 'پیشنهاد تجاری جدید' ?></h2>
    <div class="field<?= isset($errors['type']) ? ' has-error' : '' ?>">
      <span class="label">نوع پیشنهاد</span>
      <div class="chip-picks">
        <?php foreach (ProposalService::TYPES as $id => $label): ?>
          <label class="chip-pick"><input type="radio" name="type" value="<?= $id ?>"<?= (int) ($old['type'] ?? 0) === $id ? ' checked' : '' ?>><span><?= e($label) ?></span></label>
        <?php endforeach; ?>
      </div>
      <?php if (isset($errors['type'])): ?><div class="error"><?= e($errors['type']) ?></div><?php endif; ?>
    </div>
    <div class="field<?= isset($errors['category_id']) ? ' has-error' : '' ?>">
      <label for="f-category_id">دسته</label>
      <select class="select" id="f-category_id" name="category_id">
        <option value="">انتخاب کنید</option>
        <?php foreach ($categories as $c): ?><option value="<?= e($c['id']) ?>" data-search="<?= e($c['name_en'] ?? '') ?>"<?= (int) ($old['category_id'] ?? 0) === $c['id'] ? ' selected' : '' ?>><?= e($c['name_fa']) ?></option><?php endforeach; ?>
      </select>
      <?php if (isset($errors['category_id'])): ?><div class="error"><?= e($errors['category_id']) ?></div><?php endif; ?>
    </div>
    <?= $f('title', 'عنوان', ['hint' => 'کوتاه و روشن؛ مثلاً «عرضه پسته اکبری صادراتی»', 'attrs' => ['required' => true, 'maxlength' => 120]]) ?>
    <?= $f('summary', 'خلاصه', ['type' => 'textarea', 'hint' => 'در کارت فید دیده می‌شود. حداکثر ۳۰۰ نویسه.', 'attrs' => ['required' => true, 'maxlength' => 300, 'rows' => 3]]) ?>
  </section>

  <section class="panel form">
    <h2>تصاویر</h2>
    <div class="field">
      <span class="label">تصویر اصلی <span class="muted">(در فید نمایش داده می‌شود · نسبت ۴:۳)</span></span>
      <div class="image-pick cover">
        <?php if ($cover): ?><img id="pp-cover" src="<?= e($cover) ?>" alt=""><?php else: ?><div class="ph" id="pp-cover"><svg class="icon"><use href="#i-spark"/></svg></div><?php endif; ?>
        <div class="stack">
          <input type="file" name="cover" accept="image/jpeg,image/png,image/webp" data-preview="pp-cover" data-max-kb="<?= upload_max_kb() ?>" aria-label="تصویر اصلی">
          <div class="hint upload-hint">JPG، PNG یا WebP · حداکثر <?= fa_num(upload_max_kb()) ?> کیلوبایت</div>
          <?php if ($cover): ?><label class="check"><input type="checkbox" name="remove_cover" value="1"> حذف تصویر اصلی</label><?php endif; ?>
        </div>
      </div>
    </div>
    <div class="field">
      <label for="f-gallery">تصاویر بیشتر <span class="muted">(حداکثر ۴)</span></label>
      <?php if ($gallery): ?><div class="thumbs"><?php foreach ($gallery as $g): ?><img src="<?= e(media($g)) ?>" alt="" loading="lazy"><?php endforeach; ?></div>
        <label class="check"><input type="checkbox" name="remove_gallery" value="1"> حذف همه تصاویر بیشتر</label><?php endif; ?>
      <input id="f-gallery" type="file" name="gallery[]" multiple accept="image/jpeg,image/png,image/webp" data-max-kb="<?= upload_max_kb() ?>" data-max-files="4">
    </div>
  </section>

  <section class="panel form">
    <div><h2>جزئیات</h2><p class="muted">شماره تماس، ایمیل، آیدی و نشانی سایت در پیشنهاد پذیرفته نمی‌شود؛ تجار با دکمه «با من ارتباط بگیرید» برای شما نامه می‌فرستند.</p></div>
    <?= $f('body', 'توضیحات', ['type' => 'textarea', 'attrs' => ['maxlength' => 5000, 'rows' => 6]]) ?>
    <div class="row row-2">
      <?= $f('product', 'محصول یا خدمت', ['attrs' => ['maxlength' => 200]]) ?>
      <?= $f('quantity', 'مقدار', ['hint' => 'مثلاً ۲۰ تن در ماه', 'attrs' => ['maxlength' => 100]]) ?>
    </div>
    <?= $f('target_markets', 'بازار هدف', ['attrs' => ['maxlength' => 500]]) ?>
    <?= $f('terms', 'شرایط', ['type' => 'textarea', 'hint' => 'پرداخت، تحویل، بسته‌بندی، استانداردها…', 'attrs' => ['maxlength' => 3000, 'rows' => 4]]) ?>
    <?= $f('tags_text', 'برچسب‌ها', ['hint' => 'تا ۵ برچسب، با ویرگول جدا کنید', 'attrs' => ['maxlength' => 200]]) ?>
  </section>

  <section class="panel form">
    <?php if (!$editing): ?>
      <label class="check"><input type="checkbox" name="publish" value="1" checked> همین حالا در فید منتشر شود</label>
      <?php if ($fee > 0): ?><p class="fee-note">⭐ انتشار هر پیشنهاد در فید <?= fa_int($fee) ?> Star هزینه دارد و فقط یک بار، هنگام اولین انتشار، کسر می‌شود.</p><?php endif; ?>
    <?php endif; ?>
    <div class="form-actions">
      <button class="btn" type="submit"><?= $editing ? 'ذخیره تغییرات' : 'ذخیره پیشنهاد' ?></button>
      <a class="btn btn-quiet" href="/proposals/mine">انصراف</a>
    </div>
  </section>
</form>

<?php if ($editing): ?>
<form class="panel" method="post" action="/proposals/<?= e($p['uid']) ?>/delete" data-confirm="این پیشنهاد حذف شود؟">
  <?= csrf_field() ?>
  <div class="panel-head"><div><h3>حذف پیشنهاد</h3><p class="muted">پیشنهاد از فید و فهرست شما حذف می‌شود.</p></div><button class="btn btn-danger" type="submit">حذف</button></div>
</form>
<?php endif; ?>
