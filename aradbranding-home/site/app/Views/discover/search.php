<?php /** @var string $q @var string $type @var int $country @var int $category @var string $product @var int $page @var array $result @var array $countries @var array $categories */
$tabs = ['traders' => t('تجار'), 'pages' => t('صفحه‌های تجاری'), 'proposals' => t('پیشنهادها')];
$qs = static fn (array $over) => '/search?' . http_build_query(array_filter(array_merge(['q' => $q, 'product' => $product, 'type' => $type, 'country' => $country ?: null, 'category' => $category ?: null], $over), static fn ($v) => $v !== null && $v !== '')); ?>
<div class="stack">
  <?= $this->partial('discover/_finder', ['q' => $q, 'type' => $type, 'country' => $country, 'category' => $category, 'product' => $product, 'countries' => $countries, 'categories' => $categories]) ?>
  <nav class="mail-cats" aria-label="<?= te('نوع نتیجه') ?>"><?php foreach ($tabs as $k => $l): ?><a href="<?= e($qs(['type' => $k, 'page' => null])) ?>"<?= $type === $k ? ' aria-current="page"' : '' ?>><?= e($l) ?></a><?php endforeach; ?></nav>

  <?php if ($q === '' && $country === 0 && $category === 0 && mb_strlen($product) < 2): ?>
    <div class="panel empty"><svg class="icon"><use href="#i-search"/></svg><h3><?= te('دنبال چه هستید؟') ?></h3><p><?= te('محصول یا کالا (مثلاً «زعفران»)، کشور، حوزه فعالیت یا نام تاجر را انتخاب کنید و «اعمال و جستجو» را بزنید.') ?></p></div>
  <?php elseif ($result['rows'] === []): ?>
    <div class="panel empty"><h3><?= te('نتیجه‌ای پیدا نشد') ?></h3><p><?= te('یکی از فیلترها را بردارید یا کلمه دیگری امتحان کنید.') ?></p><a class="btn btn-ghost" href="/discover"><?= te('کشف تجار و فرصت‌ها') ?></a></div>
  <?php elseif ($type === 'traders'): ?>
    <div class="trader-grid"><?php foreach ($result['rows'] as $t): ?><?= $this->partial('discover/_trader', ['t' => $t]) ?><?php endforeach; ?></div>
  <?php elseif ($type === 'pages'): ?>
    <ul class="mail"><?php foreach ($result['rows'] as $r): $av = media($r['avatar_path']); ?>
      <li class="mail-row"><a href="/p/<?= e($r['handle']) ?>/<?= e($r['lang']) ?>">
        <?php if ($av): ?><img class="avatar" src="<?= e($av) ?>" alt="" loading="lazy"><?php else: ?><span class="avatar"><?= e(mb_substr((string) $r['title'], 0, 1)) ?></span><?php endif; ?>
        <span class="mail-main"><span class="mail-top"><span class="mail-name"><?= e($r['company_name'] ?: $r['title']) ?> <?= flag($r['country_code']) ?></span><span class="chip"><?= e(strtoupper($r['lang'])) ?></span></span>
          <span class="mail-preview"><?= e(\App\Core\Security\ContactGuard::mask(\App\Core\Support\Str::excerpt(plain_text($r['teaser']), 160))) ?></span></span></a></li>
    <?php endforeach; ?></ul>
  <?php else: ?>
    <div class="pmini-list pmini-grid"><?php foreach ($result['rows'] as $p): ?><?= $this->partial('discover/_mini', ['p' => $p]) ?><?php endforeach; ?></div>
  <?php endif; ?>

  <?php if ($result['more'] || $page > 1): ?>
    <div class="form-actions form-actions-center">
      <?php if ($page > 1): ?><a class="btn btn-ghost btn-sm" href="<?= e($qs(['page' => $page - 1])) ?>"><?= te('قبلی') ?></a><?php endif; ?>
      <?php if ($result['more']): ?><a class="btn btn-ghost btn-sm" href="<?= e($qs(['page' => $page + 1])) ?>"><?= te('بعدی') ?></a><?php endif; ?>
    </div>
  <?php endif; ?>
</div>
