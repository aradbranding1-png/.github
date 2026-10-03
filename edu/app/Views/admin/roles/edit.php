<?php
use App\Core\Gate;
$isRoot = (bool)$role['is_root'];
$actions = $reg['actions'];
$sections = $reg['sections'];
$bySection = [];
foreach ($reg['modules'] as $m => $def) $bySection[$def['section']][$m] = $def;
?>
<div class="page-head">
    <div><div class="crumbs"><a href="<?= url('/admin/roles') ?>">نقش‌ها و سطوح دسترسی</a></div>
        <h1 class="flex"><?php if ($isRoot): ?><span class="blue-tick"><?= icon('badge-check') ?></span><?php endif; ?><?= e($role['name']) ?></h1>
        <div class="sub"><?= e($role['description']) ?></div></div>
    <div class="btn-group"><a class="btn btn-outline" href="#members"><?= icon('users') ?> اعضای نقش (<?= fa(count($members)) ?>)</a></div>
</div>

<?php if ($isRoot): ?>
    <div class="card hero mb-3" style="background:linear-gradient(135deg,#1d4ed8,#0ea5e9)">
        <h2 class="flex"><?= icon('crown') ?> مدیر کل — بالاترین سطح دسترسی</h2>
        <p class="muted mb-0">مدیر کل به صورت ذاتی به <b style="color:#fff">همه</b> صفحات و عملیات سامانه دسترسی دارد (از جمله بروزرسانی، Migration، بازیابی پشتیبان و ورود به حساب کاربران). این نقش در معماری مجوزها پیاده‌سازی شده و هیچ نقش دیگری نمی‌تواند آن را حذف، غیرفعال یا محدود کند یا تیک آبی را بردارد.</p>
    </div>
<?php else: ?>
<form method="post" action="<?= url('/admin/roles/' . $role['id']) ?>">
    <?= csrf_field() ?>
    <div class="card mb-3">
        <div class="form-grid" style="grid-template-columns:2fr 1fr 1fr 1fr">
            <div class="field"><label>نام نقش</label><input type="text" name="name" value="<?= e($role['name']) ?>" <?= $editable ? '' : 'disabled' ?> required></div>
            <div class="field"><label>محدوده داده</label><select name="data_scope" <?= $editable ? '' : 'disabled' ?>><?php foreach (App\Core\Labels::SCOPE as $k => $v): ?><option value="<?= $k ?>"<?= selected($k, $role['data_scope']) ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
            <div class="field"><label>رنگ</label><select name="color" <?= $editable ? '' : 'disabled' ?>><?php foreach ($colors as $k => $v): ?><option value="<?= $k ?>"<?= selected($k, $role['color']) ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
            <div class="field"><label>وضعیت</label><label class="switch"><input type="checkbox" name="is_active" value="1"<?= checked($role['is_active']) ?> <?= $editable ? '' : 'disabled' ?>> فعال</label></div>
            <div class="field full" style="grid-column:1/-1"><label>توضیحات</label><input type="text" name="description" value="<?= e($role['description']) ?>" <?= $editable ? '' : 'disabled' ?>></div>
        </div>
    </div>

    <div class="card flush mb-3">
        <div class="card-head" style="padding:1rem 1.25rem">
            <h3><?= icon('shield-check') ?> ماتریس دسترسی</h3>
            <div class="flex flex-wrap">
                <input type="search" data-matrix-filter placeholder="جست‌وجوی بخش…" style="width:200px;padding:.4rem .7rem">
                <?php if ($editable): ?><label class="check small"><input type="checkbox" data-check-all> انتخاب همه مجاز</label><?php endif; ?>
            </div>
        </div>
        <div class="table-wrap" style="max-height:70vh">
            <table class="matrix">
                <thead><tr><th>بخش / صفحه</th>
                    <?php foreach ($actions as $a => $al): ?><th><?= e($al) ?><?php if ($editable): ?><br><input type="checkbox" data-check-col="<?= $a ?>" title="انتخاب ستون"><?php endif; ?></th><?php endforeach; ?>
                    <th>همه</th></tr></thead>
                <tbody>
                <?php foreach ($bySection as $sec => $mods): ?>
                    <tr class="sec-row"><td colspan="<?= count($actions) + 2 ?>"><?= icon($sections[$sec]['icon']) ?> <?= e($sections[$sec]['label']) ?></td></tr>
                    <?php foreach ($mods as $m => $def): ?>
                        <tr data-label="<?= e($def['label'] . ' ' . $sections[$sec]['label']) ?>">
                            <td><b><?= e($def['label']) ?></b><div class="faint small ltr" style="text-align:right"><?= e($m) ?></div></td>
                            <?php foreach ($actions as $a => $al):
                                $key = "$m.$a";
                                if (!in_array($a, $def['actions'], true)) { echo '<td class="na">·</td>'; continue; }
                                if (Gate::isRootOnly($key)) { echo '<td class="lock" title="فقط مدیر کل">' . icon('crown') . '</td>'; continue; }
                                $can = $editable && Gate::canGrant($key); ?>
                                <td><input type="checkbox" name="perms[]" value="<?= e($key) ?>" data-row="<?= e($m) ?>" data-col="<?= e($a) ?>"<?= checked(isset($granted[$key])) ?><?= $can ? '' : ' disabled' ?> title="<?= e($def['label'] . ' — ' . $al) ?><?= $can ? '' : ' (شما این مجوز را ندارید)' ?>"></td>
                            <?php endforeach; ?>
                            <td><?php if ($editable): ?><input type="checkbox" data-check-row="<?= e($m) ?>" title="همه عملیات این بخش"><?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="small faint" style="padding:.75rem 1.25rem"><?= icon('crown') ?> = فقط مدیر کل · خانه‌های غیرفعال: مجوزهایی که شما خودتان ندارید و نمی‌توانید اعطا کنید · «·» = این عملیات برای این بخش وجود ندارد</div>
    </div>
    <?php if ($editable): ?><div class="form-actions" style="position:sticky;bottom:0;background:var(--bg);padding:1rem 0;z-index:5"><button class="btn btn-grad btn-lg"><?= icon('save') ?> ذخیره دسترسی‌ها</button><a class="btn btn-ghost" href="<?= url('/admin/roles') ?>">بازگشت</a></div><?php endif; ?>
</form>
<?php endif; ?>

<div class="card mt-3" id="members">
    <div class="card-head"><h3><?= icon('users') ?> اعضای این نقش</h3></div>
    <?php if (!$isRoot && can('roles.assign') && Gate::canAssignRole($role)): ?>
    <form method="post" action="<?= url('/admin/roles/' . $role['id']) ?>" class="mb-2">
        <?= csrf_field() ?><input type="hidden" name="action" value="members">
        <div class="field"><label>افزودن کاربر به این نقش</label>
            <input type="search" data-filter-select="add-users" placeholder="جست‌وجوی نام یا موبایل…" class="mb-1">
            <select id="add-users" name="add_users[]" multiple size="6"><?php foreach ($candidates as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e(full_name($c)) ?> — <?= e($c['mobile']) ?></option><?php endforeach; ?></select>
            <div class="hint">با Ctrl/Cmd چند کاربر را انتخاب کنید.</div>
        </div>
        <button class="btn btn-primary btn-sm"><?= icon('user-plus') ?> افزودن</button>
    </form>
    <?php endif; ?>
    <div class="table-wrap"><table class="table"><thead><tr><th>کاربر</th><th>موبایل</th><th>وضعیت</th><th></th></tr></thead><tbody>
    <?php foreach ($members as $m): ?>
        <tr><td><div class="person"><?= avatar_html($m, 'sm') ?><div class="nm"><?= user_name_html($m) ?></div></div></td><td class="ltr num"><?= e($m['mobile']) ?></td><td><?= status_badge($m['status']) ?></td>
        <td class="actions">
            <?php if (can('roles.view')): ?><a class="btn btn-xs btn-ghost" href="<?= url('/admin/users/' . $m['id'] . '/access') ?>"><?= icon('key-round') ?> دسترسی فردی</a><?php endif; ?>
            <?php if (!$isRoot && can('roles.assign') && Gate::canAssignRole($role) && !$m['is_root']): ?><form class="inline" method="post" action="<?= url('/admin/roles/' . $role['id']) ?>" data-confirm="نقش از این کاربر گرفته شود؟"><?= csrf_field() ?><input type="hidden" name="action" value="members"><input type="hidden" name="remove_users[]" value="<?= (int)$m['id'] ?>"><button class="btn btn-xs btn-ghost" style="color:var(--danger)"><?= icon('user-x') ?></button></form><?php endif; ?>
        </td></tr>
    <?php endforeach; ?>
    <?php if (!$members): ?><tr><td colspan="4" class="faint text-center">کاربری این نقش را ندارد.</td></tr><?php endif; ?>
    </tbody></table></div>
</div>
