<?php
use App\Services\TraderGrowth;
$apprShort = ['auto' => 'عبور خودکار', 'expert' => 'عبور + تأیید کارشناس', 'manual' => 'فقط تأیید کارشناس'];
$maxN = max(1, (int)max(array_values($counts) ?: [0]));
$notStarted = max(0, $participants - $started);
?>
<div class="page-head">
    <div><h1>نظام رشد تاجر</h1><div class="sub">نقشه راه رشد تاجران — نمای کلی برای مدیران و مسئولان آموزش</div></div>
    <div class="btn-group">
        <?php if ($isParticipant): ?><a class="btn btn-outline" href="<?= url('/learn/growth') ?>"><?= icon('user') ?> نظام رشد من</a><?php endif; ?>
        <?php if (can('growth.view')): ?><a class="btn btn-outline" href="<?= url('/admin/growth/traders') ?>"><?= icon('trophy') ?> جایگاه تاجران</a>
            <a class="btn btn-primary" href="<?= url('/admin/growth') ?>"><?= icon('settings') ?> مدیریت نظام رشد</a><?php endif; ?>
    </div>
</div>
<?php if ($scoped): ?><div class="alert alert-info mb-3"><?= icon('info') ?><div>آمار این صفحه فقط برای تاجرانِ تحت مسئولیت شما محاسبه شده است.</div></div><?php endif; ?>

<div class="grid g-4 mb-3">
    <div class="card stat tone-primary"><div class="bubble"><?= icon('users') ?></div><div><div class="v"><?= nf($participants) ?></div><div class="l">تاجر در نظام رشد</div></div></div>
    <div class="card stat tone-success"><div class="bubble"><?= icon('mountain') ?></div><div><div class="v"><?= nf($started) ?></div><div class="l">در حال طی مراحل</div></div></div>
    <div class="card stat tone-warning"><div class="bubble"><?= icon('flag') ?></div><div><div class="v"><?= nf($notStarted) ?></div><div class="l">هنوز شروع نکرده</div></div></div>
    <div class="card stat tone-info"><div class="bubble"><?= icon('chart-column') ?></div><div><div class="v"><?= fa(round($avg)) ?>٪</div><div class="l">میانگین پیشرفت مرحله</div></div></div>
</div>

<?php if ($rankCounts): ?>
<div class="card mb-3"><h3 class="mb-2"><?= icon('award') ?> رتبه‌ها</h3>
    <div class="flex flex-wrap" style="gap:.5rem">
    <?php foreach ($rankCounts as $rc): ?>
        <span class="go-rank" style="--rc:<?= e($rc['rank']['color']) ?>"><?= TraderGrowth::stars($rc['rank']) ?> <b><?= e($rc['rank']['title']) ?></b>
            <span class="faint">مرحله <?= fa($rc['rank']['from_stage']) ?><?= (int)$rc['rank']['to_stage'] > (int)$rc['rank']['from_stage'] ? '–' . fa($rc['rank']['to_stage']) : '' ?></span> · <?= fa($rc['n']) ?> نفر</span>
    <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php if (!$stages): ?>
    <div class="card empty"><?= icon('mountain') ?><div>هنوز مرحله‌ای برای نظام رشد تعریف نشده است.</div></div>
<?php else: ?>
<div class="roadmap">
    <div class="rm-item rm-flag">
        <div class="rm-node"><?= icon('flag') ?></div>
        <div class="rm-flag-txt"><b>شروع نظام رشد</b><span><?= fa(count($stages)) ?> مرحله · <?= fa($notStarted) ?> تاجر هنوز شروع نکرده‌اند</span></div>
    </div>
    <?php $season = null; foreach ($stages as $i => $s):
        $list = $items[(int)$s['id']] ?? [];
        $byKind = [];
        foreach ($list as $it) $byKind[$it['kind']][] = $it;
        $n = (int)($counts[$s['no']] ?? 0);
        $link = can('growth.view') ? url('/admin/growth/stages/' . $s['id']) : null; ?>
    <div class="rm-item is-new<?= $i % 2 ? ' rm-alt' : '' ?>" style="--c:<?= e($s['color'] ?: '#4f46e5') ?>">
        <div class="rm-node"><?= icon($s['icon'] ?: 'flag') ?></div>
        <<?= $link ? 'a href="' . $link . '"' : 'div' ?> class="rm-card">
            <div class="rm-top"><span>مرحله <?= fa($s['no']) ?><?= !empty($s['season_title']) ? ' · ' . e($s['season_title']) : '' ?></span><?= TraderGrowth::badge((int)$s['no'], true) ?></div>
            <h3><?= e($s['title']) ?></h3>
            <?php if (trim((string)$s['goal']) !== ''): ?><p class="small muted mb-0"><?= e(str_limit($s['goal'], 150)) ?></p><?php endif; ?>
            <div class="rm-meta">
                <?php if (!empty($byKind['course'])): ?><span class="chip"><?= icon('graduation-cap') ?> <?= fa(count($byKind['course'])) ?> دوره</span><?php endif; ?>
                <?php $ev = count($byKind['event'] ?? []) + count($byKind['event_all'] ?? []) + count($byKind['event_count'] ?? []); if ($ev): ?><span class="chip"><?= icon('calendar') ?> <?= fa($ev) ?> شرط جلسه</span><?php endif; ?>
                <?php if (!empty($byKind['service'])): ?><span class="chip"><?= icon('package') ?> <?= fa(count($byKind['service'])) ?> خدمت</span><?php endif; ?>
                <?php if ((int)$s['min_deals'] > 0): ?><span class="chip"><?= icon('handshake') ?> حداقل <?= fa($s['min_deals']) ?> معامله</span><?php endif; ?>
                <span class="chip"><?= icon('target') ?> عبور <?= fa((int)$s['pass_percent']) ?>٪ · <?= e($apprShort[$s['approval']] ?? $s['approval']) ?></span>
            </div>
            <?php if (!empty($byKind['course'])): ?>
            <div class="go-courses small">
                <?php foreach (array_slice($byKind['course'], 0, 4) as $it): ?><div><?= icon('book-open') ?> <?= e($courses[(int)$it['ref_id']] ?? 'دوره حذف‌شده') ?></div><?php endforeach; ?>
                <?php if (count($byKind['course']) > 4): ?><div class="faint">و <?= fa(count($byKind['course']) - 4) ?> دوره دیگر</div><?php endif; ?>
            </div>
            <?php endif; ?>
            <div class="rm-prog"><span class="small"><?= icon('users') ?> <b><?= fa($n) ?></b> تاجر در این مرحله</span><div class="grow"><?= progress_bar($n / $maxN * 100) ?></div></div>
            <?php if ($link): ?><span class="rm-cta">جزئیات و تنظیم مرحله <?= icon('chevron-left') ?></span><?php endif; ?>
        </<?= $link ? 'a' : 'div' ?>>
    </div>
    <?php endforeach; ?>
    <div class="rm-item rm-flag rm-finish">
        <div class="rm-node"><?= icon('trophy') ?></div>
        <div class="rm-flag-txt"><b>قله نظام رشد</b><span>تاجری که همه مراحل را بگذراند</span></div>
    </div>
</div>
<?php endif; ?>
