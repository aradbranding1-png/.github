<div class="page-head"><div><div class="crumbs"><a href="<?= url('/admin/reports') ?>">گزارش‌ها</a></div><h1>افراد غیرفعال</h1><div class="sub">کاربران فعالی که بیش از <?= fa($days) ?> روز وارد سامانه نشده‌اند (<?= nf($page['total']) ?> نفر)</div></div>
<div class="btn-group">
    <?php if (can('reports.export')): ?><a class="btn btn-outline" href="<?= url('/admin/reports/inactive', ['days' => $days, 'export' => 1]) ?>"><?= icon('file-spreadsheet') ?> Excel</a><?php endif; ?>
    <?php if (can('notifications.create') && $page['total']): ?><form class="inline" method="post" action="<?= url('/admin/reports/inactive', ['days' => $days]) ?>" data-confirm="یادآوری برای همه این افراد ارسال شود؟"><?= csrf_field() ?><input type="hidden" name="notify" value="1"><button class="btn btn-warning"><?= icon('bell') ?> ارسال یادآوری</button></form><?php endif; ?>
</div></div>
<form class="card filters" method="get" action="<?= url('/admin/reports/inactive') ?>"><div class="field"><label>بیش از چند روز؟</label><input type="number" name="days" value="<?= (int)$days ?>" min="1"></div><button class="btn btn-primary"><?= icon('filter') ?></button></form>
<div class="card flush"><div class="table-wrap"><table class="table"><thead><tr><th>کاربر</th><th>نوع</th><th>آخرین ورود</th><th>آخرین فعالیت آموزشی</th><th>دوره‌های باز</th></tr></thead><tbody>
<?php foreach ($page['rows'] as $u): ?><tr><td><a class="person" href="<?= url('/admin/reports/user/' . $u['id']) ?>" style="color:var(--text)"><?= avatar_html($u, 'sm') ?><span class="nm"><?= user_name_html($u) ?></span></a></td><td><?= e(label('segment_one', $u['segment'])) ?></td><td class="small"><?= $u['last_login_at'] ? jdatetime($u['last_login_at']) . ' (' . time_ago($u['last_login_at']) . ')' : '<span class="badge badge-danger">هرگز وارد نشده</span>' ?></td><td class="small"><?= jdate($u['last_learning']) ?></td><td class="num"><?= fa($u['open_courses']) ?></td></tr><?php endforeach; ?>
<?php if (!$page['rows']): ?><tr><td colspan="5"><div class="empty"><?= icon('circle-check') ?><div>همه کاربران فعال هستند</div></div></td></tr><?php endif; ?>
</tbody></table></div></div>
<?= paginate_links($page) ?>
