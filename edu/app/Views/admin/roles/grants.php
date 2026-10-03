<?php
use App\Services\RoleGrants;
$canEdit = can('role_grants.edit');
?>
<div class="page-head">
    <div><div class="crumbs"><a href="<?= url('/admin/roles') ?>">نقش‌ها و سطوح دسترسی</a></div><h1>شارژ گروهی نقش‌ها</h1>
        <div class="sub">بسته اعتبار هر نقش را تعیین کنید و با یک دکمه همه اعضای آن نقش را شارژ کنید. هر نفر فقط یک بار شارژ می‌شود، مگر گزینه «شارژ دوباره» را بزنید.</div></div>
</div>

<form method="post" action="<?= url('/admin/role-grants') ?>">
<?= csrf_field() ?>
<div class="grid g-2 mb-3">
<?php foreach ($packs as $key => $p): $c = $p['cfg']; $st = $p['stats']; ?>
    <div class="card rg-card">
        <div class="flex between mb-2">
            <div class="flex"><span class="rg-ic"><?= icon($p['icon']) ?></span>
                <div><h3 class="mb-0"><?= e($p['title']) ?></h3>
                    <div class="small muted">نقش: <?= $p['role_name'] !== null ? '<b>' . e($p['role_name']) . '</b>' : '<span style="color:var(--danger)">پیدا نشد — از فهرست زیر انتخاب کنید</span>' ?></div></div></div>
            <?= $c['auto'] ? '<span class="badge badge-success">شارژ خودکار هنگام تخصیص نقش</span>' : '<span class="badge badge-gray">فقط با دکمه</span>' ?>
        </div>

        <div class="rg-stats">
            <div><b><?= nf($st['members']) ?></b><span>عضو نقش</span></div>
            <div><b style="color:var(--success)"><?= nf($st['charged']) ?></b><span>شارژ شده</span></div>
            <div><b style="color:var(--warning-ink,var(--warning))"><?= nf($st['pending']) ?></b><span>در انتظار شارژ</span></div>
        </div>
        <div class="rg-pack"><?= icon('package') ?> <?= e(RoleGrants::describe($c)) ?></div>

        <?php if ($canEdit): ?>
        <details class="rg-edit">
            <summary><?= icon('settings') ?> تنظیم نقش و مقادیر</summary>
            <div class="grid g-2 mt-2">
                <div class="field" style="grid-column:1/-1"><label>نقش</label><select name="p[<?= e($key) ?>][role_id]">
                    <option value="0">— خودکار (نقش فراگیر <?= e($p['title']) ?>) —</option>
                    <?php foreach ($roles as $rid => $rn): ?><option value="<?= (int)$rid ?>"<?= selected($rid, $c['role_id']) ?>><?= e($rn) ?></option><?php endforeach; ?>
                </select></div>
                <div class="field"><label>اعتبار دوره (ساعت)</label><input type="number" min="0" name="p[<?= e($key) ?>][course_hours]" value="<?= (int)$c['course_hours'] ?>"></div>
                <div class="field"><label>اشتراک میتینگ (ماه)</label><input type="number" min="0" name="p[<?= e($key) ?>][meeting_months]" value="<?= (int)$c['meeting_months'] ?>"></div>
                <div class="field"><label>اعتبار وبینار (ساعت)</label><input type="number" min="0" name="p[<?= e($key) ?>][webinar_hours]" value="<?= (int)$c['webinar_hours'] ?>"></div>
                <div class="field"><label>کارگاه (تعداد)</label><input type="number" min="0" name="p[<?= e($key) ?>][workshop]" value="<?= (int)$c['workshop'] ?>"></div>
                <label class="check" style="grid-column:1/-1"><input type="checkbox" name="p[<?= e($key) ?>][auto]" value="1"<?= checked($c['auto']) ?>> هر کس این نقش را بگیرد، خودکار یک بار شارژ شود</label>
            </div>
        </details>
        <?php endif; ?>

        <?php if ($p['can_run']): ?>
        <div class="rg-run">
            <button class="btn btn-grad w-100" type="submit" formaction="<?= url('/admin/role-grants/' . $key . '/run') ?>"
                data-confirm="<?= e(fa($st['pending']) . ' نفر از ' . $p['title'] . ' که هنوز شارژ نشده‌اند، هر کدام ' . RoleGrants::describe($c) . ' شارژ شوند؟') ?>"<?= $p['role_id'] ? '' : ' disabled' ?>>
                <?= icon('zap') ?> شارژ <?= e($p['title']) ?> (<?= fa($st['pending']) ?> نفر)</button>
            <?php if ($st['charged']): ?>
            <button class="btn btn-outline btn-sm w-100 mt-1" type="submit" formaction="<?= url('/admin/role-grants/' . $key . '/run?again=1') ?>"
                data-confirm="<?= e('همه ' . fa($st['members']) . ' نفر ' . $p['title'] . '، حتی کسانی که قبلاً شارژ شده‌اند، دوباره ' . RoleGrants::describe($c) . ' شارژ شوند؟') ?>"<?= $p['role_id'] ? '' : ' disabled' ?>>
                <?= icon('repeat') ?> شارژ دوباره همه اعضا (<?= fa($st['members']) ?> نفر)</button>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
<?php endforeach; ?>
</div>
<?php if ($canEdit): ?><div class="mb-3"><button class="btn btn-primary"><?= icon('save') ?> ذخیره تنظیمات</button> <span class="small muted">پس از تغییر مقادیر، ابتدا ذخیره کنید و بعد دکمه شارژ را بزنید.</span></div><?php endif; ?>
</form>

<?php if ($recent): ?>
<div class="card flush"><div class="table-wrap"><table class="table">
    <thead><tr><th>زمان</th><th>توسط</th><th>عملیات</th><th>نتیجه</th></tr></thead><tbody>
    <?php foreach ($recent as $a): $d = json_decode((string)$a['details'], true) ?: []; ?>
        <tr><td class="small"><?= jdatetime($a['created_at']) ?></td><td class="small"><?= e(trim(($a['first_name'] ?? '') . ' ' . ($a['last_name'] ?? '')) ?: '—') ?></td>
            <td class="small"><?= $a['action'] === 'roles.grant_bulk' ? 'شارژ گروهی ' . e(RoleGrants::PACKAGES[$d['package'] ?? '']['title'] ?? '') . (!empty($d['again']) ? ' (دوباره)' : '') : 'تغییر تنظیمات' ?></td>
            <td class="small"><?= $a['action'] === 'roles.grant_bulk' ? fa((int)($d['charged'] ?? 0)) . ' شارژ · ' . fa((int)($d['skipped'] ?? 0)) . ' تکراری' . (!empty($d['failed']) ? ' · <b style="color:var(--danger)">' . fa((int)$d['failed']) . ' خطا</b>' : '') : '—' ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div></div>
<?php endif; ?>
