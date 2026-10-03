<?php
use App\Services\TraderGrowth;
/** @var array $ev */
$me = $ev['user'];
$trackNames = array_column($tracks, 'name', 'id');
$c = $ev['cur'];
$cur = currency_label();
$canTrack = $tracks && (setting('growth_track_self', '1') === '1' || empty($me['tg_track_id']));
$statusLbl = ['pending' => ['در انتظار بررسی', 'warning'], 'approved' => ['تأیید شده', 'success'], 'rejected' => ['نیاز به اصلاح', 'danger']];
?>
<?php if (!$ev['participant'] || !$ev['count']): ?>
    <div class="page-head"><div><h1>نظام رشد تاجر</h1></div></div>
    <div class="card empty"><?= icon('mountain') ?><h3>نظام رشد تاجر برای حساب شما فعال نیست</h3><div class="muted">این بخش برای تاجران فعال است. اگر فکر می‌کنید باید فعال باشد با پشتیبانی تماس بگیرید.</div></div>
    <?php if ($legacy): ?><div class="card mt-3"><h3><?= icon('history') ?> سوابق نظام رشد قبلی</h3><div class="timeline"><?php foreach ($legacy as $h): ?><div class="tl-item success"><div class="t"><?= e($h['stage_name']) ?> — <?= e($h['group_name']) ?></div><div class="d"><?= jdatetime($h['created_at']) ?></div></div><?php endforeach; ?></div></div><?php endif; ?>
    <?php return; ?>
<?php endif; ?>

<?php if (!empty($staff)): ?><div class="flex mb-2" style="justify-content:flex-end"><a class="btn btn-sm btn-outline" href="<?= url('/learn/growth', ['view' => 'overview']) ?>"><?= icon('mountain') ?> نمای کلی نظام رشد (مدیران)</a></div><?php endif; ?>
<div class="card hero tg-hero mb-3">
    <div class="flex between flex-wrap gap-2">
        <div class="grow">
            <div class="flex gap-2 flex-wrap" style="align-items:center">
                <?= avatar_html($me, 'lg') ?>
                <div>
                    <div class="muted small">نظام رشد تاجر</div>
                    <h1 class="mb-0" style="font-size:1.6rem"><?= e(full_name($me)) ?></h1>
                </div>
                <?= TraderGrowth::badge($ev['current'], true, 'xl') ?>
            </div>
            <div class="tg-meta">
                <span class="chip"><?= icon('flag') ?> مرحله <?= fa($ev['current']) ?> از <?= fa($ev['count']) ?>: <b><?= e($c['stage']['title'] ?? '') ?></b></span>
                <?php if (!empty($c['stage']['season_title'])): ?><span class="chip"><?= icon('layers') ?> <?= e($c['stage']['season_title']) ?></span><?php endif; ?>

            </div>
            <?php if ($tracks): ?>
            <div class="tg-track">
                <span class="tg-track-l"><?= icon('route') ?> مسیر تجاری شما<?= empty($me['tg_track_id']) ? ' را انتخاب کنید' : '' ?>:</span>
                <?php if ($canTrack): ?>
                    <form method="post" action="<?= url('/learn/growth/track') ?>" class="tg-track-opts"><?= csrf_field() ?>
                        <?php foreach ($tracks as $t): $on = (int)$t['id'] === (int)($me['tg_track_id'] ?? 0); ?>
                            <button name="track_id" value="<?= (int)$t['id'] ?>" class="<?= $on ? 'on' : '' ?>"<?= $on ? ' disabled' : '' ?>><?= $on ? icon('check') . ' ' : '' ?><?= e($t['name']) ?></button>
                        <?php endforeach; ?>
                    </form>
                <?php else: ?>
                    <span class="tg-track-opts"><button class="on" disabled><?= icon('check') ?> <?= e($trackNames[(int)$me['tg_track_id']] ?? '') ?></button></span>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <div class="tg-overall">
                <div class="flex between small"><span>پیشرفت کل مسیر رشد</span><b><?= fa(round($ev['overall'])) ?>٪</b></div>
                <?= progress_bar($ev['overall']) ?>
            </div>
        </div>
        <div class="text-center">
            <?= progress_ring($ev['all_done'] ? 100 : (float)($c['shown'] ?? 0), 124, 'white') ?>
            <div class="small muted mt-1"><?= $ev['all_done'] ? 'همه مراحل کامل شد' : 'پیشرفت مرحله فعلی' ?></div>
        </div>
    </div>
</div>

<div class="grid g-main mb-3">
    <div class="card">
        <div class="card-head"><h3><?= icon('list-checks') ?> <?= $ev['all_done'] ? 'وضعیت' : 'برای رسیدن به مرحله بعد' ?></h3></div>
        <?php include APP_PATH . '/Views/partials/growth_remaining.php'; ?>
    </div>
    <div class="card" id="services">
        <h3><?= icon('package') ?> خدمات دریافت‌شده</h3>
        <?php $got = 0; $all = 0; foreach ($ev['stages'] as $o) { $all += count($o['svc']['rows']); $got += count(array_filter($o['svc']['rows'], fn($r) => $r['done'])); } ?>
        <div class="flex between"><span class="muted small">خدمات مرتبط با مسیر رشد</span><b><?= fa($got) ?> از <?= fa($all) ?></b></div>
        <?= progress_bar($all ? $got * 100 / $all : 0, 'warning') ?>
        <div class="small faint mt-2"><?= icon('clock') ?> آخرین بروزرسانی از سامانه فروش: <?= $ev['services_at'] ? jdatetime($ev['services_at']) . ' (' . time_ago($ev['services_at']) . ')' : 'هنوز انجام نشده' ?></div>
        <div class="small faint"><?= icon('smartphone') ?> خدمات <?= fa($phones) ?> شماره موبایل ثبت‌شده برای شما بررسی می‌شود.</div>
        <?php if ($canSync): ?><form method="post" action="<?= url('/learn/growth/sync') ?>" class="mt-2"><?= csrf_field() ?><button class="btn btn-sm btn-outline"><?= icon('refresh-cw') ?> بروزرسانی خدمات من</button></form><?php endif; ?>
    </div>
</div>

<h2 class="mb-2"><?= icon('route') ?> مسیر ۱۲ مرحله‌ای رشد</h2>
<div class="mb-3"><?php include APP_PATH . '/Views/partials/growth_road.php'; ?></div>

<div class="stack mb-3">
    <?php $admin = false; $uid = (int)$me['id']; foreach ($ev['stages'] as $o): if ($o['state'] === 'locked' && $o['no'] > $ev['current'] + 1) continue; include APP_PATH . '/Views/partials/growth_stage.php'; endforeach; ?>
    <?php $later = array_filter($ev['stages'], fn($o) => $o['state'] === 'locked' && $o['no'] > $ev['current'] + 1); if ($later): ?>
        <details class="card"><summary class="flex between" style="cursor:pointer"><b><?= icon('lock') ?> مراحل بعدی (<?= fa(count($later)) ?> مرحله)</b><span class="small faint">نمایش</span></summary>
            <div class="stack mt-2"><?php foreach ($later as $o): include APP_PATH . '/Views/partials/growth_stage.php'; endforeach; ?></div>
        </details>
    <?php endif; ?>
</div>

<?php if ($dealsOpen): ?>
<div class="card mb-3" id="deals">
    <div class="card-head"><h3><?= icon('handshake') ?> معاملات من</h3><span class="small faint"><?= fa($ev['deals']['approved']) ?> تأییدشده · <?= fa($ev['deals']['pending']) ?> در انتظار</span></div>
    <div class="stack mb-3">
        <?php foreach ($deals as $d): $sl = $statusLbl[$d['status']] ?? [$d['status'], 'gray']; $editable = in_array($d['status'], ['pending', 'rejected'], true); ?>
            <div class="tg-deal" id="deal-<?= (int)$d['id'] ?>">
                <div class="top"><div><b><?= e($d['product']) ?></b> <span class="faint">← <?= e($d['customer']) ?></span></div><span class="badge badge-<?= $sl[1] ?>"><?= $sl[0] ?></span></div>
                <div class="kv"><?php if ($d['amount'] !== null): ?><span>مبلغ: <b><?= nf($d['amount']) ?></b> <?= e($cur) ?></span><?php endif; ?><?php if ($d['deal_date']): ?><span>تاریخ: <b><?= jdate($d['deal_date']) ?></b></span><?php endif; ?><?php if ($d['market']): ?><span>بازار/مقصد: <b><?= e($d['market']) ?></b></span><?php endif; ?><span class="faint">ثبت: <?= jdate($d['created_at']) ?></span></div>
                <?php if ($d['description']): ?><div class="small muted mb-1"><?= nl2br(e($d['description'])) ?></div><?php endif; ?>
                <?php $docs = $dealDocs[(int)$d['id']] ?? []; include APP_PATH . '/Views/partials/growth_docs.php'; ?>
                <?php if ($d['status'] === 'rejected' && $d['review_note']): ?><div class="alert alert-danger mt-2 mb-0"><?= icon('message-square') ?><div><b>نظر کارشناس:</b> <?= e($d['review_note']) ?></div></div><?php elseif ($d['review_note']): ?><div class="small mt-1"><?= icon('message-square') ?> <?= e($d['review_note']) ?></div><?php endif; ?>
                <?php if ($editable): ?>
                    <details class="mt-2"><summary class="small" style="cursor:pointer;color:var(--primary)"><?= icon('pencil') ?> ویرایش / افزودن مدرک<?= $d['status'] === 'rejected' ? ' و ارسال دوباره' : '' ?></summary>
                        <form class="mt-2" method="post" enctype="multipart/form-data" action="<?= url('/learn/growth/deals/' . $d['id']) ?>"><?= csrf_field() ?>
                            <?php $dv = $d; include APP_PATH . '/Views/learn/_deal_fields.php'; ?>
                            <div class="flex between flex-wrap gap-2"><button class="btn btn-primary btn-sm"><?= icon('save') ?> ذخیره<?= $d['status'] === 'rejected' ? ' و ارسال دوباره' : '' ?></button></div>
                        </form>
                        <form method="post" action="<?= url('/learn/growth/deals/' . $d['id'] . '/delete') ?>" data-confirm="این معامله حذف شود؟" class="mt-1"><?= csrf_field() ?><button class="btn btn-xs btn-ghost" style="color:var(--danger)"><?= icon('trash-2') ?> حذف معامله</button></form>
                    </details>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        <?php if (!$deals): ?><div class="empty" style="padding:1rem"><?= icon('handshake') ?><div>هنوز معامله‌ای ثبت نکرده‌اید. اولین معامله واقعی خود را با مدارک ثبت کنید.</div></div><?php endif; ?>
    </div>
    <details class="card" style="background:var(--surface-2)"<?= !$deals ? ' open' : '' ?>>
        <summary style="cursor:pointer"><b><?= icon('plus') ?> ثبت معامله جدید</b></summary>
        <form class="mt-2" method="post" enctype="multipart/form-data" action="<?= url('/learn/growth/deals') ?>"><?= csrf_field() ?>
            <?php $dv = null; include APP_PATH . '/Views/learn/_deal_fields.php'; ?>
            <button class="btn btn-grad"><?= icon('send') ?> ثبت معامله برای بررسی</button>
        </form>
    </details>
</div>
<?php endif; ?>

<?php if ($requests): ?>
<div class="card mb-3">
    <h3><?= icon('file-check') ?> درخواست‌های تأیید مرحله</h3>
    <div class="stack">
        <?php foreach ($requests as $q): $sl = $statusLbl[$q['status']] ?? [$q['status'], 'gray']; ?>
            <div class="tg-deal"><div class="top"><b>مرحله «<?= e($q['stage_title']) ?>»</b><span class="badge badge-<?= $sl[1] ?>"><?= $sl[0] ?></span></div>
                <div class="kv"><span class="faint">ارسال: <?= jdatetime($q['created_at']) ?></span><?php if ($q['reviewed_at']): ?><span class="faint">بررسی: <?= jdatetime($q['reviewed_at']) ?></span><?php endif; ?></div>
                <?php if ($q['note']): ?><div class="small muted mb-1"><?= nl2br(e($q['note'])) ?></div><?php endif; ?>
                <?php $docs = $reqDocs[(int)$q['id']] ?? []; include APP_PATH . '/Views/partials/growth_docs.php'; ?>
                <?php if ($q['review_note']): ?><div class="small mt-1"><?= icon('message-square') ?> <b>کارشناس:</b> <?= e($q['review_note']) ?></div><?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php if ($history || $legacy): ?>
<div class="card">
    <h3><?= icon('history') ?> تاریخچه رشد</h3>
    <div class="timeline">
        <?php foreach ($history as $h): $st = TraderGrowth::stageByNo((int)$h['to_stage']); ?><div class="tl-item success"><div class="t">مرحله <?= fa($h['to_stage']) ?><?= $st ? ' «' . e($st['title']) . '»' : '' ?> <?= TraderGrowth::badge((int)$h['to_stage']) ?></div><div class="d"><?= jdatetime($h['created_at']) ?> · <?= e($h['note']) ?></div></div><?php endforeach; ?>
        <?php foreach ($legacy as $h): ?><div class="tl-item"><div class="t"><?= e($h['stage_name']) ?> — <?= e($h['group_name']) ?> <span class="badge badge-gray">نظام قبلی</span></div><div class="d"><?= jdatetime($h['created_at']) ?> · <?= e($h['note']) ?></div></div><?php endforeach; ?>
    </div>
</div>
<?php endif; ?>
