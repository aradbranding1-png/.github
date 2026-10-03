<?php
use App\Services\TraderGrowth;
use App\Services\EventService;
$bySeason = [];
foreach ($stages as $s) {
    $k = (int)($s['season_id'] ?? 0);
    $bySeason[$k]['title'] ??= $s['season_title'] ?: 'بدون فصل';
    $bySeason[$k]['color'] ??= $s['season_color'] ?: '#64748b';
    $bySeason[$k]['sort'] ??= (int)($s['season_sort'] ?? 99);
    $bySeason[$k]['stages'][] = $s;
}
uasort($bySeason, fn($a, $b) => $a['sort'] <=> $b['sort']);
$kindShort = ['course' => 'دوره', 'event' => 'جلسه', 'event_all' => 'همه جلسات', 'event_count' => 'حداقل جلسه', 'service' => 'خدمت'];
?>
<div class="page-head">
    <div><h1>نظام رشد تاجر</h1><div class="sub">مسیر ۱۲ مرحله‌ای رشد تاجران در ۴ فصل؛ الزامات هر مرحله (آموزش، جلسات، خدمات، معاملات) از همین‌جا تعریف می‌شود.</div></div>
    <div class="btn-group">
        <?php if (can('growth.create')): ?><a class="btn btn-primary" href="<?= url('/admin/growth/stages/new') ?>"><?= icon('plus') ?> مرحله جدید</a><?php endif; ?>
        <?php if ($legacy): ?><a class="btn btn-ghost" href="<?= url('/admin/growth/legacy') ?>"><?= icon('archive') ?> نظام رشد قبلی</a><?php endif; ?>
    </div>
</div>
<?php include __DIR__ . '/_nav.php'; ?>

<?php if ($dirty): ?><div class="alert alert-info"><?= icon('refresh-cw') ?><div>درصد پیشرفت <?= fa($dirty) ?> کاربر در حال محاسبه دوباره است (به‌خاطر تغییر الزامات). این کار در پس‌زمینه با Cron و هنگام مراجعه کاربران کامل می‌شود.</div></div><?php endif; ?>

<div class="grid g-4 mb-3">
    <div class="card stat tone-primary"><div class="bubble"><?= icon('users') ?></div><div><div class="v"><?= nf($total) ?></div><div class="l">تاجر در نظام رشد</div></div></div>
    <div class="card stat tone-success"><div class="bubble"><?= icon('gauge') ?></div><div><div class="v"><?= fa(round($avg)) ?>٪</div><div class="l">میانگین پیشرفت مرحله فعلی</div></div></div>
    <a class="card stat tone-warning" href="<?= url('/admin/growth/reviews') ?>"><div class="bubble"><?= icon('file-check') ?></div><div><div class="v"><?= fa($pending['deals'] + $pending['requests']) ?></div><div class="l">در انتظار بررسی (<?= fa($pending['deals']) ?> معامله، <?= fa($pending['requests']) ?> درخواست)</div></div></a>
    <a class="card stat tone-info" href="<?= url('/admin/growth/services') ?>"><div class="bubble"><?= icon('package') ?></div><div><div class="v" style="font-size:1.2rem"><?= $configured ? 'متصل' : 'غیرفعال' ?></div><div class="l">سامانه فروش · <?= $lastRun && $lastRun['finished_at'] ? 'آخرین بروزرسانی ' . time_ago($lastRun['finished_at']) : 'بروزرسانی گروهی انجام نشده' ?></div></div></a>
</div>

<div class="grid g-main mb-3">
    <div class="card"><h3><?= icon('chart-column') ?> تعداد تاجران در هر مرحله</h3><div class="chart" data-chart='<?= e(json_encode($chart, JSON_UNESCAPED_UNICODE)) ?>' data-height="240"></div></div>
    <div class="card">
        <h3><?= icon('star') ?> رتبه‌ها</h3>
        <div class="stack">
            <?php foreach ($rankCounts as $rc): $r = $rc['rank']; ?>
                <a class="list-item" href="<?= url('/admin/growth/traders', ['rank' => $r['id']]) ?>" style="color:var(--text)">
                    <span class="grow"><?= TraderGrowth::badge((int)$r['from_stage'], true, 'lg') ?><div class="small faint mt-1">مراحل <?= fa($r['from_stage']) ?> تا <?= fa($r['to_stage']) ?></div></span>
                    <b><?= nf($rc['n']) ?></b> <span class="small faint">نفر</span>
                </a>
            <?php endforeach; ?>
        </div>
        <div class="small faint mt-2">مخاطبان: <?= e(implode('، ', array_map(fn($s) => label('segment', $s), $segs))) ?> <?php if (can('growth.edit')): ?>· <a href="<?= url('/admin/growth/settings') ?>">تغییر</a><?php endif; ?></div>
    </div>
</div>

<h2 class="mb-2"><?= icon('route') ?> مراحل رشد</h2>
<div class="tg-admin-road mb-3">
    <?php $si = 0; foreach ($bySeason as $sea): $si++; ?>
        <div class="col">
            <h4 style="--sc:<?= e($sea['color']) ?>">فصل <?= fa($si) ?>: <?= e($sea['title']) ?></h4>
            <?php foreach ($sea['stages'] as $s): $its = $items[(int)$s['id']] ?? []; $kinds = array_count_values(array_column($its, 'kind')); ?>
                <div class="tg-stage-card" style="--stc:<?= e($s['color']) ?>">
                    <div class="h"><?= icon($s['icon']) ?><b class="grow"><?= fa($s['no']) ?>. <?= e($s['title']) ?></b><?php if (can('growth.edit')): ?>
                        <form method="post" action="<?= url('/admin/growth/stages/' . $s['id'] . '/move') ?>" class="flex" style="gap:0"><?= csrf_field() ?><button class="btn btn-xs btn-ghost" name="dir" value="up" title="انتقال به قبل"><?= icon('chevron-right') ?></button><button class="btn btn-xs btn-ghost" name="dir" value="down" title="انتقال به بعد"><?= icon('chevron-left') ?></button></form><?php endif; ?></div>
                    <div class="tags">
                        <?php foreach ($kinds as $k => $n): ?><span class="badge badge-gray"><?= fa($n) ?> <?= e($kindShort[$k] ?? $k) ?></span><?php endforeach; ?>
                        <?php if ((int)$s['min_deals'] > 0): ?><span class="badge badge-success"><?= fa($s['min_deals']) ?> معامله</span><?php endif; ?>
                        <?php if (!$its && !(int)$s['min_deals'] && $s['approval'] !== 'manual'): ?><span class="badge badge-warning">الزامات تعریف نشده</span><?php endif; ?>
                        <span class="badge badge-<?= $s['approval'] === 'auto' ? 'info' : 'purple' ?>"><?= $s['approval'] === 'auto' ? 'تأیید خودکار' : ($s['approval'] === 'expert' ? '+ تأیید کارشناس' : 'تأیید کارشناس') ?></span>
                    </div>
                    <div class="flex between small"><a href="<?= url('/admin/growth/traders', ['stage' => $s['no']]) ?>"><?= icon('users') ?> <?= nf((int)($counts[$s['no']] ?? 0)) ?> تاجر</a><a href="<?= url('/admin/growth/stages/' . $s['id']) ?>"><?= icon('settings') ?> الزامات و تنظیمات</a></div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>
</div>
<?php if ($inactive): ?><div class="card mb-3"><h3><?= icon('eye-off') ?> مراحل غیرفعال</h3><?php foreach ($inactive as $s): ?><a class="badge badge-gray" href="<?= url('/admin/growth/stages/' . $s['id']) ?>"><?= e($s['title']) ?></a> <?php endforeach; ?></div><?php endif; ?>

<div class="card">
    <h3><?= icon('history') ?> آخرین تغییرات مرحله</h3>
    <?php if (!$recent): ?><div class="faint small">هنوز ارتقایی ثبت نشده است.</div><?php endif; ?>
    <?php foreach ($recent as $h): $st = TraderGrowth::stageByNo((int)$h['to_stage']); ?>
        <div class="list-item"><?= avatar_html(['id' => $h['uid'], 'first_name' => $h['first_name'], 'last_name' => $h['last_name'], 'avatar_path' => $h['avatar_path']], 'sm') ?>
            <div class="grow"><a class="fw-b small" href="<?= url('/admin/growth/user/' . $h['uid']) ?>"><?= person_name($h, 'uid') ?></a><div class="small faint"><?= $h['kind'] === 'admin' ? 'تعیین توسط مدیر' : 'ارتقای خودکار' ?> ← مرحله <?= fa($h['to_stage']) ?><?= $st ? ' «' . e($st['title']) . '»' : '' ?> · <?= time_ago($h['created_at']) ?></div></div>
        </div>
    <?php endforeach; ?>
</div>
