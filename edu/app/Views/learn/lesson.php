<?php
$isDone = in_array((int)$lesson['id'], $done, true);
$needPct = (int)setting('video_complete_percent', 90);
$remote = $remote ?? null;
$isMedia = (in_array($lesson['content_type'], ['video', 'audio'], true) && $media) || ($remote && in_array($remote['mode'], ['video', 'audio'], true));
$resume = (int)($lp['position_sec'] ?? 0);
$pct = (int)($lp['percent'] ?? 0);
$contentDone = $contentDone ?? $isDone;
$tasks = $tasks ?? ['items' => [], 'blocking' => []];
$taskState = [];
foreach ($tasks['items'] as $t) $taskState[$t['type'] . $t['id']] = $t['state'];
$taskGate = $taskGate ?? null;
$tasksLeft = $contentDone && !$isDone && $tasks['blocking'];
$canComplete = !$preview && $en && !$contentDone && (!$isMedia || $pct >= $needPct);
// the same rule the server applies: a completion that would be suspicious is not allowed
$auditBlock = $canComplete ? App\Services\CompletionAudit::precheck($lesson, (int)auth()['id']) : null;
if ($auditBlock) $canComplete = false;
$stateBadge = [
    'done' => ['قبول / تأیید شده', 'success'], 'pending' => ['در انتظار بررسی — می‌توانید ادامه دهید', 'info'],
    'failed' => ['قبول نشدید — دوباره شرکت کنید', 'danger'], 'rejected' => ['رد شد — اصلاح و ارسال دوباره', 'danger'], 'todo' => ['انجام نشده', 'warning'],
];
?>
<?php
$lsIdx = 0; foreach ($items['lessons'] as $ix => $ll) if ((int)$ll['id'] === (int)$lesson['id']) $lsIdx = $ix + 1;
$lsAll = count($items['lessons']);
$ctIcons = ['video' => 'video', 'audio' => 'music', 'pdf' => 'file-text', 'image' => 'image', 'file' => 'file', 'link' => 'link', 'text' => 'file-text'];
$canLessonEdit = can('lessons.edit'); $canExam = can('exams.create'); $canExercise = can('lessons.create'); $canCourse = can('courses.view') || can('lessons.view'); $canReport = can('reports.report') || can('courses.report');
?>
<div class="crumbs mb-1"><a href="<?= url('/learn') ?>">دوره‌های من</a> / <a href="<?= url('/learn/course/' . $c['id']) ?>"><?= e($c['title']) ?></a> / درس <?= fa($lsIdx) ?></div>
<div class="card chero" style="--cc:<?= e($c['category_color'] ?? '' ?: '#6366f1') ?>">
    <div class="chero-top">
        <div class="chero-cover"><?= icon($ctIcons[$lesson['content_type']] ?? 'book-open') ?></div>
        <div class="chero-main">
            <h1><?= e($lesson['title']) ?></h1>
            <div class="chero-tags">
                <?php if ($isDone): ?><span class="badge badge-success"><?= icon('circle-check') ?> تکمیل شده</span><?php elseif ($tasksLeft): ?><span class="badge badge-warning"><?= icon('clipboard-check') ?> آزمون/تمرین مانده</span><?php endif; ?>
                <?php if ($preview): ?><span class="badge badge-info">پیش‌نمایش</span><?php endif; ?>
                <a class="chero-cat" href="<?= url('/learn/course/' . $c['id']) ?>"><?= e($c['title']) ?></a>
                <?php if (!empty($lesson['section_title'])): ?><span class="chero-meta"><?= icon('layers') ?> <?= e($lesson['section_title']) ?></span><?php endif; ?>
            </div>
        </div>
        <?php if ($canLessonEdit || $canExam || $canExercise || $canReport || $canCourse): ?>
        <div class="chero-acts">
            <?php if ($canLessonEdit): ?><a class="btn btn-outline btn-sm" href="<?= url('/admin/lessons/' . $lesson['id'] . '/edit') ?>"><?= icon('pencil') ?> ویرایش درس</a><?php endif; ?>
            <?php if ($canReport): ?><a class="btn btn-outline btn-sm" href="<?= url('/admin/reports/lesson/' . $lesson['id']) ?>"><?= icon('chart-column') ?> گزارش درس</a><?php endif; ?>
            <details class="dropdown chero-more"><summary class="btn btn-icon btn-ghost" title="ابزار مدیریت"><?= icon('ellipsis-vertical') ?></summary>
                <div class="dd-menu">
                    <?php if ($canExam): ?><a href="<?= url('/admin/exams/create', ['course_id' => $c['id'], 'lesson_id' => $lesson['id']]) ?>"><?= icon('clipboard-check') ?> آزمون جدید برای این درس</a><?php endif; ?>
                    <?php if ($canExercise): ?><a href="<?= url('/admin/lessons/' . $lesson['id'] . '/edit') ?>#lesson-extras"><?= icon('notebook-pen') ?> تمرین‌ها و آزمون‌های درس</a><?php endif; ?>
                    <?php if ($canReport): ?><a href="<?= url('/admin/reports/course/' . $c['id']) ?>"><?= icon('chart-pie') ?> گزارش مدیریتی دوره</a><?php endif; ?>
                    <?php if ($canCourse): ?><a href="<?= url('/admin/courses/' . $c['id']) ?>"><?= icon('settings') ?> مدیریت دوره</a><?php endif; ?>
                </div>
            </details>
        </div>
        <?php endif; ?>
    </div>
    <div class="chero-stats">
        <div class="cs" style="--tc:var(--primary)"><span class="cs-ic"><?= icon('list') ?></span><span><b><?= fa($lsIdx) ?> <i style="color:var(--muted)">از <?= fa($lsAll) ?></i></b><small>شماره درس</small></span></div>
        <div class="cs" style="--tc:#0ea5e9"><span class="cs-ic"><?= icon($ctIcons[$lesson['content_type']] ?? 'file') ?></span><span><b><?= e(label('content_type', $lesson['content_type'])) ?></b><small>نوع محتوا</small></span></div>
        <div class="cs" style="--tc:var(--warning)"><span class="cs-ic"><?= icon('clock') ?></span><span><b><?= $lesson['duration_minutes'] ? fa($lesson['duration_minutes']) . ' دقیقه' : '—' ?></b><small>مدت درس</small></span></div>
        <div class="cs" style="--tc:var(--success)"><span class="cs-ic"><?= icon('gauge') ?></span><span><b><?= $en ? fa((int)$en['progress_pct']) . '٪' : '—' ?></b><small>پیشرفت دوره</small></span></div>
        <div class="cs" style="--tc:#8b5cf6"><span class="cs-ic"><?= icon('clipboard-check') ?></span><span><b><?= fa(count($exams) + count($exercises)) ?></b><small>آزمون و تمرین</small></span></div>
    </div>
</div>
<?php if (!empty($flag)): $fv = $lesson['content_type'] === 'audio' ? 'گوش دهید' : ($lesson['content_type'] === 'text' ? 'مطالعه کنید' : 'ببینید'); ?>
<div class="alert alert-warning"><?= icon('triangle-alert') ?><div>
    <b><?= $flag['status'] === 'reset' ? 'مسئول آموزش تکمیل این درس را لغو کرده است.' : 'این درس بدون ' . ($lesson['content_type'] === 'audio' ? 'شنیدن' : ($lesson['content_type'] === 'text' ? 'مطالعه' : 'دیدن')) . ' کامل، تکمیل شده است.' ?></b>
    طول درس <?= App\Services\CompletionAudit::minutes((int)$flag['expected_sec']) ?> است<?= $flag['elapsed_sec'] !== null && $flag['status'] !== 'reset' ? ' ولی در ' . App\Services\CompletionAudit::minutes((int)$flag['elapsed_sec']) . ' علامت تکمیل خورد' : '' ?>.
    لطفاً درس را از ابتدا تا انتها <?= $fv ?>؛ جلو زدن فایل به‌عنوان دیدن حساب نمی‌شود. <?= $lesson['content_type'] === 'text' ? 'این مورد را مسئول آموزش بررسی می‌کند.' : 'پس از پخش کامل، این هشدار خودکار برداشته می‌شود.' ?>
</div></div>
<?php endif; ?>

<div class="player-layout">
    <div class="stack" data-lesson data-need="<?= $needPct ?>" data-pct="<?= $pct ?>" data-watched="<?= (int)($lp['watched_sec'] ?? 0) ?>"<?php if ($en && !$preview): ?> data-progress-url="<?= url('/learn/lesson/' . $lesson['id'] . '/progress') ?>" data-heartbeat-url="<?= url('/learn/lesson/' . $lesson['id'] . '/heartbeat') ?>"<?php endif; ?>>
        <?php if ($media && $lesson['content_type'] === 'video'): ?>
            <div class="media-box"><video controls preload="metadata" controlsList="<?= $media['downloadable'] ? '' : 'nodownload' ?>" data-track data-resume="<?= (int)($lp['position_sec'] ?? 0) ?>" src="<?= e(file_url($media['id'])) ?>"></video></div>
        <?php elseif ($media && $lesson['content_type'] === 'audio'): ?>
            <div class="card"><div class="flex gap-2"><span class="ico" style="width:56px;height:56px;border-radius:16px;display:grid;place-items:center;background:var(--primary-soft);color:var(--primary)"><?= icon('music') ?></span><audio class="grow" controls preload="metadata" data-title="<?= e($lesson['title']) ?>" data-track data-resume="<?= (int)($lp['position_sec'] ?? 0) ?>" src="<?= e(file_url($media['id'])) ?>" style="width:100%"></audio></div></div>
        <?php elseif ($media && $lesson['content_type'] === 'pdf'): ?>
            <iframe class="pdf-frame" src="<?= e(file_url($media['id'])) ?>" title="PDF"></iframe>
        <?php elseif ($media && $lesson['content_type'] === 'image'): ?>
            <div class="card text-center"><img src="<?= e(file_url($media['id'])) ?>" alt="<?= e($lesson['title']) ?>" style="border-radius:12px"></div>
        <?php elseif ($remote && $remote['mode'] === 'embed'): ?>
            <div class="media-box"><iframe src="<?= e($remote['src']) ?>" allow="autoplay; fullscreen; picture-in-picture; encrypted-media" allowfullscreen referrerpolicy="strict-origin-when-cross-origin" title="<?= e($lesson['title']) ?>"></iframe></div>
        <?php elseif ($remote && $remote['mode'] === 'video'): ?>
            <div class="media-box"><video controls preload="metadata" playsinline controlsList="nodownload" data-track data-resume="<?= $resume ?>" data-remote src="<?= e($remote['src']) ?>"></video></div>
            <div class="small faint remote-err hide" data-remote-err><?= icon('triangle-alert') ?> پخش این فیلم ممکن نشد. <a target="_blank" rel="noopener noreferrer" href="<?= e($remote['src']) ?>">باز کردن مستقیم فایل</a></div>
        <?php elseif ($remote && $remote['mode'] === 'audio'): ?>
            <div class="card"><div class="flex gap-2"><span class="ico" style="width:56px;height:56px;border-radius:16px;display:grid;place-items:center;background:var(--primary-soft);color:var(--primary)"><?= icon('music') ?></span><audio class="grow" controls preload="metadata" controlsList="nodownload" data-title="<?= e($lesson['title']) ?>" data-track data-resume="<?= $resume ?>" data-remote src="<?= e($remote['src']) ?>" style="width:100%"></audio></div></div>
            <div class="small faint remote-err hide" data-remote-err><?= icon('triangle-alert') ?> پخش این فایل صوتی ممکن نشد. <a target="_blank" rel="noopener noreferrer" href="<?= e($remote['src']) ?>">باز کردن مستقیم فایل</a></div>
        <?php elseif ($remote && $remote['mode'] === 'link'): ?>
            <div class="card flex between"><div class="flex"><?= icon('external-link') ?> <b>منبع خارجی</b></div><a class="btn btn-primary" target="_blank" rel="noopener noreferrer" href="<?= e($remote['src']) ?>">باز کردن لینک</a></div>
        <?php elseif ($media): ?>
            <div class="file-tile"><span class="fi"><?= icon('file') ?></span><div class="grow"><b><?= e($media['title'] ?: $media['original_name']) ?></b><div class="small faint"><?= human_size((int)$media['size']) ?></div></div><?php if ($media['downloadable']): ?><a class="btn btn-primary btn-sm" href="<?= e(file_url($media['id'], true)) ?>"><?= icon('download') ?> دانلود</a><?php endif; ?></div>
        <?php endif; ?>

        <?php if ($lesson['body']): ?><div class="card lbody" data-readmore><div class="prose lbody-in"><?= clean_html($lesson['body']) ?></div><button type="button" class="lbody-more" data-readmore-btn hidden><span class="t-more"><?= icon('chevron-down') ?> ادامه متن</span><span class="t-less"><?= icon('chevron-down') ?> بستن متن</span></button></div><?php endif; ?>

        <?php if ($files): ?>
            <div class="card"><h3><?= icon('file-down') ?> فایل‌های پیوست</h3>
                <div class="grid g-2">
                <?php foreach ($files as $f): ?>
                    <div class="file-tile"><span class="fi"><?= icon(['video' => 'video', 'audio' => 'music', 'pdf' => 'file-text', 'image' => 'image'][$f['kind']] ?? 'file') ?></span>
                        <div class="grow"><b class="small"><?= e($f['title'] ?: $f['original_name']) ?></b><div class="small faint"><?= e(strtoupper($f['ext'])) ?> · <?= human_size((int)$f['size']) ?></div></div>
                        <?php if ($f['viewable']): ?><a class="btn btn-ghost btn-sm" target="_blank" href="<?= e(file_url($f['id'])) ?>"><?= icon('eye') ?></a><?php endif; ?>
                        <?php if ($f['downloadable']): ?><a class="btn btn-outline btn-sm" href="<?= e(file_url($f['id'], true)) ?>"><?= icon('download') ?></a><?php endif; ?>
                    </div>
                <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="card lbar">
            <?php if ($prev): ?><a class="lbar-step" href="<?= url('/learn/lesson/' . $prev['id']) ?>"><span class="lbar-arrow"><?= icon('chevron-right') ?></span><span class="lbar-txt"><small>درس قبلی</small><b><?= e($prev['title']) ?></b></span></a><?php else: ?><span class="lbar-step is-empty"></span><?php endif; ?>
            <div class="lbar-main">
            <?php if ($en && !$preview): ?>
                <?php if ($isDone): ?>
                    <span class="lbar-done"><?= icon('circle-check') ?> این درس را تکمیل کرده‌اید</span>
                <?php elseif ($tasksLeft && $taskGate): ?>
                    <span class="btn btn-outline btn-lg disabled" aria-disabled="true" title="<?= e($taskGate['msg']) ?>"><?= icon('circle-play') ?> ابتدا درس را کامل ببینید</span>
                <?php elseif ($tasksLeft): ?>
                    <a class="btn btn-warning btn-lg" href="#lesson-tasks"><?= icon('clipboard-check') ?> مرحله بعد: آزمون/تمرین درس</a>
                <?php else: ?>
                    <form method="post" action="<?= url('/learn/lesson/' . $lesson['id'] . '/complete') ?>"><?= csrf_field() ?>
                        <button class="btn btn-success btn-lg<?= $canComplete ? '' : ' disabled' ?>" data-complete-btn<?= $auditBlock ? ' data-audit-block' . (!empty($auditBlock['wait_sec']) ? ' data-audit-wait="' . (int)$auditBlock['wait_sec'] . '"' : '') . ' title="' . e($auditBlock['msg']) . '"' : '' ?> <?= $canComplete ? '' : 'disabled' ?><?= $isMedia && !$contentDone ? ' title="برای تکمیل، حداقل ' . fa($needPct) . '٪ محتوا را ببینید"' : '' ?>><?= icon('circle-check') ?> تکمیل درس و ادامه</button>
                    </form>
                <?php endif; ?>
            <?php endif; ?>
            </div>
            <?php if ($next && ($nextOpen ?? true)): ?><a class="lbar-step is-next" href="<?= url('/learn/lesson/' . $next['id']) ?>"><span class="lbar-txt"><small>درس بعدی</small><b><?= e($next['title']) ?></b></span><span class="lbar-arrow"><?= icon('chevron-left') ?></span></a>
            <?php elseif ($next): ?><span class="lbar-step is-next is-locked" aria-disabled="true" title="<?= e($tasksLeft ? 'ابتدا آزمون/تمرین این درس را انجام دهید' : 'ابتدا این درس را تکمیل کنید') ?>"><span class="lbar-txt"><small>درس بعدی</small><b><?= e($next['title']) ?></b></span><span class="lbar-arrow"><?= icon('lock') ?></span></span>
            <?php else: ?><span class="lbar-step is-next is-empty"></span><?php endif; ?>
            <?php if ($auditBlock): ?><div class="lbar-block" data-audit-note><?= icon('lock') ?><span><b>دکمه تکمیل فعلاً غیرفعال است.</b> <?= e($auditBlock['msg']) ?></span></div><?php endif; ?>
            <?php if ($en && !$preview && $isMedia && !$contentDone): ?><div class="lbar-hint"><?= icon('info') ?> برای تکمیل درس، حداقل <?= fa($needPct) ?>٪ محتوا را ببینید. محل توقف شما ذخیره می‌شود.</div><?php endif; ?>
        </div>
    </div>

    <aside class="lside">
        <?php $nAll = count($items['lessons']); $nDone = count(array_filter($items['lessons'], fn($l) => in_array((int)$l['id'], $done, true))); ?>
        <div class="card lnav">
            <div class="lnav-head">
                <h3><?= icon('list') ?> درس‌های دوره</h3>
                <span class="lnav-count"><?= fa($nDone) ?> از <?= fa($nAll) ?></span>
            </div>
            <div class="cp-bar mb-1"><span style="width:<?= $nAll ? round($nDone * 100 / $nAll) : 0 ?>%"></span></div>
            <ol class="lnav-list">
            <?php foreach ($items['lessons'] as $k => $l):
                $d = in_array((int)$l['id'], $done, true);
                $cur = (int)$l['id'] === (int)$lesson['id'];
                $open = $preview || can('lessons.view') || ($en && App\Services\Enrollment::lessonUnlocked((int)auth()['id'], $l, $c, $items['lessons'], $done)); ?>
                <li><a class="lnav-item<?= $cur ? ' is-current' : '' ?><?= $d ? ' is-done' : '' ?><?= $open ? '' : ' is-locked' ?>" href="<?= url('/learn/lesson/' . $l['id']) ?>"<?= $cur ? ' aria-current="page"' : '' ?><?= $open ? '' : ' data-locked="' . e(App\Services\Enrollment::lockReason($l, $c, $items['lessons'], $done, $en ?: null)) . '"' ?>>
                    <span class="lnav-st"><?= $d ? icon('check') : ($cur ? icon('play') : ($open ? fa($k + 1) : icon('lock'))) ?></span>
                    <span class="lnav-t"><?= e($l['title']) ?><?php if ($l['duration_minutes'] ?? 0): ?><small><?= fa($l['duration_minutes']) ?> دقیقه</small><?php endif; ?></span>
                </a></li>
            <?php endforeach; ?>
            </ol>
        </div>

        <?php if ($exams || $exercises): ?>
        <div class="card ltasks" id="lesson-tasks">
            <h3><?= icon('clipboard-check') ?> آزمون و تمرین این درس</h3>
            <?php if ($taskGate): ?><div class="ltasks-note"><?= icon('lock') ?><span><?= e($taskGate['msg']) ?></span></div>
            <?php elseif ($tasksLeft): ?><div class="ltasks-note is-warn"><?= icon('triangle-alert') ?><span>محتوای درس را دیده‌اید؛ برای رفتن به درس بعد، <?= e(App\Services\Enrollment::tasksText($tasks['blocking'])) ?> را انجام دهید. تمرینِ در انتظار بررسی مانع ادامه نیست.</span></div><?php endif; ?>
            <?php
            $taskRows = [];
            foreach ($exams as $exam) $taskRows[] = ['exam', $exam, 'آزمون', 'clipboard-check', 'var(--purple,#8b5cf6)', url('/learn/exam/' . $exam['id'])];
            foreach ($exercises as $exercise) $taskRows[] = ['exercise', $exercise, 'تمرین', 'notebook-pen', 'var(--warning)', url('/learn/exercise/' . $exercise['id'])];
            foreach ($taskRows as [$type, $t, $tLabel, $tIcon, $tColor, $tUrl]):
                $st = $en ? ($taskState[$type . $t['id']] ?? null) : null; $sb = $st ? $stateBadge[$st] : null; ?>
                <<?= $taskGate ? 'div' : 'a' ?> class="ltask<?= $taskGate ? ' is-locked' : '' ?><?= $st === 'done' ? ' is-done' : '' ?>"<?= $taskGate ? ' aria-disabled="true"' : ' href="' . $tUrl . '"' ?> style="--tc:<?= $tColor ?>">
                    <span class="ltask-ic"><?= icon($taskGate ? 'lock' : ($st === 'done' ? 'check' : $tIcon)) ?></span>
                    <span class="ltask-b"><small><?= $tLabel ?> این درس</small><b><?= e($t['title']) ?></b><?php if ($sb): ?><span class="badge badge-<?= $sb[1] ?>"><?= e($sb[0]) ?></span><?php endif; ?></span>
                    <?php if (!$taskGate): ?><span class="ltask-go"><?= icon('chevron-left') ?></span><?php endif; ?>
                </<?= $taskGate ? 'div' : 'a' ?>>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </aside>
</div>
