<div class="page-head">
    <div><div class="crumbs"><a href="<?= url('/admin/org') ?>">ساختار سازمانی</a></div><h1><span class="badge badge-gray"><?= e($o['type_name']) ?></span> <?= e($o['name']) ?></h1><div class="sub">کارکنان این واحد و زیرمجموعه‌های آن</div></div>
    <?php if (can('reports.report')): ?><a class="btn btn-outline" href="<?= url('/admin/reports/groups', ['dim' => 'org_unit', 'id' => $o['id']]) ?>"><?= icon('chart-column') ?> گزارش واحد</a><?php endif; ?>
</div>
<div class="grid g-main">
    <div class="card flush"><div class="card-head"><h3><?= icon('users') ?> کارکنان (<?= fa(count($members)) ?>)</h3></div>
        <div class="table-wrap"><table class="table"><thead><tr><th>نام</th><th>واحدها / سمت</th><th>پیشرفت</th><th></th></tr></thead><tbody>
        <?php foreach ($members as $m): ?><tr><td><a class="person" href="<?= url('/admin/users/' . $m['id']) ?>" style="color:var(--text)"><?= avatar_html($m, 'sm') ?><span class="nm"><?= user_name_html($m) ?></span></a></td><td class="small"><?= e($m['units']) ?></td><td style="min-width:120px"><?= $m['prog'] !== null ? progress_bar((float)$m['prog']) : '—' ?></td>
        <td class="actions"><?php if (can('org.assign')): ?><form class="inline" method="post" action="<?= url('/admin/org/units/' . $o['id'] . '/members') ?>" data-confirm="از این واحد حذف شود؟"><?= csrf_field() ?><input type="hidden" name="remove_users[]" value="<?= (int)$m['id'] ?>"><button class="btn btn-xs btn-ghost"><?= icon('user-x') ?></button></form><?php endif; ?></td></tr><?php endforeach; ?>
        <?php if (!$members): ?><tr><td colspan="4" class="faint text-center">کسی در این واحد نیست</td></tr><?php endif; ?>
        </tbody></table></div>
    </div>
    <?php if (can('org.assign')): ?>
    <form class="card" method="post" action="<?= url('/admin/org/units/' . $o['id'] . '/members') ?>">
        <?= csrf_field() ?>
        <h3><?= icon('user-plus') ?> افزودن کارمند</h3>
        <input type="search" data-filter-select="unit-add" placeholder="جست‌وجو…" class="mb-1">
        <select id="unit-add" name="add_users[]" multiple size="10"><?php foreach ($candidates as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e(full_name($c)) ?> — <?= e($c['mobile']) ?></option><?php endforeach; ?></select>
        <button class="btn btn-primary mt-1"><?= icon('plus') ?> افزودن</button>
    </form>
    <?php endif; ?>
</div>
