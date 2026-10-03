<?php $title = 'نصب سامانه آموزش آراد برندینگ'; ?>
<div style="max-width:980px;margin:2rem auto;padding:0 1rem">
    <div class="card hero mb-3">
        <div class="flex gap-2">
            <div class="sb-logo is-img" style="width:56px;height:56px"><img src="<?= asset('img/icons/icon-96.png') ?>" alt="آراد برندینگ" width="56" height="56"></div>
            <div>
                <h1 class="mb-0">نصب سامانه جامع آموزش آراد برندینگ</h1>
                <div class="muted">نسخه <?= e(app_version()) ?> — نصب یک‌مرحله‌ای روی DirectAdmin</div>
            </div>
        </div>
    </div>

    <?php if ($done): ?>
        <div class="card text-center" style="padding:3rem">
            <div class="ring ring-success" style="width:90px;height:90px;margin:0 auto 1rem"><svg viewBox="0 0 36 36"><circle class="ring-bg" cx="18" cy="18" r="15.9155"/><circle class="ring-fg" cx="18" cy="18" r="15.9155" stroke-dasharray="100 100"/></svg><span><?= icon('check') ?></span></div>
            <h2>نصب با موفقیت انجام شد</h2>
            <p class="muted">جداول دیتابیس ایجاد شد، نقش‌ها و دسترسی‌های پیش‌فرض ساخته شد و حساب مدیر کل (با تیک آبی) فعال است.<br>برای امنیت بیشتر، مسیر نصب اکنون به صورت خودکار غیرفعال شده است.</p>
            <a class="btn btn-grad btn-lg" href="<?= url('/login') ?>"><?= icon('log-in') ?> ورود به سامانه</a>
        </div>
    <?php else: ?>
        <?php foreach ($errors as $err): ?><div class="alert alert-danger"><?= icon('circle-x') ?><div><?= e($err) ?></div></div><?php endforeach; ?>

        <div class="grid g-main">
            <form method="post" action="<?= e(base_url() . '/index.php?r=/install') ?>" class="card" autocomplete="off">
                <?= csrf_field() ?>
                <input type="hidden" name="pretty" value="0">
                <h3 class="flex"><?= icon('globe') ?> آدرس سامانه</h3>
                <div class="field"><label>آدرس کامل (APP_URL) <span class="req">*</span></label><input class="ltr" type="url" name="app_url" required value="<?= e(old('app_url', $suggestUrl)) ?>"><div class="hint">مثال: https://edu.aradbranding.me</div></div>
                <div class="field"><label>نام سامانه <span class="req">*</span></label><input type="text" name="site_name" required value="<?= e(old('site_name', 'سامانه آموزش آراد برندینگ')) ?>"></div>
                <div class="field"><label>آدرس‌های تمیز (mod_rewrite)</label><span class="badge badge-gray" data-rewrite-test="<?= e(base_url() . '/__rewrite_test') ?>">در حال بررسی…</span></div>

                <h3 class="flex mt-2"><?= icon('database') ?> دیتابیس MySQL / MariaDB</h3>
                <div class="form-grid">
                    <div class="field"><label>میزبان</label><input class="ltr" type="text" name="db_host" value="<?= e(old('db_host', 'localhost')) ?>" required></div>
                    <div class="field"><label>پورت</label><input class="ltr" type="number" name="db_port" value="<?= e(old('db_port', '3306')) ?>" required></div>
                    <div class="field"><label>نام دیتابیس</label><input class="ltr" type="text" name="db_name" value="<?= e(old('db_name')) ?>" required></div>
                    <div class="field"><label>نام کاربری</label><input class="ltr" type="text" name="db_user" value="<?= e(old('db_user')) ?>" required></div>
                    <div class="field full"><label>رمز عبور دیتابیس</label><input class="ltr" type="password" name="db_pass" autocomplete="new-password"></div>
                </div>

                <h3 class="flex mt-2"><?= icon('crown') ?> حساب مدیر کل (ROOT)</h3>
                <div class="form-grid">
                    <div class="field"><label>نام</label><input type="text" name="first_name" value="<?= e(old('first_name')) ?>" required></div>
                    <div class="field"><label>نام خانوادگی</label><input type="text" name="last_name" value="<?= e(old('last_name')) ?>" required></div>
                    <div class="field"><label>موبایل (نام کاربری ورود)</label><input class="ltr" type="tel" name="mobile" value="<?= e(old('mobile')) ?>" placeholder="09xxxxxxxxx" required></div>
                    <div class="field"><label>ایمیل</label><input class="ltr" type="email" name="email" value="<?= e(old('email')) ?>"></div>
                    <div class="field"><label>رمز عبور</label><input class="ltr" type="password" name="password" autocomplete="new-password" required><div class="hint">حداقل ۸ کاراکتر، شامل حرف و عدد</div></div>
                    <div class="field"><label>تکرار رمز عبور</label><input class="ltr" type="password" name="password_confirmation" autocomplete="new-password" required></div>
                </div>
                <div class="form-actions"><button class="btn btn-grad btn-lg" type="submit" <?= $blocking ? 'disabled' : '' ?>><?= icon('rocket') ?> نصب سامانه</button></div>
            </form>

            <div class="card">
                <h3 class="flex"><?= icon('server') ?> پیش‌نیازهای سرور</h3>
                <?php foreach ($checks as $c): ?>
                    <div class="flex between small" style="padding:.35rem 0;border-bottom:1px dashed var(--border)">
                        <span><span class="dot-st dot-<?= e($c['status']) ?>"></span><?= e($c['label']) ?></span>
                        <span class="<?= $c['status'] === 'ok' ? 'faint' : 'fw-b' ?>"><?= e($c['msg']) ?></span>
                    </div>
                <?php endforeach; ?>
                <?php if ($blocking): ?><div class="alert alert-danger mt-2">پیش از نصب، موارد قرمز را در DirectAdmin برطرف کنید (راهنما: docs/DEPLOYMENT-DirectAdmin.md).</div><?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>
