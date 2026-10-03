<?php $fm = explode(',', (string)($ex['formats'] ?? 'text,file')); ?>
<div class="field"><label>عنوان تمرین</label><input type="text" name="title" value="<?= e($ex['title'] ?? '') ?>" required></div>
<div class="field"><label>شرح و دستورالعمل</label><textarea name="instructions" rows="4"><?= e($ex['instructions'] ?? '') ?></textarea></div>
<div class="field"><label>فرمت‌های مجاز پاسخ</label>
    <div class="chip-select"><?php foreach (['text' => 'متن', 'file' => 'فایل (PDF/Word/صوت)', 'image' => 'تصویر', 'video' => 'ویدیو'] as $k => $v): ?><label><input type="checkbox" name="formats[]" value="<?= $k ?>"<?= checked(in_array($k, $fm, true)) ?>><span><?= e($v) ?></span></label><?php endforeach; ?></div>
</div>
<div class="form-grid">
    <div class="field"><label>حداکثر نمره</label><input type="number" name="max_score" value="<?= e($ex['max_score'] ?? 100) ?>"></div>
    <div class="field"><label>نمره قبولی</label><input type="number" name="pass_score" value="<?= e($ex['pass_score'] ?? 60) ?>"></div>
    <div class="field"><label>مرتبط با درس</label><select name="lesson_id"><option value="">— کل دوره —</option><?php foreach ($lessons as $lo): ?><option value="<?= (int)$lo['id'] ?>"<?= selected($lo['id'], $ex['lesson_id'] ?? '') ?>><?= e($lo['title']) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label>وضعیت</label><select name="status"><option value="published"<?= selected('published', $ex['status'] ?? 'published') ?>>منتشر شده</option><option value="draft"<?= selected('draft', $ex['status'] ?? '') ?>>پیش‌نویس</option></select></div>
</div>
<label class="switch mb-2"><input type="checkbox" name="is_required" value="1"<?= checked($ex['is_required'] ?? 1) ?>> الزامی برای تکمیل دوره</label>
