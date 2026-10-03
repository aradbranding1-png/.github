<?php $q = $_GET; unset($q['r'], $q['page']); ?>
<div class="page-head">
    <div><div class="crumbs"><a href="<?= url('/admin') ?>">مدیریت</a></div><h1>کاربران</h1><div class="sub">تاجران، کارمندان و نمایندگان — مدیریت حساب، نقش، گروه و ساختار سازمانی</div></div>
    <div class="btn-group">
        <?php if (can('users.export')): ?><a class="btn btn-outline" href="<?= url('/admin/users/export', $q) ?>"><?= icon('file-spreadsheet') ?> خروجی Excel</a><?php endif; ?>
        <?php if (can('users.create')): ?><button class="btn btn-outline" data-open="dlg-import"><?= icon('upload') ?> ورود از CSV</button><?php endif; ?>
        <?php if (can('users.edit')): ?><button class="btn btn-outline" data-open="dlg-merge"><?= icon('git-branch') ?> ادغام حساب‌ها</button><?php endif; ?>
        <?php if (can('users.create')): ?><a class="btn btn-grad" href="<?= url('/admin/users/create') ?>"><?= icon('user-plus') ?> کاربر جدید</a><?php endif; ?>
    </div>
</div>

<div class="grid g-4 mb-3">
    <?php foreach (['merchant' => ['store', 'primary'], 'employee' => ['briefcase', 'info'], 'agent' => ['globe', 'warning']] as $s => [$ic, $tone]): ?>
        <a class="card stat tone-<?= $tone ?>" href="<?= url('/admin/users', ['segment' => $s]) ?>"><div class="bubble"><?= icon($ic) ?></div><div><div class="v"><?= nf($counts[$s] ?? 0) ?></div><div class="l"><?= e(label('segment', $s)) ?></div></div></a>
    <?php endforeach; ?>
    <a class="card stat tone-danger" href="<?= url('/admin/users', ['status' => 'pending']) ?>"><div class="bubble"><?= icon('user-check') ?></div><div><div class="v"><?= nf($pending) ?></div><div class="l">در انتظار تأیید</div></div></a>
</div>

<form class="card filters" method="get" action="<?= url('/admin/users') ?>">
    <div class="field grow"><label>جست‌وجو</label><input type="search" name="q" value="<?= e($_GET['q'] ?? '') ?>" placeholder="نام، نام خانوادگی، موبایل، ایمیل، شناسه"></div>
    <div class="field"><label>نوع</label><select name="segment"><option value="">همه</option><?php foreach (App\Core\Labels::SEGMENT_ONE as $k => $v): if ($k === 'custom') continue; ?><option value="<?= $k ?>"<?= selected($k, $_GET['segment'] ?? '') ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label>نقش</label><select name="role"><option value="">همه</option><?php foreach ($roles as $k => $v): ?><option value="<?= (int)$k ?>"<?= selected($k, $_GET['role'] ?? '') ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label>گروه</label><select name="group"><option value="">همه</option><?php foreach ($groups as $g): ?><option value="<?= (int)$g['id'] ?>"<?= selected($g['id'], $_GET['group'] ?? '') ?>><?= $g['parent_id'] ? '— ' : '' ?><?= e($g['name']) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label>واحد سازمانی</label><select name="org"><option value="">همه</option><?php foreach ($orgs as $o): ?><option value="<?= (int)$o['id'] ?>"<?= selected($o['id'], $_GET['org'] ?? '') ?>><?= e($o['type_name'] . ': ' . $o['name']) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label>وضعیت</label><select name="status"><option value="">همه</option><?php foreach (['active', 'inactive', 'pending'] as $s): ?><option value="<?= $s ?>"<?= selected($s, $_GET['status'] ?? '') ?>><?= e(label('status', $s)) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label>SSO</label><select name="sso"><option value="">همه</option><option value="1"<?= selected('1', $_GET['sso'] ?? '') ?>>متصل به my</option><option value="0"<?= selected('0', $_GET['sso'] ?? '') ?>>غیرمتصل</option></select></div>
    <button class="btn btn-primary"><?= icon('filter') ?> اعمال</button>
</form>

<form method="post" action="<?= url('/admin/users/bulk') ?>" class="card flush">
    <?= csrf_field() ?>
    <?php if (can_any(['groups.assign', 'assignments.assign', 'users.edit', 'users.approve', 'credits.edit'])): ?>
    <div class="flex flex-wrap" style="padding:.8rem 1.25rem;border-bottom:1px solid var(--border)">
        <b class="small">عملیات گروهی روی انتخاب‌شده‌ها:</b>
        <select name="bulk_action" style="width:auto"><option value="">—</option><?php if (can('users.approve')): ?><option value="approve">تأیید حساب‌های در انتظار</option><?php endif; ?><?php if (can('credits.edit')): ?><option value="credit">شارژ / کسر اعتبار زمانی</option><?php endif; ?><?php if (can('groups.assign')): ?><option value="group">افزودن به گروه</option><?php endif; ?><?php if (can('assignments.assign')): ?><option value="course">تخصیص دوره</option><?php endif; ?><?php if (can('users.edit')): ?><option value="activate">فعال‌سازی</option><option value="deactivate">غیرفعال‌سازی</option><?php endif; ?></select>
        <select name="group_id" style="width:auto"><option value="">گروه…</option><?php foreach ($allGroups as $k => $v): ?><option value="<?= (int)$k ?>"><?= e($v) ?></option><?php endforeach; ?></select>
        <select name="course_id" style="width:auto;max-width:260px"><option value="">دوره…</option><?php foreach ($courses as $k => $v): ?><option value="<?= (int)$k ?>"><?= e($v) ?></option><?php endforeach; ?></select>
        <select name="training_type" style="width:auto"><?php foreach (App\Core\Labels::TRAINING_TYPE as $k => $v): ?><option value="<?= $k ?>"><?= e($v) ?></option><?php endforeach; ?></select>
        <?php if (can('credits.edit')): ?>
            <select name="bulk_ctype" style="width:auto" title="نوع اعتبار"><option value="course">اعتبار دوره (دقیقه)</option><option value="webinar">اعتبار وبینار (دقیقه)</option><option value="workshop">اعتبار کارگاه (عدد)</option><option value="meeting">اشتراک میتینگ (ماه)</option></select>
            <input type="number" name="bulk_minutes" placeholder="مقدار (منفی = کسر)" style="width:150px" title="دوره و وبینار: دقیقه — کارگاه: تعداد — میتینگ: ماه">
        <?php endif; ?>
        <button class="btn btn-sm btn-outline" data-confirm="عملیات روی کاربران انتخاب‌شده اجرا شود؟">اجرا</button>
    </div>
    <?php endif; ?>
    <div class="table-wrap">
    <table class="table">
        <thead><tr><th style="width:36px"></th><th>کاربر</th><th>موبایل</th><th>نوع</th><th>نقش‌ها</th><th>پیشرفت</th><?php if (can('credits.view')): ?><th>اعتبار زمانی</th><?php endif; ?><th>آخرین ورود</th><th>وضعیت</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($page['rows'] as $u): ?>
            <tr>
                <td><?php if (!$u['is_root']): ?><input type="checkbox" name="ids[]" value="<?= (int)$u['id'] ?>" class="check"><?php endif; ?></td>
                <td><a class="person" href="<?= url('/admin/users/' . $u['id']) ?>" style="color:var(--text)"><?= avatar_html($u, 'sm') ?><div><div class="nm"><?= user_name_html($u) ?></div><div class="sub">#<?= (int)$u['id'] ?><?= $u['my_user_id'] ? ' · <span style="color:var(--success)">my ✓</span>' : '' ?></div></div></a></td>
                <td class="ltr num"><?= e($u['mobile']) ?></td>
                <td><span class="badge badge-gray"><?= e(label('segment_one', $u['segment'])) ?></span></td>
                <td class="small"><?= e(str_limit($u['role_names'], 40)) ?></td>
                <td style="min-width:110px"><?php if ($u['avg_progress'] !== null): ?><div class="flex"><div class="grow"><?= progress_bar((float)$u['avg_progress']) ?></div><span class="small"><?= fa((int)$u['avg_progress']) ?>٪</span></div><?php else: ?><span class="faint">—</span><?php endif; ?></td>
                <?php if (can('credits.view')): ?><td class="small nowrap"><?= (int)$u['minute_balance'] > 0 ? App\Services\Credit::format((int)$u['minute_balance']) : '<span class="faint">۰</span>' ?></td><?php endif; ?>
                <td class="small nowrap"><?= $u['last_login_at'] ? time_ago($u['last_login_at']) : '<span class="faint">هرگز</span>' ?></td>
                <td><?= status_badge($u['status']) ?><?php if ($u['status'] === 'pending' && can('users.approve')): ?>
                    <button class="btn btn-xs btn-success mt-1" formaction="<?= url('/admin/users/' . $u['id'] . '/approve') ?>" name="decision" value="approve" formnovalidate><?= icon('check') ?> تأیید</button>
                <?php endif; ?></td>
                <td class="actions">
                    <a class="btn btn-xs btn-ghost" href="<?= url('/admin/users/' . $u['id']) ?>" title="نمایش"><?= icon('eye') ?></a>
                    <?php if (can_any(['reports.report', 'users.report'])): ?><a class="btn btn-xs btn-ghost" href="<?= url('/admin/reports/user/' . $u['id']) ?>" title="گزارش"><?= icon('chart-column') ?></a><?php endif; ?>
                    <?php if (can('users.edit')): ?><a class="btn btn-xs btn-ghost" href="<?= url('/admin/users/' . $u['id'] . '/edit') ?>" title="ویرایش"><?= icon('pencil') ?></a><?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$page['rows']): ?><tr><td colspan="10"><div class="empty"><?= icon('users') ?><div>کاربری یافت نشد</div></div></td></tr><?php endif; ?>
        </tbody>
    </table>
    </div>
</form>
<?= paginate_links($page) ?>

<?php if (can('users.create')): ?>
<dialog class="modal" id="dlg-import">
    <div class="modal-head"><b>ورود گروهی کاربران از CSV</b><button class="btn btn-ghost btn-icon" data-close><?= icon('x') ?></button></div>
    <form class="modal-body" method="post" enctype="multipart/form-data" action="<?= url('/admin/users/import') ?>">
        <?= csrf_field() ?>
        <p class="small muted">ستون‌ها به ترتیب: نام، نام خانوادگی، موبایل، ایمیل، نوع (تاجر/کارمند/نماینده). فایل Excel را با فرمت CSV UTF-8 ذخیره کنید. موبایل‌های تکراری نادیده گرفته می‌شوند.</p>
        <div class="field"><label>فایل CSV</label><input type="file" name="csv" accept=".csv,text/csv" required></div>
        <div class="field"><label>افزودن همه به گروه (اختیاری)</label><select name="group_id"><option value="">—</option><?php foreach ($allGroups as $k => $v): ?><option value="<?= (int)$k ?>"><?= e($v) ?></option><?php endforeach; ?></select></div>
        <button class="btn btn-primary"><?= icon('upload') ?> ورود کاربران</button>
    </form>
</dialog>
<?php endif; ?>
<?php if (can('users.edit')): ?>
<dialog class="modal" id="dlg-merge">
    <div class="modal-head"><b>ادغام دو حساب (Link / Merge)</b><button class="btn btn-ghost btn-icon" data-close><?= icon('x') ?></button></div>
    <form class="modal-body" method="post" action="<?= url('/admin/users/merge') ?>" data-confirm="ادغام حساب‌ها غیرقابل بازگشت است. ادامه می‌دهید؟">
        <?= csrf_field() ?>
        <p class="small muted">تمام سوابق آموزشی (دوره‌ها، پیشرفت، آزمون‌ها، تمرین‌ها، گواهی‌ها، فعالیت‌ها) از حساب مبدأ به حساب مقصد منتقل می‌شود و حساب مبدأ غیرفعال می‌گردد.</p>
        <div class="form-grid">
            <div class="field"><label>شناسه حساب مبدأ (حذف می‌شود)</label><input type="number" name="source_id" required></div>
            <div class="field"><label>شناسه حساب مقصد (باقی می‌ماند)</label><input type="number" name="target_id" required></div>
        </div>
        <button class="btn btn-danger"><?= icon('git-branch') ?> ادغام</button>
    </form>
</dialog>
<?php endif; ?>
