<div class="page-head">
    <div><div class="crumbs"><a href="<?= url('/admin/groups') ?>">گروه‌ها</a><?php if ($parent): ?> / <a href="<?= url('/admin/groups/' . $parent['id']) ?>"><?= e($parent['name']) ?></a><?php endif; ?></div>
        <h1 class="flex"><span style="color:<?= e($g['color']) ?>"><?= icon($g['icon']) ?></span> <?= e($g['name']) ?></h1><div class="sub"><?= e($g['description']) ?></div></div>
    <div class="btn-group">
        <?php if (can('reports.report')): ?><a class="btn btn-outline" href="<?= url('/admin/reports/groups', ['dim' => 'group', 'id' => $g['id']]) ?>"><?= icon('chart-column') ?> گزارش گروه</a><?php endif; ?>
        <?php if (can('groups.delete') && !$g['is_system']): ?><form class="inline" method="post" action="<?= url('/admin/groups/' . $g['id'] . '/delete') ?>" data-confirm="گروه حذف شود؟"><?= csrf_field() ?><button class="btn btn-ghost" style="color:var(--danger)"><?= icon('trash-2') ?> حذف</button></form><?php endif; ?>
    </div>
</div>
<div class="grid g-main">
    <div class="stack">
        <div class="card flush">
            <div class="card-head"><h3><?= icon('users') ?> اعضا (<?= nf($members['total']) ?>)</h3></div>
            <?php if (can('groups.assign')): ?>
            <form method="post" action="<?= url('/admin/groups/' . $g['id'] . '/members') ?>" style="padding:0 1.25rem 1rem">
                <?= csrf_field() ?>
                <input type="search" data-filter-select="gm-add" placeholder="جست‌وجوی کاربر برای افزودن…" class="mb-1">
                <select id="gm-add" name="add_users[]" multiple size="5"><?php foreach ($candidates as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e(full_name($c)) ?> — <?= e($c['mobile']) ?></option><?php endforeach; ?></select>
                <button class="btn btn-sm btn-primary mt-1"><?= icon('user-plus') ?> افزودن به گروه</button>
            </form>
            <?php endif; ?>
            <div class="table-wrap"><table class="table"><thead><tr><th>کاربر</th><th>موبایل</th><th>پیشرفت</th><th></th></tr></thead><tbody>
            <?php foreach ($members['rows'] as $m): ?>
                <tr><td><a class="person" href="<?= url('/admin/users/' . $m['id']) ?>" style="color:var(--text)"><?= avatar_html($m, 'sm') ?><span class="nm"><?= user_name_html($m) ?></span></a></td><td class="ltr num"><?= e($m['mobile']) ?></td>
                <td style="min-width:120px"><?= $m['prog'] !== null ? progress_bar((float)$m['prog']) : '<span class="faint">—</span>' ?></td>
                <td class="actions"><?php if (can('groups.assign')): ?><form class="inline" method="post" action="<?= url('/admin/groups/' . $g['id'] . '/members') ?>" data-confirm="از گروه حذف شود؟"><?= csrf_field() ?><input type="hidden" name="remove_users[]" value="<?= (int)$m['id'] ?>"><button class="btn btn-xs btn-ghost" style="color:var(--danger)"><?= icon('user-x') ?></button></form><?php endif; ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$members['rows']): ?><tr><td colspan="4" class="faint text-center">عضوی ندارد</td></tr><?php endif; ?>
            </tbody></table></div>
            <div style="padding:0 1rem 1rem"><?= paginate_links($members) ?></div>
        </div>
    </div>
    <div class="stack">
        <?php if (can('groups.edit')): ?>
        <form class="card" method="post" action="<?= url('/admin/groups/' . $g['id']) ?>">
            <?= csrf_field() ?>
            <h3><?= icon('pencil') ?> ویرایش گروه</h3>
            <div class="field"><label>نام</label><input type="text" name="name" value="<?= e($g['name']) ?>" required></div>
            <input type="hidden" name="segment" value="<?= e($g['segment']) ?>">
            <?php if (!$g['is_system']): ?>
                <div class="field"><label>نوع</label><select name="segment"><?php foreach (App\Core\Labels::SEGMENT as $k => $v): ?><option value="<?= $k ?>"<?= selected($k, $g['segment']) ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
                <div class="field"><label>گروه والد</label><select name="parent_id"><option value="">—</option><?php foreach ($all as $a): ?><option value="<?= (int)$a['id'] ?>"<?= selected($a['id'], $g['parent_id']) ?>><?= e($a['name']) ?></option><?php endforeach; ?></select></div>
            <?php endif; ?>
            <div class="form-grid">
                <div class="field"><label>آیکون</label><select name="icon"><?php foreach ($icons as $i): ?><option value="<?= $i ?>"<?= selected($i, $g['icon']) ?>><?= $i ?></option><?php endforeach; ?></select></div>
                <div class="field"><label>رنگ</label><input type="text" class="ltr" name="color" value="<?= e($g['color']) ?>"></div>
            </div>
            <div class="field"><label>مسئول آموزش گروه</label><select name="supervisor_id"><option value="">—</option><?php foreach ($supervisors as $s): ?><option value="<?= (int)$s['id'] ?>"<?= selected($s['id'], $g['supervisor_id']) ?>><?= e(full_name($s)) ?></option><?php endforeach; ?></select><div class="hint">مسئول آموزش با نقش «افراد تحت مسئولیت»، وضعیت اعضای این گروه را می‌بیند.</div></div>
            <div class="field"><label>ترتیب</label><input type="number" name="sort" value="<?= (int)$g['sort'] ?>"></div>
            <div class="field"><label>توضیحات</label><textarea name="description" rows="2"><?= e($g['description']) ?></textarea></div>
            <button class="btn btn-primary"><?= icon('save') ?> ذخیره</button>
        </form>
        <?php endif; ?>
        <div class="card"><div class="card-head"><h3><?= icon('signal') ?> سطح‌بندی این گروه</h3><?php if (can('levels.view')): ?><a class="small" href="<?= url('/admin/levels') ?>">مدیریت سطوح</a><?php endif; ?></div>
            <?php foreach ($levels as $l): ?><div class="list-item"><span class="dot-st" style="background:<?= e($l['color']) ?>"></span><div class="grow"><?= e($l['name']) ?></div><span class="badge badge-gray"><?= fa($l['n']) ?> نفر</span></div><?php endforeach; ?>
            <?php if (!$levels): ?><div class="faint small">سطحی تعریف نشده</div><?php endif; ?>
        </div>
        <?php if ($children): ?><div class="card"><h3><?= icon('layers') ?> زیرگروه‌ها</h3><?php foreach ($children as $c): ?><a class="lesson-link" href="<?= url('/admin/groups/' . $c['id']) ?>"><span class="st"><?= icon($c['icon']) ?></span><?= e($c['name']) ?></a><?php endforeach; ?></div><?php endif; ?>
    </div>
</div>
