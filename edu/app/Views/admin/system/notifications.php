<div class="page-head"><div><h1>ارسال اعلان</h1><div class="sub">اطلاعیه درون‌سامانه‌ای برای همه یا گروه مشخصی از کاربران. اعلان‌های خودکار (آموزش جدید، مهلت، نتیجه آزمون، اصلاح تمرین، عدم فعالیت) به صورت خودکار ارسال می‌شوند.</div></div></div>
<div class="grid g-main">
    <div class="card flush"><div class="card-head" style="padding:1rem 1.25rem"><h3><?= icon('history') ?> اعلان‌های ارسال‌شده</h3></div>
        <?php foreach ($recent as $r): ?><div class="list-item" style="padding:.8rem 1.25rem"><span class="ico" style="background:var(--primary-soft);color:var(--primary)"><?= icon('bell') ?></span><div class="grow"><b><?= e($r['title']) ?></b><div class="small muted"><?= e(str_limit($r['body'], 120)) ?></div><div class="small faint"><?= jdatetime($r['created_at']) ?></div></div><div class="text-center"><b><?= fa($r['n']) ?></b><div class="small faint">گیرنده · <?= fa($r['seen']) ?> خوانده</div></div></div><?php endforeach; ?>
        <?php if (!$recent): ?><div class="empty"><?= icon('bell') ?><div>هنوز اعلانی ارسال نشده</div></div><?php endif; ?>
    </div>
    <?php if (can('notifications.create')): ?>
    <form class="card" method="post" action="<?= url('/admin/notifications') ?>"><?= csrf_field() ?>
        <h3><?= icon('send') ?> اعلان جدید</h3>
        <div class="field"><label>عنوان</label><input type="text" name="title" required></div>
        <div class="field"><label>متن</label><textarea name="body" rows="4"></textarea></div>
        <div class="field"><label>لینک داخلی (اختیاری)</label><input class="ltr" type="text" name="link" placeholder="/learn/catalog"></div>
        <div class="field"><label>مخاطبان</label><select name="target" data-toggle-target><option value="all">همه کاربران</option><option value="segment">نوع کاربر</option><option value="group">گروه</option><option value="role">نقش</option><option value="org_unit">واحد سازمانی</option></select></div>
        <div class="field hide" data-target-box="segment"><select name="segment"><?php foreach (['merchant', 'employee', 'agent'] as $s): ?><option value="<?= $s ?>"><?= e(label('segment', $s)) ?></option><?php endforeach; ?></select></div>
        <div class="field hide" data-target-box="group"><select name="group_id"><?php foreach ($groups as $k => $v): ?><option value="<?= (int)$k ?>"><?= e($v) ?></option><?php endforeach; ?></select></div>
        <div class="field hide" data-target-box="role"><select name="role_id"><?php foreach ($roles as $k => $v): ?><option value="<?= (int)$k ?>"><?= e($v) ?></option><?php endforeach; ?></select></div>
        <div class="field hide" data-target-box="org_unit"><select name="org_id"><?php foreach ($orgs as $k => $v): ?><option value="<?= (int)$k ?>"><?= e($v) ?></option><?php endforeach; ?></select></div>
        <button class="btn btn-grad w-100" data-confirm="اعلان ارسال شود؟"><?= icon('send') ?> ارسال</button>
    </form>
    <?php endif; ?>
</div>
