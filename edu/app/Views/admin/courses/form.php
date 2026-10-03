<?php
$isEdit = $c !== null;
$v = fn($k, $d = '') => old($k, $c[$k] ?? $d);
?>
<div class="page-head"><div><div class="crumbs"><a href="<?= url('/admin/courses') ?>">دوره‌ها</a><?= $isEdit ? ' / <a href="' . url('/admin/courses/' . $c['id']) . '">' . e($c['title']) . '</a>' : '' ?></div><h1><?= $isEdit ? 'ویرایش دوره' : 'دوره جدید' ?></h1></div></div>
<form method="post" enctype="multipart/form-data" action="<?= url($isEdit ? '/admin/courses/' . $c['id'] : '/admin/courses') ?>">
    <?= csrf_field() ?>
    <div class="grid g-main">
        <div class="stack">
            <div class="card">
                <h3><?= icon('book-open') ?> اطلاعات دوره</h3>
                <div class="field"><label>عنوان <span class="req">*</span></label><input type="text" name="title" value="<?= e($v('title')) ?>" required></div>
                <div class="field"><label>خلاصه (نمایش در کارت دوره)</label><input type="text" name="summary" value="<?= e($v('summary')) ?>" maxlength="500"></div>
                <div class="field"><label>توضیحات کامل</label><textarea name="description" rows="7"><?= e($v('description')) ?></textarea><div class="hint">متن ساده، HTML پایه یا متن خروجی هوش مصنوعی (Markdown). علامت‌های ## تیتر، **بولد** و * بولت خودکار به قالب‌بندی تبدیل می‌شوند.</div></div>
                <div class="field"><label>سرفصل تفصیلی</label><textarea name="syllabus" rows="6"><?= e($v('syllabus')) ?></textarea></div>
            </div>
            <div class="card">
                <h3><?= icon('settings') ?> قوانین دوره</h3>
                <div class="form-grid">
                    <div class="field"><label>نوع آموزش</label><select name="training_type"><?php foreach (App\Core\Labels::TRAINING_TYPE as $k => $t): ?><option value="<?= $k ?>"<?= selected($k, $v('training_type', 'optional')) ?>><?= e($t) ?></option><?php endforeach; ?></select></div>
                    <div class="field"><label>حداقل نمره قبولی (٪)</label><input type="number" name="pass_score" min="0" max="100" value="<?= e($v('pass_score', 80)) ?>"><div class="hint">میانگین نمره آزمون‌های دوره باید به این حد برسد تا دوره تکمیل و گواهی صادر شود. اگر دوره آزمون نداشته باشد، اعمال نمی‌شود و دوره با تکمیل درس‌ها و تمرین‌های الزامی تکمیل می‌شود.</div></div>
                    <?php $autoDur = $c ? App\Core\DB::one("SELECT COUNT(*) n, COALESCE(SUM(duration_minutes), 0) m FROM lessons WHERE course_id = ? AND deleted_at IS NULL AND status = 'published' AND duration_minutes > 0", [(int)$c['id']]) : null; ?>
                    <?php if ($autoDur && (int)$autoDur['n']): ?>
                        <div class="field"><label>مدت دوره (دقیقه)</label><input type="number" name="duration_minutes" value="<?= (int)$autoDur['m'] ?>" readonly style="background:var(--surface-2)"><div class="hint"><?= icon('refresh-cw') ?> خودکار: جمع مدت <?= fa((int)$autoDur['n']) ?> درس منتشرشده (<?= e(App\Services\Credit::format((int)$autoDur['m'])) ?>)</div></div>
                    <?php else: ?>
                        <div class="field"><label>مدت تقریبی (دقیقه)</label><input type="number" name="duration_minutes" value="<?= e($v('duration_minutes')) ?>"><div class="hint">بعد از افزودن درس‌های منتشرشده، خودکار از جمع مدت درس‌ها محاسبه می‌شود.</div></div>
                    <?php endif; ?>
                    <div class="field"><label>کد دوره</label><input type="text" class="ltr" name="code" value="<?= e($v('code')) ?>"></div>
                    <div class="field"><label>تاریخ انتشار (شمسی)</label><input type="text" class="ltr" name="publish_at" value="<?= e(old('publish_at', App\Core\Jalali::input($c['publish_at'] ?? null))) ?>" placeholder="1405/07/01"></div>
                    <div class="field"><label>تاریخ انقضا (اختیاری)</label><input type="text" class="ltr" name="expire_at" value="<?= e(old('expire_at', App\Core\Jalali::input($c['expire_at'] ?? null))) ?>" placeholder="1405/12/29"></div>
                </div>
                <div class="flex flex-wrap gap-2">
                    <label class="switch"><input type="checkbox" name="is_sequential" value="1"<?= checked($v('is_sequential', 1)) ?>> درس‌ها به ترتیب باز شوند</label>
                    <label class="switch"><input type="checkbox" name="self_enroll" value="1"<?= checked($v('self_enroll', 1)) ?>> ثبت‌نام آزاد از کاتالوگ</label>
                    <label class="switch"><input type="checkbox" name="has_certificate" value="1"<?= checked($v('has_certificate', 0)) ?>> صدور گواهی</label>
                </div>
                <div class="field mt-2"><label>اعتبار گواهی (ماه، خالی = دائمی)</label><input type="number" name="certificate_validity_months" value="<?= e($v('certificate_validity_months')) ?>" style="max-width:200px"></div>
            </div>
        </div>
        <div class="stack">
            <div class="card">
                <h3><?= icon('image') ?> تصویر دوره</h3>
                <?php if ($isEdit && $c['image_file_id']): ?><img src="<?= e(image_url($c['image_file_id'], 640)) ?>" alt="" style="border-radius:12px;margin-bottom:.5rem"><input type="hidden" name="image_file_id" value="<?= (int)$c['image_file_id'] ?>"><?php endif; ?>
                <input type="file" name="image" accept="image/*">
            </div>
            <div class="card">
                <h3><?= icon('target') ?> دسته‌بندی و مخاطب</h3>
                <div class="field"><label>موضوع</label><select name="category_id"><option value="">—</option><?php foreach ($cats as $ct): ?><option value="<?= (int)$ct['id'] ?>"<?= selected($ct['id'], $v('category_id')) ?>><?= $ct['parent_id'] ? '— ' : '' ?><?= e($ct['name']) ?></option><?php endforeach; ?></select></div>
                <div class="field"><label>گروه هدف</label><select name="target_segment"><option value="">همه</option><?php foreach (['merchant', 'employee', 'agent'] as $s): ?><option value="<?= $s ?>"<?= selected($s, $v('target_segment')) ?>><?= e(label('segment', $s)) ?></option><?php endforeach; ?></select></div>
                <div class="field"><label>محدود به گروه خاص</label><select name="group_id"><option value="">—</option><?php foreach ($groups as $g): ?><option value="<?= (int)$g['id'] ?>"<?= selected($g['id'], $v('group_id')) ?>><?= e($g['name']) ?></option><?php endforeach; ?></select></div>
                <div class="field"><label>سطح</label><select name="level_id"><option value="">—</option><?php foreach ($levels as $l): ?><option value="<?= (int)$l['id'] ?>"<?= selected($l['id'], $v('level_id')) ?>><?= e($l['name']) ?></option><?php endforeach; ?></select></div>
                <div class="field"><label>مدرس</label><select name="instructor_id"><option value="">—</option><?php foreach ($instructors as $i): ?><option value="<?= (int)$i['id'] ?>"<?= selected($i['id'], $v('instructor_id')) ?>><?= e(full_name($i)) ?></option><?php endforeach; ?></select></div>
                <div class="field"><label>پیش‌نیازها</label><select name="prerequisites[]" multiple size="6"><?php foreach ($allCourses as $ac): ?><option value="<?= (int)$ac['id'] ?>"<?= selected($ac['id'], $prereqs) ?>><?= e($ac['title']) ?></option><?php endforeach; ?></select><div class="hint">تا تکمیل پیش‌نیازها، دوره برای فراگیر قفل است.</div></div>
            </div>
            <div class="card"><button class="btn btn-grad btn-lg w-100"><?= icon('save') ?> ذخیره دوره</button></div>
        </div>
    </div>
</form>
