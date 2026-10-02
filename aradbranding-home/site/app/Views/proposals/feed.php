<?php
/** @var array $items @var ?string $next @var string $moreUrl @var string $nextUrl @var string $tab @var array $filters @var array $categories @var array $countries @var bool $hasInterests */
use App\Modules\Proposals\ProposalService;
$isFiltered = (bool) array_filter($filters);
?>
<?= $this->partial('proposals/_tabs', ['path' => '/proposals']) ?>

<div class="feed-head">
  <div class="segmented" role="tablist">
    <a role="tab" href="/proposals"<?= $tab === 'foryou' ? ' aria-selected="true"' : '' ?>>برای شما</a>
    <a role="tab" href="/proposals?tab=latest"<?= $tab === 'latest' && !$isFiltered ? ' aria-selected="true"' : '' ?>>جدیدترین</a>
  </div>
  <details class="filters"<?= $isFiltered ? ' open' : '' ?>>
    <summary class="filters-btn"><svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16M7 12h10M10 18h4"/></svg>فیلتر<?php if ($isFiltered): ?><em class="filters-on"><?= fa_int(count(array_filter($filters))) ?></em><?php endif; ?></summary>
    <form class="filters-form" method="get" action="/proposals">
      <input type="hidden" name="tab" value="latest">
      <fieldset class="ff-group">
        <legend>نوع پیشنهاد</legend>
        <div class="ff-chips">
          <label class="ff-chip"><input type="radio" name="type" value=""<?= !$filters['type'] ? ' checked' : '' ?>><span>همه</span></label>
          <?php foreach (ProposalService::TYPES as $id => $label): ?>
            <label class="ff-chip"><input type="radio" name="type" value="<?= $id ?>"<?= $filters['type'] === $id ? ' checked' : '' ?>><span><?= e($label) ?></span></label>
          <?php endforeach; ?>
        </div>
      </fieldset>
      <div class="ff-row">
        <div class="ff-group">
          <label class="ff-label" for="ff-category">دسته</label>
          <select class="select" id="ff-category" name="category" data-ss>
            <option value="">همه دسته‌ها</option>
            <?php foreach ($categories as $c): ?><option value="<?= e($c['id']) ?>" data-search="<?= e($c['name_en'] ?? '') ?>"<?= $filters['category'] === $c['id'] ? ' selected' : '' ?>><?= e($c['name_fa']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="ff-group">
          <label class="ff-label" for="ff-country">کشور</label>
          <select class="select" id="ff-country" name="country" data-ss>
            <option value="">همه کشورها</option>
            <?php foreach ($countries as $c): ?><option value="<?= e($c['id']) ?>" data-search="<?= e($c['name_en'] ?? '') ?>"<?= $filters['country'] === $c['id'] ? ' selected' : '' ?>><?= e(flag($c['code']) . ' ' . $c['name_fa']) ?></option><?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="ff-actions">
        <button class="btn btn-sm" type="submit"><svg class="icon"><use href="#i-search"/></svg>اعمال فیلتر</button>
        <?php if ($isFiltered): ?><a class="btn btn-quiet btn-sm" href="/proposals?tab=latest">حذف فیلترها</a><?php endif; ?>
      </div>
    </form>
  </details>
</div>

<?php if ($tab === 'foryou' && !$hasInterests): ?>
  <a class="panel hint-strip" href="/account?tab=interests">حوزه‌های مورد علاقه‌تان را انتخاب کنید تا فید برای شما شخصی شود ←</a>
<?php endif; ?>

<?php if ($items === []): ?>
  <div class="panel empty">
    <svg class="icon"><use href="#i-spark"/></svg>
    <h3><?= $isFiltered ? 'پیشنهادی با این فیلتر پیدا نشد' : 'هنوز پیشنهادی منتشر نشده است' ?></h3>
    <p>اولین نفری باشید که فرصت تجاری خود را معرفی می‌کند.</p>
    <a class="btn" href="/proposals/new">ساخت پیشنهاد تجاری</a>
  </div>
<?php else: ?>
  <div class="feed" id="feed" data-more="<?= e($next ? $moreUrl : '') ?>">
    <?php foreach ($items as $p): ?><?= $this->partial('proposals/card', ['p' => $p]) ?><?php endforeach; ?>
  </div>
  <div class="feed-foot" id="feed-foot">
    <?php if ($next): ?>
      <div class="skeleton-row" aria-hidden="true"><span></span><span></span><span></span></div>
      <a class="btn btn-ghost btn-sm" href="<?= e($nextUrl) ?>" data-feed-next>موارد بیشتر</a>
    <?php else: ?>
      <p class="muted">به انتهای فهرست رسیدید.</p>
    <?php endif; ?>
  </div>
<?php endif; ?>
