<div class="page-head"><div><h1>مسیرهای آموزشی</h1><div class="sub">مسیر مرحله‌ای: ترتیب دوره‌ها و شرایط عبور از هر مرحله (پیشرفت، نمره، تمرین، ارزیابی عملی)</div></div>
<div class="btn-group"><a class="btn btn-outline" href="<?= url('/learn/paths') ?>"><?= icon('eye') ?> نمای نقشه راه</a><?php if (can('paths.create')): ?><button class="btn btn-grad" data-open="dlg-path"><?= icon('plus') ?> مسیر جدید</button><?php endif; ?></div></div>
<?php if (!$rows): ?><div class="card empty"><?= icon('route') ?><h3>هنوز مسیری تعریف نشده</h3><p>مثال: مبانی تجارت ← مشتری‌یابی ← ارتباط با مشتری ← مذاکره ← فروش</p></div><?php endif; ?>
<?php if ($rows):
    $sortable = can('paths.edit') && count($rows) > 1; ?>
<?php if ($sortable): ?><div class="drag-hint po-hint"><?= icon('grip-vertical') ?> ترتیب مسیرها همان ترتیب نقشه راه فراگیران است — با کشیدن دستگیره، مسیرها را جابه‌جا کنید (اولی در بالا).</div><?php endif; ?>
<div class="path-order"<?= $sortable ? ' data-sortable="' . url('/admin/paths/order') . '" data-row-drag' : '' ?>>
<?php foreach ($rows as $i => $p): ?>
    <div class="po-row" data-id="<?= (int)$p['id'] ?>" style="--c:<?= e($p['color'] ?: '#6366f1') ?>">
        <?php if ($sortable): ?><span class="drag-h" title="جابه‌جایی"><?= icon('grip-vertical') ?></span><?php endif; ?>
        <span class="po-no" data-row-no><?= fa($i + 1) ?></span>
        <div class="po-main">
            <a class="po-title" href="<?= url('/admin/paths/' . $p['id']) ?>"><?= e($p['title']) ?></a>
            <div class="po-sub">
                <?= status_badge($p['status']) ?>
                <?= $p['target_segment'] ? '<span class="badge badge-gray">' . e(label('segment', $p['target_segment'])) . '</span>' : '<span class="badge badge-gray">همه فراگیران</span>' ?>
                <?= $p['group_name'] && $p['group_name'] !== ($p['target_segment'] ? label('segment', $p['target_segment']) : '') ? '<span class="badge badge-gray">' . icon('users') . ' ' . e($p['group_name']) . '</span>' : '' ?>
            </div>
        </div>
        <div class="po-stats"><span><b><?= fa($p['steps']) ?></b> مرحله</span><span><b><?= fa($p['learners']) ?></b> فراگیر</span><span><b><?= fa($p['done']) ?></b> تکمیل</span></div>
        <a class="btn btn-sm btn-ghost btn-icon" href="<?= url('/admin/paths/' . $p['id']) ?>" title="مدیریت مسیر"><?= icon('chevron-left') ?></a>
    </div>
<?php endforeach; ?>
</div>
<?php endif; ?>
<?php if (can('paths.create')): ?>
<dialog class="modal" id="dlg-path"><div class="modal-head"><b>مسیر آموزشی جدید</b><button class="btn btn-ghost btn-icon" data-close><?= icon('x') ?></button></div>
<form class="modal-body" method="post" action="<?= url('/admin/paths') ?>"><?= csrf_field() ?>
    <div class="field"><label>عنوان</label><input type="text" name="title" required placeholder="مثلاً: مسیر تاجر حرفه‌ای"></div>
    <div class="field"><label>توضیحات</label><textarea name="description" rows="3"></textarea></div>
    <div class="form-grid">
        <div class="field"><label>گروه هدف</label><select name="target_segment"><option value="">همه</option><?php foreach (['merchant', 'employee', 'agent'] as $s): ?><option value="<?= $s ?>"><?= e(label('segment', $s)) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label>گروه خاص</label><select name="group_id"><option value="">—</option><?php foreach ($groups as $k => $v): ?><option value="<?= (int)$k ?>"><?= e($v) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label>رنگ</label><input class="ltr" type="text" name="color" value="#6366f1"></div>
    </div>
    <button class="btn btn-primary"><?= icon('plus') ?> ایجاد</button>
</form></dialog>
<?php endif; ?>
