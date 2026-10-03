<div class="page-head"><div><h1>نیازسنجی آموزشی</h1><div class="sub">نیازهای آموزشی ثبت‌شده توسط مدیران یا خود کاربران — مبنای پیشنهاد دوره و برنامه‌ریزی آموزش</div></div>
<?php if (can('reports.report')): ?><a class="btn btn-outline" href="<?= url('/admin/reports/needs') ?>"><?= icon('chart-column') ?> گزارش نیازها</a><?php endif; ?></div>
<div class="grid g-main">
    <div class="stack">
        <form class="card filters" method="get" action="<?= url('/admin/needs') ?>">
            <div class="field"><label>وضعیت</label><select name="status"><option value="">همه</option><?php foreach (['open', 'planned', 'resolved'] as $s): ?><option value="<?= $s ?>"<?= selected($s, $_GET['status'] ?? '') ?>><?= e(label('status', $s)) ?></option><?php endforeach; ?></select></div>
            <div class="field"><label>موضوع</label><select name="category"><option value="">همه</option><?php foreach ($cats as $k => $t): ?><option value="<?= (int)$k ?>"<?= selected($k, $_GET['category'] ?? '') ?>><?= e($t) ?></option><?php endforeach; ?></select></div>
            <button class="btn btn-primary"><?= icon('filter') ?></button>
        </form>
        <div class="card flush"><div class="table-wrap"><table class="table"><thead><tr><th>کاربر</th><th>نیاز</th><th>اولویت</th><th>منبع</th><th>وضعیت / اقدام</th><th></th></tr></thead><tbody>
        <?php foreach ($rows as $n): ?>
            <tr><td><a href="<?= url('/admin/users/' . $n['user_id']) ?>"><?= person_name($n, 'user_id') ?></a><div class="small faint"><?= jdate($n['created_at']) ?></div></td>
            <td><b><?= e($n['title']) ?></b><div class="small faint"><?= e($n['category_name'] ?? '') ?> <?= e(str_limit($n['description'], 60)) ?></div></td>
            <td><span class="badge badge-<?= ['high' => 'danger', 'medium' => 'warning', 'low' => 'gray'][$n['priority']] ?>"><?= e(label('priority', $n['priority'])) ?></span></td>
            <td class="small"><?= $n['source'] === 'self' ? 'خود کاربر' : 'مدیر' ?></td>
            <td><?php if (can('needs.edit')): ?>
                <form class="flex" method="post" action="<?= url('/admin/needs/' . $n['id']) ?>"><?= csrf_field() ?>
                    <select name="status" style="width:auto;padding:.3rem"><?php foreach (['open', 'planned', 'resolved'] as $s): ?><option value="<?= $s ?>"<?= selected($s, $n['status']) ?>><?= e(label('status', $s)) ?></option><?php endforeach; ?></select>
                    <input type="hidden" name="priority" value="<?= e($n['priority']) ?>">
                    <select name="resolved_course_id" style="width:160px;padding:.3rem"><option value="">دوره پاسخ…</option><?php foreach ($courses as $k => $t): ?><option value="<?= (int)$k ?>"<?= selected($k, $n['resolved_course_id']) ?>><?= e($t) ?></option><?php endforeach; ?></select>
                    <label class="check small" title="تخصیص دوره به کاربر"><input type="checkbox" name="assign" value="1"> تخصیص</label>
                    <button class="btn btn-xs btn-outline"><?= icon('save') ?></button>
                </form>
            <?php else: ?><?= status_badge($n['status']) ?><?php endif; ?></td>
            <td class="actions"><?php if (can('needs.delete')): ?><form class="inline" method="post" action="<?= url('/admin/needs/' . $n['id'] . '/delete') ?>" data-confirm="حذف شود؟"><?= csrf_field() ?><button class="btn btn-xs btn-ghost"><?= icon('trash-2') ?></button></form><?php endif; ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="6"><div class="empty"><?= icon('lightbulb') ?><div>نیازی ثبت نشده</div></div></td></tr><?php endif; ?>
        </tbody></table></div></div>
    </div>
    <div class="stack">
        <?php if (can('needs.create')): ?>
        <form class="card" method="post" action="<?= url('/admin/needs') ?>"><?= csrf_field() ?>
            <h3><?= icon('plus') ?> ثبت نیاز آموزشی برای کاربر</h3>
            <div class="field"><input type="search" data-filter-select="nd-user" placeholder="جست‌وجوی کاربر…" class="mb-1"><select id="nd-user" name="user_id" size="5" required><?php foreach ($users as $u): ?><option value="<?= (int)$u['id'] ?>"><?= e(full_name($u)) ?> — <?= e($u['mobile']) ?></option><?php endforeach; ?></select></div>
            <div class="field"><label>عنوان نیاز</label><input type="text" name="title" required></div>
            <div class="field"><label>موضوع</label><select name="category_id"><option value="">—</option><?php foreach ($cats as $k => $t): ?><option value="<?= (int)$k ?>"><?= e($t) ?></option><?php endforeach; ?></select></div>
            <div class="field"><label>اولویت</label><select name="priority"><?php foreach (App\Core\Labels::PRIORITY as $k => $t): ?><option value="<?= $k ?>"<?= selected($k, 'medium') ?>><?= e($t) ?></option><?php endforeach; ?></select></div>
            <div class="field"><label>توضیحات</label><textarea name="description" rows="2"></textarea></div>
            <button class="btn btn-primary w-100"><?= icon('save') ?> ثبت</button>
        </form>
        <?php endif; ?>
        <div class="card"><h3><?= icon('chart-pie') ?> نیازهای باز بر اساس موضوع</h3><?php foreach ($byCat as $b): ?><div class="list-item"><div class="grow small"><?= e($b['name']) ?></div><b><?= fa($b['n']) ?></b></div><?php endforeach; ?><?php if (!$byCat): ?><div class="faint small">—</div><?php endif; ?></div>
    </div>
</div>
