<?php
$renderTree = function (int $parent, int $depth = 0) use (&$renderTree, $tree) {
    if (empty($tree[$parent])) return;
    echo '<ul class="tree">';
    foreach ($tree[$parent] as $g) {
        echo '<li><div class="node" style="border-right:4px solid ' . e($g['color']) . '">'
            . '<span style="color:' . e($g['color']) . '">' . icon($g['icon']) . '</span>'
            . '<a class="fw-b grow" href="' . url('/admin/groups/' . $g['id']) . '">' . e($g['name']) . '</a>'
            . ($g['is_system'] ? '<span class="badge badge-gray">گروه اصلی</span>' : '')
            . '<span class="badge badge-primary">' . fa($g['members']) . ' عضو</span>'
            . '<span class="badge badge-info">' . fa($g['levels_n']) . ' سطح</span>'
            . ($g['first_name'] ? '<span class="badge badge-warning">' . icon('user-check') . ' ' . e($g['first_name'] . ' ' . $g['last_name']) . '</span>' : '')
            . '</div>';
        $renderTree((int)$g['id'], $depth + 1);
        echo '</li>';
    }
    echo '</ul>';
};
?>
<div class="page-head">
    <div><h1>گروه‌ها</h1><div class="sub">سه گروه اصلی (تاجران، کارمندان، نمایندگان) با ساختار آموزشی مستقل + زیرگروه‌های نامحدود. هر کاربر می‌تواند عضو چند گروه باشد.</div></div>
    <?php if (can('groups.create')): ?><button class="btn btn-grad" data-open="dlg-group"><?= icon('plus') ?> گروه / زیرگروه جدید</button><?php endif; ?>
</div>
<div class="card"><?php $renderTree(0); ?></div>

<?php if (can('groups.create')): ?>
<dialog class="modal" id="dlg-group">
    <div class="modal-head"><b>گروه جدید</b><button class="btn btn-ghost btn-icon" data-close><?= icon('x') ?></button></div>
    <form class="modal-body" method="post" action="<?= url('/admin/groups') ?>">
        <?= csrf_field() ?>
        <div class="form-grid">
            <div class="field full"><label>نام گروه</label><input type="text" name="name" required></div>
            <div class="field"><label>نوع</label><select name="segment"><?php foreach (App\Core\Labels::SEGMENT as $k => $v): ?><option value="<?= $k ?>"><?= e($v) ?></option><?php endforeach; ?></select></div>
            <div class="field"><label>زیرمجموعه</label><select name="parent_id"><option value="">— گروه سطح اول —</option><?php foreach ($rows as $g): ?><option value="<?= (int)$g['id'] ?>"><?= e($g['name']) ?></option><?php endforeach; ?></select></div>
            <div class="field"><label>آیکون</label><select name="icon"><?php foreach ($icons as $i): ?><option value="<?= $i ?>"><?= $i ?></option><?php endforeach; ?></select></div>
            <div class="field"><label>رنگ</label><input type="text" class="ltr" name="color" value="#4f46e5"></div>
            <div class="field full"><label>مسئول آموزش گروه</label><select name="supervisor_id"><option value="">—</option><?php foreach ($supervisors as $s): ?><option value="<?= (int)$s['id'] ?>"><?= e(full_name($s)) ?></option><?php endforeach; ?></select></div>
            <div class="field full"><label>توضیحات</label><textarea name="description" rows="2"></textarea></div>
        </div>
        <button class="btn btn-primary"><?= icon('save') ?> ایجاد</button>
    </form>
</dialog>
<?php endif; ?>
