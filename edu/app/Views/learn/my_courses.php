<div class="page-head">
    <div><h1>دوره‌های من</h1><div class="sub">همه آموزش‌هایی که به شما تخصیص داده شده یا در آن ثبت‌نام کرده‌اید</div></div>
    <a class="btn btn-primary" href="<?= url('/learn/catalog') ?>"><?= icon('layout-grid') ?> کاتالوگ دوره‌ها</a>
</div>
<div class="tabs">
    <?php foreach (['all' => 'همه', 'mandatory' => 'اجباری', 'active' => 'در جریان', 'completed' => 'تکمیل‌شده', 'locked' => 'قفل / منقضی / مردود'] as $k => $v): ?>
        <a class="<?= $tab === $k ? 'active' : '' ?>" href="<?= url('/learn', ['tab' => $k]) ?>"><?= e($v) ?> <span class="badge badge-gray"><?= fa($counts[$k] ?? 0) ?></span></a>
    <?php endforeach; ?>
</div>
<?php if (!$rows): ?>
    <div class="card empty"><?= icon('graduation-cap') ?><h3>دوره‌ای در این بخش نیست</h3><a class="btn btn-primary" href="<?= url('/learn/catalog') ?>">مشاهده کاتالوگ</a></div>
<?php else: ?>
    <div class="grid g-auto"><?php foreach ($rows as $c) include APP_PATH . '/Views/partials/course_card.php'; ?></div>
<?php endif; ?>
