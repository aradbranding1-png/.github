<div class="auth-wrap">
    <?php include __DIR__ . '/_side.php'; ?>
    <main class="auth-main">
        <div class="auth-card">
            <div class="brand"><div class="sb-logo is-img"><img src="<?= asset('img/icons/icon-96.png') ?>" alt="آراد برندینگ" width="42" height="42"></div><div><h2 class="mb-0">ورود به سامانه</h2><div class="muted small">خوش آمدید؛ برای ادامه وارد شوید</div></div></div>
            <?php foreach (flashes() as $f): ?><div class="alert alert-<?= e($f['type']) ?>"><?= $f['msg'] ?></div><?php endforeach; ?>
            <form method="post" action="<?= url('/login') ?>" class="card">
                <?= csrf_field() ?>
                <div class="field"><label>موبایل، ایمیل یا نام کاربری</label><input class="ltr" type="text" name="identifier" value="<?= e(old('identifier')) ?>" autocomplete="username" required autofocus></div>
                <div class="field"><label>رمز عبور</label><input class="ltr" type="password" name="password" autocomplete="current-password" required></div>
                <div class="field"><label class="check"><input type="checkbox" name="remember" value="1" checked> مرا به خاطر بسپار <span class="muted small">(تا ۳۰ روز وارد بمانید)</span></label></div>
                <button class="btn btn-grad btn-lg w-100" type="submit"><?= icon('log-in') ?> ورود</button>
            </form>
            <?php if (setting('registration_enabled') === '1'): ?>
                <p class="text-center mt-2 muted">حساب ندارید؟ <a href="<?= url('/register') ?>">ثبت‌نام کنید</a></p>
            <?php endif; ?>
        </div>
    </main>
</div>
