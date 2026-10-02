<?php
/**
 * Discover finder: several filters at once, applied with one button (→ /search).
 * @var string $q @var string $type @var int $country @var int $category @var string $product @var array $countries @var array $categories
 */
$types = ['traders' => 'تجار', 'pages' => 'صفحه‌های تجاری', 'proposals' => 'پیشنهادها'];
$type = isset($types[$type ?? '']) ? $type : 'traders';
$active = ($q ?? '') !== '' || !empty($country) || !empty($category) || ($product ?? '') !== '';
?>
<form class="finder" method="get" action="/search" role="search" aria-label="کشف تجار و فرصت‌ها">
  <div class="finder-head">
    <h2><svg class="icon" aria-hidden="true"><use href="#i-compass"/></svg>کشف بر اساس چند ویژگی</h2>
    <div class="finder-types" role="radiogroup" aria-label="نوع نتیجه">
      <?php foreach ($types as $k => $label): ?>
        <label class="ff-chip"><input type="radio" name="type" value="<?= e($k) ?>"<?= $type === $k ? ' checked' : '' ?>><span><?= e($label) ?></span></label>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="finder-grid">
    <div class="ff-group">
      <label class="ff-label" for="fd-product">محصول یا کالا</label>
      <input class="input" id="fd-product" name="product" value="<?= e($product ?? '') ?>" maxlength="60" placeholder="مثلاً زعفران، تجهیزات پزشکی…" autocomplete="off">
    </div>
    <div class="ff-group">
      <label class="ff-label" for="fd-country">کشور</label>
      <select class="select" id="fd-country" name="country" data-ss>
        <option value="">همه کشورها</option>
        <?php foreach ($countries as $c): ?><option value="<?= e($c['id']) ?>" data-search="<?= e(($c['name_en'] ?? '') . ' ' . $c['code']) ?>"<?= (int) ($country ?? 0) === (int) $c['id'] ? ' selected' : '' ?>><?= e(flag($c['code']) . ' ' . $c['name_fa']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="ff-group">
      <label class="ff-label" for="fd-category">حوزه فعالیت</label>
      <select class="select" id="fd-category" name="category" data-ss>
        <option value="">همه حوزه‌ها</option>
        <?php foreach ($categories as $c): ?><option value="<?= e($c['id']) ?>" data-search="<?= e($c['name_en'] ?? '') ?>"<?= (int) ($category ?? 0) === (int) $c['id'] ? ' selected' : '' ?>><?= e($c['name_fa']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="ff-group">
      <label class="ff-label" for="fd-q">نام تاجر یا شرکت <span class="muted">(اختیاری)</span></label>
      <input class="input" id="fd-q" type="search" name="q" value="<?= e($q ?? '') ?>" placeholder="نام یا نشانی صفحه" autocomplete="off" enterkeyhint="search">
    </div>
  </div>
  <div class="ff-actions">
    <button class="btn" type="submit"><svg class="icon"><use href="#i-search"/></svg>اعمال و جستجو</button>
    <?php if ($active): ?><a class="btn btn-quiet" href="/discover">پاک‌کردن فیلترها</a><?php endif; ?>
  </div>
</form>
