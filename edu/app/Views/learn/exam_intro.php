<?php $left = $left ?? null; ?>
<div class="crumbs"><a href="<?= url('/learn/exams') ?>">آزمون‌های من</a><?php if ($x['course_id']): ?> / <a href="<?= url('/learn/course/' . $x['course_id']) ?>"><?= e($x['course_title']) ?></a><?php endif; ?></div>
<div class="grid g-main">
    <div class="card">
        <h1><?= e($x['title']) ?></h1>
        <?php if ($x['description']): ?><p class="muted"><?= nl2br(e($x['description'])) ?></p><?php endif; ?>
        <div class="grid g-4 mt-2">
            <div class="card stat tone-purple"><div class="bubble"><?= icon('circle-help') ?></div><div><div class="v"><?= fa($qcount) ?></div><div class="l">سؤال</div></div></div>
            <div class="card stat tone-info"><div class="bubble"><?= icon('clock') ?></div><div><div class="v"><?= $x['time_limit_minutes'] ? fa($x['time_limit_minutes']) : '∞' ?></div><div class="l">دقیقه</div></div></div>
            <div class="card stat tone-success"><div class="bubble"><?= icon('target') ?></div><div><div class="v"><?= fa((float)$x['pass_score']) ?>٪</div><div class="l">حد قبولی</div></div></div>
            <div class="card stat tone-warning"><div class="bubble"><?= icon('refresh-cw') ?></div><div><div class="v"><?= $left === null ? '∞' : fa($left) ?></div><div class="l"><?= $left === null ? 'دفعات شرکت' : 'فرصت باقی‌مانده امروز' ?></div></div></div>
        </div>
        <?php if ($x['available_from'] || $x['available_until']): ?><p class="small muted mt-2"><?= icon('calendar') ?> بازه برگزاری: <?= $x['available_from'] ? jdatetime($x['available_from']) : 'از هم‌اکنون' ?> تا <?= $x['available_until'] ? jdatetime($x['available_until']) : 'نامحدود' ?></p><?php endif; ?>
        <div class="alert alert-info mt-2"><?= icon('info') ?><div>با شروع آزمون، زمان‌سنج فعال می‌شود و در پایان زمان، پاسخ‌ها به صورت خودکار ارسال می‌شوند. از بستن صفحه در حین آزمون خودداری کنید.</div></div>
        <?php if ($passed): ?>
            <div class="alert alert-success"><?= icon('circle-check') ?> شما در این آزمون قبول شده‌اید.</div>
        <?php elseif (!$open && !$credit['ok']): ?>
            <div class="alert alert-danger"><?= icon('lock') ?><div>
                <b>اعتبار این دوره آموزشی را ندارید.</b>
                <?php if ($credit['balance'] >= $credit['need']): ?>
                    برای شرکت در آزمون ابتدا <?= $credit['lesson'] ? 'درس «' . e($credit['lesson']['title']) . '»' : 'درس‌های این دوره' ?> را با اعتبار خود فعال کنید (<?= App\Services\Credit::format($credit['need']) ?>).
                <?php else: ?>
                    برای دریافت اعتبار با کارشناسان آراد برندینگ تماس بگیرید.<div class="small mt-1">اعتبار لازم: <?= App\Services\Credit::format($credit['need']) ?> · موجودی شما: <?= App\Services\Credit::format($credit['balance']) ?></div>
                <?php endif; ?>
            </div></div>
            <div class="flex flex-wrap">
                <?php if ($credit['balance'] >= $credit['need']): ?><a class="btn btn-primary" href="<?= url($credit['lesson'] ? '/learn/lesson/' . $credit['lesson']['id'] : '/learn/course/' . $credit['course_id']) ?>"><?= icon('lock-open') ?> فعال‌سازی درس</a><?php endif; ?>
                <a class="btn btn-outline" href="<?= url('/me/credits') ?>"><?= icon('zap') ?> اعتبارهای من</a>
            </div>
        <?php elseif ($open): ?>
            <a class="btn btn-grad btn-lg" href="<?= url('/learn/attempt/' . $open['id']) ?>"><?= icon('play') ?> ادامه آزمون در حال انجام</a>
        <?php elseif ($left === 0): ?>
            <div class="alert alert-warning"><?= icon('clock') ?><div>امروز <?= fa((int)$x['max_attempts']) ?> بار در این آزمون شرکت کرده‌اید که سقف مجاز روزانه است. از فردا (بعد از ساعت ۰۰:۰۰) دوباره می‌توانید شرکت کنید.</div></div>
        <?php elseif (!$window): ?>
            <div class="alert alert-warning"><?= icon('clock') ?> آزمون در حال حاضر در بازه برگزاری نیست.</div>
        <?php else: ?>
            <form method="post" action="<?= url('/learn/exam/' . $x['id'] . '/start') ?>" data-confirm="آزمون شروع شود؟"><?= csrf_field() ?><button class="btn btn-grad btn-lg"><?= icon('play') ?> شروع آزمون</button></form>
        <?php endif; ?>
    </div>
    <div class="card"><h3><?= icon('history') ?> نتایج قبلی</h3>
        <?php if (!$attempts): ?><div class="faint small">هنوز در این آزمون شرکت نکرده‌اید.</div><?php endif; ?>
        <?php foreach ($attempts as $a): ?>
            <a class="list-item" href="<?= url('/learn/attempt/' . $a['id'] . '/result') ?>" style="color:var(--text)">
                <?= progress_ring((float)($a['percent'] ?? 0), 46, (int)$a['passed'] ? 'success' : 'danger') ?>
                <div class="grow"><b>دفعه <?= fa($a['attempt_no']) ?></b><div class="small faint"><?= jdatetime($a['submitted_at']) ?></div></div>
                <?= $a['status'] === 'pending_review' ? status_badge('pending_review') : ((int)$a['passed'] ? status_badge('passed') : status_badge('failed')) ?>
            </a>
        <?php endforeach; ?>
    </div>
</div>
