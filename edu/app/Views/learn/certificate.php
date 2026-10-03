<div class="flex between no-print mb-2">
    <a class="btn btn-outline" href="<?= url('/learn/certificates') ?>"><?= icon('chevron-right') ?> بازگشت</a>
    <div class="flex"><a class="btn btn-ghost" href="<?= url('/verify/' . $cert['code']) ?>" target="_blank"><?= icon('shield-check') ?> صفحه استعلام</a><button class="btn btn-primary" data-print><?= icon('file-down') ?> چاپ / ذخیره PDF</button></div>
</div>
<?php if ($cert['revoked_at']): ?><div class="alert alert-danger"><?= icon('circle-x') ?> این گواهی ابطال شده است<?= $cert['revoke_reason'] ? ': ' . e($cert['revoke_reason']) : '' ?>.</div><?php endif; ?>
<div class="cert">
    <div style="font-weight:800;letter-spacing:.1em;color:#b45309"><?= e(setting('certificate_issuer')) ?></div>
    <h1>گواهی پایان دوره</h1>
    <div>بدین‌وسیله گواهی می‌شود</div>
    <div class="nm"><?= e($cert['user_name']) ?></div>
    <div>دوره آموزشی</div>
    <div class="course">«<?= e($cert['course_title']) ?>»</div>
    <div class="mt-1">را با موفقیت به پایان رسانده است<?= $cert['score'] !== null ? ' و موفق به کسب نمره ' . fa((float)$cert['score']) . ' از ۱۰۰ شده است' : '' ?>.</div>
    <div class="seal"><?= icon('award') ?></div>
    <div class="meta">
        <div><span>تاریخ صدور</span><b><?= jdate($cert['issued_at']) ?></b></div>
        <div><span>مدرس</span><b><?= e($cert['instructor_name'] ?: '—') ?></b></div>
        <div><span>اعتبار</span><b><?= $cert['expires_at'] ? 'تا ' . jdate($cert['expires_at']) : 'دائمی' ?></b></div>
        <div><span>کد گواهی</span><b class="ltr"><?= e($cert['code']) ?></b></div>
    </div>
    <div class="small mt-2" style="color:#78716c">استعلام اصالت: <span class="ltr"><?= e(url('/verify/' . $cert['code'])) ?></span></div>
</div>
