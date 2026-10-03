<?php
$total = count($rows);
$late = count(array_filter($rows, fn($r) => $r['late'] > 0));
$avg = $total ? array_sum(array_map(fn($r) => (float)$r['prog'], $rows)) / $total : 0;
?>
<div class="page-head"><div><h1>تیم تحت مسئولیت من</h1><div class="sub">وضعیت آموزش افرادی که مسئول آموزش آن‌ها هستید
<?php foreach ($groups as $g): ?><span class="badge badge-gray"><?= e($g['name']) ?></span><?php endforeach; ?><?php foreach ($units as $u): ?><span class="badge badge-info"><?= e($u['type_name'] . ': ' . $u['name']) ?></span><?php endforeach; ?></div></div>
<?php if (can('assignments.assign')): ?><a class="btn btn-grad" href="<?= url('/admin/assignments') ?>"><?= icon('send') ?> تخصیص آموزش به تیم</a><?php endif; ?></div>
<div class="grid g-4 mb-3">
    <div class="card stat tone-primary"><div class="bubble"><?= icon('users') ?></div><div><div class="v"><?= fa($total) ?></div><div class="l">عضو تیم</div></div></div>
    <div class="card stat tone-info"><div class="bubble"><?= icon('gauge') ?></div><div><div class="v"><?= fa(round($avg)) ?>٪</div><div class="l">میانگین پیشرفت</div></div></div>
    <div class="card stat tone-danger"><div class="bubble"><?= icon('triangle-alert') ?></div><div><div class="v"><?= fa($late) ?></div><div class="l">دارای آموزش عقب‌افتاده</div></div></div>
    <div class="card stat tone-success"><div class="bubble"><?= icon('circle-check') ?></div><div><div class="v"><?= fa(array_sum(array_column($rows, 'done'))) ?></div><div class="l">دوره تکمیل‌شده</div></div></div>
</div>
<?php if (!$rows): ?><div class="card empty"><?= icon('users') ?><h3>هنوز فردی به شما سپرده نشده است</h3><p>مدیر سامانه می‌تواند شما را «مسئول آموزش» کاربران، مسئول یک گروه یا مدیر یک واحد سازمانی تعیین کند.</p></div><?php endif; ?>
<div class="grid g-auto">
<?php foreach ($rows as $r): ?>
    <div class="card" style="<?= $r['late'] ? 'border-color:var(--danger)' : '' ?>">
        <div class="flex between"><div class="person"><?= avatar_html($r, 'md') ?><div><div class="nm"><?= user_name_html($r) ?></div><div class="sub"><?= $r['last_login_at'] ? 'آخرین ورود ' . time_ago($r['last_login_at']) : 'هرگز وارد نشده' ?></div></div></div><?= progress_ring((float)$r['prog'], 56, $r['late'] ? 'danger' : 'primary') ?></div>
        <div class="mini-stats mt-1"><div><b><?= fa($r['total']) ?></b><span>دوره</span></div><div><b style="color:var(--success)"><?= fa($r['done']) ?></b><span>تکمیل</span></div><div><b style="color:var(--warning)"><?= fa($r['mand_open']) ?></b><span>اجباری باز</span></div><div><b style="color:var(--danger)"><?= fa($r['late']) ?></b><span>عقب‌افتاده</span></div></div>
        <?php if (can_any(['team.report', 'reports.report', 'users.report'])): ?><a class="btn btn-sm btn-outline mt-2" href="<?= url('/admin/reports/user/' . $r['id']) ?>"><?= icon('chart-column') ?> گزارش کامل</a><?php endif; ?>
    </div>
<?php endforeach; ?>
</div>
