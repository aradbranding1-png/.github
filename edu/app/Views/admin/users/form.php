<?php
use App\Core\Gate;
$isEdit = $u !== null;
$v = fn($k, $d = '') => old($k, $u[$k] ?? $d);
$bySeg = [];
foreach ($groups as $g) $bySeg[$g['segment']][] = $g;
?>
<div class="page-head"><div><div class="crumbs"><a href="<?= url('/admin/users') ?>">کاربران</a></div><h1><?= $isEdit ? 'ویرایش ' . user_name_html($u) : 'کاربر جدید' ?></h1></div></div>
<form method="post" action="<?= url($isEdit ? '/admin/users/' . $u['id'] : '/admin/users') ?>" autocomplete="off">
    <?= csrf_field() ?>
    <div class="grid g-main">
        <div class="stack">
            <div class="card">
                <h3><?= icon('user') ?> اطلاعات حساب</h3>
                <div class="form-grid">
                    <div class="field"><label>نام <span class="req">*</span></label><input type="text" name="first_name" value="<?= e($v('first_name')) ?>" required></div>
                    <div class="field"><label>نام خانوادگی <span class="req">*</span></label><input type="text" name="last_name" value="<?= e($v('last_name')) ?>" required></div>
                    <div class="field"><label>موبایل <span class="req">*</span></label><input class="ltr" type="tel" name="mobile" value="<?= e($v('mobile')) ?>" required></div>
                    <div class="field"><label>ایمیل</label><input class="ltr" type="email" name="email" value="<?= e($v('email')) ?>"></div>
                    <div class="field"><label>نام کاربری (اختیاری)</label><input class="ltr" type="text" name="username" value="<?= e($v('username')) ?>"></div>
                    <div class="field"><label>عنوان شغلی</label><input type="text" name="job_title" value="<?= e($v('job_title')) ?>"></div>
                    <div class="field"><label>نوع کاربر <span class="req">*</span></label><select name="segment"><?php foreach (['merchant', 'employee', 'agent'] as $s): ?><option value="<?= $s ?>"<?= selected($s, $v('segment', 'merchant')) ?>><?= e(label('segment_one', $s)) ?></option><?php endforeach; ?></select></div>
                    <div class="field"><label>وضعیت</label><select name="status" <?= $isEdit && $u['is_root'] ? 'disabled' : '' ?>><?php foreach (['active', 'inactive', 'pending'] as $s): ?><option value="<?= $s ?>"<?= selected($s, $v('status', 'active')) ?>><?= e(label('status', $s)) ?></option><?php endforeach; ?></select><?php if ($isEdit && $u['is_root']): ?><input type="hidden" name="status" value="active"><?php endif; ?></div>
                    <?php if ($isEdit): ?>
                    <div class="field"><label>رمز عبور</label>
                        <label class="check pw-change"><input type="checkbox" name="change_password" value="1" data-enables="#user-pw"> تغییر رمز عبور این کاربر</label>
                        <input class="ltr" type="password" id="user-pw" name="password" autocomplete="new-password" data-nofill readonly disabled placeholder="رمز جدید">
                        <div class="hint">فقط اگر تیک بالا را بزنید رمز کاربر عوض می‌شود؛ در غیر این صورت رمز فعلی او دست‌نخورده می‌ماند.</div></div>
                    <?php else: ?>
                    <div class="field"><label>رمز عبور</label><input class="ltr" type="password" name="password" autocomplete="new-password" data-nofill readonly><div class="hint">حداقل ۸ کاراکتر شامل حرف و عدد. برای کاربران SSO می‌تواند خالی بماند.</div></div>
                    <?php endif; ?>
                    <div class="field"><label>مسئول آموزش (سرپرست)</label><select name="supervisor_id"><option value="">—</option><?php foreach ($supervisors as $s): if ($isEdit && (int)$s['id'] === (int)$u['id']) continue; ?><option value="<?= (int)$s['id'] ?>"<?= selected($s['id'], $v('supervisor_id')) ?>><?= e(full_name($s)) ?></option><?php endforeach; ?></select></div>
                </div>
            </div>

            <?php if (can('groups.assign') || can('users.assign')): ?>
            <div class="card">
                <h3><?= icon('layers') ?> گروه‌ها و سطح (عضویت چندگانه مجاز است)</h3>
                <?php foreach ($bySeg as $seg => $list): ?>
                    <div class="mb-2"><div class="small fw-b mb-1"><?= e(label('segment', $seg)) ?></div>
                        <div class="chip-select"><?php foreach ($list as $g): ?><label><input type="checkbox" name="groups[]" value="<?= (int)$g['id'] ?>"<?= checked(in_array((int)$g['id'], $uGroups, true)) ?>><span><?= icon($g['icon']) ?> <?= e($g['name']) ?></span></label><?php endforeach; ?></div>
                    </div>
                <?php endforeach; ?>
                <hr>
                <div class="form-grid">
                    <?php $lvByGroup = []; foreach ($levels as $l) $lvByGroup[$l['group_id']][] = $l;
                    foreach ($lvByGroup as $gid => $ls): ?>
                        <div class="field"><label>سطح در «<?= e($ls[0]['group_name']) ?>»</label><select name="levels[<?= (int)$gid ?>]"><option value="">—</option><?php foreach ($ls as $l): ?><option value="<?= (int)$l['id'] ?>"<?= selected($l['id'], $uLevels[$gid] ?? '') ?>><?= e($l['name']) ?></option><?php endforeach; ?></select></div>
                    <?php endforeach; ?>
                </div>
                <hr>
                <h4><?= icon('list-tree') ?> طبقه‌بندی‌ها (تاجران / نمایندگان)</h4>
                <div class="form-grid">
                    <?php foreach ($taxes as $t): if (!$t['terms']) continue; ?>
                        <div class="field"><label><?= e($t['name']) ?> <span class="faint small">(<?= e(label('segment_one', $t['segment'])) ?>)</span></label>
                            <div class="chip-select"><?php foreach ($t['terms'] as $term): ?><label><input type="checkbox" name="terms[]" value="<?= (int)$term['id'] ?>"<?= checked(in_array((int)$term['id'], $uTerms, true)) ?>><span><?= e($term['name']) ?></span></label><?php endforeach; ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <div class="stack">
            <?php if (can('roles.assign') && !($isEdit && ($u['is_root'] || (int)$u['id'] === (int)uid()))): ?>
            <div class="card">
                <h3><?= icon('shield-check') ?> نقش‌ها</h3>
                <?php foreach ($roles as $r): $can = Gate::canAssignRole($r); ?>
                    <label class="check mb-1"><input type="checkbox" name="roles[]" value="<?= (int)$r['id'] ?>"<?= checked(in_array((int)$r['id'], $uRoles, true)) ?><?= $can ? '' : ' disabled' ?>> <?= e($r['name']) ?> <?= $r['is_learner'] ? '<span class="badge badge-gray">فراگیر</span>' : '' ?></label>
                <?php endforeach; ?>
                <div class="hint">اگر هیچ نقشی انتخاب نشود، نقش فراگیر متناسب با نوع کاربر داده می‌شود. دسترسی‌های فردی از صفحه «دسترسی‌ها» تنظیم می‌شود.</div>
            </div>
            <?php endif; ?>
            <?php if (can('org.assign') || can('users.assign')): ?>
            <div class="card">
                <h3><?= icon('network') ?> ساختار سازمانی</h3>
                <input type="search" data-filter-select="org-select" placeholder="جست‌وجو…" class="mb-1">
                <select id="org-select" name="org_units[]" multiple size="10"><?php foreach ($orgs as $o): ?><option value="<?= (int)$o['id'] ?>"<?= selected($o['id'], $uOrgs) ?>><?= e($o['type_name'] . ': ' . $o['name']) ?></option><?php endforeach; ?></select>
                <div class="hint">معاونت / واحد / بخش / سمت کارمند (چند انتخاب با Ctrl)</div>
            </div>
            <?php endif; ?>
            <div class="card"><button class="btn btn-grad btn-lg w-100"><?= icon('save') ?> ذخیره کاربر</button></div>
        </div>
    </div>
</form>
