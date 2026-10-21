<?php
$segs = explode(',', (string)$s['registration_segments']);
$sections = [
    'general' => ['settings', 'عمومی', 'نام سامانه، صادرکنندهٔ گواهی و آپلود فایل', 'primary'],
    'signup' => ['user-plus', 'ثبت‌نام و تأیید حساب', 'چه کسانی و چطور حساب بسازند', 'success'],
    'credit' => ['clock', 'اعتبار زمانی', 'فروش دوره بر اساس دقیقهٔ مشاهده', 'warning'],
    'learning' => ['graduation-cap', 'یادگیری و پایش', 'تکمیل درس، غیرفعال‌ها و یادآوری مهلت', 'info'],
    'cheat' => ['triangle-alert', 'کشف تقلب در تکمیل درس', 'تشخیص جلو زدن و تکمیل بدون دیدن درس', 'danger'],
];
if (is_root()) $sections['security'] = ['shield-check', 'امنیت بروزرسانی', 'فقط مدیر کل', 'purple'];
$head = function (string $id, string $action = '') use ($sections) { [$ic, $t, $d, $tone] = $sections[$id]; return '<div class="set-head"><span class="set-bubble tone-' . $tone . '">' . icon($ic) . '</span><div class="grow"><h2>' . e($t) . '</h2><div class="sub">' . e($d) . '</div></div>' . $action . '</div>'; };
?>
<div class="page-head"><div><h1><?= icon('settings') ?> تنظیمات سامانه</h1><div class="sub">تنظیمات عمومی سامانه. اطلاعات محرمانه (رمز دیتابیس و کلیدها) فقط در فایل ‎.env‎ خارج از public_html نگهداری می‌شود.</div></div></div>
<form method="post" action="<?= url('/admin/settings') ?>" class="set-layout"><?= csrf_field() ?>
<aside class="set-nav" aria-label="بخش‌ها">
    <?php foreach ($sections as $id => [$ic, $t]): ?><a href="#<?= $id ?>" data-set-link="<?= $id ?>"><?= icon($ic) ?><span><?= e($t) ?></span></a><?php endforeach; ?>
</aside>
<fieldset class="set-main" <?= $editable ? '' : 'disabled' ?>>
    <?php if (!$editable): ?><div class="alert alert-info"><?= icon('lock') ?> شما فقط اجازهٔ مشاهدهٔ تنظیمات را دارید.</div><?php endif; ?>

    <section class="card set-sec" id="general"><?= $head('general') ?>
        <div class="set-grid">
            <div class="field"><label>نام سامانه</label><input type="text" name="site_name" value="<?= e($s['site_name']) ?>" required></div>
            <div class="field"><label>شعار</label><input type="text" name="site_tagline" value="<?= e($s['site_tagline']) ?>"></div>
            <div class="field"><label>صادرکنندهٔ گواهی</label><input type="text" name="certificate_issuer" value="<?= e($s['certificate_issuer']) ?>"><div class="hint">روی گواهی‌های پایان دوره چاپ می‌شود.</div></div>
            <div class="field"><label>حداکثر حجم هر فایل آپلودی</label><div class="input-unit"><input type="number" name="max_upload_mb" value="<?= e($s['max_upload_mb']) ?>"><span>مگابایت</span></div><div class="hint">محدودیت PHP در هاست هم باید متناسب باشد (فعلی: <span class="ltr"><?= e(ini_get('upload_max_filesize')) ?></span>).</div></div>
        </div>
    </section>

    <section class="card set-sec" id="signup"><?= $head('signup') ?>
        <div class="set-row"><div><b>ثبت‌نام آنلاین</b><div class="hint">فرم «ثبت‌نام کنید» در صفحهٔ ورود نمایش داده شود.</div></div><label class="switch"><input type="checkbox" name="registration_enabled" value="1"<?= checked($s['registration_enabled'] === '1') ?>></label></div>
        <div class="set-row"><div><b>تأیید همهٔ حساب‌های جدید</b><div class="hint">هر حساب جدید (ثبت‌نام آنلاین، API، فایل CSV یا ساخته‌شده توسط همکاران بدون مجوز تأیید) تا تأیید توسط افراد دارای مجوز «تأیید حساب‌های جدید» امکان ورود ندارد.</div></div><label class="switch"><input type="checkbox" name="registration_requires_approval" value="1"<?= checked($s['registration_requires_approval'] === '1') ?>></label></div>
        <div class="field mt-2"><label>گروه‌های مجاز برای ثبت‌نام</label><div class="chip-select"><?php foreach (['merchant' => 'تاجر', 'agent' => 'نماینده', 'employee' => 'کارمند (همیشه نیازمند تأیید)'] as $k => $t): ?><label><input type="checkbox" name="registration_segments[]" value="<?= $k ?>"<?= checked(in_array($k, $segs, true)) ?>><span><?= e($t) ?></span></label><?php endforeach; ?></div></div>
    </section>

    <section class="card set-sec" id="credit"><?= $head('credit') ?>
        <div class="set-row"><div><b>مشاهدهٔ درس‌ها با اعتبار زمانی</b><div class="hint">هر درس هنگام اولین ورود به اندازهٔ «مدت (دقیقه)» خودش از اعتبار فراگیر کم می‌کند. درس‌های بدون مدت و «پیش‌نمایش رایگان» مجانی‌اند. مدیران، مدرسان و همکاران دارای دسترسی درس‌ها معاف‌اند.</div></div><label class="switch"><input type="checkbox" name="minutes_enabled" value="1"<?= checked(($s['minutes_enabled'] ?? '1') === '1') ?>></label></div>
        <div class="field mt-2"><label>متن راهنمای شارژ <span class="faint">(به فراگیر نمایش داده می‌شود)</span></label><textarea name="minutes_charge_text" rows="2"><?= e($s['minutes_charge_text'] ?? '') ?></textarea></div>
    </section>

    <section class="card set-sec" id="learning"><?= $head('learning') ?>
        <div class="set-grid g3">
            <div class="field"><label>مشاهدهٔ لازم برای تکمیل ویدیو/صوت</label><div class="input-unit"><input type="number" name="video_complete_percent" min="50" max="100" value="<?= e($s['video_complete_percent']) ?>"><span>٪</span></div><div class="hint">دکمهٔ «تکمیل درس» از این مقدار پخش واقعی فعال می‌شود.</div></div>
            <div class="field"><label>غیرفعال محسوب شدن بعد از</label><div class="input-unit"><input type="number" name="inactivity_days" value="<?= e($s['inactivity_days']) ?>"><span>روز بدون ورود</span></div></div>
            <div class="field"><label>یادآوری مهلت دوره</label><div class="input-unit"><input type="number" name="deadline_warning_days" value="<?= e($s['deadline_warning_days']) ?>"><span>روز قبل از پایان</span></div></div>
        </div>
    </section>

    <section class="card set-sec" id="cheat"><?= $head('cheat', can('reports.report') || can('courses.report') ? '<a class="btn btn-sm btn-outline" href="' . url('/admin/reports/suspicious') . '">' . icon('triangle-alert') . ' فهرست تکمیل‌های مشکوک</a>' : '') ?>
        <div class="set-row"><div><b>کشف تقلب فعال باشد</b><div class="hint">جلو زدن فایل، تکمیل زودتر از زمان واقعی درس و مطالعهٔ خیلی کوتاه تشخیص داده می‌شود و به خود فرد و مدیران هشدار داده می‌شود.</div></div><label class="switch"><input type="checkbox" name="completion_audit" value="1"<?= checked(($s['completion_audit'] ?? '1') !== '0') ?>></label></div>
        <div class="set-grid mt-2">
            <div class="field"><label>حداقل پخش واقعی ویدیو/صوت</label><div class="input-unit"><input type="number" name="completion_audit_pct" min="20" max="95" value="<?= e($s['completion_audit_pct'] ?? '60') ?>"><span>٪</span></div><div class="hint">کمتر از این مقدار (جلو زدن حساب نمی‌شود) = تکمیل مشکوک؛ آزمون و تمرین آن درس تا دیدن دوباره باز نمی‌شود. بین ۲۰ تا ۹۵.</div></div>
        </div>
    </section>

    <?php if (is_root()): ?>
    <section class="card set-sec" id="security"><?= $head('security') ?>
        <div class="set-row"><div><b>فقط بسته‌های دارای امضای دیجیتال نصب شوند</b><div class="hint">کلید عمومی در ‎.env‎ با نام UPDATE_PUBLIC_KEY تنظیم می‌شود (راهنما: docs/UPDATE.md).</div></div><label class="switch"><input type="checkbox" name="update_require_signature" value="1"<?= checked($s['update_require_signature'] === '1') ?>></label></div>
    </section>
    <?php endif; ?>

    <?php if ($editable): ?><div class="set-save"><span class="faint small"><?= icon('info') ?> تغییرات بعد از ذخیره برای همه اعمال می‌شود.</span><button class="btn btn-grad"><?= icon('save') ?> ذخیرهٔ تنظیمات</button></div><?php endif; ?>
</fieldset>
</form>
