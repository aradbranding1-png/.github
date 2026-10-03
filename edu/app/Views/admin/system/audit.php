<?php $q = $_GET; unset($q['r'], $q['page']); ?>
<div class="page-head"><div><h1>Audit Log — رویدادنگاری مدیریتی</h1><div class="sub">کاربر، عملیات، تاریخ، ساعت، IP، هدف و نتیجه همه اقدامات مدیریتی و امنیتی</div></div>
<?php if (can('audit.export')): ?><a class="btn btn-outline" href="<?= url('/admin/audit', $q + ['export' => 1]) ?>"><?= icon('file-spreadsheet') ?> Excel</a><?php endif; ?></div>
<form class="card filters" method="get" action="<?= url('/admin/audit') ?>">
    <div class="field grow"><label>عملیات</label><input type="search" name="action" value="<?= e($_GET['action'] ?? '') ?>" placeholder="مثلاً users. یا auth.login"></div>
    <div class="field"><label>شناسه کاربر</label><input type="number" name="user" value="<?= e($_GET['user'] ?? '') ?>"></div>
    <div class="field"><label>نتیجه</label><select name="result"><option value="">همه</option><?php foreach (['success', 'fail', 'denied'] as $r): ?><option value="<?= $r ?>"<?= selected($r, $_GET['result'] ?? '') ?>><?= e(label('status', $r)) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label>از تاریخ</label><input class="ltr" type="text" name="from" value="<?= e($_GET['from'] ?? '') ?>" placeholder="1405/07/01"></div>
    <div class="field"><label>تا تاریخ</label><input class="ltr" type="text" name="to" value="<?= e($_GET['to'] ?? '') ?>" placeholder="1405/07/30"></div>
    <button class="btn btn-primary"><?= icon('filter') ?></button>
</form>
<div class="card flush mb-3"><div class="table-wrap"><table class="table"><thead><tr><th>تاریخ و ساعت</th><th>کاربر</th><th>عملیات</th><th>هدف</th><th>نتیجه</th><th>IP</th><th>جزئیات</th></tr></thead><tbody>
<?php foreach ($page['rows'] as $a): ?><tr>
    <td class="num small nowrap"><?= jdate($a['created_at']) ?><br><span class="faint"><?= fa(date('H:i:s', strtotime($a['created_at']))) ?></span></td>
    <td class="small"><?= e(trim(($a['first_name'] ?? '') . ' ' . ($a['last_name'] ?? '')) ?: 'سیستم/مهمان') ?><?= $a['imp_first'] ? '<div class="badge badge-warning">' . icon('venetian-mask') . ' توسط ' . e($a['imp_first'] . ' ' . $a['imp_last']) . '</div>' : '' ?></td>
    <td><code class="ltr small"><?= e($a['action']) ?></code><div class="small faint"><?= e(App\Core\Labels::AUDIT[$a['action']] ?? '') ?></div></td>
    <td class="small ltr"><?= e($a['target_type']) ?><?= $a['target_id'] ? ' #' . (int)$a['target_id'] : '' ?></td>
    <td><?= status_badge($a['result']) ?></td><td class="ltr small num"><?= e($a['ip']) ?></td>
    <td class="small ltr" style="max-width:340px;word-break:break-all"><?= e(str_limit($a['details'], 160)) ?></td>
</tr><?php endforeach; ?>
</tbody></table></div></div>
<?= paginate_links($page) ?>
<?php if ($imps): ?>
<div class="card flush mt-3"><div class="card-head" style="padding:1rem 1.25rem"><h3><?= icon('venetian-mask') ?> سوابق ورود مدیر کل به حساب کاربران</h3></div><div class="table-wrap"><table class="table"><thead><tr><th>مدیر کل</th><th>کاربر مقصد</th><th>شروع</th><th>پایان</th><th>مدت</th><th>IP</th></tr></thead><tbody>
<?php foreach ($imps as $i): ?><tr><td><?= e($i['r_first'] . ' ' . $i['r_last']) ?></td><td><a href="<?= url('/admin/users/' . $i['target_id']) ?>"><?= e($i['t_first'] . ' ' . $i['t_last']) ?></a></td><td class="num small"><?= jdatetime($i['started_at']) ?></td><td class="num small"><?= $i['ended_at'] ? jdatetime($i['ended_at']) : '<span class="badge badge-warning">در جریان</span>' ?></td><td class="num small"><?= $i['duration_sec'] !== null ? fa(round($i['duration_sec'] / 60, 1)) . ' دقیقه' : '—' ?></td><td class="ltr small"><?= e($i['ip']) ?></td></tr><?php endforeach; ?>
</tbody></table></div></div>
<?php endif; ?>
