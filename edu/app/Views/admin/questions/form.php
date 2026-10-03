<?php
$isEdit = $q !== null;
$v = fn($k, $d = '') => old($k, $q[$k] ?? $d);
$type = $v('type', 'single');
$oldTexts = old('opt_text', null);
if ($oldTexts !== null) { $opts = []; foreach ($oldTexts as $k => $t) $opts[] = ['text' => $t, 'is_correct' => in_array((string)$k, array_map('strval', (array)old('opt_correct', [])), true)]; }
else $opts = $options ?: [['text' => '', 'is_correct' => 1], ['text' => '', 'is_correct' => 0], ['text' => '', 'is_correct' => 0], ['text' => '', 'is_correct' => 0]];
?>
<?php $forExam = $forExam ?? null; ?>
<div class="page-head"><div><div class="crumbs"><?php if ($forExam): ?><a href="<?= url('/admin/exams/' . $forExam['id']) ?>#questions">آزمون «<?= e($forExam['title']) ?>»</a><?php else: ?><a href="<?= url('/admin/questions') ?>">بانک سؤال</a><?php endif; ?></div><h1><?= $isEdit ? 'ویرایش سؤال' : 'سؤال جدید' ?></h1></div></div>
<?php if ($forExam): ?><div class="alert alert-info"><?= icon('clipboard-check') ?><div>این سؤال در بانک سؤال ذخیره و <b>خودکار به آزمون «<?= e($forExam['title']) ?>»</b> اضافه می‌شود. این آزمون الان <?= fa((int)($examCount ?? 0)) ?> سؤال دارد. <a href="<?= url('/admin/exams/' . $forExam['id']) ?>#questions">بازگشت به آزمون</a></div></div><?php endif; ?>
<form method="post" action="<?= url($isEdit ? '/admin/questions/' . $q['id'] : '/admin/questions') ?>">
    <?= csrf_field() ?>
    <?php if ($forExam): ?><input type="hidden" name="exam_id" value="<?= (int)$forExam['id'] ?>"><?php endif; ?>
    <div class="grid g-main">
        <div class="card">
            <div class="field"><label>نوع سؤال</label><select name="type" data-qtype><?php foreach (App\Core\Labels::QTYPE as $k => $t): ?><option value="<?= $k ?>"<?= selected($k, $type) ?>><?= e($t) ?></option><?php endforeach; ?></select></div>
            <div class="field"><label>متن سؤال <span class="req">*</span></label><textarea name="text" rows="4" required><?= e($v('text')) ?></textarea></div>
            <div data-options-box>
                <label>گزینه‌ها <span class="faint small short-hint hide">(برای پاسخ کوتاه: همه پاسخ‌های قابل قبول را وارد کنید؛ مقایسه بدون حساسیت به حروف و فاصله انجام می‌شود)</span></label>
                <div data-options-list>
                    <?php foreach ($opts as $i => $o): ?>
                        <div class="flex mb-1" data-opt-row data-row-item>
                            <input data-opt-correct type="<?= $type === 'multiple' ? 'checkbox' : 'radio' ?>" name="opt_correct[]" value="<?= $i ?>"<?= checked(!empty($o['is_correct'])) ?> title="گزینه صحیح" style="width:20px;height:20px;accent-color:var(--success)">
                            <input type="text" name="opt_text[<?= $i ?>]" value="<?= e($o['text']) ?>" placeholder="متن گزینه">
                            <button class="btn btn-ghost btn-icon" data-remove-row type="button"><?= icon('x') ?></button>
                        </div>
                    <?php endforeach; ?>
                </div>
                <button class="btn btn-sm btn-outline" type="button" data-add-option><?= icon('plus') ?> افزودن گزینه</button>
                <template id="opt-template"><div class="flex mb-1" data-opt-row data-row-item><input data-opt-correct type="radio" name="opt_correct[]" value="__i__" style="width:20px;height:20px;accent-color:var(--success)"><input type="text" name="opt_text[__i__]" placeholder="متن گزینه"><button class="btn btn-ghost btn-icon" data-remove-row type="button">✕</button></div></template>
            </div>
            <div class="field mt-2"><label>توضیح پاسخ (پس از آزمون نمایش داده می‌شود)</label><textarea name="explanation" rows="2"><?= e($v('explanation')) ?></textarea></div>
        </div>
        <div class="stack">
            <div class="card">
                <input type="hidden" name="score" value="<?= e($v('score', 1)) ?>">
                <div class="field"><label>دشواری</label><select name="difficulty"><?php foreach (App\Core\Labels::DIFFICULTY as $k => $t): ?><option value="<?= $k ?>"<?= selected($k, $v('difficulty', 1)) ?>><?= e($t) ?></option><?php endforeach; ?></select></div>
                <div class="field"><label>دسته</label><select name="category_id"><option value="">—</option><?php foreach ($cats as $k => $t): ?><option value="<?= (int)$k ?>"<?= selected($k, $v('category_id', $isEdit ? '' : (array_search('عمومی', $cats, true) ?: ''))) ?>><?= e($t) ?></option><?php endforeach; ?></select></div>
                <div class="field"><label>سطح</label><select name="level_id"><option value="">—</option><?php foreach ($levels as $k => $t): ?><option value="<?= (int)$k ?>"<?= selected($k, $v('level_id')) ?>><?= e($t) ?></option><?php endforeach; ?></select></div>
                <div class="field"><label>برچسب‌ها (با کاما)</label><input type="text" name="tags" value="<?= e($v('tags')) ?>" placeholder="مذاکره,فروش"></div>
                <label class="switch"><input type="checkbox" name="is_active" value="1"<?= checked($v('is_active', 1)) ?>> فعال</label>
            </div>
            <div class="card"><button class="btn btn-grad btn-lg w-100"><?= icon('save') ?> <?= $forExam ? 'ذخیره و بازگشت به آزمون' : 'ذخیره سؤال' ?></button><?php if (!$isEdit): ?><button class="btn btn-outline w-100 mt-1" name="add_another" value="1"><?= icon('plus') ?> ذخیره و سؤال بعدی</button><?php endif; ?></div>
        </div>
    </div>
</form>
