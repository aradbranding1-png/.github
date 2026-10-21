<?php
$segLabel = $audiences[$seg];
$segOne = ['merchant' => 'تاجران', 'employee' => 'کارمندان', 'agent' => 'نمایندگان', 'all' => 'همه گروه‌ها'][$seg];
?>
<div class="page-head"><div><h1>مسیرهای آموزشی</h1><div class="sub">برای هر گروه (تاجران، کارمندان، نمایندگان) مسیر اختصاصی خودش را بسازید؛ هر فراگیر فقط مسیرهای گروه خودش را می‌بیند.</div></div>
<div class="btn-group"><a class="btn btn-outline" href="<?= url('/learn/paths', $seg !== 'all' ? ['seg' => $seg] : []) ?>"><?= icon('eye') ?> نمای نقشه راه <?= e($segOne) ?></a><?php if (can('paths.create')): ?><button class="btn btn-grad" data-open="dlg-path"><?= icon('plus') ?> مسیر جدید برای <?= e($segOne) ?></button><?php endif; ?></div></div>

<div class="card mb-3" style="padding:.9rem 1rem">
    <div class="small muted mb-1"><?= icon('users') ?> مسیرهای کدام گروه را می‌خواهید ببینید و بسازید؟</div>
    <div class="pill-nav" style="margin:0">
        <?php foreach ($audiences as $k => $t): ?><a class="<?= $seg === $k ? 'active' : '' ?>" href="<?= url('/admin/paths', ['seg' => $k]) ?>"><?= e($t) ?> <span style="opacity:.75">(<?= fa($counts[$k] ?? 0) ?>)</span></a><?php endforeach; ?>
    </div>
</div>

<?php if (!$rows): ?><div class="card empty"><?= icon('route') ?><h3>هنوز مسیری برای <?= e($segOne) ?> تعریف نشده</h3>
    <p><?= $seg === 'all' ? 'مسیرهای مشترک برای همه فراگیران، صرف‌نظر از گروه، نمایش داده می‌شوند.' : 'مسیر ' . e($segOne) . ' را جدا بسازید، یا یکی از مسیرهای گروه دیگر را کپی کنید و تغییرش دهید (دکمه کپی کنار هر مسیر).' ?></p>
    <?php if (can('paths.create')): ?><button class="btn btn-grad" data-open="dlg-path"><?= icon('plus') ?> ساخت اولین مسیر <?= e($segOne) ?></button><?php endif; ?>
</div><?php endif; ?>
<?php if ($rows):
    $sortable = can('paths.edit') && count($rows) > 1; ?>
<?php if ($sortable): ?><div class="drag-hint po-hint"><?= icon('grip-vertical') ?> ترتیب مسیرهای <?= e($segOne) ?> همان ترتیب نقشه راه آن‌هاست — با کشیدن دستگیره، مسیرها را جابه‌جا کنید (اولی در بالا).</div><?php endif; ?>
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
        <?php if (can('paths.create')): ?><button class="btn btn-sm btn-ghost btn-icon" type="button" data-open="dlg-copy-<?= (int)$p['id'] ?>" title="کپی این مسیر برای گروه دیگر"><?= icon('copy') ?></button><?php endif; ?>
        <a class="btn btn-sm btn-ghost btn-icon" href="<?= url('/admin/paths/' . $p['id']) ?>" title="مدیریت مسیر"><?= icon('chevron-left') ?></a>
    </div>
<?php endforeach; ?>
</div>
<?php if (can('paths.create')) foreach ($rows as $p): ?>
<dialog class="modal" id="dlg-copy-<?= (int)$p['id'] ?>"><div class="modal-head"><b>کپی «<?= e($p['title']) ?>» برای گروه دیگر</b><button class="btn btn-ghost btn-icon" data-close><?= icon('x') ?></button></div>
<form class="modal-body" method="post" action="<?= url('/admin/paths/' . $p['id'] . '/copy') ?>"><?= csrf_field() ?>
    <p class="small muted">یک نسخه پیش‌نویس با همه مراحل ساخته می‌شود تا برای آن گروه تغییرش دهید. مسیر فعلی دست نمی‌خورد.</p>
    <div class="field"><label>برای کدام گروه؟</label><div class="chip-select">
        <?php $first = true; foreach (['merchant' => 'تاجران', 'employee' => 'کارمندان', 'agent' => 'نمایندگان', '' => 'همه گروه‌ها'] as $k => $t): if ($k === ($p['target_segment'] ?? '')) continue; ?><label><input type="radio" name="target_segment" value="<?= $k ?>"<?= $first ? ' checked' : '' ?>><span><?= e($t) ?></span></label><?php $first = false; endforeach; ?>
    </div></div>
    <div class="field"><label>عنوان نسخه جدید</label><input type="text" name="title" value="<?= e($p['title']) ?>" required></div>
    <button class="btn btn-primary"><?= icon('copy') ?> ساخت نسخه</button>
</form></dialog>
<?php endforeach; ?>
<?php endif; ?>
<?php if (can('paths.create')): ?>
<dialog class="modal" id="dlg-path"><div class="modal-head"><b>مسیر آموزشی جدید</b><button class="btn btn-ghost btn-icon" data-close><?= icon('x') ?></button></div>
<form class="modal-body" method="post" action="<?= url('/admin/paths') ?>"><?= csrf_field() ?>
    <div class="field"><label>این مسیر برای کدام گروه است؟ <span class="req">*</span></label><div class="chip-select">
        <?php foreach (['merchant' => 'تاجران', 'employee' => 'کارمندان', 'agent' => 'نمایندگان', '' => 'همه گروه‌ها'] as $k => $t): ?><label><input type="radio" name="target_segment" value="<?= $k ?>"<?= ($seg === 'all' ? '' : $seg) === $k ? ' checked' : '' ?>><span><?= e($t) ?></span></label><?php endforeach; ?>
    </div><div class="hint">فقط فراگیران همین گروه این مسیر را در نقشه راه خود می‌بینند.</div></div>
    <div class="field"><label>عنوان</label><input type="text" name="title" required placeholder="مثلاً: مسیر کارمند فروش"></div>
    <div class="field"><label>توضیحات</label><textarea name="description" rows="3"></textarea></div>
    <div class="form-grid">
        <div class="field"><label>محدود به یک گروه سازمانی خاص (اختیاری)</label><select name="group_id"><option value="">—</option><?php foreach ($groups as $k => $v): ?><option value="<?= (int)$k ?>"><?= e($v) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label>رنگ</label><input class="ltr" type="text" name="color" value="#6366f1"></div>
    </div>
    <button class="btn btn-primary"><?= icon('plus') ?> ایجاد و تعریف مراحل</button>
</form></dialog>
<?php endif; ?>
