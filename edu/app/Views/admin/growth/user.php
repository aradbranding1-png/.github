<?php
use App\Services\TraderGrowth;
$trackNames = array_column($tracks, 'name', 'id');
$c = $ev['cur'];
$cur = currency_label();
$statusLbl = ['pending' => ['در انتظار بررسی', 'warning'], 'approved' => ['تأیید شده', 'success'], 'rejected' => ['رد / نیاز به اصلاح', 'danger']];
$uid = (int)$u['id'];
?>
<div class="crumbs"><a href="<?= url('/admin/growth/traders') ?>">جایگاه تاجران</a><?php if (can('users.view')): ?> / <a href="<?= url('/admin/users/' . $uid) ?>">پروفایل کاربر</a><?php endif; ?></div>
<div class="card hero tg-hero mb-3">
    <div class="flex between flex-wrap gap-2">
        <div class="flex gap-2 flex-wrap" style="align-items:center">
            <?= avatar_html($u, 'lg') ?>
            <div><h1 class="mb-0"><?= e(full_name($u)) ?></h1><div class="muted small ltr" style="text-align:right"><?= e($u['mobile']) ?> · #<?= $uid ?></div></div>
            <?php if ($participant && $ev['count']): ?><?= TraderGrowth::badge($ev['current'], true, 'xl') ?><?php endif; ?>
        </div>
        <?php if ($participant && $ev['count']): ?>
        <div class="text-center"><?= progress_ring($ev['all_done'] ? 100 : (float)($c['shown'] ?? 0), 104, 'white') ?><div class="small muted mt-1">مرحله <?= fa($ev['current']) ?>: <?= e($c['stage']['title'] ?? '') ?></div></div>
        <?php endif; ?>
    </div>
    <?php if ($participant && $ev['count']): ?><div class="tg-overall"><div class="flex between small"><span>پیشرفت کل مسیر</span><b><?= fa(round($ev['overall'])) ?>٪</b></div><?= progress_bar($ev['overall']) ?></div><?php endif; ?>
</div>
<?php if (!$participant): ?><div class="alert alert-warning"><?= icon('info') ?><div>نوع این کاربر (<?= e(label('segment_one', $u['segment'])) ?>) جزو مخاطبان نظام رشد نیست. از <a href="<?= url('/admin/growth/settings') ?>">تنظیمات نظام رشد</a> می‌توانید مخاطبان را تغییر دهید.</div></div><?php endif; ?>

<div class="grid g-main mb-3">
    <div class="card"><h3><?= icon('list-checks') ?> باقی‌مانده تا مرحله بعد</h3><?php include APP_PATH . '/Views/partials/growth_remaining.php'; ?></div>
    <div class="stack">
        <?php if (can('growth.approve') && $ev['count']): ?>
        <form class="card" method="post" action="<?= url('/admin/growth/user/' . $uid . '/place') ?>" data-confirm="مرحله این تاجر تغییر کند؟ مراحل قبل از مرحله انتخابی «معاف» می‌شوند."><?= csrf_field() ?>
            <h3><?= icon('trending-up') ?> تعیین مرحله</h3>
            <div class="field"><select name="stage_no"><?php foreach ($ev['stages'] as $o): ?><option value="<?= $o['no'] ?>"<?= selected($o['no'], $ev['current']) ?>><?= fa($o['no']) ?>. <?= e($o['stage']['title']) ?></option><?php endforeach; ?></select></div>
            <div class="field"><input type="text" name="note" placeholder="علت (مثلاً: تاجر فعال صادراتی از قبل)"></div>
            <button class="btn btn-primary btn-sm w-100"><?= icon('check') ?> ثبت مرحله</button>
            <div class="hint">برای تاجرانی که از قبل فعال بوده‌اند؛ مراحل قبلی معاف می‌شوند.</div>
        </form>
        <?php endif; ?>
        <form class="card" method="post" action="<?= url('/admin/growth/user/' . $uid . '/track') ?>"><?= csrf_field() ?>
            <h3><?= icon('route') ?> مسیر تجاری</h3>
            <div class="flex"><select name="track_id" class="grow"><option value="">انتخاب‌نشده</option><?php foreach ($tracks as $t): ?><option value="<?= (int)$t['id'] ?>"<?= selected($t['id'], $u['tg_track_id']) ?>><?= e($t['name']) ?></option><?php endforeach; ?></select><?php if (can('growth.approve') || can('growth.edit')): ?><button class="btn btn-outline btn-sm"><?= icon('save') ?></button><?php endif; ?></div>
        </form>
    </div>
</div>

<div class="grid g-main mb-3">
    <div class="card" id="services">
        <div class="card-head"><h3><?= icon('package') ?> خدمات خریداری‌شده</h3>
            <?php if ($configured && (can('growth.run') || can('growth.approve'))): ?><form method="post" action="<?= url('/admin/growth/user/' . $uid . '/sync') ?>"><?= csrf_field() ?><button class="btn btn-primary btn-sm"><?= icon('refresh-cw') ?> بروزرسانی خدمات</button></form><?php endif; ?></div>
        <div class="small faint mb-2"><?= icon('clock') ?> آخرین بروزرسانی موفق: <?= $u['tg_services_at'] ? jdatetime($u['tg_services_at']) : 'هرگز' ?> · آخرین ارتباط با API: <?= $u['tg_services_api_at'] ? jdatetime($u['tg_services_api_at']) : '—' ?>
            <?php if ($u['tg_services_error']): ?><div class="alert alert-danger mt-1 mb-0"><?= icon('triangle-alert') ?><div><?= e($u['tg_services_error']) ?></div></div><?php endif; ?>
            <?php if (!$configured): ?><div class="alert alert-info mt-1 mb-0"><?= icon('info') ?><div>اتصال به سامانه فروش هنوز تنظیم نشده؛ <a href="<?= url('/admin/growth/services#api') ?>">تنظیم اتصال</a>. تا آن زمان می‌توانید خدمات را دستی ثبت کنید.</div></div><?php endif; ?></div>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>خدمت (سامانه فروش)</th><th>خدمت در نظام رشد</th><th>شماره</th><th>وضعیت</th><th>تاریخ</th><th>منبع</th><th></th></tr></thead>
            <tbody>
            <?php if (!$services): ?><tr><td colspan="7" class="faint small">خدمتی ثبت نشده است.</td></tr><?php endif; ?>
            <?php foreach ($services as $s): ?>
                <tr<?= (int)$s['is_active'] ? '' : ' style="opacity:.6"' ?>>
                    <td class="small"><b><?= e($s['service_name']) ?></b><?= $s['service_code'] ? ' <span class="faint ltr">' . e($s['service_code']) . '</span>' : '' ?><?= $s['note'] ? '<div class="faint">' . e($s['note']) . '</div>' : '' ?></td>
                    <td class="small"><?= $s['catalog_name'] ? e($s['catalog_name']) : '<span class="badge badge-gray">بدون نگاشت</span>' ?></td>
                    <td class="small ltr"><?= e($s['phone'] ?? '—') ?></td>
                    <td class="small"><?= (int)$s['is_active'] ? '<span class="tg-yes">دریافت شده ✓</span>' : '<span class="tg-no">' . e($s['status'] ?: 'غیرفعال') . '</span>' ?></td>
                    <td class="small nowrap"><?= $s['purchased_at'] ? jdate($s['purchased_at']) : '—' ?></td>
                    <td class="small"><?= $s['source'] === 'manual' ? '<span class="badge badge-info">دستی</span>' : '<span class="badge badge-gray">API</span>' ?></td>
                    <td><?php if ($s['source'] === 'manual' && can('growth.approve')): ?><form method="post" action="<?= url('/admin/growth/user/' . $uid . '/services/' . $s['id'] . '/delete') ?>" data-confirm="این خدمت ثبت دستی حذف شود؟"><?= csrf_field() ?><button class="btn btn-xs btn-ghost" style="color:var(--danger)"><?= icon('trash-2') ?></button></form><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php if (can('growth.approve') && $catalog): ?>
        <form method="post" action="<?= url('/admin/growth/user/' . $uid . '/services') ?>" class="flex flex-wrap mt-2"><?= csrf_field() ?>
            <select name="service_id" style="width:auto"><?php foreach ($catalog as $id => $n): ?><option value="<?= (int)$id ?>"><?= e($n) ?></option><?php endforeach; ?></select>
            <input type="text" name="note" placeholder="توضیح (مثلاً: خرید حضوری)" style="width:220px">
            <button class="btn btn-outline btn-sm"><?= icon('plus') ?> ثبت دستی خدمت</button>
        </form>
        <?php endif; ?>
    </div>
    <div class="card" id="phones">
        <h3><?= icon('smartphone') ?> شماره‌های موبایل مشتری</h3>
        <div class="small muted mb-2">خدمات همه این شماره‌ها از سامانه فروش بررسی و برای این تاجر فعال می‌شود.</div>
        <div class="stack">
            <?php foreach ($phones as $p): ?>
                <div class="list-item"><?= icon($p['primary'] ? 'user' : 'phone') ?><div class="grow"><b class="ltr"><?= e($p['phone']) ?></b><div class="small faint"><?= e($p['label']) ?><?php if ($p['row'] && $p['row']['last_checked_at']): ?> · بررسی: <?= time_ago($p['row']['last_checked_at']) ?> <?= $p['row']['last_status'] === 'ok' ? '(' . fa((int)$p['row']['last_count']) . ' خدمت)' : '<span style="color:var(--danger)">(خطا)</span>' ?><?php endif; ?></div></div>
                    <?php if (!$p['primary'] && (can('growth.run') || can('users.edit'))): ?><form method="post" action="<?= url('/admin/growth/user/' . $uid . '/phones/' . $p['row']['id'] . '/delete') ?>" data-confirm="این شماره و خدمات آن حذف شود؟"><?= csrf_field() ?><button class="btn btn-xs btn-ghost" style="color:var(--danger)"><?= icon('trash-2') ?></button></form><?php endif; ?></div>
            <?php endforeach; ?>
        </div>
        <?php if (can('growth.run') || can('users.edit')): ?>
        <form method="post" action="<?= url('/admin/growth/user/' . $uid . '/phones') ?>" class="mt-2"><?= csrf_field() ?>
            <div class="form-grid"><div class="field"><input type="text" inputmode="tel" class="ltr" name="phone" placeholder="09121234567" required></div><div class="field"><input type="text" name="label" placeholder="برچسب (مثلاً: شماره دوم)"></div></div>
            <?php if ($configured): ?><label class="check small"><input type="checkbox" name="sync" value="1" checked> بلافاصله خدمات را بروزرسانی کن</label><?php endif; ?>
            <button class="btn btn-outline btn-sm"><?= icon('plus') ?> افزودن شماره</button>
        </form>
        <?php endif; ?>
    </div>
</div>

<?php if ($participant && $ev['count']): ?>
<h2 class="mb-2"><?= icon('route') ?> مسیر رشد</h2>
<div class="mb-3"><?php include APP_PATH . '/Views/partials/growth_road.php'; ?></div>
<div class="stack mb-3"><?php $admin = true; foreach ($ev['stages'] as $o): include APP_PATH . '/Views/partials/growth_stage.php'; endforeach; ?></div>
<?php endif; ?>

<div class="card mb-3" id="deals">
    <div class="card-head"><h3><?= icon('handshake') ?> معاملات</h3><span class="small faint"><?= fa($stats['count']) ?> تأییدشده · <?= fa($stats['customers']) ?> مشتری (<?= fa($stats['repeat_customers']) ?> تکرارشونده) · <?= fa($stats['months']) ?> ماه فعال<?= $stats['sum'] ? ' · جمع ' . nf($stats['sum']) . ' ' . e($cur) : '' ?></span></div>
    <?php if (!$deals): ?><div class="faint small">معامله‌ای ثبت نشده است.</div><?php endif; ?>
    <div class="stack">
    <?php foreach ($deals as $d): $sl = $statusLbl[$d['status']] ?? [$d['status'], 'gray']; ?>
        <div class="tg-deal"><div class="top"><div><b><?= e($d['product']) ?></b> <span class="faint">← <?= e($d['customer']) ?></span></div><div class="flex"><span class="badge badge-<?= $sl[1] ?>"><?= $sl[0] ?></span><a class="btn btn-xs btn-outline" href="<?= url('/admin/growth/review/deal/' . $d['id']) ?>"><?= icon('eye') ?> بررسی</a></div></div>
            <div class="kv"><?php if ($d['amount'] !== null): ?><span>مبلغ: <b><?= nf($d['amount']) ?></b> <?= e($cur) ?></span><?php endif; ?><?php if ($d['deal_date']): ?><span>تاریخ: <b><?= jdate($d['deal_date']) ?></b></span><?php endif; ?><?php if ($d['market']): ?><span>بازار: <b><?= e($d['market']) ?></b></span><?php endif; ?><?php if ($d['rv_first']): ?><span class="faint">بررسی: <?= e($d['rv_first'] . ' ' . $d['rv_last']) ?></span><?php endif; ?></div>
            <?php $docs = $dealDocs[(int)$d['id']] ?? []; include APP_PATH . '/Views/partials/growth_docs.php'; ?>
        </div>
    <?php endforeach; ?>
    </div>
</div>

<?php if ($requests): ?>
<div class="card mb-3"><h3><?= icon('file-check') ?> درخواست‌های تأیید مرحله</h3>
    <div class="stack"><?php foreach ($requests as $q): $sl = $statusLbl[$q['status']] ?? [$q['status'], 'gray']; ?>
        <div class="tg-deal"><div class="top"><b>مرحله «<?= e($q['stage_title']) ?>»</b><div class="flex"><span class="badge badge-<?= $sl[1] ?>"><?= $sl[0] ?></span><a class="btn btn-xs btn-outline" href="<?= url('/admin/growth/review/request/' . $q['id']) ?>"><?= icon('eye') ?> بررسی</a></div></div>
            <div class="kv"><span class="faint"><?= jdatetime($q['created_at']) ?></span></div><?php $docs = $reqDocs[(int)$q['id']] ?? []; include APP_PATH . '/Views/partials/growth_docs.php'; ?></div>
    <?php endforeach; ?></div>
</div>
<?php endif; ?>

<div class="card">
    <h3><?= icon('history') ?> تاریخچه</h3>
    <div class="timeline">
        <?php foreach ($history as $h): $st = TraderGrowth::stageByNo((int)$h['to_stage']); ?><div class="tl-item <?= $h['kind'] === 'admin' ? 'warning' : 'success' ?>"><div class="t"><?= $h['kind'] === 'admin' ? 'تعیین توسط ' . e(trim(($h['by_first'] ?? '') . ' ' . ($h['by_last'] ?? ''))) : 'ارتقای خودکار' ?> ← مرحله <?= fa($h['to_stage']) ?><?= $st ? ' «' . e($st['title']) . '»' : '' ?></div><div class="d"><?= jdatetime($h['created_at']) ?> · <?= e($h['note']) ?></div></div><?php endforeach; ?>
        <?php foreach ($legacy as $h): ?><div class="tl-item"><div class="t"><?= e($h['stage_name']) ?> — <?= e($h['group_name']) ?> <span class="badge badge-gray">نظام قبلی</span></div><div class="d"><?= jdatetime($h['created_at']) ?></div></div><?php endforeach; ?>
    </div>
    <?php if (!$history && !$legacy): ?><div class="faint small">سابقه‌ای ثبت نشده است.</div><?php endif; ?>
</div>
