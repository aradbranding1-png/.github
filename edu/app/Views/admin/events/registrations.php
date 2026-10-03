<?php $reg = array_filter($rows, fn($r) => $r['status'] === 'registered'); ?>
<div class="page-head">
    <div><div class="crumbs"><a href="<?= url('/admin/events/' . $e['type']) ?>"><?= e($t['plural']) ?></a></div><h1><?= e($e['title']) ?></h1>
        <div class="sub"><?= jdate($e['starts_at'], 'l j F Y · H:i') ?> · <?= App\Services\Credit::format((int)$e['duration_minutes']) ?></div></div>
    <?php if ($rows && can('events.report')): ?><a class="btn btn-outline" href="<?= url('/admin/event/' . $e['id'] . '/registrations', ['export' => 1]) ?>"><?= icon('file-spreadsheet') ?> خروجی Excel</a><?php endif; ?>
</div>
<div class="grid g-3 mb-3">
    <div class="card stat tone-primary"><div class="bubble"><?= icon('users') ?></div><div><div class="v"><?= fa(count($reg)) ?></div><div class="l"><?= $e['type'] === 'meeting' ? 'حاضران' : 'ثبت‌نام فعال' ?></div></div></div>
    <div class="card stat tone-success"><div class="bubble"><?= icon('log-in') ?></div><div><div class="v"><?= fa(count(array_filter($rows, fn($r) => $r['joined_at']))) ?></div><div class="l">وارد جلسه شده‌اند</div></div></div>
    <div class="card stat tone-warning"><div class="bubble"><?= icon('clock') ?></div><div><div class="v"><?= $e['type'] === 'workshop' ? fa(array_sum(array_column($reg, 'cost'))) . ' عدد' : App\Services\Credit::format(array_sum(array_column($reg, 'cost'))) ?></div><div class="l">اعتبار کسرشده</div></div></div>
</div>
<div class="card flush"><div class="table-wrap"><table class="table">
    <thead><tr><th>کاربر</th><th>موبایل</th><th>نوع</th><th>ثبت‌نام</th><th>ورود به جلسه</th><th>وضعیت</th><th></th></tr></thead><tbody>
    <?php if (!$rows): ?><tr><td colspan="7"><div class="empty"><?= icon('users') ?><div>هنوز کسی ثبت‌نام نکرده است.</div></div></td></tr><?php endif; ?>
    <?php foreach ($rows as $r): ?>
        <tr>
            <td><a class="person" href="<?= url('/admin/users/' . $r['uid']) ?>" style="color:var(--text)"><?= avatar_html(['id' => $r['uid'], 'first_name' => $r['first_name'], 'last_name' => $r['last_name'], 'avatar_path' => $r['avatar_path'], 'is_root' => $r['is_root']], 'sm') ?><div class="nm"><?= person_name($r, 'uid') ?></div></a></td>
            <td class="ltr num"><?= e($r['mobile']) ?></td>
            <td><span class="badge badge-gray"><?= e(label('segment_one', $r['segment'])) ?></span></td>
            <td class="small nowrap"><?= jdatetime($r['created_at']) ?></td>
            <td class="small nowrap"><?= $r['joined_at'] ? jdatetime($r['joined_at']) . ' <span class="faint">(' . fa((int)$r['join_count']) . ' بار)</span>' : '<span class="faint">—</span>' ?></td>
            <td><?= $r['status'] === 'registered' ? '<span class="badge badge-success">ثبت‌نام شده</span>' : '<span class="badge badge-gray">لغو شده</span>' ?></td>
            <td class="actions"><?php if ($r['status'] === 'registered' && $e['type'] !== 'meeting' && can('events.edit')): ?>
                <form method="post" action="<?= url('/admin/event/' . $e['id'] . '/registrations/' . $r['id'] . '/cancel') ?>" data-confirm="ثبت‌نام لغو و <?= $r['cost'] ? 'اعتبار کسرشده برگشت داده' : 'حذف' ?> شود؟"><?= csrf_field() ?><input type="hidden" name="refund" value="1"><button class="btn btn-xs btn-ghost" style="color:var(--danger)"><?= icon('x') ?> لغو و بازگشت اعتبار</button></form>
            <?php endif; ?></td>
        </tr>
    <?php endforeach; ?>
</tbody></table></div></div>
