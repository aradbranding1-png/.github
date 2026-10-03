<?php
use App\Services\Credit;
$banner = image_url($e['banner_file_id'] ?: $e['image_file_id'], 960);
$ts = $e['starts_at'] ? strtotime($e['starts_at']) : null;
$have = $e['type'] === 'webinar' ? $bal['webinar'] : ($e['type'] === 'workshop' ? $bal['workshop'] : 0);
$enough = $have >= $cost;
?>
<div class="crumbs"><a href="<?= url('/learn/events/' . $e['type']) ?>"><?= e($t['plural']) ?></a></div>
<div class="ev-detail">
    <div class="ev-poster tone-<?= e($t['tone']) ?>">
        <?php if ($banner): ?><img src="<?= e($banner) ?>" alt="<?= e($e['title']) ?>"><?php else: ?><span class="ev-ph"><?= icon($t['icon']) ?></span><?php endif; ?>
    </div>
    <div class="stack">
        <div class="card">
            <span class="ev-kind"><?= icon($t['icon']) ?> <?= e($t['label']) ?></span>
            <h1 class="mt-1"><?= e($e['title']) ?></h1>
            <?php if ($e['summary']): ?><p class="muted"><?= e($e['summary']) ?></p><?php endif; ?>
            <div class="ev-facts">
                <div><span><?= icon('calendar') ?> تاریخ</span><b><?= $ts ? jdate($e['starts_at'], 'l j F Y') : '—' ?></b></div>
                <div><span><?= icon('clock') ?> ساعت شروع</span><b><?= $ts ? fa(date('H:i', $ts)) : '—' ?></b></div>
                <div><span><?= icon('hourglass') ?> مدت</span><b><?= Credit::format((int)$e['duration_minutes']) ?></b></div>
                <div><span><?= icon('zap') ?> اعتبار لازم</span><b><?= $e['type'] === 'meeting' ? 'اشتراک میتینگ' : ($cost ? Credit::amount($e['type'], $cost) : 'رایگان') ?></b></div>
                <?php if ($e['host_name']): ?><div><span><?= icon('user') ?> ارائه‌دهنده</span><b><?= e($e['host_name']) ?></b></div><?php endif; ?>
            </div>
            <?php if ($phase === 'live'): ?><div class="alert alert-danger mt-2"><span class="live-dot"></span><div>این جلسه هم‌اکنون در حال برگزاری است.</div></div><?php endif; ?>
        </div>

        <div class="card ev-action">
            <?php if ($mayJoin && ($reg || $e['type'] === 'meeting' || $staff)): ?>
                <div class="ev-ok"><?= icon('circle-check') ?> <?= $e['type'] === 'meeting' ? 'اشتراک میتینگ شما فعال است.' : ($reg ? 'شما در این ' . e($t['label']) . ' ثبت‌نام کرده‌اید.' : 'دسترسی مدیریتی') ?></div>
                <?php if ($e['join_url']): ?>
                    <a class="btn btn-grad btn-lg w-100" href="<?= url('/learn/event/' . $e['id'] . '/join') ?>" target="_blank" rel="noopener"><?= icon('log-in') ?> ورود به <?= e($t['label']) ?></a>
                    <div class="ev-link ltr"><?= icon('link') ?> <?= e($e['join_url']) ?></div>
                    <div class="hint text-center">این لینک در «خدمات و جلسات من» هم همیشه در دسترس شماست.</div>
                <?php else: ?>
                    <div class="alert alert-info"><?= icon('info') ?><div>لینک ورود هنوز ثبت نشده است؛ نزدیک زمان شروع همین‌جا نمایش داده می‌شود.</div></div>
                <?php endif; ?>
            <?php elseif ($e['type'] === 'meeting'): ?>
                <div class="ev-need"><?= icon('lock') ?> برای ورود به این میتینگ اشتراک فعال میتینگ آنلاین لازم است.</div>
                <div class="alert alert-warning"><?= icon('info') ?><div><?= e(setting('minutes_charge_text')) ?></div></div>
            <?php elseif ($phase === 'ended'): ?>
                <div class="ev-need"><?= icon('history') ?> این <?= e($t['label']) ?> برگزار شده و ثبت‌نام آن بسته است.</div>
            <?php else: ?>
                <div class="ev-bal"><span>موجودی شما</span><b class="<?= $enough ? '' : 'text-danger' ?>"><?= Credit::amount($e['type'], $have) ?></b></div>
                <?php if ($enough): ?>
                    <form method="post" action="<?= url('/learn/event/' . $e['id'] . '/register') ?>" data-confirm="<?= $cost ? e(Credit::amount($e['type'], $cost)) . ' از اعتبار شما کسر و ثبت‌نام انجام شود؟' : 'ثبت‌نام انجام شود؟' ?>"><?= csrf_field() ?>
                        <button class="btn btn-grad btn-lg w-100"><?= icon('check') ?> ثبت‌نام در <?= e($t['label']) ?></button>
                    </form>
                    <?php if ($cost): ?><div class="hint text-center">پس از ثبت‌نام <?= e(Credit::amount($e['type'], $have - $cost)) ?> برای شما باقی می‌ماند.</div><?php endif; ?>
                <?php else: ?>
                    <div class="ev-need"><?= icon('hourglass') ?> اعتبار شما برای این <?= e($t['label']) ?> کافی نیست.</div>
                    <div class="alert alert-warning"><?= icon('info') ?><div><?= e(setting('minutes_charge_text')) ?></div></div>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <?php if ($e['description']): ?><div class="card"><h3><?= icon('info') ?> درباره این <?= e($t['label']) ?></h3><div class="prose"><?= clean_html($e['description']) ?></div></div><?php endif; ?>
        <?php if ($staff && can('events.edit')): ?><a class="btn btn-outline" href="<?= url('/admin/event/' . $e['id'] . '/edit') ?>"><?= icon('pencil') ?> ویرایش در پنل مدیریت</a><?php endif; ?>
    </div>
</div>
