<?php
$fm = explode(',', (string)($ex['formats'] ?? 'text'));
$fmtList = [
    'text'  => ['متن', 'message-square', 'نوشتن پاسخ در سامانه'],
    'audio' => ['صوت', 'music', 'mp3 · m4a · wav · ogg'],
    'file'  => ['Word/PDF', 'file-text', 'doc · docx · pdf'],
    'image' => ['تصویر', 'image', 'jpg · png · webp'],
    'video' => ['ویدیو', 'video', 'mp4 · webm'],
];
$num = fn($v) => str_contains((string)$v, '.') ? rtrim(rtrim((string)$v, '0'), '.') : (string)$v;
?>
<div class="exf">
    <div class="exf-sec">
        <div class="exf-h"><span>۱</span> محتوای تمرین</div>
        <div class="field"><label>عنوان تمرین</label><input type="text" name="title" value="<?= e($ex['title'] ?? '') ?>" required placeholder="مثلاً: تمرین صوتی — معرفی محصول به مشتری"></div>
        <div class="field mb-0"><label>شرح و دستورالعمل</label><textarea name="instructions" rows="5" data-rich><?= e($ex['instructions'] ?? '') ?></textarea></div>
    </div>
    <div class="exf-sec">
        <div class="exf-h"><span>۲</span> فراگیر پاسخ را چطور بفرستد؟ <small>یک یا چند مورد</small></div>
        <div class="exf-fmts">
            <?php foreach ($fmtList as $k => [$t, $ic, $sub]): ?>
                <label class="exf-fmt" title="<?= e($sub) ?>"><input type="checkbox" name="formats[]" value="<?= $k ?>"<?= checked(in_array($k, $fm, true)) ?>>
                    <span class="exf-card"><span class="exf-ic"><?= icon($ic) ?></span><b><?= e($t) ?></b><span class="exf-tick"><?= icon('check') ?></span></span>
                </label>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="exf-sec">
        <div class="exf-h"><span>۳</span> نمره و تنظیمات</div>
        <div class="form-grid">
            <div class="field"><label>حداکثر نمره</label><input type="number" step="any" min="0" name="max_score" value="<?= e($num($ex['max_score'] ?? 100)) ?>"></div>
            <div class="field"><label>نمره قبولی</label><input type="number" step="any" min="0" name="pass_score" value="<?= e($num($ex['pass_score'] ?? 60)) ?>"></div>
            <div class="field mb-0"><label>مرتبط با درس</label><select name="lesson_id"><option value="">— کل دوره —</option><?php foreach ($lessons as $lo): ?><option value="<?= (int)$lo['id'] ?>"<?= selected($lo['id'], $ex['lesson_id'] ?? '') ?>><?= e($lo['title']) ?></option><?php endforeach; ?></select></div>
            <div class="field mb-0"><label>وضعیت</label><select name="status"><option value="published"<?= selected('published', $ex['status'] ?? 'published') ?>>منتشر شده</option><option value="draft"<?= selected('draft', $ex['status'] ?? '') ?>>پیش‌نویس</option></select></div>
        </div>
        <label class="exf-req switch"><input type="checkbox" name="is_required" value="1"<?= checked($ex['is_required'] ?? 1) ?>><span><b>الزامی برای تکمیل دوره</b><small>تا این تمرین پذیرفته نشود، دوره تکمیل‌شده حساب نمی‌شود.</small></span></label>
    </div>
</div>
