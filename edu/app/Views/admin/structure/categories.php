<div class="page-head"><div><h1>موضوعات آموزشی</h1><div class="sub">دسته‌بندی دوره‌ها — برای هر موضوع می‌توان ده‌ها دوره، درس، آزمون و مسیر ایجاد کرد.</div></div>
<?php if (can('categories.create')): ?><button class="btn btn-grad" data-open="dlg-cat"><?= icon('plus') ?> موضوع جدید</button><?php endif; ?></div>
<div class="grid g-auto">
    <?php foreach ($rows as $c): ?>
        <div class="card" style="border-top:4px solid <?= e($c['color']) ?>">
            <div class="flex between"><div class="flex"><span class="ico" style="width:42px;height:42px;border-radius:12px;display:grid;place-items:center;background:color-mix(in srgb, <?= e($c['color']) ?> 14%, transparent);color:<?= e($c['color']) ?>"><?= icon($c['icon']) ?></span><div><b><?= e($c['name']) ?></b><div class="small faint"><?= $c['segment'] ? e(label('segment', $c['segment'])) : 'همه گروه‌ها' ?><?= $c['parent_id'] ? ' · زیرموضوع' : '' ?></div></div></div><span class="badge badge-primary"><?= fa($c['courses']) ?> دوره</span></div>
            <?php if (can('categories.edit')): ?>
            <details class="mt-1"><summary class="small" style="cursor:pointer;color:var(--primary)">ویرایش</summary>
                <form method="post" action="<?= url('/admin/categories/' . $c['id']) ?>" class="mt-1"><?= csrf_field() ?>
                    <div class="field"><input type="text" name="name" value="<?= e($c['name']) ?>" required></div>
                    <div class="form-grid">
                        <div class="field"><select name="segment"><option value="">همه گروه‌ها</option><?php foreach (['merchant', 'employee', 'agent'] as $s): ?><option value="<?= $s ?>"<?= selected($s, $c['segment']) ?>><?= e(label('segment', $s)) ?></option><?php endforeach; ?></select></div>
                        <div class="field"><select name="parent_id"><option value="">— اصلی —</option><?php foreach ($rows as $p): if ($p['id'] == $c['id']) continue; ?><option value="<?= (int)$p['id'] ?>"<?= selected($p['id'], $c['parent_id']) ?>><?= e($p['name']) ?></option><?php endforeach; ?></select></div>
                        <div class="field"><select name="icon"><?php foreach ($icons as $i): ?><option value="<?= $i ?>"<?= selected($i, $c['icon']) ?>><?= $i ?></option><?php endforeach; ?></select></div>
                        <div class="field"><input type="text" class="ltr" name="color" value="<?= e($c['color']) ?>"></div>
                        <div class="field"><input type="number" name="sort" value="<?= (int)$c['sort'] ?>"></div>
                    </div>
                    <div class="flex"><button class="btn btn-sm btn-primary"><?= icon('save') ?> ذخیره</button></div>
                </form>
                <?php if (can('categories.delete')): ?><form method="post" action="<?= url('/admin/categories/' . $c['id'] . '/delete') ?>" data-confirm="حذف شود؟" class="mt-1"><?= csrf_field() ?><button class="btn btn-xs btn-ghost" style="color:var(--danger)"><?= icon('trash-2') ?> حذف</button></form><?php endif; ?>
            </details>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>
<?php if (can('categories.create')): ?>
<dialog class="modal" id="dlg-cat">
    <div class="modal-head"><b>موضوع جدید</b><button class="btn btn-ghost btn-icon" data-close><?= icon('x') ?></button></div>
    <form class="modal-body" method="post" action="<?= url('/admin/categories') ?>"><?= csrf_field() ?>
        <div class="form-grid">
            <div class="field full"><label>نام</label><input type="text" name="name" required></div>
            <div class="field"><label>گروه هدف</label><select name="segment"><option value="">همه</option><?php foreach (['merchant', 'employee', 'agent'] as $s): ?><option value="<?= $s ?>"><?= e(label('segment', $s)) ?></option><?php endforeach; ?></select></div>
            <div class="field"><label>زیرموضوعِ</label><select name="parent_id"><option value="">— اصلی —</option><?php foreach ($rows as $p): ?><option value="<?= (int)$p['id'] ?>"><?= e($p['name']) ?></option><?php endforeach; ?></select></div>
            <div class="field"><label>آیکون</label><select name="icon"><?php foreach ($icons as $i): ?><option value="<?= $i ?>"><?= $i ?></option><?php endforeach; ?></select></div>
            <div class="field"><label>رنگ</label><input type="text" class="ltr" name="color" value="#6366f1"></div>
        </div>
        <button class="btn btn-primary"><?= icon('save') ?> ایجاد</button>
    </form>
</dialog>
<?php endif; ?>
