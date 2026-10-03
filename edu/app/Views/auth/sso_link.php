<div class="auth-wrap">
    <?php include __DIR__ . '/_side.php'; ?>
    <main class="auth-main">
        <div class="auth-card">
            <div class="brand"><div class="sb-logo"><?= icon('link') ?></div><div><h2 class="mb-0">اتصال حساب‌ها</h2><div class="muted small">حساب my شما با یک حساب موجود در سامانه آموزش مطابقت دارد</div></div></div>
            <?php foreach (flashes() as $f): ?><div class="alert alert-<?= e($f['type']) ?>"><?= $f['msg'] ?></div><?php endforeach; ?>
            <div class="card mb-2">
                <div class="flex"><?= avatar_html($user, 'md') ?><div><b><?= e(full_name($user)) ?></b><div class="small faint ltr"><?= e($user['mobile'] ?? $user['email']) ?></div></div></div>
                <p class="small muted mt-1 mb-0">برای جلوگیری از اتصال اشتباه، ادغام خودکار انجام نمی‌شود. با وارد کردن رمز عبور حساب سامانه آموزش، مالکیت آن را تأیید کنید. همه دوره‌ها، پیشرفت، آزمون‌ها، تمرین‌ها و گواهی‌ها حفظ می‌شوند.</p>
            </div>
            <form method="post" action="<?= url('/sso/link') ?>" class="card">
                <?= csrf_field() ?>
                <div class="field"><label>رمز عبور حساب سامانه آموزش</label><input class="ltr" type="password" name="password" required autofocus></div>
                <button class="btn btn-grad w-100"><?= icon('link') ?> تأیید و اتصال حساب‌ها</button>
            </form>
            <p class="small muted mt-2">رمز را به خاطر ندارید؟ با پشتیبانی تماس بگیرید تا مدیر، حساب‌ها را به صورت امن ادغام کند.</p>
        </div>
    </main>
</div>
