<div class="page-head"><div><h1>تخصیص آموزش</h1><div class="sub">تخصیص دوره یا مسیر آموزشی به یک یا چند شخص، گروه، واحد، بخش، سمت، نقش، سطح یا طبقه‌بندی</div></div>
<?php if (can('rules.view')): ?><a class="btn btn-outline" href="<?= url('/admin/rules') ?>"><?= icon('wand-sparkles') ?> قوانین تخصیص خودکار</a><?php endif; ?></div>
<div class="grid g-main">
    <div class="card flush">
        <div class="card-head" style="padding:1rem 1.25rem"><h3><?= icon('history') ?> تخصیص‌های انجام‌شده</h3></div>
        <div class="table-wrap"><table class="table"><thead><tr><th>آموزش</th><th>مخاطب</th><th>نوع</th><th>مهلت</th><th>افراد / تکمیل</th><th>توسط</th><th></th></tr></thead><tbody>
        <?php foreach ($rows as $a): ?>
            <tr style="<?= $a['is_active'] ? '' : 'opacity:.5' ?>">
                <td class="fw-b"><?= $a['path_id'] ? '<span class="badge badge-purple">' . icon('route') . ' مسیر</span> ' . e($a['path_title']) : e($a['course_title']) ?></td>
                <td><span class="badge badge-gray"><?= e(label('target_type', $a['target_type'])) ?></span> <?= e($a['target_label']) ?></td>
                <td><span class="badge badge-<?= $a['training_type'] === 'mandatory' ? 'danger' : 'gray' ?>"><?= e(label('training_type', $a['training_type'])) ?></span></td>
                <td class="num small"><?= $a['due_at'] ? jdate($a['due_at']) : '—' ?></td>
                <td class="num"><?= fa($a['enrolled_count']) ?><?= isset($a['done']) ? ' / <span style="color:var(--success)">' . fa($a['done']) . '</span>' : '' ?></td>
                <td class="small"><?= e(trim(($a['first_name'] ?? '') . ' ' . ($a['last_name'] ?? ''))) ?><div class="faint"><?= jdate($a['created_at']) ?></div></td>
                <td class="actions"><?php if ($a['is_active']): ?>
                    <?php if (can('assignments.assign')): ?><form class="inline" method="post" action="<?= url('/admin/assignments/' . $a['id'] . '/reapply') ?>"><?= csrf_field() ?><button class="btn btn-xs btn-ghost" title="اعمال مجدد"><?= icon('refresh-cw') ?></button></form><?php endif; ?>
                    <?php if (can('assignments.delete')): ?><form class="inline" method="post" action="<?= url('/admin/assignments/' . $a['id'] . '/delete') ?>" data-confirm="تخصیص لغو شود؟ ثبت‌نام‌های شروع‌نشده حذف می‌شوند."><?= csrf_field() ?><input type="hidden" name="remove_unstarted" value="1"><button class="btn btn-xs btn-ghost" style="color:var(--danger)"><?= icon('x') ?></button></form><?php endif; ?>
                <?php else: ?><span class="badge badge-gray">لغو شده</span><?php endif; ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="7"><div class="empty"><?= icon('send') ?><div>هنوز تخصیصی انجام نشده</div></div></td></tr><?php endif; ?>
        </tbody></table></div>
    </div>
    <?php if (can('assignments.assign')): ?>
    <form class="card" method="post" action="<?= url('/admin/assignments') ?>"><?= csrf_field() ?>
        <h3><?= icon('send') ?> تخصیص جدید</h3>
        <div class="field"><label>دوره‌ها (یک یا چند)</label><select name="course_ids[]" multiple size="6"><?php foreach ($courses as $k => $t): ?><option value="<?= (int)$k ?>"<?= selected($k, $preCourse) ?>><?= e($t) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label>یا مسیر آموزشی</label><select name="path_id"><option value="">—</option><?php foreach ($paths as $k => $t): ?><option value="<?= (int)$k ?>"<?= selected($k, $prePath) ?>><?= e($t) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label>مخاطب</label><select name="target_type" data-toggle-target><?php foreach (App\Core\Labels::TARGET_TYPE as $k => $t): ?><option value="<?= $k ?>"><?= e($t) ?></option><?php endforeach; ?></select></div>
        <div class="field" data-target-box="user"><input type="search" data-filter-select="t-users" placeholder="جست‌وجوی نام یا موبایل…" class="mb-1"><select id="t-users" name="target_users[]" multiple size="7"><?php foreach ($users as $u): ?><option value="<?= (int)$u['id'] ?>"><?= e(full_name($u)) ?> — <?= e($u['mobile']) ?></option><?php endforeach; ?></select><div class="hint">چند نفر را با Ctrl/Cmd انتخاب کنید.</div></div>
        <?php foreach (['group' => $groups, 'org_unit' => $orgs, 'role' => $roles, 'level' => $levels, 'term' => $terms] as $k => $opts): ?>
            <div class="field hide" data-target-box="<?= $k ?>"><select name="target_<?= $k ?>[]" multiple size="7"><?php foreach ($opts as $id => $t): ?><option value="<?= (int)$id ?>"><?= e($t) ?></option><?php endforeach; ?></select><div class="hint">شامل زیرمجموعه‌ها نیز می‌شود.</div></div>
        <?php endforeach; ?>
        <div class="form-grid">
            <div class="field"><label>نوع آموزش</label><select name="training_type"><?php foreach (App\Core\Labels::TRAINING_TYPE as $k => $t): ?><option value="<?= $k ?>"><?= e($t) ?></option><?php endforeach; ?></select></div>
            <div class="field"><label>مهلت (شمسی)</label><input class="ltr" type="text" name="due_at" placeholder="1405/08/30"></div>
        </div>
        <div class="field"><label>یادداشت</label><input type="text" name="note"></div>
        <button class="btn btn-grad w-100"><?= icon('send') ?> تخصیص و اطلاع‌رسانی</button>
    </form>
    <?php endif; ?>
</div>
