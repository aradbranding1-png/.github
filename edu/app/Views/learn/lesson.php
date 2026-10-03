<?php
$isDone = in_array((int)$lesson['id'], $done, true);
$needPct = (int)setting('video_complete_percent', 90);
$remote = $remote ?? null;
$isMedia = (in_array($lesson['content_type'], ['video', 'audio'], true) && $media) || ($remote && in_array($remote['mode'], ['video', 'audio'], true));
$resume = (int)($lp['position_sec'] ?? 0);
$pct = (int)($lp['percent'] ?? 0);
$canComplete = !$preview && $en && !$isDone && (!$isMedia || $pct >= $needPct);
?>
<div class="crumbs"><a href="<?= url('/learn') ?>">دوره‌های من</a> / <a href="<?= url('/learn/course/' . $c['id']) ?>"><?= e($c['title']) ?></a></div>
<div class="page-head">
    <div><h1><?= e($lesson['title']) ?></h1><div class="sub"><?= e($lesson['section_title'] ?? '') ?> <?= $isDone ? status_badge('completed') : '' ?> <?= $preview ? '<span class="badge badge-info">پیش‌نمایش</span>' : '' ?></div></div>
    <?php if ($en): ?><div class="flex" style="min-width:220px"><div class="grow"><?= progress_bar((float)$en['progress_pct']) ?></div><b class="small">پیشرفت دوره <?= fa((int)$en['progress_pct']) ?>٪</b></div><?php endif; ?>
</div>
<?php
// management shortcuts — only for people allowed to manage lessons/exams (server still checks every action)
$canLessonEdit = can('lessons.edit'); $canExam = can('exams.create'); $canExercise = can('lessons.create'); $canCourse = can('courses.view') || can('lessons.view');
if ($canLessonEdit || $canExam || $canExercise): ?>
<div class="staff-bar">
    <span class="sb-label"><?= icon('shield-check') ?> ابزار مدیریت درس</span>
    <div class="flex flex-wrap">
        <?php if ($canLessonEdit): ?><a class="btn btn-primary btn-sm" href="<?= url('/admin/lessons/' . $lesson['id'] . '/edit') ?>"><?= icon('pencil') ?> ویرایش درس و متن</a><?php endif; ?>
        <?php if ($canExam): ?><a class="btn btn-outline btn-sm" href="<?= url('/admin/exams/create', ['course_id' => $c['id'], 'lesson_id' => $lesson['id']]) ?>"><?= icon('clipboard-check') ?> آزمون جدید برای این درس</a><?php endif; ?>
        <?php if ($canExercise): ?><a class="btn btn-outline btn-sm" href="<?= url('/admin/lessons/' . $lesson['id'] . '/edit') ?>#lesson-extras"><?= icon('notebook-pen') ?> تمرین‌ها و آزمون‌های درس</a><?php endif; ?>
        <?php if ($canCourse): ?><a class="btn btn-ghost btn-sm" href="<?= url('/admin/courses/' . $c['id']) ?>"><?= icon('settings') ?> مدیریت دوره</a><?php endif; ?>
    </div>
</div>
<?php endif; ?>

<div class="player-layout">
    <div class="stack" data-lesson data-need="<?= $needPct ?>" data-pct="<?= $pct ?>"<?php if ($en && !$preview): ?> data-progress-url="<?= url('/learn/lesson/' . $lesson['id'] . '/progress') ?>" data-heartbeat-url="<?= url('/learn/lesson/' . $lesson['id'] . '/heartbeat') ?>"<?php endif; ?>>
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

        <?php if ($lesson['body']): ?><div class="card prose"><?= clean_html($lesson['body']) ?></div><?php endif; ?>

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

        <?php if ($exams || $exercises): ?>
            <div class="grid g-2">
                <?php foreach ($exams as $exam): ?><a class="card flex" href="<?= url('/learn/exam/' . $exam['id']) ?>"><span class="ico" style="width:44px;height:44px;border-radius:12px;display:grid;place-items:center;background:var(--purple-soft);color:var(--purple)"><?= icon('clipboard-check') ?></span><div><b>آزمون این درس</b><div class="small faint"><?= e($exam['title']) ?></div></div></a><?php endforeach; ?>
                <?php foreach ($exercises as $exercise): ?><a class="card flex" href="<?= url('/learn/exercise/' . $exercise['id']) ?>"><span class="ico" style="width:44px;height:44px;border-radius:12px;display:grid;place-items:center;background:var(--warning-soft);color:var(--warning)"><?= icon('notebook-pen') ?></span><div><b>تمرین این درس</b><div class="small faint"><?= e($exercise['title']) ?></div></div></a><?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="card flex between flex-wrap">
            <div class="flex">
                <?php if ($prev): ?><a class="btn btn-outline" href="<?= url('/learn/lesson/' . $prev['id']) ?>"><?= icon('chevron-right') ?> درس قبلی</a><?php endif; ?>
                <?php if ($next): ?><a class="btn btn-outline" href="<?= url('/learn/lesson/' . $next['id']) ?>">درس بعدی <?= icon('chevron-left') ?></a><?php endif; ?>
            </div>
            <?php if ($en && !$preview): ?>
                <?php if ($isDone): ?>
                    <span class="badge badge-success" style="font-size:.9rem;padding:.4rem 1rem"><?= icon('circle-check') ?> این درس را تکمیل کرده‌اید</span>
                <?php else: ?>
                    <form method="post" action="<?= url('/learn/lesson/' . $lesson['id'] . '/complete') ?>"><?= csrf_field() ?>
                        <button class="btn btn-success btn-lg<?= $canComplete ? '' : ' disabled' ?>" data-complete-btn <?= $canComplete ? '' : 'disabled' ?>><?= icon('circle-check') ?> تکمیل درس و ادامه</button>
                    </form>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php if ($isMedia && !$isDone && $en): ?><div class="small faint">برای تکمیل درس، حداقل <?= fa($needPct) ?>٪ محتوا را مشاهده کنید. محل توقف شما ذخیره می‌شود و دفعه بعد از همان نقطه ادامه می‌دهید.</div><?php endif; ?>
    </div>

    <aside class="card lesson-nav">
        <h3 class="flex"><?= icon('list') ?> درس‌های دوره</h3>
        <?php foreach ($items['lessons'] as $l):
            $d = in_array((int)$l['id'], $done, true);
            $open = $preview || can('lessons.view') || ($en && App\Services\Enrollment::lessonUnlocked((int)auth()['id'], $l, $c, $items['lessons'], $done)); ?>
            <a class="lesson-link<?= (int)$l['id'] === (int)$lesson['id'] ? ' active' : '' ?><?= $d ? ' done' : '' ?><?= $open ? '' : ' locked' ?>" href="<?= url('/learn/lesson/' . $l['id']) ?>"<?= $open ? '' : ' data-locked="' . e(App\Services\Enrollment::lockReason($l, $c, $items['lessons'], $done, $en ?: null)) . '"' ?>>
                <span class="st"><?= icon($d ? 'check' : ($open ? 'play' : 'lock')) ?></span><span class="grow"><?= e($l['title']) ?></span>
            </a>
        <?php endforeach; ?>
    </aside>
</div>
