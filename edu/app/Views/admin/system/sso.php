<div class="page-head"><div><h1>اتصال SSO به my.aradbranding.me</h1><div class="sub">کاربران my بدون ثبت‌نام مجدد وارد سامانه آموزش می‌شوند. اتصال حساب‌ها فقط بر اساس شناسه یکتای my انجام می‌شود.</div></div>
<a class="btn btn-outline" href="<?= url('/admin/sso', ['probe' => 1]) ?>"><?= icon('activity') ?> تست دسترسی به my</a></div>
<?php if ($probe !== null): ?><div class="alert alert-<?= $probe === true ? 'success' : 'warning' ?>"><?= icon($probe === true ? 'circle-check' : 'triangle-alert') ?> <?= $probe === true ? 'سرور my در دسترس است.' : e($probe) ?></div><?php endif; ?>
<div class="grid g-4 mb-3">
    <div class="card stat tone-<?= $s['sso_enabled'] === '1' && !$errors ? 'success' : 'warning' ?>"><div class="bubble"><?= icon('fingerprint') ?></div><div><div class="v small fw-b"><?= $s['sso_enabled'] === '1' ? ($errors ? 'فعال (ناقص)' : 'فعال') : 'غیرفعال' ?></div><div class="l">وضعیت SSO</div></div></div>
    <div class="card stat tone-primary"><div class="bubble"><?= icon('link') ?></div><div><div class="v"><?= nf($linked) ?></div><div class="l">حساب متصل به my</div></div></div>
    <div class="card" style="grid-column:span 2"><div class="small muted">آدرس بازگشت (Redirect / Callback URL) — این آدرس را به تیم my بدهید:</div><div class="flex mt-1"><code class="ltr grow" style="background:var(--surface-3);padding:.4rem .6rem;border-radius:8px"><?= e($callback) ?></code><button class="btn btn-sm btn-outline" data-copy="<?= e($callback) ?>"><?= icon('copy') ?></button></div></div>
</div>
<?php if ($errors && $s['sso_enabled'] === '1'): ?><div class="alert alert-warning"><?= icon('triangle-alert') ?><div><b>پیکربندی ناقص:</b> <?= e(implode('، ', $errors)) ?></div></div><?php endif; ?>
<form method="post" action="<?= url('/admin/sso') ?>"><?= csrf_field() ?>
<fieldset style="border:0;padding:0;margin:0" <?= $editable ? '' : 'disabled' ?>>
<div class="grid g-2">
    <div class="card">
        <h3><?= icon('settings') ?> روش اتصال</h3>
        <label class="switch mb-2"><input type="checkbox" name="sso_enabled" value="1"<?= checked($s['sso_enabled'] === '1') ?>> SSO فعال باشد</label>
        <div class="field"><label>روش</label><select name="sso_mode"><option value="oauth2"<?= selected('oauth2', $s['sso_mode']) ?>>OAuth2 Authorization Code + PKCE (پیشنهادی — Laravel Passport / OIDC)</option><option value="jwt"<?= selected('jwt', $s['sso_mode']) ?>>توکن امضاشده JWT (HS256) از سمت my</option></select></div>
        <div class="field"><label>آدرس Authorize / صفحه ورود my</label><input class="ltr" type="url" name="sso_authorize_url" value="<?= e($s['sso_authorize_url']) ?>" placeholder="https://my.aradbranding.me/oauth/authorize"></div>
        <div class="field"><label>آدرس Token (فقط OAuth2)</label><input class="ltr" type="url" name="sso_token_url" value="<?= e($s['sso_token_url']) ?>" placeholder="https://my.aradbranding.me/oauth/token"></div>
        <div class="field"><label>آدرس User Info / دریافت اطلاعات به‌روز کاربر</label><input class="ltr" type="text" name="sso_userinfo_url" value="<?= e($s['sso_userinfo_url']) ?>" placeholder="https://my.aradbranding.me/api/user"><div class="hint">در حالت JWT اختیاری است؛ اگر {id} در آدرس باشد با شناسه کاربر جایگزین می‌شود و با MY_API_KEY فراخوانی می‌شود.</div></div>
        <div class="field"><label>آدرس Logout my (اختیاری)</label><input class="ltr" type="url" name="sso_logout_url" value="<?= e($s['sso_logout_url']) ?>"></div>
        <div class="field"><label>Scopes</label><input class="ltr" type="text" name="sso_scopes" value="<?= e($s['sso_scopes']) ?>"></div>
        <div class="field"><label>متن دکمه ورود</label><input type="text" name="sso_button_label" value="<?= e($s['sso_button_label']) ?>"></div>
    </div>
    <div class="stack">
        <div class="card">
            <h3><?= icon('list-tree') ?> نگاشت فیلدها (مسیر نقطه‌ای در JSON پاسخ my)</h3>
            <div class="form-grid">
                <?php foreach (['id' => 'شناسه یکتا *', 'first_name' => 'نام', 'last_name' => 'نام خانوادگی', 'mobile' => 'موبایل', 'email' => 'ایمیل', 'avatar' => 'آدرس عکس پروفایل', 'status' => 'وضعیت حساب'] as $k => $t): ?>
                    <div class="field"><label><?= e($t) ?></label><input class="ltr" type="text" name="sso_map_<?= $k ?>" value="<?= e($s['sso_map_' . $k]) ?>"></div>
                <?php endforeach; ?>
                <div class="field"><label>مقادیر «فعال» برای وضعیت</label><input class="ltr" type="text" name="sso_active_values" value="<?= e($s['sso_active_values']) ?>"></div>
            </div>
            <div class="hint">مثال: اگر پاسخ my به شکل {"data":{"user":{"id":5}}} است، مقدار «data.user.id» را وارد کنید.</div>
        </div>
        <div class="card">
            <h3><?= icon('user-plus') ?> رفتار حساب‌ها</h3>
            <label class="switch mb-1"><input type="checkbox" name="sso_auto_register" value="1"<?= checked($s['sso_auto_register'] === '1') ?>> ایجاد خودکار حساب برای کاربران جدید my</label>
            <label class="switch mb-1"><input type="checkbox" name="sso_sync_avatar" value="1"<?= checked($s['sso_sync_avatar'] === '1') ?>> همگام‌سازی عکس پروفایل از my (عکس آپلودی در edu بازنویسی نمی‌شود)</label>
            <div class="field mt-1"><label>نوع پیش‌فرض کاربران جدید</label><select name="sso_default_segment"><?php foreach (['merchant', 'employee', 'agent'] as $sg): ?><option value="<?= $sg ?>"<?= selected($sg, $s['sso_default_segment']) ?>><?= e(label('segment_one', $sg)) ?></option><?php endforeach; ?></select></div>
            <div class="alert alert-info small mt-1"><?= icon('shield-check') ?><div>اگر حسابی با همان موبایل/ایمیل در edu وجود داشته باشد، اتصال خودکار انجام نمی‌شود؛ کاربر باید با رمز حساب edu مالکیت را تأیید کند (جلوگیری از اتصال اشتباه و حساب تکراری).</div></div>
        </div>
        <div class="card">
            <h3><?= icon('key-round') ?> اطلاعات محرمانه (.env)</h3>
            <?php foreach ($env as $k => $set): ?><div class="flex between small" style="padding:.3rem 0"><code class="ltr"><?= e($k) ?></code><?= $set ? '<span class="badge badge-success">تنظیم شده</span>' : '<span class="badge badge-gray">خالی</span>' ?></div><?php endforeach; ?>
            <div class="hint">این مقادیر هرگز در دیتابیس ذخیره یا در پنل نمایش داده نمی‌شوند. آن‌ها را در فایل .env (خارج از public_html) وارد کنید.</div>
        </div>
    </div>
</div>
<?php if ($editable): ?><div class="form-actions mt-2"><button class="btn btn-grad"><?= icon('save') ?> ذخیره تنظیمات SSO</button></div><?php endif; ?>
</fieldset>
</form>
