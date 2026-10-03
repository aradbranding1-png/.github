<?php
$v = fn($k, $d = '') => old($k, $e[$k] ?? $d);
$segs = $e && $e['segments'] ? explode(',', (string)$e['segments']) : [];
$oldSegs = old('segments', null);
if (is_array($oldSegs)) $segs = $oldSegs;
$dateVal = old('date', $e && $e['starts_at'] ? App\Core\Jalali::input($e['starts_at']) : '');
$timeVal = old('time', $e && $e['starts_at'] ? date('H:i', strtotime($e['starts_at'])) : ($type === 'meeting' ? '10:00' : '18:00'));
$dur = (int)($e['duration_minutes'] ?? 60);
$durHours = old('duration_hours', rtrim(rtrim(number_format($dur / 60, 2, '.', ''), '0'), '.'));
$copying = !$isEdit && $e;
?>
<div class="page-head"><div><div class="crumbs"><a href="<?= url('/admin/events/' . $type) ?>"><?= e($t['plural']) ?></a></div><h1><?= $isEdit ? 'ویرایش ' . e($t['label']) : ($copying ? 'کپی ' . e($t['label']) : e($t['label']) . ' جدید') ?></h1></div></div>
<form method="post" enctype="multipart/form-data" action="<?= url($isEdit ? '/admin/event/' . $e['id'] : '/admin/events/' . $type) ?>">
    <?= csrf_field() ?>
    <div class="grid g-main">
        <div class="stack">
            <div class="card">
                <h3><?= icon($t['icon']) ?> مشخصات</h3>
                <div class="field"><label>عنوان <span class="req">*</span></label><input type="text" name="title" value="<?= e($v('title')) ?>" required placeholder="<?= $type === 'webinar' ? 'مثلاً: وبینار مذاکره با مشتری خارجی' : ($type === 'workshop' ? 'مثلاً: کارگاه قیمت‌گذاری صادراتی' : 'مثلاً: میتینگ هفتگی تاجران') ?>"></div>
                <div class="field"><label>خلاصه (نمایش روی کارت)</label><input type="text" name="summary" value="<?= e($v('summary')) ?>" maxlength="500"></div>
                <div class="field"><label>توضیحات کامل</label><textarea name="description" rows="7"><?= e($v('description')) ?></textarea><div class="hint">متن ساده، HTML پایه یا متن خروجی هوش مصنوعی (Markdown).</div></div>
                <div class="field"><label>لینک ورود</label><input type="url" class="ltr" name="join_url" value="<?= e($v('join_url')) ?>" placeholder="https://…"><div class="hint">پس از ثبت‌نام<?= $type === 'meeting' ? ' (برای میتینگ: با اشتراک فعال)' : '' ?> به فراگیر نمایش داده می‌شود. از فهرست هم می‌توانید لینک را سریع عوض کنید.</div></div>
                <div class="field"><label>ارائه‌دهنده / میزبان (اختیاری)</label><input type="text" name="host_name" value="<?= e($v('host_name')) ?>"></div>
            </div>
            <div class="card">
                <h3><?= icon('calendar') ?> زمان برگزاری</h3>
                <div class="form-grid" style="grid-template-columns:repeat(auto-fit,minmax(170px,1fr))">
                    <div class="field"><label>تاریخ (شمسی) <span class="req">*</span></label><input type="text" class="ltr" name="date" value="<?= e($dateVal) ?>" placeholder="1405/07/10" required></div>
                    <div class="field"><label>ساعت شروع <span class="req">*</span></label><input type="text" class="ltr" name="time" value="<?= e($timeVal) ?>" placeholder="18:00" required></div>
                    <?php if ($type === 'webinar'): ?>
                        <div class="field"><label>مدت (ساعت) <span class="req">*</span></label><input type="text" inputmode="decimal" class="ltr" name="duration_hours" value="<?= e($durHours) ?>" placeholder="1"><div class="hint">همین مقدار از اعتبار وبینار کسر می‌شود؛ مثلاً ۱ یا ۱.۵</div></div>
                    <?php else: ?>
                        <div class="field"><label>مدت (دقیقه)</label><input type="number" name="duration_minutes" value="<?= e($v('duration_minutes', 60)) ?>"></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="stack">
            <div class="card">
                <h3><?= icon('users') ?> مخاطبان</h3>
                <div class="field"><label>نوع کاربر</label>
                    <div class="chip-select"><?php foreach (['merchant' => 'تاجران', 'agent' => 'نمایندگان', 'employee' => 'کارمندان'] as $k => $l): ?><label><input type="checkbox" name="segments[]" value="<?= $k ?>"<?= checked(!$segs || in_array($k, $segs, true)) ?>><span><?= $l ?></span></label><?php endforeach; ?></div>
                    <div class="hint">هیچ یا هر سه = همه. مثلاً فقط «تاجران» یا «کارمندان + تاجران».</div>
                </div>
                <div class="field"><label>محدود به گروه خاص (اختیاری)</label><select name="group_id"><option value="">—</option><?php foreach ($groups as $k => $n): ?><option value="<?= (int)$k ?>"<?= selected($k, $v('group_id')) ?>><?= e($n) ?></option><?php endforeach; ?></select></div>
                <div class="field"><label>وضعیت</label><select name="status"><option value="active"<?= selected('active', $v('status', 'active')) ?>>فعال (نمایش به فراگیران)</option><option value="inactive"<?= selected('inactive', $v('status')) ?>>غیرفعال</option></select></div>
            </div>
            <div class="card">
                <h3><?= icon('image') ?> تصویر کارت</h3>
                <?php if ($e && $e['image_file_id']): ?><img src="<?= e(image_url($e['image_file_id'], 640)) ?>" alt="" class="ev-prev"><?php if ($isEdit): ?><label class="check small"><input type="checkbox" name="remove_image" value="1"> حذف تصویر</label><?php else: ?><input type="hidden" name="keep_image" value="<?= (int)$e['image_file_id'] ?>"><?php endif; ?><?php endif; ?>
                <input type="file" name="image" accept="image/*"><div class="hint">افقی (مثلاً ۱۲۰۰×۶۷۵)</div>
            </div>
            <div class="card">
                <h3><?= icon('image') ?> بنر استوری</h3>
                <?php if ($e && $e['banner_file_id']): ?><img src="<?= e(image_url($e['banner_file_id'], 320)) ?>" alt="" class="ev-prev" style="max-width:160px"><?php if ($isEdit): ?><label class="check small"><input type="checkbox" name="remove_banner" value="1"> حذف بنر</label><?php else: ?><input type="hidden" name="keep_banner" value="<?= (int)$e['banner_file_id'] ?>"><?php endif; ?><?php endif; ?>
                <input type="file" name="banner" accept="image/*"><div class="hint">عمودی (۱۰۸۰×۱۹۲۰) برای استوری و صفحه جزئیات</div>
            </div>
            <div class="card"><button class="btn btn-grad btn-lg w-100"><?= icon('save') ?> ذخیره</button></div>
        </div>
    </div>
</form>
