<div class="page-head">
    <div><h1>دوره‌ها و درس‌ها</h1><div class="sub">مدیریت دوره‌ها، سرفصل‌ها، درس‌ها، تمرین‌ها و انتشار</div></div>
    <?php if (can('courses.create')): ?><a class="btn btn-grad" href="<?= url('/admin/courses/create') ?>"><?= icon('plus') ?> دوره جدید</a><?php endif; ?>
</div>
<form class="card filters" method="get" action="<?= url('/admin/courses') ?>">
    <div class="field grow"><label>جست‌وجو</label><input type="search" name="q" value="<?= e($_GET['q'] ?? '') ?>" placeholder="عنوان دوره"></div>
    <div class="field"><label>وضعیت</label><select name="status"><option value="">همه</option><?php foreach (['draft', 'published', 'archived'] as $s): ?><option value="<?= $s ?>"<?= selected($s, $_GET['status'] ?? '') ?>><?= e(label('status', $s)) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label>موضوع</label><select name="category"><option value="">همه</option><?php foreach ($cats as $k => $v): ?><option value="<?= (int)$k ?>"<?= selected($k, $_GET['category'] ?? '') ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label>گروه هدف</label><select name="segment"><option value="">همه</option><?php foreach (['merchant', 'employee', 'agent'] as $s): ?><option value="<?= $s ?>"<?= selected($s, $_GET['segment'] ?? '') ?>><?= e(label('segment', $s)) ?></option><?php endforeach; ?></select></div>
    <button class="btn btn-primary"><?= icon('filter') ?></button>
</form>
<?php if (!$page['rows']): ?><div class="card empty"><?= icon('book-open') ?><h3>دوره‌ای یافت نشد</h3><?php if (can('courses.create')): ?><a class="btn btn-primary" href="<?= url('/admin/courses/create') ?>">ایجاد اولین دوره</a><?php endif; ?></div><?php endif; ?>
<div class="grid g-auto">
    <?php foreach ($page['rows'] as $c): $cover = image_url($c['image_file_id'], 640); $color = $c['category_color'] ?: '#6366f1'; ?>
        <div class="card course-card">
            <a class="course-cover" href="<?= url('/admin/courses/' . $c['id']) ?>" style="background:linear-gradient(135deg, <?= e($color) ?>, color-mix(in srgb, <?= e($color) ?> 50%, #0f172a))">
                <?php if ($cover): ?><img class="cc-bg" src="<?= e($cover) ?>" alt="" aria-hidden="true" loading="lazy" decoding="async"><img class="cc-fg" src="<?= e($cover) ?>" alt="" loading="lazy" decoding="async"><?php else: ?><?= icon($c['category_icon'] ?: 'book-open') ?><?php endif; ?>
            </a>
            <div class="course-body">
                <div class="course-tags"><span class="small grow" style="color:<?= e($color) ?>;font-weight:700"><?= e($c['category_name'] ?? 'بدون موضوع') ?><?= $c['target_segment'] ? ' · ' . e(label('segment', $c['target_segment'])) : '' ?></span><span class="badge badge-<?= $c['training_type'] === 'mandatory' ? 'danger' : 'gray' ?>"><?= e(label('training_type', $c['training_type'])) ?></span><?= status_badge($c['status']) ?></div>
                <h3><a href="<?= url('/admin/courses/' . $c['id']) ?>"><?= e($c['title']) ?></a></h3>
                <div class="course-meta"><span><?= icon('list') ?> <?= fa($c['lessons_n']) ?> درس</span><span><?= icon('users') ?> <?= fa($c['learners']) ?> فراگیر</span><span><?= icon('circle-check') ?> <?= fa($c['done']) ?> تکمیل</span><?php if ($c['first_name']): ?><span><?= icon('user') ?> <?= e($c['first_name'] . ' ' . $c['last_name']) ?></span><?php endif; ?></div>
                <div class="course-foot"><div class="grow"><?= progress_bar($c['learners'] ? $c['done'] * 100 / $c['learners'] : 0, 'success') ?></div><a class="btn btn-sm btn-outline" href="<?= url('/admin/courses/' . $c['id']) ?>">مدیریت</a></div>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<?= paginate_links($page) ?>
