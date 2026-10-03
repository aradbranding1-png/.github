<?php $cnt = array_count_values(array_column($rows, 'status')); ?>
<div class="page-head"><div><h1>Migration Dashboard</h1><div class="sub">وضعیت تغییرات ساختار دیتابیس: اجرا شده، اجرا نشده، ناموفق — به ترتیب اجرا</div></div>
<?php if (is_root() && (($cnt['pending'] ?? 0) + ($cnt['failed'] ?? 0))): ?><form method="post" action="<?= url('/admin/system/migrations/run') ?>" data-confirm="Migrationهای اجرانشده اجرا شوند؟ پیشنهاد می‌شود ابتدا پشتیبان تهیه کنید."><?= csrf_field() ?><button class="btn btn-grad"><?= icon('play') ?> اجرای Migrationهای معلق</button></form><?php endif; ?></div>
<div class="grid g-4 mb-3">
    <div class="card stat tone-success"><div class="bubble"><?= icon('circle-check') ?></div><div><div class="v"><?= fa($cnt['success'] ?? 0) ?></div><div class="l">اجرا شده</div></div></div>
    <div class="card stat tone-warning"><div class="bubble"><?= icon('clock') ?></div><div><div class="v"><?= fa($cnt['pending'] ?? 0) ?></div><div class="l">اجرا نشده / جدید</div></div></div>
    <div class="card stat tone-danger"><div class="bubble"><?= icon('circle-x') ?></div><div><div class="v"><?= fa($cnt['failed'] ?? 0) ?></div><div class="l">ناموفق</div></div></div>
    <div class="card stat tone-info"><div class="bubble"><?= icon('database') ?></div><div><div class="v"><?= fa(count($rows)) ?></div><div class="l">کل</div></div></div>
</div>
<div class="card flush"><div class="table-wrap"><table class="table"><thead><tr><th>ترتیب</th><th>Migration</th><th>شرح</th><th>وضعیت</th><th>Batch</th><th>تاریخ اجرا</th><th>زمان</th><th>Error</th></tr></thead><tbody>
<?php foreach ($rows as $m): ?><tr><td class="num"><?= fa($m['order']) ?></td><td class="ltr small"><code><?= e($m['migration']) ?></code></td><td class="small"><?= e($m['description']) ?></td>
<td><?= $m['status'] === 'pending' ? '<span class="badge badge-warning">اجرا نشده</span>' : ($m['status'] === 'failed' ? '<span class="badge badge-danger">ناموفق</span>' : ($m['status'] === 'orphan' ? '<span class="badge badge-gray">فایل موجود نیست</span>' : '<span class="badge badge-success">اجرا شده</span>')) ?></td>
<td class="num"><?= $m['batch'] ? fa($m['batch']) : '—' ?></td><td class="num small"><?= $m['executed_at'] ? jdatetime($m['executed_at']) : '—' ?></td><td class="num small"><?= $m['duration_ms'] !== null ? fa($m['duration_ms']) . 'ms' : '—' ?></td><td class="small ltr" style="color:var(--danger);max-width:300px"><?= e($m['error']) ?></td></tr><?php endforeach; ?>
</tbody></table></div></div>
<p class="small faint mt-2">اجرای Migration فقط برای مدیر کل مجاز است و در Audit Log ثبت می‌شود. Migrationهای جدید معمولاً از طریق بسته بروزرسانی و به صورت خودکار اجرا می‌شوند.</p>
