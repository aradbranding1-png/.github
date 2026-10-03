<div class="page-head">
    <div><div class="crumbs"><a href="<?= url('/admin') ?>">مدیریت</a></div><h1>نقش‌ها و سطوح دسترسی</h1><div class="sub">برای هر نقش مشخص کنید به کدام صفحات و عملیات دسترسی دارد. دسترسی فردی هر کاربر نیز از صفحه کاربر قابل تنظیم است.</div></div>
    <div class="btn-group">
        <a class="btn btn-outline" href="<?= url('/admin/roles/matrix') ?>"><?= icon('layout-grid') ?> ماتریس کلی</a>
        <?php if (can_any(['role_grants.view', 'grant_employee.run', 'grant_agent.run'])): ?><a class="btn btn-outline" href="<?= url('/admin/role-grants') ?>"><?= icon('zap') ?> شارژ گروهی نقش‌ها</a><?php endif; ?>
        <?php if (can('roles.create')): ?><a class="btn btn-grad" href="<?= url('/admin/roles/create') ?>"><?= icon('plus') ?> نقش جدید</a><?php endif; ?>
    </div>
</div>
<div class="alert alert-info"><?= icon('shield-check') ?><div><b>قانون امنیتی:</b> هیچ مدیری نمی‌تواند مجوزی را به نقش یا کاربری بدهد که خودش آن را ندارد. مجوزهای «بروزرسانی سامانه، اجرای Migration، بازیابی/دانلود پشتیبان و ورود به حساب کاربران» فقط در اختیار مدیر کل است.</div></div>
<?php foreach (['مدیریتی' => fn($r) => !$r['is_learner'], 'فراگیران (پنل یادگیری)' => fn($r) => (bool)$r['is_learner']] as $title => $filter): ?>
<h3 class="mt-2 mb-2"><?= e($title) ?></h3>
<div class="grid g-auto mb-3">
    <?php foreach (array_filter($roles, $filter) as $r): $cVar = ['primary' => '#6366f1', 'purple' => '#8b5cf6', 'info' => '#0ea5e9', 'success' => '#10b981', 'warning' => '#f59e0b', 'danger' => '#ef4444', 'dark' => '#334155', 'gray' => '#94a3b8', 'root' => '#1d9bf0'][$r['color']] ?? '#6366f1'; ?>
        <div class="card role-card" style="--c:<?= $cVar ?>">
            <div class="flex between">
                <h3 class="mb-0 flex"><?php if ($r['is_root']): ?><span class="blue-tick"><?= icon('badge-check') ?></span><?php endif; ?><?= e($r['name']) ?></h3>
                <div class="flex"><?php if ($r['is_system']): ?><span class="badge badge-gray">سیستمی</span><?php endif; ?><?php if (!$r['is_active']): ?><span class="badge badge-danger">غیرفعال</span><?php endif; ?></div>
            </div>
            <p class="small muted mt-1" style="min-height:3.2em"><?= e($r['description']) ?></p>
            <div class="flex between">
                <div class="mini-stats">
                    <div><b><?= $r['is_root'] ? 'همه' : fa($r['perms_count']) ?></b><span>مجوز<?= $r['is_root'] ? '' : ' از ' . fa($totalPerms) ?></span></div>
                    <div><b><?= fa($r['users_count']) ?></b><span>کاربر</span></div>
                </div>
                <?php if (!$r['is_root'] && !$r['is_learner']): ?><?= progress_ring($totalPerms ? $r['perms_count'] * 100 / $totalPerms : 0, 54) ?><?php endif; ?>
            </div>
            <div class="flex flex-wrap mt-1"><span class="badge badge-<?= $r['data_scope'] === 'all' ? 'success' : ($r['data_scope'] === 'supervised' ? 'warning' : 'gray') ?>"><?= icon('eye') ?> محدوده: <?= e(label('scope', $r['data_scope'])) ?></span></div>
            <div class="form-actions mt-2">
                <a class="btn btn-sm btn-primary" href="<?= url('/admin/roles/' . $r['id']) ?>"><?= icon($r['is_root'] ? 'eye' : 'shield-check') ?> <?= $r['is_root'] ? 'مشاهده' : 'دسترسی‌ها و اعضا' ?></a>
                <?php if (!$r['is_root'] && can('roles.create')): ?><form class="inline" method="post" action="<?= url('/admin/roles/' . $r['id'] . '/clone') ?>"><?= csrf_field() ?><button class="btn btn-sm btn-ghost"><?= icon('copy') ?> کپی</button></form><?php endif; ?>
                <?php if (!$r['is_system'] && can('roles.delete')): ?><form class="inline" method="post" action="<?= url('/admin/roles/' . $r['id'] . '/delete') ?>" data-confirm="نقش «<?= e($r['name']) ?>» حذف شود؟"><?= csrf_field() ?><button class="btn btn-sm btn-ghost" style="color:var(--danger)"><?= icon('trash-2') ?></button></form><?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<?php endforeach; ?>
