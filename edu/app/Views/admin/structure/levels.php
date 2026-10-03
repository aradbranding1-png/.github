<div class="page-head"><div><h1>سطح‌بندی و طبقه‌بندی‌ها</h1><div class="sub">هر گروه سطح‌بندی مستقل خود را دارد. طبقه‌بندی‌های تاجران (مرحله، نوع فعالیت، بازار، محصول…) و نمایندگان (کشور، منطقه، نوع و سطح نمایندگی…) نیز از اینجا مدیریت می‌شوند.</div></div></div>
<h3 class="mb-2"><?= icon('signal') ?> سطوح هر گروه</h3>
<div class="grid g-auto mb-3" style="grid-template-columns:repeat(auto-fill,minmax(320px,1fr))">
    <?php foreach ($groups as $g): ?>
        <div class="card" style="border-top:4px solid <?= e($g['color']) ?>">
            <h3 class="flex"><?= icon($g['icon']) ?> <?= e($g['name']) ?></h3>
            <?php foreach ($levels[$g['id']] ?? [] as $l): ?>
                <div class="list-item">
                    <?php if (can('levels.edit')): ?>
                        <form class="flex grow" method="post" action="<?= url('/admin/levels/' . $l['id']) ?>"><?= csrf_field() ?><input type="hidden" name="group_id" value="<?= (int)$g['id'] ?>"><input type="number" name="rank_no" value="<?= (int)$l['rank_no'] ?>" style="width:56px;padding:.3rem"><input type="text" name="name" value="<?= e($l['name']) ?>" style="padding:.3rem .5rem"><input type="text" name="color" value="<?= e($l['color']) ?>" class="ltr" style="width:84px;padding:.3rem"><button class="btn btn-xs btn-outline"><?= icon('save') ?></button></form>
                    <?php else: ?><span class="dot-st" style="background:<?= e($l['color']) ?>"></span><div class="grow"><?= e($l['name']) ?></div><?php endif; ?>
                    <span class="badge badge-gray"><?= fa($l['n']) ?></span>
                    <?php if (can('levels.delete')): ?><form method="post" action="<?= url('/admin/levels/' . $l['id'] . '/delete') ?>" data-confirm="سطح حذف شود؟"><?= csrf_field() ?><button class="btn btn-xs btn-ghost"><?= icon('trash-2') ?></button></form><?php endif; ?>
                </div>
            <?php endforeach; ?>
            <?php if (can('levels.create')): ?>
                <form class="flex mt-1" method="post" action="<?= url('/admin/levels') ?>"><?= csrf_field() ?><input type="hidden" name="group_id" value="<?= (int)$g['id'] ?>"><input type="number" name="rank_no" value="<?= count($levels[$g['id']] ?? []) + 1 ?>" style="width:60px"><input type="text" name="name" placeholder="سطح جدید" required><button class="btn btn-sm btn-primary"><?= icon('plus') ?></button></form>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>

<h3 class="mb-2" id="tax"><?= icon('list-tree') ?> طبقه‌بندی‌ها</h3>
<div class="grid g-auto" style="grid-template-columns:repeat(auto-fill,minmax(320px,1fr))">
    <?php foreach ($taxes as $t): ?>
        <div class="card">
            <div class="flex between"><h3 class="mb-0"><?= e($t['name']) ?></h3><span class="badge badge-gray"><?= e(label('segment', $t['segment'])) ?></span></div>
            <div class="mt-1">
            <?php foreach ($t['terms'] as $term): ?>
                <div class="list-item" style="padding:.35rem 0">
                    <?php if (can('levels.edit')): ?><form class="flex grow" method="post" action="<?= url('/admin/terms/' . $term['id']) ?>"><?= csrf_field() ?><input type="text" name="name" value="<?= e($term['name']) ?>" style="padding:.3rem .5rem"><button class="btn btn-xs btn-outline"><?= icon('save') ?></button></form><?php else: ?><div class="grow"><?= e($term['name']) ?></div><?php endif; ?>
                    <span class="badge badge-gray"><?= fa($term['n']) ?></span>
                    <?php if (can('levels.delete')): ?><form method="post" action="<?= url('/admin/terms/' . $term['id'] . '/delete') ?>" data-confirm="حذف شود؟"><?= csrf_field() ?><button class="btn btn-xs btn-ghost"><?= icon('x') ?></button></form><?php endif; ?>
                </div>
            <?php endforeach; ?>
            </div>
            <?php if (can('levels.create')): ?><form class="flex mt-1" method="post" action="<?= url('/admin/terms') ?>"><?= csrf_field() ?><input type="hidden" name="taxonomy_id" value="<?= (int)$t['id'] ?>"><input type="text" name="name" placeholder="مقدار جدید (مثلاً: ترکیه)" required><button class="btn btn-sm btn-primary"><?= icon('plus') ?></button></form><?php endif; ?>
            <?php if (can('levels.delete')): ?><form class="mt-1" method="post" action="<?= url('/admin/taxonomies/' . $t['id'] . '/delete') ?>" data-confirm="کل این طبقه‌بندی و مقادیرش حذف شود؟"><?= csrf_field() ?><button class="btn btn-xs btn-ghost" style="color:var(--danger)"><?= icon('trash-2') ?> حذف طبقه‌بندی</button></form><?php endif; ?>
        </div>
    <?php endforeach; ?>
    <?php if (can('levels.create')): ?>
    <form class="card" method="post" action="<?= url('/admin/taxonomies') ?>">
        <?= csrf_field() ?>
        <h3><?= icon('plus') ?> طبقه‌بندی جدید</h3>
        <div class="field"><label>نام</label><input type="text" name="name" required placeholder="مثلاً: حوزه تخصصی"></div>
        <div class="field"><label>برای</label><select name="segment"><?php foreach (App\Core\Labels::SEGMENT as $k => $v): ?><option value="<?= $k ?>"><?= e($v) ?></option><?php endforeach; ?></select></div>
        <button class="btn btn-primary"><?= icon('plus') ?> ایجاد</button>
    </form>
    <?php endif; ?>
</div>
