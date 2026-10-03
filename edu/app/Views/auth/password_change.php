<div class="auth-wrap">
    <?php include __DIR__ . '/_side.php'; ?>
    <main class="auth-main">
        <div class="auth-card">
            <div class="brand"><div class="sb-logo is-img"><img src="<?= asset('img/icons/icon-96.png') ?>" alt="آراد برندینگ" width="42" height="42"></div><div><h2 class="mb-0">تعیین رمز عبور جدید</h2><div class="muted small"><?= e(trim(($me['first_name'] ?? '') . ' ' . ($me['last_name'] ?? '')) ?: 'خوش آمدید') ?></div></div></div>
            <?php foreach (flashes() as $f): ?><div class="alert alert-<?= e($f['type']) ?>"><?= $f['msg'] ?></div><?php endforeach; ?>
            <div class="alert alert-info"><?= icon('lock') ?><div>رمز فعلی شما یک رمز موقت است. برای امنیت حساب، پیش از ادامه یک رمز جدید انتخاب کنید (حداقل ۸ کاراکتر، شامل حرف انگلیسی و عدد).</div></div>
            <form method="post" action="<?= url('/password/change') ?>" class="card">
                <?= csrf_field() ?>
                <input type="text" name="username" value="<?= e($me['username'] ?: $me['mobile']) ?>" autocomplete="username" hidden>
                <div class="field"><label>رمز عبور جدید</label><input class="ltr" type="password" name="password" autocomplete="new-password" minlength="8" required autofocus></div>
                <div class="field"><label>تکرار رمز عبور جدید</label><input class="ltr" type="password" name="password_confirmation" autocomplete="new-password" minlength="8" required></div>
                <button class="btn btn-grad btn-lg w-100" type="submit"><?= icon('save') ?> ثبت رمز و ورود</button>
            </form>
            <form method="post" action="<?= url('/logout') ?>" class="text-center mt-2"><?= csrf_field() ?><button class="btn btn-ghost btn-sm" type="submit"><?= icon('log-out') ?> خروج</button></form>
        </div>
    </main>
</div>
