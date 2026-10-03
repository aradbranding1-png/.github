<?php
$renderTree = function (int $parent) use (&$renderTree, $tree) {
    if (empty($tree[$parent])) return;
    echo '<ul class="tree">';
    foreach ($tree[$parent] as $o) {
        echo '<li><div class="node"><span style="color:var(--primary)">' . icon($o['icon']) . '</span>'
            . '<span class="badge badge-gray type">' . e($o['type_name']) . '</span>'
            . '<a class="fw-b grow" href="' . url('/admin/org/units/' . $o['id']) . '">' . e($o['name']) . '</a>'
            . ($o['code'] ? '<span class="faint small ltr">' . e($o['code']) . '</span>' : '')
            . ($o['first_name'] ? '<span class="badge badge-warning">' . icon('user-check') . ' ' . e($o['first_name'] . ' ' . $o['last_name']) . '</span>' : '')
            . '<span class="badge badge-primary">' . fa($o['members']) . ' نفر</span>'
            . (can('org.delete') ? '<form class="inline" method="post" action="' . url('/admin/org/units/' . $o['id'] . '/delete') . '" data-confirm="حذف شود؟">' . csrf_field() . '<button class="btn btn-xs btn-ghost" style="color:var(--danger)">' . icon('trash-2') . '</button></form>' : '')
            . '</div>';
        $renderTree((int)$o['id']);
        echo '</li>';
    }
    echo '</ul>';
};
?>
<div class="page-head">
    <div><h1>ساختار سازمانی کارمندان</h1><div class="sub">سطوح ساختار (معاونت ← واحد ← بخش ← سمت) و درخت واحدها کاملاً از پنل قابل تعریف و توسعه است.</div></div>
    <?php if (can('org.create')): ?><button class="btn btn-grad" data-open="dlg-unit"><?= icon('plus') ?> واحد جدید</button><?php endif; ?>
</div>
<div class="grid g-main">
    <div class="card"><?php if (!$units): ?><div class="empty"><?= icon('network') ?><h3>هنوز واحدی تعریف نشده</h3><p>ابتدا معاونت‌ها را ایجاد کنید، سپس واحد، بخش و سمت‌ها را زیرمجموعه آن‌ها قرار دهید.</p></div><?php endif; ?><?php $renderTree(0); ?></div>
    <div class="stack">
        <div class="card">
            <h3><?= icon('list-tree') ?> سطوح ساختار</h3>
            <?php foreach ($types as $t): ?>
                <div class="list-item">
                    <span class="ico" style="background:var(--primary-soft);color:var(--primary)"><?= icon($t['icon']) ?></span>
                    <?php if (can('org.edit')): ?>
                        <form class="flex grow" method="post" action="<?= url('/admin/org/types/' . $t['id']) ?>"><?= csrf_field() ?><input type="text" name="name" value="<?= e($t['name']) ?>" style="padding:.3rem .6rem"><input type="number" name="sort" value="<?= (int)$t['sort'] ?>" style="width:64px;padding:.3rem"><input type="hidden" name="icon" value="<?= e($t['icon']) ?>"><button class="btn btn-xs btn-outline"><?= icon('save') ?></button></form>
                    <?php else: ?><div class="grow"><?= e($t['name']) ?></div><?php endif; ?>
                    <span class="badge badge-gray"><?= fa($t['n']) ?></span>
                    <?php if (can('org.delete') && !$t['n']): ?><form method="post" action="<?= url('/admin/org/types/' . $t['id'] . '/delete') ?>" data-confirm="حذف شود؟"><?= csrf_field() ?><button class="btn btn-xs btn-ghost"><?= icon('trash-2') ?></button></form><?php endif; ?>
                </div>
            <?php endforeach; ?>
            <?php if (can('org.create')): ?>
            <form class="flex mt-2" method="post" action="<?= url('/admin/org/types') ?>"><?= csrf_field() ?><input type="text" name="name" placeholder="سطح جدید (مثلاً: تیم)" required><input type="number" name="sort" value="<?= count($types) + 1 ?>" style="width:70px"><button class="btn btn-sm btn-primary"><?= icon('plus') ?></button></form>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php if (can('org.create')): ?>
<dialog class="modal" id="dlg-unit">
    <div class="modal-head"><b>واحد سازمانی جدید</b><button class="btn btn-ghost btn-icon" data-close><?= icon('x') ?></button></div>
    <form class="modal-body" method="post" action="<?= url('/admin/org/units') ?>">
        <?= csrf_field() ?>
        <div class="form-grid">
            <div class="field"><label>نوع (سطح)</label><select name="type_id" required><?php foreach ($types as $t): ?><option value="<?= (int)$t['id'] ?>"><?= e($t['name']) ?></option><?php endforeach; ?></select></div>
            <div class="field"><label>زیرمجموعه‌ی</label><select name="parent_id"><option value="">— سطح اول —</option><?php foreach ($units as $u): ?><option value="<?= (int)$u['id'] ?>"><?= e($u['type_name'] . ': ' . $u['name']) ?></option><?php endforeach; ?></select></div>
            <div class="field"><label>نام</label><input type="text" name="name" required placeholder="مثلاً: معاونت مالی"></div>
            <div class="field"><label>کد (اختیاری)</label><input type="text" name="code" class="ltr"></div>
            <div class="field full"><label>مدیر / مسئول</label><select name="manager_id"><option value="">—</option><?php foreach ($managers as $m): ?><option value="<?= (int)$m['id'] ?>"><?= e(full_name($m)) ?></option><?php endforeach; ?></select><div class="hint">مدیر واحد با محدوده «تحت مسئولیت»، کارکنان این واحد و زیرمجموعه‌ها را می‌بیند.</div></div>
        </div>
        <button class="btn btn-primary"><?= icon('save') ?> ایجاد</button>
    </form>
</dialog>
<?php endif; ?>
