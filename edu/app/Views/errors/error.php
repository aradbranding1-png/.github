<?php $title = 'خطا ' . $status; ?>
<div class="error-page">
    <div>
        <div class="code"><?= fa($status) ?></div>
        <h2 class="mt-2"><?= e($message) ?></h2>
        <?php if (!empty($ref)): ?><p class="muted">کد پیگیری خطا: <b class="ltr"><?= e($ref) ?></b> — در صورت تکرار، این کد را به پشتیبانی اعلام کنید.</p><?php endif; ?>
        <?php if (!empty($debug)): ?><pre class="code-block" style="text-align:left;max-width:800px;margin:1rem auto"><?= e($debug) ?></pre><?php endif; ?>
        <div class="flex" style="justify-content:center;margin-top:1.2rem">
            <?php if ($status === 401 || $status === 419): ?>
                <a class="btn btn-primary" href="<?= url('/login') ?>"><?= icon('log-in') ?> ورود به سامانه</a>
            <?php else: ?>
                <a class="btn btn-primary" href="<?= url('/') ?>"><?= icon('house') ?> بازگشت به صفحه اصلی</a>
            <?php endif; ?>
        </div>
    </div>
</div>
