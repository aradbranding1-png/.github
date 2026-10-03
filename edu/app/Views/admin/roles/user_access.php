<?php
use App\Core\Gate;
$bySection = [];
foreach ($reg['modules'] as $m => $def) $bySection[$def['section']][$m] = $def;
?>
<div class="page-head">
    <div><div class="crumbs"><a href="<?= url('/admin/users') ?>">کاربران</a> / <a href="<?= url('/admin/users/' . $u['id']) ?>"><?= e(full_name($u)) ?></a></div>
        <h1>دسترسی‌های <?= user_name_html($u) ?></h1><div class="sub">نقش‌ها + مجوزهای فردی (اجازه/منع). «منع» همیشه بر «اجازه» اولویت دارد.</div></div>
    <span class="badge badge-<?= $scope === 'all' ? 'success' : 'warning' ?>" style="font-size:.85rem"><?= icon('eye') ?> محدوده داده: <?= e(label('scope', $scope)) ?></span>
</div>
<?php if ($u['is_root']): ?>
    <div class="alert alert-info"><?= icon('crown') ?> این کاربر مدیر کل است و به همه بخش‌ها دسترسی کامل دارد. دسترسی‌های او قابل تغییر نیست.</div>
<?php endif; ?>
<?php if (!$editable && !$u['is_root']): ?><div class="alert alert-warning"><?= icon('lock') ?> شما مجوز تغییر دسترسی این کاربر را ندارید (یا کاربر مجوزهایی بالاتر از شما دارد).</div><?php endif; ?>
<form method="post" action="<?= url('/admin/users/' . $u['id'] . '/access') ?>">
    <?= csrf_field() ?>
    <div class="card mb-3">
        <h3><?= icon('shield-check') ?> نقش‌ها</h3>
        <div class="chip-select">
            <?php foreach ($roles as $r): $can = $editable && Gate::canAssignRole($r); ?>
                <label title="<?= e($r['description']) ?>"><input type="checkbox" name="roles[]" value="<?= (int)$r['id'] ?>"<?= checked(isset($userRoles[(int)$r['id']])) ?><?= $can ? '' : ' disabled' ?>><span><?= e($r['name']) ?><?= $r['is_learner'] ? ' (فراگیر)' : '' ?></span></label>
                <?php if (!$can && isset($userRoles[(int)$r['id']])): ?><input type="hidden" name="roles[]" value="<?= (int)$r['id'] ?>"><?php endif; ?>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="card flush mb-3">
        <div class="card-head" style="padding:1rem 1.25rem"><h3><?= icon('key-round') ?> مجوزهای فردی و دسترسی نهایی</h3><input type="search" data-matrix-filter placeholder="جست‌وجو…" style="width:220px;padding:.4rem .7rem"></div>
        <div class="table-wrap" style="max-height:70vh">
        <table class="matrix perm-3state">
            <thead><tr><th>مجوز</th><th>از نقش‌ها</th><th>تنظیم فردی</th><th>دسترسی نهایی</th></tr></thead>
            <tbody>
            <?php foreach ($bySection as $sec => $mods): ?>
                <tr class="sec-row"><td colspan="4"><?= e($reg['sections'][$sec]['label']) ?></td></tr>
                <?php foreach ($mods as $m => $def) foreach ($def['actions'] as $a): $key = "$m.$a"; $ro = Gate::isRootOnly($key); $ov = $overrides[$key] ?? 'inherit'; $can = $editable && !$ro && Gate::canGrant($key); ?>
                    <tr data-label="<?= e($def['label'] . ' ' . $reg['actions'][$a]) ?>">
                        <td><?= e($def['label']) ?> — <b><?= e($reg['actions'][$a]) ?></b></td>
                        <td><?= $ro ? '<span class="lock">' . icon('crown') . '</span>' : (isset($fromRoles[$key]) ? '<span style="color:var(--success)">' . icon('check') . '</span>' : '<span class="na">—</span>') ?></td>
                        <td><?php if ($ro): ?><span class="faint small">فقط مدیر کل</span><?php else: ?>
                            <select name="ov[<?= e(str_replace('.', '__', $key)) ?>]" <?= $can ? '' : 'disabled' ?>>
                                <option value="inherit"<?= selected('inherit', $ov) ?>>طبق نقش</option>
                                <option value="allow"<?= selected('allow', $ov) ?>>اجازه</option>
                                <option value="deny"<?= selected('deny', $ov) ?>>منع</option>
                            </select><?php endif; ?></td>
                        <td><?= isset($effective[$key]) ? '<span class="badge badge-success">' . icon('check') . ' دارد</span>' : '<span class="badge badge-gray">ندارد</span>' ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
    <?php if ($editable): ?><div class="form-actions" style="position:sticky;bottom:0;background:var(--bg);padding:1rem 0"><button class="btn btn-grad btn-lg"><?= icon('save') ?> ذخیره دسترسی‌ها</button></div><?php endif; ?>
</form>
