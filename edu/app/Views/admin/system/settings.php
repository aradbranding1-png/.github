<?php $segs = explode(',', (string)$s['registration_segments']); ?>
<div class="page-head"><div><h1>تنظیمات سامانه</h1><div class="sub">تنظیمات عمومی؛ اطلاعات محرمانه (رمز دیتابیس، کلیدها) فقط در فایل .env خارج از public_html نگهداری می‌شود</div></div></div>
<form method="post" action="<?= url('/admin/settings') ?>"><?= csrf_field() ?>
<fieldset style="border:0;padding:0;margin:0" <?= $editable ? '' : 'disabled' ?>>
<div class="grid g-2">
    <div class="card">
        <h3><?= icon('settings') ?> عمومی</h3>
        <div class="field"><label>نام سامانه</label><input type="text" name="site_name" value="<?= e($s['site_name']) ?>" required></div>
        <div class="field"><label>شعار</label><input type="text" name="site_tagline" value="<?= e($s['site_tagline']) ?>"></div>
        <div class="field"><label>صادرکننده گواهی</label><input type="text" name="certificate_issuer" value="<?= e($s['certificate_issuer']) ?>"></div>
        <div class="field"><label>حداکثر حجم آپلود هر فایل (مگابایت)</label><input type="number" name="max_upload_mb" value="<?= e($s['max_upload_mb']) ?>"><div class="hint">محدودیت upload_max_filesize و post_max_size در PHP نیز باید در DirectAdmin متناسب تنظیم شود (فعلی: <?= e(ini_get('upload_max_filesize')) ?>).</div></div>
    </div>
    <div class="card">
        <h3><?= icon('user-plus') ?> ثبت‌نام</h3>
        <label class="switch mb-2"><input type="checkbox" name="registration_enabled" value="1"<?= checked($s['registration_enabled'] === '1') ?>> ثبت‌نام آنلاین (فرم «ثبت‌نام کنید» در صفحه ورود) فعال باشد</label>
        <label class="switch mb-2"><input type="checkbox" name="registration_requires_approval" value="1"<?= checked($s['registration_requires_approval'] === '1') ?>> همه حساب‌های جدید نیاز به تأیید داشته باشند</label>
        <div class="hint mb-2">با روشن بودن تأیید، هر حساب جدید — از ثبت‌نام آنلاین، ورود با my، API، فایل CSV یا ساخته‌شده توسط همکارانی که مجوز «تأیید حساب» ندارند — تا زمان تأیید توسط افراد دارای مجوز «کاربران ← تأیید حساب‌های جدید» امکان ورود ندارد.</div>
        <div class="field"><label>گروه‌های مجاز برای ثبت‌نام</label><div class="chip-select"><?php foreach (['merchant' => 'تاجر', 'agent' => 'نماینده', 'employee' => 'کارمند (همیشه نیازمند تأیید)'] as $k => $t): ?><label><input type="checkbox" name="registration_segments[]" value="<?= $k ?>"<?= checked(in_array($k, $segs, true)) ?>><span><?= e($t) ?></span></label><?php endforeach; ?></div></div>
    </div>
    <div class="card">
        <h3><?= icon('clock') ?> اعتبار زمانی (فروش دوره)</h3>
        <label class="switch mb-2"><input type="checkbox" name="minutes_enabled" value="1"<?= checked(($s['minutes_enabled'] ?? '1') === '1') ?>> مشاهده درس‌ها بر اساس اعتبار زمانی (دقیقه) کاربر باشد</label>
        <div class="hint mb-2">هر درس هنگام اولین ورود به اندازه «مدت (دقیقه)» خودش از اعتبار فراگیر کم می‌کند. درس‌های بدون مدت و «پیش‌نمایش رایگان» مجانی‌اند. مدیران، مدرسان و همکاران دارای دسترسی درس‌ها معاف‌اند.</div>
        <div class="field"><label>متن راهنمای شارژ (به فراگیر نمایش داده می‌شود)</label><textarea name="minutes_charge_text" rows="2"><?= e($s['minutes_charge_text'] ?? '') ?></textarea></div>
    </div>
    <div class="card">
        <h3><?= icon('graduation-cap') ?> یادگیری و پایش</h3>
        <div class="field"><label>درصد مشاهده لازم برای تکمیل ویدیو/صوت</label><input type="number" name="video_complete_percent" min="50" max="100" value="<?= e($s['video_complete_percent']) ?>"></div>
        <div class="field"><label>تعداد روز بدون ورود برای «غیرفعال» محسوب شدن</label><input type="number" name="inactivity_days" value="<?= e($s['inactivity_days']) ?>"></div>
        <div class="field"><label>یادآوری مهلت (روز قبل از پایان)</label><input type="number" name="deadline_warning_days" value="<?= e($s['deadline_warning_days']) ?>"></div>
    </div>
    <?php if (is_root()): ?>
    <div class="card">
        <h3><?= icon('shield-check') ?> امنیت بروزرسانی (فقط مدیر کل)</h3>
        <label class="switch"><input type="checkbox" name="update_require_signature" value="1"<?= checked($s['update_require_signature'] === '1') ?>> فقط بسته‌های دارای امضای دیجیتال معتبر نصب شوند</label>
        <div class="hint">کلید عمومی در .env با نام UPDATE_PUBLIC_KEY تنظیم می‌شود (راهنما: docs/UPDATE.md).</div>
    </div>
    <?php endif; ?>
</div>
<?php if ($editable): ?><div class="form-actions mt-2"><button class="btn btn-grad"><?= icon('save') ?> ذخیره تنظیمات</button></div><?php endif; ?>
</fieldset>
</form>
