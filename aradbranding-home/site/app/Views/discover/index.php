<?php /** @var array $recommended @var bool $hasInterests @var array $newProposals @var array $popular @var array $countryStats @var array $countries @var array $categories */ ?>
<div class="stack">
  <?= $this->partial('discover/_finder', ['q' => '', 'type' => 'traders', 'country' => 0, 'category' => 0, 'product' => '', 'countries' => $countries, 'categories' => $categories]) ?>

  <section class="disc">
    <div class="disc-head"><h2>تجار پیشنهادی برای شما</h2><?php if (!$hasInterests): ?><a class="btn btn-quiet btn-sm" href="/account?tab=interests">انتخاب علاقه‌مندی‌ها</a><?php endif; ?></div>
    <?php if ($recommended === []): ?><p class="muted">با انتخاب حوزه‌های مورد علاقه، تجار مرتبط را اینجا می‌بینید.</p>
    <?php else: ?><div class="rail"><?php foreach ($recommended as $t): ?><?= $this->partial('discover/_trader', ['t' => $t]) ?><?php endforeach; ?></div><?php endif; ?>
  </section>

  <?php if ($popular !== [] || $newProposals !== []): ?>
  <div class="disc-pair">
    <?php if ($popular !== []): ?>
    <section class="disc">
      <div class="disc-head"><h2>فرصت‌های پربازدید این هفته</h2><a class="btn btn-quiet btn-sm" href="/proposals">همه</a></div>
      <div class="pmini-list"><?php foreach ($popular as $p): ?><?= $this->partial('discover/_mini', ['p' => $p]) ?><?php endforeach; ?></div>
    </section>
    <?php endif; ?>
    <?php if ($newProposals !== []): ?>
    <section class="disc">
      <div class="disc-head"><h2>تازه‌ترین پیشنهادها</h2><a class="btn btn-quiet btn-sm" href="/proposals?tab=latest">همه</a></div>
      <div class="pmini-list"><?php foreach ($newProposals as $p): ?><?= $this->partial('discover/_mini', ['p' => $p]) ?><?php endforeach; ?></div>
    </section>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <?php if ($countryStats !== []): ?>
  <section class="disc">
    <div class="disc-head"><h2>کشورهای پرتاجر</h2><span class="muted disc-note">برای کشورهای دیگر از فیلد «کشور» بالای صفحه استفاده کنید.</span></div>
    <div class="country-grid">
      <?php foreach (array_slice($countryStats, 0, 12) as $cs): $c = $countries[$cs['country_id']] ?? null; if (!$c) { continue; } ?>
        <a class="country-tile" href="/discover/country/<?= e($c['id']) ?>"><span class="country-flag" aria-hidden="true"><?= flag($c['code']) ?></span><b><?= e($c['name_fa']) ?></b><small><?= fa_int($cs['n']) ?> تاجر</small></a>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>
</div>
