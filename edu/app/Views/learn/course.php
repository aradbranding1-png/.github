<?php
$color = $c['category_color'] ?? '#6366f1';
$cover = image_url($c['image_file_id'], 960);
$lessonsBySection = [];
foreach ($items['lessons'] as $l) $lessonsBySection[$l['section_title'] ?: ''][] = $l;
$locked = $en && $en['status'] === 'locked';
$creditOn = $en && App\Services\Credit::applies();
$unlockedIds = $creditOn ? App\Services\Credit::unlockedIds((int)auth()['id'], (int)$c['id']) : [];
$creditNeed = $creditOn ? App\Services\Credit::courseRemaining((int)auth()['id'], $items['lessons']) : 0;
$creditBal = $creditOn ? App\Services\Credit::balance((int)auth()['id']) : 0;
?>
<div class="card hero mb-3" style="background:linear-gradient(135deg, <?= e($color) ?>, color-mix(in srgb, <?= e($color) ?> 45%, #0f172a))">
    <div class="flex between flex-wrap gap-2">
        <div class="grow" style="min-width:260px">
            <div class="crumbs" style="color:rgba(255,255,255,.75)"><a style="color:inherit" href="<?= url('/learn/catalog') ?>">کاتالوگ</a> / <?= e($c['category_name'] ?? 'دوره') ?></div>
            <h1><?= e($c['title']) ?></h1>
            <p class="muted text-justify"><?= e($c['summary']) ?></p>
            <div class="flex flex-wrap">
                <span class="badge badge-<?= $c['training_type'] === 'mandatory' ? 'danger' : 'gray' ?>"><?= e(label('training_type', $c['training_type'])) ?></span>
                <?php if (App\Services\CourseAudience::groupIds((int)$c['id'])): ?><span class="badge badge-gray"><?= icon('users') ?> <?= e(App\Services\CourseAudience::label((int)$c['id'])) ?></span><?php endif; ?>
                <span class="badge badge-gray"><?= icon('book-open') ?> <?= fa(count($items['lessons'])) ?> درس</span>
                <?php if ($items['exams']): ?><span class="badge badge-gray"><?= icon('clipboard-check') ?> <?= fa(count($items['exams'])) ?> آزمون</span><?php endif; ?>
                <?php if ($c['duration_minutes']): ?><span class="badge badge-gray"><?= icon('clock') ?> <?= fa($c['duration_minutes']) ?> دقیقه</span><?php endif; ?>
                <?php if ($c['has_certificate']): ?><span class="badge badge-warning"><?= icon('award') ?> دارای گواهی</span><?php endif; ?>
                <span class="badge badge-gray"><?= icon('users') ?> <?= fa((int)$stats['learners']) ?> فراگیر</span>
            </div>
            <?php if ($c['status'] !== 'published'): ?><div class="alert alert-warning mt-2"><?= icon('eye') ?> پیش‌نمایش مدیر — این دوره هنوز منتشر نشده است.</div><?php endif; ?>
        </div>
        <div class="glass text-center" style="min-width:230px">
            <?php if ($en): ?>
                <?= progress_ring((float)$en['progress_pct'], 110, 'white') ?>
                <div class="mt-1"><?= status_badge($en['status']) ?></div>
                <?php if ($en['due_at']): ?><div class="small mt-1">مهلت: <?= jdate($en['due_at']) ?></div><?php endif; ?>
                <?php if (!$locked && $items['lessons'] && $en['status'] !== 'completed'): ?>
                    <a class="btn btn-white mt-2 w-100" href="<?= url('/learn/lesson/' . ($en['last_lesson_id'] ?: $items['lessons'][0]['id'])) ?>"><?= icon('circle-play') ?> <?= $en['last_lesson_id'] ? 'ادامه از آخرین درس' : 'شروع دوره' ?></a>
                <?php endif; ?>
                <?php if ($cert): ?><a class="btn btn-light mt-1 w-100" href="<?= url('/learn/certificate/' . $cert['code']) ?>"><?= icon('award') ?> مشاهده گواهی</a><?php endif; ?>
            <?php elseif ($visibleToSelf): ?>
                <div class="mb-2"><?= icon('graduation-cap', 'fs-xl') ?></div>
                <form method="post" action="<?= url('/learn/course/' . $c['id'] . '/enroll') ?>"><?= csrf_field() ?><button class="btn btn-white btn-lg w-100"><?= icon('plus') ?> ثبت‌نام در دوره</button></form>
            <?php else: ?>
                <div class="small">این دوره توسط مدیر آموزش تخصیص داده می‌شود.</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($locked): ?>
<div class="alert alert-warning"><?= icon('lock') ?><div>این دوره قفل است. <?php if ($prereq): ?>ابتدا پیش‌نیازها را تکمیل کنید.<?php else: ?>ابتدا مرحله قبلی مسیر آموزشی را تکمیل کنید.<?php endif; ?></div></div>
<?php endif; ?>

<div class="grid g-main">
    <div class="stack">
        <?php if ($c['description']): ?><div class="card"><h3><?= icon('info') ?> درباره دوره</h3><div class="prose"><?= clean_html($c['description']) ?></div></div><?php endif; ?>
        <?php
        $totalMin = array_sum(array_map(fn($l) => (int)$l['duration_minutes'], $items['lessons']));
        $nDone = count($done); $nAll = count($items['lessons']);
        $ctIcons = ['video' => 'video', 'audio' => 'music', 'pdf' => 'file-text', 'image' => 'image', 'file' => 'file', 'link' => 'link', 'text' => 'file-text'];
        $currentId = $en ? (int)($en['last_lesson_id'] ?? 0) : 0;
        $fmtMin = fn(int $m) => $m >= 60 ? fa(intdiv($m, 60)) . ' ساعت' . ($m % 60 ? ' و ' . fa($m % 60) . ' دقیقه' : '') : fa($m) . ' دقیقه';
        // one pass to know each lesson's state, so the "next lesson" can be picked before rendering
        $rowInfo = []; $nextId = 0; $firstOpenId = 0; $rowNo = 0;
        foreach ($items['lessons'] as $l) {
            $id = (int)$l['id']; $rowNo++;
            $isDone = in_array($id, $done, true);
            $open = $en && !$locked && App\Services\Enrollment::lessonUnlocked((int)auth()['id'], $l, $c, $items['lessons'], $done);
            $can = $open || $isStaff || ((int)$l['is_preview'] === 1);
            $rowInfo[$id] = ['no' => $rowNo, 'done' => $isDone, 'can' => $can];
            if (!$isDone && $can && !$firstOpenId) $firstOpenId = $id;
        }
        if ($en && !$locked) $nextId = ($currentId && isset($rowInfo[$currentId]) && !$rowInfo[$currentId]['done'] && $rowInfo[$currentId]['can']) ? $currentId : $firstOpenId;
        $next = null; foreach ($items['lessons'] as $l) if ((int)$l['id'] === $nextId) $next = $l;
        $pct = $nAll ? (int)round($nDone * 100 / $nAll) : 0;
        $inactiveIds = $creditOn ? array_values(array_filter(array_map(fn($l) => App\Services\Credit::cost($l) > 0 && !in_array((int)$l['id'], $unlockedIds, true) ? (int)$l['id'] : 0, $items['lessons']))) : [];
        $flat = count($lessonsBySection) === 1 && array_key_first($lessonsBySection) === '';
        ?>
        <div class="card curr">
            <div class="curr-top">
                <h3><?= icon('list') ?> سرفصل‌ها و درس‌ها</h3>
                <div class="curr-sum">
                    <span><?= icon('book-open') ?> <?= fa($nAll) ?> درس</span>
                    <?php if (!$flat): ?><span><?= icon('layers') ?> <?= fa(count($lessonsBySection)) ?> بخش</span><?php endif; ?>
                    <?php if ($totalMin): ?><span><?= icon('clock') ?> <?= $fmtMin($totalMin) ?></span><?php endif; ?>
                </div>
                <?php if ($en && $nAll): ?>
                    <div class="curr-prog">
                        <div class="cp-bar"><span style="width:<?= $pct ?>%"></span></div>
                        <div class="cp-txt"><b><?= fa($pct) ?>٪</b> · <?= fa($nDone) ?> از <?= fa($nAll) ?> درس</div>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($next): $nInfo = $rowInfo[(int)$next['id']]; ?>
                <a class="curr-next" href="<?= url('/learn/lesson/' . $next['id']) ?>">
                    <span class="cn-play"><?= icon('play') ?></span>
                    <span class="cn-body">
                        <span class="cn-label"><?= $nDone || (int)$next['id'] === $currentId ? 'ادامه یادگیری' : 'شروع از اینجا' ?> · درس <?= fa($nInfo['no']) ?></span>
                        <span class="cn-title"><?= e($next['title']) ?></span>
                        <span class="cn-meta"><?= e(label('content_type', $next['content_type'])) ?><?= $next['duration_minutes'] ? ' · ' . fa($next['duration_minutes']) . ' دقیقه' : '' ?></span>
                    </span>
                    <span class="cn-go"><?= icon('chevron-left') ?></span>
                </a>
            <?php elseif ($en && $nAll && $nDone === $nAll): ?>
                <div class="curr-next is-done"><span class="cn-play"><?= icon('check') ?></span><span class="cn-body"><span class="cn-title">همه درس‌ها را تکمیل کرده‌اید 🎉</span></span></div>
            <?php endif; ?>

            <?php if ($inactiveIds): ?><div class="curr-note"><?= icon('hourglass') ?><span>درس‌های دارای این علامت هنوز فعال نشده‌اند؛ با اولین ورود فعال می‌شوند و زمانشان از اعتبار شما کسر می‌شود.</span></div><?php endif; ?>
            <?php if (!$items['lessons']): ?><div class="empty"><?= icon('book-open') ?><div>هنوز درسی اضافه نشده است.</div></div><?php endif; ?>
            <?php $secNo = 0; foreach ($lessonsBySection as $sec => $ls):
                $secNo++;
                $secDone = count(array_filter($ls, fn($l) => in_array((int)$l['id'], $done, true)));
                $secMin = array_sum(array_map(fn($l) => (int)$l['duration_minutes'], $ls));
                $hasNext = (bool)array_filter($ls, fn($l) => (int)$l['id'] === $nextId);
                $secOpen = $flat || $hasNext || (!$nextId && $secNo === 1); ?>
                <<?= $flat ? 'div' : 'details' ?> class="curr-sec<?= $secDone === count($ls) && $en ? ' is-done' : '' ?><?= $flat ? ' is-flat' : '' ?>"<?= $secOpen && !$flat ? ' open' : '' ?>>
                    <?php if (!$flat): ?>
                    <summary class="curr-head">
                        <span class="curr-num"><?= $secDone === count($ls) && $en ? icon('check') : fa($secNo) ?></span>
                        <span class="grow"><b><?= e($sec !== '' ? $sec : 'درس‌های دوره') ?></b>
                            <span class="curr-meta"><?= fa(count($ls)) ?> درس<?= $secMin ? ' · ' . $fmtMin($secMin) : '' ?><?php if ($en): ?> · <?= fa($secDone) ?>/<?= fa(count($ls)) ?> تکمیل<?php endif; ?></span></span>
                        <span class="curr-chev"><?= icon('chevron-down') ?></span>
                    </summary>
                    <?php endif; ?>
                    <ol class="curr-rows">
                    <?php foreach ($ls as $l):
                        $id = (int)$l['id']; $ri = $rowInfo[$id];
                        $isDone = $ri['done']; $can = $ri['can'];
                        $isCur = $id === $nextId;
                        $st = $isDone ? 'done' : ($isCur ? 'current' : ($can ? 'open' : 'locked'));
                        $inactive = in_array($id, $inactiveIds, true);
                        $tasksLeft = !$isDone && in_array($id, $contentDone ?? [], true) && !empty($lessonTasks[$id]['blocking']); ?>
                        <li>
                        <a class="curr-row is-<?= $st ?>" href="<?= url('/learn/lesson/' . $l['id']) ?>"<?= $can ? '' : ' aria-disabled="true" data-locked="' . e(App\Services\Enrollment::lockReason($l, $c, $items['lessons'], $done, $en ?: null)) . '"' ?>>
                            <span class="curr-st"><?= $isDone ? icon('check') : ($isCur ? icon('play') : ($can ? fa($ri['no']) : icon('lock'))) ?></span>
                            <span class="curr-body">
                                <span class="curr-title"><?= e($l['title']) ?></span>
                                <span class="curr-meta">
                                    <span><?= icon($ctIcons[$l['content_type']] ?? 'file') ?> <?= e(label('content_type', $l['content_type'])) ?></span>
                                    <?php if ($l['duration_minutes']): ?><span><?= fa($l['duration_minutes']) ?> دقیقه</span><?php endif; ?>
                                    <?php if ($inactive): ?><span class="cm-warn" title="فعال نشده — با اولین ورود، زمان درس از اعتبار شما کسر می‌شود"><?= icon('hourglass') ?></span><?php endif; ?>
                                    <?php if ($tasksLeft): ?><span class="cm-warn" title="<?= e(App\Services\Enrollment::tasksText($lessonTasks[$id]['blocking'])) ?>"><?= icon('clipboard-check') ?> آزمون/تمرین مانده</span><?php endif; ?>
                                    <?php if ((int)$l['is_preview'] === 1 && !$en): ?><span class="cm-info">پیش‌نمایش رایگان</span><?php endif; ?>
                                </span>
                            </span>
                            <?php if ($can): ?><span class="curr-go"><?= icon('chevron-left') ?></span><?php endif; ?>
                        </a>
                        </li>
                    <?php endforeach; ?>
                    </ol>
                </<?= $flat ? 'div' : 'details' ?>>
            <?php endforeach; ?>
        </div>
        <?php if ($c['syllabus']): ?><div class="card"><h3><?= icon('scroll-text') ?> سرفصل تفصیلی</h3><div class="prose"><?= clean_html($c['syllabus']) ?></div></div><?php endif; ?>
    </div>
    <div class="stack">
        <?php if (can('reports.report') || can('courses.report')): ?><a class="btn btn-primary w-100" href="<?= url('/admin/reports/course/' . $c['id']) ?>"><?= icon('chart-column') ?> گزارش مدیریتی دوره</a><?php endif; ?>
        <?php if ($isStaff && can('courses.edit')): ?><a class="btn btn-outline w-100" href="<?= url('/admin/courses/' . $c['id']) ?>"><?= icon('settings') ?> مدیریت این دوره</a><?php endif; ?>
        <?php if ($creditOn): ?>
            <div class="card credit-box">
                <h3><?= icon('clock') ?> اعتبار زمانی</h3>
                <div class="cb-row"><span>موجودی شما</span><b><?= App\Services\Credit::format($creditBal) ?></b></div>
                <div class="cb-row"><span>لازم برای درس‌های فعال‌نشده این دوره</span><b><?= App\Services\Credit::format($creditNeed) ?></b></div>
                <?php if ($creditNeed > 0 && $creditBal >= $creditNeed && !$locked): ?>
                    <form method="post" action="<?= url('/learn/course/' . $c['id'] . '/unlock') ?>" data-confirm="<?= e(fa($creditNeed)) ?> دقیقه از اعتبار شما کسر و همه درس‌های باقی‌مانده فعال شود؟"><?= csrf_field() ?><button class="btn btn-primary w-100 mt-1"><?= icon('lock-open') ?> فعال‌سازی کل دوره</button></form>
                    <div class="hint mt-1">یا هر درس را هنگام ورود جداگانه فعال کنید.</div>
                <?php elseif ($creditNeed > $creditBal): ?>
                    <div class="alert alert-warning mt-1 small"><?= icon('info') ?><div>اعتبار شما برای همه درس‌های این دوره کافی نیست. <?= e(setting('minutes_charge_text')) ?></div></div>
                <?php elseif ($creditNeed === 0): ?>
                    <div class="small" style="color:var(--success)"><?= icon('circle-check') ?> همه درس‌های این دوره برای شما فعال است.</div>
                <?php endif; ?>
                <a class="small" href="<?= url('/me/credits') ?>"><?= icon('history') ?> تاریخچه اعتبار</a>
            </div>
        <?php endif; ?>
        <?php if ($instructor && !App\Services\Scope::hiddenRoot((int)$instructor['id'])): ?>
            <div class="card"><h3><?= icon('user') ?> مدرس</h3><div class="person"><?= avatar_html($instructor, 'md') ?><div><div class="nm"><?= user_name_html($instructor) ?></div><div class="sub"><?= e($instructor['job_title'] ?? '') ?></div></div></div></div>
        <?php endif; ?>
        <?php if ($prereq): ?>
            <div class="card"><h3><?= icon('git-branch') ?> پیش‌نیازها</h3>
                <?php foreach ($prereq as $pr): ?><a class="lesson-link <?= $pr['status'] === 'completed' ? 'done' : '' ?>" href="<?= url('/learn/course/' . $pr['id']) ?>"><span class="st"><?= icon($pr['status'] === 'completed' ? 'check' : 'lock') ?></span><span class="grow"><?= e($pr['title']) ?></span><?= $pr['status'] ? status_badge($pr['status']) : '<span class="badge badge-gray">ثبت‌نام نشده</span>' ?></a><?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php if ($items['exams']): ?>
            <div class="card"><h3><?= icon('clipboard-check') ?> آزمون‌ها</h3>
                <?php foreach ($items['exams'] as $x): $b = $best[(int)$x['id']] ?? null; ?>
                    <div class="list-item">
                        <span class="ico" style="background:var(--purple-soft);color:var(--purple)"><?= icon('clipboard-check') ?></span>
                        <div class="grow"><a class="fw-b" href="<?= url('/learn/exam/' . $x['id']) ?>"><?= e($x['title']) ?></a>
                            <div class="small faint"><?= $x['is_required'] ? 'الزامی' : 'اختیاری' ?> · حد قبولی <?= fa((float)$x['pass_score']) ?>٪<?= $b ? ' · بهترین نمره ' . fa(round((float)$b['best'])) . '٪' : '' ?></div></div>
                        <?php if ($b && (int)$b['passed']): ?><span class="badge badge-success">قبول</span><?php elseif ($b && (int)$b['pending']): ?><span class="badge badge-warning">در انتظار تصحیح</span><?php elseif ($b): ?><span class="badge badge-danger">مردود</span><?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php if ($items['exercises']): ?>
            <div class="card"><h3><?= icon('notebook-pen') ?> تمرین‌ها</h3>
                <?php foreach ($items['exercises'] as $x): $s = $exSt[(int)$x['id']] ?? null; ?>
                    <div class="list-item">
                        <span class="ico" style="background:var(--warning-soft);color:var(--warning)"><?= icon('notebook-pen') ?></span>
                        <div class="grow"><a class="fw-b" href="<?= url('/learn/exercise/' . $x['id']) ?>"><?= e($x['title']) ?></a><div class="small faint"><?= $x['is_required'] ? 'الزامی' : 'اختیاری' ?></div></div>
                        <?= $s ? status_badge($s['status']) : '<span class="badge badge-gray">ارسال نشده</span>' ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
