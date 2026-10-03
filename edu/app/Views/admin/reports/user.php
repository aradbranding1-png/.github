<?php $q = $_GET; unset($q['r']); $q['export'] = 1; ?>
<div class="page-head">
    <div><div class="crumbs"><a href="<?= url('/admin/reports') ?>">گزارش‌ها</a> / <a href="<?= url('/admin/reports/users') ?>">گزارش کاربر</a></div><h1>گزارش فعالیت و یادگیری</h1></div>
    <div class="btn-group">
        <?php if (can('users.view')): ?><a class="btn btn-outline" href="<?= url('/admin/users/' . $R['u']['id']) ?>"><?= icon('user') ?> پروفایل کاربر</a><?php endif; ?>
        <?php if (can('reports.export')): ?><a class="btn btn-outline" href="<?= url('/admin/reports/user/' . $R['u']['id'], $q) ?>"><?= icon('file-spreadsheet') ?> Excel</a><?php endif; ?>
        <button class="btn btn-ghost" data-print><?= icon('file-down') ?> چاپ</button>
    </div>
</div>
<?php $baseUrl = '/admin/reports/user/' . $R['u']['id']; include APP_PATH . '/Views/partials/user_report.php'; ?>
