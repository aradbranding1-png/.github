<?php
use App\Services\Credit;
$enough = $balance >= $cost;
$ctIcon = ['video' => 'video', 'audio' => 'music', 'pdf' => 'file-text', 'image' => 'image', 'file' => 'file', 'link' => 'link', 'text' => 'file-text'][$lesson['content_type']] ?? 'file';
?>
<div class="crumbs"><a href="<?= url('/learn') ?>">دوره‌های من</a> / <a href="<?= url('/learn/course/' . $c['id']) ?>"><?= e($c['title']) ?></a></div>
<div class="page-head"><div><h1><?= e($lesson['title']) ?></h1><div class="sub"><?= e($lesson['section_title'] ?? '') ?></div></div></div>

<div class="unlock-card card">
    <div class="uc-icon<?= $enough ? '' : ' is-low' ?>"><?= icon($enough ? 'lock-open' : 'hourglass') ?></div>
    <h2><?= $enough ? 'فعال‌سازی درس' : 'اعتبار زمانی کافی نیست' ?></h2>
    <p class="muted">
        <?php if ($enough): ?>
            مشاهده این درس <b><?= Credit::format($cost) ?></b> از اعتبار زمانی شما را مصرف می‌کند. پس از فعال‌سازی، این درس برای همیشه برای شما باز می‌ماند و مشاهده دوباره آن هزینه‌ای ندارد.
        <?php else: ?>
            برای مشاهده این درس به <b><?= Credit::format($cost) ?></b> اعتبار نیاز دارید، اما موجودی شما <b><?= Credit::format($balance) ?></b> است. لطفاً اعتبار خود را شارژ کنید.
        <?php endif; ?>
    </p>
    <div class="uc-stats">
        <div><span class="l"><?= icon($ctIcon) ?> زمان این درس</span><b><?= Credit::format($cost) ?></b></div>
        <div><span class="l"><?= icon('clock') ?> موجودی شما</span><b class="<?= $enough ? '' : 'text-danger' ?>"><?= Credit::format($balance) ?></b></div>
        <?php if ($enough): ?><div><span class="l"><?= icon('hourglass') ?> موجودی پس از فعال‌سازی</span><b><?= Credit::format($balance - $cost) ?></b></div><?php endif; ?>
    </div>
    <?php if ($enough): ?>
        <form method="post" action="<?= url('/learn/lesson/' . $lesson['id'] . '/unlock') ?>" data-confirm="<?= e(fa($cost)) ?> دقیقه از اعتبار شما کسر شود و درس فعال شود؟"><?= csrf_field() ?>
            <button class="btn btn-grad btn-lg"><?= icon('lock-open') ?> فعال‌سازی و مشاهده درس</button>
        </form>
        <?php if ($courseNeed > $cost && $balance >= $courseNeed): ?>
            <form method="post" action="<?= url('/learn/course/' . $c['id'] . '/unlock') ?>" class="mt-1" data-confirm="<?= e(fa($courseNeed)) ?> دقیقه برای همه درس‌های باقی‌مانده دوره کسر شود؟"><?= csrf_field() ?>
                <button class="btn btn-outline"><?= icon('layers') ?> <span>فعال‌سازی همه درس‌های باقی‌مانده دوره <small class="nowrap">(<?= Credit::format($courseNeed) ?>)</small></span></button>
            </form>
        <?php endif; ?>
    <?php else: ?>
        <div class="alert alert-warning"><?= icon('info') ?><div><?= e(setting('minutes_charge_text')) ?></div></div>
    <?php endif; ?>
    <div class="mt-2"><a class="btn btn-ghost btn-sm" href="<?= url('/learn/course/' . $c['id']) ?>"><?= icon('chevron-right') ?> بازگشت به دوره</a> <a class="btn btn-ghost btn-sm" href="<?= url('/me/credits') ?>"><?= icon('history') ?> تاریخچه اعتبار من</a></div>
</div>
