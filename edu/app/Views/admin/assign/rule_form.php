<?php
$fieldLabels = App\Services\Targeting::FIELDS;
$valueSelect = function (string $name, string $selected) use ($options, $fieldLabels): string {
    $h = '<select name="' . e($name) . '" required><option value="">— انتخاب —</option>';
    foreach ($options as $field => $opts) {
        $h .= '<optgroup label="' . e($fieldLabels[$field]) . '">';
        foreach ($opts as $id => $t) { $val = $field . ':' . $id; $h .= '<option value="' . e($val) . '"' . selected($val, $selected) . '>' . e($t) . '</option>'; }
        $h .= '</optgroup>';
    }
    return $h . '</select>';
};
$ro = $rule && !can('rules.edit');
?>
<div class="page-head"><div><div class="crumbs"><a href="<?= url('/admin/rules') ?>">قوانین تخصیص خودکار</a></div><h1><?= $rule ? e($rule['name']) : 'قانون جدید' ?></h1><?php if ($preview !== null): ?><div class="sub">در حال حاضر <b><?= fa($preview) ?></b> نفر با این قانون مطابقت دارند.</div><?php endif; ?></div></div>
<form class="card" method="post" action="<?= url($rule ? '/admin/rules/' . $rule['id'] : '/admin/rules') ?>" style="max-width:900px"><?= csrf_field() ?>
<fieldset style="border:0;padding:0;margin:0" <?= $ro ? 'disabled' : '' ?>>
    <div class="field"><label>نام قانون</label><input type="text" name="name" value="<?= e($rule['name'] ?? '') ?>" required placeholder="مثلاً: آموزش‌های بدو ورود کارشناسان مالی"></div>
    <fieldset><legend>اگر</legend>
        <div class="field"><select name="match_type" style="max-width:260px"><option value="all"<?= selected('all', $rule['match_type'] ?? 'all') ?>>همه شرایط زیر برقرار باشد (و)</option><option value="any"<?= selected('any', $rule['match_type'] ?? '') ?>>حداقل یکی برقرار باشد (یا)</option></select></div>
        <div id="conds">
        <?php foreach ($conds as $i => $c): ?>
            <div class="flex mb-1" data-row-item>
                <select name="cond[<?= $i ?>][op]" style="width:130px"><option value="is"<?= selected('is', $c['op']) ?>>برابر است با</option><option value="is_not"<?= selected('is_not', $c['op']) ?>>نیست</option></select>
                <?= $valueSelect('cond[' . $i . '][value]', $c['field'] . ':' . $c['value']) ?>
                <button class="btn btn-ghost btn-icon" type="button" data-remove-row><?= icon('x') ?></button>
            </div>
        <?php endforeach; ?>
        </div>
        <template id="cond-tpl"><div class="flex mb-1" data-row-item><select name="cond[__i__][op]" style="width:130px"><option value="is">برابر است با</option><option value="is_not">نیست</option></select><?= $valueSelect('cond[__i__][value]', '') ?><button class="btn btn-ghost btn-icon" type="button" data-remove-row>✕</button></div></template>
        <button class="btn btn-sm btn-outline" type="button" data-add-row="cond-tpl" data-into="#conds"><?= icon('plus') ?> افزودن شرط</button>
    </fieldset>
    <fieldset><legend>آنگاه تخصیص بده</legend>
        <div class="form-grid">
            <div class="field"><label>دوره</label><select name="course_id"><option value="">—</option><?php foreach ($courses as $k => $t): ?><option value="<?= (int)$k ?>"<?= selected($k, $rule['course_id'] ?? '') ?>><?= e($t) ?></option><?php endforeach; ?></select></div>
            <div class="field"><label>یا مسیر آموزشی</label><select name="path_id"><option value="">—</option><?php foreach ($paths as $k => $t): ?><option value="<?= (int)$k ?>"<?= selected($k, $rule['path_id'] ?? '') ?>><?= e($t) ?></option><?php endforeach; ?></select></div>
            <div class="field"><label>نوع آموزش</label><select name="training_type"><?php foreach (App\Core\Labels::TRAINING_TYPE as $k => $t): ?><option value="<?= $k ?>"<?= selected($k, $rule['training_type'] ?? 'mandatory') ?>><?= e($t) ?></option><?php endforeach; ?></select></div>
            <div class="field"><label>مهلت (روز پس از تخصیص)</label><input type="number" name="due_days" value="<?= e($rule['due_days'] ?? '') ?>"></div>
        </div>
    </fieldset>
    <div class="flex flex-wrap gap-2 mb-2"><label class="switch"><input type="checkbox" name="is_active" value="1"<?= checked($rule['is_active'] ?? 1) ?>> قانون فعال است</label><?php if (can('rules.run')): ?><label class="switch"><input type="checkbox" name="run_now" value="1" checked> پس از ذخیره روی کاربران فعلی اجرا شود</label><?php endif; ?></div>
    <button class="btn btn-grad"><?= icon('save') ?> ذخیره قانون</button>
</fieldset>
</form>
