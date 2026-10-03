<?php
$filtered = $q !== '' || $type !== '' || $cat;
$catName = null;
foreach ($cats as $ct) if ($cat === (int)$ct['id']) $catName = $ct['name'];
$shown = array_values(array_filter($cats, fn($ct) => (int)$ct['n'] > 0 || $cat === (int)$ct['id']));
?>
<div class="page-head">
    <div><h1>کاتالوگ دوره‌ها</h1><div class="sub">دوره‌های متناسب با گروه و مسیر رشد شما</div></div>
</div>

<form class="filters card cat-search" method="get" action="<?= url('/learn/catalog') ?>">
    <div class="field grow"><label>جست‌وجو</label><input type="search" name="q" value="<?= e($q) ?>" placeholder="عنوان دوره…"></div>
    <div class="field"><label>نوع آموزش</label><select name="type"><option value="">همه</option><?php foreach (App\Core\Labels::TRAINING_TYPE as $k => $v): ?><option value="<?= $k ?>"<?= selected($k, $type) ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
    <?php if ($cat): ?><input type="hidden" name="category" value="<?= (int)$cat ?>"><?php endif; ?>
    <button class="btn btn-primary"><?= icon('search') ?> جست‌وجو</button>
</form>

<?php if ($filtered): ?>
    <div class="cat-result-head" id="results">
        <div class="grow">
            <b><?= icon('search') ?> نتیجه <?= fa($page['total']) ?> دوره</b>
            <div class="cat-active">
                <?php if ($q !== ''): ?><span class="badge badge-primary">«<?= e($q) ?>»</span><?php endif; ?>
                <?php if ($catName): ?><span class="badge badge-primary"><?= e($catName) ?></span><?php endif; ?>
                <?php if ($type !== ''): ?><span class="badge badge-primary"><?= e(label('training_type', $type)) ?></span><?php endif; ?>
            </div>
        </div>
        <a class="btn btn-outline btn-sm" href="<?= url('/learn/catalog') ?>"><?= icon('x') ?> حذف فیلترها</a>
    </div>
<?php else: ?>
    <div class="cat-strip mb-3">
        <?php foreach ($shown as $ct): ?>
            <a class="cat-tile" style="--c:<?= e($ct['color']) ?>" href="<?= url('/learn/catalog', ['category' => $ct['id']]) ?>#results">
                <span class="ci"><?= icon($ct['icon']) ?></span><span class="ct"><b><?= e($ct['name']) ?></b><span class="cn"><?= fa($ct['n']) ?> دوره</span></span>
            </a>
        <?php endforeach; ?>
    </div>
    <h3 class="mb-2" id="results"><?= icon('layout-grid') ?> همه دوره‌ها</h3>
<?php endif; ?>

<?php if (!$page['rows']): ?>
    <div class="card empty"><?= icon('book-open') ?><h3>دوره‌ای یافت نشد</h3><?php if ($filtered): ?><a class="btn btn-primary" href="<?= url('/learn/catalog') ?>">نمایش همه دوره‌ها</a><?php endif; ?></div>
<?php else: ?>
    <div class="grid g-auto"><?php foreach ($page['rows'] as $c) include APP_PATH . '/Views/partials/course_card.php'; ?></div>
    <?= paginate_links($page) ?>
<?php endif; ?>
