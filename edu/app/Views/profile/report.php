<div class="page-head"><div><h1>گزارش فعالیت و یادگیری من</h1><div class="sub">فقط اطلاعات شما در این صفحه نمایش داده می‌شود</div></div></div>
<?php $baseUrl = '/me/report'; include APP_PATH . '/Views/partials/user_report.php'; ?>
<div class="grid g-2 mt-3">
    <form class="card" method="post" action="<?= url('/me/needs') ?>">
        <?= csrf_field() ?>
        <h3><?= icon('lightbulb') ?> ثبت نیاز آموزشی</h3>
        <p class="small muted">چه مهارتی را می‌خواهید یاد بگیرید؟ سامانه بر اساس نیاز شما دوره پیشنهاد می‌دهد.</p>
        <div class="field"><label>عنوان</label><input type="text" name="title" required></div>
        <div class="field"><label>موضوع</label><select name="category_id"><option value="">—</option><?php foreach ($cats as $k => $v): ?><option value="<?= (int)$k ?>"><?= e($v) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label>توضیحات</label><textarea name="description" rows="3"></textarea></div>
        <button class="btn btn-primary"><?= icon('plus') ?> ثبت</button>
    </form>
    <div class="card"><h3><?= icon('list-checks') ?> نیازهای آموزشی ثبت‌شده</h3>
        <?php if (!$needs): ?><div class="faint small">موردی ثبت نشده است.</div><?php endif; ?>
        <?php foreach ($needs as $n): ?><div class="list-item"><div class="grow"><b><?= e($n['title']) ?></b><div class="small faint"><?= e($n['category_name'] ?? '') ?> · <?= jdate($n['created_at']) ?></div></div><?= status_badge($n['status']) ?></div><?php endforeach; ?>
    </div>
</div>
