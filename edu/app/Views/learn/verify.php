<div style="max-width:560px;margin:3rem auto;padding:0 1rem">
    <div class="card text-center">
        <div class="sb-logo" style="margin:0 auto 1rem;width:56px;height:56px"><?= icon('shield-check') ?></div>
        <h2>استعلام اصالت گواهی</h2>
        <p class="ltr faint"><?= e($code) ?></p>
        <?php if (!$cert): ?>
            <div class="alert alert-danger"><?= icon('circle-x') ?> گواهی با این کد یافت نشد.</div>
        <?php else: $valid = !$cert['revoked_at'] && (!$cert['expires_at'] || $cert['expires_at'] > now()); ?>
            <div class="alert alert-<?= $valid ? 'success' : 'warning' ?>"><?= icon($valid ? 'circle-check' : 'triangle-alert') ?> <?= $cert['revoked_at'] ? 'این گواهی ابطال شده است.' : ($valid ? 'این گواهی معتبر است.' : 'اعتبار این گواهی به پایان رسیده است.') ?></div>
            <dl class="kv" style="text-align:right">
                <dt>نام دارنده</dt><dd><?= e($cert['user_name']) ?></dd>
                <dt>دوره</dt><dd><?= e($cert['course_title']) ?></dd>
                <dt>تاریخ صدور</dt><dd><?= jdate($cert['issued_at']) ?></dd>
                <?php if ($cert['expires_at']): ?><dt>اعتبار تا</dt><dd><?= jdate($cert['expires_at']) ?></dd><?php endif; ?>
            </dl>
        <?php endif; ?>
        <div class="small faint mt-2"><?= e(setting('site_name')) ?></div>
    </div>
</div>
