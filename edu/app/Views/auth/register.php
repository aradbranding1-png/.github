<div class="auth-wrap">
    <?php include __DIR__ . '/_side.php'; ?>
    <main class="auth-main">
        <div class="auth-card" style="max-width:520px">
            <div class="brand"><div class="sb-logo"><?= icon('user-plus') ?></div><div><h2 class="mb-0">ثبت‌نام در سامانه آموزش</h2><div class="muted small">اگر در my.aradbranding.me حساب دارید، نیازی به ثبت‌نام نیست</div></div></div>
            <?php foreach (flashes() as $f): ?><div class="alert alert-<?= e($f['type']) ?>"><?= $f['msg'] ?></div><?php endforeach; ?>
            <?php if ($sso): ?><a class="btn btn-sso btn-lg mb-2" href="<?= url('/sso/redirect') ?>"><?= icon('fingerprint') ?> <?= e(setting('sso_button_label')) ?></a><?php endif; ?>
            <form method="post" action="<?= url('/register') ?>" class="card">
                <?= csrf_field() ?>
                <div class="field"><label>نوع کاربری</label>
                    <div class="chip-select">
                        <?php foreach ($segments as $k => $v): ?>
                            <label><input type="radio" name="segment" value="<?= e($k) ?>"<?= checked(old('segment', 'merchant') === $k) ?>><span><?= e($v) ?></span></label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="form-grid">
                    <div class="field"><label>نام</label><input type="text" name="first_name" value="<?= e(old('first_name')) ?>" required></div>
                    <div class="field"><label>نام خانوادگی</label><input type="text" name="last_name" value="<?= e(old('last_name')) ?>" required></div>
                    <div class="field"><label>موبایل</label><input class="ltr" type="tel" name="mobile" value="<?= e(old('mobile')) ?>" placeholder="09xxxxxxxxx" required></div>
                    <div class="field"><label>ایمیل (اختیاری)</label><input class="ltr" type="email" name="email" value="<?= e(old('email')) ?>"></div>
                    <div class="field"><label>رمز عبور</label><input class="ltr" type="password" name="password" autocomplete="new-password" required></div>
                    <div class="field"><label>تکرار رمز عبور</label><input class="ltr" type="password" name="password_confirmation" autocomplete="new-password" required></div>
                </div>
                <button class="btn btn-grad btn-lg w-100" type="submit"><?= icon('user-plus') ?> ایجاد حساب</button>
            </form>
            <p class="text-center mt-2 muted">حساب دارید؟ <a href="<?= url('/login') ?>">وارد شوید</a></p>
        </div>
    </main>
</div>
