<?php
use App\Services\AradContact;
$q = $_GET; unset($q['r'], $q['page']);
$hint = (string)($s['arad_contact_token_hint'] ?? '');
?>
<div class="page-head"><div><h1>اتصال آراد کانتکت</h1><div class="sub">ساخت خودکار حساب تاجر و شارژ خدمات خریداری‌شده از سامانه آراد کانتکت — مستندات: docs/ARAD-CONTACT.md</div></div>
    <div><?= AradContact::enabled() && $configured ? '<span class="badge badge-success">فعال</span>' : '<span class="badge badge-danger">' . (!$configured ? 'توکن تعریف نشده' : 'غیرفعال') . '</span>' ?></div></div>

<?php if ($newToken): ?>
<div class="alert alert-success"><?= icon('key-round') ?><div><b>توکن جدید ساخته شد. این توکن فقط همین یک بار نمایش داده می‌شود؛ آن را در تنظیمات آراد کانتکت ثبت کنید:</b>
    <div class="flex mt-1"><code class="ltr grow" style="background:#fff;padding:.4rem .6rem;border-radius:8px;color:#065f46"><?= e($newToken) ?></code><button type="button" class="btn btn-sm btn-outline" data-copy="<?= e($newToken) ?>"><?= icon('copy') ?></button></div></div></div>
<?php endif; ?>

<div class="grid g-4 mb-3">
    <div class="card stat tone-primary"><div class="bubble"><?= icon('receipt') ?></div><div><div class="v"><?= nf($stats['orders'] ?? 0) ?></div><div class="l">سفارش اعمال‌شده (۳۰ روز)</div></div></div>
    <div class="card stat tone-success"><div class="bubble"><?= icon('user-plus') ?></div><div><div class="v"><?= nf($stats['created'] ?? 0) ?></div><div class="l">حساب جدید ساخته‌شده</div></div></div>
    <div class="card stat tone-info"><div class="bubble"><?= icon('repeat') ?></div><div><div class="v"><?= nf($stats['dups'] ?? 0) ?></div><div class="l">درخواست تکراری (بدون شارژ مجدد)</div></div></div>
    <div class="card stat tone-danger"><div class="bubble"><?= icon('triangle-alert') ?></div><div><div class="v"><?= nf($stats['failed'] ?? 0) ?></div><div class="l">درخواست ناموفق<?= !empty($stats['last_at']) ? ' · آخرین تماس ' . e(time_ago($stats['last_at'])) : '' ?></div></div></div>
</div>

<div class="grid g-2 mb-3">
    <div class="card">
        <h3><?= icon('key-round') ?> توکن اتصال (Bearer)</h3>
        <p class="small muted">آراد کانتکت هر درخواست را با هدر <code class="ltr">Authorization: Bearer &lt;توکن&gt;</code> ارسال می‌کند. فقط هش توکن ذخیره می‌شود.</p>
        <div class="mb-2">
            <?php if ((string)($s['arad_contact_token_hash'] ?? '') !== ''): ?>
                <span class="badge badge-success">تعریف شده</span> <span class="ltr small">…<?= e($hint) ?></span>
                <?php if (!empty($s['arad_contact_token_set_at'])): ?><span class="small faint"> · <?= jdatetime($s['arad_contact_token_set_at']) ?></span><?php endif; ?>
            <?php else: ?><span class="badge badge-gray">در تنظیمات تعریف نشده</span><?php endif; ?>
            <?php if ($envToken): ?> <span class="badge badge-info">ARAD_CONTACT_TOKEN در .env نیز تعریف شده و پذیرفته می‌شود</span><?php endif; ?>
        </div>
        <?php if (is_root()): ?>
            <form method="post" action="<?= url('/admin/integrations/arad-contact/token') ?>" class="inline"><?= csrf_field() ?><input type="hidden" name="op" value="generate">
                <button class="btn btn-primary btn-sm"<?= (string)($s['arad_contact_token_hash'] ?? '') !== '' ? ' data-confirm="توکن فعلی باطل و توکن جدید ساخته شود؟ آراد کانتکت تا ثبت توکن جدید نمی‌تواند درخواست بفرستد."' : '' ?>><?= icon('refresh-cw') ?> ساخت توکن تصادفی</button></form>
            <?php if ((string)($s['arad_contact_token_hash'] ?? '') !== ''): ?>
            <form method="post" action="<?= url('/admin/integrations/arad-contact/token') ?>" class="inline" data-confirm="توکن حذف شود؟"><?= csrf_field() ?><input type="hidden" name="op" value="clear">
                <button class="btn btn-outline btn-sm" style="color:var(--danger)"><?= icon('x') ?> حذف توکن</button></form>
            <?php endif; ?>
            <form method="post" action="<?= url('/admin/integrations/arad-contact/token') ?>" class="mt-2"><?= csrf_field() ?><input type="hidden" name="op" value="custom">
                <div class="field"><label>یا توکنی که آراد کانتکت داده را وارد کنید</label>
                    <div class="flex"><input class="ltr grow" type="password" name="token" minlength="24" maxlength="200" autocomplete="off" placeholder="حداقل ۲۴ کاراکتر" required><button class="btn btn-outline"><?= icon('save') ?> ثبت</button></div></div>
            </form>
        <?php else: ?>
            <div class="hint">تعریف یا تغییر توکن فقط توسط مدیر کل امکان‌پذیر است.</div>
        <?php endif; ?>
    </div>

    <form class="card" method="post" action="<?= url('/admin/integrations/arad-contact') ?>"><?= csrf_field() ?>
        <fieldset style="border:0;padding:0;margin:0" <?= $editable ? '' : 'disabled' ?>>
        <h3><?= icon('settings') ?> تنظیمات</h3>
        <label class="switch mb-2"><input type="checkbox" name="enabled" value="1"<?= checked(($s['arad_contact_enabled'] ?? '1') === '1') ?>> اتصال فعال باشد</label>
        <label class="switch mb-2"><input type="checkbox" name="auto_activate" value="1"<?= checked(($s['arad_contact_auto_activate'] ?? '1') === '1') ?>> حساب‌های ساخته‌شده از خرید، بدون نیاز به تأیید فعال شوند (حساب «در انتظار تأیید» موجود هم با خرید فعال می‌شود)</label>
        <div class="grid g-3">
            <div class="field"><label>نقش حساب جدید</label><select name="role_id"><option value="0">خودکار («<?= e(AradContact::DEFAULT_ROLE) ?>»)</option><?php foreach ($roles as $r): ?><option value="<?= (int)$r['id'] ?>"<?= selected((string)$r['id'], $s['arad_contact_role_id'] ?? '0') ?>><?= e($r['name']) ?></option><?php endforeach; ?></select></div>
            <div class="field"><label>گروه</label><select name="group_id"><option value="0">خودکار («<?= e(AradContact::DEFAULT_GROUP) ?>»)</option><?php foreach ($groups as $g): ?><option value="<?= (int)$g['id'] ?>"<?= selected((string)$g['id'], $s['arad_contact_group_id'] ?? '0') ?>><?= $g['parent_id'] ? '— ' : '' ?><?= e($g['name']) ?></option><?php endforeach; ?></select></div>
            <div class="field"><label>سطح</label><select name="level_id"><option value="0">خودکار («<?= e(AradContact::DEFAULT_LEVEL) ?>»)</option><?php foreach ($levels as $l): ?><option value="<?= (int)$l['id'] ?>"<?= selected((string)$l['id'], $s['arad_contact_level_id'] ?? '0') ?>><?= e($l['group_name'] . ' ← ' . $l['name']) ?></option><?php endforeach; ?></select></div>
        </div>
        <div class="hint mb-2">اکنون اعمال می‌شود: نقش <b><?= e($resolved['role'] ?? '—') ?></b>، گروه <b><?= e($resolved['group'] ?? '—') ?></b>، سطح <b><?= e($resolved['level'] ?? 'پیدا نشد') ?></b> · نوع کاربر: تاجر · نام کاربری: موبایل · رمز: تصادفی ۱۰ کاراکتری با تغییر اجباری در اولین ورود</div>
        <div class="field"><label>IPهای مجاز (اختیاری، با کاما جدا کنید؛ خالی = همه)</label><input class="ltr" type="text" name="allowed_ips" value="<?= e($s['arad_contact_allowed_ips'] ?? '') ?>" placeholder="185.1.2.3, 185.1.2.4"></div>
        <?php if ($editable): ?><button class="btn btn-grad"><?= icon('save') ?> ذخیره</button><?php endif; ?>
        </fieldset>
    </form>
</div>

<div class="card mb-3">
    <h3><?= icon('code') ?> آدرس‌ها و خدمات</h3>
    <div class="table-wrap"><table class="table small">
        <tbody>
            <tr><td class="nowrap">فهرست خدمات</td><td class="ltr"><code>GET <?= e($baseApi) ?>/services</code></td></tr>
            <tr><td class="nowrap">اعمال خرید</td><td class="ltr"><code>POST <?= e($baseApi) ?>/provision</code></td></tr>
        </tbody>
    </table></div>
    <div class="table-wrap mt-2"><table class="table small">
        <thead><tr><th>کد</th><th>عنوان</th><th>واحد</th><th>اثر در حساب کاربر</th></tr></thead>
        <tbody>
        <?php foreach (AradContact::SERVICES as $code => $svc): ?>
            <tr><td class="ltr"><code><?= e($code) ?></code></td><td><?= e($svc['title']) ?></td><td><?= e(implode('، ', array_keys($svc['units']))) ?></td>
                <td><?= e(['account' => 'تمدید اکانت سامانه (هر واحد ۱۲ ماه)', 'webinar' => 'افزایش اعتبار وبینار (هر ساعت ۶۰ دقیقه)', 'workshop' => 'افزایش اعتبار کارگاه (عدد)', 'course' => 'افزایش اعتبار دوره‌ها/درس‌ها (هر ساعت ۶۰ دقیقه)', 'meeting' => 'تمدید اشتراک میتینگ آنلاین (هر واحد ۱۲ ماه)'][$svc['credit']] ?? $svc['credit']) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>

<div class="page-head"><div><h2 class="mb-0"><?= icon('scroll-text') ?> لاگ درخواست‌ها</h2></div></div>
<form class="card filters" method="get" action="<?= url('/admin/integrations/arad-contact') ?>">
    <div class="field grow"><label>جست‌وجو</label><input type="search" name="q" value="<?= e($_GET['q'] ?? '') ?>" placeholder="external_id یا موبایل"></div>
    <div class="field"><label>نتیجه</label><select name="status"><option value="">همه</option><?php foreach (['ok' => 'موفق', 'fail' => 'ناموفق', 'dup' => 'تکراری', 'new' => 'حساب جدید'] as $k => $t): ?><option value="<?= $k ?>"<?= selected($k, $_GET['status'] ?? '') ?>><?= e($t) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label>مسیر</label><select name="endpoint"><option value="">همه</option><option value="provision"<?= selected('provision', $_GET['endpoint'] ?? '') ?>>provision</option><option value="services"<?= selected('services', $_GET['endpoint'] ?? '') ?>>services</option></select></div>
    <button class="btn btn-primary"><?= icon('filter') ?></button>
</form>
<div class="card flush"><div class="table-wrap"><table class="table">
    <thead><tr><th>زمان</th><th>مسیر</th><th>کد</th><th>external_id</th><th>موبایل / کاربر</th><th>نتیجه</th><th>IP</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($page['rows'] as $l): ?>
        <tr>
            <td class="num small nowrap"><?= jdate($l['created_at']) ?><br><span class="faint"><?= fa(date('H:i:s', strtotime($l['created_at']))) ?></span></td>
            <td class="ltr small"><?= e($l['method'] . ' ' . $l['endpoint']) ?></td>
            <td><span class="badge badge-<?= (int)$l['http_status'] < 300 ? 'success' : ((int)$l['http_status'] >= 500 ? 'danger' : 'warning') ?>"><?= (int)$l['http_status'] ?></span></td>
            <td class="ltr small" style="max-width:220px;word-break:break-all"><?= e($l['external_id'] ?? '—') ?></td>
            <td class="small"><span class="ltr"><?= e($l['mobile'] ?? '') ?></span><?php if ($l['user_id']): ?><br><a href="<?= url('/admin/users/' . (int)$l['user_id']) ?>"><?= e(trim(($l['first_name'] ?? '') . ' ' . ($l['last_name'] ?? '')) ?: '#' . (int)$l['user_id']) ?></a><?php endif; ?></td>
            <td class="small">
                <?php if ((int)$l['success'] === 1): ?><span class="badge badge-success">موفق</span><?php else: ?><span class="badge badge-danger">ناموفق</span><?php endif; ?>
                <?php if ((int)$l['user_created'] === 1): ?><span class="badge badge-info">حساب جدید</span><?php endif; ?>
                <?php if ((int)$l['duplicate'] === 1): ?><span class="badge badge-gray">تکراری</span><?php endif; ?>
                <?php if ($l['message']): ?><div class="faint"><?= e(str_limit($l['message'], 110)) ?></div><?php endif; ?>
            </td>
            <td class="ltr small num"><?= e($l['ip']) ?></td>
            <td class="actions"><a class="btn btn-xs btn-ghost" href="<?= url('/admin/integrations/arad-contact/logs/' . (int)$l['id']) ?>"><?= icon('eye') ?></a></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$page['rows']): ?><tr><td colspan="8"><div class="empty"><?= icon('scroll-text') ?><div>هنوز درخواستی ثبت نشده است.</div></div></td></tr><?php endif; ?>
    </tbody>
</table></div></div>
<?= paginate_links($page) ?>
