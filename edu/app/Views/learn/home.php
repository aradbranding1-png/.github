<?php
$hour = (int)date('G');
$greet = $hour < 12 ? 'صبح بخیر' : ($hour < 17 ? 'روز بخیر' : 'عصر بخیر');
$first = $continue[0] ?? null;
?>
<div class="grid g-main mb-3">
    <div class="card hero">
        <div class="flex between flex-wrap gap-2">
            <div class="grow">
                <div class="muted"><?= e($greet) ?>،</div>
                <h1 style="font-size:1.8rem"><?= user_name_html($me) ?></h1>
                <p class="muted mb-2">
                    <?php if ($stats['mandatory_open']): ?>شما <b style="color:#fff"><?= fa($stats['mandatory_open']) ?></b> آموزش اجباری در پیش دارید.<?php else: ?>همه آموزش‌های اجباری شما تکمیل شده است. عالی!<?php endif; ?>
                    <?php if ($growth && $growth['cur']): ?> مرحله رشد فعلی: <b style="color:#fff"><?= fa($growth['current']) ?>. <?= e($growth['cur']['stage']['title']) ?></b><?php endif; ?>
                </p>
                <div class="flex flex-wrap">
                    <?php if ($first): ?>
                        <a class="btn btn-white" href="<?= url($first['last_lesson_id'] ? '/learn/lesson/' . $first['last_lesson_id'] : '/learn/course/' . $first['course_id']) ?>"><?= icon('circle-play') ?> ادامه یادگیری: <?= e(str_limit($first['title'], 32)) ?></a>
                    <?php else: ?>
                        <a class="btn btn-white" href="<?= url('/learn/catalog') ?>"><?= icon('layout-grid') ?> مشاهده دوره‌ها</a>
                    <?php endif; ?>
                    <?php if (can('dashboard.view')): ?><a class="btn btn-light" href="<?= url('/admin') ?>"><?= icon('layout-dashboard') ?> داشبورد مدیریتی</a><?php endif; ?>
                </div>
            </div>
            <div class="text-center">
                <?= progress_ring((float)$overall, 128, 'white') ?>
                <div class="small muted mt-1">پیشرفت کلی آموزش</div>
            </div>
        </div>
    </div>
    <div class="card">
        <div class="card-head"><h3><?= icon('activity') ?> فعالیت ۱۴ روز اخیر</h3><a class="small" href="<?= url('/me/report') ?>">گزارش کامل</a></div>
        <div class="chart" data-chart='<?= e(json_encode($chart, JSON_UNESCAPED_UNICODE)) ?>' data-height="170" style="min-height:170px"></div>
    </div>
</div>

<?php if (!empty($library['courses'])): ?>
<a class="card lib-strip mb-3" href="<?= url('/learn/catalog') ?>">
    <span class="lib-title"><?= icon('library') ?> کتابخانه آموزشی آراد</span>
    <span class="lib-items">
        <span class="lib-i"><b><?= fa((int)$library['courses']) ?></b><small>دوره</small></span>
        <span class="lib-i"><b><?= fa((int)$library['lessons']) ?></b><small>درس</small></span>
        <?php $lm = (int)$library['minutes']; ?>
        <span class="lib-i" title="<?= e(App\Services\Credit::format($lm)) ?>"><b class="ltr-num"><?= $lm >= 60 ? fa(intdiv($lm, 60) . ':' . str_pad((string)($lm % 60), 2, '0', STR_PAD_LEFT)) : fa($lm) ?></b><small><?= $lm >= 60 ? 'ساعت آموزش' : 'دقیقه آموزش' ?></small></span>
    </span>
    <span class="lib-go">مشاهده کاتالوگ <?= icon('chevron-left') ?></span>
</a>
<?php endif; ?>
<div class="grid g-5 mb-3">
    <div class="card stat tone-danger"><div class="bubble"><?= icon('flag') ?></div><div><div class="v"><?= fa($stats['mandatory_open']) ?><small class="faint small"> / <?= fa($stats['mandatory']) ?></small></div><div class="l">آموزش اجباری باقی‌مانده</div></div></div>
    <div class="card stat tone-primary"><div class="bubble"><?= icon('circle-play') ?></div><div><div class="v"><?= fa($stats['in_progress']) ?></div><div class="l">در حال انجام</div></div></div>
    <div class="card stat tone-success"><div class="bubble"><?= icon('circle-check') ?></div><div><div class="v"><?= fa($stats['completed']) ?></div><div class="l">تکمیل‌شده</div></div></div>
    <div class="card stat tone-purple"><div class="bubble"><?= icon('clipboard-check') ?></div><div><div class="v"><?= fa($exams['pending']) ?></div><div class="l">آزمون در انتظار<?php if ($exams['avg'] !== null): ?> · میانگین <?= fa(round((float)$exams['avg'])) ?><?php endif; ?></div></div></div>
    <div class="card stat tone-warning"><div class="bubble"><?= icon('notebook-pen') ?></div><div><div class="v"><?= fa($exercises['revise'] + $exercises['review']) ?></div><div class="l">تمرین (<?= fa($exercises['review']) ?> در بررسی، <?= fa($exercises['revise']) ?> نیازمند اصلاح)</div></div></div>
</div>

<div class="grid g-main">
    <div class="stack">
        <div class="card">
            <div class="card-head"><h3><?= icon('circle-play') ?> ادامه یادگیری</h3><a class="small" href="<?= url('/learn') ?>">همه دوره‌های من</a></div>
            <?php if (!$continue): ?>
                <div class="empty"><?= icon('graduation-cap') ?><h3>هنوز دوره‌ای شروع نکرده‌اید</h3><p>از کاتالوگ دوره‌ها، آموزش مناسب خود را انتخاب کنید.</p><a class="btn btn-primary" href="<?= url('/learn/catalog') ?>">کاتالوگ دوره‌ها</a></div>
            <?php else: ?>
                <div class="grid g-2"><?php foreach ($continue as $c) include APP_PATH . '/Views/partials/course_card.php'; ?></div>
            <?php endif; ?>
        </div>

        <?php if ($pathEn): ?>
        <div class="card">
            <div class="card-head"><h3><?= icon('route') ?> مسیر آموزشی: <?= e($pathEn['title']) ?></h3><a class="btn btn-sm btn-outline" href="<?= url('/learn/path/' . $pathEn['path_id']) ?>">نقشه کامل مسیر</a></div>
            <div class="path-strip">
                <?php foreach ($pathSteps as $i => $st):
                    $cls = ($i + 1) < (int)$pathEn['current_step'] || $pathEn['status'] === 'completed' ? 'done' : (($i + 1) === (int)$pathEn['current_step'] ? 'current' : ''); ?>
                    <?php if ($i): ?><div class="ps-bar<?= $cls === 'done' || $cls === 'current' ? ' done' : '' ?>"></div><?php endif; ?>
                    <div class="ps-node <?= $cls ?>"><div class="ps-dot"><?= $cls === 'done' ? icon('check') : fa($i + 1) ?></div><span><?= e(str_limit($st['title'] ?: $st['course_title'], 22)) ?></span></div>
                <?php endforeach; ?>
            </div>
            <div class="flex mt-1"><div class="grow"><?= progress_bar((float)$pathEn['progress_pct']) ?></div><b class="small"><?= fa((int)$pathEn['progress_pct']) ?>٪</b></div>
        </div>
        <?php endif; ?>

        <?php if ($recommend): ?>
        <div class="card">
            <div class="card-head"><h3><?= icon('sparkles') ?> پیشنهاد آموزشی برای شما</h3><a class="small" href="<?= url('/learn/catalog') ?>">کاتالوگ</a></div>
            <div class="grid g-3"><?php foreach ($recommend as $c) include APP_PATH . '/Views/partials/course_card.php'; ?></div>
        </div>
        <?php endif; ?>
    </div>

    <div class="stack">
    <?php if (!empty($sessions)): ?>
        <div class="card">
            <div class="card-head"><h3><?= icon('video') ?> جلسات آنلاین پیش رو</h3><a class="small" href="<?= url('/me/services') ?>">همه</a></div>
            <?php foreach ($sessions as $ev): $et = App\Services\EventService::TYPES[$ev['type']]; $ts = strtotime((string)$ev['starts_at']); ?>
                <a class="up-ev tone-<?= e($et['tone']) ?>" href="<?= url('/learn/event/' . $ev['id']) ?>">
                    <span class="d"><b><?= fa(App\Core\Jalali::format('j', $ts)) ?></b><small><?= App\Core\Jalali::format('F', $ts) ?></small></span>
                    <span class="grow"><b class="small"><?= e($ev['title']) ?></b><br><span class="faint small"><?= e($et['label']) ?> · ساعت <?= fa(date('H:i', $ts)) ?><?= App\Services\EventService::phase($ev) === 'live' ? ' · <span style="color:var(--danger)">در حال برگزاری</span>' : '' ?></span></span>
                    <?= icon('chevron-left', 'faint') ?>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
        <?php if ($growth): $ev = $growth; include APP_PATH . '/Views/partials/growth_mini.php'; endif; ?>

        <div class="card">
            <div class="card-head"><h3><?= icon('flag') ?> آموزش‌های اجباری</h3></div>
            <?php if (!$mandatory): ?><div class="empty" style="padding:1rem"><?= icon('circle-check') ?><div>آموزش اجباری باز ندارید</div></div><?php endif; ?>
            <div class="timeline">
                <?php foreach ($mandatory as $m): $late = $m['due_at'] && $m['due_at'] < now(); ?>
                    <div class="tl-item <?= $late ? 'danger' : ($m['status'] === 'in_progress' ? 'info' : 'warning') ?>">
                        <a class="t" href="<?= url('/learn/course/' . $m['course_id']) ?>"><?= e($m['title']) ?></a>
                        <div class="flex"><div class="grow"><?= progress_bar((float)$m['progress_pct']) ?></div><span class="small"><?= fa((int)$m['progress_pct']) ?>٪</span></div>
                        <div class="d"><?= $m['due_at'] ? ($late ? 'مهلت گذشته: ' : 'مهلت: ') . jdate($m['due_at']) : 'بدون مهلت' ?> · <?= status_badge($m['status']) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="card">
            <div class="card-head"><h3><?= icon('history') ?> فعالیت اخیر</h3></div>
            <?php if (!$recent): ?><div class="faint small">هنوز فعالیتی ثبت نشده است.</div><?php endif; ?>
            <div class="list">
                <?php foreach ($recent as $a):
                    [$ic, $tone, $txt] = match ($a['type']) {
                        'lesson_view' => ['eye', 'info', 'مشاهده درس «' . ($a['lesson_title'] ?? '') . '»'],
                        'lesson_complete' => ['circle-check', 'success', 'تکمیل درس «' . ($a['lesson_title'] ?? '') . '»'],
                        'course_complete' => ['award', 'success', 'تکمیل دوره «' . ($a['course_title'] ?? '') . '»'],
                        'exam_submit' => ['clipboard-check', 'purple', 'شرکت در آزمون «' . ($a['exam_title'] ?? '') . '»'],
                        'exercise_submit' => ['notebook-pen', 'warning', 'ارسال تمرین'],
                        default => ['activity', 'gray', 'فعالیت'],
                    }; ?>
                    <div class="list-item"><span class="ico tone-<?= $tone ?>" style="background:var(--<?= $tone === 'gray' ? 'gray' : $tone ?>-soft)"><?= icon($ic) ?></span><div class="grow small"><?= e($txt) ?><div class="faint"><?= time_ago($a['created_at']) ?></div></div></div>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="card stat tone-success"><div class="bubble"><?= icon('award') ?></div><div><div class="v"><?= fa($certs) ?></div><div class="l">گواهی دریافت‌شده · <a href="<?= url('/learn/certificates') ?>">مشاهده</a></div></div></div>
    </div>
</div>
