<div class="page-head"><div><div class="crumbs"><a href="<?= url('/admin/reports') ?>">گزارش‌ها</a></div><h1>گزارش کامل کاربر</h1><div class="sub">کاربر را بر اساس نام، نام خانوادگی، موبایل، شناسه، نقش یا گروه جست‌وجو کنید</div></div></div>
<form class="card filters" method="get" action="<?= url('/admin/reports/users') ?>">
    <div class="field grow"><label>نام / موبایل / شناسه</label><input type="search" name="q" value="<?= e($q) ?>" autofocus></div>
    <div class="field"><label>نقش</label><select name="role"><option value="">—</option><?php foreach ($roles as $k => $v): ?><option value="<?= (int)$k ?>"<?= selected($k, $_GET['role'] ?? '') ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label>گروه</label><select name="group"><option value="">—</option><?php foreach ($groups as $k => $v): ?><option value="<?= (int)$k ?>"<?= selected($k, $_GET['group'] ?? '') ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
    <button class="btn btn-primary"><?= icon('search') ?> جست‌وجو</button>
</form>
<div class="grid g-auto">
<?php foreach ($rows as $u): ?>
    <a class="card" href="<?= url('/admin/reports/user/' . $u['id']) ?>" style="color:var(--text)">
        <div class="flex between"><div class="person"><?= avatar_html($u, 'md') ?><div><div class="nm"><?= user_name_html($u) ?></div><div class="sub ltr" style="text-align:right"><?= e($u['mobile']) ?></div></div></div><?= $u['prog'] !== null ? progress_ring((float)$u['prog'], 50) : '' ?></div>
        <div class="small muted mt-1"><?= fa($u['done']) ?> دوره تکمیل‌شده · آخرین ورود <?= $u['last_login_at'] ? time_ago($u['last_login_at']) : 'هرگز' ?></div>
    </a>
<?php endforeach; ?>
</div>
<?php if ($q !== '' && !$rows): ?><div class="card empty"><?= icon('search') ?><div>کاربری یافت نشد</div></div><?php endif; ?>
