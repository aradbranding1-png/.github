<div class="page-head"><div><h1>پشتیبان‌گیری و بازیابی</h1><div class="sub">پشتیبان‌ها در storage/backups (خارج از Web Root) نگهداری می‌شوند و از طریق URL قابل دانلود نیستند. دانلود و بازیابی فقط برای مدیر کل و با تأیید رمز عبور.</div></div></div>
<div class="grid g-main">
    <div class="card flush"><div class="table-wrap"><table class="table"><thead><tr><th>فایل</th><th>نوع</th><th>حجم</th><th>نسخه</th><th>ایجاد</th><th></th></tr></thead><tbody>
    <?php foreach ($rows as $b): ?><tr>
        <td><b class="ltr small"><?= e($b['filename']) ?></b><?= $b['note'] ? '<div class="small faint">' . e($b['note']) . '</div>' : '' ?><?= $b['exists'] ? '' : '<span class="badge badge-danger">فایل موجود نیست</span>' ?></td>
        <td><span class="badge badge-<?= ['db' => 'info', 'pre_update' => 'purple', 'full' => 'success'][$b['type']] ?? 'gray' ?>"><?= e(['db' => 'دیتابیس', 'pre_update' => 'پیش از بروزرسانی', 'full' => 'کامل'][$b['type']] ?? $b['type']) ?></span></td>
        <td class="num"><?= human_size((int)$b['size']) ?></td><td class="ltr small"><?= e($b['app_version']) ?></td>
        <td class="small"><?= jdatetime($b['created_at']) ?><div class="faint"><?= e(trim(($b['first_name'] ?? '') . ' ' . ($b['last_name'] ?? ''))) ?: 'سیستم' ?></div></td>
        <td class="actions">
            <?php if (is_root() && $b['exists']): ?>
                <button class="btn btn-xs btn-ghost" data-open="dl-<?= (int)$b['id'] ?>" title="دانلود"><?= icon('download') ?></button>
                <button class="btn btn-xs btn-ghost" data-open="rs-<?= (int)$b['id'] ?>" title="بازیابی دیتابیس"><?= icon('rotate-ccw') ?></button>
                <dialog class="modal" id="dl-<?= (int)$b['id'] ?>"><div class="modal-head"><b>دانلود پشتیبان</b><button class="btn btn-ghost btn-icon" data-close><?= icon('x') ?></button></div><form class="modal-body" method="post" action="<?= url('/admin/system/backups/' . $b['id'] . '/download') ?>" data-no-busy><?= csrf_field() ?><?= confirm_password_field() ?><button class="btn btn-primary"><?= icon('download') ?> دانلود</button></form></dialog>
                <dialog class="modal" id="rs-<?= (int)$b['id'] ?>"><div class="modal-head"><b>بازیابی دیتابیس از این پشتیبان</b><button class="btn btn-ghost btn-icon" data-close><?= icon('x') ?></button></div><form class="modal-body" method="post" action="<?= url('/admin/system/backups/' . $b['id'] . '/restore') ?>"><?= csrf_field() ?><div class="alert alert-danger small"><?= icon('triangle-alert') ?> همه داده‌های فعلی دیتابیس با محتوای این پشتیبان جایگزین می‌شود. پیش از بازیابی، به صورت خودکار یک پشتیبان ایمنی از وضعیت فعلی گرفته می‌شود.</div><?= confirm_password_field() ?><button class="btn btn-danger"><?= icon('rotate-ccw') ?> بازیابی</button></form></dialog>
            <?php endif; ?>
            <?php if (can('backups.delete')): ?><form class="inline" method="post" action="<?= url('/admin/system/backups/' . $b['id'] . '/delete') ?>" data-confirm="پشتیبان حذف شود؟"><?= csrf_field() ?><button class="btn btn-xs btn-ghost" style="color:var(--danger)"><?= icon('trash-2') ?></button></form><?php endif; ?>
        </td></tr><?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="6"><div class="empty"><?= icon('hard-drive') ?><div>هنوز پشتیبانی تهیه نشده</div></div></td></tr><?php endif; ?>
    </tbody></table></div></div>
    <div class="stack">
        <?php if (can('backups.create')): ?>
        <form class="card" method="post" action="<?= url('/admin/system/backups') ?>"><?= csrf_field() ?>
            <h3><?= icon('plus') ?> تهیه پشتیبان</h3>
            <div class="field"><label>نوع</label><select name="type"><option value="db">فقط دیتابیس (شامل همه اطلاعات آموزشی)</option><option value="pre_update">دیتابیس + تنظیمات + کد برنامه</option><?php if (is_root()): ?><option value="full">کامل (به همراه فایل‌های آپلودشده)</option><?php endif; ?></select></div>
            <div class="field"><label>یادداشت</label><input type="text" name="note"></div>
            <button class="btn btn-grad w-100"><?= icon('hard-drive') ?> تهیه پشتیبان</button>
        </form>
        <?php endif; ?>
        <div class="card"><h3><?= icon('info') ?> نکات</h3><ul class="small muted" style="padding-right:1.2rem;margin:0">
            <li>Cron شبانه به صورت خودکار از دیتابیس پشتیبان می‌گیرد و ۱۰ نسخه آخر هر نوع را نگه می‌دارد.</li>
            <li>فضای آزاد دیسک: <?= $free !== false ? human_size($free) : '—' ?></li>
            <li>پیشنهاد: پشتیبان‌ها را به صورت دوره‌ای دانلود و در محل امن دیگری نگهداری کنید.</li>
            <li>حذف دوره یا فایل آموزشی، سوابق آموزشی کاربران را حذف نمی‌کند (حذف نرم).</li>
        </ul></div>
    </div>
</div>
