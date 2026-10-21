<?php
use App\Services\BaleBroadcast as BB;
$on = BB::enabled(); $configured = BB::configured();
$tok = (string)setting('bale_api_token', '');
$since = (string)setting('bale_since', '');
?>
<div class="page-head"><div><h1><?= icon('send') ?> ارسال خبر به ربات بله</h1><div class="sub">خبر هر وبینار و میتینگ جدید، و هر دوره‌ای که برای تاجران است، از طریق ربات بله my.aradbranding.me برای تاجران متصل ارسال می‌شود.</div></div>
    <div><?= $on ? '<span class="badge badge-success">فعال</span>' : '<span class="badge badge-danger">' . ($configured ? 'غیرفعال' : 'آدرس/توکن API تنظیم نشده') . '</span>' ?></div>
</div>

<div class="grid g-main mb-3">
    <form class="card" method="post" action="<?= url('/admin/integrations/bale') ?>"><?= csrf_field() ?>
        <h3><?= icon('settings') ?> تنظیمات اتصال</h3>
        <label class="exf-req switch mb-2"><input type="checkbox" name="enabled" value="1"<?= checked(setting('bale_enabled', '0') === '1') ?>><span><b>ارسال خودکار فعال باشد</b><small>فقط مواردی که بعد از اولین فعال‌سازی منتشر شوند ارسال می‌شوند<?= $since ? ' (از ' . jdatetime($since) . ')' : '' ?>.</small></span></label>
        <div class="form-grid">
            <div class="field full"><label>آدرس پایه API سامانه my</label><input class="ltr" type="url" name="api_url" value="<?= e(setting('bale_api_url', '')) ?>" placeholder="https://my.aradbranding.me"></div>
            <div class="field full"><label>توکن API</label><input class="ltr" type="password" name="api_token" autocomplete="new-password" placeholder="<?= $tok !== '' ? 'ذخیره شده (…' . e(substr($tok, -4)) . ') — برای تغییر، توکن جدید وارد کنید' : 'توکنی که برنامه‌نویس my می‌دهد' ?>">
                <?php if ($tok !== ''): ?><label class="check small mt-1"><input type="checkbox" name="clear_token" value="1"> حذف توکن</label><?php endif; ?></div>
            <div class="field"><label>تأخیر ارسال بعد از انتشار</label><div class="input-unit"><input type="number" name="delay_min" data-delay-input min="0" max="1440" value="<?= e((string)BB::delayMin()) ?>"><span>دقیقه</span></div><div class="hint">اگر در این فاصله حذف یا غیرفعال شود، ارسال نمی‌شود.</div></div>
            <div class="field"><label>چه چیزهایی ارسال شود</label>
                <label class="check"><input type="checkbox" name="events" value="1"<?= checked(setting('bale_events', '1') === '1') ?>> وبینارها و میتینگ‌های جدید</label>
                <label class="check"><input type="checkbox" name="courses" value="1"<?= checked(setting('bale_courses', '1') === '1') ?>> دوره‌های جدید (برای همه یا تاجران)</label></div>
        </div>
        <div class="flex flex-wrap"><button class="btn btn-primary"><?= icon('save') ?> ذخیره</button></div>
    </form>
    <div class="stack">
        <div class="card">
            <h3><?= icon('activity') ?> آمار ارسال</h3>
            <div class="exs-stats">
                <div><b><?= fa((int)($stats['sent'] ?? 0)) ?></b><span>خبر ارسال‌شده</span></div>
                <div class="is-ok"><b><?= nf((int)($stats['people'] ?? 0)) ?></b><span>مجموع گیرنده</span></div>
                <div class="is-warn"><b><?= fa((int)($stats['failed'] ?? 0)) ?></b><span>ناموفق</span></div>
            </div>
            <form method="post" action="<?= url('/admin/integrations/bale/test') ?>" class="mt-2"><?= csrf_field() ?><button class="btn btn-outline w-100"<?= $configured ? '' : ' disabled' ?>><?= icon('refresh-cw') ?> تست اتصال و شمارش تاجران متصل</button></form>
        </div>
        <div class="card small muted" style="line-height:2">
            <b><?= icon('info') ?> قوانین ارسال</b><br>
            • وبینار و میتینگ: <b data-delay-mirror><?= fa(BB::delayMin()) ?></b> دقیقه بعد از ساخت، اگر هنوز منتشرشده و تمام‌نشده باشد.<br>
            • دوره: <b data-delay-mirror><?= fa(BB::delayMin()) ?></b> دقیقه بعد از انتشار، اگر مخاطب آن «همه» یا «تاجران» (یا زیرگروه آن) باشد؛ دوره‌های فقط کارمندان/نمایندگان ارسال نمی‌شوند.<br>
            • گیرندگان: تاجرانِ متصل به ربات در همان لحظه، طبق فهرست my.<br>
            • هر خبر فقط یک بار ارسال می‌شود. بررسی هر ۱۵ دقیقه با Cron انجام می‌شود.
        </div>
    </div>
</div>

<div class="card flush mb-3">
    <div class="card-head" style="padding:1rem 1.25rem"><h3><?= icon('clock') ?> در انتظار ارسال</h3><span class="small muted">مواردی که هنوز ارسال نشده‌اند</span></div>
    <?php if ($upcoming): ?><div class="crows">
    <?php foreach ($upcoming as $it): $due = strtotime($it['due_at']) <= time(); ?>
        <div class="crow">
            <span class="crow-ic" style="--tc:<?= $it['type'] === 'event' ? 'var(--primary)' : 'var(--success)' ?>"><?= icon($it['type'] === 'event' ? 'calendar' : 'graduation-cap') ?></span>
            <span class="crow-main"><span class="crow-title"><?= e($it['title']) ?></span><span class="crow-meta"><span><?= icon('clock') ?> <?= $due ? 'در اجرای بعدی Cron' : 'زمان ارسال: ' . jdatetime($it['due_at']) ?></span></span></span>
            <span class="crow-side"><span class="crow-acts">
                <form method="post" action="<?= url('/admin/integrations/bale/action') ?>"><?= csrf_field() ?><input type="hidden" name="ref" value="<?= e($it['ref']) ?>"><input type="hidden" name="title" value="<?= e($it['title']) ?>"><input type="hidden" name="act" value="send"><button class="btn btn-sm btn-outline"<?= $configured ? '' : ' disabled' ?>><?= icon('send') ?> ارسال الان</button></form>
                <form method="post" action="<?= url('/admin/integrations/bale/action') ?>" data-confirm="این مورد هرگز به بله ارسال نشود؟"><?= csrf_field() ?><input type="hidden" name="ref" value="<?= e($it['ref']) ?>"><input type="hidden" name="title" value="<?= e($it['title']) ?>"><input type="hidden" name="act" value="skip"><button class="btn btn-sm btn-ghost" style="color:var(--danger)"><?= icon('x') ?> ارسال نشود</button></form>
            </span></span>
        </div>
    <?php endforeach; ?>
    </div><?php else: ?><div class="empty small"><?= icon('circle-check') ?><div><?= $since ? 'موردی در انتظار ارسال نیست.' : 'پس از فعال‌سازی، موارد جدید اینجا نمایش داده می‌شوند.' ?></div></div><?php endif; ?>
</div>

<div class="card flush">
    <div class="card-head" style="padding:1rem 1.25rem"><h3><?= icon('history') ?> سابقه ارسال‌ها</h3></div>
    <?php if ($page['rows']): ?><div class="crows">
    <?php foreach ($page['rows'] as $m): $st = BB::STATUSES[$m['status']] ?? [$m['status'], 'gray']; ?>
        <div class="crow">
            <span class="crow-ic" style="--tc:<?= $m['ref_type'] === 'event' ? 'var(--primary)' : 'var(--success)' ?>"><?= icon($m['ref_type'] === 'event' ? 'calendar' : 'graduation-cap') ?></span>
            <span class="crow-main"><span class="crow-title"><?= e($m['title'] ?: $m['ref']) ?></span>
                <span class="crow-meta">
                    <span><?= icon('clock') ?> <?= jdatetime($m['sent_at'] ?: $m['created_at']) ?></span>
                    <?php if ($m['recipients'] !== null): ?><span><?= icon('users') ?> <?= nf((int)$m['recipients']) ?> گیرنده</span><?php endif; ?>
                    <?php if ($m['error']): ?><span class="crow-warn" title="<?= e($m['error']) ?>"><?= icon('triangle-alert') ?> <?= e(str_limit($m['error'], 70)) ?></span><?php endif; ?>
                </span></span>
            <span class="crow-side"><span class="badge badge-<?= $st[1] ?>"><?= e($st[0]) ?></span>
                <?php if ($m['status'] === 'failed'): ?><form method="post" action="<?= url('/admin/integrations/bale/action') ?>"><?= csrf_field() ?><input type="hidden" name="ref" value="<?= e($m['ref']) ?>"><input type="hidden" name="act" value="retry"><button class="btn btn-sm btn-outline"><?= icon('rotate-ccw') ?> ارسال دوباره</button></form><?php endif; ?>
            </span>
        </div>
    <?php endforeach; ?>
    </div><div style="padding:0 1rem 1rem"><?= paginate_links($page) ?></div>
    <?php else: ?><div class="empty small"><?= icon('send') ?><div>هنوز پیامی ارسال نشده است.</div></div><?php endif; ?>
</div>
