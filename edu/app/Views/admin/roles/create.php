<div class="page-head"><div><div class="crumbs"><a href="<?= url('/admin/roles') ?>">نقش‌ها</a></div><h1>ایجاد نقش جدید</h1></div></div>
<form class="card" method="post" action="<?= url('/admin/roles') ?>" style="max-width:760px">
    <?= csrf_field() ?>
    <div class="form-grid">
        <div class="field"><label>نام نقش <span class="req">*</span></label><input type="text" name="name" value="<?= e(old('name')) ?>" required placeholder="مثلاً: سرپرست آموزش نمایندگان"></div>
        <div class="field"><label>رنگ برچسب</label><select name="color"><?php foreach ($colors as $k => $v): ?><option value="<?= $k ?>"<?= selected($k, old('color', 'primary')) ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
        <div class="field full"><label>توضیحات</label><textarea name="description" rows="2"><?= e(old('description')) ?></textarea></div>
        <div class="field"><label>محدوده داده‌ها</label>
            <select name="data_scope"><?php foreach (App\Core\Labels::SCOPE as $k => $v): ?><option value="<?= $k ?>"<?= selected($k, old('data_scope', 'all')) ?>><?= e($v) ?></option><?php endforeach; ?></select>
            <div class="hint">«فقط افراد تحت مسئولیت»: کاربرانی که این فرد مسئول آموزش آن‌ها، مدیر واحد یا مسئول گروهشان است. «فقط موارد خودش»: دوره‌هایی که مدرس آن است.</div>
        </div>
        <div class="field"><label>کپی دسترسی‌ها از نقش</label><select name="copy_from"><option value="">— شروع از صفر —</option><?php foreach ($roles as $r): ?><option value="<?= (int)$r['id'] ?>"><?= e($r['name']) ?></option><?php endforeach; ?></select></div>
        <div class="field full"><label class="check"><input type="checkbox" name="is_learner" value="1"> نقش فراگیر (برای دسته‌بندی کاربران یادگیرنده)</label></div>
    </div>
    <div class="form-actions"><button class="btn btn-grad"><?= icon('plus') ?> ایجاد و تعیین دسترسی‌ها</button><a class="btn btn-ghost" href="<?= url('/admin/roles') ?>">انصراف</a></div>
</form>
